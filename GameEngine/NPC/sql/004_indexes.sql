-- Indexes for the hot game tables (phase 7). The engine schema has primary keys
-- only, so the automation scans whole tables every second and NPC turns filter
-- bdata/training/research/vdata without an index.
-- Adding indexes changes no behaviour; IF NOT EXISTS keeps this re-runnable (MariaDB).

CREATE INDEX IF NOT EXISTS `npc_proc_sort_end` ON `%PREFIX%movement` (`proc`, `sort_type`, `endtime`);
CREATE INDEX IF NOT EXISTS `npc_ref` ON `%PREFIX%movement` (`ref`);
CREATE INDEX IF NOT EXISTS `npc_wid` ON `%PREFIX%bdata` (`wid`);
CREATE INDEX IF NOT EXISTS `npc_vref` ON `%PREFIX%training` (`vref`);
CREATE INDEX IF NOT EXISTS `npc_vref` ON `%PREFIX%research` (`vref`);
CREATE INDEX IF NOT EXISTS `npc_owner` ON `%PREFIX%vdata` (`owner`);
CREATE INDEX IF NOT EXISTS `npc_uid` ON `%PREFIX%ndata` (`uid`);
