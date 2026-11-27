-- Migration: 20261126_academy_materials_grouping
-- Versioned reconciliation for deterministic message grouping and safe file titles.

SET @academy_has_grouping_version = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'academy_material_channel_state'
    AND COLUMN_NAME = 'grouping_version'
);
SET @academy_grouping_version_sql = IF(
  @academy_has_grouping_version = 0,
  'ALTER TABLE academy_material_channel_state ADD COLUMN grouping_version SMALLINT UNSIGNED NOT NULL DEFAULT 0',
  'DO 0'
);
PREPARE academy_grouping_version_stmt FROM @academy_grouping_version_sql;
EXECUTE academy_grouping_version_stmt;
DEALLOCATE PREPARE academy_grouping_version_stmt;

SET @academy_has_attachment_filename = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'academy_material_messages'
    AND COLUMN_NAME = 'attachment_filename'
);
SET @academy_attachment_filename_sql = IF(
  @academy_has_attachment_filename = 0,
  'ALTER TABLE academy_material_messages ADD COLUMN attachment_filename VARCHAR(255) NULL AFTER material_type',
  'DO 0'
);
PREPARE academy_attachment_filename_stmt FROM @academy_attachment_filename_sql;
EXECUTE academy_attachment_filename_stmt;
DEALLOCATE PREPARE academy_attachment_filename_stmt;

SET @academy_has_recording_caption = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'academy_material_messages'
    AND COLUMN_NAME = 'is_recording_caption'
);
SET @academy_recording_caption_sql = IF(
  @academy_has_recording_caption = 0,
  'ALTER TABLE academy_material_messages ADD COLUMN is_recording_caption TINYINT(1) NOT NULL DEFAULT 0 AFTER is_caption_candidate',
  'DO 0'
);
PREPARE academy_recording_caption_stmt FROM @academy_recording_caption_sql;
EXECUTE academy_recording_caption_stmt;
DEALLOCATE PREPARE academy_recording_caption_stmt;

SET @academy_description_is_nullable = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'academy_material_items'
    AND COLUMN_NAME = 'description_message_id'
    AND IS_NULLABLE = 'YES'
);
SET @academy_description_nullable_sql = IF(
  @academy_description_is_nullable = 0,
  'ALTER TABLE academy_material_items MODIFY COLUMN description_message_id VARCHAR(32) NULL',
  'DO 0'
);
PREPARE academy_description_nullable_stmt FROM @academy_description_nullable_sql;
EXECUTE academy_description_nullable_stmt;
DEALLOCATE PREPARE academy_description_nullable_stmt;
