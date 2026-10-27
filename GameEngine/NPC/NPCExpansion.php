<?php
/*
 * Founding new villages, one at a time per NPC (s1_npc_expansion):
 *
 *   idle     ready? (fields, population, village limit by profile) and culture
 *            points close enough (NPC_EXPAND_CP_AHEAD) -> decides, by mood, to expand
 *   prepare  the mother village builds main building 5, residence 10 and a
 *            warehouse/granary that holds one settler's price
 *   train    3 settlers in the residence, one at a time; the price of the
 *            next one is kept back from other spending (NPCVillage::$reserve)
 *   send     enough culture points + a free slot -> picks a field and sends
 *            the settlers (750 of each resource, as Units::Settlers)
 *   travel   the engine founds the village on arrival (sendSettlersComplete);
 *            if the field was taken meanwhile the settlers come back -> send again
 *
 * Field choice: free, outside the Natar area, NPC_EXPAND_MIN_DIST..RADIUS fields
 * from the mother village; scored by field type (profile taste), distance,
 * free oases nearby, crowding and enemies remembered from attacks - drawn like
 * every other choice (NPCHuman::pickBest).
 * A founded village gets a role (crop / front / eco) and that role's starting plan.
 * A young colony no merchants come to (the other villages are poor, under attack
 * or the player doesn't bother) stops waiting after NPC_COLONY_ALONE_HOURS and
 * fends for itself: fields first, a cranny for its little stock, nothing else,
 * until its fields carry it (NPC_COLONY_SELF_FIELDS) or help arrives.
 */
class NPCExpansion {

	// Field type => taste per profile (1 = 3-3-3-9, 3 = 4-4-4-6, 6 = 1-1-1-15); others 1.0
	private static $siteTaste = array(
		'farmer'    => array(6 => 3.0, 1 => 2.2, 3 => 1.5),
		'balanced'  => array(3 => 2.0, 1 => 1.6, 6 => 1.4),
		'aggressor' => array(1 => 2.5, 6 => 2.0, 3 => 1.5),
	);

	// Starting plan of a founded village by role: [type, level, reason]
	private static $colonyPlans = array(
		'eco'   => array(array(15,3,'colony'), array(10,3,'colony'), array(11,3,'colony'), array(16,1,'colony'),
			array(15,5,'colony'), array(10,6,'colony'), array(11,6,'colony')),
		'crop'  => array(array(15,3,'colony'), array(11,3,'colony'), array(10,3,'colony'), array(16,1,'colony'),
			array(11,6,'colony'), array(15,5,'colony'), array(19,3,'colony')),
		'front' => array(array(15,3,'colony'), array(10,3,'colony'), array(11,3,'colony'), array(16,1,'colony'),
			array('wall',5,'colony'), array(19,3,'colony'), array(15,5,'colony')),
	);

	// What a colony on its own builds besides fields (storage comes by itself when needed)
	private static $alonePlan = array(array(23,2,'alone'), array(15,3,'alone'));

	private static $cache = array();   // uid => array('state' => row, 'roles' => wref => role)

