-- How many hero adventures appear (x one per game day); Generator::adventureTimes(),
-- set by runtime/novy_svet.bat (VYPRAVY). The game's own table - the NPC migrations
-- are the place that adds columns automatically.
ALTER TABLE `%PREFIX%config`
  ADD COLUMN IF NOT EXISTS `adventures` float NOT NULL DEFAULT 1;
