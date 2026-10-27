-- Expansion (phase 9). One expansion at a time per NPC.
CREATE TABLE IF NOT EXISTS `%PREFIX%npc_expansion` (
  `uid` int(11) unsigned NOT NULL,
  `state` varchar(10) NOT NULL DEFAULT 'idle',          -- idle | prepare | train | send | travel
  `from_wref` int(11) unsigned NOT NULL DEFAULT 0,      -- mother village
  `target_wref` int(11) unsigned NOT NULL DEFAULT 0,    -- chosen field
  `since` int(11) unsigned NOT NULL DEFAULT 0,          -- when the current state began
  PRIMARY KEY (`uid`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- Role of every village an NPC founded: picks its starting plan
CREATE TABLE IF NOT EXISTS `%PREFIX%npc_village` (
  `wref` int(11) unsigned NOT NULL,
  `uid` int(11) unsigned NOT NULL,
  `role` varchar(10) NOT NULL DEFAULT 'eco',            -- eco | crop | front
  `founded` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`wref`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;
