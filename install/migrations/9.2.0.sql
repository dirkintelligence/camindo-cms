-- 9.2.0 - Fourth visibility of an input field: visibility_lookup decides which fields the
-- detailed preview of a lookup field pointing to the area shows (RecordBackend::
-- detailedText(), details.lookup_detailed). Until now it showed the list fields - existing
-- fields take over visibility_list, so previews stay as they were.
ALTER TABLE `field`
  ADD COLUMN `visibility_lookup` bit(1) NOT NULL DEFAULT b'0' AFTER `visibility_list`;
UPDATE `field` SET `visibility_lookup` = `visibility_list`;
