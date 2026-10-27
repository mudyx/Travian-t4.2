<?php
/*
 * Drives all NPC players. run() = one tick (from a game request, the ping or
 * the CLI runner): evaluate finished raids, then let every due NPC act until
 * the tick's time/step budget is used up.
 *
 * An NPC that is overdue by more than NPC_CATCHUP_THRESHOLD (the game was
 * closed) first replays the missed time in catchUp(); then it acts live: a
 * session of incoming-attack check, one job per build queue, research/training,
 * expansion step, maybe a raid or revenge - unless it is asleep. When it looks again, how it
 * chooses and when it takes breaks is NPCHuman (in the replay too).
 */
class NPCController {

	private $deadline;
	private $stepsLeft;

	public function __construct($budget = NPC_RUN_TIME_BUDGET, $maxSteps = NPC_MAX_PER_RUN) {
		$this->deadline = microtime(true) + $budget;
		$this->stepsLeft = $maxSteps;
	}

	private function hasTime() {
		return $this->stepsLeft > 0 && microtime(true) < $this->deadline;
	}

	// Returns the number of NPCs that acted.
	public function run() {
		NPCIntel::evaluateRaids();
		$done = 0;
		$due = $this->getDueNpcs();
		$this->checkLag($due);
		foreach($due as $npc) {
			if(!$this->hasTime()) {
				npc_log("budget used up, remaining NPCs wait for the next tick");
				break;
			}
			$next = time() + NPC_TICK_SECONDS;
			try {
				$overdue = time() - max(intval($npc['next_action_at']), intval($npc['sim_at']));
				if($overdue > NPC_CATCHUP_THRESHOLD && !$this->catchUp($npc)) {
					$done++;   // budget ran out mid-replay; sim_at keeps the progress
					continue;
				}
				$next = $this->act($npc);
			} catch(Exception $e) {
				$this->log($npc['uid'], 0, 'error', $e->getMessage());
			}
			// Reschedule even after an error, so one broken NPC can't block the queue
			$this->reschedule($npc['uid'], $next);
			$this->stepsLeft--;
			$done++;
		}
		return $done;
	}

	// The game has been open (ticks less than 2 min apart) and still an NPC is
	// NPC_LAG_THRESHOLD late: the server can't keep up - NPCWorld stops adding NPCs
	private function checkLag($due) {
		$now = time();
		$prev = intval(npc_meta_get('tick_at', 0));
		npc_meta_set('tick_at', $now);
		if($prev > 0 && $now - $prev < 120 && !empty($due) && $now - $due[0]['next_action_at'] > NPC_LAG_THRESHOLD) {
			npc_meta_set('lag_at', $now);
		}
	}

	public function getDueNpcs() {
		global $database;
		return $database->query_return("SELECT n.*, u.username, u.tribe FROM ".TB_PREFIX."npc n".
			" JOIN ".TB_PREFIX."users u ON u.id = n.uid".
			" WHERE n.active = 1 AND n.next_action_at <= ".time().
			" ORDER BY n.next_action_at ASC LIMIT 200");
	}

