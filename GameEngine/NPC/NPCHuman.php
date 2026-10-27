<?php
/*
 * The player behind an NPC: a character, a daily rhythm and the way it decides.
 *
 *   character (fixed per NPC, 0-100)
 *     diligence   how closely it follows the game: longer online blocks, shorter
 *                 breaks, comes back closer to the moment it was waiting for
 *     discipline  saves for what it decided to build instead of grabbing
 *                 something cheaper (and how long it keeps waiting)
 *     skill       how often it picks the best option, how rarely it overlooks
 *                 a free build queue
 *   mood          a focus (build / raid / save) for 1-3 days, picked by profile;
 *                 changes tastes, army share, raid pace, patience
 *   rhythm        online blocks (30-120 min) and breaks (15-90 min); while online
 *                 it comes back when it expects something (queue done, enough
 *                 resources) plus a human delay; a nightly sleep of 6-8 h
 *   decisions     every option gets a utility, the choice is drawn from them
 *                 (softmax, the less skill the flatter); the chosen build becomes
 *                 a goal it saves for - or not, when impatient or the storage fills
 * Rhythm times are real seconds (people don't sleep faster on a speed server),
 * the sleep window uses NPC_TIMEZONE.
 */
class NPCHuman {

	// Mood => multipliers (away = break length, army share, raid interval, spending
	// before a hit, revenge) and discipline shift
	public static $moods = array(
		'build' => array('away' => 0.8, 'army' => 0.6, 'raid' => 1.5, 'spend' => 1.2, 'revenge' => 0.8, 'discipline' => -10),
		'raid'  => array('away' => 0.9, 'army' => 1.5, 'raid' => 0.6, 'spend' => 0.9, 'revenge' => 1.4, 'discipline' => -5),
		'save'  => array('away' => 1.1, 'army' => 0.8, 'raid' => 1.2, 'spend' => 0.7, 'revenge' => 1.0, 'discipline' => 25),
	);

	// Odds of each mood per profile
	public static $moodOdds = array(
		'farmer'    => array('build' => 50, 'raid' => 10, 'save' => 40),
		'balanced'  => array('build' => 40, 'raid' => 30, 'save' => 30),
		'aggressor' => array('build' => 30, 'raid' => 55, 'save' => 15),
	);

	// How much a reason is worth to a player
	private static $utility = array('expansion' => 3.0, 'storage' => 3.0, 'colony' => 2.5, 'trade' => 2.2, 'oasis' => 2.0, 'lowest production' => 2.0,
		'plan' => 2.0, 'whim' => 1.2);

	// Buildings a raid-minded player suddenly cares about
	private static $military = array(12, 13, 16, 19, 20, 21, 22, 31, 32, 33);

	// Fills in character, sleep, hit watermark on first use and rolls a new mood
	// when the old one ran out (at $t - the replayed time during catch-up).
	// Returns true when the mood changed.
	public static function init(&$npc, $t) {
		global $database;
		$set = array();
		if(intval($npc['diligence']) == 0) {
			$npc['diligence'] = mt_rand(30, 95);
			$npc['discipline'] = mt_rand(20, 90);
			$npc['skill'] = mt_rand(30, 90);
			$set[] = "diligence = ".$npc['diligence'].", discipline = ".$npc['discipline'].", skill = ".$npc['skill'];
		}
		if(intval($npc['sleep_len']) == 0) {
			$npc['sleep_start'] = (NPC_SLEEP_FROM + mt_rand(0, NPC_SLEEP_SPREAD)) % 1440;
			$npc['sleep_len'] = mt_rand(NPC_SLEEP_MIN, NPC_SLEEP_MAX);
			$set[] = "sleep_start = ".$npc['sleep_start'].", sleep_len = ".$npc['sleep_len'];
		}
		if(intval($npc['hits_seen_at']) == 0) {
			$npc['hits_seen_at'] = $t;   // older attacks are forgotten
			$set[] = "hits_seen_at = ".intval($t);
		}
		$changed = false;
		if(!isset(self::$moods[$npc['mood']]) || intval($npc['mood_until']) <= $t) {
			$odds = isset(self::$moodOdds[$npc['profile']]) ? self::$moodOdds[$npc['profile']] : self::$moodOdds['balanced'];
			$npc['mood'] = self::pick($odds);
			$npc['mood_until'] = $t + mt_rand(NPC_MOOD_MIN, NPC_MOOD_MAX);
			$set[] = "mood = '".$npc['mood']."', mood_until = ".intval($npc['mood_until']);
			$changed = true;
		}
		if($set) {
			$database->query("UPDATE ".TB_PREFIX."npc SET ".implode(', ', $set)." WHERE uid = ".intval($npc['uid']));
		}
		return $changed;
	}

