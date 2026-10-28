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
    redirect(url('admin/users.php?' . http_build_query(array_filter(['q' => post('q'), 'module' => post('module')]))));
}

$q = trim($_GET['q'] ?? '');
$filterModule = trim($_GET['module'] ?? '');
$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(u.username LIKE ? OR u.full_name LIKE ? OR u.position LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%");
}
if ($filterModule === 'none') {
    $where[] = 'u.is_superadmin = 0 AND NOT EXISTS (SELECT 1 FROM core_user_roles r WHERE r.user_id = u.id)';
} elseif ($filterModule !== '' && module_def($filterModule)) {
    $where[] = '(u.is_superadmin = 1 OR EXISTS (SELECT 1 FROM core_user_roles r WHERE r.user_id = u.id AND r.module_key = ?))';
    $params[] = $filterModule;
}
$st = $pdo->prepare('SELECT u.*, (u.pin_hash IS NOT NULL) AS has_pin FROM core_users u'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY u.is_active DESC, u.full_name');
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
      <div class="col-auto"><button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button></div>
      <div class="col-auto ms-auto"><a href="<?= h(url('admin/user_form.php')) ?>" class="btn btn-primary"><i class="bi bi-person-plus"></i> เพิ่มผู้ใช้งาน</a></div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr>
        <th>ชื่อ-นามสกุล</th><th>ชื่อผู้ใช้</th><th class="text-center">PIN</th>
        <?php foreach ($mods as $m): ?><th><i class="bi <?= h($m['icon']) ?>"></i> <?= h($m['short']) ?></th><?php endforeach; ?>
        <th class="text-end"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr class="<?= $u['is_active'] ? '' : 'table-secondary text-muted' ?>">
          <td><a href="<?= h(url('admin/user_form.php?id=' . (int)$u['id'])) ?>" class="fw-semibold text-decoration-none"><?= h($u['full_name']) ?></a>
            <?php if ($u['is_superadmin']): ?><span class="badge text-bg-warning">ผู้ดูแลระบบกลาง</span><?php endif; ?>
            <?php if (!$u['is_active']): ?><span class="badge text-bg-secondary">ปิดการใช้งาน</span><?php endif; ?>
            <div class="small text-muted"><?= h($u['position']) ?></div></td>
          <td><?= $u['username'] ? h($u['username']) : '<span class="text-muted small">— PIN อย่างเดียว</span>' ?></td>
          <td class="text-center"><?= $u['has_pin'] ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<span class="text-muted">-</span>' ?></td>
          <?php foreach ($mods as $m): $r = $u['is_superadmin'] ? top_role($m['key']) : ($roles[$u['id']][$m['key']] ?? null); ?>
            <td class="small"><?= $r ? h(role_short($m['key'], $r)) : '<span class="text-muted">-</span>' ?></td>
          <?php endforeach; ?>
          <td class="text-end text-nowrap">
            <a href="<?= h(url('admin/user_form.php?id=' . (int)$u['id'])) ?>" class="btn btn-sm btn-light" title="แก้ไข"><i class="bi bi-pencil"></i></a>
            <?php if ((int)$u['id'] !== $me['id']): ?>
              <form method="post" class="d-inline"><?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <input type="hidden" name="q" value="<?= h($q) ?>"><input type="hidden" name="module" value="<?= h($filterModule) ?>">
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
      <?php if (!$users): ?><tr><td colspan="<?= 4 + count($mods) ?>" class="text-center text-muted py-5">ไม่พบผู้ใช้งาน</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer small text-muted">ทั้งหมด <?= count($users) ?> คน · ผู้ใช้ที่ลบไม่ได้เพราะมีประวัติทำรายการ ให้ใช้ปุ่มปิดการใช้งานแทน</div>
</div>
<?php require APP_ROOT . '/core/layout/footer.php'; ?>
