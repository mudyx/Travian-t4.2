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

// Army (shares of income per profile are in NPCMilitary::$armyShare)
define('NPC_ARMY_BUDGET_CAP_HOURS', 6);     // the army budget never holds more than this many hours of its share
define('NPC_TRAIN_QUEUE_SECONDS', 1800);    // don't queue more training than this per building

// Raids (attack_type 4). Times are game seconds at speed 1 and get divided by SPEED.
define('NPC_ATTACK_REAL_PLAYERS', false);   // false = NPCs raid only farms and other NPCs
define('NPC_RAID_PROFILES', 'balanced,aggressor'); // farmers never raid
define('NPC_RAID_INTERVAL', 3600);          // base pause between raids of one NPC; x (1.5 - aggression/100)
define('NPC_RAID_TARGET_COOLDOWN', 7200);   // the same NPC doesn't hit the same target again before this
define('NPC_RAID_MAX_ACTIVE', 2);           // raids of one NPC on the way at the same time
define('NPC_RAID_RADIUS', 25);              // fields
define('NPC_RAID_HOME_RESERVE', 0.3);       // share of offensive units that always stays home (defensive units never leave)
define('NPC_RAID_MIN_UNITS', 5);            // smaller raids are not worth it
define('NPC_RAID_MIN_ADVANTAGE', 1.5);      // attack must be this many times the target's known defence