	// One step of the NPC's expansion in a live session (no expansion in catch-up).
	public static function turn(NPCController $ctl, $npc, $villages) {
		global $database;
		$uid = intval($npc['uid']);
		$e = self::state($uid);
		$v = null;
		foreach($villages as $vv) { if($vv->wref == $e['from_wref']) $v = $vv; }
		if($e['state'] != 'idle' && !$v) {
			$e = self::setState($uid, 'idle');   // the mother village is gone
		}

		switch($e['state']) {
		case 'idle':
			if(count($villages) >= self::profileValue(NPC_EXPAND_MAX, $npc['profile'], 3)) break;
			$row = mysql_fetch_row($database->query("SELECT COUNT(*) FROM ".TB_PREFIX."vdata WHERE owner IN (SELECT uid FROM ".TB_PREFIX."npc)"));
			if($row[0] >= (NPC_VILLAGE_CAP > 0 ? NPC_VILLAGE_CAP : 3 * NPCWorld::maxNpcs())) break;   // map/server limit
			$mother = self::motherVillage($npc, $villages);
			if(!$mother) break;
			list($have, $need, $eta) = self::culturePoints($uid, count($villages));
			if($eta > NPC_EXPAND_CP_AHEAD) {
				$ctl->log($uid, 0, 'expand_wait', 'culture points '.round($have).'/'.$need.', enough in '.
					($eta === null ? 'never' : round($eta / 3600, 1).'h'), true);
				break;
			}
			// A decision, not a trigger: some sessions it just doesn't think of it yet
			$odds = array('build' => 0.6, 'save' => 0.4, 'raid' => 0.2);
			if(!NPCHuman::chance(isset($odds[$npc['mood']]) ? $odds[$npc['mood']] : 0.4)) break;
			self::setState($uid, 'prepare', $mother->wref);
			$ctl->log($uid, $mother->wref, 'expand_plan', 'wants village '.(count($villages) + 1).', culture points '.
				round($have).'/'.$need.', settlers from '.$mother->vdata['name']);
			break;

		case 'prepare':
			if(self::prepSteps($v)) break;   // still building residence / storage
			if($database->getFreeExpansionSlots($v->wref) < 1) {
				self::setState($uid, 'idle');   // residence used up - another village has to do it
				$ctl->log($uid, $v->wref, 'expand_wait', 'no free expansion slot in '.$v->vdata['name']);
				break;
			}
			self::setState($uid, 'train', $v->wref);
			$ctl->log($uid, $v->wref, 'expand_train', 'residence ready, training settlers');
			// no break: start training right away

		case 'train':
			$unit = $v->tribe * 10;
			$home = isset($v->units['u'.$unit]) ? intval($v->units['u'.$unit]) : 0;
			$have = $v->unitCount($unit);
			if($have < 3 && !$v->isQueuedUnit($unit)) {
				$cost = NPCActions::queueTraining($v, $unit, 1);
				if($cost) {
					$ctl->log($uid, $v->wref, 'train', '1x settler ('.($have + 1).'/3), done in '.$cost['time'].'s, cost '.
						$cost['wood'].'/'.$cost['clay'].'/'.$cost['iron'].'/'.$cost['crop']);
					$v->refresh();
				}
			}
			if($home < 3) break;
			self::setState($uid, 'send', $v->wref);
			// no break: send right away

		case 'send':
			list($have, $need) = self::culturePoints($uid, count($villages));
			if($have < $need) {
				$ctl->log($uid, $v->wref, 'expand_wait', 'settlers ready, culture points '.round($have).'/'.$need, true);
				break;
			}
			if($database->getFreeExpansionSlots($v->wref) - count($database->getMovement(5, $v->wref, 0)) < 1) {
				self::setState($uid, 'idle');
				break;
			}
			$site = self::pickSite($npc, $v);
			if(!$site) {
				$ctl->log($uid, $v->wref, 'expand_wait', 'no good free field within '.NPC_EXPAND_RADIUS.' fields', true);
				break;
			}
			$seconds = NPCActions::sendSettlers($v, $site['id']);
			if($seconds === false) {
				$ctl->log($uid, $v->wref, 'expand_wait', 'settlers or 4x750 resources missing', true);
				break;
			}
			self::setState($uid, 'travel', $v->wref, $site['id']);
			$ctl->log($uid, $v->wref, 'settle', 'settlers to '.$site['x'].'|'.$site['y'].' (field type '.$site['fieldtype'].
				', '.round($site['dist'], 1).' fields, score '.round($site['score'], 2).'), arrive in '.$seconds.'s');
			break;

		case 'travel':
			if(count($database->query_return("SELECT moveid FROM ".TB_PREFIX."movement WHERE sort_type = 5 AND proc = 0".
				" AND `from` = ".$v->wref." AND `to` = ".intval($e['target_wref']))) > 0) break;
			$owner = $database->query_return("SELECT owner FROM ".TB_PREFIX."vdata WHERE wref = ".intval($e['target_wref']));
			if(!empty($owner) && $owner[0]['owner'] == $uid) {
				$role = self::roleFor($uid, $e['target_wref']);
				$database->query("REPLACE INTO ".TB_PREFIX."npc_village (wref, uid, role, founded) VALUES (".
					intval($e['target_wref']).", ".$uid.", '".$role."', ".time().")");
				unset(self::$cache[$uid]['roles']);
				self::setState($uid, 'idle');
				$ctl->log($uid, $e['target_wref'], 'founded', 'new village ('.$role.' role) from '.$v->vdata['name']);
			} else {
				self::setState($uid, 'send', $v->wref);   // field taken meanwhile: settlers walk home, try elsewhere
				$ctl->log($uid, $v->wref, 'settle_failed', 'field '.$e['target_wref'].' was taken, settlers return');
			}
			break;
		}
		foreach($villages as $vv) {
			self::shape($npc, $vv);
			self::notice($ctl, $npc, $vv);
		}
	}

