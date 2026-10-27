<?php
// NPC system settings (global for all NPCs; per-NPC values live in s1_npc).

define('NPC_COUNT', 10);                    // npc_setup.php tops the NPC population up to this number
define('NPC_TRIBES', '1,2,3');              // tribes assigned round-robin to new NPCs
define('NPC_TICK_SECONDS', 60);             // how far next_action_at moves after each decision
define('NPC_MAX_PER_RUN', 50);              // NPCs processed per runner start (keeps one run under a minute)
define('NPC_RUN_TIME_BUDGET', 50);          // seconds; stop picking new NPCs after this
define('NPC_AUTOMATION_LOCK_WAIT', 10);     // seconds the runner waits for the game automation lock
define('NPC_LOG_KEEP_DAYS', 7);             // older s1_npc_log rows are pruned by the runner
define('NPC_MAX_SLEEP', 600);               // longest pause (s) of an NPC that is saving up or has both queues busy

// Economy
define('NPC_STORAGE_HOURS', 3);             // warehouse/granary should hold at least this many hours of production

// Attack targets (used from the attack phase on)
define('NPC_ATTACK_REAL_PLAYERS', false);   // false = NPCs raid only farms and other NPCs
