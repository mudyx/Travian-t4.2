<?php
/*
 * How an NPC reacts to being attacked - like a player at the rally point, not
 * like a bot: it sees incoming attacks (time, attacker, the attacker's village
 * size) but not the troops, and it may not look, react too late or misjudge.
 *
 *   notice   NPC_NOTICE_CHANCE per session; a reaction time of 1-10 min that
 *            is longer than the time left = too late
 *   spend    with NPC_SPEND_CHANCE (x mood) the stock goes into builds and
 *            troops before the hit, otherwise the attacker finds it full
 *   troops   guesses the attack from the village's population (+-50 %) against
 *            its own defence: weaker -> more likely to dodge (raid to a target
 *            far enough that the troops are back after the hit), aggressive
 *            NPCs take the risk more often and keep the army home
 *   memory   every hit is remembered (s1_npc_grudge); with a chance by
 *            aggression, mood and number of hits a revenge raid is planned
 */
class NPCDefence {

	// Returns a time the NPC has to be awake again (to dodge on time) or 0.
	public static function turn(NPCController $ctl, &$npc, $villages, $now) {
		global $database;
		$byWref = array();
		foreach($villages as $v) { $byWref[$v->wref] = $v; }
		if(empty($byWref)) return 0;
		self::remember($ctl, $npc, array_keys($byWref), $now);

		$incoming = $database->query_return("SELECT m.moveid, m.`from`, m.`to`, m.endtime, v.owner, v.pop".
			" FROM ".TB_PREFIX."movement m JOIN ".TB_PREFIX."attacks a ON a.id = m.ref".
			" JOIN ".TB_PREFIX."vdata v ON v.wref = m.`from`".
			" WHERE m.sort_type = 3 AND m.proc = 0 AND a.attack_type IN (3,4) AND m.endtime > ".$now.
			" AND m.`to` IN (".implode(',', array_keys($byWref)).") ORDER BY m.endtime");
		if(empty($incoming)) return 0;
		$known = array();
		foreach($database->query_return("SELECT * FROM ".TB_PREFIX."npc_threat WHERE uid = ".intval($npc['uid']).
			" AND arrive_at > ".$now) as $row) {
			$known[$row['moveid']] = $row;
		}

		$wake = 0;
		foreach($incoming as $in) {
			$v = $byWref[$in['to']];
			$left = $in['endtime'] - $now;
			if(isset($known[$in['moveid']])) {
				$th = $known[$in['moveid']];
			} else {
				if($left > NPC_THREAT_HORIZON) continue;   // far away: deal with it in a later session
				if(!NPCHuman::chance(NPC_NOTICE_CHANCE)) {
					$ctl->log($npc['uid'], $v->wref, 'threat_missed', 'did not check the rally point, attack '.$in['moveid'].
						' lands in '.$left.'s', true);
					continue;
				}
				$th = self::decide($ctl, $npc, $v, $in, $left);
				if($th['spend']) {
					$ctl->spendAll($npc, $v, $th['reaction'] == 'hold');
				}
			}
			if($th['reaction'] == 'dodge' && !$th['dodged']) {
				$w = self::dodge($ctl, $npc, $v, $in);
				if($w && (!$wake || $w < $wake)) $wake = $w;
			}
		}
		return $wake;
	}

