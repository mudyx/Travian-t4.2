<?php
/*
 * Background ping from the game page (Templates/footer.tpl, every 60 s while the
 * game is open): runs the game automation and an NPC tick with a bigger budget
 * than a normal page request, so NPCs keep playing - and catch up after the
 * game was closed - without any external runner.
 * Only for logged-in players: Session.php sends everyone else to login.php.
 */
define('NPC_PING', true);               // Automation.php uses NPC_PING_BUDGET / NPC_PING_MAX_STEPS
include("GameEngine/Session.php");      // runs Automation.php -> runAutomation() + npc_tick()

header('Content-Type: application/json');
header('Cache-Control: no-store');
echo '{"ok":1}';
