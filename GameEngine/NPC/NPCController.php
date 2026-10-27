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
		foreach($database->getVillagesID($npc['uid']) as $wref) {
			$village = NPCVillage::load($wref, $npc['tribe']);
			$delay = min($delay, $this->economyTurn($npc, $village));
		}
		return $delay;
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
				$this->log($npc['uid'], $v->wref, 'skip', self::describe($c).': '.$result);
			}
		}
		if($waitFor !== null) {
			$this->log($npc['uid'], $v->wref, 'wait', 'saving for '.self::describe($waitFor).', ready in '.
				($wait === null ? 'never (production <= 0)' : $wait.'s').' ('.$waitFor['reason'].')');
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

	public function log($uid, $wref, $action, $detail) {
		global $database;
		$database->query("INSERT INTO ".TB_PREFIX."npc_log (uid, wref, time, action, detail) VALUES (".
			intval($uid).", ".intval($wref).", ".time().", '".mysql_real_escape_string($action, $database->connection)."', '".
			mysql_real_escape_string($detail, $database->connection)."')");
	}

	public function pruneLog() {
		global $database;
		$database->query("DELETE FROM ".TB_PREFIX."npc_log WHERE time < ".(time() - NPC_LOG_KEEP_DAYS * 86400));
	}
}
