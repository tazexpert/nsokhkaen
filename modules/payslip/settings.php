<?php
/**
 * ตั้งค่าสลิป: ผู้ลงนามแยกตามช่วงเวลา
 * สลิปแต่ละเดือนแสดงผู้ลงนามที่ "เริ่มตั้งแต่" ล่าสุดไม่เกินเดือนนั้น — เปลี่ยนผู้ลงนามแล้วสลิปเดือนเก่ายังเป็นคนเดิม
 */
require_once __DIR__ . '/bootstrap.php';
require_module(PAY_MODULE, 'manager');
$pdo = db();

/** อัปโหลดลายมือชื่อ คืน path หรือ null (ไม่ได้เลือกไฟล์) */
function pay_save_signature(?array $f): ?string
{
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        throw new RuntimeException('อัปโหลดลายมือชื่อไม่สำเร็จ');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext || $f['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException('ลายมือชื่อต้องเป็นรูป PNG/JPG/WebP ไม่เกิน 2 MB');
    }
    $name = 'signature_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], pay_private_dir() . '/' . $name)) {
        throw new RuntimeException('บันทึกไฟล์ไม่สำเร็จ ตรวจสอบสิทธิ์การเขียนโฟลเดอร์ uploads');
    }
    return PAY_PRIVATE_DIR . '/' . $name;
}

$edit = int_param('edit');
$errors = [];
$form = ['id' => 0, 'effective_ym' => null, 'name' => '', 'position' => '', 'signature_path' => null];
if ($edit) {
    $st = $pdo->prepare('SELECT * FROM pay_signers WHERE id = ?');
    $st->execute([$edit]);
    $form = $st->fetch() ?: $form;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $id = int_param('id');
    if (post('action') === 'delete') {
        $st = $pdo->prepare('SELECT * FROM pay_signers WHERE id = ?');
        $st->execute([$id]);
        if ($row = $st->fetch()) {
            $pdo->prepare('DELETE FROM pay_signers WHERE id = ?')->execute([$id]);
            delete_upload($row['signature_path'] ?: null);
            audit_log('payslip_signer_delete', $row['name'] . ' ' . $row['effective_ym'], PAY_MODULE);
            flash('ลบผู้ลงนามแล้ว');
        }
        redirect('settings.php');
    }
    $ym = post('from') === 'start' ? 0 : int_param('year') * 100 + int_param('month');
    $form = array_merge($form, ['id' => $id, 'effective_ym' => $ym, 'name' => post('name'),
        'position' => mb_substr(trim((string)($_POST['position'] ?? '')), 0, 300)]);
    if ($form['name'] === '') {
        $errors[] = 'กรุณากรอกชื่อผู้ลงนาม';
    }
    if ($ym !== 0 && (int_param('year') < 2500 || int_param('month') < 1 || int_param('month') > 12)) {
        $errors[] = 'กรุณาเลือกเดือน/ปีที่เริ่มใช้';
    }
    $dup = $pdo->prepare('SELECT name FROM pay_signers WHERE effective_ym = ? AND id <> ?');
    $dup->execute([$ym, $id]);
    if ($d = $dup->fetchColumn()) {
        $errors[] = 'มีผู้ลงนามที่เริ่ม' . pay_signer_from($ym) . ' อยู่แล้ว (' . $d . ') — แก้ไขรายการนั้นแทน';
    }
    $newSig = null;
    if (!$errors) {
        try {
            $newSig = pay_save_signature($_FILES['signature'] ?? null);
        } catch (RuntimeException $ex) {
            $errors[] = $ex->getMessage();
        }
    }
    if (!$errors) {
        $sigPath = $form['signature_path'];
        if ($newSig || post('remove_signature')) {
            delete_upload($sigPath ?: null);
            $sigPath = $newSig;
        }
        if ($id) {
            $pdo->prepare('UPDATE pay_signers SET effective_ym = ?, name = ?, position = ?, signature_path = ? WHERE id = ?')
                ->execute([$ym, $form['name'], $form['position'] ?: null, $sigPath, $id]);
        } else {
            $pdo->prepare('INSERT INTO pay_signers (effective_ym, name, position, signature_path) VALUES (?, ?, ?, ?)')
                ->execute([$ym, $form['name'], $form['position'] ?: null, $sigPath]);
        }
        audit_log('payslip_signer_save', $form['name'] . ' ' . pay_signer_from($ym), PAY_MODULE);
        flash('บันทึกผู้ลงนามแล้ว — สลิป' . pay_signer_from($ym) . ' จะแสดง ' . $form['name']);
        redirect('settings.php');
    }
    if ($newSig) {
        delete_upload($newSig);
    }
}

