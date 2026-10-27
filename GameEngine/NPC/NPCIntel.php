<?php
/*
 * What NPCs know about their targets. Filled only from information a player
 * would have too - the outcome of their own raids (later: scouting) - never by
 * reading the target's units or stock.
 *
 * Defence estimate: Battle::calculateBattle() gives a raiding attacker the loss
 * share L = h/(1+h) with h = (D/A)^1.5, for winner and loser alike, so
 *   D = A * (L/(1-L))^(2/3)
 * with A = the raid's attack in the NPC's own units (NPCAttack). If the whole
 * raid died, only a lower bound is known.
 */
class NPCIntel {

	// Evaluates raids whose battle has been fought; returns how many.
	public static function evaluateRaids() {
		global $database;
		$raids = $database->query_return("SELECT r.*, a.t1, a.t2, a.t3, a.t4, a.t5, a.t6, a.t7, a.t8, a.t9, a.t10".
			" FROM ".TB_PREFIX."npc_raid r JOIN ".TB_PREFIX."attacks a ON a.id = r.ref".
			" WHERE r.evaluated = 0 AND EXISTS (SELECT 1 FROM ".TB_PREFIX."movement m".
			" WHERE m.ref = r.ref AND m.sort_type = 3 AND m.proc = 1)");
		foreach($raids as $r) {
			// sendunitsComplete() subtracts the casualties from the attacks row
			$survivors = 0;
			for($i = 1; $i <= 10; $i++) { $survivors += intval($r['t'.$i]); }
			$sent = max(1, intval($r['sent_units']));
			$lost = max(0, $sent - $survivors);

			// Loot rides home with the survivors: "wood,clay,iron,crop,capacity"
			$loot = 0;
			$back = $database->query_return("SELECT data FROM ".TB_PREFIX."movement WHERE ref = ".intval($r['ref']).
				" AND sort_type = 4 LIMIT 1");
			if(!empty($back)) {
				$parts = explode(',', $back[0]['data']);
				for($i = 0; $i < 4; $i++) { $loot += isset($parts[$i]) ? intval($parts[$i]) : 0; }
			}

			$database->query("UPDATE ".TB_PREFIX."npc_raid SET evaluated = 1, lost_units = ".$lost.", loot = ".$loot.
				" WHERE id = ".intval($r['id']));
			// Only the survivors carried loot home
			$carried = intval($r['capacity']) * $survivors / $sent;
			self::learn($r['uid'], $r['to_wref'], floatval($r['attack']), $lost / $sent, $loot, $carried, $lost);
			// A raid that was wiped out hurts (the more troops, the more); a rich haul cheers up
			if($lost >= 10 && $lost / $sent >= 0.7) {
				NPCHuman::upset($r['uid'], NPC_UPSET_RAID + min(30, $lost / 4));
			} elseif($carried > 0 && $loot >= 0.6 * $carried) {
				NPCHuman::upset($r['uid'], -3);
			}
		}
		return count($raids);
	}

	private static function learn($uid, $wref, $attack, $lossShare, $loot, $capacity, $lost) {
		global $database;
		if($lossShare >= 0.999) {
			$defence = $attack * 2;   // everyone died: at least this strong
			$known = 2;
		} else {
			$defence = $attack * pow($lossShare / (1 - $lossShare), 2 / 3);
			$known = 1;
		}
		// The survivors came back full: there was more to take - expect half again next time
		$expect = ($capacity > 0 && $loot >= 0.95 * $capacity) ? round($loot * 1.5) : max($loot, 0);
		$database->query("INSERT INTO ".TB_PREFIX."npc_intel (uid, wref, defence, defence_known, loot, raids, losses, source, updated)".
			" VALUES (".intval($uid).", ".intval($wref).", ".floatval($defence).", ".$known.", ".intval($expect).", 1, ".intval($lost).
			", 'raid', ".time().")".
			" ON DUPLICATE KEY UPDATE defence = VALUES(defence), defence_known = VALUES(defence_known), loot = VALUES(loot),".
			" raids = raids + 1, losses = losses + VALUES(losses), source = 'raid', updated = VALUES(updated)");
	}

	// All intel of one NPC: wref => row
	public static function forNpc($uid) {
		global $database;
		$map = array();
		foreach($database->query_return("SELECT * FROM ".TB_PREFIX."npc_intel WHERE uid = ".intval($uid)) as $row) {
			$map[$row['wref']] = $row;
		}
		return $map;
	}
}
