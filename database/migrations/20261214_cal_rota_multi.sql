-- ============================================================================
-- ปฏิทิน: เวรกิจกรรม — ข้าราชการหลายคน / ข้าราชการทุกคน (ไม่เลือกกลุ่มเจ้าหน้าที่) — รันซ้ำได้ · ใช้ CREATE TABLE อย่างเดียว
--   1 เวร (cal_rota_turns) มีข้าราชการได้หลายคน: แถวในตารางนี้ (counted = 1 → นับเป็นคิวของคนนั้น · 0 = ข้าราชการทุกคน ไม่กระทบคิว)
--   เวรแบบเดิม (ข้าราชการ 1 คน + กลุ่ม) ไม่มีแถวในตารางนี้ — ใช้ official_user_id/official_name ตามเดิม
--   ไม่เลือกกลุ่มเจ้าหน้าที่: cal_rota_turns.group_no = 0
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261214_cal_rota_multi.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

CREATE TABLE IF NOT EXISTS cal_rota_turn_officials (
  turn_id     INT UNSIGNED NOT NULL,
  sort_order  INT          NOT NULL,
  user_id     INT UNSIGNED NULL,
  name        VARCHAR(150) NOT NULL,
  counted     TINYINT(1)   NOT NULL DEFAULT 1,      -- 1 = นับเป็นคิวข้าราชการของคนนั้น · 0 = ไม่นับ (ข้าราชการทุกคน)
  PRIMARY KEY (turn_id, sort_order),
  CONSTRAINT fk_cal_rota_to_turn FOREIGN KEY (turn_id) REFERENCES cal_rota_turns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
