<?php
// NPC system settings (global for all NPCs; per-NPC values live in s1_npc).

define('NPC_COUNT', 10);                    // npc_setup.php tops the NPC population up to this number
define('NPC_TRIBES', '1,2,3');              // tribes assigned round-robin to new NPCs
define('NPC_TICK_SECONDS', 60);             // how far next_action_at moves after each decision
define('NPC_MAX_SLEEP', 600);               // longest pause (s) of an NPC that is saving up or has both queues busy

// In-game triggering: every game request gives the NPCs a small time slice,
// the background ping (npc_ping.php, every 60 s while the game is open) a bigger one.
define('NPC_PAGE_BUDGET', 0.25);            // seconds of NPC work a normal page request may add
define('NPC_PAGE_MAX_STEPS', 20);
define('NPC_PING_BUDGET', 2.0);             // the built-in PHP server is single-threaded - keep it short
define('NPC_PING_MAX_STEPS', 300);
define('NPC_MAX_PER_RUN', 50);              // optional CLI runner (npc_runner.php)
define('NPC_RUN_TIME_BUDGET', 50);
define('NPC_AUTOMATION_LOCK_WAIT', 10);     // seconds the CLI runner waits for the game automation lock

// Catch-up: an NPC that missed its turns (game closed) replays the missed time
// event by event - builds, research and training start back in the past and
// finish at once, as far as its resources allow.
define('NPC_CATCHUP_THRESHOLD', 120);       // seconds an NPC must be overdue before catching up
define('NPC_CATCHUP_MAX', 7 * 86400);       // never replay more than this

// Housekeeping (NPC-only data; real players' rows are never touched)
define('NPC_CLEANUP_INTERVAL', 3600);       // seconds between cleanups
define('NPC_CLEANUP_KEEP_DAYS', 3);         // processed NPC-vs-NPC/farm movements, attacks, raids
define('NPC_REPORT_KEEP_HOURS', 24);        // NPCs' own reports (nobody reads them)
define('NPC_LOG_KEEP_DAYS', 7);             // s1_npc_log

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
define('NPC_RAID_PROBE_LOOT', 300);         // assumed loot of a target the NPC knows nothing about (sends a small probe raid)
define('NPC_INTEL_MAX_AGE', 86400);         // game seconds after which raid intel counts as stale (probe again)
