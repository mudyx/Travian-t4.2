<?php
/*
 * Oases for NPCs, by the engine's rules (Battle.php / sendunitsComplete.php):
 * a raid with the hero that leaves no animal alive on a free oasis within
 * reach of the village (distance < 4.95) annexes it, if the heroes' mansion has
 * a free slot (level 10/15/20 -> 1/2/3 oases). The bonus goes to the village.
 *
 *   mansion   the hero's home village gets the heroes' mansion into its plan
 *             (level 10, +5 per oasis it has) once it has grown a bit
 *   target    free oases in reach; the animals are visible on the map, so the
 *             player sizes the troops for them. A bonus the village needs
 *             (its weakest resource, crop for an army) is worth more
 *   clearing  troops + hero when the attack beats the animals with the
 *             player's safety margin and the hero survives the expected losses;
 *             with enough cages the hero catches the animals instead (no
 *             fight) and takes the empty oasis on the next ride
 */
class NPCOasis {

	const REACH = 4.9497474683058;   // Battle.php: annexing distance

	// Adds the heroes' mansion to the plan of the hero's home village (live and catch-up)
	public static function shape($npc, NPCVillage $v) {
		global $database;
		$minPop = $npc['profile'] == 'aggressor' ? 300 : 180;
		if(intval($v->vdata['pop']) < $minPop) return;
		$hero = $database->getHero(intval($npc['uid']));
		if(!$hero || $hero['wref'] != $v->wref) return;
		$have = count($database->getOasis($v->wref));
		if($have >= 3) return;
		$v->plan[] = array(37, 10 + 5 * $have, 'oasis');
	}

	// Sends the hero (with troops or cages) to take an oasis. Returns true if it went.
	public static function turn(NPCController $ctl, $npc, $hero, NPCVillage $v) {
		global $database, $generator;
		$uid = intval($npc['uid']);
		$mansion = $v->typeLevel(37);
		if($mansion < 10) return false;
		$state = NPCHero::state($uid);
		$have = count($database->getOasis($v->wref));
		if($have > intval($state['oases'])) {
			$ctl->log($uid, $v->wref, 'oasis_taken', 'the village now has '.$have.' oasis/oases');
		}
		if($have != intval($state['oases'])) NPCHero::saveState($uid, array('oases' => $have));
		$slots = floor(($mansion - 5) / 5);
		if($slots <= $have || $hero['health'] < NPCHero::keep($npc)) return false;
		// An oasis ride already under way
		$busy = $database->query_return("SELECT 1 FROM ".TB_PREFIX."movement m JOIN ".TB_PREFIX."odata o ON o.wref = m.`to`".
			" WHERE m.`from` = ".$v->wref." AND m.sort_type = 3 AND m.proc = 0 LIMIT 1");
		if(!empty($busy)) return false;

		$c = $database->getCoor($v->wref);
		$x = intval($c['x']); $y = intval($c['y']);
		$oases = $database->query_return("SELECT o.wref, o.type, w.x, w.y FROM ".TB_PREFIX."odata o JOIN ".TB_PREFIX."wdata w ON w.id = o.wref".
			" WHERE o.conqured = 0 AND w.x BETWEEN ".($x - 4)." AND ".($x + 4)." AND w.y BETWEEN ".($y - 4)." AND ".($y + 4));
		if(empty($oases)) return false;

		$avail = NPCAttack::available($v);
		$army = 0; $cav = 0;
		foreach($avail as $unit => $n) {
			$army += $n * $GLOBALS['u'.$unit]['atk'];
			if(in_array($unit, NPCMilitary::$stables)) $cav += $n * $GLOBALS['u'.$unit]['atk'];
		}
		$cavShare = $army > 0 ? $cav / $army : 0;
		$attack = $army + NPCHero::attack($hero);
		$cages = 0;
		foreach($database->query_return("SELECT num FROM ".TB_PREFIX."heroitems WHERE uid = $uid AND btype = 9 AND num > 0 AND proc = 0") as $r) { $cages += $r['num']; }
		$face = $database->getHeroFace($uid);
		if($face['bag']) {
			$bag = $database->getHeroItem($face['bag']);
			if($bag && $bag['btype'] == 9) $cages += intval($face['num']);
		}
		$caution = 1.5 + NPCHero::keep($npc) / 40;   // 1.9 .. 2.9 x the animals' defence
		$need = self::needs($npc, $v);
		$scores = $plans = array();
		foreach($oases as $o) {
			if(sqrt(pow($o['x'] - $x, 2) + pow($o['y'] - $y, 2)) >= self::REACH) continue;
			list($animals, $defence) = self::animals($o['wref'], $cavShare);
			$value = self::bonus($o['type'], $need);
			if($animals == 0) {
				// Animals come back to an oasis left alone for long (oasesTrain() on arrival): troops go along.
				// Even empty, Battle.php gives the oasis a defence of 10 and a hero without strength (level 0)
				// has no attack at all: alone it would lose and die.
				if($attack < 10 * $caution || $hero['health'] - 100 * NPCHero::lossShare(10, $attack) < NPCHero::keep($npc)) continue;
				$plan = array('units' => $avail, 'attack' => $attack, 'how' => 'takes the empty oasis');
			} elseif($cages * CAGE_NUMBER >= $animals) {
				$plan = array('units' => array(), 'attack' => 0, 'how' => 'catches '.$animals.' animal(s) with cages', 'cages' => true);
			} elseif($attack >= $defence * $caution && $hero['health'] - 100 * NPCHero::lossShare($defence, $attack) >= NPCHero::keep($npc)) {
				$plan = array('units' => $avail, 'attack' => $attack, 'how' => 'clears '.$animals.' animal(s) (defence '.round($defence).')');
			} else {
				continue;
			}
			$scores[$o['wref']] = $value / (1 + $defence / 2000);
			$plans[$o['wref']] = $plan + array('target' => $o, 'defence' => $defence);
		}
		if(empty($scores)) {
			$ctl->log($uid, $v->wref, 'oasis_wait', 'heroes\' mansion ready, but no oasis in reach it can take yet', true);
			return false;
		}
		$p = $plans[NPCHuman::pickBest($scores, $npc)];
		if(!empty($p['cages'])) NPCHero::fillBag($uid, 9);
		elseif(!empty($p['units'])) NPCHero::fillBag($uid, 8) || NPCHero::fillBag($uid, 7);
		$speed = $hero['speed'] + $hero['itemspeed'];
		$capacity = 0;
		foreach($p['units'] as $unit => $n) {
			$speed = min($speed, $GLOBALS['u'.$unit]['speed']);
			$capacity += $n * $GLOBALS['u'.$unit]['cap'];
		}
		$o = $p['target'];
		$plan = array('target' => array('wref' => $o['wref'], 'x' => $o['x'], 'y' => $o['y'], 'name' => 'oasis'),
			'units' => $p['units'], 'hero' => true, 'capacity' => $capacity, 'loot' => $capacity, 'attack' => $p['attack'],
			'defence' => $p['defence'], 'seconds' => $generator->procDistanceTime($c, $o, $speed, 1, $hero), 'probe' => false);
		if(!NPCActions::sendRaid($uid, $v, $plan)) return false;
		$ctl->log($uid, $v->wref, 'oasis', 'hero '.$p['how'].' at '.$o['x'].'|'.$o['y'].' (type '.$o['type'].', '.
			array_sum($p['units']).' troops along), arrives in '.$plan['seconds'].'s');
		return true;
	}

