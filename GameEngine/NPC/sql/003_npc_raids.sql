-- NPC system - raids (phase 6).
-- One row per raid an NPC sent; the battle itself lives in the game tables
-- (attacks/movement), ref links to s1_attacks.id.

CREATE TABLE IF NOT EXISTS `%PREFIX%npc_raid` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(11) unsigned NOT NULL,                   -- raiding NPC
  `from_wref` int(11) unsigned NOT NULL,
  `to_wref` int(11) unsigned NOT NULL,
  `ref` int(11) unsigned NOT NULL,                   -- s1_attacks.id
  `units` varchar(255) NOT NULL,                     -- e.g. "u22:40,u24:15"
  `capacity` int(11) unsigned NOT NULL DEFAULT 0,    -- total carry capacity sent
  `expected_loot` int(11) unsigned NOT NULL DEFAULT 0,
  `sent_at` int(11) unsigned NOT NULL,
  `arrive_at` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `uid_sent` (`uid`,`sent_at`),
  KEY `target_sent` (`to_wref`,`sent_at`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;
