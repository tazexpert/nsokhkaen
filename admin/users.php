<?php
/** ผู้ใช้งานและสิทธิ์ (ผู้ดูแลระบบกลาง) */
require_once __DIR__ . '/../core/bootstrap.php';
$me = require_superadmin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $id = int_param('id');
    $action = post('action');
    if ($id === $me['id']) {
        flash('ไม่สามารถปิดการใช้งานหรือลบบัญชีของตนเองได้', 'danger');
    } elseif ($action === 'toggle') {
        $pdo->prepare('UPDATE core_users SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
        audit_log('user_toggle_active', 'user #' . $id);
        flash('เปลี่ยนสถานะผู้ใช้งานเรียบร้อยแล้ว');
    } elseif ($action === 'delete') {
        try {
            $pdo->prepare('DELETE FROM core_users WHERE id = ?')->execute([$id]);
            audit_log('user_delete', 'user #' . $id);
            flash('ลบผู้ใช้งานเรียบร้อยแล้ว');
        } catch (PDOException $e) {
            // มีประวัติทำรายการในระบบย่อย (FK RESTRICT)
            flash('ไม่สามารถลบผู้ใช้งานนี้ได้เนื่องจากมีประวัติการทำรายการอยู่ กรุณาใช้ "ปิดการใช้งาน" แทน', 'danger');
        }
    }
    redirect(url('admin/users.php?' . http_build_query(array_filter(['q' => post('q'), 'module' => post('module'), 'group' => post('group')]))));
}

