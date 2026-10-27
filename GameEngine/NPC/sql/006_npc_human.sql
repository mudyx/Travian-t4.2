-- Human behaviour and defence (phase 8).

-- Character (0 = not rolled yet), mood (focus for a few days), sleep window,
-- end of the current online block, last impact the NPC has looked at.
-- Zero/empty values are filled in by NPCHuman::init() on the NPC's next turn.
ALTER TABLE `%PREFIX%npc`
  ADD COLUMN IF NOT EXISTS `diligence` tinyint(3) unsigned NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `discipline` tinyint(3) unsigned NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `skill` tinyint(3) unsigned NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `online_until` int(11) unsigned NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `mood` varchar(10) NOT NULL DEFAULT '',            -- build | raid | save
  ADD COLUMN IF NOT EXISTS `mood_until` int(11) unsigned NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `sleep_start` smallint(5) unsigned NOT NULL DEFAULT 0, -- minute of the day
  ADD COLUMN IF NOT EXISTS `sleep_len` smallint(5) unsigned NOT NULL DEFAULT 0,   -- minutes
  ADD COLUMN IF NOT EXISTS `hits_seen_at` int(11) unsigned NOT NULL DEFAULT 0;

-- The build a village is being saved for (one per village)
CREATE TABLE IF NOT EXISTS `%PREFIX%npc_goal` (
  `wref` int(11) unsigned NOT NULL,
  `uid` int(11) unsigned NOT NULL,
  `field` tinyint(3) unsigned NOT NULL,
  `tid` tinyint(3) unsigned NOT NULL,
  `level` tinyint(3) unsigned NOT NULL,
  `since` int(11) unsigned NOT NULL,
  PRIMARY KEY (`wref`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- Incoming attacks the NPC has noticed and what it decided to do about them
CREATE TABLE IF NOT EXISTS `%PREFIX%npc_threat` (
  `moveid` int(11) unsigned NOT NULL,                   -- the incoming movement
  `uid` int(11) unsigned NOT NULL,
  `wref` int(11) unsigned NOT NULL,                     -- attacked village
  `attacker` int(11) unsigned NOT NULL DEFAULT 0,       -- attacking player
  `from_wref` int(11) unsigned NOT NULL DEFAULT 0,
  `arrive_at` int(11) unsigned NOT NULL,
  `noticed_at` int(11) unsigned NOT NULL,
  `estimate` float NOT NULL DEFAULT 0,                  -- guessed attack strength
  `reaction` varchar(10) NOT NULL DEFAULT '',           -- hold | dodge | late | none
  `spend` tinyint(1) unsigned NOT NULL DEFAULT 0,       -- spent the stock before the hit
  `dodged` tinyint(1) unsigned NOT NULL DEFAULT 0,      -- troops sent away
  PRIMARY KEY (`moveid`),
  KEY `uid` (`uid`, `arrive_at`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- Who hit this NPC (memory) and a planned revenge
CREATE TABLE IF NOT EXISTS `%PREFIX%npc_grudge` (
  `uid` int(11) unsigned NOT NULL,
  `wref` int(11) unsigned NOT NULL,                     -- attacker's village
  `enemy` int(11) unsigned NOT NULL DEFAULT 0,          -- attacker's player
  `hits` int(11) unsigned NOT NULL DEFAULT 0,
  `last_hit` int(11) unsigned NOT NULL DEFAULT 0,
  `revenge_at` int(11) unsigned NOT NULL DEFAULT 0,     -- 0 = no revenge planned
  `revenges` int(11) unsigned NOT NULL DEFAULT 0,
  `seen_attack` float NOT NULL DEFAULT 0,               -- strongest attack seen marching home (report)
  PRIMARY KEY (`uid`, `wref`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

ALTER TABLE `%PREFIX%npc_grudge`
  ADD COLUMN IF NOT EXISTS `seen_attack` float NOT NULL DEFAULT 0;
