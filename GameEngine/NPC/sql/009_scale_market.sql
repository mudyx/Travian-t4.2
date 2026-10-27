-- Hundreds of NPCs (phase 11): map and movement lookups by position / village.
-- Pure indexes - the game's own queries profit too.
CREATE INDEX IF NOT EXISTS `npc_xy` ON `%PREFIX%wdata` (`x`, `y`);
CREATE INDEX IF NOT EXISTS `npc_free` ON `%PREFIX%wdata` (`occupied`, `fieldtype`);
CREATE INDEX IF NOT EXISTS `npc_to` ON `%PREFIX%movement` (`to`, `sort_type`, `proc`);
CREATE INDEX IF NOT EXISTS `npc_from` ON `%PREFIX%movement` (`from`, `sort_type`, `proc`);
CREATE INDEX IF NOT EXISTS `npc_active` ON `%PREFIX%npc` (`active`);
