<?php
/*
 * NPC system entry points shared by web requests and CLI scripts.
 *
 *   npc_tick($budget, $maxSteps)  - called from Automation.php on every game
 *                                   request and from npc_ping.php; lets due NPCs
 *                                   act and catches up missed time
 *   npc_migrate()                 - applies new GameEngine/NPC/sql/NNN_*.sql files
 *
 * Needs the engine globals ($database, $generator, $technology, game data),
 * i.e. include it after Session.php / NPC/bootstrap.php have loaded them.
 */
require_once dirname(__FILE__).'/npc_config.php';

// Output only on the command line; web requests stay silent
function npc_log($msg) {
	if(php_sapi_name() === 'cli') {
		echo date('Y-m-d H:i:s')." ".$msg.PHP_EOL;
	}
}

// MySQL named locks; the table prefix keeps worlds on one server apart
function npc_acquire_lock($name, $wait=0) {
	global $database;
	$row = mysql_fetch_row($database->query("SELECT GET_LOCK('".TB_PREFIX.$name."',".intval($wait).")"));
	return $row && $row[0] == 1;
}

function npc_release_lock($name) {
	global $database;
	$database->query("SELECT RELEASE_LOCK('".TB_PREFIX.$name."')");
}

function npc_meta_get($key, $default = null) {
	global $database;
	$rows = $database->query_return("SELECT v FROM ".TB_PREFIX."npc_meta WHERE k = '".mysql_real_escape_string($key, $database->connection)."'");
	return empty($rows) ? $default : $rows[0]['v'];
}

function npc_meta_set($key, $value) {
	global $database;
	$database->query("REPLACE INTO ".TB_PREFIX."npc_meta (k, v) VALUES ('".mysql_real_escape_string($key, $database->connection)."', '".
		mysql_real_escape_string($value, $database->connection)."')");
}

// Applies sql/NNN_*.sql files newer than the stored schema version.
// $force re-runs all of them (they are idempotent). Returns false on an SQL error.
function npc_migrate($force = false) {
	global $database;
	static $done = false;
	if($done && !$force) return true;
	$current = 0;
	$hasMeta = mysql_num_rows($database->query("SHOW TABLES LIKE '".TB_PREFIX."npc_meta'")) > 0;
	if($hasMeta && !$force) {
		$current = intval(npc_meta_get('schema', 0));
	}
	$files = glob(dirname(__FILE__).'/sql/*.sql');
	sort($files);
	foreach($files as $file) {
		$version = intval(basename($file));
		if($version <= $current) continue;
		$sql = str_replace('%PREFIX%', TB_PREFIX, file_get_contents($file));
		$sql = preg_replace('/^\s*--.*$/m', '', $sql);  // whole-line comments
		foreach(preg_split('/;\s*$/m', $sql) as $statement) {
			if(trim($statement) == '') continue;
			if(!$database->query($statement)) {
				error_log('NPC migration '.basename($file).': '.mysql_error($database->connection));
				npc_log('migration '.basename($file).' FAILED: '.mysql_error($database->connection));
				return false;
			}
		}
		npc_meta_set('schema', $version);
		npc_log('migration '.basename($file).' OK');
	}
	$done = true;
	return true;
}

function npc_load_classes() {
	foreach(array('NPCVillage', 'NPCRules', 'NPCEconomy', 'NPCMilitary', 'NPCIntel', 'NPCAttack', 'NPCActions', 'NPCHuman', 'NPCDefence', 'NPCWorld', 'NPCExpansion', 'NPCMarket', 'NPCFactory', 'NPCController') as $class) {
		require_once dirname(__FILE__).'/'.$class.'.php';
	}
}

// One NPC tick: due NPCs act (catching up missed time), raids get evaluated,
// old NPC data is cleaned up hourly, the NPC population grows/shrinks. Only one tick runs at a time; a request
// that finds it busy skips it. Never throws - a page must not break because of NPCs.
function npc_tick($budget, $maxSteps) {
	if(!npc_acquire_lock('npc_tick', 0)) {
		return 0;
	}
	$done = 0;
	try {
		if(npc_migrate()) {
			npc_load_classes();
			$controller = new NPCController($budget, $maxSteps);
			$done = $controller->run();
			$controller->housekeeping();
			// New accounts only from the background ping / CLI: a page request stays fast
			if(defined('NPC_PING') || php_sapi_name() === 'cli') NPCWorld::grow($controller);
		}
	} catch(Exception $e) {
		error_log('NPC tick: '.$e->getMessage());
	}
	npc_release_lock('npc_tick');
	return $done;
}
