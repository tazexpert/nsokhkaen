<?php
/** ตั้งค่าแดชบอร์ด Power BI: ลิงก์ (Publish to web / Embed) และชื่อที่แสดง — ผู้ดูแลแดชบอร์ด */
require_once __DIR__ . '/bootstrap.php';
require_module(PBI_MODULE, 'admin');
$errors = [];
$url = pbi_url();
$title = pbi_title();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $url = trim(post('url'));
    $title = trim(post('title'));
    if (!pbi_valid_url($url)) {
        $errors[] = 'ลิงก์ต้องขึ้นต้นด้วย https:// (คัดลอกจาก Power BI → ไฟล์ → ฝังรายงาน → เผยแพร่ไปยังเว็บ หรือ ฝังเว็บไซต์)';
    }
    if ($title === '') {
        $errors[] = 'กรุณากรอกชื่อที่แสดง';
    }
    if (!$errors) {
        save_setting('powerbi_url', $url);
        save_setting('powerbi_title', mb_substr($title, 0, 150));
        audit_log('powerbi_settings', $title, PBI_MODULE);
        flash('บันทึกการตั้งค่าแดชบอร์ดแล้ว');
        redirect('index.php');
    }
}
$active = 'settings';
$pageTitle = 'ตั้งค่า พิกัด EA ตัวอย่างโครงการ';
require __DIR__ . '/header.php';
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>
<form method="post" class="card" style="max-width:900px">
  <div class="card-body">
    <?= csrf_field() ?>
    <div class="mb-3"><label class="form-label">ชื่อที่แสดง (หัวข้อหน้า)</label>
      <input name="title" class="form-control" maxlength="150" required value="<?= h($title) ?>"></div>
    <div class="mb-2"><label class="form-label">ลิงก์ Power BI</label>
      <textarea name="url" class="form-control font-monospace small" rows="3" required><?= h($url) ?></textarea></div>
    <div class="form-text">
      ใช้ลิงก์จาก Power BI → <strong>ไฟล์ → ฝังรายงาน → เผยแพร่ไปยังเว็บ (สาธารณะ)</strong> หรือ <strong>ฝังเว็บไซต์หรือพอร์ทัล</strong>
      (ใส่เฉพาะ URL เช่น <code>https://app.powerbi.com/view?r=...</code> ไม่ต้องใส่โค้ด &lt;iframe&gt;)
    </div>
    <div class="alert alert-warning small mt-3 mb-0"><i class="bi bi-shield-exclamation"></i>
      สิทธิ์ในระบบนี้ควบคุมว่า<strong>ใครเห็นเมนู</strong> — ถ้าเป็นลิงก์ "เผยแพร่ไปยังเว็บ" ผู้ที่มีลิงก์จะเปิดรายงานได้โดยตรง
      ข้อมูลที่เป็นความลับควรใช้ลิงก์แบบ "ฝังเว็บไซต์หรือพอร์ทัล" ที่ต้องลงชื่อเข้าใช้ Microsoft</div>
  </div>
  <div class="card-footer d-flex gap-2"><button class="btn btn-primary"><i class="bi bi-save"></i> บันทึก</button>
    <a href="index.php" class="btn btn-light">ยกเลิก</a></div>
</form>
<?php require __DIR__ . '/footer.php'; ?>
