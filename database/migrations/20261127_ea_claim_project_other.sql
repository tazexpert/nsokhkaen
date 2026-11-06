-- ============================================================================
-- ใบเบิกค่าเดินทาง: โครงการ "อื่น ๆ" (พิมพ์ชื่อเอง) — รันซ้ำได้
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261127_ea_claim_project_other.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

ALTER TABLE ea_claims
  ADD COLUMN IF NOT EXISTS project_other VARCHAR(255) NOT NULL DEFAULT '' AFTER project_id;   -- ชื่อโครงการที่พิมพ์เอง (project_id = NULL)
