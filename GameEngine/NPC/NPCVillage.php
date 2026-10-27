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

	public static function load($wref, $tribe) {
		global $database;
		updateWrefResource($wref);   // stock in vdata is only current after this
		$v = new NPCVillage;
		$v->wref = intval($wref);
		$v->tribe = intval($tribe);
		$v->vdata = $database->getVillage($wref);
		$v->fdata = $database->getResourceLevel($wref);
		$v->jobs = $database->getJobs($wref);
		$v->capital = intval($v->vdata['capital']);
		return $v;
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
	public function prod($res)  { return floatval($this->vdata[$res.'p']); }    // per hour, cropp is net of upkeep+pop

	// Crop left per hour after troops, population and the population of queued jobs.
	// Stricter than Building::canBuild(), which adds the troop upkeep back and so
	// ignores the army - an NPC must never starve its own troops.
	public function freeCrop() {
		$soonPop = 0;
		foreach($this->jobs as $job) {
			$data = $GLOBALS['bid'.$job['type']];
			if(isset($data[$job['level']]['pop'])) $soonPop += $data[$job['level']]['pop'];
		}
		return $this->prod('crop') - $soonPop;
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