	// The player notices a colony is left to itself - or back on its feet
	private static function notice(NPCController $ctl, $npc, NPCVillage $v) {
		global $database;
		$uid = intval($npc['uid']);
		$c = self::colonies($uid);
		if(!isset($c[$v->wref])) return;
		$since = intval($c[$v->wref]['alone_since']);
		if($v->alone == ($since > 0)) return;
		$now = time();
		$database->query("UPDATE ".TB_PREFIX."npc_village SET alone_since = ".($v->alone ? $now : 0)." WHERE wref = ".$v->wref);
		self::$cache[$uid]['colonies'][$v->wref]['alone_since'] = $v->alone ? $now : 0;
		if($v->alone) {
			$help = max(intval($c[$v->wref]['founded']), intval($c[$v->wref]['supplied_at']));
			$ctl->log($uid, $v->wref, 'colony_alone', $v->vdata['name'].': no merchants for '.round(($now - $help) / 3600, 1).
				'h, stops waiting - lives off its own fields, a cranny, nothing else for now');
			NPCHuman::upset($uid, 4);   // a colony left hungry is no fun
		} else {
			$fed = $now - intval($c[$v->wref]['supplied_at']) <= NPC_COLONY_ALONE_HOURS * 3600 / SPEED;
			$ctl->log($uid, $v->wref, 'colony_supplied', $v->vdata['name'].': '.($fed ? 'merchants are coming again' :
				'its fields carry it now').' after '.round(($now - $since) / 3600, 1).'h on its own, back to its start plan');
		}
	}

	// Merchants set out to $wref (NPCMarket): help is coming
	public static function supplied($uid, $wref) {
		global $database;
		$c = self::colonies($uid);
		if(!isset($c[$wref])) return;
		$database->query("UPDATE ".TB_PREFIX."npc_village SET supplied_at = ".time()." WHERE wref = ".intval($wref));
		self::$cache[$uid]['colonies'][$wref]['supplied_at'] = time();
	}

	// A young colony nobody has sent merchants to for NPC_COLONY_ALONE_HOURS (game time)
	// and whose fields are still too low to carry its start plan
	private static function isAlone($uid, NPCVillage $v) {
		$c = self::colonies($uid);
		if(!isset($c[$v->wref]) || $v->capital || intval($v->vdata['pop']) >= NPC_COLONY_POP) return false;
		if($v->avgFieldLevel() >= NPC_COLONY_SELF_FIELDS) return false;
		$help = max(intval($c[$v->wref]['founded']), intval($c[$v->wref]['supplied_at']));
		return time() - $help > NPC_COLONY_ALONE_HOURS * 3600 / SPEED;
	}

	// Gives a village its extra plan steps and reserve for this turn (live and catch-up)
	public static function shape($npc, NPCVillage $v) {
		$uid = intval($npc['uid']);
		$roles = self::roles($uid);
		$v->plan = isset($roles[$v->wref]) ? self::$colonyPlans[$roles[$v->wref]] : array();
		$v->alone = isset($roles[$v->wref]) && self::isAlone($uid, $v);
		if($v->alone) $v->plan = self::$alonePlan;
		if(!empty($roles) && !$v->alone) {
			// Several villages: merchants to move resources around (NPCMarket)
			$v->plan[] = array(17, isset($roles[$v->wref]) ? 1 : 3, 'trade');
		}
		$v->reserve = array();
		$e = self::state($uid);
		if($e['from_wref'] != $v->wref) return;
		if($e['state'] == 'prepare' || $e['state'] == 'train') {
			$v->plan = array_merge(self::prepSteps($v), $v->plan);
		}
		$unit = $GLOBALS['u'.($v->tribe * 10)];
		if($e['state'] == 'train' && $v->unitCount($v->tribe * 10) < 3) {
			$v->reserve = array('wood' => $unit['wood'], 'clay' => $unit['clay'], 'iron' => $unit['iron'], 'crop' => $unit['crop']);
		} elseif($e['state'] == 'train' || $e['state'] == 'send') {
			$v->reserve = array('wood' => 750, 'clay' => 750, 'iron' => 750, 'crop' => 750);
		}
	}

