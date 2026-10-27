<?php
/**********************************************
/ All Of the Copy Rights Of The Script Is Reserved For mersad_mr@att.net
/	You may have made some changes but You Have No Right To remove This Copy Right!
/	For Debug And Support Just Contact Me : mersad_mr@att.net
/	Mobile: 09127679667  / Yahoo ID : mersad_mr@att.net
/
*/

include("Automation.funcs/auctionComplete.php");
include("Automation.funcs/buildComplete.php");
include("Automation.funcs/celebrationComplete.php");
include("Automation.funcs/clearAndDeleting.php");
include("Automation.funcs/culturePoints.php");
include("Automation.funcs/demolitionComplete.php");
include("Automation.funcs/loyaltyRegeneration.php");
include("Automation.funcs/marketComplete.php");
include("Automation.funcs/MasterBuilder.php");
include("Automation.funcs/natarsJobs.php");
include("Automation.funcs/researchComplete.php");
include("Automation.funcs/returnunitsComplete.php");
include("Automation.funcs/sendAdventuresComplete.php");
include("Automation.funcs/sendreinfunitsComplete.php");
include("Automation.funcs/sendSettlersComplete.php");
include("Automation.funcs/sendunitsComplete.php");
include("Automation.funcs/trainingComplete.php");
include("Automation.funcs/updateHero.php");
include("Automation.funcs/zeroPopedVillages.php");
include("Automation.funcs/medals.php");
include("Automation.funcs/updateResource.php");
// Added new 
include("Smart-medals.php");
include_once("Technology.php");

include("Automation.funcs/func/getPop.php");
include("Automation.funcs/func/getTypeLevel.php");
include("Automation.funcs/farms.php");
include("Automation.funcs/herocheck.php");
function autoUpdate($which,$time){
	global $database;
	$database->query("UPDATE `".TB_PREFIX."automation` SET ".$which." = ".$time."");
}

// Runs every due automation job. A MySQL named lock makes sure only one process
// (a page request or the CLI runner) processes builds/movements at a time;
// without it two overlapping requests could resolve the same attack twice.
// $lockWait = seconds to wait for the lock; 0 = skip this run if someone else holds it.
function runAutomation($lockWait=0){
	global $database;
	if($database->hasWinner()) {
		return false;
	}
	$lockName = TB_PREFIX."automation";
	$lock = mysql_fetch_row($database->query("SELECT GET_LOCK('".$lockName."',".intval($lockWait).")"));
	if(!$lock || $lock[0] != 1) {
		return false;
	}
	try {
		@$automation = mysql_fetch_array($database->query("SELECT * FROM `".TB_PREFIX."automation` WHERE 1"));

		if($automation['auto1']<time()){	auctionComplete();	autoUpdate("auto1",(time()+1));}
		if($automation['auto2']<time()){	buildComplete();	autoUpdate("auto2",(time()+1));}
		if($automation['auto3']<time()){	celebrationComplete();	autoUpdate("auto3",(time()+1));}
		if($automation['auto4']<time()){	clearAndDeleting();	autoUpdate("auto4",(time()+1));}
		if($automation['auto5']<time()){	culturePoints();	autoUpdate("auto5",(time()+1));}
		if($automation['auto6']<time()){	demolitionComplete();	autoUpdate("auto6",(time()+1));}
		if($automation['auto7']<time()){	loyaltyRegeneration();	autoUpdate("auto7",(time()+1));}
		if($automation['auto8']<time()){	marketComplete();	autoUpdate("auto8",(time()+1));}
		if($automation['auto9']<time()){	MasterBuilder();	autoUpdate("auto9",(time()+1));}
		if($automation['auto10']<time()){	natarsJobs();	autoUpdate("auto10",(time()+1));}
		if($automation['auto11']<time()){	researchComplete();	autoUpdate("auto11",(time()+1));}
		if($automation['auto12']<time()){	returnunitsComplete();	autoUpdate("auto12",(time()+1));}
		if($automation['auto13']<time()){	sendAdventuresComplete();	autoUpdate("auto13",(time()+1));}
		if($automation['auto14']<time()){	sendreinfunitsComplete();	autoUpdate("auto14",(time()+1));}
		if($automation['auto15']<time()){	sendSettlersComplete();	autoUpdate("auto15",(time()+1));}
		if($automation['auto16']<time()){	sendunitsComplete();	autoUpdate("auto16",(time()+2));}
		if($automation['auto17']<time()){	trainingComplete();	autoUpdate("auto17",(time()+1));}
		if($automation['auto18']<time()){	updateHero();	autoUpdate("auto18",(time()+1));}
		if($automation['auto19']<time()){	zeroPopedVillages();	autoUpdate("auto19",(time()+1));}
		if($automation['auto20']<time()){	/*medals();*/	autoUpdate("auto20",(time()+1));}
	} catch(Exception $e) {
		// PHP 5.4 has no "finally" - release the lock, then let the error surface
		$database->query("SELECT RELEASE_LOCK('".$lockName."')");
		throw $e;
	}
	$database->query("SELECT RELEASE_LOCK('".$lockName."')");
	return true;
}

// Page requests run automation right away (as before); CLI scripts define
// AUTOMATION_MANUAL and call runAutomation() themselves.
if(!defined('AUTOMATION_MANUAL')) {
	runAutomation(0);
}

?>
