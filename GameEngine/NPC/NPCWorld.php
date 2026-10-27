<?php
/*
 * Where NPCs live on the map and how the world's population lives and breathes.
 *
 * Placement: new NPCs go on a Vogel spiral around the centre - the k-th one at
 * angle k x 137.5 deg and radius NATARS_MAX + 3 + NPC_SPAWN_STEP x sqrt(k). That
 * spreads them evenly (no clumps, no empty quarters) and lets the occupied ring
 * grow outwards the way real sign-ups (Database::generateBase) do. Each spiral
 * point snaps to the nearest free 4-4-4-6 field that keeps its distance to other
 * villages. Friends who register together settle next to each other.
 *
 * Population (grow(), every NPC_GROW_INTERVAL from the background ping/CLI).
 * Nothing is filled up to a number - people come and go:
 *   sign-ups  a random process (Poisson) whose rate follows the round: a launch
 *             rush, a main wave over the first days, a thin trickle later; more
 *             in the evening than at night; random spells of an advert or word of
 *             mouth (several times more) or of silence; a world popularity drawn
 *             once per round; a crowded map attracts fewer. Sometimes 2-5 friends
 *             join together.
 *   absences  life gets in the way: an NPC drops out for half a day to a week
 *             (less diligent and frustrated ones more often), its villages lie idle;
 *             when the time is up it comes back - or never does
 *   quitting  newcomers who only tried it leave in the first days, a trickle of
 *             others later, frustrated ones (big lost battles, being farmed) much
 *             sooner; the villages stay as inactive farms
 *   load      while the server can't keep up (NPCController::run) sign-ups wait;
 *             too many waiting = they went to another server
 * All rates are per real time and integrated over the gap since the last step,
 * so a world that was closed overnight has lived through the night too.
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

	// When people register, by hour of the day (NPC_TIMEZONE)
	private static $hours = array(0.5, 0.3, 0.15, 0.1, 0.1, 0.15, 0.3, 0.6, 0.8, 0.9, 1.0, 1.0,
		1.1, 1.1, 1.1, 1.2, 1.4, 1.6, 1.8, 2.0, 2.0, 1.8, 1.3, 0.8);

	// How many friends join together (1 = alone); mean 1.31
	private static $groups = array(1 => 80, 2 => 13, 3 => 4, 4 => 2, 5 => 1);

	// Spells that change the sign-up rate: weight, rate multiplier from-to, length in hours from-to
	private static $spells = array(
		'advert'        => array(50, 2.5, 6.0, 1, 6),
		'word of mouth' => array(20, 1.5, 2.5, 6, 30),
		'silence'       => array(30, 0.05, 0.35, 4, 40),
	);

	private static $reasons = array('busy at work', 'exams', 'on holiday', 'ill', 'family matters', 'moving house',
		'lost interest for a while', 'playing something else');

	private static $spell = null;   // current spell: kind, multiplier, until

	// How many NPCs the server is made for: a scale for the rates, not a target
	public static function maxNpcs() {
		return NPC_MAX > 0 ? NPC_MAX : max(160, min(400, round(WORLD_MAX * 4)));
	}

	// One step of the world's life: what happened since the last one
	public static function grow(NPCController $ctl) {
		$now = time();
		$last = intval(npc_meta_get('grow_at', 0));
		if($now < COMMENCE || $now - $last < NPC_GROW_INTERVAL) return;
		npc_meta_set('grow_at', $now);
		$from = $last > 0 ? max($last, COMMENCE, $now - NPC_LIFE_MAX_GAP) : max(COMMENCE, $now - NPC_GROW_INTERVAL);
		self::lifeEvents($ctl, $from, $now);
		self::signUps($from, $now);
		self::arrivals($ctl, $now);
	}

	// ---- sign-ups ----

	// Expected sign-ups per hour at $t, before spells, popularity and crowding
	public static function signupRate($t) {
		$age = max(0, $t - COMMENCE) / 3600;
		$rate = self::maxNpcs() * (NPC_JOIN_RUSH / NPC_JOIN_RUSH_HOURS * exp(-$age / NPC_JOIN_RUSH_HOURS) +
			NPC_JOIN_MAIN / NPC_JOIN_MAIN_HOURS * exp(-$age / NPC_JOIN_MAIN_HOURS) + NPC_JOIN_LATE / 24);
		return $rate * self::$hours[intval(NPCHuman::clock('G', $t))] / (array_sum(self::$hours) / 24);
	}

	// Draws the sign-ups of [$from, $now) into the waiting queue (npc_meta join_queue, group sizes)
	private static function signUps($from, $now) {
		global $database;
		self::$spell = explode(',', npc_meta_get('spell', ',1,0'));
		$expected = 0;
		for($t = $from; $t < $now; $t += NPC_GROW_INTERVAL) {
			$dt = min(NPC_GROW_INTERVAL, $now - $t);
			$expected += self::signupRate($t) * self::spell($t, $dt) * $dt / 3600;
		}
		npc_meta_set('spell', implode(',', self::$spell));

		$max = self::maxNpcs();
		$row = mysql_fetch_row($database->query("SELECT SUM(active) FROM ".TB_PREFIX."npc"));
		$crowd = max(0, 1 - pow(intval($row[0]) / (NPC_JOIN_ROOM * $max), 3));   // a full map attracts fewer
		$expected *= self::popularity() * $crowd;

		$queue = array_filter(explode(',', npc_meta_get('join_queue', '')));
		for($n = self::poisson($expected / 1.31); $n > 0; $n--) { $queue[] = NPCHuman::pick(self::$groups); }
		// Kept waiting too long (server lagging): the rest went to another server
		$waiting = 0;
		foreach($queue as $i => $size) {
			$waiting += $size;
			if($waiting > $max / 4) unset($queue[$i]);
		}
		npc_meta_set('join_queue', implode(',', $queue));
	}

	// Spell of the sign-up rate at $t (advert, word of mouth, silence or none);
	// one starts NPC_JOIN_SPELLS times a day on average. Returns the rate multiplier.
	private static function spell($t, $dt) {
		$s =& self::$spell;
		if(intval($s[2]) > $t) return floatval($s[1]);
		if($s[0] != '') npc_log('sign-ups: '.$s[0].' is over');
		$s = array('', 1, 0);
		if(NPCHuman::chance(1 - exp(-NPC_JOIN_SPELLS * $dt / 86400))) {
			$weights = array();
			foreach(self::$spells as $kind => $p) { $weights[$kind] = $p[0]; }
			$kind = NPCHuman::pick($weights);
			$p = self::$spells[$kind];
			$mult = round($p[1] + NPCHuman::rand() * ($p[2] - $p[1]), 2);
			$s = array($kind, $mult, $t + mt_rand($p[3] * 3600, $p[4] * 3600));
			npc_log('sign-ups: '.$kind.' (x'.$mult.') until '.NPCHuman::clock('d.m. H:i', $s[2]));
		}
		return floatval($s[1]);
	}

	// How much this round's world draws people (drawn once: 0.5 - 1.8, mostly near 1)
	private static function popularity() {
		$p = npc_meta_get('popularity', null);
		if($p === null) {
			$p = round(max(0.5, min(1.8, exp(0.3 * NPCHuman::gauss()))), 2);
			npc_meta_set('popularity', $p);
		}
		return floatval($p);
	}

	// Creates waiting newcomers, at most NPC_SPAWN_PER_STEP per step (load)
	private static function arrivals(NPCController $ctl, $now) {
		global $database;
		$queue = array_values(array_filter(explode(',', npc_meta_get('join_queue', ''))));
		if(empty($queue)) return;
		if($now - intval(npc_meta_get('lag_at', 0)) < NPC_LAG_PAUSE) {
			npc_log("sign-ups wait: the server is behind with NPC turns");
			return;
		}
		$row = mysql_fetch_row($database->query("SELECT COUNT(*) FROM ".TB_PREFIX."npc"));
		// Not a fixed batch: people who signed up while nobody looked trickle in;
		// accounts never pile up beyond 2 x NPC_MAX
		$room = min(mt_rand(1, NPC_SPAWN_PER_STEP), 2 * self::maxNpcs() - intval($row[0]));
		$made = 0;
		while($queue && $made + $queue[0] <= $room) {
			$size = intval(array_shift($queue));
			$near = 0;
			for($i = 0; $i < $size; $i++) {
				$uid = self::spawnNpc($ctl, $near, $size > 1 ? ' (joined with '.($size - 1).' friend(s))' : '');
				if(!$uid) break 2;
				if(!$near) {
					$v = $database->getVillagesID($uid);
					$near = $v[0];
				}
				$made++;
			}
		}
		npc_meta_set('join_queue', implode(',', $queue));
	}

	// Creates one NPC (random tribe, profile, name) on the spiral, or next to
	// village $near (a friend's); returns its uid or false
	public static function spawnNpc(NPCController $ctl, $near = 0, $note = '') {
		global $database;
		$profile = NPCHuman::pick(self::$profiles);
		$tribes = array_map('intval', explode(',', NPC_TRIBES));
		$tribe = $tribes[mt_rand(0, count($tribes) - 1)];
		$aggression = max(0, min(100, self::$aggression[$profile] + mt_rand(-10, 10)));
		$name = self::freeName();
		$factory = new NPCFactory;
		$uid = $factory->create($name, $tribe, $profile, $aggression, $near);
		if(!$uid) {
			error_log('NPC spawn: '.$factory->error);
			return false;
		}
		$villages = $database->getVillagesID($uid);
		$ctl->log($uid, $villages[0], 'created', "tribe $tribe, $profile, aggression $aggression".$note);
		npc_log("created NPC #$uid $name: tribe $tribe, $profile ($aggression), village ".$villages[0].$note);
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

	// ---- absences and quitting ----

	// Rolls absences and quitting over [$from, $now): hazards per day by character,
	// age and frustration; each event happens at a random moment of the gap
	private static function lifeEvents(NPCController $ctl, $from, $now) {
		global $database;
		$days = ($now - $from) / 86400;
		if($days <= 0) return;
		$rows = $database->query_return("SELECT uid, diligence, created, frustration, frustration_at FROM ".TB_PREFIX."npc".
			" WHERE active = 1 AND away_until = 0");
		foreach($rows as $n) {
			$d = intval($n['diligence']) > 0 ? intval($n['diligence']) / 100 : 0.5;
			$f = NPCHuman::frustration($n, $now);
			$age = max(0, $now - $n['created']) / 86400;
			// A trickle of everyone + newcomers who only tried it (the less diligent) + the fed-up;
			// nobody in the first two hours
			$quit = (NPC_QUIT_RATE * (1.6 - $d) + NPC_DROPOUT_RATE * pow(1 - $d, 2) * exp(-$age / NPC_DROPOUT_DAYS)) *
				(1 + $f / NPC_FRUSTRATION_QUIT) * min(1, $age * 12);
			$away = NPC_ABSENCE_RATE * (1.5 - $d) * (1 + $f / NPC_FRUSTRATION_AWAY);
			$at = intval($from + NPCHuman::rand() * ($now - $from));
			if(NPCHuman::chance(1 - exp(-$quit * $days))) {
				$database->query("UPDATE ".TB_PREFIX."npc SET active = 0, quit_at = ".$at." WHERE uid = ".intval($n['uid']));
				$why = $f >= 30 ? 'fed up (frustration '.round($f).')' : ($age < NPC_DROPOUT_DAYS ? 'only tried the game' : 'lost interest');
				$ctl->log($n['uid'], 0, 'quit', 'stops playing: '.$why.', the villages stay as they are');
			} elseif(NPCHuman::chance(1 - exp(-$away * $days))) {
				// Mostly a day or two, sometimes just a night, rarely a week
				$len = (int)round(max(NPC_ABSENCE_MIN, min(NPC_ABSENCE_MAX, NPC_ABSENCE_MEDIAN * exp(0.7 * NPCHuman::gauss()))));
				$until = $at + $len;
				// The absence is not replayed by the catch-up: the NPC goes on from $until
				$database->query("UPDATE ".TB_PREFIX."npc SET away_from = ".$at.", away_until = ".$until.", next_action_at = GREATEST(next_action_at, ".$until."),".
					" sim_at = GREATEST(sim_at, ".$until.") WHERE uid = ".intval($n['uid']));
				$why = $f >= 30 ? 'fed up after losses' : self::$reasons[mt_rand(0, count(self::$reasons) - 1)];
				$ctl->log($n['uid'], 0, 'absent', 'away ('.$why.') for '.round($len / 3600).'h, until '.NPCHuman::clock('d.m. H:i', $until));
			}
		}
	}

	// An absent NPC's time is up: does it come back? The longer it was away and the
	// more frustrated, the likelier not. Called by the controller before its turn.
	public static function comesBack(NPCController $ctl, &$npc) {
		global $database;
		$now = time();
		$len = max(0, intval($npc['away_until']) - intval($npc['away_from']));
		$never = min(0.9, NPC_ABSENCE_NEVER * (1 + NPCHuman::frustration($npc, $now) / (2 * NPC_FRUSTRATION_QUIT)) *
			(0.6 + 0.4 * $len / NPC_ABSENCE_MEDIAN));
		if(NPCHuman::chance($never)) {
			$database->query("UPDATE ".TB_PREFIX."npc SET active = 0, away_until = 0, quit_at = ".intval($npc['away_until']).
				" WHERE uid = ".intval($npc['uid']));
			$ctl->log($npc['uid'], 0, 'quit', 'never came back, the villages stay as they are');
			return false;
		}
		$database->query("UPDATE ".TB_PREFIX."npc SET away_until = 0, online_until = 0 WHERE uid = ".intval($npc['uid']));
		$ctl->log($npc['uid'], 0, 'back', 'back after '.round($len / 3600).'h away');
		$npc['away_until'] = 0;
		$npc['online_until'] = 0;
		return true;
	}

	// ---- helpers ----

	// Random count with mean $lambda
	public static function poisson($lambda) {
		if($lambda <= 0) return 0;
		if($lambda > 30) return max(0, (int)round($lambda + sqrt($lambda) * NPCHuman::gauss()));
		$limit = exp(-$lambda);
		$k = 0;
		for($p = NPCHuman::rand(); $p > $limit; $p *= NPCHuman::rand()) { $k++; }
		return $k;
	}

	// Free field near village $wref (a friend's), 0 if there is none
	public static function siteNear($wref) {
		global $database;
		$c = $database->getCoor($wref);
		for($window = 3; $window <= 9; $window += 3) {
			$site = self::nearestFree(intval($c['x']) + mt_rand(-2, 2), intval($c['y']) + mt_rand(-2, 2), $window);
			if($site) return $site;
		}
		return 0;
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
