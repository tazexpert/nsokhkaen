<?php
/**
 * สถานประกอบการของโครงการ (ea_project_units) — เช่น โครงการสำรวจยอดขายรายไตรมาส
 * - ดูรายชื่อแยกรอบ/งวด · สรุปรายผู้ปฏิบัติงาน · ส่งออก CSV
 * - เจ้าหน้าที่โครงการ (editor): เลือกผู้ปฏิบัติงานและสถานะจากตารางได้ทันที (บันทึกอัตโนมัติ) · ลบรายการ
 * นำเข้าจากบัญชีมอบหมายแบบสถานประกอบการที่หน้า "นำเข้า EA โครงการ"
 */
require_once __DIR__ . '/bootstrap.php';
$me = require_module(EA_MODULE, 'viewer');
$pdo = db();
$pid = int_param('id');
$project = projects_all()[$pid] ?? null;
if (!$project) {
    flash('ไม่พบโครงการ', 'warning');
    redirect('projects.php');
}
$canEdit = ea_can('editor');

// รอบ/งวดของสถานประกอบการในโครงการ
$st = $pdo->prepare('SELECT round_label, COUNT(*) n FROM ea_project_units WHERE project_id = ? GROUP BY round_label
                     ORDER BY MIN(enum_year) IS NULL, MIN(enum_year), MIN(enum_type), MIN(enum_month), round_label');
$st->execute([$pid]);
$rounds = $st->fetchAll(PDO::FETCH_KEY_PAIR);
$round = isset($_GET['round']) ? mb_substr((string)$_GET['round'], 0, 50) : (string)(array_key_last($rounds) ?? '');
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$fStatus = isset(EA_STATUSES[$_GET['status'] ?? '']) ? $_GET['status'] : '';
$fUser = ($_GET['user'] ?? '') === 'none' ? 'none' : (ctype_digit((string)($_GET['user'] ?? '')) ? (int)$_GET['user'] : '');

// แก้ไขจากตาราง (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    ea_require('editor');
    $id = int_param('unit_id');
    $st = $pdo->prepare('SELECT * FROM ea_project_units WHERE id = ? AND project_id = ?');
    $st->execute([$id, $pid]);
    $u = $st->fetch();
    if (!$u) {
        json_out(['ok' => false, 'error' => 'ไม่พบสถานประกอบการ'], 404);
    }
    $action = post('action');
    if ($action === 'set_assignee') {
        $staff = ea_staff();
        $uid = ctype_digit(post('user')) && isset($staff[(int)post('user')]) ? (int)post('user') : null;
        if (($u['assigned_user_id'] === null ? null : (int)$u['assigned_user_id']) !== $uid) {
            $pdo->prepare('UPDATE ea_project_units SET assigned_user_id = ?, assigned_at = IF(? IS NULL, NULL, NOW()), assigned_by = ? WHERE id = ?')
                ->execute([$uid, $uid, (int)$me['id'], $id]);
            audit_log('ea_unit_assign', 'ผู้ปฏิบัติงานสถานประกอบการ "' . $u['name'] . '" โครงการ ' . $project['code'] . ': ' . ($uid ? $staff[$uid]['full_name'] : 'ไม่กำหนด'), EA_MODULE);
        }
        json_out(['ok' => true]);
    }
    if ($action === 'set_status' && isset(EA_STATUSES[post('status')])) {
        $s = post('status');
        $pdo->prepare("UPDATE ea_project_units SET status = ?, done_at = IF(? = 'done', COALESCE(done_at, NOW()), NULL) WHERE id = ?")->execute([$s, $s, $id]);
        json_out(['ok' => true]);
    }
    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM ea_project_units WHERE id = ?')->execute([$id]);
        audit_log('ea_unit_delete', 'ลบสถานประกอบการ "' . $u['name'] . '" โครงการ ' . $project['code'] . ' ' . $u['round_label'], EA_MODULE);
        flash('ลบ "' . $u['name'] . '" แล้ว');
        redirect('project_units.php?' . http_build_query(['id' => $pid, 'round' => $u['round_label']]));
    }
    json_out(['ok' => false, 'error' => 'คำสั่งไม่ถูกต้อง'], 400);
}