	// One live session: look at incoming attacks, fill the build queues, army,
	// maybe a raid. Returns when the NPC looks again (NPCHuman::nextVisit: when it
	// expects something to happen, or after a break), earlier only to dodge an attack.
	private function act(&$npc) {
		global $database;
		$now = time();
		$this->humanInit($npc, $now);
		if(NPCHuman::asleep($npc, $now)) {
			NPCHuman::wentToSleep($npc);
			$wake = NPCHuman::wakeAt($npc, $now);
			$this->log($npc['uid'], 0, 'sleep', 'asleep until '.NPCHuman::clock('H:i', $wake).', queues keep running', true);
			return $wake;
		}
		$villages = array();
		$income = 0;
		foreach($database->getVillagesID($npc['uid']) as $wref) {
			$v = NPCVillage::load($wref, $npc['tribe']);   // the only production update of this turn
			$income += $v->income();
			$villages[] = $v;
		}
		$watch = NPCDefence::turn($this, $npc, $villages, $now);
		NPCExpansion::turn($this, $npc, $villages);   // also sets each village's extra plan and reserve
		$delay = NPC_MAX_SLEEP;
		foreach($villages as $v) {
			// Sometimes a free queue simply goes unnoticed this time
			$busy = array();
			if(NPCHuman::overlooks($npc)) {
				$q = mt_rand(0, 1) ? 'field' : 'building';
				if(!$v->queueBusy($q == 'field')) {
					$busy[$q] = true;
					$this->log($npc['uid'], $v->wref, 'overlooked', 'did not notice the '.$q.' queue is free');
				}
			}
			// One job per build queue while the player is online
			for($k = 0; $k < 2; $k++) {
				$res = $this->economyTurn($npc, $v, $now, $busy);
				if(!$res['queued']) { $delay = min($delay, $res['delay']); break; }
				$v->refresh();
				if($k == 1) $delay = min($delay, $this->secondsUntilQueueFrees($v));
			}
		}
		$budget = $this->topUpBudget($npc, $income);
		foreach($villages as $v) {
			$m = $this->militaryTurn($npc, $v, $budget, $now);
			$budget = $m['budget'];
			if($m['acted']) $v->refresh();
		}
		$this->saveBudget($npc['uid'], $budget);
		$this->raidTurn($npc, $villages);
		list($next, $kind) = NPCHuman::nextVisit($npc, $now, max(NPC_TICK_SECONDS, min(NPC_MAX_SLEEP, $delay)));
		if($watch && $watch < $next) {
			return max($now + NPC_TICK_SECONDS / 2, $watch);   // stays around for the dodge
		}
		if($kind == 'break') {
			$this->log($npc['uid'], 0, 'away', 'takes a break until '.NPCHuman::clock('H:i', $next));
		}
		return $next;
	}

	private function humanInit(&$npc, $t, $tag = '') {
		if(NPCHuman::init($npc, $t)) {
			$this->log($npc['uid'], 0, 'mood', $npc['mood'].' focus until '.date('d.m. H:i', $npc['mood_until']).$tag);
		}
	}

	// An attack is coming: put the stock into both build queues and troops
	// (defenders if the army stays home) before the attacker takes it.
	public function spendAll($npc, NPCVillage $v, $defenders) {
		$tag = ' [before attack]';
		for($k = 0; $k < 2; $k++) {
			$res = $this->economyTurn($npc, $v, time(), array(), $tag);
			if(!$res['queued']) break;
			$v->refresh();
		}
		$stock = $v->stock('wood') + $v->stock('clay') + $v->stock('iron') + $v->stock('crop');
		$m = $this->militaryTurn($npc, $v, $stock, time(), true, $tag, $defenders ? 'def' : null, true);
		if($m['acted']) $v->refresh();
	}

