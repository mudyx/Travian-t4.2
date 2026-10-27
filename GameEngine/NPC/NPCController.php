<?php
/*
 * Drives all NPC players. One call to run() = one runner start:
 * pick NPCs whose next_action_at has passed, let each one act, reschedule it.
 *
 * act() is still a skeleton (heartbeat only); economy, army and attacks
 * plug in there in the next phases.
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
			try {
				$this->act($npc);
			} catch(Exception $e) {
				$this->log($npc['uid'], 0, 'error', $e->getMessage());
			}
			// Reschedule even after an error, so one broken NPC can't block the queue
			$this->reschedule($npc['uid'], time() + NPC_TICK_SECONDS);
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

	private function act($npc) {
		global $database;
		$villages = $database->getVillagesID($npc['uid']);
		$this->log($npc['uid'], isset($villages[0]) ? $villages[0] : 0, 'heartbeat',
			$npc['username'].' (tribe '.$npc['tribe'].', '.$npc['profile'].') alive, villages: '.count($villages));
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