	private static function decide(NPCController $ctl, $npc, NPCVillage $v, $in, $left) {
		global $database;
		$th = array('moveid' => $in['moveid'], 'reaction' => 'late', 'spend' => 0, 'dodged' => 0, 'estimate' => 0);
		$react = mt_rand(NPC_REACTION_MIN, NPC_REACTION_MAX);
		$who = $database->getUserField($in['owner'], 'username', 0);
		$defence = 0;
		if($react < $left) {
			$th['spend'] = NPCHuman::chance(NPC_SPEND_CHANCE * NPCHuman::factor($npc, 'spend')) ? 1 : 0;
			// Guess from the village size, or what came from there last time (+20 %)
			$seen = $database->query_return("SELECT seen_attack FROM ".TB_PREFIX."npc_grudge WHERE uid = ".intval($npc['uid']).
				" AND wref = ".intval($in['from']));
			$guess = max(max(1, intval($in['pop'])) * NPC_THREAT_PER_POP, empty($seen) ? 0 : 1.2 * $seen[0]['seen_attack']);
			$th['estimate'] = $guess * NPCHuman::noise(0.5);
			$defence = self::defencePower($v);
			if(array_sum(NPCAttack::available($v, true)) < NPC_RAID_MIN_UNITS) {
				$th['reaction'] = 'none';   // no army worth saving
			} else {
				// Weaker than the guess -> mostly dodge; the bolder the NPC, the more it gambles on the wall
				$p = (1.2 - $defence / $th['estimate']) * 0.8 + 0.2 - $npc['aggression'] / 250;
				$th['reaction'] = NPCHuman::chance(max(0.05, min(0.9, $p))) ? 'dodge' : 'hold';
			}
		}
		$database->query("INSERT IGNORE INTO ".TB_PREFIX."npc_threat (moveid, uid, wref, attacker, from_wref, arrive_at, noticed_at,".
			" estimate, reaction, spend) VALUES (".intval($in['moveid']).", ".intval($npc['uid']).", ".$v->wref.", ".
			intval($in['owner']).", ".intval($in['from']).", ".intval($in['endtime']).", ".time().", ".floatval($th['estimate']).
			", '".$th['reaction']."', ".$th['spend'].")");
		$texts = array('late' => 'noticed too late, can do nothing', 'none' => 'no army to save',
			'dodge' => 'will send the army away', 'hold' => 'keeps the army home and trusts the wall');
		$ctl->log($npc['uid'], $v->wref, 'threat', 'attack from '.$who.' in '.$left.'s, guessed '.round($th['estimate']).
			' vs own defence '.round($defence).': '.$texts[$th['reaction']].
			($th['reaction'] == 'late' ? '' : ($th['spend'] ? ', spends the stock' : ', forgets the stock')));
		return $th;
	}

	// Sends everyone away if a target far enough exists; else returns when to look again
	private static function dodge(NPCController $ctl, $npc, NPCVillage $v, $in) {
		global $database;
		$plan = NPCAttack::dodgePlan($npc, $v, $in['endtime'], $in['from']);
		if($plan && isset($plan['units'])) {
			if(NPCActions::sendRaid($npc['uid'], $v, $plan)) {
				$database->query("UPDATE ".TB_PREFIX."npc_threat SET dodged = 1 WHERE moveid = ".intval($in['moveid']));
				$ctl->log($npc['uid'], $v->wref, 'dodge', array_sum($plan['units']).' units sent to '.$plan['target']['name'].
					' '.$plan['target']['x'].'|'.$plan['target']['y'].', back in '.(2 * $plan['seconds']).'s, hit in '.
					($in['endtime'] - time()).'s');
				$v->refresh();
			}
			return 0;
		}
		if($plan && $plan['wait'] > time()) {
			return $plan['wait'];   // too early to leave - the troops would be back before the hit
		}
		$database->query("UPDATE ".TB_PREFIX."npc_threat SET reaction = 'hold' WHERE moveid = ".intval($in['moveid']));
		$ctl->log($npc['uid'], $v->wref, 'dodge_failed', 'nowhere to send the army in time, it stays and fights');
		return 0;
	}

	// Units at home x average defence, wall roughly +2.5 % per level
	public static function defencePower(NPCVillage $v) {
		$sum = 0;
		$first = ($v->tribe - 1) * 10;
		for($i = 1; $i <= 10; $i++) {
			$unit = $first + $i;
			if(!isset($GLOBALS['u'.$unit]) || empty($v->units['u'.$unit])) continue;
			$sum += intval($v->units['u'.$unit]) * ($GLOBALS['u'.$unit]['di'] + $GLOBALS['u'.$unit]['dc']) / 2;
		}
		return $sum * pow(1.025, $v->typeLevel(NPCRules::wallType($v->tribe)));
	}

