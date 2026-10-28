<?php
/*
 * The NPC's hero, looked after like a player does on the hero pages - with the
 * same rules (hero.php, hero_inventory.php / Inventory.php, hero_adventure.php,
 * hero_auction.php, Units::Adventures), written DB-only without a session.
 *
 *   revive      a dead hero comes back by a bucket, or for the level's price
 *               (hero_t<tribe>) in the home village; until then the village
 *               saves for it (diligent players and raiders)
 *   points      new points go where the player's taste is: raiders into
 *               fighting strength and attack bonus, farmers into production,
 *               the mood shifts it; the production bonus goes to all resources
 *               or to the one the village is short of
 *   items       the best piece for each slot is worn (by what the profile
 *               values), XP scrolls are read, ointments used when hurt, art
 *               works when culture points are short, law tablets when loyalty
 *               dropped, a book of wisdom when the points no longer fit
 *   adventures  generated as the adventure page does; the hero goes when it is
 *               home and healthy enough for the player's guess of the damage
 *               (careful players keep more health back, hard ones need more)
 *   auction     surplus items are put up for sale; with the silver the player
 *               now and then bids on something it would actually use
 *   oases       NPCOasis (heroes' mansion, clearing, cages, annexing)
 *   raids       NPCHero::escort() lets the hero ride along when it is worth the risk
 * The player does not open the hero pages at every visit: only when something
 * calls for it (points, wounds, death, new loot) or by habit (diligence).
 */
class NPCHero {

	// Equipment slots: item btype => heroface column
	public static $slots = array(1 => 'helmet', 2 => 'body', 3 => 'leftHand', 4 => 'rightHand', 5 => 'shoes', 6 => 'horse');

	// What an item's effect is worth to a player of each profile (per point of the effect)
	private static $worth = array(
		'itemfs'            => array('farmer' => 0.02, 'balanced' => 0.03, 'aggressor' => 0.045),
		'itemautoregen'     => array('farmer' => 1.0, 'balanced' => 1.0, 'aggressor' => 1.2),
		'itemextraresist'   => array('farmer' => 2.5, 'balanced' => 3.0, 'aggressor' => 3.5),
		'itemextraexpgain'  => array('farmer' => 0.8, 'balanced' => 0.8, 'aggressor' => 0.8),
		'itemcpproduction'  => array('farmer' => 2.0, 'balanced' => 1.5, 'aggressor' => 0.8),
		'itemcavalrytrain'  => array('farmer' => 0.3, 'balanced' => 0.8, 'aggressor' => 1.2),
		'iteminfantrytrain' => array('farmer' => 0.3, 'balanced' => 0.8, 'aggressor' => 1.2),
		'itemreturnmspeed'  => array('farmer' => 0.1, 'balanced' => 0.2, 'aggressor' => 0.3),
		'itemaccountmspeed' => array('farmer' => 0.2, 'balanced' => 0.3, 'aggressor' => 0.3),
		'itemallymspeed'    => array('farmer' => 0.02, 'balanced' => 0.02, 'aggressor' => 0.02),   // NPCs have no alliance
		'itemrob'           => array('farmer' => 0.1, 'balanced' => 0.8, 'aggressor' => 1.5),
		'itemvsnatars'      => array('farmer' => 0.05, 'balanced' => 0.05, 'aggressor' => 0.1),
		'itemspeed'         => array('farmer' => 2.0, 'balanced' => 2.5, 'aggressor' => 3.0),
	);

	// Where a profile likes to put its points
	private static $taste = array(
		'farmer'    => array('power' => 2, 'offBonus' => 0.5, 'defBonus' => 1.5, 'product' => 6),
		'balanced'  => array('power' => 4, 'offBonus' => 1, 'defBonus' => 1, 'product' => 4),
		'aggressor' => array('power' => 6, 'offBonus' => 3, 'defBonus' => 0.3, 'product' => 1),
	);

	// How careful with the hero's health (0 reckless .. 1 careful), by profile
	private static $caution = array('farmer' => 0.8, 'balanced' => 0.55, 'aggressor' => 0.3);

