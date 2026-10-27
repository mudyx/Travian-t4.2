<?php
/*
 * NPC setup (CLI only):
 *   1. applies all GameEngine/NPC/sql/*.sql migrations (idempotent; the game
 *      also applies new ones by itself on the next request)
 *   2. tops the NPC population up to NPC_COUNT (safe to run again)
 *
 *   php npc_setup.php                 migrate + create missing NPCs
 *   php npc_setup.php --migrate-only  only create/upgrade the tables
 */
require dirname(__FILE__).'/GameEngine/NPC/bootstrap.php';
require NPC_ENGINE.'/NPC/NPCFactory.php';
npc_load_classes();

if(!npc_migrate(true)) {
	fwrite(STDERR, "migration failed".PHP_EOL);
	exit(1);
}
if(in_array('--migrate-only', $argv)) {
	exit(0);
}

$names = array('Aldric','Borvik','Cassia','Drogan','Elwen','Fenrik','Gisla','Harald','Ivor','Jorund',
	'Kaelin','Lorcan','Maren','Norrik','Odalys','Perrin','Quintus','Rhosyn','Sigrun','Talwyn');
$profiles = array('farmer' => 25, 'balanced' => 50, 'aggressor' => 80);   // profile => base aggression
$tribes = array_map('intval', explode(',', NPC_TRIBES));

$row = mysql_fetch_row($database->query("SELECT COUNT(*) FROM ".TB_PREFIX."npc"));
$existing = intval($row[0]);
if($existing >= NPC_COUNT) {
	npc_log("already $existing NPCs (NPC_COUNT = ".NPC_COUNT."), nothing to create");
	exit(0);
}

$factory = new NPCFactory;
$controller = new NPCController;
$profileNames = array_keys($profiles);
$created = 0;
for($i = $existing; $i < NPC_COUNT; $i++) {
	$tribe = $tribes[$i % count($tribes)];
	$profile = $profileNames[$i % count($profileNames)];
	$aggression = max(0, min(100, $profiles[$profile] + rand(-10, 10)));

	// first free name from the list, numbered once the list runs out
	$name = '';
	foreach($names as $candidate) {
		if(!$database->checkExist($candidate, 0)) { $name = $candidate; break; }
	}
	if($name == '') {
		$name = 'Npc'.($i + 1);
	}

	$uid = $factory->create($name, $tribe, $profile, $aggression);
	if(!$uid) {
		fwrite(STDERR, "NPC '$name' not created: ".$factory->error.PHP_EOL);
		exit(1);
	}
	$villages = $database->getVillagesID($uid);
	$controller->log($uid, $villages[0], 'created', "tribe $tribe, $profile, aggression $aggression");
	npc_log("created NPC #$uid $name: tribe $tribe, $profile ($aggression), village ".$villages[0]);
	$created++;
}
npc_log("done, created $created NPC(s)");