	// Replays the time between the NPC's missed turn and now, event by event:
	// jobs start back in the past (when the queue was free in the replay) and the
	// automation finishes them at once, so the queues end up as if the NPC had
	// played - limited by the resources it has now. No raids in the past.
	// Returns true when the replay reached the present, false when the budget
	// ran out (sim_at keeps the progress for the next tick).
	private function catchUp(&$npc) {
		global $database;
		// Finishing backdated jobs uses the automation functions: one process at a time
		if(!npc_acquire_lock('automation', 0)) {
			return true;   // busy - just play live this time
		}
		$now = time();
		$t = max(intval($npc['next_action_at']), intval($npc['sim_at']), $now - NPC_CATCHUP_MAX);
		$tag = ' [catch-up]';
		$this->humanInit($npc, $t, $tag);
		$wrefs = $database->getVillagesID($npc['uid']);
		$income = 0;
		foreach($wrefs as $wref) {
			$vd = $database->getVillage($wref);
			$income += floatval($vd['woodp']) + floatval($vd['clayp']) + floatval($vd['ironp']) + max(0, floatval($vd['cropp']));
		}
		$budget = $this->topUpBudget($npc, $income);   // the whole gap at once (capped)
		$free = array();   // "wref:queue" => when that queue frees up in the replay
		$steps = 0;
		while($t < $now && $this->hasTime()) {
			$this->stepsLeft--;
			$steps++;
			$this->humanInit($npc, $t, $tag);
			if(NPCHuman::asleep($npc, $t)) {
				NPCHuman::wentToSleep($npc);
				$t = min(NPCHuman::wakeAt($npc, $t), $now);   // the night passes, the queues just run out
				continue;
			}
			$ends = array();
			foreach($wrefs as $wref) {
				$v = NPCVillage::load($wref, $npc['tribe']);
				NPCExpansion::shape($npc, $v);
				// Up to one job per build queue at this moment
				for($k = 0; $k < 2; $k++) {
					$busy = array('field' => $this->busyAt($free, $wref, 'field', $t),
						'building' => $this->busyAt($free, $wref, 'building', $t));
					$res = $this->economyTurn($npc, $v, $t, $busy, $tag);
					if(!$res['queued']) break;
					$free[$wref.':'.($res['fieldQueue'] ? 'field' : 'building')] = $res['end'];
					$ends[] = $res['end'];
					$v->refresh();
				}
				$m = $this->militaryTurn($npc, $v, $budget, $t, $this->busyAt($free, $wref, 'research', $t), $tag);
				$budget = $m['budget'];
				if($m['researchEnd']) {
					$free[$wref.':research'] = $m['researchEnd'];
					$ends[] = $m['researchEnd'];
				}
			}
			// Let the engine finish everything that already ended (the backdated jobs)
			buildComplete();
			researchComplete();
			trainingComplete();
			// Jump to when the player looks again: after the next queue frees up, or
			// after a break; nothing pending = resources ran out
			$future = array();
			foreach(array_merge($ends, array_values($free)) as $e) { if($e > $t) $future[] = $e; }
			if(empty($future)) {
				$t = $now;
			} else {
				list($next) = NPCHuman::nextVisit($npc, $t, min($future) - $t);
				$t = min($next, $now);
			}
		}
		npc_release_lock('automation');
		$this->saveBudget($npc['uid'], $budget);
		$database->query("UPDATE ".TB_PREFIX."npc SET sim_at = ".intval($t)." WHERE uid = ".intval($npc['uid']));
		$npc['sim_at'] = $t;
		$npc['army_budget'] = $budget;
		$npc['budget_at'] = $now;
		npc_log("catch-up ".$npc['username'].": $steps step(s), ".($t >= $now ? 'reached now' : 'paused at '.date('H:i:s', $t)));
		return $t >= $now;
	}

	private function busyAt($free, $wref, $queue, $t) {
		return isset($free[$wref.':'.$queue]) && $free[$wref.':'.$queue] > $t;
	}

	// One economic decision for a village, jobs starting at $start.
	// The player weighs the options (NPCHuman::choose), sticks to a build it is
	// saving for (s1_npc_goal) and, when its pick is too expensive, either keeps
	// saving or grabs the first affordable option instead (NPCHuman::keepsSaving).
	// Returns array('queued', 'fieldQueue', 'end', 'delay').
	private function economyTurn($npc, NPCVillage $v, $start, $busy = array(), $tag = '') {
		$candidates = NPCHuman::choose(NPCEconomy::propose($v, $npc['profile'], !empty($busy['field']), !empty($busy['building'])), $npc);
		if(empty($candidates)) {
			// Both queues busy: come back when the first job finishes
			return array('queued' => false, 'delay' => $this->secondsUntilQueueFrees($v));
		}
		$goal = $this->goal($v, $candidates, $start);
		$wait = null;
		$waitFor = null;
		foreach($candidates as $c) {
			$result = NPCActions::queueBuild($v, $c['field'], $c['tid'], $c['level'], $start);
			$cost = NPCActions::$lastCost;
			if($result == NPCActions::QUEUED) {
				$isGoal = $goal && self::sameJob($goal, $c);
				if($isGoal) $this->setGoal($npc, $v, null);
				$why = $waitFor !== null ? ', impulse instead of saving for '.self::describe($waitFor) : ($isGoal ? ', saved for it' : '');
				$this->log($npc['uid'], $v->wref, 'build', self::describe($c).' in '.$cost['time'].'s, cost '.
					$cost['wood'].'/'.$cost['clay'].'/'.$cost['iron'].'/'.$cost['crop'].' ('.$c['reason'].$why.')'.$tag);
				return array('queued' => true, 'fieldQueue' => $c['field'] <= 18, 'end' => $start + $cost['time'],
					'delay' => NPC_TICK_SECONDS);
			}
			if($result == NPCActions::NO_RESOURCES && $waitFor === null) {
				// The pick is too expensive: save for it, or look for something affordable?
				$wait = NPCActions::secondsUntilAffordable($v, $cost);
				$waitFor = $c;
				$since = ($goal && self::sameJob($goal, $c)) ? $start - $goal['since'] : 0;
				if($c['reason'] == 'crop reserve' || NPCHuman::keepsSaving($npc, $v, $since)) break;
			} elseif($result != NPCActions::NO_RESOURCES) {
				$this->log($npc['uid'], $v->wref, 'skip', self::describe($c).': '.$result, true);
			}
		}
		if($waitFor !== null) {
			if(!$goal || !self::sameJob($goal, $waitFor)) $this->setGoal($npc, $v, $waitFor, $start);
			$this->log($npc['uid'], $v->wref, 'wait', 'saving for '.self::describe($waitFor).', ready in '.
				($wait === null ? 'never (production <= 0)' : $wait.'s').' ('.$waitFor['reason'].')', true);
			return array('queued' => false, 'delay' => $wait === null ? NPC_MAX_SLEEP : $wait);
		}
		// Everything was skipped (no crop, storage too small): nothing changes until a job finishes
		return array('queued' => false, 'delay' => $this->secondsUntilQueueFrees($v));
	}

