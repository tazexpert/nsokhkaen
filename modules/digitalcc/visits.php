<?php
require_once __DIR__ . '/bootstrap.php';
require_module(DCC_MODULE, 'inspector');
$pdo = db();

// ลบการตรวจเยี่ยม (รวมกิจกรรมและภาพที่ผูกกัน)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    check_csrf();
    dcc_require('admin');              // ลบข้อมูลได้เฉพาะผู้ดูแลระบบศูนย์ฯ
    $id = int_param('id');
    $st = $pdo->prepare('SELECT activity_id FROM dcc_visits WHERE id = ?');
    $st->execute([$id]);
    $actId = (int)$st->fetchColumn();
    $files = [];
    if ($actId) {
        $st = $pdo->prepare('SELECT file FROM dcc_activity_images WHERE activity_id = ? UNION ALL SELECT cover_image FROM dcc_activities WHERE id = ?');
        $st->execute([$actId, $actId]);
        $files = $st->fetchAll(PDO::FETCH_COLUMN);
        $pdo->prepare('DELETE FROM dcc_activities WHERE id = ?')->execute([$actId]);
    }
    $pdo->prepare('DELETE FROM dcc_visits WHERE id = ?')->execute([$id]);
    array_map('delete_upload', array_filter($files));
    flash('ลบบันทึกการตรวจเยี่ยมเรียบร้อยแล้ว');
    redirect('visits.php?' . http_build_query(array_filter(['district' => post('district'), 'q' => post('q'), 'status' => post('status'), 'center_id' => post('center_id')])));
}

// ----- ตัวกรอง (ใช้ร่วมกันทั้งรายชื่อศูนย์และประวัติ) -----
$district = trim($_GET['district'] ?? '');
$q = trim($_GET['q'] ?? '');
$status = in_array($_GET['status'] ?? '', ['todo', 'done'], true) ? $_GET['status'] : '';
$centerId = int_param('center_id');

