<?php
/*
 * Drives all NPC players. One call to run() = one runner start:
 * pick NPCs whose next_action_at has passed, let each one act, reschedule it.
 *
 * Per turn each village makes one economic decision (NPCEconomy) and queues
 * it if affordable. Army and attacks plug into act() in the next phases.
 */
class NPCController {

	private $startedAt;

	public function __construct() {
		$this->startedAt = time();
	}

	// Returns the number of NPCs processed.
	public function run() {
		$done = 0;
		foreach($this->getDueNpcs() as $npc) {
			if((time() - $this->startedAt) >= NPC_RUN_TIME_BUDGET) {
				npc_log("time budget used up, remaining NPCs wait for the next run");
				break;
			}
			$delay = NPC_TICK_SECONDS;
			try {
				$delay = $this->act($npc);
			} catch(Exception $e) {
				$this->log($npc['uid'], 0, 'error', $e->getMessage());
			}
			// Reschedule even after an error, so one broken NPC can't block the queue
			$this->reschedule($npc['uid'], time() + max(NPC_TICK_SECONDS, min(NPC_MAX_SLEEP, $delay)));
			$done++;
		}
		return $done;
	}

	public function getDueNpcs() {
		global $database;
		return $database->query_return("SELECT n.*, u.username, u.tribe FROM ".TB_PREFIX."npc n".
			" JOIN ".TB_PREFIX."users u ON u.id = n.uid".
			" WHERE n.active = 1 AND n.next_action_at <= ".time().
			" ORDER BY n.next_action_at ASC LIMIT ".intval(NPC_MAX_PER_RUN));
	}

	// One turn of an NPC; returns seconds until it wants to act again
	// (clamped to NPC_TICK_SECONDS..NPC_MAX_SLEEP by run()).
	private function act($npc) {
		global $database;
		$delay = NPC_MAX_SLEEP;
		$wrefs = $database->getVillagesID($npc['uid']);
		$income = 0;
		foreach($wrefs as $wref) {
			$village = NPCVillage::load($wref, $npc['tribe']);
			$income += $village->income();
			$delay = min($delay, $this->economyTurn($npc, $village));
		}
		$budget = $this->topUpBudget($npc, $income);
		foreach($wrefs as $wref) {
			// Reload: the economy turn may just have spent resources
			$village = NPCVillage::load($wref, $npc['tribe']);
			$budget = $this->militaryTurn($npc, $village, $budget);
		}
		$database->query("UPDATE ".TB_PREFIX."npc SET army_budget = ".floatval($budget).", budget_at = ".time().
			" WHERE uid = ".intval($npc['uid']));
		$this->raidTurn($npc, $wrefs);
		return $delay;
	}

	// At most one raid per turn, once the NPC's raid interval (by aggression) has passed
	private function raidTurn($npc, $wrefs) {
		global $database;
		if(!NPCAttack::canRaid($npc) || time() - $npc['last_attack_at'] < NPCAttack::interval($npc)) {
			return;
		}
		if(NPCAttack::activeRaids($npc['uid']) >= NPC_RAID_MAX_ACTIVE) {
			return;
		}
		foreach($wrefs as $wref) {
			$v = NPCVillage::load($wref, $npc['tribe']);
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
			$this->log($npc['uid'], $v->wref, 'raid', $t['name'].' ('.$database->getUserField($t['owner'], 'username', 0).') '.
				$t['x'].'|'.$t['y'].', '.implode(' + ', $units).', capacity '.$plan['capacity'].', expected loot '.$plan['loot'].
				', attack '.round($plan['attack']).' vs defence '.round($plan['defence']).', arrives in '.$plan['seconds'].'s');
			return;
		}
		$this->log($npc['uid'], 0, 'raid_wait', 'no raid: no worthwhile target in range or not enough offensive units', true);
	}

	// Adds the profile's share of income for the time since the last top-up.
	// Capped at NPC_ARMY_BUDGET_CAP_HOURS of that share (an existing larger budget is kept).
	private function topUpBudget($npc, $income) {
		$share = isset(NPCMilitary::$armyShare[$npc['profile']]) ? NPCMilitary::$armyShare[$npc['profile']] : 0;
		$budget = floatval($npc['army_budget']);
		$since = $npc['budget_at'] > 0 ? time() - $npc['budget_at'] : 0;
		$add = $share * $income * $since / 3600;
		$cap = $share * $income * NPC_ARMY_BUDGET_CAP_HOURS;
		return min($budget + $add, max($cap, $budget));
	}