	// The build this village is being saved for, moved to the front of $candidates.
	// Dropped once built/queued by other means or when patience ran out.
	private function goal(NPCVillage $v, &$candidates, $now) {
		global $database;
		$rows = $database->query_return("SELECT * FROM ".TB_PREFIX."npc_goal WHERE wref = ".$v->wref);
		if(empty($rows)) return null;
		$goal = $rows[0];
		if($v->level($goal['field']) >= $goal['level'] || $v->isQueuedField($goal['field']) ||
			$now - $goal['since'] > NPC_GOAL_PATIENCE / SPEED * 2) {
			$database->query("DELETE FROM ".TB_PREFIX."npc_goal WHERE wref = ".$v->wref);
			return null;
		}
		foreach($candidates as $i => $c) {
			if(!self::sameJob($goal, $c)) continue;
			array_splice($candidates, $i, 1);
			// Behind a crop emergency, before everything else
			$at = (!empty($candidates) && $candidates[0]['reason'] == 'crop reserve') ? 1 : 0;
			array_splice($candidates, $at, 0, array($c));
			break;
		}
		return $goal;   // its queue may be busy now - still the goal for later
	}

	private function setGoal($npc, NPCVillage $v, $c, $since = 0) {
		global $database;
		if($c === null) {
			$database->query("DELETE FROM ".TB_PREFIX."npc_goal WHERE wref = ".$v->wref);
			return;
		}
		$database->query("REPLACE INTO ".TB_PREFIX."npc_goal (wref, uid, field, tid, level, since) VALUES (".$v->wref.", ".
			intval($npc['uid']).", ".intval($c['field']).", ".intval($c['tid']).", ".intval($c['level']).", ".intval($since).")");
	}

	private static function sameJob($a, $b) {
		return $a['field'] == $b['field'] && $a['level'] == $b['level'];
	}

	private function secondsUntilQueueFrees(NPCVillage $v) {
		$first = null;
		foreach($v->jobs as $job) {
			if($first === null || $job['timestamp'] < $first) $first = $job['timestamp'];
		}
		return $first === null ? NPC_TICK_SECONDS : $first - time() + 1;
	}

	private static function describe($c) {
		$name = defined('B'.$c['tid']) ? constant('B'.$c['tid']) : 'type '.$c['tid'];
		return $name.' f'.$c['field'].' -> '.$c['level'];
	}

