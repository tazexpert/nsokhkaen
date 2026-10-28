<?php
/** ตั้งค่าสลิป: ผู้ลงนาม และรูปลายมือชื่อ (แสดงบนสลิปที่อนุมัติแล้ว) */
require_once __DIR__ . '/bootstrap.php';
require_module(PAY_MODULE, 'manager');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $set = pay_settings();
    pay_save_setting('signer_name', mb_substr(post('signer_name'), 0, 150));
    pay_save_setting('signer_position', mb_substr(trim((string)($_POST['signer_position'] ?? '')), 0, 300));
    $f = $_FILES['signature'] ?? null;
    $old = $set['signature_path'];
    if ($f && $f['error'] === UPLOAD_ERR_OK && is_uploaded_file($f['tmp_name'])) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? null;
        if (!$ext || $f['size'] > 2 * 1024 * 1024) {
            flash('ลายมือชื่อต้องเป็นรูป PNG/JPG/WebP ไม่เกิน 2 MB', 'danger');
            redirect('settings.php');
        }
        $name = 'signature_' . bin2hex(random_bytes(8)) . '.' . $ext;
        move_uploaded_file($f['tmp_name'], pay_private_dir() . '/' . $name);
        pay_save_setting('signature_path', PAY_PRIVATE_DIR . '/' . $name);
        delete_upload($old ?: null);
    } elseif (post('remove_signature')) {
        pay_save_setting('signature_path', '');
        delete_upload($old ?: null);
    }
    audit_log('payslip_settings', '', PAY_MODULE);
    flash('บันทึกการตั้งค่าแล้ว');
    redirect('settings.php');
}
$set = pay_settings();
$active = 'settings';
$pageTitle = 'ตั้งค่าสลิปเงินเดือน';
require __DIR__ . '/header.php';
?>
<form method="post" enctype="multipart/form-data" class="card" style="max-width:720px">
  <div class="card-header"><strong>ผู้ลงนามในสลิป</strong></div>
  <div class="card-body row g-3">
    <?= csrf_field() ?>
    <div class="col-md-6"><label class="form-label">ชื่อผู้ลงนาม</label><input name="signer_name" class="form-control" value="<?= h($set['signer_name']) ?>"></div>
    <div class="col-md-6"><label class="form-label">ตำแหน่ง <small class="text-muted">(ขึ้นบรรทัดใหม่ได้)</small></label>
      <textarea name="signer_position" class="form-control" rows="2"><?= h($set['signer_position']) ?></textarea></div>
    <div class="col-12"><label class="form-label">รูปลายมือชื่อ <small class="text-muted">(PNG พื้นโปร่งใสจะสวยที่สุด · แสดงเฉพาะสลิปที่อนุมัติแล้ว)</small></label>
      <?php if ($set['signature_path']): ?>
        <div class="border rounded p-2 mb-2 d-inline-block bg-white"><img src="signature.php?v=<?= h(substr(md5($set['signature_path']), 0, 8)) ?>" style="height:60px" alt=""></div>
        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="remove_signature" value="1" id="rmSig"><label class="form-check-label" for="rmSig">ลบรูปลายมือชื่อ</label></div>
      <?php endif; ?>
      <input type="file" name="signature" accept="image/png,image/jpeg,image/webp" class="form-control">
      <div class="form-text">ไฟล์ลายมือชื่อเก็บในโฟลเดอร์ที่เปิดตรงจาก URL ไม่ได้ ระบบส่งให้เฉพาะผู้ที่มีสิทธิ์ในระบบสลิปเท่านั้น</div></div>
  </div>
  <div class="card-footer"><button class="btn btn-primary">บันทึก</button></div>
</form>
<?php require __DIR__ . '/footer.php'; ?>