$where = ['u.project_id = ?', 'u.round_label = ?'];
$params = [$pid, $round];
if ($q !== '') {
    $where[] = '(u.name LIKE ? OR u.road LIKE ? OR u.tambon_name LIKE ? OR u.district_name LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
}
if ($fStatus !== '') {
    $where[] = 'u.status = ?';
    $params[] = $fStatus;
}
if ($fUser === 'none') {
    $where[] = 'u.assigned_user_id IS NULL';
} elseif ($fUser !== '') {
    $where[] = 'u.assigned_user_id = ?';
    $params[] = $fUser;
}
$st = $pdo->prepare('SELECT u.*, a.ea_code FROM ea_project_units u LEFT JOIN ea_areas a ON a.id = u.ea_id WHERE ' . implode(' AND ', $where) . ' ORDER BY u.seq, u.id');
$st->execute($params);
$rows = $st->fetchAll();
$names = [];
foreach ($pdo->query('SELECT id, full_name FROM core_users') as $u) {
    $names[(int)$u['id']] = $u['full_name'];
}

if (($_GET['export'] ?? '') === 'csv') {
    $out = ea_csv_start('units_' . $project['code'] . '_' . preg_replace('/[^\p{L}\p{N}_-]+/u', '_', $round) . '_' . date('Ymd') . '.csv');
    fputcsv($out, ['ที่', 'โครงการ', 'รอบ/งวด', 'คำนำหน้า', 'ชื่อสถานประกอบการ', 'EA', 'รหัส EA', 'หมู่ที่', 'เลขที่', 'ถนน', 'ตำบล', 'อำเภอ',
                   'ผู้ปฏิบัติงาน', 'สถานะ', 'วันที่สำรวจเสร็จ', 'หมายเหตุ']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['seq'], $project['code'], $r['round_label'], $r['prefix'], $r['name'], $r['ea_no'] !== '' ? '="' . $r['ea_no'] . '"' : '',
                       $r['ea_code'] ? '="' . $r['ea_code'] . '"' : '', $r['moo'], $r['house_no'], $r['road'], $r['tambon_name'], $r['district_name'],
                       $r['assigned_user_id'] ? ($names[(int)$r['assigned_user_id']] ?? '') : '', EA_STATUSES[$r['status']][0] ?? $r['status'],
                       $r['done_at'] ? thai_date(substr($r['done_at'], 0, 10)) : '', $r['note']]);
    }
    fclose($out);
    exit;
}

