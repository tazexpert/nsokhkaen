<?php
/** บัญชีของฉัน: แก้ไขข้อมูลส่วนตัว เปลี่ยนรหัสผ่าน ตั้ง/ลบ PIN (ทุกคนแก้ได้เฉพาะบัญชีตัวเอง) */
require_once __DIR__ . '/core/bootstrap.php';
$user = require_login();
$pdo = db();
$setupPin = !empty($_GET['setup_pin']);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    if ($action === 'profile') {
        $fullName = post('full_name');
        if ($fullName === '') {
            $errors[] = 'กรุณากรอกชื่อ-นามสกุล';
        } else {
            $wg = int_param('work_group_id');
            $pdo->prepare('UPDATE core_users SET full_name = ?, position = ?, work_group_id = ?, email = ?, phone = ? WHERE id = ?')
                ->execute([$fullName, post('position') ?: null, isset(work_groups()[$wg]) ? $wg : null,
                           post('email') ?: null, post('phone') ?: null, $user['id']]);
            flash('บันทึกข้อมูลส่วนตัวเรียบร้อยแล้ว');
            redirect(url('account.php'));
        }
    } elseif ($action === 'password') {
        $st = $pdo->prepare('SELECT password_hash FROM core_users WHERE id = ?');
        $st->execute([$user['id']]);
        $hash = $st->fetchColumn();
        $new = (string)($_POST['new_password'] ?? '');
        if (!$hash || !password_verify((string)($_POST['current_password'] ?? ''), $hash)) {
            $errors[] = 'รหัสผ่านปัจจุบันไม่ถูกต้อง';
        } elseif (mb_strlen($new) < PASSWORD_MIN_LENGTH) {
            $errors[] = 'รหัสผ่านใหม่ต้องมีอย่างน้อย ' . PASSWORD_MIN_LENGTH . ' ตัวอักษร';
        } elseif ($new !== ($_POST['confirm_password'] ?? '')) {
            $errors[] = 'รหัสผ่านใหม่และยืนยันรหัสผ่านไม่ตรงกัน';
        } else {
            $pdo->prepare('UPDATE core_users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            audit_log('change_password');
            flash('เปลี่ยนรหัสผ่านเรียบร้อยแล้ว');
            redirect(url('account.php'));
        }
    } elseif ($action === 'set_pin') {
        $pin = post('pin');
        if (!preg_match('/^\d{6}$/', $pin)) {
            $errors[] = 'PIN ต้องเป็นตัวเลข 6 หลัก';
        } elseif ($pin !== post('confirm_pin')) {
            $errors[] = 'PIN และยืนยัน PIN ไม่ตรงกัน';
        } elseif (find_user_by_pin($pin, $user['id'])) {
            $errors[] = 'PIN นี้มีผู้ใช้งานอยู่แล้ว กรุณาใช้ PIN อื่น';
        } else {
            $pdo->prepare('UPDATE core_users SET pin_hash = ? WHERE id = ?')->execute([password_hash($pin, PASSWORD_DEFAULT), $user['id']]);
            audit_log('set_pin');
            flash('ตั้ง PIN เรียบร้อยแล้ว');
            redirect($setupPin ? take_login_target(url('index.php')) : url('account.php'));
        }
    } elseif ($action === 'remove_pin') {
        $pdo->prepare('UPDATE core_users SET pin_hash = NULL WHERE id = ?')->execute([$user['id']]);
        audit_log('remove_pin');
        flash('ลบ PIN เรียบร้อยแล้ว');
        redirect(url('account.php'));
    }
}
$user = current_user(true);