	// Animals on an oasis and their defence against this mix of infantry/cavalry
	private static function animals($wref, $cavShare) {
		global $database;
		$u = $database->getUnit($wref);
		$n = 0; $def = 0;
		for($i = 31; $i <= 40; $i++) {
			$k = intval($u['u'.$i]);
			$n += $k;
			$def += $k * ($GLOBALS['u'.$i]['di'] * (1 - $cavShare) + $GLOBALS['u'.$i]['dc'] * $cavShare);
		}
		return array($n, $def);
	}

	// How much the village wants each resource bonus (1 .. 2)
	private static function needs($npc, NPCVillage $v) {
		$prod = array();
		foreach(array(1 => 'wood', 2 => 'clay', 3 => 'iron', 4 => 'crop') as $i => $r) { $prod[$i] = max(1, $v->prod($r)); }
		$avg = array_sum($prod) / 4;
		$need = array();
		foreach($prod as $i => $p) { $need[$i] = max(1, min(2, $avg / $p)); }
		if($npc['profile'] == 'aggressor') $need[4] *= 1.3;   // an army eats
		return $need;
	}

	// Oasis type -> bonus value (updateResource.php: 25 % per symbol)
	private static function bonus($type, $need) {
		$t = intval($type);
		$res = array(1 => array(1 => 1), 2 => array(1 => 2), 3 => array(1 => 1, 4 => 1), 4 => array(2 => 1), 5 => array(2 => 2),
			6 => array(2 => 1, 4 => 1), 7 => array(3 => 1), 8 => array(3 => 2), 9 => array(3 => 1, 4 => 1), 10 => array(4 => 1),
			11 => array(4 => 1), 12 => array(4 => 2));
		$v = 0;
		if(isset($res[$t])) foreach($res[$t] as $r => $n) { $v += $n * $need[$r]; }
		return $v;
	}
}
