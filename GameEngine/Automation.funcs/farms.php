<?php
/**********************************************
/ All Of the Copy Rights Of The Script Is Reserved For mersad_mr@att.net
/	You may have made some changes but You Have No Right To remove This Copy Right!
/	For Debug And Support Just Contact Me : mersad_mr@att.net
/	Mobile: 09127679667  / Yahoo ID : mersad_mr@att.net
/
*/

/////////////// THE JOB
$time = time()-30; // delay for refreshing the farms!
// lastupdate is UNSIGNED: "$time - lastupdate" fails (out of range) as soon as any
// village was updated in the last 30 s, so compare lastupdate against $time - interval.
$q = "SELECT * FROM ".TB_PREFIX ."vdata WHERE (woodp=0 AND clayp=0 AND ironp=0 AND cropp=0 AND name = 'Farm') OR ".
	"(woodp<>0 AND lastupdate <= ".$time." - 3600/woodp AND name = 'Farm') OR ".
	"(clayp<>0 AND lastupdate <= ".$time." - 3600/clayp AND name = 'Farm') OR ".
	"(ironp<>0 AND lastupdate <= ".$time." - 3600/ironp AND name = 'Farm') OR ".
	"(cropp<>0 AND lastupdate <= ".$time." - 3600/ABS(cropp) AND name = 'Farm')";
$list = $database->query_return2($q);
if($list && mysql_num_rows($list) > 0) {
	//echo "Farming....";
	while($farm = mysql_fetch_array($list)) {
		updateWrefResource($farm['wref']);
		//echo $farm['wref'].", ";
	}
}
?>
