<div id="footer">
	<div id="mfoot">
		<center>
        برنامه نویس, دیباگر و پشتیبان فنی اسکریپت: <a style="color:#FFF;" href="http://www.20script.ir/">20script.ir</a><br>
        <br>
		مدیریت و فروش: <a style="color:#FFF;" href="http://www.20script.ir/">20script.ir</a><br><br>
        تیم مرجع بازی های آنلاین, T4DL<br><br>
		<p style="direction:ltr;">Copyright © <?=date("Y")?> mersad_mr@att.net All rights reserved.</p>
        </center>
		<div class="clear"></div>
	</div>
</div>
<script type="text/javascript">
// Background ping: keeps the game automation and the NPC players running
// (and catching up) while the game is open - no external runner needed.
(function() {
	function npcPing() {
		var r = new XMLHttpRequest();
		r.open('GET', 'npc_ping.php?t=' + new Date().getTime(), true);
		r.send();
	}
	setInterval(npcPing, 60000);
})();
</script>