<?php
/*
 * Where NPCs live on the map. New NPCs are placed on a Vogel spiral around the
 * centre: the k-th one at angle k x 137.5 deg and radius
 * NATARS_MAX + 3 + NPC_SPAWN_STEP x sqrt(k). That spreads them evenly over the
 * area (no clumps, no empty quarters) and lets the occupied ring grow outwards
 * the way real sign-ups (Database::generateBase) do. Each spiral point snaps to
 * the nearest free 4-4-4-6 field that keeps its distance to other villages.
 *
 * The world grows by itself (grow(), every NPC_GROW_INTERVAL from the NPC tick):
 *   arrivals  NPC_START_SHARE of NPC_MAX at the round start, the rest along
 *             1 - e^(-t/NPC_GROWTH_TAU); late joiners keep the active ones at
 *             NPC_MIN_ACTIVE x NPC_MAX - always on the outer edge of the spiral
 *   quitting  after their first day NPCs may stop playing (less diligent and
 *             heavily farmed ones sooner); their villages stay as inactive farms
 *   load      no arrivals while the server can't keep up (see NPCController::run)
 */
class NPCWorld {

	private static $names = array('Aldric','Borvik','Cassia','Drogan','Elwen','Fenrik','Gisla','Harald','Ivor','Jorund',
		'Kaelin','Lorcan','Maren','Norrik','Odalys','Perrin','Quintus','Rhosyn','Sigrun','Talwyn');
	private static $prefixes = array('Al','Bor','Cae','Dra','El','Fen','Gal','Hal','Isa','Jor','Kal','Lor','Mar','Nor','Os',
		'Per','Quin','Ros','Sig','Tal','Ul','Var','Wyn','Yr','Zan');
	private static $suffixes = array('ric','vik','sia','gan','wen','rik','la','ald','or','und','in','an','eth','is','mir',
		'dor','wyn','ga','ion','ra');
	private static $profiles = array('farmer' => 35, 'balanced' => 40, 'aggressor' => 25);
	private static $aggression = array('farmer' => 25, 'balanced' => 50, 'aggressor' => 80);

	// NPCs that join over the round
	public static function maxNpcs() {
		return NPC_MAX > 0 ? NPC_MAX : max(160, min(400, round(WORLD_MAX * 4)));
	}

	// One growth step: quitting, then arrivals as the curve and the server allow.
	public static function grow(NPCController $ctl) {
		global $database;
		$now = time();
		$last = intval(npc_meta_get('grow_at', 0));
		if($now < COMMENCE || $now - $last < NPC_GROW_INTERVAL) return;
		npc_meta_set('grow_at', $now);
		if($last > 0) self::quits($ctl, $now - $last);

		$max = self::maxNpcs();
		$row = mysql_fetch_row($database->query("SELECT COUNT(*), SUM(active) FROM ".TB_PREFIX."npc"));
		$total = intval($row[0]);
		$active = intval($row[1]);
		$arrived = round($max * (NPC_START_SHARE + (1 - NPC_START_SHARE) * (1 - exp(-($now - COMMENCE) / NPC_GROWTH_TAU))));
		$want = max($arrived - $total, (int)ceil($max * NPC_MIN_ACTIVE) - $active);
		$want = min($want, NPC_SPAWN_PER_STEP, 2 * $max - $total);   // accounts never pile up beyond 2 x NPC_MAX
		if($want <= 0) return;
		if($now - intval(npc_meta_get('lag_at', 0)) < NPC_LAG_PAUSE) {
			npc_log("growth paused: the server is behind with NPC turns");
			return;
		}
		for($i = 0; $i < $want; $i++) {
			if(!self::spawnNpc($ctl)) break;
		}
	}

	// Creates one NPC (random tribe, profile, name) on the spiral; returns its uid or false
	public static function spawnNpc(NPCController $ctl) {
		global $database;
		$profile = NPCHuman::pick(self::$profiles);
		$tribes = array_map('intval', explode(',', NPC_TRIBES));
		$tribe = $tribes[mt_rand(0, count($tribes) - 1)];
		$aggression = max(0, min(100, self::$aggression[$profile] + mt_rand(-10, 10)));
		$name = self::freeName();
		$factory = new NPCFactory;
		$uid = $factory->create($name, $tribe, $profile, $aggression);
		if(!$uid) {
			error_log('NPC spawn: '.$factory->error);
			return false;
		}
		$villages = $database->getVillagesID($uid);
		$ctl->log($uid, $villages[0], 'created', "tribe $tribe, $profile, aggression $aggression");
		npc_log("created NPC #$uid $name: tribe $tribe, $profile ($aggression), village ".$villages[0]);
		return $uid;
	}

