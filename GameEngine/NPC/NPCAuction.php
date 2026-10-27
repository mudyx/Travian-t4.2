<?php
/*
 * The hero auction for NPCs, with the rules of hero_auction.php:
 *   sell  at most MAXSELL open sales; Database::addAuction() sets the start
 *         price; auctionComplete() pays the seller and hands over the item
 *   bid   the bid is the player's top price; the silver is held back at once,
 *         a player who is outbid gets it back; the price rises to one over
 *         the second highest top price (like the page)
 * What a player sells: worn-out spare equipment (not better than what the hero
 * wears, or useless for its way of playing, weapons of other tribes) and
 * consumables beyond what it keeps. What it buys: equipment that beats its
 * own, ointments when the hero is often hurt, cages when it hunts oases -
 * at a price it guesses, never all of its silver.
 */
class NPCAuction {

	// Consumables a player keeps (btype => amount); the rest may be sold
	private static $keep = array(7 => 60, 8 => 40, 9 => 40, 11 => 150, 12 => 2, 13 => 1, 14 => 40, 15 => 3);

	// Silver per point of NPCHero::worth() a player would pay for better equipment
	const SILVER_PER_WORTH = 6;

	public static function turn(NPCController $ctl, $npc) {
		self::sell($ctl, $npc);
		self::bid($ctl, $npc);
	}

	private static function sell(NPCController $ctl, $npc) {
		global $database;
		$uid = intval($npc['uid']);
		$max = defined('MAXSELL') ? intval(MAXSELL) : 5;
		$open = $database->query_return("SELECT COUNT(*) n FROM ".TB_PREFIX."auction WHERE finish = 0 AND owner = $uid");
		$room = $max - intval($open[0]['n']);
		if($room <= 0) return;
		$face = $database->getHeroFace($uid);
		$keep = self::$keep;
		if($npc['profile'] == 'aggressor') $keep[9] = 0;   // no oasis hunting without a mansion plan
		$sold = 0;
		foreach($database->query_return("SELECT * FROM ".TB_PREFIX."heroitems WHERE uid = $uid AND num > 0 AND proc = 0 ORDER BY RAND()") as $it) {
			if($sold >= min($room, 2)) break;
			$btype = intval($it['btype']);
			$amount = 0;
			if(isset(NPCHero::$slots[$btype])) {
				$slot = NPCHero::$slots[$btype];
				$worn = $face[$slot] ? $database->getHeroItem($face[$slot]) : null;
				$w = NPCHero::worth($npc, $it['type']);
				// A spare that is no better than what is worn, or of no use to this player
				if($w < 3 || ($worn && $w <= NPCHero::worth($npc, $worn['type']))) $amount = intval($it['num']);
			} elseif(isset($keep[$btype])) {
				$amount = intval($it['num']) - $keep[$btype];
			}
			if($amount <= 0 || !NPCHuman::chance(0.7)) continue;
			if($database->addAuction($uid, intval($it['id']), $btype, intval($it['type']), $amount)) {
				$sold++;
				$ctl->log($uid, 0, 'auction_sell', "puts up $amount x item ".$it['type'].' (kind '.$btype.')');
			}
		}
	}

	// At most one bid per session, on the open sale it wants most
	private static function bid(NPCController $ctl, $npc) {
		global $database;
		$uid = intval($npc['uid']);
		$silver = intval($database->getUserField($uid, 'silver', 0));
		if($silver < 10) return;
		$face = $database->getHeroFace($uid);
		$hero = $database->getHero($uid);
		$best = null;
		foreach($database->query_return("SELECT * FROM ".TB_PREFIX."auction WHERE finish = 0 AND time > ".(time() + 60).
			" AND owner <> $uid AND uid <> $uid ORDER BY time LIMIT 40") as $a) {
			$value = self::value($npc, $hero, $face, $a);
			if($value <= 0) continue;
			$top = floor(min($value * NPCHuman::noise(0.2), 0.7 * $silver));
			if($top <= $a['silver']) continue;
			if(!$best || $value - $a['silver'] > $best['gain']) $best = array('a' => $a, 'top' => $top, 'gain' => $value - $a['silver']);
		}
		if(!$best) return;
		$a = $best['a'];
		$won = self::place($uid, $a, $best['top']);
		$ctl->log($uid, 0, 'auction_bid', 'bids up to '.$best['top'].' silver on '.$a['num'].' x item '.$a['type'].' (now '.$a['silver'].')'.
			($won ? '' : ', but someone offers more'));
	}

	// What an item on sale is worth to this player, in silver (0 = not interested)
	private static function value($npc, $hero, $face, $a) {
		global $database;
		$btype = intval($a['btype']);
		$n = intval($a['num']);
		if(isset(NPCHero::$slots[$btype])) {
			$slot = NPCHero::$slots[$btype];
			$worn = $face[$slot] ? $database->getHeroItem($face[$slot]) : null;
			$gain = NPCHero::worth($npc, $a['type']) - ($worn ? NPCHero::worth($npc, $worn['type']) : 0);
			return $gain > 2 ? $gain * self::SILVER_PER_WORTH : 0;
		}
		switch($btype) {
			case 11: return $hero && $hero['health'] < 70 ? $n * 1.5 : 0;               // ointments
			case 10: return $n * 1.2;                                                     // XP scrolls
			case 9:  return $npc['profile'] != 'aggressor' ? $n * 2 : 0;                 // cages
			case 8:  case 7: return $npc['profile'] == 'aggressor' ? $n * 1 : 0;          // bandages
			case 12: return $hero && $hero['dead'] ? 60 : 15;                            // bucket
		}
		return 0;
	}

	// hero_auction.php's bid: returns true when the NPC is the top bidder afterwards
	private static function place($uid, $a, $top) {
		global $database;
		$a = $database->getBidData(intval($a['id']));
		if(!$a || $a['finish'] || $a['time'] < time() || $top <= $a['silver']) return false;
		$newtime = time() + max(min(300, AUCTIONTIME), $a['time'] - time());
		if($a['uid'] == 0) {
			$database->addBid($a['id'], $uid, $a['silver'] + 1, $top, $newtime);
		} elseif($top <= $a['maxsilver']) {
			// The leader's top price is higher: the price rises, the leader stays
			$database->addBid($a['id'], $a['uid'], $top, $a['maxsilver'], $newtime);
			return false;
		} else {
			$database->setSilver($a['uid'], $a['maxsilver'], 1);
			$database->query("UPDATE ".TB_PREFIX."users SET bisilver = bisilver - ".intval($a['maxsilver'])." WHERE id = ".intval($a['uid']));
			$database->addBid($a['id'], $uid, $a['maxsilver'] + 1, $top, $newtime);
		}
		$database->setSilver($uid, $top, 0);
		$database->query("UPDATE ".TB_PREFIX."users SET bisilver = bisilver + ".intval($top)." WHERE id = ".intval($uid));
		return true;
	}
}
