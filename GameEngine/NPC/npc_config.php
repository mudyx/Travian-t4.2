<?php
// NPC system settings (global for all NPCs; per-NPC values live in s1_npc).

define('NPC_COUNT', 10);                    // npc_setup.php tops the NPC population up to this number
define('NPC_TRIBES', '1,2,3');              // tribes assigned round-robin to new NPCs
define('NPC_TICK_SECONDS', 60);             // how far next_action_at moves after each decision
define('NPC_MAX_SLEEP', 600);               // longest pause (s) of an NPC that is saving up or has both queues busy (then NPCHuman::nextVisit)

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
define('NPC_LOG_KEEP_DAYS', 3);             // s1_npc_log (hundreds of NPCs write a lot) ...
define('NPC_LOG_MILESTONE_DAYS', 60);       // ... except milestones (joined, founded, oasis, quit, revenge, errors)
define('NPC_LOG_MILESTONES', 'created,founded,settle,settle_failed,oasis_taken,absent,back,quit,grudge,revenge,hero_revive,colony_alone,colony_supplied,error');
define('NPC_INTEL_KEEP_DAYS', 7);           // raid intel not refreshed for this long is forgotten (real days)
define('NPC_GRUDGE_KEEP_DAYS', 3);          // a grudge without revenge fades after this many real days ...
define('NPC_GRUDGE_DAY_PER_HIT', 1);        // ... + this many per hit taken from that village ...
define('NPC_GRUDGE_MAX_DAYS', 14);          // ... but never longer
define('NPC_CLEANUP_BATCH', 5000);          // rows deleted per query (short table locks) ...
define('NPC_CLEANUP_SECONDS', 1.0);         // ... batches go on while the hourly cleanup has used less than this

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
define('NPC_RAID_RADIUS', 25);              // fields a player looks for raid targets at the round start (x 0.8..1.2 by aggression) ...
define('NPC_RAID_REACH_DAYS', 60);          // ... growing by the same again over this many game days (by mid-round people farm further out) ...
define('NPC_RAID_REACH_GROWTH', 1.4);       // ... growth capped here (1.4 = +140 %)
define('NPC_RAID_MIN_CHOICE', 3);           // fewer worthwhile targets in reach: looks further (x1.6, x2.5) ...
define('NPC_RAID_RADIUS_MAX', 70);          // ... but never beyond this
define('NPC_RAID_HOME_RESERVE', 0.3);       // share of offensive units that always stays home (defensive units never leave)
define('NPC_RAID_MIN_UNITS', 5);            // smaller raids are not worth it
define('NPC_RAID_MIN_ADVANTAGE', 1.5);      // attack must be this many times the target's known defence
define('NPC_RAID_PROBE_LOOT', 300);         // assumed loot of a target the NPC knows nothing about (sends a small probe raid)
define('NPC_INTEL_MAX_AGE', 86400);         // game seconds after which raid intel counts as stale (probe again)

// Human behaviour (NPCHuman). Rhythm times are real seconds - people take breaks
// and sleep in real time, whatever the game speed.
define('NPC_TIMEZONE', 'Europe/Prague');    // clock of the sleep window ('' = server clock, date.timezone)
define('NPC_SESSION_MIN', 2700);            // an online block lasts 45-150 min (x 0.5..1.5 by diligence)
define('NPC_SESSION_MAX', 9000);
define('NPC_BREAK_MIN', 600);               // then a break of 10-60 min (x 1.5..0.5 by diligence, x mood)
define('NPC_BREAK_MAX', 3600);
define('NPC_LOOK_MIN', 15);                 // while online it looks again 15-90 s after the moment it waited for ...
define('NPC_LOOK_MAX', 90);
define('NPC_OVERSHOOT', 0.5);               // ... plus up to 50 % of the wait (x 1.5..0.5 by diligence)
define('NPC_OVERLOOK', 0.15);               // chance per session to miss a free build queue (x (100-skill)/100)
define('NPC_OVERFLOW', 0.9);                // storage this full: spends on anything, saving or not
define('NPC_GOAL_PATIENCE', 7200);          // game seconds of saving for one build (x 0.5..1.5 by discipline), then grabs other things
define('NPC_MOOD_MIN', 86400);              // a mood (build / raid / save focus) lasts 1-3 days
define('NPC_MOOD_MAX', 3 * 86400);
define('NPC_SLEEP_FROM', 1290);             // bedtime between 21:30 (minute of the day) ...
define('NPC_SLEEP_SPREAD', 300);            // ... and 02:30; +-30 min more each night
define('NPC_SLEEP_MIN', 360);               // sleeps 6-8 h (minutes)
define('NPC_SLEEP_MAX', 480);

// Defence (NPCDefence), real seconds
define('NPC_THREAT_HORIZON', 3600);         // attacks landing later than this are dealt with in a later session
define('NPC_NOTICE_CHANCE', 0.75);          // chance per session to notice an incoming attack
define('NPC_REACTION_MIN', 60);             // time a person needs to react; less time left = too late
define('NPC_REACTION_MAX', 600);
define('NPC_SPEND_CHANCE', 0.6);            // spends the stock before the hit (x mood)
define('NPC_THREAT_PER_POP', 30);           // guessed attack per population of the attacking village (+-50 %)
define('NPC_REVENGE_CHANCE', 0.5);          // x aggression/50 x mood, +25 % per earlier hit by the same village
define('NPC_REVENGE_DELAY_MIN', 600);       // revenge is planned 10-60 min ahead
define('NPC_REVENGE_DELAY_MAX', 3600);
define('NPC_REVENGE_PATIENCE', 21600);      // a revenge not carried out by then is dropped