	// Adds the profile's share of income for the time since the last top-up.
	// Capped at NPC_ARMY_BUDGET_CAP_HOURS of that share (an existing larger budget is kept).
	private function topUpBudget($npc, $income) {
		$share = isset(NPCMilitary::$armyShare[$npc['profile']]) ? NPCMilitary::$armyShare[$npc['profile']] : 0;
		$share = min(0.9, $share * NPCHuman::factor($npc, 'army'));
		$budget = floatval($npc['army_budget']);
		$since = $npc['budget_at'] > 0 ? time() - $npc['budget_at'] : 0;
		$add = $share * $income * $since / 3600;
		$cap = $share * $income * NPC_ARMY_BUDGET_CAP_HOURS;
		return min($budget + $add, max($cap, $budget));
	}

	private function saveBudget($uid, $budget) {
		global $database;
		$database->query("UPDATE ".TB_PREFIX."npc SET army_budget = ".floatval($budget).", budget_at = ".time().
			" WHERE uid = ".intval($uid));
	}

	// Research first (the army saves for it), then training; actions start at $start.
	// Returns array('budget' => remaining, 'acted' => bool, 'researchEnd' => finish time or 0).
	// $prefer / $force: see NPCMilitary::trainCandidate() / trainAmount().
	private function militaryTurn($npc, NPCVillage $v, $budget, $start, $researchBusy = false, $tag = '', $prefer = null, $force = false) {
		$profile = isset(NPCMilitary::$armyShare[$npc['profile']]) ? $npc['profile'] : 'balanced';
		$out = array('budget' => $budget, 'acted' => false, 'researchEnd' => 0);

		$research = $researchBusy ? null : NPCMilitary::researchCandidate($v, $profile);
		if($research) {
			$r = $GLOBALS['r'.$research];
			$price = $r['wood'] + $r['clay'] + $r['iron'] + $r['crop'];
			if($budget < $price) {
				$this->log($npc['uid'], $v->wref, 'army_wait', 'research '.self::unitName($research).', budget '.
					round($budget).'/'.$price, true);
				return $out;
			}
			$cost = NPCActions::queueResearch($v, $research, $start);
			if(!$cost) {
				$this->log($npc['uid'], $v->wref, 'army_wait', 'research '.self::unitName($research).
					', not enough resources in stock', true);
				return $out;
			}
			$this->log($npc['uid'], $v->wref, 'research', self::unitName($research).' in '.$cost['time'].'s, cost '.
				$cost['wood'].'/'.$cost['clay'].'/'.$cost['iron'].'/'.$cost['crop'].$tag);
			return array('budget' => $budget - $price, 'acted' => true, 'researchEnd' => $cost['end']);
		}

		$c = NPCMilitary::trainCandidate($v, $profile, $prefer);
		if(!$c) {
			return $out;   // no barracks/stable or nothing researched yet
		}
		$reserve = NPCEconomy::$cropReserve[$profile] * ($v->prod('wood') + $v->prod('clay') + $v->prod('iron'));
		list($amt, $limit) = NPCMilitary::trainAmount($v, $c['unit'], $budget, $reserve, $start, $force);
		if($amt < 1) {
			if($limit == 'crop') {
				$this->log($npc['uid'], $v->wref, 'army_paused', 'training paused, free crop '.round($v->freeCrop()).
					'/h would drop under reserve '.round($reserve).'/h', true);
			}
			return $out;
		}
		$cost = NPCActions::queueTraining($v, $c['unit'], $amt, $start);
		if(!$cost) {
			return $out;
		}
		$this->log($npc['uid'], $v->wref, 'train', $amt.'x '.self::unitName($c['unit']).' ('.$c['role'].', limited by '.$limit.
			'), done in '.$cost['time'].'s, cost '.$cost['wood'].'/'.$cost['clay'].'/'.$cost['iron'].'/'.$cost['crop'].$tag);
		return array('budget' => $budget - ($cost['wood'] + $cost['clay'] + $cost['iron'] + $cost['crop']),
			'acted' => true, 'researchEnd' => 0);
	}

	private static function unitName($unit) {
		return defined('U'.$unit) ? constant('U'.$unit) : 'unit '.$unit;
	}

