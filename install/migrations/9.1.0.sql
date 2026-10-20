-- 9.1.0 - Trash for input fields: deleting a field only marks it (Field, class
-- comment "TRASH"); changed_at/changed_by name the deletion operation, the
-- scheduler task trash deletes it finally after admin.trash_retention_days.
ALTER TABLE `field`
  ADD COLUMN `changed_at` datetime(3) DEFAULT NULL,
  ADD COLUMN `changed_by` bigint unsigned DEFAULT NULL,
  ADD COLUMN `deleted` bit(1) NOT NULL DEFAULT b'0',
  ADD KEY `idx_deleted` (`deleted`);

-- 9.1.0 - Records hang on their area: deleting an area row takes the records
-- (and via their foreign keys data_*, record_cache, history ...) with it.
-- Area::hookOnDelete() still purges the records one by one beforehand (hooks,
-- media files) - the key only protects against deleting an area directly in
-- the database. Records whose area no longer exists are removed first.
DELETE `r` FROM `record` `r` LEFT JOIN `area` `a` ON `a`.`area_id` = `r`.`area_id`
  WHERE `a`.`area_id` IS NULL;
ALTER TABLE `record`
  ALTER COLUMN `area_id` DROP DEFAULT,
  ADD CONSTRAINT `fk_record_area_id` FOREIGN KEY (`area_id`) REFERENCES `area` (`area_id`) ON DELETE CASCADE ON UPDATE RESTRICT;