$signers = $pdo->query('SELECT * FROM pay_signers ORDER BY effective_ym DESC')->fetchAll();
$users = $pdo->query('SELECT full_name, position FROM core_users WHERE is_active = 1 ORDER BY full_name')->fetchAll();
$by = (int)date('Y') + 543;
$bm = (int)date('n');
[$ny, $nm] = $bm === 12 ? [$by + 1, 1] : [$by, $bm + 1];
$latest = $pdo->query('SELECT year, month FROM pay_slips ORDER BY year DESC, month DESC LIMIT 1')->fetch();
$isStart = $form['effective_ym'] !== null && (int)$form['effective_ym'] === 0;
$fy = $form['effective_ym'] ? intdiv((int)$form['effective_ym'], 100) : $by;   // ค่าเริ่มต้น = เดือนปัจจุบัน
$fm = $form['effective_ym'] ? (int)$form['effective_ym'] % 100 : $bm;

$active = 'settings';
$pageTitle = 'ตั้งค่าสลิปเงินเดือน';
require __DIR__ . '/header.php';
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>
<div class="row g-3">
  <div class="col-xl-7">
    <div class="card">
      <div class="card-header"><strong><i class="bi bi-pen"></i> ผู้ลงนามในสลิป</strong>
        <span class="small text-muted">— สลิปแต่ละเดือนใช้ผู้ลงนามที่มีผลในเดือนนั้น</span></div>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead><tr><th>มีผล</th><th>ผู้ลงนาม</th><th>ลายมือชื่อ</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($signers as $i => $sg): $next = $signers[$i - 1] ?? null; ?>
            <tr class="<?= (int)$sg['id'] === (int)$form['id'] ? 'table-primary' : '' ?>">
              <td class="text-nowrap"><strong><?= h(pay_signer_from((int)$sg['effective_ym'])) ?></strong>
                <div class="small text-muted"><?php if ($next): $e = (int)$next['effective_ym']; [$ey, $em] = $e % 100 === 1 ? [intdiv($e, 100) - 1, 12] : [intdiv($e, 100), $e % 100 - 1]; ?>
                  ถึง <?= h(pay_period($ey, $em)) ?><?php else: ?>ถึงปัจจุบัน<?php endif; ?></div></td>
              <td><?= h($sg['name']) ?><div class="small text-muted"><?= nl2br(h((string)$sg['position'])) ?></div></td>
              <td><?= $sg['signature_path'] ? '<img src="signature.php?id=' . (int)$sg['id'] . '&v=' . substr(md5($sg['signature_path']), 0, 8) . '" style="height:40px" alt="">' : '<span class="small text-muted">ไม่มี</span>' ?></td>
              <td class="text-end text-nowrap">
                <a href="?edit=<?= (int)$sg['id'] ?>" class="btn btn-sm btn-light" title="แก้ไข"><i class="bi bi-pencil"></i></a>
                <form method="post" class="d-inline" data-confirm="ลบผู้ลงนาม <?= h($sg['name']) ?>?"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$sg['id'] ?>">
                  <button class="btn btn-sm btn-light text-danger" title="ลบ"><i class="bi bi-trash"></i></button></form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$signers): ?><tr><td colspan="4" class="text-center text-muted py-4">ยังไม่ได้กำหนด — สลิปจะแสดง <?= h(PAY_DEFAULT_SIGNER['name']) ?> (ไม่มีลายมือชื่อ)</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer small">
        สลิปเดือนนี้ (<?= h(pay_period($by, $bm)) ?>): <strong><?= h(pay_signer_for($by, $bm)['name']) ?></strong> ·
        เดือนหน้า (<?= h(pay_period($ny, $nm)) ?>): <strong><?= h(pay_signer_for($ny, $nm)['name']) ?></strong>
        <?php if ($latest): ?> · สลิปล่าสุดในระบบ <?= h(pay_period((int)$latest['year'], (int)$latest['month'])) ?>: <strong><?= h(pay_signer_for((int)$latest['year'], (int)$latest['month'])['name']) ?></strong><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-xl-5">
    <form method="post" enctype="multipart/form-data" class="card">
      <div class="card-header d-flex"><strong><?= $form['id'] ? '<i class="bi bi-pencil"></i> แก้ไขผู้ลงนาม' : '<i class="bi bi-plus-lg"></i> เพิ่มผู้ลงนาม (เปลี่ยนผู้ลงนาม)' ?></strong>
        <?php if ($form['id']): ?><a href="settings.php" class="ms-auto small">เพิ่มใหม่แทน</a><?php endif; ?></div>
      <div class="card-body row g-3">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
        <div class="col-12"><label class="form-label">เริ่มใช้กับสลิปเดือน</label>
          <div class="form-check"><input class="form-check-input" type="radio" name="from" value="month" id="fromM" <?= $isStart ? '' : 'checked' ?>>
            <label class="form-check-label d-flex gap-2 align-items-center" for="fromM">
              <select name="month" class="form-select form-select-sm" style="width:auto"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= $fm === $m ? 'selected' : '' ?>><?= pay_month_name($m) ?></option><?php endfor; ?></select>
              <input type="number" name="year" class="form-control form-control-sm" style="width:100px" value="<?= $fy ?>"> เป็นต้นไป</label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="from" value="start" id="fromS" <?= $isStart ? 'checked' : '' ?>>
            <label class="form-check-label" for="fromS">ตั้งแต่เริ่มต้น (ทุกเดือนก่อนผู้ลงนามคนถัดไป)</label></div>
        </div>
        <div class="col-12"><label class="form-label">ชื่อผู้ลงนาม</label>
          <input name="name" class="form-control" list="userList" required value="<?= h($form['name']) ?>" placeholder="พิมพ์ค้นหาชื่อผู้ใช้งาน หรือพิมพ์ชื่อเอง" autocomplete="off" id="sgName">
          <datalist id="userList"><?php foreach ($users as $u): ?><option value="<?= h($u['full_name']) ?>" data-position="<?= h($u['position']) ?>"><?php endforeach; ?></datalist></div>
        <div class="col-12"><label class="form-label">ตำแหน่ง <small class="text-muted">(ดึงจากผู้ใช้งาน แก้ไข/ขึ้นบรรทัดใหม่ได้)</small></label>
          <textarea name="position" id="sgPos" class="form-control" rows="2" placeholder="เช่น สถิติจังหวัดขอนแก่น"><?= h((string)$form['position']) ?></textarea></div>
        <div class="col-12"><label class="form-label">รูปลายมือชื่อ <small class="text-muted">(PNG พื้นโปร่งใส · แสดงเฉพาะสลิปที่อนุมัติแล้ว)</small></label>
          <?php if ($form['signature_path']): ?>
            <div class="border rounded p-2 mb-2 d-inline-block bg-white"><img src="signature.php?id=<?= (int)$form['id'] ?>&v=<?= substr(md5($form['signature_path']), 0, 8) ?>" style="height:56px" alt=""></div>
            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="remove_signature" value="1" id="rmSig"><label class="form-check-label" for="rmSig">ลบรูปลายมือชื่อ</label></div>
          <?php endif; ?>
          <input type="file" name="signature" accept="image/png,image/jpeg,image/webp" class="form-control">
          <div class="form-text">เก็บในโฟลเดอร์ที่เปิดตรงจาก URL ไม่ได้ ส่งให้เฉพาะผู้มีสิทธิ์ในระบบสลิป</div></div>
      </div>
      <div class="card-footer"><button class="btn btn-primary"><i class="bi bi-save"></i> บันทึก</button>
        <?php if ($form['id']): ?><a href="settings.php" class="btn btn-light">ยกเลิก</a><?php endif; ?></div>
    </form>
  </div>
</div>
<script>
document.getElementById('sgName').addEventListener('change', e => {
  const o = [...document.querySelectorAll('#userList option')].find(x => x.value === e.target.value.trim());
  if (o) document.getElementById('sgPos').value = o.dataset.position || '';
});
</script>
<?php require __DIR__ . '/footer.php'; ?>