	private static $resources = array(1 => 'wood', 2 => 'clay', 3 => 'iron', 4 => 'crop');

	// ---- the session ----

	public static function turn(NPCController $ctl, &$npc, $villages, $now) {
		global $database;
		$uid = intval($npc['uid']);
		$hero = $database->getHero($uid);
		if(!$hero || empty($villages)) return;
		$home = self::home($hero, $villages);
		$state = self::state($uid);
		if($hero['dead']) {
			self::revive($ctl, $npc, $hero, $home, $villages);
			return;
		}
		$items = count(self::items($uid));
		$loot = $items != intval($state['items_seen']);
		$hurt = $hero['health'] < self::healAt($npc);
		$away = intval($home->units['hero']) < 1;
		// Opens the hero pages when something calls for it, otherwise by habit (less while the hero is out)
		if(!$hero['points'] && !$hurt && !$loot && !NPCHuman::chance((0.15 + 0.4 * self::d($npc)) * ($away ? 0.3 : 1))) return;
		self::$inventory = null;

		self::points($ctl, $npc, $hero, $home);
		self::consume($ctl, $npc, $hero, $villages);
		self::equip($ctl, $npc);
		self::visibility($npc, $hero);
		if(NPCHuman::chance(0.2 + 0.4 * self::d($npc))) NPCAuction::turn($ctl, $npc);

		if(!$away) {
			$hero = $database->getHero($uid);
			// Home and fit: an oasis to take, else an adventure
			if(NPCOasis::turn($ctl, $npc, $hero, $home) || self::adventure($ctl, $npc, $hero, $home, $now)) {
				$home->refresh();   // the hero (and troops) left
			}
		}
		if($loot || self::$inventory === null) self::saveState($uid, array('items_seen' => count(self::items($uid))));
	}

	// The village the hero belongs to (hero.wref), the capital if that is gone
	public static function home($hero, $villages) {
		foreach($villages as $v) { if($v->wref == $hero['wref']) return $v; }
		foreach($villages as $v) { if($v->capital) return $v; }
		return $villages[0];
	}

	// ---- death ----

	private static function revive(NPCController $ctl, $npc, $hero, NPCVillage $home, $villages) {
		global $database;
		$uid = intval($npc['uid']);
		$wrefs = array();
		foreach($villages as $v) { $wrefs[] = $v->wref; }
		$busy = $database->query_return("SELECT id FROM ".TB_PREFIX."training WHERE unit = 0 AND vref IN (".implode(',', $wrefs).") LIMIT 1");
		if(!empty($busy)) return;   // already being revived
		// A bucket brings it back at once (Inventory.php, btype 12)
		$bucket = $database->query_return("SELECT * FROM ".TB_PREFIX."heroitems WHERE uid = $uid AND btype = 12 AND num > 0 LIMIT 1");
		if(!empty($bucket)) {
			$database->query("UPDATE ".TB_PREFIX."hero SET dead = 0, health = 100, wref = ".$home->wref.", lastupdate = ".time()." WHERE uid = $uid");
			$database->query("UPDATE ".TB_PREFIX."units SET hero = 1 WHERE vref = ".$home->wref);
			self::useItem($bucket[0], 1);
			$ctl->log($uid, $home->wref, 'hero_revive', 'used a bucket, the hero is back at once');
			return;
		}
		$table = $GLOBALS['hero_t'.$npc['tribe']];
		$cost = $table[min(intval($hero['level']), count($table) - 1)];
		$ok = true;
		foreach(self::$resources as $r) { if($home->spendable($r) < $cost[$r]) $ok = false; }
		if($ok && NPCActions::takeResources($home->wref, $cost)) {
			// hero.php ?r: trainHero() + the hero's new home
			$each = round($cost['time'] / SPEED);
			$database->query("INSERT INTO ".TB_PREFIX."training VALUES (0, ".$home->wref.", 0, 1, 6, ".time().", ".($each * 1000).", ".time().", ".(time() + $each).")");
			$database->query("UPDATE ".TB_PREFIX."hero SET wref = ".$home->wref." WHERE uid = $uid");
			$ctl->log($uid, $home->wref, 'hero_revive', 'revives the level '.$hero['level'].' hero for '.$cost['wood'].'/'.$cost['clay'].'/'.
				$cost['iron'].'/'.$cost['crop'].', back in '.$each.'s');
			return;
		}
		// Not enough yet: most players save for the hero first
		if(self::d($npc) >= 0.45 || $npc['profile'] == 'aggressor') {
			foreach(self::$resources as $r) {
				$home->reserve[$r] = max(isset($home->reserve[$r]) ? $home->reserve[$r] : 0, $cost[$r]);
			}
			$ctl->log($uid, $home->wref, 'hero_wait', 'saving to revive the hero ('.$cost['wood'].'/'.$cost['clay'].'/'.
				$cost['iron'].'/'.$cost['crop'].')', true);
		}
	}