	// Research first (the army saves for it), then training. Returns the remaining budget.
	private function militaryTurn($npc, NPCVillage $v, $budget) {
		$profile = isset(NPCMilitary::$armyShare[$npc['profile']]) ? $npc['profile'] : 'balanced';

		$research = NPCMilitary::researchCandidate($v, $profile);
		if($research) {
			$r = $GLOBALS['r'.$research];
			$price = $r['wood'] + $r['clay'] + $r['iron'] + $r['crop'];
			if($budget < $price) {
				$this->log($npc['uid'], $v->wref, 'army_wait', 'research '.self::unitName($research).', budget '.
					round($budget).'/'.$price, true);
				return $budget;
			}
			$cost = NPCActions::queueResearch($v, $research);
			if(!$cost) {
				$this->log($npc['uid'], $v->wref, 'army_wait', 'research '.self::unitName($research).
					', not enough resources in stock', true);
				return $budget;
			}
			$this->log($npc['uid'], $v->wref, 'research', self::unitName($research).' in '.$cost['time'].'s, cost '.
				$cost['wood'].'/'.$cost['clay'].'/'.$cost['iron'].'/'.$cost['crop']);
			return $budget - $price;
		}

		$c = NPCMilitary::trainCandidate($v, $profile);
		if(!$c) {
			return $budget;   // no barracks/stable or nothing researched yet
		}
		$reserve = NPCEconomy::$cropReserve[$profile] * ($v->prod('wood') + $v->prod('clay') + $v->prod('iron'));
		list($amt, $limit) = NPCMilitary::trainAmount($v, $c['unit'], $budget, $reserve);
		if($amt < 1) {
			if($limit == 'crop') {
				$this->log($npc['uid'], $v->wref, 'army_paused', 'training paused, free crop '.round($v->freeCrop()).
					'/h would drop under reserve '.round($reserve).'/h', true);
			}
			return $budget;
		}
		$cost = NPCActions::queueTraining($v, $c['unit'], $amt);
		if(!$cost) {
			return $budget;
		}
		$this->log($npc['uid'], $v->wref, 'train', $amt.'x '.self::unitName($c['unit']).' ('.$c['role'].', limited by '.$limit.
			'), done in '.$cost['time'].'s, cost '.$cost['wood'].'/'.$cost['clay'].'/'.$cost['iron'].'/'.$cost['crop']);
		return $budget - ($cost['wood'] + $cost['clay'] + $cost['iron'] + $cost['crop']);
	}

	private static function unitName($unit) {
		return defined('U'.$unit) ? constant('U'.$unit) : 'unit '.$unit;
	}

	// Tries the economy's candidates in order and queues the first affordable one.
	private function economyTurn($npc, NPCVillage $v) {
		$candidates = NPCEconomy::propose($v, $npc['profile']);
		if(empty($candidates)) {
			// Both queues busy: come back when the first job finishes
			return $this->secondsUntilQueueFrees($v);
		}
		$wait = null;
		$waitFor = null;
		foreach($candidates as $c) {
			$result = NPCActions::queueBuild($v, $c['field'], $c['tid'], $c['level']);
			$cost = NPCActions::$lastCost;
			if($result == NPCActions::QUEUED) {
				$this->log($npc['uid'], $v->wref, 'build', self::describe($c).' in '.$cost['time'].'s, cost '.
					$cost['wood'].'/'.$cost['clay'].'/'.$cost['iron'].'/'.$cost['crop'].' ('.$c['reason'].')');
				return NPC_TICK_SECONDS;
			}
			if($result == NPCActions::NO_RESOURCES && $waitFor === null) {
				$wait = NPCActions::secondsUntilAffordable($v, $cost);
				$waitFor = $c;
			} elseif($result != NPCActions::NO_RESOURCES) {
				$this->log($npc['uid'], $v->wref, 'skip', self::describe($c).': '.$result, true);
			}
		}
		if($waitFor !== null) {
			$this->log($npc['uid'], $v->wref, 'wait', 'saving for '.self::describe($waitFor).', ready in '.
				($wait === null ? 'never (production <= 0)' : $wait.'s').' ('.$waitFor['reason'].')', true);
			return $wait === null ? NPC_MAX_SLEEP : $wait;
		}
		// Everything was skipped (no crop, storage too small): nothing changes until a job finishes
		return $this->secondsUntilQueueFrees($v);
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

	private function reschedule($uid, $at) {
		global $database;
		$database->query("UPDATE ".TB_PREFIX."npc SET next_action_at = ".intval($at).", last_action_at = ".time().
			" WHERE uid = ".intval($uid));
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

	public function pruneLog() {
		global $database;
		$database->query("DELETE FROM ".TB_PREFIX."npc_log WHERE time < ".(time() - NPC_LOG_KEEP_DAYS * 86400));
	}
}
