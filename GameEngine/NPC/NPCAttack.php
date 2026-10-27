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
 * Reach: like a player, an NPC farms close by at first and further out as the
 * round goes on (reach()); when there is too little worth taking nearby
 * (farmed empty, too strong, protected) it looks further (x1.6, x2.5). The
 * map wraps at its edge, so does the search.
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

	// Fields this player looks for raid targets in: grows with the round (a few
	// weeks in, everyone farms further out) and with the player's aggression
	public static function reach($npc) {
		$days = max(0, time() - COMMENCE) * SPEED / 86400;
		$grow = 1 + min(NPC_RAID_REACH_GROWTH, $days / NPC_RAID_REACH_DAYS);
		return min(NPC_RAID_RADIUS_MAX, NPC_RAID_RADIUS * $grow * (0.8 + 0.4 * intval($npc['aggression']) / 100));
	}

	// Best raid from this village or null:
	// array(target, units, capacity, loot, attack, defence, seconds, score, probe, far)
	// Nearby first; with fewer than NPC_RAID_MIN_CHOICE worthwhile targets it looks further.
	public static function plan($npc, NPCVillage $v) {
		global $database, $generator;
		$avail = self::available($v);
		if(array_sum($avail) < NPC_RAID_MIN_UNITS) return null;
		$fullAttack = 0;
		foreach($avail as $unit => $n) { $fullAttack += $n * $GLOBALS['u'.$unit]['atk']; }

		$from = $database->getCoor($v->wref);
		$intel = NPCIntel::forNpc($npc['uid']);
		$fresh = time() - round(NPC_INTEL_MAX_AGE / SPEED);
		$reach = self::reach($npc);
		$options = $seen = $protected = array();
		foreach(array(1, 1.6, 2.5) as $ring => $f) {
			$radius = min(NPC_RAID_RADIUS_MAX, $reach * $f);
			if($ring > 0 && $radius <= $reach * 1.01) break;   // already at the limit
			foreach(self::targets($npc, true, $from, $radius) as $t) {
				if(isset($seen[$t['wref']])) continue;
				$dist = $generator->procDistance($from, $t);
				if($dist > $radius) continue;
				$seen[$t['wref']] = true;
				$known = isset($intel[$t['wref']]) && $intel[$t['wref']]['updated'] >= $fresh ? $intel[$t['wref']] : null;
				$o = self::option($t, $avail, $fullAttack, $from, $known);
				if(!$o) continue;
				$o['far'] = $ring > 0;
				$o['dist'] = $dist;
				$options[] = $o;
			}
			// Enough to choose from? Beginner protection is checked only for the best ones (a query each)
			usort($options, function($a, $b) { return $a['score'] < $b['score'] ? 1 : -1; });
			$open = 0;
			foreach($options as $o) {
				$w = $o['target']['wref'];
				if(!isset($protected[$w])) $protected[$w] = $database->hasBeginnerProtection($w);
				if(!$protected[$w] && ++$open >= NPC_RAID_MIN_CHOICE) break;
			}
			if($open >= NPC_RAID_MIN_CHOICE) break;
		}
		foreach($options as $o) {
			if(!$protected[$o['target']['wref']]) return $o;
		}
		return null;
	}

	// One target weighed: what to send and what it brings per second, null if not worth it
	private static function option($t, $avail, $fullAttack, $from, $known) {
		global $generator;
		if($known) {
			// Known too strong (whole raid died) or nothing worth taking: leave it
			if($known['defence_known'] == 2 && $fullAttack < $known['defence'] * NPC_RAID_MIN_ADVANTAGE) return null;
			if($known['loot'] < 100) return null;
			$loot = intval($known['loot']);
			$defence = floatval($known['defence']);
			$raid = self::compose($avail, $loot, $defence);
		} else {
			// Unknown or stale: probe with a minimal raid
			$loot = NPC_RAID_PROBE_LOOT;
			$defence = 0;
			$raid = self::minimum($avail);
		}
		if(!$raid) return null;

		$seconds = $generator->procDistanceTime($from, $t, $raid['speed'], 1);
		// A person's judgement, not a formula: sometimes a worse target wins
		$score = min($loot, $raid['capacity']) / max(60, $seconds) * NPCHuman::noise(0.35);
		return array('target' => $t, 'units' => $raid['units'], 'capacity' => $raid['capacity'],
			'loot' => $loot, 'attack' => $raid['attack'], 'defence' => $defence,
			'seconds' => $seconds, 'score' => $score, 'probe' => $known === null);
	}

	// Villages this NPC may raid (distance and protection are checked in plan())
	// $from/$radius: only villages in that square of the map (hundreds of NPCs), across the edge too
	private static function targets($npc, $cooldown = true, $from = null, $radius = 0) {
		global $database;
		$owners = "v.owner IN (SELECT uid FROM ".TB_PREFIX."npc) OR (v.owner = 3 AND v.name = 'Farm')";
		if(NPC_ATTACK_REAL_PLAYERS) {
			$owners .= " OR (v.owner > 4 AND v.owner NOT IN (SELECT uid FROM ".TB_PREFIX."npc))";
		}
		$recent = $cooldown ? time() - round(NPC_RAID_TARGET_COOLDOWN / SPEED) : time();
		return $database->query_return("SELECT v.wref, v.owner, v.name, w.x, w.y FROM ".TB_PREFIX."vdata v".
			" JOIN ".TB_PREFIX."wdata w ON w.id = v.wref".
			" WHERE ".($from ? self::span('w.x', $from['x'], $radius)." AND ".self::span('w.y', $from['y'], $radius)." AND " : "").
			"v.owner <> ".intval($npc['uid'])." AND v.owner <> 2 AND v.natar = 0 AND v.pop > 0 AND (".$owners.")".
			" AND v.wref NOT IN (SELECT to_wref FROM ".TB_PREFIX."npc_raid WHERE uid = ".intval($npc['uid'])." AND sent_at > ".$recent.")");
	}

	// $col within $r of $c on a map that wraps at WORLD_MAX (as Generator::procDistance)
	private static function span($col, $c, $r) {
		$size = 2 * WORLD_MAX + 1;
		$r = (int)ceil($r);
		if(2 * $r + 1 >= $size) return '1';
		$lo = intval($c) - $r;
		$hi = intval($c) + $r;
		if($lo < -WORLD_MAX) return "(".$col." >= ".($lo + $size)." OR ".$col." <= ".$hi.")";
		if($hi > WORLD_MAX) return "(".$col." >= ".$lo." OR ".$col." <= ".($hi - $size).")";
		return $col." BETWEEN ".$lo." AND ".$hi;
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
		$radius = min(NPC_RAID_RADIUS_MAX, 2 * self::reach($npc));
		foreach(self::targets($npc, false, $from, $radius) as $t) {
			if($generator->procDistance($from, $t) > $radius) continue;
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
		// Anger carries further than greed, but not across the whole map
		if($generator->procDistance($from, $t) > min(NPC_RAID_RADIUS_MAX, self::reach($npc) * 1.5)) return null;
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