	// ---- points and production ----

	private static function points(NPCController $ctl, $npc, $hero, NPCVillage $home) {
		global $database;
		$uid = intval($npc['uid']);
		$left = intval($hero['points']);
		if($left > 0) {
			$taste = self::$taste[isset(self::$taste[$npc['profile']]) ? $npc['profile'] : 'balanced'];
			switch($npc['mood']) {
				case 'raid':  $taste['power'] *= 1.5; $taste['offBonus'] *= 1.5; break;
				case 'build': $taste['product'] *= 1.5; break;
				case 'save':  $taste['product'] *= 1.2; $taste['defBonus'] *= 1.3; break;
			}
			if($hero['level'] < 5) $taste['power'] *= 1.5;   // a weak hero dies on adventures
			$add = array('power' => 0, 'offBonus' => 0, 'defBonus' => 0, 'product' => 0);
			for(; $left > 0; $left--) {
				$w = array();
				foreach($taste as $attr => $t) { if($hero[$attr] + $add[$attr] < 100) $w[$attr] = $t; }
				if(empty($w)) break;
				$add[NPCHuman::pick($w)]++;
			}
			$set = array();
			$text = array();
			foreach($add as $attr => $n) {
				if($n == 0) continue;
				$set[] = "$attr = $attr + $n";
				$text[] = "+$n ".$attr;
			}
			$spent = array_sum($add);
			$database->query("UPDATE ".TB_PREFIX."hero SET ".implode(', ', $set).", points = points - $spent WHERE uid = $uid AND points >= $spent");
			$ctl->log($uid, 0, 'hero_points', 'level '.$hero['level'].': '.implode(', ', $text).' ('.$npc['profile'].', '.$npc['mood'].' mood)');
			// Now and then a new look after a level-up (hero.php, free)
			if(NPCHuman::chance(0.03)) {
				$face = $database->getHeroFace($uid);
				$database->modifyWholeHeroFace($uid, mt_rand(0, 4), mt_rand(0, 4), mt_rand(0, 4), mt_rand(0, 4), mt_rand(0, 4),
					mt_rand(0, 4), mt_rand(0, 4), mt_rand(0, 3), mt_rand(0, 5), intval($face['gender']));
				$ctl->log($uid, 0, 'hero_look', 'gave the hero a new look');
			}
		}
		// Production bonus: all resources, or the one the home village is short of
		$prod = array();
		foreach(self::$resources as $i => $r) { $prod[$i] = max(1, $home->prod($r)) / NPCEconomy::$weights[self::profile($npc)][$i]; }
		$low = array_search(min($prod), $prod);
		$want = min($prod) < 0.7 * array_sum($prod) / 4 ? $low : 0;
		$now = 0;
		for($i = 0; $i <= 4; $i++) { if($hero['r'.$i]) $now = $i; }
		if($want != $now && ($left < intval($hero['points']) || NPCHuman::chance(0.3))) {
			$set = array();
			for($i = 0; $i <= 4; $i++) { $set[] = "r$i = ".($i == $want ? 1 : 0); }
			$database->query("UPDATE ".TB_PREFIX."hero SET ".implode(', ', $set)." WHERE uid = $uid");
			$ctl->log($uid, $home->wref, 'hero_points', 'hero production now on '.($want ? self::$resources[$want] : 'all resources'));
		}
	}

	// ---- items ----

