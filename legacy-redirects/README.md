# ไฟล์ redirect สำหรับ URL ของระบบเดิม

ใช้หลังย้ายไประบบใหม่ เพื่อให้ **QR Code สติ๊กเกอร์ที่พิมพ์ไว้แล้ว** และลิงก์/บุ๊กมาร์กเดิมยังใช้งานได้
โดยไม่ต้องพิมพ์สติ๊กเกอร์ใหม่ (ดูขั้นตอนเต็มใน docs/MIGRATION.md หัวข้อ "ขั้นที่ 6")

1. สำรองโฟลเดอร์ระบบเดิมเก็บไว้ (เช่น `htdocs/nsokhkaen_inventory` → `nsokhkaen_inventory_backup`)
2. ลบไฟล์ในโฟลเดอร์เดิมออก แล้วคัดลอกไฟล์ในโฟลเดอร์นี้ไปแทน
   - `legacy-redirects/nsokhkaen_inventory/*` → `htdocs/nsokhkaen_inventory/`
   - `legacy-redirects/digitalcc/*` → โฟลเดอร์เว็บของระบบศูนย์ดิจิทัลชุมชนเดิม (ถ้าเคยติดตั้ง)
3. แก้ URL ของระบบใหม่ในไฟล์ `redirect-config.php` (และ `redirect-config.js` สำหรับระบบศูนย์ฯ)

ตัวอย่าง: สติ๊กเกอร์เดิม `http://192.168.1.50/nsokhkaen_inventory/public/scan.php?qr=MAT-QR-0001`
จะถูกพาไปที่ `http://192.168.1.50/nsokhkaen/modules/inventory/scan.php?qr=MAT-QR-0001` อัตโนมัติ
(ค่า qr_code ของวัสดุถูกย้ายมาเหมือนเดิมทุกตัว จึงเปิดรายการเดิมได้ถูกต้อง)