	// What the mother village still has to build: main building 5, residence 10,
	// storage for one settler's price. Empty = ready.
	private static function prepSteps(NPCVillage $v) {
		$unit = $GLOBALS['u'.($v->tribe * 10)];
		$steps = array();
		if($v->vdata['maxstore'] < 1.05 * max($unit['wood'], $unit['clay'], $unit['iron'])) {
			$steps[] = array(10, $v->typeLevel(10) + 1, 'expansion');
		}
		if($v->vdata['maxcrop'] < 1.05 * $unit['crop']) {
			$steps[] = array(11, $v->typeLevel(11) + 1, 'expansion');
		}
		if($v->typeLevel(25) < 10) {
			$steps[] = array(15, 5, 'expansion');
			$steps[] = array(25, 10, 'expansion');
		}
		return $steps;
	}

	// Biggest village that is developed enough and can still get a slot
	// (a residence below 10 or a free slot; never one with a palace)
	private static function motherVillage($npc, $villages) {
		global $database;
		$best = null;
		foreach($villages as $v) {
			if(intval($v->vdata['pop']) < NPC_EXPAND_MIN_POP) continue;
			if($v->avgFieldLevel() < self::profileValue(NPC_EXPAND_FIELDS, $npc['profile'], 5)) continue;
			if($v->typeLevel(26) > 0) continue;
			if($v->typeLevel(25) >= 10 && $database->getFreeExpansionSlots($v->wref) < 1) continue;
			if(!$best || $v->vdata['pop'] > $best->vdata['pop']) $best = $v;
		}
		return $best;
	}

	// array(have, needed for village $count + 1, seconds until enough or null if no production)
	private static function culturePoints($uid, $count) {
		global $database;
		$table = $GLOBALS['cp'.CP];
		$need = isset($table[$count + 1]) ? $table[$count + 1] : PHP_INT_MAX;
		$have = floatval($database->getUserField($uid, 'cp', 0));
		if($have >= $need) return array($have, $need, 0);
		$perDay = floatval($database->getVSumField($uid, 'cp'));
		return array($have, $need, $perDay > 0 ? ($need - $have) / $perDay * 86400 : null);
	}

	private static function pickSite($npc, NPCVillage $v) {
		global $database;
		$c = $database->getCoor($v->wref);
		$r = NPC_EXPAND_RADIUS;
		$box = function($pad) use ($c, $r) {
			return " w.x BETWEEN ".($c['x'] - $r - $pad)." AND ".($c['x'] + $r + $pad).
				" AND w.y BETWEEN ".($c['y'] - $r - $pad)." AND ".($c['y'] + $r + $pad);
		};
		$free = $database->query_return("SELECT w.id, w.x, w.y, w.fieldtype FROM ".TB_PREFIX."wdata w".
			" WHERE w.occupied = 0 AND w.oasistype = 0 AND ".$box(0)." AND SQRT(w.x * w.x + w.y * w.y) > ".(NATARS_MAX + 3).
			" AND w.id NOT IN (SELECT `to` FROM ".TB_PREFIX."movement WHERE sort_type = 5 AND proc = 0)");
		$villages = $database->query_return("SELECT w.x, w.y, v.owner FROM ".TB_PREFIX."vdata v JOIN ".TB_PREFIX."wdata w ON w.id = v.wref".
			" WHERE ".$box(3));
		$oases = $database->query_return("SELECT w.x, w.y FROM ".TB_PREFIX."wdata w WHERE w.oasistype > 0 AND ".$box(3));
		$enemies = $database->query_return("SELECT w.x, w.y FROM ".TB_PREFIX."npc_grudge g JOIN ".TB_PREFIX."wdata w ON w.id = g.wref".
			" WHERE g.uid = ".intval($npc['uid']));
		$taste = isset(self::$siteTaste[$npc['profile']]) ? self::$siteTaste[$npc['profile']] : self::$siteTaste['balanced'];
		$sites = array();
		foreach($free as $f) {
			$d = self::dist($c, $f);
			if($d < NPC_EXPAND_MIN_DIST || $d > $r) continue;
			$score = (isset($taste[$f['fieldtype']]) ? $taste[$f['fieldtype']] : 1.0) / (1 + abs($d - 6) / 4);
			$n = 0;
			foreach($oases as $o) { if(self::dist($f, $o) <= 3) $n++; }
			$score *= 1 + 0.15 * min(3, $n);
			foreach($villages as $o) {
				if($o['owner'] != $npc['uid'] && self::dist($f, $o) <= 3) $score *= 0.7;   // crowded
			}
			foreach($enemies as $o) {
				if(self::dist($f, $o) <= 7) $score *= 0.5;   // next to someone who attacked us
			}
			$f['dist'] = $d;
			$f['score'] = $score;
			$sites[$f['id']] = $f;
		}
		if(empty($sites)) return null;
		uasort($sites, function($a, $b) { return $a['score'] < $b['score'] ? 1 : -1; });
		$top = array_slice($sites, 0, 15, true);
		$scores = array();
		foreach($top as $id => $s) { $scores[$id] = $s['score']; }
		return $top[NPCHuman::pickBest($scores, $npc)];
	}