	// Item rows the hero has (in the bag or worn)
	public static function items($uid) {
		global $database;
		return $database->query_return("SELECT * FROM ".TB_PREFIX."heroitems WHERE uid = ".intval($uid)." AND (num > 0 OR proc = 1)");
	}

	private static $inventory = null;   // uid => free inventory rows, read once per look

	// Free items of one kind in the inventory (not worn), best type first
	private static function stock($uid, $btype) {
		global $database;
		$uid = intval($uid);
		if(!isset(self::$inventory[$uid])) {
			self::$inventory = array($uid => $database->query_return("SELECT * FROM ".TB_PREFIX."heroitems WHERE uid = $uid".
				" AND num > 0 AND proc = 0 ORDER BY type DESC"));
		}
		$out = array();
		foreach(self::$inventory[$uid] as $it) { if($it['btype'] == $btype) $out[] = $it; }
		return $out;
	}

	// Takes $n of an inventory row as Inventory.php does (proc = 1 when it is used up)
	private static function useItem($item, $n) {
		global $database;
		self::$inventory = null;
		$n = min(intval($n), intval($item['num']));
		$database->query("UPDATE ".TB_PREFIX."heroitems SET num = num - $n".($n >= $item['num'] ? ", proc = 1" : "")." WHERE id = ".intval($item['id']));
		return $n;
	}

	// One-off items: XP scrolls, ointments, art works, law tablets, books of wisdom
	private static function consume(NPCController $ctl, $npc, $hero, $villages) {
		global $database;
		$uid = intval($npc['uid']);
		foreach(self::stock($uid, 10) as $it) {   // scroll: 10 XP each (x item speed)
			if(!NPCHuman::chance(0.8)) continue;
			$n = self::useItem($it, $it['num']);
			$database->query("UPDATE ".TB_PREFIX."hero SET experience = experience + ".($n * 10 * ITEMATTRSPEED)." WHERE uid = $uid");
			$ctl->log($uid, 0, 'hero_item', "read $n scroll(s): +".($n * 10 * ITEMATTRSPEED).' XP');
		}
		$health = round($hero['health']);
		if($health < self::healAt($npc)) {
			foreach(self::stock($uid, 11) as $it) {   // ointment: 1 health each
				$n = self::useItem($it, min($it['num'], 100 - $health));
				$database->query("UPDATE ".TB_PREFIX."hero SET health = LEAST(100, health + $n) WHERE uid = $uid");
				$ctl->log($uid, 0, 'hero_item', "used $n ointment(s), health $health -> ".min(100, $health + $n));
				break;
			}
		}
		$art = self::stock($uid, 15);
		if($art && self::needsCulture($npc, count($villages))) {   // art work: a day of all villages' culture points each
			$n = self::useItem($art[0], $art[0]['num']);
			$cp = $n * $database->getVSumField($uid, 'cp');
			$database->query("UPDATE ".TB_PREFIX."users SET cp = cp + ".intval($cp)." WHERE id = $uid");
			$ctl->log($uid, 0, 'hero_item', "used $n work(s) of art: +$cp culture points for the next village");
		}
		$tablets = self::stock($uid, 14);
		if($tablets) {   // law tablets: loyalty of a village that was hit by chiefs
			foreach($villages as $v) {
				$loyalty = intval($v->vdata['loyalty']);
				if($loyalty >= 100) continue;
				$n = self::useItem($tablets[0], min($tablets[0]['num'], 125 - $loyalty));
				$database->setVillageField($v->wref, 'loyalty', $loyalty + $n);
				$ctl->log($uid, $v->wref, 'hero_item', "used $n law tablet(s): loyalty $loyalty -> ".($loyalty + $n));
				break;
			}
		}
		$books = self::stock($uid, 13);
		if($books && self::misfit($npc, $hero) && NPCHuman::chance(0.5)) {   // book of wisdom: all points back
			self::useItem($books[0], 1);
			$database->query("UPDATE ".TB_PREFIX."hero SET points = ".(intval($hero['level']) * 4 + 4).", power = 0, offBonus = 0, defBonus = 0,".
				" product = 0, r0 = 1, r1 = 0, r2 = 0, r3 = 0, r4 = 0 WHERE uid = $uid");
			$ctl->log($uid, 0, 'hero_item', 'read a book of wisdom: the points no longer fit, sets them anew');
		}
	}

