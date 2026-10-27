<?php
/*
 * Raid planning (attack_type 4) for NPCs. plan() picks the best target in
 * range and the units to send; NPCActions::sendRaid() puts it into the game.
 *
 * Targets: other NPC villages and Nature "Farm" villages; real players only
 * when NPC_ATTACK_REAL_PLAYERS is on. Never: own villages, Natars, beginner
 * protection, targets this NPC hit recently, targets with a defence the
 * raid can't beat by NPC_RAID_MIN_ADVANTAGE.
 * Units: offensive units only; defensive ones never leave and
 * NPC_RAID_HOME_RESERVE of the offensive ones stays home too.
 *
 * Defence is read from the target's units (fine for NPCs and farms; real
 * players should be scouted first - left for when player raids are enabled).
 */
class NPCAttack {

	public static function canRaid($npc) {
		return in_array($npc['profile'], explode(',', NPC_RAID_PROFILES));
	}

	// Seconds between raids of this NPC: aggression 80 -> 0.7 x base, 20 -> 1.3 x base
	public static function interval($npc) {
		return round(NPC_RAID_INTERVAL / SPEED * (1.5 - $npc['aggression'] / 100));
	}

	// Raids of this NPC still on the way there or back
	public static function activeRaids($uid) {
		global $database;
		$row = mysql_fetch_row($database->query("SELECT COUNT(DISTINCT r.id) FROM ".TB_PREFIX."npc_raid r".
			" JOIN ".TB_PREFIX."movement m ON m.ref = r.ref AND m.sort_type IN (3,4) AND m.proc = 0".
			" WHERE r.uid = ".intval($uid)));
		return intval($row[0]);
	}

	// Offensive units that may leave: unit => count (home reserve already kept back)
	public static function available(NPCVillage $v) {
		$units = array();
		if(!isset(NPCMilitary::$units[$v->tribe])) return $units;
		foreach(NPCMilitary::$units[$v->tribe]['off'] as $unit) {
			$home = isset($v->units['u'.$unit]) ? intval($v->units['u'.$unit]) : 0;
			$free = (int)floor($home * (1 - NPC_RAID_HOME_RESERVE));
			if($free > 0) $units[$unit] = $free;
		}
		return $units;
	}

	// Best raid from this village or null:
	// array(target, units, capacity, loot, attack, defence, seconds, score)
	public static function plan($npc, NPCVillage $v) {
		global $database, $generator;
		$avail = self::available($v);
		if(array_sum($avail) < NPC_RAID_MIN_UNITS) return null;

		$from = $database->getCoor($v->wref);
		$best = null;
		foreach(self::targets($npc) as $t) {
			$distance = $generator->procDistance($from, $t);
			if($distance > NPC_RAID_RADIUS) continue;
			if($database->hasBeginnerProtection($t['wref'])) continue;

			$loot = self::lootable($t['wref']);
			if($loot < 100) continue;
			$defence = self::defence($t['wref'], $avail);
			$raid = self::compose($avail, $loot, $defence);
			if(!$raid) continue;

			$seconds = $generator->procDistanceTime($from, $t, $raid['speed'], 1);
			$score = min($loot, $raid['capacity']) / max(60, $seconds);
			if($best === null || $score > $best['score']) {
				$best = array('target' => $t, 'units' => $raid['units'], 'capacity' => $raid['capacity'],
					'loot' => $loot, 'attack' => $raid['attack'], 'defence' => $defence,
					'seconds' => $seconds, 'score' => $score);
			}
		}
		return $best;
	}

	// Villages this NPC may raid (distance and protection are checked in plan())
	private static function targets($npc) {
		global $database;
		$owners = "v.owner IN (SELECT uid FROM ".TB_PREFIX."npc) OR (v.owner = 3 AND v.name = 'Farm')";
		if(NPC_ATTACK_REAL_PLAYERS) {
			$owners .= " OR (v.owner > 4 AND v.owner NOT IN (SELECT uid FROM ".TB_PREFIX."npc))";
		}
		$recent = time() - round(NPC_RAID_TARGET_COOLDOWN / SPEED);
		return $database->query_return("SELECT v.wref, v.owner, v.name, w.x, w.y FROM ".TB_PREFIX."vdata v".
			" JOIN ".TB_PREFIX."wdata w ON w.id = v.wref".
			" WHERE v.owner <> ".intval($npc['uid'])." AND v.owner <> 2 AND v.natar = 0 AND v.pop > 0 AND (".$owners.")".
			" AND v.wref NOT IN (SELECT to_wref FROM ".TB_PREFIX."npc_raid WHERE uid = ".intval($npc['uid'])." AND sent_at > ".$recent.")");
	}

	// Resources the raid could take now: stock projected to this second (as the
	// lazy update would), minus what the target's crannies hide.
	private static function lootable($wref) {
		global $database, $bid23;
		$vd = $database->getVillage($wref);
		$fd = $database->getResourceLevel($wref);
		$hidden = 0;
		for($i = 19; $i <= 40; $i++) {
			if($fd['f'.$i.'t'] == 23 && $fd['f'.$i] > 0) $hidden += $bid23[$fd['f'.$i]]['attri'];
		}
		$hours = max(0, time() - $vd['lastupdate']) / 3600;
		$loot = 0;
		foreach(array('wood', 'clay', 'iron', 'crop') as $res) {
			$max = $res == 'crop' ? $vd['maxcrop'] : $vd['maxstore'];
			$stock = min($max, $vd[$res] + max(0, $vd[$res.'p']) * $hours);
			$loot += max(0, $stock - $hidden);
		}
		return (int)$loot;
	}

	// Defence of the target's own and reinforcing units against our infantry/cavalry mix
	private static function defence($wref, $avail) {
		global $database;
		$inf = $cav = 0;
		foreach($avail as $unit => $n) {
			if(in_array($unit, NPCMilitary::$stables)) $cav += $n; else $inf += $n;
		}
		$cavShare = ($inf + $cav) > 0 ? $cav / ($inf + $cav) : 0;
		$armies = $database->getEnforceVillage($wref, 0);
		$armies[] = $database->getUnit($wref);
		$def = 0;
		foreach($armies as $army) {
			for($i = 1; $i <= 50; $i++) {
				if(empty($army['u'.$i])) continue;
				$u = $GLOBALS['u'.$i];
				$def += $army['u'.$i] * ($u['di'] * (1 - $cavShare) + $u['dc'] * $cavShare);
			}
		}
		return $def;
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
