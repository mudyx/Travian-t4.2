-- NPC system - base tables.
-- %PREFIX% is replaced with TB_PREFIX (e.g. s1_) by npc_setup.php.
-- MyISAM like the rest of the game schema (no mixed engines / transactions).

-- One row per NPC player (s1_users.id). The player itself, its villages,
-- fields and units live in the normal game tables.
CREATE TABLE IF NOT EXISTS `%PREFIX%npc` (
  `uid` int(11) unsigned NOT NULL,
  `profile` varchar(20) NOT NULL DEFAULT 'balanced',          -- farmer | balanced | aggressor
  `aggression` tinyint(3) unsigned NOT NULL DEFAULT 50,       -- 0-100, scales army budget and raids
  `active` tinyint(1) unsigned NOT NULL DEFAULT 1,            -- 0 = runner skips this NPC
  `next_action_at` int(11) unsigned NOT NULL DEFAULT 0,       -- unix time of the next decision
  `last_action_at` int(11) unsigned NOT NULL DEFAULT 0,
  `last_attack_at` int(11) unsigned NOT NULL DEFAULT 0,
  `created` int(11) unsigned NOT NULL DEFAULT 0,
  `settings` text,                                            -- per-NPC overrides (JSON), optional
  PRIMARY KEY (`uid`),
  KEY `due` (`active`,`next_action_at`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- Audit log of everything an NPC decided or did.
CREATE TABLE IF NOT EXISTS `%PREFIX%npc_log` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(11) unsigned NOT NULL,
  `wref` int(11) unsigned NOT NULL DEFAULT 0,                 -- village the action concerns (0 = player level)
  `time` int(11) unsigned NOT NULL,
  `action` varchar(30) NOT NULL,                              -- created | heartbeat | build | train | attack | error ...
  `detail` text,
  PRIMARY KEY (`id`),
  KEY `uid_time` (`uid`,`time`),
  KEY `time` (`time`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;