	// Wears the item it likes best in each slot (Inventory.php: the old one goes back to the bag)
	private static function equip(NPCController $ctl, $npc) {
		global $database;
		$uid = intval($npc['uid']);
		$face = $database->getHeroFace($uid);
		$skill = intval($npc['skill']) / 100;
		foreach(self::$slots as $btype => $slot) {
			$cands = self::stock($uid, $btype);
			if(empty($cands)) continue;
			$worn = $face[$slot] ? $database->getHeroItem($face[$slot]) : null;
			$current = $worn ? self::worth($npc, $worn['type']) : 0;
			$best = null;
			$bestWorth = $current * 1.05 + 1;
			foreach($cands as $it) {
				$w = self::worth($npc, $it['type']) * NPCHuman::noise(0.3 * (1 - $skill));
				if($w > $bestWorth) { $best = $it; $bestWorth = $w; }
			}
			if(!$best) continue;
			self::wear($uid, $face, $slot, $best, $worn);
			$face[$slot] = $best['id'];
			$ctl->log($uid, 0, 'hero_equip', 'wears item '.$best['type'].' ('.$slot.')'.($worn ? ' instead of item '.$worn['type'] : ''));
		}
	}

	private static function wear($uid, $face, $slot, $item, $worn) {
		global $database;
		self::useItem($item, 1);
		if($worn) {
			self::$inventory = null;
			$database->query("UPDATE ".TB_PREFIX."heroitems SET proc = 0, num = num + 1 WHERE id = ".intval($worn['id']));
			self::effect($uid, $worn['type'], -1);
		}
		self::effect($uid, $item['type'], 1);
		$database->modifyHeroFace($uid, $slot, intval($item['id']));
	}

	// Puts bandages or cages (btype 7/8/9) into the bag, as Inventory.php: all of the row
	public static function fillBag($uid, $btype) {
		global $database;
		$face = $database->getHeroFace($uid);
		if($face['bag']) {
			$in = $database->getHeroItem($face['bag']);
			if($in && $in['btype'] == $btype && $face['num'] > 0) return true;
		}
		$rows = self::stock($uid, $btype);
		if(empty($rows)) return false;
		$it = $rows[0];
		if($face['bag'] && $face['bag'] != $it['id']) {
			self::$inventory = null;
			$database->query("UPDATE ".TB_PREFIX."heroitems SET num = num + ".intval($face['num']).", proc = 0 WHERE id = ".intval($face['bag']));
			$face['num'] = 0;
		}
		$n = self::useItem($it, $it['num']);
		$database->modifyHeroFace($uid, 'num', $n + ($face['bag'] == $it['id'] ? intval($face['num']) : 0));
		$database->modifyHeroFace($uid, 'bag', intval($it['id']));
		return true;
	}

