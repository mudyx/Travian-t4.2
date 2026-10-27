<?php
/*
 * NPC runner (CLI only) - started every minute by the Windows Task Scheduler:
 *   php npc_runner.php
 *
 *   1. takes the runner lock (a second instance exits immediately)
 *   2. runs the regular game automation (builds, training, attacks finish
 *      even when no human player has a page open)
 *   3. lets every NPC whose next_action_at has passed act once
 */
require dirname(__FILE__).'/GameEngine/NPC/bootstrap.php';
require NPC_ENGINE.'/NPC/npc_config.php';
require NPC_ENGINE.'/NPC/NPCController.php';

// Lock name gets the table prefix (s1_npc_runner), so two worlds on one
// database server don't block each other.
if(!npc_acquire_lock('npc_runner', 0)) {
	npc_log("another npc_runner is still running, exiting");
	exit(0);
}

$started = microtime(true);

if(runAutomation(NPC_AUTOMATION_LOCK_WAIT)) {
	npc_log("automation done");
} else {
	npc_log("automation skipped (lock busy for ".NPC_AUTOMATION_LOCK_WAIT."s or round finished)");
}

$controller = new NPCController;
$processed = $controller->run();
$controller->pruneLog();

npc_release_lock('npc_runner');
npc_log("NPCs processed: $processed, run took ".round(microtime(true) - $started, 2)."s");
