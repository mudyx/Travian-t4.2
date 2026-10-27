-- Long-running worlds (phase 14).

-- A colony the other villages don't feed fends for itself (NPCExpansion::shape):
-- when merchants last set out to it, and since when it is on its own (0 = it isn't)
ALTER TABLE `%PREFIX%npc_village`
  ADD COLUMN IF NOT EXISTS `supplied_at` int(11) unsigned NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `alone_since` int(11) unsigned NOT NULL DEFAULT 0;

-- Housekeeping looks rows up by age
CREATE INDEX IF NOT EXISTS `updated` ON `%PREFIX%npc_intel` (`updated`);
CREATE INDEX IF NOT EXISTS `last_hit` ON `%PREFIX%npc_grudge` (`revenge_at`, `last_hit`);
CREATE INDEX IF NOT EXISTS `action_time` ON `%PREFIX%npc_log` (`action`, `time`);