	// Effects of an equipment item (Inventory.php), column => amount
	public static function effects($type) {
		$t = intval($type);
		$tier = function($first, $values) use ($t) { return $values[($t - $first) % 3]; };
		if($t >= 1 && $t <= 15) {
			$cols = array('itemextraexpgain', 'itemautoregen', 'itemcpproduction', 'itemcavalrytrain', 'iteminfantrytrain');
			$vals = array(array(15, 20, 25), array(10, 15, 20), array(5, 10, 15), array(10, 15, 20), array(10, 15, 20));
			$g = intval(($t - 1) / 3);
			return array($cols[$g] => $vals[$g][($t - 1) % 3]);
		}
		if($t >= 16 && $t <= 60) return array('itemfs' => $tier(16, array(500, 1000, 1500)));
		if($t >= 61 && $t <= 63) return array('itemreturnmspeed' => $tier(61, array(30, 40, 50)));
		if($t >= 64 && $t <= 66) return array('itemaccountmspeed' => $tier(64, array(30, 40, 50)));
		if($t >= 67 && $t <= 69) return array('itemallymspeed' => $tier(67, array(15, 20, 25)));
		if($t >= 73 && $t <= 75) return array('itemrob' => $tier(73, array(10, 15, 20)));
		if($t >= 76 && $t <= 78) return array('itemfs' => $tier(76, array(500, 1000, 1500)));
		if($t >= 79 && $t <= 81) return array('itemvsnatars' => $tier(79, array(25, 50, 75)));
		if($t >= 82 && $t <= 84) return array('itemautoregen' => $tier(82, array(20, 30, 40)));
		if($t >= 85 && $t <= 87) return array('itemautoregen' => $tier(85, array(10, 15, 20)), 'itemextraresist' => $tier(85, array(4, 6, 8)));
		if($t >= 88 && $t <= 90) return array('itemfs' => $tier(88, array(500, 1000, 1500)));
		if($t >= 91 && $t <= 93) return array('itemfs' => $tier(91, array(250, 500, 750)), 'itemextraresist' => $tier(91, array(3, 4, 5)));
		if($t >= 94 && $t <= 96) return array('itemautoregen' => $tier(94, array(10, 15, 20)));
		// 97-99: attack speed boots - the engine has no column for them, no effect
		if($t >= 100 && $t <= 102) return array('itemspeed' => $tier(100, array(3, 4, 5)));
		if($t >= 103 && $t <= 105) return array('itemspeed' => $tier(103, array(14, 17, 20)));
		return array();
	}

	private static function effect($uid, $type, $sign) {
		global $database;
		$set = array();
		foreach(self::effects($type) as $col => $n) { $set[] = "$col = $col ".($sign > 0 ? '+' : '-')." ".intval($n); }
		if($set) $database->query("UPDATE ".TB_PREFIX."hero SET ".implode(', ', $set)." WHERE uid = ".intval($uid));
	}

	// What an equipment item is worth to this player (0 = useless); weapons of other tribes are useless
	public static function worth($npc, $type) {
		$t = intval($type);
		if($t >= 16 && $t <= 60) {
			$first = array(1 => 16, 2 => 46, 3 => 31);
			$f = isset($first[intval($npc['tribe'])]) ? $first[intval($npc['tribe'])] : 0;
			if($t < $f || $t > $f + 14) return 0;
		}
		$profile = self::profile($npc);
		$w = 0;
		foreach(self::effects($t) as $col => $n) { $w += $n * self::$worth[$col][$profile]; }
		return $w;
	}

	// ---- adventures ----

	// Adventures appear as on the adventure page (Generator::adventureTimes: one per game
	// day x s1_config.adventures, each open for five game days)
	private static function generate($uid, $hero, NPCVillage $home, $now) {
		global $database, $generator;
		list($aday, $tenday) = $generator->adventureTimes();
		$last = intval($hero['lastadv']);
		if($last > $now - $aday) return;   // nothing new since the last look
		if($last <= $now - $tenday) $last = $now - $tenday + $aday;
		$end = $last + $tenday;
		$c = $database->getCoor($home->wref);
		for($i = 0; $last <= $now - $aday && $i < 20; $i++) {
			for($try = 0; $try < 20; $try++) {
				$x = intval($c['x']) + mt_rand(-20, 20);
				$y = intval($c['y']) + mt_rand(-20, 20);
				if(abs($x) > WORLD_MAX || abs($y) > WORLD_MAX || ($x == $c['x'] && $y == $c['y'])) continue;
				$w = $database->query_return("SELECT id FROM ".TB_PREFIX."wdata WHERE x = $x AND y = $y");
				if(empty($w) || $database->getAdventure($uid, $w[0]['id'])) continue;
				$database->query("INSERT INTO ".TB_PREFIX."adventure (wref, uid, dif, time, end) VALUES (".intval($w[0]['id']).", $uid, ".
					(mt_rand(0, 10) > 8 ? 1 : 0).", $end, 0)");
				break;
			}
			$last += $aday;
			$end += $aday;
		}
		$database->query("UPDATE ".TB_PREFIX."hero SET lastadv = $last WHERE uid = $uid");
	}