	// Attack of the army that came, read from the defender's battle report like a
	// player would ("attacker,from,tribe,t1..t11 sent,t1..t11 dead,..."); 0 if none
	private static function reportedAttack($uid, $hit) {
		global $database;
		$rows = $database->query_return("SELECT data FROM ".TB_PREFIX."ndata WHERE uid = ".intval($uid).
			" AND toWref = ".intval($hit['to'])." AND time = ".intval($hit['endtime']).
			" AND data LIKE '".intval($hit['owner']).",".intval($hit['from']).",%' LIMIT 1");
		if(empty($rows)) return 0;
		$p = explode(',', $rows[0]['data']);
		$attack = 0;
		for($i = 1; $i <= 10; $i++) {
			$unit = (intval($p[2]) - 1) * 10 + $i;
			if(isset($GLOBALS['u'.$unit], $p[2 + $i])) $attack += intval($p[2 + $i]) * $GLOBALS['u'.$unit]['atk'];
		}
		return $attack;
	}

	// Hits that landed since the last look go into memory; maybe a revenge gets planned
	private static function remember(NPCController $ctl, &$npc, $wrefs, $now) {
		global $database;
		$hits = $database->query_return("SELECT m.`from`, m.`to`, m.endtime, v.owner FROM ".TB_PREFIX."movement m".
			" JOIN ".TB_PREFIX."attacks a ON a.id = m.ref JOIN ".TB_PREFIX."vdata v ON v.wref = m.`from`".
			" WHERE m.sort_type = 3 AND m.proc = 1 AND a.attack_type IN (3,4) AND m.`to` IN (".implode(',', $wrefs).")".
			" AND m.endtime > ".intval($npc['hits_seen_at'])." AND m.endtime <= ".$now);
		$database->query("UPDATE ".TB_PREFIX."npc SET hits_seen_at = ".$now." WHERE uid = ".intval($npc['uid']));
		$npc['hits_seen_at'] = $now;
		foreach($hits as $h) {
			$owner = intval($h['owner']);
			if($owner == $npc['uid'] || $owner <= 4) continue;   // own or system villages
			$attack = self::reportedAttack($npc['uid'], $h);
			$database->query("INSERT INTO ".TB_PREFIX."npc_grudge (uid, wref, enemy, hits, last_hit, seen_attack) VALUES (".
				intval($npc['uid']).", ".intval($h['from']).", ".$owner.", 1, ".intval($h['endtime']).", ".floatval($attack).")".
				" ON DUPLICATE KEY UPDATE hits = hits + 1, enemy = VALUES(enemy), last_hit = VALUES(last_hit),".
				" seen_attack = GREATEST(seen_attack, VALUES(seen_attack))");
			$g = $database->query_return("SELECT * FROM ".TB_PREFIX."npc_grudge WHERE uid = ".intval($npc['uid']).
				" AND wref = ".intval($h['from']));
			$g = $g[0];
			// Every hit annoys, being farmed again and again more so
			NPCHuman::upset($npc['uid'], NPC_UPSET_HIT + 2 * min(5, $g['hits'] - 1));
			if($g['revenge_at'] > 0) continue;   // already plotting
			$who = $database->getUserField($owner, 'username', 0);
			$isNpc = mysql_num_rows($database->query("SELECT 1 FROM ".TB_PREFIX."npc WHERE uid = ".$owner)) > 0;
			if(!NPCAttack::canRaid($npc)) {
				$why = 'not the type to strike back';
			} elseif(!$isNpc && !NPC_ATTACK_REAL_PLAYERS) {
				$why = 'leaves real players alone (NPC_ATTACK_REAL_PLAYERS)';
			} else {
				$p = min(0.9, NPC_REVENGE_CHANCE * $npc['aggression'] / 50 * NPCHuman::factor($npc, 'revenge') *
					(1 + 0.25 * ($g['hits'] - 1)));
				if(NPCHuman::chance($p)) {
					$at = $now + mt_rand(NPC_REVENGE_DELAY_MIN, NPC_REVENGE_DELAY_MAX);
					$database->query("UPDATE ".TB_PREFIX."npc_grudge SET revenge_at = ".$at." WHERE uid = ".intval($npc['uid']).
						" AND wref = ".intval($h['from']));
					$ctl->log($npc['uid'], 0, 'revenge_plan', $who.' hit me ('.$g['hits'].'x), revenge at '.date('H:i', $at));
					continue;
				}
				$why = 'lets it go this time';
			}
			$ctl->log($npc['uid'], 0, 'grudge', $who.' hit me ('.$g['hits'].'x), '.$why);
		}
	}
}
