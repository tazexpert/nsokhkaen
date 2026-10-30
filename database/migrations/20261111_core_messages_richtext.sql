-- ============================================================================
-- กล่องจดหมาย: ข้อความจัดรูปแบบ (HTML) → ขยายช่อง body เป็น MEDIUMTEXT — รันซ้ำได้
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261111_core_messages_richtext.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

ALTER TABLE core_messages MODIFY body MEDIUMTEXT NOT NULL;
