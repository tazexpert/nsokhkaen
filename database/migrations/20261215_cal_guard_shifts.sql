-- ============================================================================
-- ปฏิทิน: เวรศาลากลางจากคำสั่งจังหวัด (นำเข้าไฟล์ PDF ที่สแกน) — รันซ้ำได้ · ใช้ CREATE TABLE อย่างเดียว
--   1 แถว = บุคลากร 1 คน ในเวร 1 ช่วง (วันที่ · กลางวัน/กลางคืน · ผู้ปฏิบัติหน้าที่เวร/ผู้ตรวจเวร)
--   ปฏิทิน: กิจกรรม kind 'guard' ต่อ วันที่ × ช่วง × หน้าที่ เช่น "เวรศาลากลาง-กลางคืน · ผู้ตรวจเวร"
--   เดือนที่มีข้อมูลในตารางนี้ = ใช้แทนเวรศาลากลางจากแผนการปฏิบัติงาน
--   mysql -u root -p --default-character-set=utf8mb4 nsokhkaen < database/migrations/20261215_cal_guard_shifts.sql
-- ============================================================================
SET NAMES utf8mb4;
USE nsokhkaen;

CREATE TABLE IF NOT EXISTS cal_guard_shifts (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  guard_date  DATE         NOT NULL,
  shift       ENUM('day', 'night') NOT NULL,              -- กลางวัน (วันหยุด 08.30–16.30) / กลางคืน (16.30–08.30 ของวันรุ่งขึ้น)
  role        ENUM('duty', 'inspector') NOT NULL,         -- ผู้ปฏิบัติหน้าที่เวร / ผู้ตรวจเวร
  user_id     INT UNSIGNED NULL,                          -- ว่าง = ไม่มีบัญชีในระบบ (เก็บชื่อไว้)
  name        VARCHAR(150) NOT NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cal_gs_date (guard_date),
  CONSTRAINT fk_cal_gs_user FOREIGN KEY (user_id) REFERENCES core_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_cal_gs_by FOREIGN KEY (created_by) REFERENCES core_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
