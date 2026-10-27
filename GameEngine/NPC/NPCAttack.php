<?php
/*
 * Raid planning (attack_type 4) for NPCs. plan() picks the best target in
 * range and the units to send; NPCActions::sendRaid() puts it into the game.
 *
 * Targets: other NPC villages and Nature "Farm" villages; real players only
 * when NPC_ATTACK_REAL_PLAYERS is on. Never: own villages, Natars, beginner
 * protection, targets this NPC hit recently.
 * Knowledge: only what NPCIntel learnt from the NPC's own raids. A target it
 * knows nothing (fresh) about gets a small probe raid first; a target known to
 * be too strong is left alone. Visible facts (position, protection) are used
 * like a player would.
 * Units: offensive units only; defensive ones never leave and
 * NPC_RAID_HOME_RESERVE of the offensive ones stays home too.
 */
class NPCAttack {

	public static function canRaid($npc) {
		return in_array($npc['profile'], explode(',', NPC_RAID_PROFILES));
	}

	// Seconds between raids of this NPC: aggression 80 -> 0.7 x base, 20 -> 1.3 x base
	public static function interval($npc) {
		return round(NPC_RAID_INTERVAL / SPEED * (1.5 - $npc['aggression'] / 100) * NPCHuman::factor($npc, 'raid'));
	}

	// Raids of this NPC still on the way there or back
	public static function activeRaids($uid) {
		global $database;
		$row = mysql_fetch_row($database->query("SELECT COUNT(DISTINCT r.id) FROM ".TB_PREFIX."npc_raid r".
			" JOIN ".TB_PREFIX."movement m ON m.ref = r.ref AND m.sort_type IN (3,4) AND m.proc = 0".
			" WHERE r.uid = ".intval($uid)));
		return intval($row[0]);
	}

	// Offensive units that may leave: unit => count (home reserve already kept back).
	// $all: every combat unit at home, defenders too (dodging an attack)
	public static function available(NPCVillage $v, $all = false) {
		$units = array();
		if(!isset(NPCMilitary::$units[$v->tribe])) return $units;
		$list = NPCMilitary::$units[$v->tribe]['off'];
		if($all) $list = array_unique(array_merge($list, NPCMilitary::$units[$v->tribe]['def']));
		foreach($list as $unit) {
			$home = isset($v->units['u'.$unit]) ? intval($v->units['u'.$unit]) : 0;
			$free = $all ? $home : (int)floor($home * (1 - NPC_RAID_HOME_RESERVE));
			if($free > 0) $units[$unit] = $free;
		}
		return $units;
	}

	// Best raid from this village or null:
	// array(target, units, capacity, loot, attack, defence, seconds, score, probe)
	public static function plan($npc, NPCVillage $v) {
		global $database, $generator;
		$avail = self::available($v);
		if(array_sum($avail) < NPC_RAID_MIN_UNITS) return null;
		$fullAttack = 0;
		foreach($avail as $unit => $n) { $fullAttack += $n * $GLOBALS['u'.$unit]['atk']; }

		$from = $database->getCoor($v->wref);
		$intel = NPCIntel::forNpc($npc['uid']);
		$fresh = time() - round(NPC_INTEL_MAX_AGE / SPEED);
		$best = null;
		foreach(self::targets($npc) as $t) {
			if($generator->procDistance($from, $t) > NPC_RAID_RADIUS) continue;
			if($database->hasBeginnerProtection($t['wref'])) continue;

			$known = isset($intel[$t['wref']]) && $intel[$t['wref']]['updated'] >= $fresh ? $intel[$t['wref']] : null;
			if($known) {
				// Known too strong (whole raid died) or nothing worth taking: leave it
				if($known['defence_known'] == 2 && $fullAttack < $known['defence'] * NPC_RAID_MIN_ADVANTAGE) continue;
				if($known['loot'] < 100) continue;
				$loot = intval($known['loot']);
				$defence = floatval($known['defence']);
				$raid = self::compose($avail, $loot, $defence);
			} else {
				// Unknown or stale: probe with a minimal raid
				$loot = NPC_RAID_PROBE_LOOT;
				$defence = 0;
				$raid = self::minimum($avail);
			}
			if(!$raid) continue;

			$seconds = $generator->procDistanceTime($from, $t, $raid['speed'], 1);
			// A person's judgement, not a formula: sometimes a worse target wins
			$score = min($loot, $raid['capacity']) / max(60, $seconds) * NPCHuman::noise(0.35);
			if($best === null || $score > $best['score']) {
				$best = array('target' => $t, 'units' => $raid['units'], 'capacity' => $raid['capacity'],
					'loot' => $loot, 'attack' => $raid['attack'], 'defence' => $defence,
					'seconds' => $seconds, 'score' => $score, 'probe' => $known === null);
			}
		}
		return $best;
	}

