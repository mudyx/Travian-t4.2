<?php
/*
 * Optional NPC runner (CLI only). Not needed while the game is played: every
 * game request and the page's background ping (npc_ping.php) run the NPC tick
 * themselves. Useful on a server without visitors (cron) or for debugging:
 *   php npc_runner.php
 *
 *   1. takes the runner lock (a second instance exits immediately)
 *   2. runs the regular game automation
 *   3. one NPC tick with the CLI budget (catch-up included)
 */
require dirname(__FILE__).'/GameEngine/NPC/bootstrap.php';

if(!npc_acquire_lock('npc_runner', 0)) {
	npc_log("another npc_runner is still running, exiting");
	exit(0);
}

$started = microtime(true);
npc_meta_set('runner_at', time());   // the pages leave the NPCs to the runner (npc_motor_running)

if(runAutomation(NPC_AUTOMATION_LOCK_WAIT)) {
	npc_log("automation done");
} else {
	npc_log("automation skipped (lock busy for ".NPC_AUTOMATION_LOCK_WAIT."s or round finished)");
}

$processed = npc_tick(NPC_RUN_TIME_BUDGET, NPC_MAX_PER_RUN);

npc_release_lock('npc_runner');
npc_log("NPCs processed: $processed, run took ".round(microtime(true) - $started, 2)."s");
