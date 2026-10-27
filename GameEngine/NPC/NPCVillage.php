<?php
/*
 * Snapshot of one NPC village for decision making: stock (refreshed through
 * the engine's lazy production update), field/building levels and the build queue.
 */
class NPCVillage {

	public $wref, $tribe, $capital;
	public $vdata = array();   // s1_vdata row
	public $fdata = array();   // s1_fdata row
	public $jobs = array();    // s1_bdata rows of this village
	public $units = array();   // s1_units row (troops at home)
	public $tech = array();    // s1_tdata row (t2..t49 = 1 when researched)
	public $research = array();// s1_research rows in progress
	public $training = array();// s1_training rows (queued units)
	public $reserve = array(); // resource => amount kept back (saving for settlers), set by NPCExpansion
	public $plan = array();    // plan steps before the profile plan (expansion, colony start), set by NPCExpansion

	// Once per step: the engine's lazy production update (~10 queries) + the rows
	public static function load($wref, $tribe) {
		updateWrefResource($wref);   // stock in vdata is only current after this
		$v = new NPCVillage;
		$v->wref = intval($wref);
		$v->tribe = intval($tribe);
		$v->refresh();
		return $v;
	}

	// After the NPC's own actions: re-read the rows only. Stock is still current
	// (actions just subtract from it), so no new production update is needed.
	public function refresh() {
		global $database;
		$this->vdata = $database->getVillage($this->wref);
		$this->fdata = $database->getResourceLevel($this->wref);
		$this->jobs = $database->getJobs($this->wref);
		$this->units = $database->getUnit($this->wref);
		$this->tech = $database->getTech($this->wref);
		$this->research = $database->getResearching($this->wref);
		$this->training = $database->getTraining($this->wref);
		$this->capital = intval($this->vdata['capital']);
	}

	// Units without research: the first unit of each tribe and the settler (Technology::trainUnit)
	public function isResearched($unit) {
		return ($unit % 10) <= 1 || (isset($this->tech['t'.$unit]) && $this->tech['t'.$unit] == 1);
	}

	public function isResearching() {
		foreach($this->research as $r) { if(substr($r['tech'], 0, 1) == 't') return true; }
		return false;
	}

	// Units at home plus units still in the training queue
	public function unitCount($unit) {
		$n = isset($this->units['u'.$unit]) ? intval($this->units['u'.$unit]) : 0;
		foreach($this->training as $t) { if($t['unit'] == $unit) $n += intval($t['amt']); }
		return $n;
	}

	public function isQueuedUnit($unit) {
		foreach($this->training as $t) { if($t['unit'] == $unit) return true; }
		return false;
	}

	public function level($field) { return intval($this->fdata['f'.$field]); }
	public function type($field)  { return intval($this->fdata['f'.$field.'t']); }

	// Highest level of a building type in the village (Building::getTypeLevel)
	public function typeLevel($tid) {
		$max = 0;
		for($i = 1; $i <= 40; $i++) {
			if($this->type($i) == $tid && $this->level($i) > $max) {
				$max = $this->level($i);
			}
		}
		return $max;
	}

	public function isQueuedType($tid) {
		foreach($this->jobs as $job) { if($job['type'] == $tid) return true; }
		return false;
	}

	public function isQueuedField($field) {
		foreach($this->jobs as $job) { if($job['field'] == $field) return true; }
		return false;
	}

	// Without Plus the engine allows one job on fields (1-18) and one on buildings (19+)
	public function queueBusy($fieldQueue) {
		foreach($this->jobs as $job) {
			if($job['loopcon'] == 0 && (($job['field'] <= 18) == $fieldQueue)) return true;
		}
		return false;
	}

	public function stock($res) { return floatval($this->vdata[$res]); }        // wood|clay|iron|crop
	public function spendable($res) { return $this->stock($res) - (isset($this->reserve[$res]) ? $this->reserve[$res] : 0); }
	public function prod($res)  { return floatval($this->vdata[$res.'p']); }    // per hour, cropp is net of upkeep+pop

	// Crop left per hour after troops, population, the population of queued
	// jobs and the upkeep of units still in the training queue.
	// Stricter than Building::canBuild(), which adds the troop upkeep back and so
	// ignores the army - an NPC must never starve its own troops.
	public function freeCrop() {
		$soon = 0;
		foreach($this->jobs as $job) {
			$data = $GLOBALS['bid'.$job['type']];
			if(isset($data[$job['level']]['pop'])) $soon += $data[$job['level']]['pop'];
		}
		foreach($this->training as $t) {
			$soon += intval($t['amt']) * intval($t['pop']);
		}
		return $this->prod('crop') - $soon;
	}

	// Income per hour used for the army budget (negative crop counts as 0)
	public function income() {
		return $this->prod('wood') + $this->prod('clay') + $this->prod('iron') + max(0, $this->prod('crop'));
	}

	public function avgFieldLevel() {
		$sum = 0;
		for($i = 1; $i <= 18; $i++) { $sum += $this->level($i); }
		return $sum / 18;
	}

	// Base production of one resource type from its fields only (no bonuses),
	// used to compare which resource the village is short of.
	public function fieldProd($type) {
		$sum = 0;
		$data = $GLOBALS['bid'.$type];
		for($i = 1; $i <= 18; $i++) {
			if($this->type($i) == $type) $sum += $data[$this->level($i)]['prod'];
		}
		return $sum;
	}
}