	// At most one raid per turn: a due revenge first, otherwise once the NPC's raid
	// interval (by aggression and mood) has passed
	private function raidTurn($npc, $villages) {
		global $database;
		if(NPCAttack::canRaid($npc) && $this->revengeTurn($npc, $villages)) {
			return;
		}
		if(!NPCAttack::canRaid($npc) || time() - $npc['last_attack_at'] < NPCAttack::interval($npc)) {
			return;
		}
		if(NPCAttack::activeRaids($npc['uid']) >= NPC_RAID_MAX_ACTIVE) {
			return;
		}
		foreach($villages as $v) {
			$plan = NPCAttack::plan($npc, $v);
			if(!$plan) continue;
			if(!NPCActions::sendRaid($npc['uid'], $v, $plan)) {
				$this->log($npc['uid'], $v->wref, 'raid_failed', 'units changed before sending', true);
				continue;
			}
			$database->query("UPDATE ".TB_PREFIX."npc SET last_attack_at = ".time()." WHERE uid = ".intval($npc['uid']));
			$t = $plan['target'];
			$units = array();
			foreach($plan['units'] as $unit => $n) { $units[] = $n.'x '.self::unitName($unit); }
			$this->log($npc['uid'], $v->wref, 'raid', ($plan['probe'] ? 'probe ' : '').$t['name'].' ('.
				$database->getUserField($t['owner'], 'username', 0).') '.$t['x'].'|'.$t['y'].', '.implode(' + ', $units).
				', capacity '.$plan['capacity'].', expected loot '.$plan['loot'].', attack '.round($plan['attack']).
				' vs known defence '.round($plan['defence']).', arrives in '.$plan['seconds'].'s');
			return;
		}
		$this->log($npc['uid'], 0, 'raid_wait', 'no raid: no worthwhile target in range or not enough offensive units', true);
	}

	// Carries out a planned revenge (NPCDefence) that is due; true if one was sent.
	// No units yet -> tried again next session, until NPC_REVENGE_PATIENCE runs out.
	private function revengeTurn($npc, $villages) {
		global $database;
		$now = time();
		$rows = $database->query_return("SELECT * FROM ".TB_PREFIX."npc_grudge WHERE uid = ".intval($npc['uid']).
			" AND revenge_at > 0 AND revenge_at <= ".$now." ORDER BY revenge_at LIMIT 1");
		if(empty($rows)) return false;
		$g = $rows[0];
		$where = " WHERE uid = ".intval($npc['uid'])." AND wref = ".intval($g['wref']);
		$who = $database->getUserField($g['enemy'], 'username', 0);
		if($now - $g['revenge_at'] > NPC_REVENGE_PATIENCE) {
			$database->query("UPDATE ".TB_PREFIX."npc_grudge SET revenge_at = 0".$where);
			$this->log($npc['uid'], 0, 'revenge_dropped', 'anger at '.$who.' cooled down');
			return false;
		}
		foreach($villages as $v) {
			$plan = NPCAttack::revengePlan($npc, $v, $g['wref']);
			if(!$plan) continue;
			if(isset($plan['hopeless'])) {
				$database->query("UPDATE ".TB_PREFIX."npc_grudge SET revenge_at = 0".$where);
				$this->log($npc['uid'], 0, 'revenge_dropped', $who.' is too strong, keeps the grudge');
				return false;
			}
			if(!NPCActions::sendRaid($npc['uid'], $v, $plan)) continue;
			$database->query("UPDATE ".TB_PREFIX."npc_grudge SET revenge_at = 0, revenges = revenges + 1".$where);
			$database->query("UPDATE ".TB_PREFIX."npc SET last_attack_at = ".$now." WHERE uid = ".intval($npc['uid']));
			$this->log($npc['uid'], $v->wref, 'revenge', $who.' '.$plan['target']['x'].'|'.$plan['target']['y'].', '.
				array_sum($plan['units']).' units, attack '.round($plan['attack']).', arrives in '.$plan['seconds'].'s');
			return true;
		}
		$this->log($npc['uid'], 0, 'revenge_wait', 'revenge on '.$who.', not enough troops or out of range', true);
		return false;
	}

	private function reschedule($uid, $at) {
		global $database;
		$database->query("UPDATE ".TB_PREFIX."npc SET next_action_at = ".intval($at).", last_action_at = ".time().
			", sim_at = ".time()." WHERE uid = ".intval($uid));
	}

