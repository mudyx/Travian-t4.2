<?php
/*
 * Writes NPC decisions into the game, the same way the engine does but without
 * a session. Tables are MyISAM (no transactions), so resources are taken with a
 * single conditional UPDATE: it either removes the full price or nothing, even
 * if an attack or the automation changed the stock since NPCVillage was loaded.
 */
class NPCActions {

	// Result codes of queueBuild()
	const QUEUED = 'queued';
	const NO_RESOURCES = 'no_resources';
	const STORAGE_TOO_SMALL = 'storage_too_small';
	const NO_CROP = 'no_crop';
	const FAILED = 'failed';

	public static $lastCost = array();

	// Puts field/building $field (type $tid) at $level into the build queue.
	// Mirrors Building::upgradeBuilding()/constructBuilding() + MasterBuilder.
	public static function queueBuild(NPCVillage $v, $field, $tid, $level) {
		global $database;
		$cost = NPCRules::cost($v, $tid, $level);
		self::$lastCost = $cost;
		if(!$cost) {
			return self::FAILED;
		}
		// Building::checkResource(): the price must fit the storage at all
		if(max($cost['wood'], $cost['clay'], $cost['iron']) > $v->vdata['maxstore'] || $cost['crop'] > $v->vdata['maxcrop']) {
			return self::STORAGE_TOO_SMALL;
		}
		// Building::canBuild() code 4, but counting troop upkeep too (see NPCVillage::freeCrop)
		if($tid != 4 && ($v->freeCrop() - $cost['pop']) <= 0) {
			return self::NO_CROP;
		}

		if(!self::takeResources($v->wref, $cost)) {
			return self::NO_RESOURCES;
		}
		$oldType = $v->type($field);
		// addBuilding() also stamps the type on the slot right away (as for players)
		if(!$database->addBuilding($v->wref, $field, $tid, 0, time() + $cost['time'], 0, $level)) {
			$database->modifyResource($v->wref, $cost['wood'], $cost['clay'], $cost['iron'], $cost['crop'], 1);
			if($oldType == 0) {
				$database->query("UPDATE ".TB_PREFIX."fdata SET f".intval($field)."t = 0 WHERE vref = ".$v->wref);
			}
			return self::FAILED;
		}
		return self::QUEUED;
	}

	// Starts academy research of $unit (Technology::researchTech()). Returns cost or false.
	public static function queueResearch(NPCVillage $v, $unit) {
		global $database;
		$data = $GLOBALS['r'.$unit];
		$academy = $v->typeLevel(22);
		$time = time() + round(($data['time'] * ($GLOBALS['bid22'][$academy]['attri'] / 100)) / SPEED);
		if(!self::takeResources($v->wref, $data)) {
			return false;
		}
		if(!$database->addResearch($v->wref, 't'.intval($unit), $time)) {
			$database->modifyResource($v->wref, $data['wood'], $data['clay'], $data['iron'], $data['crop'], 1);
			return false;
		}
		return array('wood' => $data['wood'], 'clay' => $data['clay'], 'iron' => $data['iron'], 'crop' => $data['crop'],
			'time' => $time - time());
	}

	// Queues $amt units behind the building's current queue, writing the same row
	// as $database->trainUnit() (which can't be used: it needs the session's $village).
	// Returns the total cost or false.
	public static function queueTraining(NPCVillage $v, $unit, $amt) {
		global $database;
		$u = $GLOBALS['u'.$unit];
		$cost = array('wood' => $u['wood'] * $amt, 'clay' => $u['clay'] * $amt, 'iron' => $u['iron'] * $amt, 'crop' => $u['crop'] * $amt);
		$each = NPCMilitary::eachTime($v, $unit);
		if(!self::takeResources($v->wref, $cost)) {
			return false;
		}
		$start = NPCMilitary::queueEnd($v, $unit);
		$endat = $start + round($each * $amt / 1000);
		$ok = $database->query("INSERT INTO ".TB_PREFIX."training VALUES (0, ".$v->wref.", ".intval($unit).", ".intval($amt).", ".
			intval($u['pop']).", ".$start.", ".intval($each).", ".$start.", ".$endat.")");
		if(!$ok) {
			$database->modifyResource($v->wref, $cost['wood'], $cost['clay'], $cost['iron'], $cost['crop'], 1);
			return false;
		}
		$cost['time'] = $endat - time();
		return $cost;
	}