	// Villages this NPC may raid (distance and protection are checked in plan())
	private static function targets($npc, $cooldown = true) {
		global $database;
		$owners = "v.owner IN (SELECT uid FROM ".TB_PREFIX."npc) OR (v.owner = 3 AND v.name = 'Farm')";
		if(NPC_ATTACK_REAL_PLAYERS) {
			$owners .= " OR (v.owner > 4 AND v.owner NOT IN (SELECT uid FROM ".TB_PREFIX."npc))";
		}
		$recent = $cooldown ? time() - round(NPC_RAID_TARGET_COOLDOWN / SPEED) : time();
		return $database->query_return("SELECT v.wref, v.owner, v.name, w.x, w.y FROM ".TB_PREFIX."vdata v".
			" JOIN ".TB_PREFIX."wdata w ON w.id = v.wref".
			" WHERE v.owner <> ".intval($npc['uid'])." AND v.owner <> 2 AND v.natar = 0 AND v.pop > 0 AND (".$owners.")".
			" AND v.wref NOT IN (SELECT to_wref FROM ".TB_PREFIX."npc_raid WHERE uid = ".intval($npc['uid'])." AND sent_at > ".$recent.")");
	}

	// Dodge: all combat units raid a target far enough away that they return after
	// the hit at $arrive. Shortest such trip wins; targets known to be strong and
	// the attacker are avoided. If every target is still too close (the hit is far
	// off), returns array('wait' => when to look again); null = no army / no target.
	public static function dodgePlan($npc, NPCVillage $v, $arrive, $attackerWref) {
		global $database, $generator;
		$units = self::available($v, true);
		if(array_sum($units) < NPC_RAID_MIN_UNITS) return null;
		$raid = self::totals($units);
		$from = $database->getCoor($v->wref);
		$intel = NPCIntel::forNpc($npc['uid']);
		$left = $arrive - time();
		$best = null;
		$longest = 0;
		foreach(self::targets($npc, false) as $t) {
			if($t['wref'] == $attackerWref || $database->hasBeginnerProtection($t['wref'])) continue;
			if(isset($intel[$t['wref']]) && $intel[$t['wref']]['defence'] * 2 > $raid['attack']) continue;
			$seconds = $generator->procDistanceTime($from, $t, $raid['speed'], 1);
			$longest = max($longest, 2 * $seconds);
			if(2 * $seconds < $left + 30) continue;
			if($best === null || $seconds < $best['seconds']) $best = array('target' => $t, 'seconds' => $seconds);
		}
		if($best === null) {
			return $longest > 0 ? array('wait' => $arrive - max(120, round($longest * 0.6))) : null;
		}
		return array('target' => $best['target'], 'units' => $units, 'capacity' => $raid['capacity'],
			'loot' => $raid['capacity'], 'attack' => $raid['attack'], 'defence' => 0,
			'seconds' => $best['seconds'], 'probe' => false);
	}

