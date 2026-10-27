<?php
/*
 * Military decisions of an NPC village: which unit to research next and what
 * to train with the army budget.
 *
 * Rules:
 *   - the army spends only its budget (a share of income per profile)
 *   - one research at a time; requirements as Technology::meetRRequirement()
 *   - the profile sets the wanted share of offensive units; the weaker
 *     category is trained with the best unit available
 *   - training never pushes free crop (incl. queued units) under the reserve
 */
class NPCMilitary {

	// Share of income saved for research + training
	public static $armyShare = array('farmer' => 0.10, 'balanced' => 0.30, 'aggressor' => 0.60);

	// Wanted share of offensive units (by population) in the army
	public static $offShare = array('farmer' => 0.0, 'balanced' => 0.5, 'aggressor' => 0.8);

	// Units per tribe and role, best first. Scouts, siege, chiefs and settlers come later.
	public static $units = array(
		1 => array('def' => array(6, 2, 1),  'off' => array(5, 3, 1)),     // Romans
		2 => array('def' => array(15, 12),   'off' => array(16, 13, 11)),  // Teutons
		3 => array('def' => array(25, 21),   'off' => array(24, 22)),      // Gauls
	);

	public static $barracks = array(1, 2, 3, 11, 12, 13, 14, 21, 22);
	public static $stables = array(4, 5, 6, 15, 16, 23, 24, 25, 26);
	public static $special = array(9, 10, 19, 20, 29, 30);   // chiefs and settlers: residence

	// Technology::meetRRequirement(): unit => [building type => level]
	private static $researchReq = array(
		2 => array(22 => 1, 12 => 1),   3 => array(22 => 5, 12 => 1),   4 => array(22 => 5, 20 => 1),
		5 => array(22 => 5, 20 => 5),   6 => array(22 => 5, 20 => 10),
		12 => array(22 => 1, 19 => 3),  13 => array(22 => 3, 11 => 1),  14 => array(22 => 1, 15 => 5),
		15 => array(22 => 1, 20 => 3),  16 => array(22 => 15, 20 => 10),
		22 => array(22 => 3, 12 => 1),  23 => array(22 => 5, 20 => 1),   24 => array(22 => 5, 20 => 3),
		25 => array(22 => 5, 20 => 5),  26 => array(22 => 15, 20 => 10),
	);

	public static function canResearch(NPCVillage $v, $unit) {
		if($v->isResearched($unit) || !isset(self::$researchReq[$unit]) || !isset($GLOBALS['r'.$unit])) {
			return false;
		}
		foreach(self::$researchReq[$unit] as $tid => $lvl) {
			if($v->typeLevel($tid) < $lvl) return false;
		}
		return true;
	}

	// Units in the order this profile wants them (wanted role first)
	private static function preferences(NPCVillage $v, $profile) {
		if(!isset(self::$units[$v->tribe])) return array();
		$u = self::$units[$v->tribe];
		$first = self::$offShare[$profile] >= 0.5 ? 'off' : 'def';
		$second = $first == 'off' ? 'def' : 'off';
		return array_values(array_unique(array_merge($u[$first], $u[$second])));
	}

	// Next unit to research, or null
	public static function researchCandidate(NPCVillage $v, $profile) {
		if($v->isResearching() || $v->typeLevel(22) == 0) return null;
		foreach(self::preferences($v, $profile) as $unit) {
			if(self::canResearch($v, $unit)) return $unit;
		}
		return null;
	}

	// Building that trains a unit (19 barracks / 20 stable / 25 residence), 0 if none
	public static function buildingFor($unit) {
		if(in_array($unit, self::$barracks)) return 19;
		if(in_array($unit, self::$stables)) return 20;
		if(in_array($unit, self::$special)) return 25;
		return 0;
	}

	public static function canTrain(NPCVillage $v, $unit) {
		$tid = self::buildingFor($unit);
		return $tid && $v->typeLevel($tid) > 0 && $v->isResearched($unit);
	}

	// Unit to train now: the role that is under its target share, best available unit
	// of that role; falls back to the other role when nothing of the wanted one exists.
	// $prefer ('off'/'def') overrides the share (defenders before an attack).
	public static function trainCandidate(NPCVillage $v, $profile, $prefer = null) {
		if(!isset(self::$units[$v->tribe])) return null;
		$roles = self::$units[$v->tribe];
		$pop = array('off' => 0, 'def' => 0);
		foreach($roles as $role => $list) {
			foreach($list as $unit) {
				$pop[$role] += $v->unitCount($unit) * $GLOBALS['u'.$unit]['pop'];
			}
		}
		$total = $pop['off'] + $pop['def'];
		$current = $total > 0 ? $pop['off'] / $total : 0;
		$want = $prefer ? $prefer : (($current < self::$offShare[$profile]) ? 'off' : 'def');
		foreach(array($want, $want == 'off' ? 'def' : 'off') as $role) {
			foreach($roles[$role] as $unit) {
				if(self::canTrain($v, $unit)) return array('unit' => $unit, 'role' => $role);
			}
		}
		return null;
	}

	// Milliseconds per unit, as Technology::trainUnit() (hero bonus left out: NPC heroes
	// have no training attributes, so the engine factor is 1 for them too)
	public static function eachTime(NPCVillage $v, $unit) {
		global $database;
		$tid = self::buildingFor($unit);
		$attri = $GLOBALS['bid'.$tid][$v->typeLevel($tid)]['attri'];
		return round($attri / 100 * $GLOBALS['u'.$unit]['time'] * $database->getArtEffTrain($v->wref) * 1000 / SPEED);
	}

	// End of the training queue of the building that trains $unit ($from - now by default - if empty)
	public static function queueEnd(NPCVillage $v, $unit, $from = null) {
		$groups = array(19 => self::$barracks, 20 => self::$stables, 25 => self::$special);
		$group = $groups[self::buildingFor($unit)];
		$end = $from === null ? time() : $from;
		foreach($v->training as $t) {
			if(in_array($t['unit'], $group)) $end = max($end, intval($t['endat']));
		}
		return $end;
	}

	// How many units to train: limited by budget, stock, queue length and crop reserve.
	// Returns array(amount, reason of the tightest limit).
	// $force (stock about to be stolen): no half-queue rule, queue up to twice as long.
	public static function trainAmount(NPCVillage $v, $unit, $budget, $cropReserve, $start = null, $force = false) {
		$start = $start === null ? time() : $start;
		$u = $GLOBALS['u'.$unit];
		$limits = array();
		$limits['budget'] = floor($budget / ($u['wood'] + $u['clay'] + $u['iron'] + $u['crop']));
		$limits['resources'] = max(0, floor(min($v->spendable('wood') / $u['wood'], $v->spendable('clay') / $u['clay'],
			$v->spendable('iron') / $u['iron'], $v->spendable('crop') / $u['crop'])));
		$queued = self::queueEnd($v, $unit, $start) - $start;
		// Refill only once the queue is under half its length: one big batch instead
		// of a new 1-unit row every turn
		$queueMax = $force ? 2 * NPC_TRAIN_QUEUE_SECONDS : NPC_TRAIN_QUEUE_SECONDS;
		$limits['queue'] = (!$force && $queued > NPC_TRAIN_QUEUE_SECONDS / 2) ? 0 :
			max(0, floor(($queueMax - $queued) * 1000 / max(1, self::eachTime($v, $unit))));
		$limits['crop'] = floor(($v->freeCrop() - $cropReserve) / $u['pop']);
		$amount = min($limits);
		return array(max(0, (int)$amount), array_search($amount, $limits));
	}
}