	// Damage the hero takes on an adventure (sendAdventuresComplete)
	public static function adventureDamage($activateat, $hero, $hard) {
		$age = abs((time() - intval($activateat)) / (ROUNDLENGHT * 86400)) + 1;
		$fs = (100 + $hero['fsperpoint'] * $hero['power'] + $hero['itemfs']) * (100 + $hero['offBonus']) / 100;
		$dmg = ($hard ? 2000 : 1000) * $age * 3.007 / max(1, $fs);
		return max(0, $dmg - ($hero['extraresist'] * HEROATTRSPEED + $hero['itemextraresist'] * ITEMATTRSPEED));
	}

	private static function adventure(NPCController $ctl, $npc, $hero, NPCVillage $home, $now) {
		global $database, $generator;
		$uid = intval($npc['uid']);
		self::generate($uid, $hero, $home, $now);
		$open = $database->query_return("SELECT a.*, w.x, w.y FROM ".TB_PREFIX."adventure a JOIN ".TB_PREFIX."wdata w ON w.id = a.wref".
			" WHERE a.uid = $uid AND a.end = 0 AND a.time > $now");
		if(empty($open)) return false;
		$skill = intval($npc['skill']) / 100;
		$keep = self::keep($npc);
		$from = $database->getCoor($home->wref);
		$speed = $hero['speed'] + $hero['itemspeed'];
		$activateat = $database->getUserField($uid, 'activateat', 0);
		$scores = $info = array();
		foreach($open as $a) {
			// The player's guess of the damage (better with experience and skill)
			$guess = self::adventureDamage($activateat, $hero, $a['dif']) * NPCHuman::noise(0.15 + 0.3 * (1 - $skill));
			if($hero['health'] - $guess < $keep * ($a['dif'] ? 1.3 : 1)) continue;
			$seconds = $generator->procDistance($from, $a) / max(1, $speed) * 3600 / INCREASE_SPEED;   // about
			$scores[$a['id']] = ($a['dif'] ? 1.8 : 1) / (1 + $seconds / 3600);
			$info[$a['id']] = array('a' => $a, 'seconds' => $seconds, 'guess' => $guess);
		}
		if(empty($scores)) {
			$ctl->log($uid, $home->wref, 'hero_rest', 'hero too hurt for an adventure ('.round($hero['health']).' health), lets it heal', true);
			return false;
		}
		$pick = $info[NPCHuman::pickBest($scores, $npc)];
		$pick['seconds'] = $generator->procDistanceTime($from, $pick['a'], $speed, 1, $hero);
		// Units::Adventures(): the hero leaves the village, movement sort_type 9
		$database->query("UPDATE ".TB_PREFIX."units SET hero = 0 WHERE vref = ".$home->wref." AND hero >= 1");
		if(mysql_affected_rows($database->connection) != 1) return false;
		$database->addMovement(9, $home->wref, intval($pick['a']['wref']), 0, 0, time() + $pick['seconds']);
		$ctl->log($uid, $home->wref, 'hero_adventure', ($pick['a']['dif'] ? 'hard' : 'normal').' adventure at '.$pick['a']['x'].'|'.$pick['a']['y'].
			', '.$pick['seconds'].'s away, health '.round($hero['health']).', expects to lose about '.round($pick['guess']));
		return true;
	}

	// ---- raids ----

	// The hero rides along on a raid from its home village when it is fit enough
	// for the expected losses and the player is in for it (profile, mood).
	// Changes $plan: hero, attack, speed/seconds. Returns true if it goes.
	public static function escort($npc, NPCVillage $v, &$plan) {
		global $database, $generator;
		if(intval($v->units['hero']) < 1) return false;
		$p = array('farmer' => 0, 'balanced' => 0.35, 'aggressor' => 0.65);
		$chance = (isset($p[$npc['profile']]) ? $p[$npc['profile']] : 0) * ($npc['mood'] == 'raid' ? 1.3 : 1);
		if(!NPCHuman::chance($chance)) return false;
		$hero = $database->getHero(intval($npc['uid']));
		if(!$hero || $hero['dead'] || $hero['wref'] != $v->wref) return false;
		$attack = $plan['attack'] + self::attack($hero);
		$loss = self::lossShare($plan['defence'], $attack);
		if($hero['health'] - 100 * $loss < self::keep($npc) + 10) return false;
		$plan['hero'] = true;
		$plan['attack'] = $attack;
		$speed = $hero['speed'] + $hero['itemspeed'];
		foreach($plan['units'] as $unit => $n) { $speed = min($speed, $GLOBALS['u'.$unit]['speed']); }
		$plan['seconds'] = $generator->procDistanceTime($database->getCoor($v->wref), $plan['target'], $speed, 1, $hero);
		self::fillBag(intval($npc['uid']), 8) || self::fillBag(intval($npc['uid']), 7);   // bandages for the wounded
		return true;
	}