$pageTitle = 'บัญชีของฉัน';
$active = 'account';
require APP_ROOT . '/core/layout/header.php';
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>
<?php if ($setupPin && !$user['has_pin']): ?>
  <div class="alert alert-warning d-flex flex-wrap gap-2 align-items-center">
    <span><i class="bi bi-shield-lock"></i> กรุณาตั้ง <strong>PIN 6 หลัก</strong> สำหรับใช้หน้าสแกน QR Code เบิกวัสดุ โดยไม่ต้องพิมพ์ชื่อผู้ใช้/รหัสผ่าน</span>
    <a class="ms-auto" href="<?= h(peek_login_target(url('index.php'))) ?>">ข้ามไปก่อน</a>
  </div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-6">
    <form method="post" class="card mb-3">
      <div class="card-header"><strong><i class="bi bi-person"></i> ข้อมูลส่วนตัว</strong></div>
      <div class="card-body row g-3">
        <?= csrf_field() ?><input type="hidden" name="action" value="profile">
        <div class="col-12"><label class="form-label">ชื่อ-นามสกุล</label><input name="full_name" class="form-control" required value="<?= h($user['full_name']) ?>"></div>
        <div class="col-12"><label class="form-label">ตำแหน่ง <small class="text-muted">(พิมพ์ในเอกสาร เช่น ใบเบิกวัสดุ)</small></label><input name="position" class="form-control" value="<?= h($user['position']) ?>"></div>
        <div class="col-12"><label class="form-label">กลุ่มงาน</label>
          <select name="work_group_id" class="form-select"><option value="">— ไม่ระบุ —</option>
            <?php foreach (work_groups() as $gid => $gname): ?><option value="<?= (int)$gid ?>" <?= (int)$user['work_group_id'] === (int)$gid ? 'selected' : '' ?>><?= h($gname) ?></option><?php endforeach; ?>
          </select></div>
        <div class="col-md-6"><label class="form-label">อีเมล</label><input name="email" type="email" class="form-control" value="<?= h($user['email']) ?>"></div>
        <div class="col-md-6"><label class="form-label">โทรศัพท์</label><input name="phone" class="form-control" value="<?= h($user['phone']) ?>"></div>
        <div class="col-12 small text-muted">ชื่อผู้ใช้: <strong><?= h($user['username'] ?: '— (ใช้ PIN อย่างเดียว)') ?></strong></div>
      </div>
      <div class="card-footer"><button class="btn btn-primary">บันทึก</button></div>
    </form>

    <div class="card">
      <div class="card-header"><strong><i class="bi bi-shield-check"></i> สิทธิ์การใช้งาน</strong></div>
      <ul class="list-group list-group-flush">
        <?php if ($user['is_superadmin']): ?><li class="list-group-item"><i class="bi bi-star-fill text-warning"></i> ผู้ดูแลระบบกลาง (มีสิทธิ์สูงสุดทุกระบบ)</li><?php endif; ?>
        <?php foreach (accessible_modules($user) as $m): ?>
          <li class="list-group-item d-flex"><span><?= module_logo($m, 18) ?> <?= h($m['name']) ?></span>
            <span class="ms-auto text-muted small"><?= h(role_short($m['key'], module_role($m['key'], $user))) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>

  <div class="col-lg-6">
    <?php if ($user['has_password']): ?>
    <form method="post" class="card mb-3">
      <div class="card-header"><strong><i class="bi bi-key"></i> เปลี่ยนรหัสผ่าน</strong></div>
      <div class="card-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="password">
        <div class="mb-2"><label class="form-label">รหัสผ่านปัจจุบัน</label><input type="password" name="current_password" class="form-control" required autocomplete="current-password"></div>
        <div class="mb-2"><label class="form-label">รหัสผ่านใหม่</label><input type="password" name="new_password" class="form-control" minlength="<?= PASSWORD_MIN_LENGTH ?>" required autocomplete="new-password"></div>
        <div class="mb-0"><label class="form-label">ยืนยันรหัสผ่านใหม่</label><input type="password" name="confirm_password" class="form-control" minlength="<?= PASSWORD_MIN_LENGTH ?>" required autocomplete="new-password"></div>
      </div>
      <div class="card-footer"><button class="btn btn-primary">เปลี่ยนรหัสผ่าน</button></div>
    </form>
    <?php endif; ?>

    <form method="post" class="card" id="pin">
      <div class="card-header d-flex align-items-center"><strong><i class="bi bi-grid-3x3-gap"></i> PIN 6 หลัก</strong>
        <span class="ms-auto badge <?= $user['has_pin'] ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $user['has_pin'] ? 'ตั้งแล้ว' : 'ยังไม่ได้ตั้ง' ?></span></div>
      <div class="card-body">
        <p class="small text-muted">ใช้เข้าระบบแบบรวดเร็ว เช่น หน้าสแกน QR Code เบิกวัสดุ — PIN ต้องไม่ซ้ำกับผู้อื่น และเก็บแบบเข้ารหัส (ลืมต้องตั้งใหม่)</p>
        <?= csrf_field() ?><input type="hidden" name="action" value="set_pin">
        <div class="row g-2">
          <div class="col-6"><label class="form-label">PIN ใหม่</label><input type="password" name="pin" inputmode="numeric" pattern="\d{6}" maxlength="6" class="form-control" required autocomplete="off" <?= $setupPin ? 'autofocus' : '' ?>></div>
          <div class="col-6"><label class="form-label">ยืนยัน PIN</label><input type="password" name="confirm_pin" inputmode="numeric" pattern="\d{6}" maxlength="6" class="form-control" required autocomplete="off"></div>
        </div>
      </div>
      <div class="card-footer d-flex gap-2">
        <button class="btn btn-primary"><?= $user['has_pin'] ? 'เปลี่ยน PIN' : 'ตั้ง PIN' ?></button>
      </div>
    </form>
    <?php if ($user['has_pin']): ?>
      <form method="post" class="mt-2 text-end" data-confirm="ลบ PIN ของคุณ? หลังลบจะเข้าใช้ด้วย PIN ไม่ได้">
        <?= csrf_field() ?><input type="hidden" name="action" value="remove_pin">
        <button class="btn btn-link text-danger btn-sm">ลบ PIN</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php require APP_ROOT . '/core/layout/footer.php'; ?>