// World placement (NPCWorld): new NPCs sit on a Vogel spiral around the map
// centre - evenly spread, the occupied ring growing outwards like real sign-ups
define('NPC_SPAWN_STEP', 2.6);              // spiral density: radius = NATARS_MAX + 3 + step * sqrt(k) (400 NPCs reach ~r 77)
define('NPC_SPAWN_SPACING', 4);             // fields to the nearest other village
define('NPC_PLAYER_SPACING', 7);            // fields to the nearest real player's village

// Expansion (NPCExpansion): culture points -> residence 10 -> 3 settlers -> new village
define('NPC_EXPAND_MAX', 'farmer:6,balanced:4,aggressor:3');  // villages per NPC
define('NPC_EXPAND_FIELDS', 'farmer:4,balanced:5,aggressor:6'); // average field level before the first expansion
define('NPC_EXPAND_MIN_POP', 120);          // population of the village the settlers come from
define('NPC_EXPAND_CP_AHEAD', 43200);       // starts preparing when the culture points are this many real seconds away
define('NPC_EXPAND_RADIUS', 12);            // new villages within this many fields of the mother village ...
define('NPC_EXPAND_MIN_DIST', 3);           // ... but not closer than this
define('NPC_COLONY_ALONE_HOURS', 8);        // a young colony no merchants came to for this long (game hours) fends for itself ...
define('NPC_COLONY_SELF_FIELDS', 3);        // ... until its fields average this level (then its normal start plan)
define('NPC_COLONY_POP', 300);              // a colony counts as young below this population

// The world's population (NPCWorld::grow, part of the background NPC tick). Real
// time, like people. Rates are averages of random processes - nothing is filled up to a number.
define('NPC_MAX', 0);                       // server size the rates scale with; 0 = by map size (WORLD_MAX x 4, kept within 160..400)
define('NPC_JOIN_RUSH', 0.3);               // sign-ups (x NPC_MAX, on average) in the launch rush ...
define('NPC_JOIN_RUSH_HOURS', 10);          // ... fading with this time constant
define('NPC_JOIN_MAIN', 0.55);              // sign-ups in the main wave of the first days ...
define('NPC_JOIN_MAIN_HOURS', 72);          // ... fading with this time constant
define('NPC_JOIN_LATE', 0.03);              // late joiners per day for the rest of the round
define('NPC_JOIN_SPELLS', 0.8);             // adverts / word of mouth / silent spells starting per day
define('NPC_JOIN_ROOM', 1.5);               // a map with this x NPC_MAX active players attracts nobody any more
define('NPC_SPAWN_PER_STEP', 10);           // most accounts created per step (load); the rest wait for the next one
define('NPC_GROW_INTERVAL', 120);           // seconds between steps
define('NPC_LIFE_MAX_GAP', 604800);         // a world closed longer than this lives through only the last week
define('NPC_QUIT_RATE', 0.015);             // daily chance to stop playing (x 1.6 - diligence)
define('NPC_DROPOUT_RATE', 0.25);           // extra daily chance for newcomers who only try it (x (1 - diligence)^2) ...
define('NPC_DROPOUT_DAYS', 2);              // ... fading with this many days of age
define('NPC_ABSENCE_RATE', 0.04);           // daily chance to drop out for a while (x 1.5 - diligence)
define('NPC_ABSENCE_MEDIAN', 108000);       // typical absence (s); spread x0.5..x2, within ...
define('NPC_ABSENCE_MIN', 28800);           // ... a night and a half ...
define('NPC_ABSENCE_MAX', 604800);          // ... and a week
define('NPC_ABSENCE_NEVER', 0.2);           // chance an absent NPC never comes back (more after long absences / frustration)
define('NPC_FRUSTRATION_HALFLIFE', 172800); // frustration (0-100) halves in this time
define('NPC_FRUSTRATION_QUIT', 25);         // quitting chance x (1 + frustration / this)
define('NPC_FRUSTRATION_AWAY', 30);         // absence chance x (1 + frustration / this)
define('NPC_UPSET_HIT', 3);                 // frustration per hit taken (+2 for each repeat by the same village)
define('NPC_UPSET_RAID', 10);               // frustration for a raid that was wiped out (+ lost units / 4)
define('NPC_VILLAGE_CAP', 0);               // all NPC villages together; 0 = 3 x NPC_MAX (no expansion beyond)
define('NPC_LAG_THRESHOLD', 300);           // a due NPC this many seconds late while the game is open = the server can't keep up ...
define('NPC_LAG_PAUSE', 3600);              // ... then sign-ups wait this long

// Trade between own villages (NPCMarket): rich villages send surplus to hungry ones (colonies first)
define('NPC_TRADE_DONOR_FILL', 0.7);        // a resource this full (share of storage) can be given away ...
define('NPC_TRADE_DONOR_KEEP', 0.4);        // ... down to this share
define('NPC_TRADE_TARGET_FILL', 0.6);       // receivers are filled up to this share (incoming merchants count)
define('NPC_TRADE_MIN_LOAD', 0.5);          // a trip carries at least this share of a merchant's load or of the receiver's warehouse
define('NPC_TRADE_MAX_HOURS', 3);           // longest trip worth it (game hours, divided by SPEED)