	// Revenge on the village $wref with all offensive units that may leave (ignores
	// the target cooldown). null = not possible now (units, range, protection);
	// array('hopeless' => true) = the NPC's intel says it would lose.
	public static function revengePlan($npc, NPCVillage $v, $wref) {
		global $database, $generator;
		$rows = $database->query_return("SELECT v.wref, v.owner, v.name, w.x, w.y FROM ".TB_PREFIX."vdata v".
			" JOIN ".TB_PREFIX."wdata w ON w.id = v.wref WHERE v.wref = ".intval($wref)." AND v.owner <> ".intval($npc['uid']));
		if(empty($rows) || $database->hasBeginnerProtection($wref)) return null;
		$t = $rows[0];
		$avail = self::available($v);
		if(array_sum($avail) < NPC_RAID_MIN_UNITS) return null;
		$raid = self::totals($avail);
		$intel = NPCIntel::forNpc($npc['uid']);
		$defence = isset($intel[$wref]) ? floatval($intel[$wref]['defence']) : 0;
		if(isset($intel[$wref]) && $intel[$wref]['defence_known'] == 2 && $raid['attack'] < $defence) {
			return array('hopeless' => true);
		}
		$from = $database->getCoor($v->wref);
		if($generator->procDistance($from, $t) > NPC_RAID_RADIUS * 1.5) return null;
		return array('target' => $t, 'units' => $avail, 'capacity' => $raid['capacity'], 'loot' => $raid['capacity'],
			'attack' => $raid['attack'], 'defence' => $defence,
			'seconds' => $generator->procDistanceTime($from, $t, $raid['speed'], 1), 'probe' => false);
	}

	// Attack, capacity and slowest speed of a group of units
	private static function totals($units) {
		$out = array('attack' => 0, 'capacity' => 0, 'speed' => null);
		foreach($units as $unit => $n) {
			$out['attack'] += $n * $GLOBALS['u'.$unit]['atk'];
			$out['capacity'] += $n * $GLOBALS['u'.$unit]['cap'];
			$out['speed'] = $out['speed'] === null ? $GLOBALS['u'.$unit]['speed'] : min($out['speed'], $GLOBALS['u'.$unit]['speed']);
		}
		return $out;
	}

	// Smallest share of the available units that both carries the loot (+20 %) and
	// beats the defence by NPC_RAID_MIN_ADVANTAGE; null if even all of them can't win.
	private static function compose($avail, $loot, $defence) {
		$attack = $capacity = 0;
		foreach($avail as $unit => $n) {
			$attack += $n * $GLOBALS['u'.$unit]['atk'];
			$capacity += $n * $GLOBALS['u'.$unit]['cap'];
		}
		if($attack < $defence * NPC_RAID_MIN_ADVANTAGE || $capacity <= 0) return null;
		$scale = min(1, max($loot * 1.2 / $capacity, $attack > 0 ? $defence * NPC_RAID_MIN_ADVANTAGE / $attack : 0));

		$units = array();
		$sum = array('attack' => 0, 'capacity' => 0);
		$speed = null;
		foreach($avail as $unit => $n) {
			$send = (int)ceil($n * $scale);
			if($send <= 0) continue;
			$units[$unit] = $send;
			$sum['attack'] += $send * $GLOBALS['u'.$unit]['atk'];
			$sum['capacity'] += $send * $GLOBALS['u'.$unit]['cap'];
			$speed = $speed === null ? $GLOBALS['u'.$unit]['speed'] : min($speed, $GLOBALS['u'.$unit]['speed']);
		}
		if(array_sum($units) < NPC_RAID_MIN_UNITS) {
			// Little loot: send the minimum raid instead, if that still beats the defence
			$min = self::minimum($avail);
			return $min['attack'] >= $defence * NPC_RAID_MIN_ADVANTAGE ? $min : null;
		}
		return array('units' => $units, 'attack' => $sum['attack'], 'capacity' => $sum['capacity'], 'speed' => $speed);
	}

	// NPC_RAID_MIN_UNITS units, fastest first
	private static function minimum($avail) {
		uksort($avail, function($a, $b) { return $GLOBALS['u'.$b]['speed'] - $GLOBALS['u'.$a]['speed']; });
		$units = array();
		$left = NPC_RAID_MIN_UNITS;
		$attack = $capacity = 0;
		$speed = null;
		foreach($avail as $unit => $n) {
			$send = min($n, $left);
			if($send <= 0) break;
			$units[$unit] = $send;
			$left -= $send;
			$attack += $send * $GLOBALS['u'.$unit]['atk'];
			$capacity += $send * $GLOBALS['u'.$unit]['cap'];
			$speed = $speed === null ? $GLOBALS['u'.$unit]['speed'] : min($speed, $GLOBALS['u'.$unit]['speed']);
		}
		return array('units' => $units, 'attack' => $attack, 'capacity' => $capacity, 'speed' => $speed);
	}
}
