<?php
/** เพิ่ม/แก้ไขผู้ใช้งาน และกำหนดสิทธิ์รายระบบ (ผู้ดูแลระบบกลาง) */
require_once __DIR__ . '/../core/bootstrap.php';
$me = require_superadmin();
$pdo = db();
$id = int_param('id');
$mods = modules();

$u = ['username' => '', 'full_name' => '', 'position' => '', 'work_group_id' => null, 'email' => '', 'phone' => '', 'nickname' => '',
      'is_superadmin' => 0, 'is_active' => 1, 'has_pin' => 0, 'has_password' => 0];
$roles = [];
if ($id) {
    $st = $pdo->prepare('SELECT *, (pin_hash IS NOT NULL) AS has_pin, (password_hash IS NOT NULL) AS has_password FROM core_users WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) {
        flash('ไม่พบผู้ใช้งาน', 'danger');
        redirect(url('admin/users.php'));
    }
    $st = $pdo->prepare('SELECT module_key, role FROM core_user_roles WHERE user_id = ?');
    $st->execute([$id]);
    $roles = $st->fetchAll(PDO::FETCH_KEY_PAIR);
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $username = post('username');
    $password = (string)($_POST['password'] ?? '');
    $pin = post('pin');
    $removePin = !empty($_POST['remove_pin']);
    $u = array_merge($u, [
        'username' => $username, 'full_name' => post('full_name'), 'position' => post('position'),
        'work_group_id' => isset(work_groups()[int_param('work_group_id')]) ? int_param('work_group_id') : null,
        'email' => post('email'), 'phone' => post('phone'), 'nickname' => post('nickname'),
        'is_superadmin' => isset($_POST['is_superadmin']) ? 1 : 0, 'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ]);
    $roles = [];
    foreach ($mods as $m) {
        $r = (string)($_POST['role'][$m['key']] ?? '');
        if ($r !== '' && isset($m['roles'][$r])) {
            $roles[$m['key']] = $r;
        }
    }
    if ($id === $me['id']) {           // ห้ามลดสิทธิ์/ปิดบัญชีตัวเอง (กันล็อกตัวเองออกจากระบบ)
        $u['is_superadmin'] = 1;
        $u['is_active'] = 1;
    }

    if ($u['full_name'] === '') {
        $errors[] = 'กรุณากรอกชื่อ-นามสกุล';
    }
    if ($username !== '' && !preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
        $errors[] = 'ชื่อผู้ใช้ต้องเป็น a-z, 0-9, _ . - ความยาว 3–50 ตัว';
    }
    if ($username !== '' && !$u['has_password'] && $password === '') {
        $errors[] = 'กรุณากำหนดรหัสผ่านสำหรับชื่อผู้ใช้นี้';
    }
    if ($password !== '' && $username === '') {
        $errors[] = 'กรุณากรอกชื่อผู้ใช้คู่กับรหัสผ่าน';
    }
    if ($password !== '' && mb_strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = 'รหัสผ่านต้องมีอย่างน้อย ' . PASSWORD_MIN_LENGTH . ' ตัวอักษร';
    }
    if ($pin !== '' && !preg_match('/^\d{6}$/', $pin)) {
        $errors[] = 'PIN ต้องเป็นตัวเลข 6 หลัก';
    } elseif ($pin !== '' && find_user_by_pin($pin, $id ?: null)) {
        $errors[] = 'PIN นี้มีผู้ใช้งานอยู่แล้ว กรุณาใช้ PIN อื่น';
    }
    $willHavePin = $pin !== '' || ($u['has_pin'] && !$removePin);
    if ($username === '' && !$willHavePin) {
        $errors[] = 'ต้องมีชื่อผู้ใช้+รหัสผ่าน หรือ PIN อย่างน้อยหนึ่งอย่าง (มิฉะนั้นจะเข้าระบบไม่ได้)';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $data = [
                'username' => $username ?: null, 'full_name' => $u['full_name'], 'position' => $u['position'] ?: null,
                'work_group_id' => $u['work_group_id'],
                'email' => $u['email'] ?: null, 'phone' => $u['phone'] ?: null, 'nickname' => mb_substr($u['nickname'], 0, 50) ?: null,
                'is_superadmin' => $u['is_superadmin'], 'is_active' => $u['is_active'],
            ];
            if ($username === '') {
                $data['password_hash'] = null;          // ไม่มีชื่อผู้ใช้ = ไม่มีรหัสผ่าน
            } elseif ($password !== '') {
                $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }
            if ($pin !== '') {
                $data['pin_hash'] = password_hash($pin, PASSWORD_DEFAULT);
            } elseif ($removePin) {
                $data['pin_hash'] = null;
            }
            $cols = array_keys($data);
            if ($id) {
                $pdo->prepare('UPDATE core_users SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ?')
                    ->execute([...array_values($data), $id]);
            } else {
                $pdo->prepare('INSERT INTO core_users (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
                    ->execute(array_values($data));
                $id = (int)$pdo->lastInsertId();
            }
            $pdo->prepare('DELETE FROM core_user_roles WHERE user_id = ?')->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO core_user_roles (user_id, module_key, role) VALUES (?, ?, ?)');
            foreach ($roles as $mk => $r) {
                $ins->execute([$id, $mk, $r]);
            }
            $pdo->commit();
            audit_log(($u['id'] ?? null) ? 'user_update' : 'user_create', 'user #' . $id . ' ' . $u['full_name']
                . ' roles: ' . json_encode($roles, JSON_UNESCAPED_UNICODE) . ($u['is_superadmin'] ? ' superadmin' : ''));
            flash('บันทึกข้อมูลผู้ใช้งานเรียบร้อยแล้ว');
            redirect(url('admin/users.php'));
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = $e->getCode() === '23000' ? 'มีชื่อผู้ใช้นี้อยู่แล้ว' : 'บันทึกไม่สำเร็จ: ' . $e->getMessage();
        }
    }
}

$pageTitle = $id ? 'แก้ไขผู้ใช้งาน: ' . $u['full_name'] : 'เพิ่มผู้ใช้งาน';
$active = 'users';
require APP_ROOT . '/core/layout/header.php';
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>
<form method="post" class="row g-3" autocomplete="off">
  <?= csrf_field() ?>
  <div class="col-xl-6">
    <div class="card mb-3">
      <div class="card-header"><strong><i class="bi bi-person"></i> ข้อมูลผู้ใช้งาน</strong></div>
      <div class="card-body row g-3">
        <div class="col-12"><label class="form-label">ชื่อ-นามสกุล <span class="text-danger">*</span></label>
          <input name="full_name" class="form-control" required value="<?= h($u['full_name']) ?>"></div>
        <div class="col-md-8"><label class="form-label">ตำแหน่ง</label><input name="position" class="form-control" value="<?= h($u['position']) ?>"></div>
        <div class="col-md-4"><label class="form-label">ชื่อเล่น</label><input name="nickname" class="form-control" maxlength="50" value="<?= h($u['nickname'] ?? '') ?>"></div>
        <div class="col-12"><label class="form-label">กลุ่มงาน</label>
          <select name="work_group_id" class="form-select"><option value="">— ไม่ระบุ —</option>
            <?php foreach (work_groups() as $gid => $gname): ?><option value="<?= (int)$gid ?>" <?= (int)$u['work_group_id'] === (int)$gid ? 'selected' : '' ?>><?= h($gname) ?></option><?php endforeach; ?>
          </select></div>
        <div class="col-md-6"><label class="form-label">อีเมล</label><input name="email" type="email" class="form-control" value="<?= h($u['email']) ?>"></div>
        <div class="col-md-6"><label class="form-label">โทรศัพท์</label><input name="phone" class="form-control" value="<?= h($u['phone']) ?>"></div>
        <div class="col-12 d-flex flex-wrap gap-4">
          <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" id="is_active" <?= $u['is_active'] ? 'checked' : '' ?> <?= $id === $me['id'] ? 'disabled checked' : '' ?>>
            <label class="form-check-label" for="is_active">เปิดใช้งานบัญชี</label></div>
          <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_superadmin" id="is_superadmin" <?= $u['is_superadmin'] ? 'checked' : '' ?> <?= $id === $me['id'] ? 'disabled checked' : '' ?>>
            <label class="form-check-label" for="is_superadmin">ผู้ดูแลระบบกลาง <small class="text-muted">(จัดการผู้ใช้/สิทธิ์ และมีสิทธิ์สูงสุดทุกระบบ)</small></label></div>
        </div>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header"><strong><i class="bi bi-key"></i> การเข้าสู่ระบบ</strong> <span class="small text-muted">— ต้องมีอย่างน้อยหนึ่งแบบ</span></div>
      <div class="card-body row g-3">
        <div class="col-md-6"><label class="form-label">ชื่อผู้ใช้ (Username)</label>
          <input name="username" class="form-control" value="<?= h($u['username']) ?>" autocomplete="off"></div>
        <div class="col-md-6"><label class="form-label">รหัสผ่าน<?= $u['has_password'] ? ' ใหม่' : '' ?></label>
          <input name="password" type="password" class="form-control" minlength="<?= PASSWORD_MIN_LENGTH ?>" placeholder="<?= $u['has_password'] ? 'เว้นว่างถ้าไม่เปลี่ยน' : 'อย่างน้อย ' . PASSWORD_MIN_LENGTH . ' ตัวอักษร' ?>" autocomplete="new-password"></div>
        <div class="col-md-6"><label class="form-label">PIN 6 หลัก <?= $u['has_pin'] ? '<span class="badge text-bg-success">ตั้งแล้ว</span>' : '' ?></label>
          <input name="pin" type="password" inputmode="numeric" pattern="\d{6}" maxlength="6" class="form-control" placeholder="<?= $u['has_pin'] ? 'เว้นว่างถ้าไม่เปลี่ยน' : 'ไม่บังคับ' ?>" autocomplete="off"></div>
        <?php if ($u['has_pin']): ?>
          <div class="col-md-6 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="remove_pin" id="remove_pin" value="1">
            <label class="form-check-label" for="remove_pin">ลบ PIN เดิม</label></div></div>
        <?php endif; ?>
        <div class="col-12 small text-muted">ผู้ใช้ที่มีเฉพาะ PIN (ไม่มีชื่อผู้ใช้) เข้าได้ทางหน้า "เข้าใช้ด้วย PIN" เท่านั้น เหมาะกับผู้ที่มาเบิกวัสดุเป็นครั้งคราว</div>
      </div>
    </div>
  </div>

  <div class="col-xl-6">
    <div class="card mb-3">
      <div class="card-header"><strong><i class="bi bi-shield-check"></i> สิทธิ์การใช้งานแต่ละระบบ</strong></div>
      <div class="card-body">
        <p class="small text-muted" id="superNote" <?= $u['is_superadmin'] ? '' : 'hidden' ?>><i class="bi bi-star-fill text-warning"></i> ผู้ดูแลระบบกลางมีสิทธิ์สูงสุดในทุกระบบอยู่แล้ว (ยกเว้นระบบที่มีป้าย <span class="badge text-bg-danger">ข้อมูลส่วนบุคคล</span> ต้องกำหนดสิทธิ์เอง)</p>
        <?php foreach ($mods as $m): ?>
          <div class="border rounded-3 p-3 mb-3">
            <div class="fw-semibold mb-2"><?= module_logo($m, 22) ?> <?= h($m['name']) ?>
              <?php if (!$m['superadmin_access']): ?><span class="badge text-bg-danger">ข้อมูลส่วนบุคคล</span><?php endif; ?></div>
            <label class="d-block mb-1"><input type="radio" name="role[<?= h($m['key']) ?>]" value="" <?= empty($roles[$m['key']]) ? 'checked' : '' ?>> <span class="text-muted"><?= $m['default_role'] ? 'ค่าเริ่มต้น — ' . h(role_label($m['key'], $m['default_role'])) . ' (ทุกคนได้อัตโนมัติ)' : 'ไม่มีสิทธิ์ใช้งาน' ?></span></label>
            <?php foreach ($m['roles'] as $rk => $rl): ?>
              <label class="d-block mb-1"><input type="radio" name="role[<?= h($m['key']) ?>]" value="<?= h($rk) ?>" <?= ($roles[$m['key']] ?? '') === $rk ? 'checked' : '' ?>> <?= h($rl) ?></label>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="col-12 sticky-actions">
    <button class="btn btn-primary btn-lg"><i class="bi bi-save"></i> บันทึก</button>
    <a href="<?= h(url('admin/users.php')) ?>" class="btn btn-light btn-lg">กลับ</a>
  </div>
</form>
<script>
document.getElementById('is_superadmin')?.addEventListener('change', e => { document.getElementById('superNote').hidden = !e.target.checked; });
</script>
<?php require APP_ROOT . '/core/layout/footer.php'; ?>
