<?php
/*
 * Trade between an NPC's own villages: a village that is running full sends
 * its surplus with merchants to a village that is short - new colonies first.
 * Like a player it only bothers sometimes (diligence, mood), always when a
 * storage is about to overflow, and never for a handful of resources
 * (less than NPC_TRADE_MIN_LOAD of a merchant's load or of the receiver's storage).
 *
 *   surplus  per resource above NPC_TRADE_DONOR_FILL of the storage, down to
 *            NPC_TRADE_DONOR_KEEP (resources kept back for settlers stay)
 *   need     per resource up to NPC_TRADE_TARGET_FILL of the receiver's
 *            storage, minus what merchants are already bringing
 *   limits   free merchants (marketplace level, in use until they are back),
 *            their load (tribe, SPEED, storage multiplier, trade office),
 *            trips longer than NPC_TRADE_MAX_HOURS are not worth it
 * One shipment per donor and session, to the receiver that gains most per hour
 * of travel. The engine delivers it (marketComplete) and brings the merchants back.
 * Every village of a multi-village NPC gets a marketplace into its plan.
 */
class NPCMarket {

	private static $res = array('wood', 'clay', 'iron', 'crop');

	public static function turn(NPCController $ctl, $npc, $villages) {
		global $database, $generator;
		if(count($villages) < 2) return;
		$need = $surplus = array();
		foreach($villages as $v) {
			$need[$v->wref] = self::need($v);
			$surplus[$v->wref] = self::surplus($v);
		}
		// Bother at all? A full storage always gets attention
		$overflow = false;
		foreach($villages as $v) {
			foreach(self::$res as $r) {
				if($v->stock($r) >= NPC_OVERFLOW * self::capacity($v, $r)) $overflow = true;
			}
		}
		$d = intval($npc['diligence']) > 0 ? intval($npc['diligence']) / 100 : 0.5;
		if(!$overflow && !NPCHuman::chance((0.3 + 0.5 * $d) * ($npc['mood'] == 'save' ? 0.6 : 1))) return;

		foreach($villages as $from) {
			if(array_sum($surplus[$from->wref]) <= 0) continue;
			$merchants = self::freeMerchants($from);
			if($merchants < 1) continue;
			$load = self::merchantLoad($from);
			$best = null;
			foreach($villages as $to) {
				if($to->wref == $from->wref || array_sum($need[$to->wref]) <= 0) continue;
				$seconds = $generator->procDistanceTime($database->getCoor($from->wref), $database->getCoor($to->wref), $from->tribe, 0);
				if($seconds > NPC_TRADE_MAX_HOURS * 3600 / SPEED) continue;
				$ship = array();
				foreach(self::$res as $r) { $ship[$r] = min($surplus[$from->wref][$r], $need[$to->wref][$r]); }
				$ship = self::fit($ship, $merchants * $load);
				$total = array_sum($ship);
				// Worth the trip: a good part of a merchant's load or of what the receiver can store
				if($total < NPC_TRADE_MIN_LOAD * min($load, self::capacity($to, 'wood'))) continue;
				// Colonies first; more per hour of travel is better
				$score = $total / max(60, $seconds) * (self::isColony($npc, $to) ? 2 : 1);
				if(!$best || $score > $best['score']) {
					$best = array('to' => $to, 'ship' => $ship, 'seconds' => $seconds, 'score' => $score);
				}
			}
			if(!$best) continue;
			$used = (int)ceil(array_sum($best['ship']) / $load);
			if(!NPCActions::sendResources($from, $best['to']->wref, $best['ship'], $used, $best['seconds'])) continue;
			NPCExpansion::supplied($npc['uid'], $best['to']->wref);
			foreach(self::$res as $r) {
				$surplus[$from->wref][$r] -= $best['ship'][$r];
				$need[$best['to']->wref][$r] -= $best['ship'][$r];
			}
			$s = $best['ship'];
			$ctl->log($npc['uid'], $from->wref, 'trade', round($s['wood']).'/'.round($s['clay']).'/'.round($s['iron']).'/'.
				round($s['crop']).' to '.$best['to']->vdata['name'].' with '.$used.' merchant(s), arrives in '.$best['seconds'].'s'.
				($overflow ? ' (storage running full)' : ''));
		}
	}

	// Free merchants: marketplace level minus those on the way (Market::merchantAvail)
	public static function freeMerchants(NPCVillage $v) {
		global $database;
		$level = $v->typeLevel(17);
		if($level < 1) return 0;
		return max(0, intval($GLOBALS['bid17'][$level]['attri']) - intval($database->totalMerchantUsed($v->wref)));
	}

	// What one merchant carries (Market::loadMarket)
	public static function merchantLoad(NPCVillage $v) {
		$load = ($v->tribe == 1 ? 500 : ($v->tribe == 2 ? 1000 : 750)) * SPEED;
		if(STORAGE_MULTIPLIER > 0) $load *= STORAGE_MULTIPLIER;
		if($v->typeLevel(28) > 0) $load *= $GLOBALS['bid28'][$v->typeLevel(28)]['attri'] / 100;
		return $load;
	}

	private static function capacity(NPCVillage $v, $r) {
		return max(1, floatval($r == 'crop' ? $v->vdata['maxcrop'] : $v->vdata['maxstore']));
	}

	private static function surplus(NPCVillage $v) {
		$out = array();
		foreach(self::$res as $r) {
			$cap = self::capacity($v, $r);
			$give = $v->stock($r) >= NPC_TRADE_DONOR_FILL * $cap ? $v->stock($r) - NPC_TRADE_DONOR_KEEP * $cap : 0;
			$out[$r] = max(0, min($give, $v->spendable($r)));
		}
		return $out;
	}

	private static function need(NPCVillage $v) {
		global $database;
		$coming = array_fill_keys(self::$res, 0);
		foreach($database->getMovement(0, $v->wref, 1) as $m) {
			foreach(self::$res as $r) { $coming[$r] += intval($m[$r]); }
		}
		$out = array();
		foreach(self::$res as $r) {
			$out[$r] = max(0, NPC_TRADE_TARGET_FILL * self::capacity($v, $r) - $v->stock($r) - $coming[$r]);
		}
		return $out;
	}

	// Scales a shipment down to what the merchants carry
	private static function fit($ship, $max) {
		$total = array_sum($ship);
		if($total <= $max) return $ship;
		foreach($ship as $r => $n) { $ship[$r] = floor($n * $max / $total); }
		return $ship;
	}

	private static function isColony($npc, NPCVillage $v) {
		return !$v->capital && intval($v->vdata['pop']) < 300;
	}
}
