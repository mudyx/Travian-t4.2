<?php
/*
 * Economic decisions of an NPC village. propose() returns build candidates in
 * the order the NPC wants them; NPCController executes the first affordable one.
 *
 * Priorities:
 *   1. free crop (net production minus queued population) under the reserve
 *      -> lowest crop field
 *   2. warehouse / granary when they hold too few hours of production or
 *      can't fit the next planned build
 *   3. resource field of the type the village produces least of
 *      (weighted by profile)
 *   4. the profile's building plan
 * Field and building queues are independent; the profile decides which one
 * is tried first.
 */
class NPCEconomy {

	// Desired production ratio wood/clay/iron/crop per profile
	public static $weights = array(
		'farmer'    => array(1 => 1.0, 2 => 1.0, 3 => 0.9, 4 => 1.1),
		'balanced'  => array(1 => 1.0, 2 => 1.0, 3 => 1.0, 4 => 0.8),
		'aggressor' => array(1 => 1.0, 2 => 0.9, 3 => 1.1, 4 => 0.8),
	);

	// Free crop to keep, as a share of wood+clay+iron production per hour
	public static $cropReserve = array('farmer' => 0.10, 'balanced' => 0.10, 'aggressor' => 0.15);

	// Building plan: [building type, target level]; 'wall' = the tribe's wall.
	// Entries whose requirements aren't met yet are skipped and retried later.
	public static $plans = array(
		'farmer' => array(
			array(15,3), array(10,3), array(11,3), array(23,3), array(16,1), array(15,5), array(17,1),
			// barracks 3 + smithy 1: research requirements of the defensive units (praetorian, spearman)
			array(10,6), array(11,6), array(22,1), array(19,3), array(12,1), array(8,5), array(15,10), array(10,10),
			array(11,10), array(5,5), array(6,5), array(7,5), array(9,5), array(17,10), array(23,10),
		),
		'balanced' => array(
			array(15,3), array(16,1), array(10,3), array(11,3), array(19,3), array(23,3), array(15,5),
			array(22,3), array('wall',3), array(12,3), array(17,1), array(10,6), array(11,6), array(22,5),
			array(20,3), array(15,10), array(19,10), array('wall',10), array(10,10), array(11,10),
		),
		'aggressor' => array(
			array(15,3), array(16,1), array(19,3), array(10,3), array(11,3), array(15,5), array(22,5),
			array(12,3), array(20,3), array('wall',5), array(16,5), array(19,10), array(20,10), array(10,8),
			array(11,8), array(15,10), array(22,10), array(21,1), array(16,10), array(12,10),
		),
	);

	// Returns candidates: array('field'=>id, 'tid'=>type, 'level'=>target level, 'reason'=>text)
	// $fieldBusy/$buildingBusy mark a queue as taken although bdata says free
	// (catch-up: a backdated job the automation already finished is still
	// running at the replayed moment).
	public static function propose(NPCVillage $v, $profile, $fieldBusy = false, $buildingBusy = false) {
		if(!isset(self::$plans[$profile])) {
			$profile = 'balanced';
		}
		$fields = ($fieldBusy || $v->queueBusy(true)) ? array() : self::fieldCandidates($v, $profile);
		$field = $fields ? $fields[0] : null;
		$buildings = ($buildingBusy || $v->queueBusy(false)) ? array() : self::buildingCandidates($v, $profile, $field);
		$building = $buildings ? $buildings[0] : null;

		$crisis = $field && $field['reason'] == 'crop reserve';
		$storage = $building && $building['reason'] == 'storage';
		// Fields are cheap and quick, so their queue is free at almost every turn;
		// balanced NPCs switch to buildings once fields reach half the main building
		// level, otherwise they would never grow the village centre.
		$balancedBuilds = $profile == 'balanced' && $v->avgFieldLevel() >= $v->typeLevel(15) / 2;
		// Crop and storage problems first; then farmers prefer fields, aggressors buildings
		if($crisis || ($profile == 'farmer' && !$storage)) {
			$order = array($field, $building);
		} elseif($storage || $profile == 'aggressor' || $balancedBuilds) {
			$order = array($building, $field);
		} else {
			$order = array($field, $building);
		}
		// The runners-up (second-weakest resource, next plan step) go last;
		// NPCHuman::choose() still picks them now and then
		return array_values(array_filter(array_merge($order, array_slice($fields, 1), array_slice($buildings, 1))));
	}