	// The hero's own fighting strength in an attack (Battle.php)
	public static function attack($hero) {
		return $hero['power'] * $hero['fsperpoint'] + $hero['itemfs'];
	}

	// Share of the attackers lost (= hero health lost / 100): h/(1+h), h = (D/A)^1.5
	public static function lossShare($defence, $attack) {
		if($attack <= 0) return 1;
		$h = pow(max(0, $defence) / $attack, 1.5);
		return $h / (1 + $h);
	}

	// ---- helpers ----

	// Health the player keeps back on adventures and raids
	public static function keep($npc) {
		$c = isset(self::$caution[$npc['profile']]) ? self::$caution[$npc['profile']] : 0.5;
		$c = max(0, min(1, $c + (intval($npc['discipline']) - 50) / 250));
		return 15 + 40 * $c;
	}

	// Under this health the hero gets ointment
	private static function healAt($npc) {
		return 40 + 0.4 * intval($npc['discipline']);
	}

	// Shows the hero for defence (hide = 0) or keeps it out of fights at home
	private static function visibility($npc, $hero) {
		global $database;
		$show = $npc['profile'] != 'aggressor' && $hero['health'] >= 50 ? 0 : 1;
		if(intval($hero['hide']) != $show) {
			$database->query("UPDATE ".TB_PREFIX."hero SET hide = $show WHERE uid = ".intval($npc['uid']));
		}
	}

	// Culture points short for the next village it wants
	private static function needsCulture($npc, $villages) {
		global $database;
		$max = 1;
		foreach(explode(',', NPC_EXPAND_MAX) as $pair) {
			list($p, $n) = explode(':', $pair);
			if($p == $npc['profile']) $max = intval($n);
		}
		if($villages >= $max) return false;
		$table = $GLOBALS['cp'.CP];
		return isset($table[$villages + 1]) && $database->getUserField($npc['uid'], 'cp', 0) < $table[$villages + 1];
	}

	// The points no longer fit the player: most of them where its taste is low
	private static function misfit($npc, $hero) {
		$taste = self::$taste[self::profile($npc)];
		$total = $hero['power'] + $hero['offBonus'] + $hero['defBonus'] + $hero['product'];
		if($hero['level'] < 5 || $total < 10) return false;
		$bad = 0;
		foreach($taste as $attr => $t) { if($t <= 1) $bad += $hero[$attr]; }
		return $bad / $total > 0.4;
	}

	private static function profile($npc) {
		return isset(self::$taste[$npc['profile']]) ? $npc['profile'] : 'balanced';
	}

	private static function d($npc) {
		return intval($npc['diligence']) > 0 ? intval($npc['diligence']) / 100 : 0.5;
	}

	public static function state($uid) {
		global $database;
		$rows = $database->query_return("SELECT * FROM ".TB_PREFIX."npc_hero WHERE uid = ".intval($uid));
		if(!empty($rows)) return $rows[0];
		$database->query("INSERT IGNORE INTO ".TB_PREFIX."npc_hero (uid) VALUES (".intval($uid).")");
		return array('uid' => $uid, 'items_seen' => 0, 'oases' => 0, 'auction_at' => 0);
	}

	public static function saveState($uid, $values) {
		global $database;
		$set = array();
		foreach($values as $k => $v) { $set[] = "`$k` = ".intval($v); }
		$database->query("UPDATE ".TB_PREFIX."npc_hero SET ".implode(', ', $set)." WHERE uid = ".intval($uid));
	}
}
