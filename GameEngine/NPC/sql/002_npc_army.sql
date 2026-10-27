-- NPC system - army budget (phase 5).
-- Each NPC saves a share of its income (by profile) for research and training.
-- ADD COLUMN IF NOT EXISTS is MariaDB syntax; keeps npc_setup.php re-runnable.

ALTER TABLE `%PREFIX%npc`
  ADD COLUMN IF NOT EXISTS `army_budget` float NOT NULL DEFAULT 0,             -- resources (sum of all 4) available to the army
  ADD COLUMN IF NOT EXISTS `budget_at` int(11) unsigned NOT NULL DEFAULT 0;    -- last time the budget was topped up