	// crop for cropper fields, front near enemies, eco otherwise
	private static function roleFor($uid, $wref) {
		global $database;
		$f = $database->query_return("SELECT x, y, fieldtype FROM ".TB_PREFIX."wdata WHERE id = ".intval($wref));
		if(empty($f)) return 'eco';
		$f = $f[0];
		if(in_array(intval($f['fieldtype']), array(1, 6))) return 'crop';
		foreach($database->query_return("SELECT w.x, w.y FROM ".TB_PREFIX."npc_grudge g JOIN ".TB_PREFIX."wdata w ON w.id = g.wref".
			" WHERE g.uid = ".intval($uid)) as $o) {
			if(self::dist($f, $o) <= 10) return 'front';
		}
		return 'eco';
	}

	private static function dist($a, $b) {
		return sqrt(pow($a['x'] - $b['x'], 2) + pow($a['y'] - $b['y'], 2));
	}

	// "farmer:6,balanced:4" -> value for $profile
	private static function profileValue($list, $profile, $default) {
		foreach(explode(',', $list) as $pair) {
			$p = explode(':', $pair);
			if(trim($p[0]) == $profile) return floatval($p[1]);
		}
		return $default;
	}

	private static function state($uid) {
		global $database;
		if(!isset(self::$cache[$uid]['state'])) {
			$rows = $database->query_return("SELECT * FROM ".TB_PREFIX."npc_expansion WHERE uid = ".intval($uid));
			self::$cache[$uid]['state'] = empty($rows) ?
				array('uid' => $uid, 'state' => 'idle', 'from_wref' => 0, 'target_wref' => 0, 'since' => 0) : $rows[0];
		}
		return self::$cache[$uid]['state'];
	}

	private static function setState($uid, $state, $from = 0, $target = 0) {
		global $database;
		$row = array('uid' => $uid, 'state' => $state, 'from_wref' => intval($from), 'target_wref' => intval($target), 'since' => time());
		$database->query("REPLACE INTO ".TB_PREFIX."npc_expansion (uid, state, from_wref, target_wref, since) VALUES (".
			intval($uid).", '".$state."', ".$row['from_wref'].", ".$row['target_wref'].", ".$row['since'].")");
		self::$cache[$uid]['state'] = $row;
		return $row;
	}

	private static function roles($uid) {
		global $database;
		if(!isset(self::$cache[$uid]['roles'])) {
			$roles = $colonies = array();
			foreach($database->query_return("SELECT * FROM ".TB_PREFIX."npc_village WHERE uid = ".intval($uid)) as $r) {
				if(isset(self::$colonyPlans[$r['role']])) $roles[$r['wref']] = $r['role'];
				$colonies[$r['wref']] = $r;
			}
			self::$cache[$uid]['roles'] = $roles;
			self::$cache[$uid]['colonies'] = $colonies;
		}
		return self::$cache[$uid]['roles'];
	}

	private static function colonies($uid) {
		self::roles($uid);
		return self::$cache[$uid]['colonies'];
	}
}