	private static function freeName() {
		global $database;
		foreach(self::$names as $name) {
			if(!$database->checkExist($name, 0)) return $name;
		}
		for($i = 0; $i < 50; $i++) {
			$name = self::$prefixes[mt_rand(0, count(self::$prefixes) - 1)].self::$suffixes[mt_rand(0, count(self::$suffixes) - 1)];
			if(!$database->checkExist($name, 0)) return $name;
		}
		return 'Npc'.mt_rand(1000, 9999);
	}

	// Some NPCs stop playing: chance per day by diligence, raised by every village
	// that attacked them in the last day; $elapsed = seconds since the last check
	private static function quits(NPCController $ctl, $elapsed) {
		global $database;
		$now = time();
		$rows = $database->query_return("SELECT n.uid, n.diligence, (SELECT COUNT(*) FROM ".TB_PREFIX."npc_grudge g".
			" WHERE g.uid = n.uid AND g.last_hit > ".($now - 86400).") AS attackers FROM ".TB_PREFIX."npc n".
			" WHERE n.active = 1 AND n.created < ".($now - NPC_QUIT_MIN_AGE));
		foreach($rows as $n) {
			$diligence = intval($n['diligence']) > 0 ? intval($n['diligence']) : 50;
			$perDay = min(0.9, NPC_QUIT_RATE * (1.5 - $diligence / 100) * (1 + 0.5 * intval($n['attackers'])));
			if(!NPCHuman::chance(1 - pow(1 - $perDay, $elapsed / 86400))) continue;
			$database->query("UPDATE ".TB_PREFIX."npc SET active = 0, quit_at = ".$now." WHERE uid = ".intval($n['uid']));
			$ctl->log($n['uid'], 0, 'quit', 'stops playing'.($n['attackers'] > 0 ? ' (farmed by '.$n['attackers'].' villages)' : '').
				', the villages stay as they are');
		}
	}

	// Field for a new NPC's first village, or 0 when the spiral left the map.
	// Advances the spiral (npc_meta spawn_k); the rotation is random per world.
	public static function spawnSite() {
		$k = intval(npc_meta_get('spawn_k', 0));
		$phase = npc_meta_get('spawn_phase', null);
		if($phase === null) {
			$phase = mt_rand(0, 359);
			npc_meta_set('spawn_phase', $phase);
		}
		for($tries = 0; $tries < 60; $tries++, $k++) {
			$radius = NATARS_MAX + 3 + NPC_SPAWN_STEP * sqrt($k);
			if($radius > WORLD_MAX * 0.9) break;
			$angle = deg2rad($phase + 137.508 * $k);
			$wref = self::nearestFree(round($radius * cos($angle)), round($radius * sin($angle)), 4);
			if($wref) {
				npc_meta_set('spawn_k', $k + 1);
				return $wref;
			}
		}
		npc_meta_set('spawn_k', $k);
		return 0;
	}

	// Map quarter as users.location / generateBase(): 1 SW, 2 SE, 3 NE, 4 NW
	public static function sector($x, $y) {
		if($x <= 0 && $y <= 0) return 1;
		if($x >= 0 && $y <= 0) return 2;
		if($x >= 0) return 3;
		return 4;
	}

	// Nearest free 4-4-4-6 field within $window of (x, y), outside the Natar area,
	// with NPC_SPAWN_SPACING to any village and NPC_PLAYER_SPACING to real players
	private static function nearestFree($x, $y, $window) {
		global $database;
		$reach = $window + max(NPC_SPAWN_SPACING, NPC_PLAYER_SPACING);
		$villages = $database->query_return("SELECT w.x, w.y, v.owner FROM ".TB_PREFIX."vdata v JOIN ".TB_PREFIX."wdata w ON w.id = v.wref".
			" WHERE w.x BETWEEN ".($x - $reach)." AND ".($x + $reach)." AND w.y BETWEEN ".($y - $reach)." AND ".($y + $reach));
		$npcs = array();
		foreach($database->query_return("SELECT uid FROM ".TB_PREFIX."npc") as $n) { $npcs[$n['uid']] = true; }
		$free = $database->query_return("SELECT id, x, y FROM ".TB_PREFIX."wdata WHERE fieldtype = 3 AND occupied = 0 AND oasistype = 0".
			" AND x BETWEEN ".($x - $window)." AND ".($x + $window)." AND y BETWEEN ".($y - $window)." AND ".($y + $window).
			" AND SQRT(x * x + y * y) > ".(NATARS_MAX + 2)." ORDER BY (x - ".$x.") * (x - ".$x.") + (y - ".$y.") * (y - ".$y.")");
		foreach($free as $f) {
			foreach($villages as $v) {
				$d = sqrt(pow($f['x'] - $v['x'], 2) + pow($f['y'] - $v['y'], 2));
				$player = $v['owner'] > 4 && !isset($npcs[$v['owner']]);
				if($d < NPC_SPAWN_SPACING || ($player && $d < NPC_PLAYER_SPACING)) continue 2;
			}
			return intval($f['id']);
		}
		return 0;
	}
}