$q = trim($_GET['q'] ?? '');
$filterModule = trim($_GET['module'] ?? '');
$filterGroup = int_param('group');
$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(u.username LIKE ? OR u.full_name LIKE ? OR u.position LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%");
}
if ($filterGroup > 0) {
    $where[] = 'u.work_group_id = ?';
    $params[] = $filterGroup;
} elseif ($filterGroup === -1) {
    $where[] = 'u.work_group_id IS NULL';
}
if ($filterModule === 'none') {
    $where[] = 'u.is_superadmin = 0 AND NOT EXISTS (SELECT 1 FROM core_user_roles r WHERE r.user_id = u.id)';
} elseif ($filterModule !== '' && module_def($filterModule)) {
    $where[] = '(' . (module_def($filterModule)['superadmin_access'] ? 'u.is_superadmin = 1 OR ' : '') . 'EXISTS (SELECT 1 FROM core_user_roles r WHERE r.user_id = u.id AND r.module_key = ?))';
    $params[] = $filterModule;
}
// เรียงตามชื่อผู้ใช้: ขึ้นต้นด้วยตัวเลขก่อน (เรียงแบบตัวเลข) แล้วตามด้วยตัวอักษร ผู้ที่ไม่มีชื่อผู้ใช้อยู่ท้ายสุด
$st = $pdo->prepare('SELECT u.*, (u.pin_hash IS NOT NULL) AS has_pin, g.name AS work_group
    FROM core_users u LEFT JOIN core_work_groups g ON g.id = u.work_group_id'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . " ORDER BY u.username IS NULL, u.username REGEXP '^[0-9]' DESC,
               CASE WHEN u.username REGEXP '^[0-9]+$' THEN CAST(u.username AS UNSIGNED) END, u.username, u.full_name");
$st->execute($params);
$users = $st->fetchAll();
$roles = [];
foreach ($pdo->query('SELECT user_id, module_key, role FROM core_user_roles') as $r) {
    $roles[$r['user_id']][$r['module_key']] = $r['role'];
}
$mods = modules();

$pageTitle = 'ผู้ใช้งานและสิทธิ์';
$active = 'users';
require APP_ROOT . '/core/layout/header.php';
?>
<div class="card">
  <div class="card-header">
    <form class="row g-2 align-items-center">
      <div class="col-md-4"><input name="q" class="form-control" placeholder="ค้นหาชื่อ / ชื่อผู้ใช้ / ตำแหน่ง" value="<?= h($q) ?>"></div>
      <div class="col-md-3">
        <select name="module" class="form-select" onchange="this.form.submit()">
          <option value="">ทุกระบบ</option>
          <?php foreach ($mods as $m): ?><option value="<?= h($m['key']) ?>" <?= $filterModule === $m['key'] ? 'selected' : '' ?>>มีสิทธิ์: <?= h($m['short']) ?></option><?php endforeach; ?>
          <option value="none" <?= $filterModule === 'none' ? 'selected' : '' ?>>ยังไม่มีสิทธิ์ระบบใด</option>
        </select>
      </div>
      <div class="col-md-3">
        <select name="group" class="form-select" onchange="this.form.submit()">
          <option value="">ทุกกลุ่มงาน</option>
          <?php foreach (work_groups() as $gid => $gname): ?><option value="<?= (int)$gid ?>" <?= $filterGroup === (int)$gid ? 'selected' : '' ?>><?= h($gname) ?></option><?php endforeach; ?>
          <option value="-1" <?= $filterGroup === -1 ? 'selected' : '' ?>>ยังไม่ระบุกลุ่มงาน</option>
        </select>
      </div>
      <div class="col-auto"><button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button></div>
      <div class="col-auto ms-auto"><a href="<?= h(url('admin/user_form.php')) ?>" class="btn btn-primary"><i class="bi bi-person-plus"></i> เพิ่มผู้ใช้งาน</a></div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr>
        <th>ชื่อผู้ใช้ <i class="bi bi-sort-alpha-down"></i></th><th>ชื่อ-นามสกุล</th><th>กลุ่มงาน</th><th class="text-center">PIN</th>
        <?php foreach ($mods as $m): ?><th class="text-nowrap"><?= module_logo($m, 18) ?> <?= h($m['short']) ?></th><?php endforeach; ?>
        <th class="text-end"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr class="<?= $u['is_active'] ? '' : 'table-secondary text-muted' ?>">
          <td><?= $u['username'] ? '<span class="font-monospace">' . h($u['username']) . '</span>' : '<span class="text-muted small">— PIN อย่างเดียว</span>' ?></td>
          <td><a href="<?= h(url('admin/user_form.php?id=' . (int)$u['id'])) ?>" class="fw-semibold text-decoration-none"><?= h($u['full_name']) ?></a>
            <?php if ($u['is_superadmin']): ?><span class="badge text-bg-warning">ผู้ดูแลระบบกลาง</span><?php endif; ?>
            <?php if (!$u['is_active']): ?><span class="badge text-bg-secondary">ปิดการใช้งาน</span><?php endif; ?>
            <div class="small text-muted"><?= h($u['position']) ?></div></td>
          <td class="small"><?= $u['work_group'] ? h($u['work_group']) : '<span class="text-muted">-</span>' ?></td>
          <td class="text-center"><?= $u['has_pin'] ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<span class="text-muted">-</span>' ?></td>
          <?php foreach ($mods as $m): $r = $u['is_superadmin'] && $m['superadmin_access'] ? top_role($m['key']) : ($roles[$u['id']][$m['key']] ?? null); ?>
            <td class="small"><?= $r ? h(role_short($m['key'], $r)) : ($m['default_role'] ? '<span class="text-muted">' . h(role_short($m['key'], $m['default_role'])) . ' (ทุกคน)</span>' : '<span class="text-muted">-</span>') ?></td>
          <?php endforeach; ?>
          <td class="text-end text-nowrap">
            <a href="<?= h(url('admin/user_form.php?id=' . (int)$u['id'])) ?>" class="btn btn-sm btn-light" title="แก้ไข"><i class="bi bi-pencil"></i></a>
            <?php if ((int)$u['id'] !== $me['id']): ?>
              <form method="post" class="d-inline"><?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <input type="hidden" name="q" value="<?= h($q) ?>"><input type="hidden" name="module" value="<?= h($filterModule) ?>"><input type="hidden" name="group" value="<?= $filterGroup ?: '' ?>">
                <button class="btn btn-sm btn-light" title="<?= $u['is_active'] ? 'ปิดการใช้งาน' : 'เปิดการใช้งาน' ?>"><i class="bi <?= $u['is_active'] ? 'bi-toggle-on text-success' : 'bi-toggle-off' ?>"></i></button>
              </form>
              <form method="post" class="d-inline" data-confirm="ลบผู้ใช้ <?= h($u['full_name']) ?>?"><?= csrf_field() ?>
                <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <button class="btn btn-sm btn-light text-danger" title="ลบ"><i class="bi bi-trash"></i></button>
              </form>
            <?php else: ?><span class="badge text-bg-light border">คุณ</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$users): ?><tr><td colspan="<?= 5 + count($mods) ?>" class="text-center text-muted py-5">ไม่พบผู้ใช้งาน</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer small text-muted">ทั้งหมด <?= count($users) ?> คน · ผู้ใช้ที่ลบไม่ได้เพราะมีประวัติทำรายการ ให้ใช้ปุ่มปิดการใช้งานแทน</div>
</div>
<?php require APP_ROOT . '/core/layout/footer.php'; ?>
