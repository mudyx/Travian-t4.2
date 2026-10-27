<?php
/*
 * Where NPCs live on the map. New NPCs are placed on a Vogel spiral around the
 * centre: the k-th one at angle k x 137.5 deg and radius
 * NATARS_MAX + 3 + NPC_SPAWN_STEP x sqrt(k). That spreads them evenly over the
 * area (no clumps, no empty quarters) and lets the occupied ring grow outwards
 * the way real sign-ups (Database::generateBase) do. Each spiral point snaps to
 * the nearest free 4-4-4-6 field that keeps its distance to other villages.
 */
class NPCWorld {

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