	// Lowest field of the weakest resource, then one of the second weakest ('whim')
	private static function fieldCandidates(NPCVillage $v, $profile) {
		$reserve = self::$cropReserve[$profile] * ($v->prod('wood') + $v->prod('clay') + $v->prod('iron'));
		if($v->freeCrop() < $reserve) {
			$id = self::lowestField($v, 4);
			return $id ? array(array('field' => $id, 'tid' => 4, 'level' => $v->level($id) + 1, 'reason' => 'crop reserve')) : array();
		}
		$scores = array();
		foreach(self::$weights[$profile] as $t => $w) {
			if(self::lowestField($v, $t) == 0) continue;   // all fields of this type maxed
			$scores[$t] = $v->fieldProd($t) / $w;
		}
		asort($scores);
		$out = array();
		foreach(array_slice(array_keys($scores), 0, 2) as $type) {
			$id = self::lowestField($v, $type);
			$out[] = array('field' => $id, 'tid' => $type, 'level' => $v->level($id) + 1,
				'reason' => $out ? 'whim' : 'lowest production');
		}
		return $out;
	}

	// Lowest, not maxed, not queued field of a resource type
	private static function lowestField(NPCVillage $v, $type) {
		$best = 0;
		$max = NPCRules::maxLevel($type, $v->capital);
		for($i = 1; $i <= 18; $i++) {
			if($v->type($i) != $type || $v->level($i) >= $max || $v->isQueuedField($i)) continue;
			if($best == 0 || $v->level($i) < $v->level($best)) $best = $i;
		}
		return $best;
	}

	// Storage if needed, else the next plan step; the step after it as a 'whim'
	private static function buildingCandidates(NPCVillage $v, $profile, $fieldCandidate) {
		$plan = self::planCandidates($v, $profile);
		$planned = $plan ? $plan[0] : null;
		$c = self::storageCandidate($v, $fieldCandidate, $planned);
		if($c) return $planned ? array($c, $planned) : array($c);
		if(isset($plan[1])) $plan[1]['reason'] = 'whim, '.$plan[1]['reason'];
		return $plan;
	}

	private static function storageCandidate(NPCVillage $v, $fieldCandidate, $planned) {
		// Biggest single cost we are about to need, to check it fits the storage
		$need = array('res' => 0, 'crop' => 0);
		foreach(array($fieldCandidate, $planned) as $c) {
			if(!$c) continue;
			$cost = NPCRules::cost($v, $c['tid'], $c['level']);
			if(!$cost) continue;
			$need['res'] = max($need['res'], $cost['wood'], $cost['clay'], $cost['iron']);
			$need['crop'] = max($need['crop'], $cost['crop']);
		}
		$hours = NPC_STORAGE_HOURS;
		$grossCrop = $v->prod('crop') + floatval($v->vdata['upkeep']) + floatval($v->vdata['pop']);
		$maxProd = max($v->prod('wood'), $v->prod('clay'), $v->prod('iron'));
		if($v->vdata['maxstore'] < $hours * $maxProd || $need['res'] > 0.8 * $v->vdata['maxstore']) {
			$c = self::upgradeOrBuild($v, 10, 'storage');
			if($c) return $c;
		}
		if($v->vdata['maxcrop'] < $hours * $grossCrop || $need['crop'] > 0.8 * $v->vdata['maxcrop']) {
			$c = self::upgradeOrBuild($v, 11, 'storage');
			if($c) return $c;
		}
		return null;
	}

	// First two steps of the profile plan (different buildings) that can be done now
	private static function planCandidates(NPCVillage $v, $profile) {
		$out = $seen = array();
		foreach(self::$plans[$profile] as $step) {
			$tid = ($step[0] === 'wall') ? NPCRules::wallType($v->tribe) : $step[0];
			if($v->typeLevel($tid) >= $step[1] || $v->isQueuedType($tid) || isset($seen[$tid])) continue;
			$c = self::upgradeOrBuild($v, $tid, 'plan '.$tid.'->'.$step[1]);
			if(!$c) continue;
			$out[] = $c;
			$seen[$tid] = true;
			if(count($out) == 2) break;
		}
		return $out;
	}

	// Upgrade the existing building of a type, or construct it if allowed
	private static function upgradeOrBuild(NPCVillage $v, $tid, $reason) {
		if($v->isQueuedType($tid)) return null;
		$id = NPCRules::fieldOf($v, $tid);
		if($id && $v->level($id) > 0) {
			if($v->level($id) >= NPCRules::maxLevel($tid, $v->capital)) return null;
			return array('field' => $id, 'tid' => $tid, 'level' => $v->level($id) + 1, 'reason' => $reason);
		}
		if(!NPCRules::canConstruct($v, $tid)) return null;
		$id = NPCRules::slotFor($v, $tid);
		if(!$id) return null;
		return array('field' => $id, 'tid' => $tid, 'level' => 1, 'reason' => $reason);
	}
}
