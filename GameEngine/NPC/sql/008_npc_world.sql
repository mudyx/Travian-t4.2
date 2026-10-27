-- World growth (phase 10): when an NPC stopped playing (its villages stay)
ALTER TABLE `%PREFIX%npc`
  ADD COLUMN IF NOT EXISTS `quit_at` int(11) unsigned NOT NULL DEFAULT 0;
