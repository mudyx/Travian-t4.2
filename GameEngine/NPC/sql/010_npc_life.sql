-- A living world (phase 12): NPCs that stay away for a while and come back
-- (or not), and frustration from lost battles that decays over time.
ALTER TABLE `%PREFIX%npc`
  ADD COLUMN IF NOT EXISTS `away_from` int(11) unsigned NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `away_until` int(11) unsigned NOT NULL DEFAULT 0,      -- 0 = not away
  ADD COLUMN IF NOT EXISTS `frustration` float NOT NULL DEFAULT 0,                -- 0-100 at frustration_at
  ADD COLUMN IF NOT EXISTS `frustration_at` int(11) unsigned NOT NULL DEFAULT 0;
