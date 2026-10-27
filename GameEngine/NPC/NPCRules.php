<?php
/*
 * Game rules from Building.php, rewritten without $session/$village so the
 * NPC runner can use them. Kept deliberately close to the originals:
 *   maxLevel()        Building::isMax()
 *   cost()            Building::resourceRequired()
 *   canConstruct()    Building::meetRequirement()
 *   slotFor()         Building::constructBuilding() slot rules
 * Where the engine is buggy the NPC uses the stricter, intended rule, so an
 * NPC never does something a player couldn't.
 */
class NPCRules {

	// Buildings an NPC never constructs: 13 has no data in T4 (merged into the
	// smithy), 38/39/40 need artefacts or a WW plan, 26/29/30/42 need checks
	// across villages, 35/41 are tribe specials we don't model yet.
	private static $excluded = array(13, 26, 29, 30, 35, 38, 39, 40, 41, 42);

	public static function wallType($tribe) {
		$walls = array(1 => 31, 2 => 32, 3 => 33);
		return isset($walls[$tribe]) ? $walls[$tribe] : 0;
	}

	public static function maxLevel($tid, $capital) {
		if($tid <= 4) {
			return $capital ? 20 : 10;
		}
		return max(array_keys($GLOBALS['bid'.$tid]));
	}

	// Cost, population and build time of $tid at $level, as Building::resourceRequired().
	public static function cost(NPCVillage $v, $tid, $level) {
		$data = $GLOBALS['bid'.$tid];
		if(!isset($data[$level])) {
			return false;
		}
		$row = $data[$level];
		$mb = $v->typeLevel(15);
		if($tid == 15) {
			$time = ($mb == 0) ? round($row['time'] / SPEED * 5) : round($row['time'] / SPEED);
		} else {
			$time = ($mb != 0) ? round($row['time'] * ($GLOBALS['bid15'][$mb]['attri'] / 100) / SPEED) : round($row['time'] * 5 / SPEED);
		}
		return array('wood' => $row['wood'], 'clay' => $row['clay'], 'iron' => $row['iron'], 'crop' => $row['crop'],
			'pop' => $row['pop'], 'cp' => $row['cp'], 'time' => $time);
	}

	// May a new building of type $tid be constructed? (Building::meetRequirement)
	public static function canConstruct(NPCVillage $v, $tid) {
		if(in_array($tid, self::$excluded) || !isset($GLOBALS['bid'.$tid])) {
			return false;
		}
		if(in_array($tid, array(31, 32, 33)) && $tid != self::wallType($v->tribe)) {
			return false;
		}
		$lvl = $v->typeLevel($tid);
		$queued = $v->isQueuedType($tid);
		// A second warehouse/granary only once the first is maxed; same for crannies at 10
		switch($tid) {
			case 10: case 11:
				if(($lvl > 0 && $lvl < 20) || ($lvl == 0 && $queued)) return false;
				break;
			case 23:
				if(($lvl > 0 && $lvl < 10) || ($lvl == 0 && $queued)) return false;
				break;
			default:
				if($queued || $lvl > 0) return false;
		}
		$L = function($t) use ($v) { return $v->typeLevel($t); };
		switch($tid) {
			case 1: case 2: case 3: case 4: case 11: case 15: case 16: case 18: case 23: case 31: case 32: case 33:
				return true;
			case 10: return $L(15) >= 1;
			case 5:  return $L(1) >= 10 && $L(15) >= 5;
			case 6:  return $L(2) >= 10 && $L(15) >= 5;
			case 7:  return $L(3) >= 10 && $L(15) >= 5;
			case 8:  return $L(4) >= 5;
			case 9:  return $L(15) >= 5 && $L(4) >= 10 && $L(8) >= 5;
			case 12: return $L(22) >= 1 && $L(15) >= 3;
			case 14: return $L(16) >= 15;
			case 17: return $L(15) >= 3 && $L(10) >= 1 && $L(11) >= 1;
			case 19: return $L(15) >= 3 && $L(16) >= 1;
			// The engine lists stable twice and the first case (main building 1) wins;
			// the NPC uses the intended rule.
			case 20: return $L(12) >= 3 && $L(22) >= 5;
			case 21: return $L(22) >= 10 && $L(15) >= 5;
			case 22: return $L(15) >= 3 && $L(16) >= 1;
			case 24: return $L(22) >= 10 && $L(15) >= 10;
			case 25: return $L(15) >= 5 && $L(26) == 0;
			case 27: return $L(15) >= 10;
			case 28: return $L(17) == 20 && $L(20) >= 10;
			case 34: return $L(26) >= 3 && $L(15) >= 5 && $L(25) == 0;
			case 36: return $L(16) >= 1 && $v->tribe == 3;
			case 37: return $L(15) >= 3 && $L(16) >= 1;
		}
		return false;
	}

	// Field id where a new building of type $tid goes: rally point 39, wall 40,
	// anything else the first empty building slot (Building::constructBuilding()).
	public static function slotFor(NPCVillage $v, $tid) {
		if($tid == 16) {
			return ($v->type(39) == 0 && !$v->isQueuedField(39)) ? 39 : 0;
		}
		if(in_array($tid, array(31, 32, 33))) {
			return ($v->type(40) == 0 && !$v->isQueuedField(40)) ? 40 : 0;
		}
		for($i = 19; $i <= 38; $i++) {
			if($v->type($i) == 0 && !$v->isQueuedField($i)) return $i;
		}
		return 0;
	}

	// Field id holding the highest level of $tid, 0 if none (upgrade target)
	public static function fieldOf(NPCVillage $v, $tid) {
		$best = 0;
		for($i = 19; $i <= 40; $i++) {
			if($v->type($i) == $tid && ($best == 0 || $v->level($i) > $v->level($best))) $best = $i;
		}
		return $best;
	}
}