// สรุปรายผู้ปฏิบัติงาน (ทั้งรอบ ไม่ขึ้นกับตัวกรอง)
$st = $pdo->prepare("SELECT assigned_user_id uid, COUNT(*) n, SUM(status = 'done') done, SUM(status = 'in_progress') doing
                     FROM ea_project_units WHERE project_id = ? AND round_label = ? GROUP BY assigned_user_id");
$st->execute([$pid, $round]);
$byUser = [];
foreach ($st as $r) {
    $byUser[(int)$r['uid']] = $r;
}
$sorted = [];
foreach (ea_staff() as $uid => $u) {
    if (isset($byUser[$uid])) {
        $sorted[$uid] = $byUser[$uid];
    }
}
$sorted += $byUser;
$total = array_sum(array_column($byUser, 'n'));
$done = array_sum(array_column($byUser, 'done'));
$query = ['id' => $pid, 'round' => $round, 'q' => $q, 'status' => $fStatus, 'user' => $fUser];
$qs = fn(array $over = []) => http_build_query(array_filter($over + $query, fn($v) => $v !== '' && $v !== null));

$pageTitle = 'สถานประกอบการ ' . $project['code'];
$active = 'projects';
$st = $pdo->prepare('SELECT COUNT(*) FROM ea_project_eas WHERE project_id = ?');
$st->execute([$pid]);
$eaCount = (int)$st->fetchColumn();
$pageActions = '<a href="projects.php" class="btn btn-light"><i class="bi bi-arrow-left"></i> ทุกโครงการ</a>'
    . ' <a href="project_eas.php?id=' . $pid . '&amp;ea=1" class="btn btn-outline-secondary"><i class="bi bi-map"></i> ' . ($eaCount ? 'EA ของโครงการ (' . number_format($eaCount) . ')' : 'เพิ่ม EA ให้โครงการ') . '</a>'
    . ($rows ? ' <a href="?' . h($qs(['export' => 'csv'])) . '" class="btn btn-outline-success"><i class="bi bi-filetype-csv"></i> Export CSV</a>' : '')
    . ($canEdit ? ' <a href="project_import.php" class="btn btn-outline-primary"><i class="bi bi-file-earmark-arrow-up"></i> นำเข้าไฟล์</a>' : '');
require __DIR__ . '/header.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <span class="fw-semibold" style="color:<?= h($project['color']) ?>"><?= h($project['name']) ?></span>
  <?php if (($project['survey_period'] ?? '') !== ''): ?><span class="text-muted small">· <?= h(project_period_label($project['survey_period'])) ?></span><?php endif; ?>
  <?php if ($rounds): ?>
  <select class="form-select form-select-sm w-auto ms-auto" data-goto title="รอบ/งวด">
    <?php foreach ($rounds as $rl => $cnt): ?><option value="project_units.php?<?= h(http_build_query(['id' => $pid, 'round' => (string)$rl])) ?>" <?= (string)$rl === $round ? 'selected' : '' ?>><?= h((string)$rl ?: 'ไม่ระบุรอบ') ?> (<?= number_format($cnt) ?>)</option><?php endforeach; ?>
  </select>
  <?php endif; ?>
</div>

<?php if (!$rounds): ?>
  <div class="alert alert-info">ยังไม่มีสถานประกอบการในโครงการนี้<?= $canEdit ? ' — <a href="project_import.php" class="alert-link">นำเข้าบัญชีมอบหมายสถานประกอบการ</a>' : '' ?></div>
<?php else: ?>
<div class="row g-3 mb-3" data-reveal>
  <?php foreach ([
      ['สถานประกอบการ', $total, 'bi-shop', 'primary'],
      ['ผู้ปฏิบัติงาน', count(array_filter(array_keys($byUser))), 'bi-people', 'info'],
      ['สำรวจเสร็จ', $done, 'bi-check2-circle', 'success'],
      ['ยังไม่กำหนดผู้ปฏิบัติงาน', (int)($byUser[0]['n'] ?? 0), 'bi-person-dash', (int)($byUser[0]['n'] ?? 0) ? 'danger' : 'secondary'],
  ] as [$label, $v, $icon, $color]): ?>
  <div class="col-6 col-lg-3 reveal-item">
    <div class="stat-card"><span class="stat-icon text-bg-<?= $color ?>"><i class="bi <?= $icon ?>"></i></span>
      <div><div class="stat-num"><?= number_format($v) ?></div><div class="stat-label"><?= $label ?><?= $label === 'สำรวจเสร็จ' && $total ? ' · ' . round($v / $total * 100) . '%' : '' ?></div></div></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card mb-3" data-reveal>
  <div class="card-header"><strong>ผู้ปฏิบัติงาน</strong> <span class="small text-muted">— คลิกชื่อเพื่อกรอง · <span class="ea-legend"><i style="background:#22c55e"></i>สำรวจเสร็จ <i style="background:#f59e0b" class="ms-2"></i>กำลังสำรวจ</span></span></div>
  <div class="card-body reveal-item"><div class="row g-2">
    <?php foreach ($sorted as $uid => $r): ?>
    <div class="col-md-6 col-xl-4">
      <div class="d-flex small"><a href="?<?= h($qs(['user' => $uid ?: 'none'])) ?>" class="text-decoration-none text-truncate<?= $uid ? '' : ' text-danger' ?>"><?= h($uid ? ($names[$uid] ?? 'ผู้ใช้ #' . $uid) : 'ยังไม่กำหนด') ?></a>
        <span class="ms-auto ps-2 text-nowrap"><?= (int)$r['done'] ?>/<?= (int)$r['n'] ?></span></div>
      <div class="ea-progress"><span class="done" style="width:<?= $r['done'] / $r['n'] * 100 ?>%"></span><span class="doing" style="width:<?= $r['doing'] / $r['n'] * 100 ?>%"></span></div>
    </div>
    <?php endforeach; ?>
  </div></div>
</div>

<div class="card">
  <div class="card-header">
    <form class="row g-2 align-items-center">
      <input type="hidden" name="id" value="<?= $pid ?>"><input type="hidden" name="round" value="<?= h($round) ?>">
      <div class="col-md-4"><input name="q" class="form-control" placeholder="ชื่อสถานประกอบการ / ถนน / ตำบล / อำเภอ" value="<?= h($q) ?>"></div>
      <div class="col-md-2 col-6"><select name="status" class="form-select"><option value="">ทุกสถานะ</option>
        <?php foreach (EA_STATUSES as $k => [$l]): ?><option value="<?= $k ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-3 col-6"><select name="user" class="form-select"><option value="">ผู้ปฏิบัติงานทุกคน</option>
        <option value="none" <?= $fUser === 'none' ? 'selected' : '' ?>>ยังไม่กำหนด</option><?= ea_staff_options(is_int($fUser) ? $fUser : null) ?></select></div>
      <div class="col-auto"><button class="btn btn-outline-secondary"><i class="bi bi-search"></i> ค้นหา</button></div>
      <?php if ($q !== '' || $fStatus !== '' || $fUser !== ''): ?><div class="col-auto"><a href="?<?= h(http_build_query(['id' => $pid, 'round' => $round])) ?>" class="btn btn-link">ล้างตัวกรอง</a></div><?php endif; ?>
      <div class="col-auto ms-auto small text-muted"><?= number_format(count($rows)) ?> รายการ</div>
    </form>
  </div>
  <?php if ($canEdit): ?><input type="hidden" id="unitCsrf" value="<?= h(csrf_token()) ?>"><?php endif; ?>
  <div class="table-responsive">
    <table class="table table-hover mb-0 ea-table align-middle">
      <thead><tr><th>#</th><th>สถานประกอบการ</th><th>EA</th><th>ที่ตั้ง</th><th style="min-width:200px">ผู้ปฏิบัติงาน</th><th>สถานะ</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $au = $r['assigned_user_id'] ? (int)$r['assigned_user_id'] : null; ?>
        <tr>
          <td class="small text-muted"><?= (int)$r['seq'] ?></td>
          <td><?= $r['prefix'] !== '' ? '<span class="text-muted small">' . h($r['prefix']) . '</span> ' : '' ?><?= h($r['name']) ?></td>
          <td class="small text-nowrap"><?php if ($r['ea_code']): ?><a href="ea_view.php?id=<?= (int)$r['ea_id'] ?>" class="ea-code text-decoration-none" title="<?= h($r['ea_code']) ?>"><?= h($r['ea_no']) ?></a>
            <?php else: ?><span class="ea-code text-muted"><?= h($r['ea_no'] ?: '-') ?></span><?php endif; ?></td>
          <td class="small"><?= h(ea_unit_address($r)) ?></td>
          <?php if ($canEdit): ?>
          <td><select class="form-select form-select-sm unit-set" data-unit="<?= (int)$r['id'] ?>" data-action="set_assignee" aria-label="ผู้ปฏิบัติงาน">
            <option value="">— ไม่กำหนด —</option><?= ea_staff_options($au) ?>
            <?php if ($au && !isset(ea_staff()[$au])): ?><option value="<?= $au ?>" selected disabled><?= h($names[$au] ?? '') ?> (ปิดบัญชีแล้ว)</option><?php endif; ?></select></td>
          <td><select class="form-select form-select-sm unit-set" data-unit="<?= (int)$r['id'] ?>" data-action="set_status" aria-label="สถานะ">
            <?php foreach (EA_STATUSES as $k => [$l]): ?><option value="<?= $k ?>" <?= $r['status'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></td>
          <td class="text-end"><form method="post" class="d-inline" data-confirm="ลบ &quot;<?= h($r['name']) ?>&quot; ออกจากรอบนี้?"><?= csrf_field() ?>
            <input type="hidden" name="action" value="delete"><input type="hidden" name="unit_id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-sm btn-light text-danger" title="ลบ"><i class="bi bi-trash"></i></button></form></td>
          <?php else: ?>
          <td class="small"><?= $au ? h($names[$au] ?? '') : '<span class="text-muted">-</span>' ?></td>
          <td><?= ea_status_badge($r['status']) ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-5">ไม่พบสถานประกอบการตามเงื่อนไข</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if ($canEdit): ?>
<style>
  .unit-set { min-width: 150px; }
  .unit-set.is-saving { opacity: .6; }
  .unit-set.is-saved { border-color: #22c55e; box-shadow: 0 0 0 .2rem rgba(34, 197, 94, .2); }
</style>
<script>
(() => {
  // เลือกผู้ปฏิบัติงาน/สถานะจากตาราง → บันทึกทันที (ไม่สำเร็จ = คืนค่าเดิม)
  const csrf = document.getElementById('unitCsrf').value;
  document.querySelectorAll('.unit-set').forEach(sel => {
    let prev = sel.value;
    sel.addEventListener('change', async () => {
      sel.classList.add('is-saving');
      const fd = new FormData();
      fd.set('csrf', csrf);
      fd.set('action', sel.dataset.action);
      fd.set('unit_id', sel.dataset.unit);
      fd.set(sel.dataset.action === 'set_status' ? 'status' : 'user', sel.value);
      try {
        const r = await fetch(location.href, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const j = await r.json();
        if (!j.ok) { throw new Error(j.error || 'บันทึกไม่สำเร็จ'); }
        prev = sel.value;
        sel.classList.add('is-saved');
        setTimeout(() => sel.classList.remove('is-saved'), 1500);
      } catch (e) {
        alert('บันทึกไม่สำเร็จ: ' + e.message);
        sel.value = prev;
      } finally {
        sel.classList.remove('is-saving');
      }
    });
  });
})();
</script>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