$where = [];
$params = [];
if ($district !== '') { $where[] = 'c.district = ?'; $params[] = $district; }
if ($q !== '') { $where[] = '(c.name LIKE ? OR c.code LIKE ? OR c.subdistrict LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

// รายชื่อศูนย์ + จำนวนครั้งที่ตรวจ + วันที่ตรวจล่าสุด
$st = $pdo->prepare(
    "SELECT c.id, c.code, c.name, c.subdistrict, c.district,
            COUNT(v.id) AS visit_count, MAX(v.visit_date) AS last_visit
     FROM dcc_centers c LEFT JOIN dcc_visits v ON v.center_id = c.id
     $whereSql GROUP BY c.id ORDER BY c.district, c.name"
);
$st->execute($params);
$centers = $st->fetchAll();
$total = count($centers);
$visited = count(array_filter($centers, fn($c) => $c['visit_count'] > 0));
$visitSum = array_sum(array_column($centers, 'visit_count'));
if ($status === 'todo') {
    $centers = array_values(array_filter($centers, fn($c) => $c['visit_count'] == 0));
} elseif ($status === 'done') {
    $centers = array_values(array_filter($centers, fn($c) => $c['visit_count'] > 0));
}

// ประวัติการตรวจเยี่ยม (ครั้งที่ = ลำดับการตรวจของศูนย์นั้น)
$hWhere = $where;
$hParams = $params;
if ($centerId) { $hWhere[] = 'v.center_id = ?'; $hParams[] = $centerId; }
$st = $pdo->prepare(
    "SELECT v.*, c.name AS center_name, c.code, c.district,
            (SELECT COUNT(*) FROM dcc_visits v2 WHERE v2.center_id = v.center_id
               AND (v2.visit_date < v.visit_date OR (v2.visit_date = v.visit_date AND v2.id <= v.id))) AS visit_no,
            (SELECT COUNT(*) FROM dcc_activity_images i WHERE i.activity_id = v.activity_id) AS photo_count
     FROM dcc_visits v JOIN dcc_centers c ON c.id = v.center_id" .
    ($hWhere ? ' WHERE ' . implode(' AND ', $hWhere) : '') . ' ORDER BY v.visit_date DESC, v.id DESC LIMIT 300'
);
$st->execute($hParams);
$history = $st->fetchAll();
$centerName = '';
if ($centerId) {
    $st = $pdo->prepare('SELECT name FROM dcc_centers WHERE id = ?');
    $st->execute([$centerId]);
    $centerName = (string)$st->fetchColumn();
}

$keep = array_filter(['district' => $district, 'q' => $q, 'status' => $status]);
$url = fn(array $extra = []) => 'visits.php?' . http_build_query(array_filter($extra + $keep, fn($v) => $v !== '' && $v !== 0 && $v !== null));

$pageTitle = 'ตรวจเยี่ยมศูนย์';
$active = 'visits';
require __DIR__ . '/header.php';
$pct = $total ? round($visited / $total * 100) : 0;
?>
<!-- ตัวกรอง -->
<form class="card mb-3" id="filters">
  <div class="card-body row g-2 align-items-end">
    <div class="col-md-4"><label class="form-label small mb-1">อำเภอ</label>
      <select name="district" class="form-select" onchange="this.form.submit()">
        <option value="">ทุกอำเภอ</option>
        <?php foreach (KK_DISTRICTS as $dn): ?><option <?= $dn === $district ? 'selected' : '' ?>><?= h($dn) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-4"><label class="form-label small mb-1">ค้นหาศูนย์</label>
      <input name="q" class="form-control" placeholder="ชื่อศูนย์ / รหัส / ตำบล" value="<?= h($q) ?>"></div>
    <div class="col-md-3"><label class="form-label small mb-1">สถานะ</label>
      <select name="status" class="form-select" onchange="this.form.submit()">
        <option value="">ทั้งหมด</option>
        <option value="todo" <?= $status === 'todo' ? 'selected' : '' ?>>ยังไม่ได้ตรวจเยี่ยม</option>
        <option value="done" <?= $status === 'done' ? 'selected' : '' ?>>ตรวจเยี่ยมแล้ว</option>
      </select></div>
    <div class="col-md-1 d-grid"><button class="btn btn-primary"><i class="bi bi-search"></i></button></div>
  </div>
  <div class="card-footer d-flex flex-wrap gap-3 align-items-center small">
    <span><strong><?= $visited ?> / <?= $total ?></strong> ศูนย์ตรวจเยี่ยมแล้ว<?= $district ? ' ใน อ.' . h($district) : '' ?></span>
    <span class="bar-track flex-grow-1" style="max-width:260px"><span class="bar-fill" style="width:<?= $pct ?>%"></span></span>
    <span><?= $pct ?>%</span>
    <span class="text-muted">· รวมการตรวจเยี่ยม <?= number_format($visitSum) ?> ครั้ง</span>
    <?php if ($keep): ?><a href="visits.php" class="ms-auto">ล้างตัวกรอง</a><?php endif; ?>
  </div>
</form>

<div class="row g-3">
  <!-- รายชื่อศูนย์ -->
  <div class="col-xl-6">
    <div class="card h-100" id="centers">
      <div class="card-header d-flex"><strong><i class="bi bi-building"></i> ศูนย์ดิจิทัลชุมชน (<?= count($centers) ?>)</strong>
        <span class="ms-auto small text-muted">กด "ตรวจเยี่ยม" เพื่อบันทึกครั้งใหม่</span></div>
      <div class="list-group list-group-flush visit-centers">
        <?php foreach ($centers as $c): ?>
          <div class="list-group-item d-flex align-items-center gap-2 <?= $centerId === (int)$c['id'] ? 'list-group-item-primary' : '' ?>">
            <div class="flex-grow-1 min-w-0">
              <div class="fw-semibold text-truncate"><?= h($c['name']) ?></div>
              <div class="small text-muted"><?= h(($c['code'] ? $c['code'] . ' · ' : '') . 'ต.' . $c['subdistrict'] . ' อ.' . $c['district']) ?></div>
              <div class="small">
                <?php if ($c['visit_count'] > 0): ?>
                  <span class="badge text-bg-success">ตรวจแล้ว <?= (int)$c['visit_count'] ?> ครั้ง</span>
                  <span class="text-muted">ล่าสุด <?= thai_date($c['last_visit']) ?></span>
                <?php else: ?>
                  <span class="badge text-bg-warning">ยังไม่ได้ตรวจเยี่ยม</span>
                <?php endif; ?>
              </div>
            </div>
            <?php if ($c['visit_count'] > 0): ?>
              <a href="<?= h($url(['center_id' => (int)$c['id']])) ?>#history" class="btn btn-sm btn-light text-nowrap" title="ดูประวัติ"><i class="bi bi-clock-history"></i></a>
            <?php endif; ?>
            <a href="visit_form.php?center_id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-success text-nowrap"><i class="bi bi-clipboard-plus"></i> ตรวจเยี่ยม<?= $c['visit_count'] > 0 ? ' ครั้งที่ ' . ($c['visit_count'] + 1) : '' ?></a>
          </div>
        <?php endforeach; ?>
        <?php if (!$centers): ?><div class="list-group-item text-center text-muted py-4">ไม่พบศูนย์ตามเงื่อนไข</div><?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ประวัติการตรวจเยี่ยม -->
  <div class="col-xl-6">
    <div class="card h-100" id="history">
      <div class="card-header d-flex align-items-center">
        <strong><i class="bi bi-clock-history"></i> ประวัติการตรวจเยี่ยม<?= $centerName ? ' · ' . h($centerName) : '' ?> (<?= count($history) ?>)</strong>
        <?php if ($centerId): ?><a href="<?= h($url()) ?>#history" class="ms-auto small">แสดงทุกศูนย์</a><?php endif; ?>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead><tr><th>วันที่</th><th>ศูนย์</th><th class="text-center">ภาพ</th><th class="text-end"></th></tr></thead>
          <tbody>
          <?php foreach ($history as $r): ?>
            <tr>
              <td class="text-nowrap"><?= thai_date($r['visit_date']) ?><?= $r['visit_time'] ? '<div class="small text-muted">' . h(substr($r['visit_time'], 0, 5)) . ' น.</div>' : '' ?></td>
              <td><a href="visit_view.php?id=<?= (int)$r['id'] ?>" class="fw-semibold text-decoration-none"><?= h($r['center_name']) ?></a>
                <span class="badge text-bg-light border">ครั้งที่ <?= (int)$r['visit_no'] ?></span>
                <div class="small text-muted"><?= h(($r['code'] ? $r['code'] . ' · ' : '') . 'อ.' . $r['district']) ?><?= $r['inspector_name'] ? ' · ' . h($r['inspector_name']) : '' ?></div></td>
              <td class="text-center"><?= (int)$r['photo_count'] ?></td>
              <td class="text-end text-nowrap">
                <a href="visit_view.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-light" title="ดู/พิมพ์"><i class="bi bi-printer"></i></a>
                <a href="visit_form.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-light" title="แก้ไข"><i class="bi bi-pencil"></i></a>
                <?php if (dcc_can('admin')): ?>
                <form method="post" class="d-inline" data-confirm="ลบบันทึกการตรวจเยี่ยมครั้งที่ <?= (int)$r['visit_no'] ?> ของ <?= h($r['center_name']) ?> และภาพทั้งหมด?">
                  <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <?php foreach ($keep + ['center_id' => $centerId ?: ''] as $k => $val): ?><input type="hidden" name="<?= $k ?>" value="<?= h($val) ?>"><?php endforeach; ?>
                  <button class="btn btn-sm btn-light text-danger" title="ลบ"><i class="bi bi-trash"></i></button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$history): ?><tr><td colspan="4" class="text-center text-muted py-5">ยังไม่มีบันทึกการตรวจเยี่ยม</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