	// Sends a raid (attack_type 4) like Units::sendTroops(): takes the units out of
	// the village, writes s1_attacks + an outgoing movement (sort_type 3) and lets
	// the automation fight it out, bring survivors back and deliver the loot.
	// $plan comes from NPCAttack::plan(). Returns the attacks id or false.
	public static function sendRaid($uid, NPCVillage $v, $plan) {
		global $database;
		// Atomic: take all units or none (the village may have lost some since loading)
		$set = $where = array();
		foreach($plan['units'] as $unit => $n) {
			$set[] = "u".intval($unit)." = u".intval($unit)." - ".intval($n);
			$where[] = "u".intval($unit)." >= ".intval($n);
		}
		$database->query("UPDATE ".TB_PREFIX."units SET ".implode(', ', $set)." WHERE vref = ".$v->wref." AND ".implode(' AND ', $where));
		if(mysql_affected_rows($database->connection) != 1) {
			return false;
		}

		// s1_attacks stores t1..t10 relative to the tribe, t11 = hero (never sent)
		$t = array_fill(1, 11, 0);
		$first = ($v->tribe - 1) * 10;
		foreach($plan['units'] as $unit => $n) { $t[$unit - $first] = intval($n); }
		$ref = $database->addAttack($v->wref, $t[1], $t[2], $t[3], $t[4], $t[5], $t[6], $t[7], $t[8], $t[9], $t[10], $t[11], 4, 0, 0, 0);
		$arrive = time() + $plan['seconds'];
		if(!$ref || !$database->addMovement(3, $v->wref, $plan['target']['wref'], $ref, 0, $arrive)) {
			if($ref) $database->query("DELETE FROM ".TB_PREFIX."attacks WHERE id = ".intval($ref));
			$database->query("UPDATE ".TB_PREFIX."units SET ".str_replace(' - ', ' + ', implode(', ', $set))." WHERE vref = ".$v->wref);
			return false;
		}
		// Attacking ends beginner protection, as for players
		$database->query("UPDATE ".TB_PREFIX."users SET protect = 0 WHERE id = ".intval($uid));

		$list = array();
		foreach($plan['units'] as $unit => $n) { $list[] = 'u'.$unit.':'.$n; }
		$database->query("INSERT INTO ".TB_PREFIX."npc_raid (uid, from_wref, to_wref, ref, units, capacity, expected_loot, sent_at, arrive_at) VALUES (".
			intval($uid).", ".$v->wref.", ".intval($plan['target']['wref']).", ".intval($ref).", '".implode(',', $list)."', ".
			intval($plan['capacity']).", ".intval(min($plan['loot'], $plan['capacity'])).", ".time().", ".$arrive.")");
		return $ref;
	}

	// Atomic "subtract only if there is enough of everything"
	public static function takeResources($wref, $cost) {
		global $database;
		$w = intval($cost['wood']); $c = intval($cost['clay']); $i = intval($cost['iron']); $cr = intval($cost['crop']);
		$database->query("UPDATE ".TB_PREFIX."vdata SET wood = wood - $w, clay = clay - $c, iron = iron - $i, crop = crop - $cr".
			" WHERE wref = ".intval($wref)." AND wood >= $w AND clay >= $c AND iron >= $i AND crop >= $cr");
		return mysql_affected_rows($database->connection) == 1;
	}

	// Seconds until the stock covers $cost at the current production (null = never, e.g. negative crop)
	public static function secondsUntilAffordable(NPCVillage $v, $cost) {
		$wait = 0;
		foreach(array('wood', 'clay', 'iron', 'crop') as $res) {
			$missing = $cost[$res] - $v->stock($res);
			if($missing <= 0) continue;
			if($v->prod($res) <= 0) return null;
			$wait = max($wait, $missing / $v->prod($res) * 3600);
		}
		return (int)ceil($wait);
	}
}