	public static function factor($npc, $key) {
		return isset(self::$moods[$npc['mood']][$key]) ? self::$moods[$npc['mood']][$key] : 1;
	}

	// ---- rhythm ----

	// When the NPC looks again after a session at $t that wants to be back in $need
	// seconds. Returns array(time, 'online' | 'break').
	public static function nextVisit(&$npc, $t, $need) {
		global $database;
		$d = intval($npc['diligence']) / 100;
		if(intval($npc['online_until']) <= $t) {
			$npc['online_until'] = $t + self::block($d);   // sat down to play (morning, after closing the game)
		}
		// Back a bit after the expected moment: the less diligent, the later
		$next = $t + $need + round($need * self::rand() * NPC_OVERSHOOT * (1.5 - $d)) + mt_rand(NPC_LOOK_MIN, NPC_LOOK_MAX);
		$kind = 'online';
		if($next > $npc['online_until']) {
			// The block is over: away for a while, then a new block
			$away = round(mt_rand(NPC_BREAK_MIN, NPC_BREAK_MAX) * (1.5 - $d) * self::factor($npc, 'away'));
			$next = max($next, intval($npc['online_until']) + $away);
			$npc['online_until'] = $next + self::block($d);
			$kind = 'break';
		}
		$database->query("UPDATE ".TB_PREFIX."npc SET online_until = ".intval($npc['online_until'])." WHERE uid = ".intval($npc['uid']));
		return array($next, $kind);
	}

	private static function block($d) {
		return round(mt_rand(NPC_SESSION_MIN, NPC_SESSION_MAX) * (0.5 + $d));
	}

	// Asleep players start a fresh block when they get up
	public static function wentToSleep(&$npc) {
		global $database;
		$npc['online_until'] = 0;
		$database->query("UPDATE ".TB_PREFIX."npc SET online_until = 0 WHERE uid = ".intval($npc['uid']));
	}

	public static function asleep($npc, $t) {
		return intval($npc['sleep_len']) > 0 && self::minutesIntoSleep($npc, $t) < intval($npc['sleep_len']);
	}

	// When the NPC gets up again (plus a slow morning of up to 15 min)
	public static function wakeAt($npc, $t) {
		return $t + (intval($npc['sleep_len']) - self::minutesIntoSleep($npc, $t)) * 60 - intval(date('s', $t)) + mt_rand(0, 900);
	}

	private static function minutesIntoSleep($npc, $t) {
		$minute = intval(self::clock('G', $t)) * 60 + intval(self::clock('i', $t));
		// The night is named after the evening it started, so one night keeps one bedtime
		$bedtime = intval($npc['sleep_start']) + abs(crc32($npc['uid'].self::clock('Ymd', $t - 43200))) % 61 - 30;
		return (($minute - $bedtime) % 1440 + 1440) % 1440;
	}

	// date() in NPC_TIMEZONE (the players' day), server clock if empty
	public static function clock($format, $t) {
		static $tz = false;
		if($tz === false) $tz = NPC_TIMEZONE != '' ? new DateTimeZone(NPC_TIMEZONE) : null;
		if(!$tz) return date($format, $t);
		$d = new DateTime('@'.$t);
		$d->setTimezone($tz);
		return $d->format($format);
	}

	// ---- decisions ----

