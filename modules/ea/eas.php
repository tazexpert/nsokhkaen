<?php
/** บัญชีเขตแจงนับ (EA) — ค้นหา/กรองตามอำเภอ ตำบล เขตการปกครอง โครงการ · ส่งออก CSV */
require_once __DIR__ . '/bootstrap.php';
require_module(EA_MODULE, 'viewer');
$pdo = db();
$f = ea_filter_input();
$project = int_param('project');
$status = in_array($_GET['status'] ?? '', ['active', 'inactive', 'all'], true) ? $_GET['status'] : 'active';
[$where, $params] = ea_filter_sql($f);
if ($status !== 'all') {
    $where[] = 'a.is_active = ' . ($status === 'active' ? 1 : 0);
}
if ($project) {
    $where[] = 'EXISTS (SELECT 1 FROM ea_project_eas pe WHERE pe.ea_id = a.id AND pe.project_id = ?)';
    $params[] = $project;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

if (($_GET['export'] ?? '') === 'csv') {
    $st = $pdo->prepare('SELECT a.* FROM ea_areas a' . $whereSql . ' ORDER BY a.ea_code');
    $st->execute($params);
    $out = ea_csv_start('ea_khonkaen_' . date('Ymd') . '.csv');
    fputcsv($out, array_merge(EA_CSV_HEAD, ['สถานะ']));
    foreach ($st as $r) {
        fputcsv($out, array_merge(ea_csv_row($r), [$r['is_active'] ? 'ใช้งาน' : 'เลิกใช้']));
    }
    fclose($out);
    exit;
}

$st = $pdo->prepare('SELECT COUNT(*) n, COALESCE(SUM(houses), 0) houses, COALESCE(SUM(households), 0) households, COALESCE(SUM(population), 0) population
                     FROM ea_areas a' . $whereSql);
$st->execute($params);
$tot = $st->fetch();
$page = max(1, int_param('page', 1));
$pages = max(1, (int)ceil($tot['n'] / EA_PER_PAGE));
$page = min($page, $pages);
$st = $pdo->prepare('SELECT a.*, (SELECT COUNT(DISTINCT pe.project_id) FROM ea_project_eas pe WHERE pe.ea_id = a.id) AS project_count
                     FROM ea_areas a' . $whereSql . ' ORDER BY a.ea_code LIMIT ' . EA_PER_PAGE . ' OFFSET ' . (($page - 1) * EA_PER_PAGE));
$st->execute($params);
$rows = $st->fetchAll();
$projects = projects_all();
$query = $f + ['project' => $project ?: '', 'status' => $status === 'active' ? '' : $status];

$pageTitle = 'บัญชี EA';
$active = 'eas';
$pageActions = (ea_can('admin') ? '<a href="import.php#clear" class="btn btn-outline-danger"><i class="bi bi-trash3"></i> ล้างและนำเข้าใหม่</a>'
    . ' <a href="import.php" class="btn btn-outline-primary"><i class="bi bi-file-earmark-arrow-up"></i> นำเข้าบัญชี EA</a>' : '');
require __DIR__ . '/header.php';
?>
<div class="card">
  <div class="card-header">
    <form class="row g-2 align-items-center">
      <div class="col-md-4 col-12"><input name="q" class="form-control" placeholder="รหัส EA (วางได้หลายรหัส) / หมู่บ้าน / ตำบล / หมายเหตุ" value="<?= h($f['q']) ?>"></div>
      <?= ea_filter_selects($f) ?>
      <div class="col-md-3 col-6">
        <select name="project" class="form-select">
          <option value="">ทุก EA (ไม่กรองโครงการ)</option>
          <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $project === (int)$p['id'] ? 'selected' : '' ?>>ใช้ในโครงการ <?= h($p['code']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2 col-6">
        <select name="status" class="form-select">
          <option value="active">ใช้งาน</option>
          <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>เลิกใช้</option>
          <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>ทั้งหมด</option>
        </select>
      </div>
      <div class="col-auto"><button class="btn btn-outline-secondary"><i class="bi bi-search"></i> ค้นหา</button></div>
      <div class="col-auto"><a href="?<?= h(http_build_query(array_filter($query + ['export' => 'csv']))) ?>" class="btn btn-outline-success"><i class="bi bi-filetype-csv"></i> Export CSV</a></div>
      <?php if (array_filter($query)): ?><div class="col-auto"><a href="eas.php" class="btn btn-link">ล้างตัวกรอง</a></div><?php endif; ?>
    </form>
  </div>
  <div class="px-3 py-2 small bg-light border-bottom">
    พบ <strong><?= number_format((int)$tot['n']) ?></strong> EA · บ้าน/อาคาร <?= number_format((int)$tot['houses']) ?> ·
    ครัวเรือน <?= number_format((int)$tot['households']) ?> · ประชากร <?= number_format((int)$tot['population']) ?> คน
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 ea-table">
      <thead><tr><th>รหัส EA</th><th>อำเภอ</th><th>ตำบล</th><th>เขตการปกครอง</th><th>หมู่บ้าน/ชุมชน</th>
        <th class="ea-num">บ้าน/อาคาร</th><th class="ea-num">ครัวเรือน</th><th class="ea-num">ประชากร</th><th>หมายเหตุ</th><th class="text-center">โครงการ</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr class="<?= $r['is_active'] ? '' : 'text-muted' ?>">
          <td><a href="ea_view.php?id=<?= (int)$r['id'] ?>" class="ea-code fw-semibold text-decoration-none"><?= h($r['ea_code']) ?></a>
            <?= $r['is_active'] ? '' : '<div class="small">เลิกใช้</div>' ?></td>
          <td><?= h($r['district_name']) ?></td>
          <td><?= h($r['tambon_name']) ?></td>
          <td class="text-nowrap"><?= (int)$r['area_type'] ?> <?= h(EA_AREA_TYPES[(int)$r['area_type']] ?? '') ?></td>
          <td><?= $r['village_name'] !== '' ? h(($r['village_code'] !== '' ? 'ม.' . (int)$r['village_code'] . ' ' : '') . $r['village_name']) : '<span class="text-muted">-</span>' ?></td>
          <td class="ea-num"><?= number_format((int)$r['houses']) ?></td>
          <td class="ea-num"><?= number_format((int)$r['households']) ?></td>
          <td class="ea-num"><?= number_format((int)$r['population']) ?></td>
          <td class="note"><?= h(mb_strimwidth((string)$r['note'], 0, 90, '…')) ?></td>
          <td class="text-center"><?= $r['project_count'] ? (int)$r['project_count'] : '<span class="text-muted">-</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="10" class="text-center text-muted py-5">
        <?= $pdo->query('SELECT COUNT(*) FROM ea_areas')->fetchColumn() ? 'ไม่พบ EA ตามเงื่อนไข' : 'ยังไม่มีบัญชี EA' . (ea_can('admin') ? ' — <a href="import.php">นำเข้าจากไฟล์ Excel</a>' : '') ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer d-flex align-items-center flex-wrap gap-2">
    <span class="small text-muted">หน้า <?= $page ?> / <?= $pages ?></span>
    <span class="ms-auto"><?= ea_pager((int)$tot['n'], $page, EA_PER_PAGE, $query) ?></span>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
