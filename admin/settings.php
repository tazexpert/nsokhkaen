<?php
/** ตั้งค่าระบบกลาง (ผู้ดูแลระบบกลาง) */
require_once __DIR__ . '/../core/bootstrap.php';
require_superadmin();

$errors = [];
$appName = APP_NAME;
$orgName = ORG_NAME;
$appUrl = APP_URL;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $appName = post('app_name');
    $orgName = post('org_name');
    $appUrl = post('app_url');
    if ($appName === '' || $orgName === '') {
        $errors[] = 'กรุณากรอกชื่อระบบและชื่อหน่วยงาน';
    }
    if (!filter_var($appUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $appUrl)) {
        $errors[] = 'รูปแบบ URL ไม่ถูกต้อง (ต้องขึ้นต้นด้วย http:// หรือ https://)';
    }
    if (!$errors) {
        save_setting('app_name', $appName);
        save_setting('org_name', $orgName);
        save_setting('app_url', rtrim($appUrl, '/') . '/');
        audit_log('settings_update', 'app_url: ' . $appUrl);
        flash('บันทึกค่าระบบเรียบร้อยแล้ว');
        redirect(url('admin/settings.php'));
    }
}

$pageTitle = 'ตั้งค่าระบบ';
$active = 'settings';
require APP_ROOT . '/core/layout/header.php';
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>
<form method="post" class="card" style="max-width:760px">
  <?= csrf_field() ?>
  <div class="card-body">
    <div class="mb-3"><label class="form-label">ชื่อระบบ</label><input name="app_name" class="form-control" required value="<?= h($appName) ?>"></div>
    <div class="mb-3"><label class="form-label">ชื่อหน่วยงาน</label><input name="org_name" class="form-control" required value="<?= h($orgName) ?>">
      <div class="form-text">แสดงในหน้าเข้าสู่ระบบ ท้ายหน้า และเอกสารที่พิมพ์</div></div>
    <div class="mb-3"><label class="form-label">URL ของระบบ (APP_URL)</label>
      <input name="app_url" class="form-control" required value="<?= h($appUrl) ?>" placeholder="http://192.168.1.50/nsokhkaen/">
      <div class="form-text">
        ใช้สร้างลิงก์ที่ต้องเปิดจาก<strong>อุปกรณ์อื่น</strong> เช่น QR Code สติ๊กเกอร์วัสดุ — ต้องเป็นที่อยู่ที่มือถือเข้าถึงได้จริง
        (ห้ามใช้ <code>localhost</code> ถ้าจะสแกนด้วยมือถือ) · ลิงก์ภายในหน้าเว็บไม่ขึ้นกับค่านี้ (ระบบตรวจ path ให้อัตโนมัติ: <code><?= h(BASE_URL) ?></code>)
      </div></div>
  </div>
  <div class="card-footer"><button class="btn btn-primary"><i class="bi bi-save"></i> บันทึก</button></div>
</form>

<div class="card mt-3" style="max-width:760px">
  <div class="card-header"><strong><i class="bi bi-grid"></i> ระบบที่ติดตั้ง</strong></div>
  <ul class="list-group list-group-flush">
    <?php foreach (modules() as $m): ?>
      <li class="list-group-item">
        <div class="d-flex"><strong><i class="bi <?= h($m['icon']) ?>" style="color:<?= h($m['color']) ?>"></i> <?= h($m['name']) ?></strong>
          <code class="ms-auto small">modules/<?= h($m['key']) ?>/</code></div>
        <div class="small text-muted">บทบาท: <?= h(implode(' · ', $m['roles'])) ?></div>
      </li>
    <?php endforeach; ?>
  </ul>
  <div class="card-footer small text-muted">เพิ่มระบบใหม่: สร้างโฟลเดอร์ใน <code>modules/</code> พร้อมไฟล์ <code>module.php</code> (ดู docs/ARCHITECTURE.md)</div>
</div>
<?php require APP_ROOT . '/core/layout/footer.php'; ?>
