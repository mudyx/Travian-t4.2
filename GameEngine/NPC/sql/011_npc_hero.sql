-- The NPC's hero (phase 13): what the player last saw of it
CREATE TABLE IF NOT EXISTS `%PREFIX%npc_hero` (
  `uid` int(11) unsigned NOT NULL,
  `items_seen` int(11) unsigned NOT NULL DEFAULT 0,   -- item rows at the last look (new loot = a reason to look)
  `oases` tinyint(3) unsigned NOT NULL DEFAULT 0,     -- oases of the hero's village at the last look
  `auction_at` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`uid`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- The engine and the NPCs look heroes, items and adventures up by player: indexes
CREATE INDEX IF NOT EXISTS `npc_uid` ON `%PREFIX%hero` (`uid`);
CREATE INDEX IF NOT EXISTS `npc_uid` ON `%PREFIX%heroitems` (`uid`);
CREATE INDEX IF NOT EXISTS `npc_uid` ON `%PREFIX%adventure` (`uid`, `end`);
CREATE INDEX IF NOT EXISTS `npc_auction` ON `%PREFIX%auction` (`finish`, `time`);
