<?php
/*
 * NPC setup (CLI only):
 *   1. applies all GameEngine/NPC/sql/*.sql migrations (idempotent; the game
 *      also applies new ones by itself on the next request)
 *   2. tops the NPC population up to NPC_COUNT (safe to run again); the game
 *      itself also adds NPCs over the round (NPCWorld::grow)
 *
 *   php npc_setup.php                 migrate + create missing NPCs
 *   php npc_setup.php --migrate-only  only create/upgrade the tables
 */
require dirname(__FILE__).'/GameEngine/NPC/bootstrap.php';
npc_load_classes();

if(!npc_migrate(true)) {
	fwrite(STDERR, "migration failed".PHP_EOL);
	exit(1);
}
if(in_array('--migrate-only', $argv)) {
	exit(0);
}

$row = mysql_fetch_row($database->query("SELECT COUNT(*) FROM ".TB_PREFIX."npc"));
$existing = intval($row[0]);
if($existing >= NPC_COUNT) {
	npc_log("already $existing NPCs (NPC_COUNT = ".NPC_COUNT."), nothing to create");
	exit(0);
}

// Spot on the spawn spiral, random tribe/profile/name as for NPCs joining later
$controller = new NPCController;
$created = 0;
for($i = $existing; $i < NPC_COUNT; $i++) {
	if(!NPCWorld::spawnNpc($controller)) {
		fwrite(STDERR, "NPC not created, see the PHP error log".PHP_EOL);
		exit(1);
	}
	$created++;
}
npc_log("done, created $created NPC(s)");
