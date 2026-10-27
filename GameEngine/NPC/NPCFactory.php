<?php
/*
 * Creates NPC players through the same database calls as a real sign-up:
 *   Account::Signup()        register, addHeroFace, addHero, clear act
 *   first.php + generateBase  village (spot: NPCWorld spawn spiral), fields, units, tech, hero
 * Differences from the web flow (on purpose):
 *   - the tribe is stored at register time, so addHero() gets that tribe's
 *     stats (the web flow registers with tribe 0 and picks it later)
 *   - no welcome message (nobody reads the NPC inbox)
 *   - random password: NPC accounts are never logged into
 */
class NPCFactory {

	public $error = '';

	// Returns the new uid, or false with $this->error set.
	public function create($name, $tribe, $profile, $aggression) {
		global $database, $generator;
		$this->error = '';

		if($database->checkExist($name, 0)) {
			$this->error = "username '$name' already exists";
			return false;
		}
		$email = strtolower($name).'@npc.local';
		$password = md5($generator->generateRandStr(32));

		// Account::Signup()
		$uid = $database->register($name, $password, $email, $tribe, 0, '', time());
		if(!$uid) {
			$this->error = "register failed: ".mysql_error($database->connection);
			return false;
		}
		$database->addHeroFace($uid, rand(0,3), rand(0,3), rand(0,4), rand(0,3), rand(0,3), rand(0,4), rand(0,3), rand(0,3), rand(0,4));
		$database->addHero($uid);
		$database->updateUserField($uid, "act", "", 1);

		// first.php -> Account::generateBase($sector, $uid, $name, 1), but the spot
		// comes from the NPC spawn spiral (even spread); generateBase if it is full
		$wid = NPCWorld::spawnSite();
		if($wid) {
			$coor = $database->getCoor($wid);
			$sector = NPCWorld::sector($coor['x'], $coor['y']);
		} else {
			$sector = rand(1,4);
			$wid = $database->generateBase($sector);
		}
		if(!$wid) {
			$this->error = "no free village slot in sector $sector";
			return false;
		}
		$database->setFieldTaken($wid);
		$database->addVillage($wid, $uid, $name, 1);
		$database->addResourceFields($wid, $database->getVillageType($wid));
		$database->addUnits($wid);
		$database->addTech($wid);
		$database->addABTech($wid);
		$database->updateUserField($uid, "access", USER, 1);
		if(B_LEVEL > 0) {
			$set = array();
			for($i=1; $i<=18; $i++) { $set[] = "`f".$i."`=".intval(B_LEVEL); }
			$database->query("UPDATE ".TB_PREFIX."fdata SET ".implode(',', $set)." WHERE vref=".$wid);
			recountPop($wid);
		}
		$database->updateUserField($uid, "location", $sector, 1);
		$database->updateUserField($uid, "settedup", "yes", 1);
		$database->modifyUnit($wid, 'hero', 1, 1);
		$database->modifyHero($uid, 0, 'wref', $wid, 0);

		// NPC bookkeeping; stagger the first decisions so NPCs don't all act in the same second
		$now = time();
		$database->query("INSERT INTO ".TB_PREFIX."npc (uid, profile, aggression, active, next_action_at, created) VALUES (".
			intval($uid).", '".mysql_real_escape_string($profile, $database->connection)."', ".intval($aggression).", 1, ".
			($now + rand(0, NPC_TICK_SECONDS - 1)).", ".$now.")");

		return $uid;
	}
}
