<?php
/*
 * CLI bootstrap for background scripts (NPC runner, cron jobs).
 *
 * Loads the same engine parts as Session.php - config, database, game data,
 * battle/generator/technology and all automation functions - but WITHOUT
 * Session.php itself: no session_start(), no login redirects, no per-user
 * objects ($session, $village, $building, $form, $message).
 *
 * Automation is NOT started automatically here; call runAutomation($lockWait).
 *
 * Usage:   require dirname(__FILE__).'/GameEngine/NPC/bootstrap.php';
 */

if(php_sapi_name() !== 'cli') {
	header("HTTP/1.1 404 Not Found");
	exit;
}

define('AUTOMATION_MANUAL', true);
define('NPC_ROOT', dirname(dirname(dirname(__FILE__))));
define('NPC_ENGINE', NPC_ROOT.'/GameEngine');

if(!file_exists(NPC_ENGINE.'/Database/connection.php')) {
	fwrite(STDERR, "Game is not installed: missing GameEngine/Database/connection.php\n");
	exit(1);
}

// Engine files include each other relative to GameEngine/ (and Natars/Smart-medals
// use the default mysql link), so run from there like a page request would.
chdir(NPC_ENGINE);
set_time_limit(0);

include("config.php");               // connection.php + constants from s1_config
include("Database.php");             // $database
include("Data/buidata.php");         // $bid1..$bid42
include("Data/cp.php");
include("Data/cel.php");
include("Data/resdata.php");         // $r2..$r50 (research)
include("Data/unitdata.php");        // $u1..$u50, $u199
include("Data/hero_full.php");       // $hero_levels
include("Data/bounty.php");

// Battle reports and notices are built from language constants.
// The language file switches error reporting off - restore ours afterwards.
$npcErrorLevel = error_reporting();
include("Lang/".(file_exists(NPC_ENGINE."/Lang/".LANG.".php") ? LANG : "en").".php");
error_reporting($npcErrorLevel);

include("Battle.php");               // $battle
include("Generator.php");            // $generator
include("Natars.php");               // $natars
include("Logging.php");              // $logging
include("Multisort.php");
include("Automation.php");           // defines runAutomation(), $technology; loads NPC/npc_common.php
                                     // (npc_log, npc_acquire_lock, npc_migrate, npc_tick)
