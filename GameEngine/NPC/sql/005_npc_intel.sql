-- NPC knowledge, raid outcomes and in-game scheduling (phase 7).

-- What an NPC knows about a target - only from its own raids (later scouting),
-- never read from the target's tables directly.
CREATE TABLE IF NOT EXISTS `%PREFIX%npc_intel` (
  `uid` int(11) unsigned NOT NULL,                      -- NPC that knows it
  `wref` int(11) unsigned NOT NULL,                     -- target village
  `defence` float NOT NULL DEFAULT 0,                   -- estimated defence (NPC attack units)
  `defence_known` tinyint(1) unsigned NOT NULL DEFAULT 0, -- 0 = never fought, 2 = lower bound only (everyone died)
  `loot` int(11) unsigned NOT NULL DEFAULT 0,           -- loot expected next time
  `raids` int(11) unsigned NOT NULL DEFAULT 0,
  `losses` int(11) unsigned NOT NULL DEFAULT 0,         -- units lost there in total
  `source` varchar(10) NOT NULL DEFAULT 'raid',         -- raid | scout
  `updated` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`uid`, `wref`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- Raid outcome, filled once the battle has been fought
ALTER TABLE `%PREFIX%npc_raid`
  ADD COLUMN IF NOT EXISTS `attack` float NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `evaluated` tinyint(1) unsigned NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `sent_units` int(11) unsigned NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `lost_units` int(11) unsigned NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `loot` int(11) unsigned NOT NULL DEFAULT 0;

-- Each NPC's own clock: everything up to sim_at has been played (catch-up)
ALTER TABLE `%PREFIX%npc`
  ADD COLUMN IF NOT EXISTS `sim_at` int(11) unsigned NOT NULL DEFAULT 0;

-- Small key/value store: schema version, last tick, last cleanup
CREATE TABLE IF NOT EXISTS `%PREFIX%npc_meta` (
  `k` varchar(30) NOT NULL,
  `v` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`k`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;