	// Build candidates in the order the player considers them. Each gets a utility
	// (reason x mood taste x the profile's preference order); the order is drawn
	// with weight utility^(1/T), T from 0.25 (skilled: nearly always the best) to
	// 1.25 (unskilled: often something else). A crop emergency is never left to chance.
	public static function choose($candidates, $npc) {
		$temperature = 0.25 + (100 - intval($npc['skill'])) / 100;
		$out = $pool = array();
		foreach($candidates as $i => $c) {
			if($c['reason'] == 'crop reserve') { $out[] = $c; continue; }
			$u = 1;
			foreach(self::$utility as $key => $value) {
				if(strpos($c['reason'], $key) === 0) { $u = $value; break; }
			}
			$u *= self::taste($npc, $c) * pow(0.75, $i);
			$pool[] = array('c' => $c, 'w' => pow($u, 1 / $temperature));
		}
		while($pool) {
			$weights = array();
			foreach($pool as $i => $p) { $weights[$i] = $p['w']; }
			$i = self::pick($weights);
			$out[] = $pool[$i]['c'];
			unset($pool[$i]);
		}
		return $out;
	}

	// Key of the best-liked option, drawn as in choose(): $scores = key => utility > 0
	public static function pickBest($scores, $npc) {
		$temperature = 0.25 + (100 - intval($npc['skill'])) / 100;
		$weights = array();
		foreach($scores as $key => $u) { $weights[$key] = pow($u, 1 / $temperature); }
		return self::pick($weights);
	}

	private static function taste($npc, $c) {
		switch($npc['mood']) {
			case 'build': return $c['field'] > 18 ? 1.6 : 1.0;
			case 'raid':  return in_array($c['tid'], self::$military) ? 2.0 : 1.0;
			case 'save':  return in_array($c['tid'], array(10, 11)) ? 2.0 : ($c['field'] > 18 ? 1.2 : 0.8);
		}
		return 1;
	}

	// Its pick is too expensive right now: keep saving for it (true) or grab
	// something affordable (false)? A filling storage always tempts; the longer it
	// has been saving, the more patience runs out.
	public static function keepsSaving($npc, NPCVillage $v, $savingFor) {
		$full = max($v->stock('wood'), $v->stock('clay'), $v->stock('iron')) / max(1, $v->vdata['maxstore']);
		$fullCrop = $v->stock('crop') / max(1, $v->vdata['maxcrop']);
		if(max($full, $fullCrop) >= NPC_OVERFLOW) return false;
		$discipline = max(0, min(100, intval($npc['discipline']) + self::factor($npc, 'discipline'))) / 100;
		$patience = NPC_GOAL_PATIENCE / SPEED * (0.5 + $discipline);
		if($savingFor > $patience) return false;
		return self::chance(0.3 + 0.7 * $discipline);
	}

	// Chance to miss a free build queue in this session
	public static function overlooks($npc) {
		return self::chance(NPC_OVERLOOK * (100 - intval($npc['skill'])) / 100);
	}

	// Key drawn by weight
	public static function pick($weights) {
		$r = self::rand() * array_sum($weights);
		foreach($weights as $key => $w) {
			$r -= $w;
			if($r < 0) return $key;
		}
		end($weights);
		return key($weights);
	}

	public static function chance($p) {
		return self::rand() < $p;
	}

	// 1 +- $spread: a person's estimate of a value
	public static function noise($spread) {
		return 1 + (self::rand() * 2 - 1) * $spread;
	}

	public static function rand() {
		return mt_rand() / (mt_getrandmax() + 1);
	}

	// Standard normal (Box-Muller)
	public static function gauss() {
		return sqrt(-2 * log(1 - self::rand())) * cos(2 * M_PI * self::rand());
	}

	// ---- frustration ----

	// How fed up the NPC is now (0-100): lost battles and being farmed add to it,
	// it halves every NPC_FRUSTRATION_HALFLIFE. Makes absences and quitting likelier.
	public static function frustration($npc, $t) {
		$f = floatval($npc['frustration']);
		if($f <= 0) return 0;
		return $f * pow(0.5, max(0, $t - intval($npc['frustration_at'])) / NPC_FRUSTRATION_HALFLIFE);
	}

	// Adds $amount (negative = a success cheers it up)
	public static function upset($uid, $amount) {
		global $database;
		$now = time();
		$rows = $database->query_return("SELECT frustration, frustration_at FROM ".TB_PREFIX."npc WHERE uid = ".intval($uid));
		if(empty($rows)) return;
		$f = max(0, min(100, self::frustration($rows[0], $now) + $amount));
		$database->query("UPDATE ".TB_PREFIX."npc SET frustration = ".round($f, 2).", frustration_at = ".$now." WHERE uid = ".intval($uid));
	}
}