	// $dedupe: skip the entry if this NPC's last entry of the same action in the same
	// village is about the same thing (text before the first comma) - keeps
	// "still waiting" states from writing a row every minute.
	public function log($uid, $wref, $action, $detail, $dedupe = false) {
		global $database;
		if($dedupe) {
			$last = $database->query_return("SELECT detail FROM ".TB_PREFIX."npc_log WHERE uid = ".intval($uid).
				" AND wref = ".intval($wref)." AND action = '".mysql_real_escape_string($action, $database->connection)."'".
				" ORDER BY id DESC LIMIT 1");
			$key = strtok($detail, ',');
			if(!empty($last) && strtok($last[0]['detail'], ',') === $key) {
				return;
			}
		}
		$database->query("INSERT INTO ".TB_PREFIX."npc_log (uid, wref, time, action, detail) VALUES (".
			intval($uid).", ".intval($wref).", ".time().", '".mysql_real_escape_string($action, $database->connection)."', '".
			mysql_real_escape_string($detail, $database->connection)."')");
	}

	// Hourly cleanup of data no human player is part of: finished movements whose
	// both ends are NPC or Nature villages (+ their attacks/loot rows), the NPCs'
	// own reports, evaluated raids and old NPC log rows. Players' rows are never touched.
	public function housekeeping() {
		global $database;
		if(time() - intval(npc_meta_get('cleanup_at', 0)) < NPC_CLEANUP_INTERVAL) {
			return;
		}
		npc_meta_set('cleanup_at', time());
		$cutoff = time() - NPC_CLEANUP_KEEP_DAYS * 86400;
		$npcVillages = "SELECT wref FROM ".TB_PREFIX."vdata WHERE owner = 3 OR owner IN (SELECT uid FROM ".TB_PREFIX."npc)";
		$moves = $database->query_return("SELECT moveid, sort_type, ref FROM ".TB_PREFIX."movement".
			" WHERE proc = 1 AND endtime < ".$cutoff." AND `from` IN (".$npcVillages.") AND `to` IN (".$npcVillages.")");
		$moveIds = $attackIds = $sendIds = array();
		foreach($moves as $m) {
			$moveIds[] = intval($m['moveid']);
			if($m['sort_type'] == 3 || $m['sort_type'] == 4) $attackIds[intval($m['ref'])] = true;
			if($m['sort_type'] == 6) $sendIds[intval($m['ref'])] = true;
		}
		foreach(array_chunk($moveIds, 500) as $chunk) {
			$database->query("DELETE FROM ".TB_PREFIX."movement WHERE moveid IN (".implode(',', $chunk).")");
		}
		// Only rows those movements pointed to, and only once nothing else points to them
		foreach(array_chunk(array_keys($attackIds), 500) as $chunk) {
			$database->query("DELETE FROM ".TB_PREFIX."attacks WHERE id IN (".implode(',', $chunk).")".
				" AND id NOT IN (SELECT ref FROM ".TB_PREFIX."movement WHERE sort_type IN (3,4))");
		}
		foreach(array_chunk(array_keys($sendIds), 500) as $chunk) {
			$database->query("DELETE FROM ".TB_PREFIX."send WHERE id IN (".implode(',', $chunk).")".
				" AND id NOT IN (SELECT ref FROM ".TB_PREFIX."movement WHERE sort_type IN (0,1,6))");
		}
		$database->query("DELETE FROM ".TB_PREFIX."ndata WHERE uid IN (SELECT uid FROM ".TB_PREFIX."npc)".
			" AND time < ".(time() - NPC_REPORT_KEEP_HOURS * 3600));
		$database->query("DELETE FROM ".TB_PREFIX."npc_raid WHERE evaluated = 1 AND sent_at < ".$cutoff);
		$database->query("DELETE FROM ".TB_PREFIX."npc_log WHERE time < ".(time() - NPC_LOG_KEEP_DAYS * 86400));
		$database->query("DELETE FROM ".TB_PREFIX."npc_threat WHERE arrive_at < ".(time() - 86400));
		npc_log("housekeeping: ".count($moveIds)." movement(s), ".count($attackIds)." attack(s), ".count($sendIds)." loot row(s)");
	}
}
