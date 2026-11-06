<?php
/**
 * EA ของโครงการ (เลือกโครงการจาก core_projects)
 * - ดูรายการ EA ที่ใช้สำรวจ แยกรอบ/งวด · สรุปผู้รับผิดชอบและความก้าวหน้า · ส่งออก CSV
 * - เจ้าหน้าที่โครงการ (editor): เพิ่ม EA ตามพื้นที่ หรือวางรหัส EA · กำหนดผู้รับผิดชอบ/สถานะ/หมายเหตุ · นำ EA ออก
 *   (ทำกับรายการที่เลือก หรือทุกรายการตามตัวกรองปัจจุบัน)
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
$round = isset($_GET['round']) ? mb_substr(trim((string)$_GET['round']), 0, 50) : null;   // null = ทุกรอบ
$f = ea_filter_input();
$fStatus = isset(EA_STATUSES[$_GET['status'] ?? '']) ? $_GET['status'] : '';
$fy = ctype_digit((string)($_GET['fy'] ?? '')) ? (int)$_GET['fy'] : '';      // ปีงบประมาณ
$fUser = ($_GET['user'] ?? '') === 'none' ? 'none' : (ctype_digit((string)($_GET['user'] ?? '')) ? (int)$_GET['user'] : '');

// เงื่อนไขรายการตามตัวกรองปัจจุบัน
[$where, $params] = ea_filter_sql($f);
array_unshift($where, 'pe.project_id = ?');
array_unshift($params, $pid);
if ($round !== null) {
    $where[] = 'pe.round_label = ?';
    $params[] = $round;
}
if ($fy !== '') {
    $where[] = 'pe.fiscal_year = ?';
    $params[] = $fy;
}
if ($fStatus !== '') {
    $where[] = 'pe.status = ?';
    $params[] = $fStatus;
}
if ($fUser === 'none') {
    $where[] = 'pe.assigned_user_id IS NULL';
} elseif ($fUser !== '') {
    $where[] = 'pe.assigned_user_id = ?';
    $params[] = $fUser;
}
$from = ' FROM ea_project_eas pe JOIN ea_areas a ON a.id = pe.ea_id WHERE ' . implode(' AND ', $where);
$query = ['id' => $pid, 'fy' => $fy, 'round' => $round] + $f + ['status' => $fStatus, 'user' => $fUser];
$keep = fn(array $q) => array_filter($q, fn($v, $k) => $v !== null && ($v !== '' || $k === 'round'), ARRAY_FILTER_USE_BOTH);
$back = 'project_eas.php?' . http_build_query($keep($query));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    ea_require('editor');
    $action = post('action');
    $staff = ea_staff();
    $userOrNull = fn(string $v) => ctype_digit($v) && isset($staff[(int)$v]) ? (int)$v : null;

    if ($action === 'add') {
        $rl = mb_substr(trim(post('round_label')), 0, 50);
        $assign = $userOrNull(post('assign'));
        // ชื่อรอบรูปแบบ "ม.ค. 2570" / "มกราคม 2570" → เก็บเดือน/ปีที่แจงนับด้วย (เรียงรอบตามเวลาได้)
        $em = $ey = null;
        if (preg_match('/^(\S+)\s+(\d{4})$/u', $rl, $mm) && ($em = ea_month_num($mm[1]))) {
            $ey = ea_year($mm[2]);
            $rl = ea_round_label($em, $ey);
        }
        $efy = $em && $ey ? ($em >= 10 ? $ey + 1 : $ey) : null;
        if (post('method') === 'codes') {
            $codes = ea_parse_codes(post('codes'));
            if (!$codes) {
                flash('ไม่พบรหัส EA 11 หลักในข้อความที่วาง', 'warning');
                redirect($back);
            }
            $ph = implode(',', array_fill(0, count($codes), '?'));
            $st = $pdo->prepare("SELECT id, ea_code, is_active FROM ea_areas WHERE ea_code IN ($ph)");
            $st->execute($codes);
            $found = $st->fetchAll();
            $ids = array_map(fn($r) => (int)$r['id'], $found);
            $unknown = array_values(array_diff($codes, array_column($found, 'ea_code')));
        } else {
            $af = ['district' => preg_match('/^\d{2}$/', post('add_district')) ? post('add_district') : '',
                   'tambon' => preg_match('/^\d{2}$/', post('add_tambon')) ? post('add_tambon') : '',
                   'area' => post('add_area'), 'q' => ''];
            if ($af['district'] === '') {
                $af['tambon'] = '';
            }
            [$w, $p] = ea_filter_sql($af);
            $w[] = 'a.is_active = 1';
            $st = $pdo->prepare('SELECT a.id FROM ea_areas a WHERE ' . implode(' AND ', $w));
            $st->execute($p);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $unknown = [];
        }
        $ins = $pdo->prepare('INSERT IGNORE INTO ea_project_eas (project_id, ea_id, round_label, enum_type, enum_month, enum_year, fiscal_year, assigned_user_id, created_by)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $added = 0;
        $pdo->beginTransaction();
        foreach ($ids as $eid) {
            $ins->execute([$pid, $eid, $rl, $em ? 'Month' : '', $em, $ey, $efy, $assign, (int)$me['id']]);
            $added += $ins->rowCount();
        }
        $pdo->commit();
        $dup = count($ids) - $added;
        $msg = 'เพิ่ม EA ให้โครงการ ' . $project['code'] . ($rl !== '' ? ' รอบ ' . $rl : '') . ' แล้ว ' . number_format($added) . ' EA'
             . ($dup ? ' (มีอยู่แล้ว ' . number_format($dup) . ' EA)' : '');
        audit_log('ea_project_add', $msg, EA_MODULE);
        flash($msg . ($unknown ? ' — ไม่พบรหัสในบัญชี EA: ' . implode(', ', array_slice($unknown, 0, 20)) . (count($unknown) > 20 ? ' …' : '') : ''), $unknown || !$added ? 'warning' : 'success');
        redirect('project_eas.php?id=' . $pid . '&round=' . urlencode($rl));
    }

    if ($action === 'bulk') {
        // รายการเป้าหมาย: ที่เลือก หรือทุกรายการตามตัวกรองปัจจุบัน
        if (post('scope') === 'filtered') {
            $st = $pdo->prepare('SELECT pe.id' . $from);
            $st->execute($params);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } else {
            $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
            if ($ids) {   // ต้องเป็นรายการของโครงการนี้เท่านั้น
                $st = $pdo->prepare('SELECT id FROM ea_project_eas WHERE project_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
                $st->execute(array_merge([$pid], $ids));
                $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            }
        }
        if (!$ids) {
            flash('ยังไม่ได้เลือก EA', 'warning');
            redirect($back);
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $op = post('op');
        if ($op === 'assign') {
            $pdo->prepare("UPDATE ea_project_eas SET assigned_user_id = ? WHERE id IN ($ph)")->execute(array_merge([$userOrNull(post('assign'))], $ids));
            $msg = 'กำหนดผู้รับผิดชอบ';
        } elseif ($op === 'status' && isset(EA_STATUSES[post('status')])) {
            $s = post('status');
            $pdo->prepare("UPDATE ea_project_eas SET status = ?, done_at = IF(? = 'done', COALESCE(done_at, NOW()), NULL) WHERE id IN ($ph)")
                ->execute(array_merge([$s, $s], $ids));
            $msg = 'ปรับสถานะเป็น "' . EA_STATUSES[$s][0] . '"';
        } elseif ($op === 'note') {
            $pdo->prepare("UPDATE ea_project_eas SET note = ? WHERE id IN ($ph)")->execute(array_merge([mb_substr(trim(post('note')), 0, 255)], $ids));
            $msg = 'บันทึกหมายเหตุ';
        } elseif ($op === 'delete') {
            $pdo->prepare("DELETE FROM ea_project_eas WHERE id IN ($ph)")->execute($ids);
            $msg = 'นำ EA ออกจากโครงการ';
        } else {
            redirect($back);
        }
        audit_log('ea_project_bulk', $msg . ' ' . count($ids) . ' EA โครงการ ' . $project['code'] . ($round ? ' รอบ ' . $round : ''), EA_MODULE);
        flash($msg . ' ' . number_format(count($ids)) . ' EA แล้ว');
        redirect($back);
    }
    redirect($back);
}

$selectCols = 'SELECT pe.id AS pe_id, pe.round_label, pe.status, pe.note AS pe_note, pe.assigned_user_id, pe.done_at,
                      pe.psu_no, pe.enum_type, pe.enum_month, pe.enum_year, pe.fiscal_year, pe.lat, pe.lng, a.*';
if (($_GET['export'] ?? '') === 'csv') {
    $st = $pdo->prepare($selectCols . $from . ' ORDER BY ' . EA_ROUND_ORDER . ', a.ea_code');
    $st->execute($params);
    $staff = ea_staff();
    $out = ea_csv_start('ea_' . $project['code'] . ($round ? '_' . preg_replace('/[^\p{L}\p{N}_-]+/u', '_', $round) : '') . '_' . date('Ymd') . '.csv');
    // รูปแบบเดียวกับไฟล์ EA_Report (นำเข้ากลับได้ที่ "นำเข้า EA โครงการ") + คอลัมน์การติดตามงาน
    fputcsv($out, array_merge(EA_REPORT_HEAD, ['รหัส EA', 'รอบ/งวด', 'ผู้รับผิดชอบ', 'สถานะ', 'วันที่สำรวจเสร็จ', 'หมายเหตุ']));
    foreach ($st as $r) {
        fputcsv($out, array_merge(ea_report_row($r, $project['code']), ['="' . $r['ea_code'] . '"', $r['round_label'],
            $r['assigned_user_id'] ? ($staff[(int)$r['assigned_user_id']]['full_name'] ?? '') : '', EA_STATUSES[$r['status']][0] ?? $r['status'],
            $r['done_at'] ? thai_date(substr($r['done_at'], 0, 10)) : '', $r['pe_note']]));
    }
    fclose($out);
    exit;
}

// รอบ/งวดของโครงการ
$st = $pdo->prepare('SELECT pe.round_label, COUNT(*) n, GROUP_CONCAT(DISTINCT NULLIF(pe.enum_type, \'\') ORDER BY pe.enum_type) types
                     FROM ea_project_eas pe WHERE pe.project_id = ?' . ($fy !== '' ? ' AND pe.fiscal_year = ' . (int)$fy : '') . '
                     GROUP BY pe.round_label ORDER BY MIN(pe.enum_year) IS NULL, MIN(pe.enum_year), MIN(pe.enum_month) IS NULL, MIN(pe.enum_month), pe.round_label');
$st->execute([$pid]);
$rounds = [];
$roundTypes = [];
foreach ($st as $r) {
    $rounds[(string)$r['round_label']] = (int)$r['n'];
    $roundTypes[(string)$r['round_label']] = (string)$r['types'];
}
$st = $pdo->prepare('SELECT DISTINCT fiscal_year FROM ea_project_eas WHERE project_id = ? AND fiscal_year IS NOT NULL ORDER BY fiscal_year');
$st->execute([$pid]);
$fiscalYears = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
// สรุปตามตัวกรอง
$st = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(a.houses), 0) houses, COALESCE(SUM(a.households), 0) households, COALESCE(SUM(a.population), 0) population,
                            COALESCE(SUM(pe.status = 'done'), 0) done, COALESCE(SUM(pe.status = 'in_progress'), 0) doing" . $from);
$st->execute($params);
$tot = $st->fetch();
$st = $pdo->prepare("SELECT pe.assigned_user_id uid, COUNT(*) n, SUM(pe.status = 'done') done, SUM(pe.status = 'in_progress') doing, SUM(a.households) hh" . $from . ' GROUP BY pe.assigned_user_id');
$st->execute($params);
$byUser = [];
foreach ($st as $r) {
    $byUser[(int)$r['uid']] = $r;
}
$staff = ea_staff();
$byUserSorted = [];
foreach ($staff as $uid => $u) {
    if (isset($byUser[$uid])) {
        $byUserSorted[$uid] = $byUser[$uid];
    }
}
foreach ($byUser as $uid => $r) {      // ผู้ใช้ที่ปิดบัญชีแล้ว / ยังไม่กำหนด อยู่ท้าย
    $byUserSorted[$uid] ??= $r;
}
$page = max(1, int_param('page', 1));
$per = 200;
$pages = max(1, (int)ceil($tot['n'] / $per));
$page = min($page, $pages);
$st = $pdo->prepare($selectCols . $from . ' ORDER BY ' . EA_ROUND_ORDER . ', a.ea_code LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
$st->execute($params);
$rows = $st->fetchAll();
$names = [];
foreach ($pdo->query('SELECT id, full_name FROM core_users') as $u) {
    $names[(int)$u['id']] = $u['full_name'];
}
$canEdit = ea_can('editor');
$addF = ['district' => '', 'tambon' => '', 'area' => '', 'q' => ''];

$pageTitle = 'EA โครงการ ' . $project['code'];
$active = 'projects';
$pageActions = '<a href="projects.php" class="btn btn-light"><i class="bi bi-arrow-left"></i> ทุกโครงการ</a>'
    . ' <a href="?' . h(http_build_query($keep($query) + ['export' => 'csv'])) . '" class="btn btn-outline-success"><i class="bi bi-filetype-csv"></i> Export CSV</a>'
    . ($canEdit ? ' <a href="project_import.php" class="btn btn-outline-primary"><i class="bi bi-file-earmark-arrow-up"></i> นำเข้าไฟล์</a>' : '')
    . ($canEdit ? ' <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal"><i class="bi bi-plus-lg"></i> เพิ่ม EA</button>' : '');
require __DIR__ . '/header.php';
$n = (int)$tot['n'];
$roundUrl = fn($r) => 'project_eas.php?id=' . $pid . ($fy !== '' ? '&fy=' . $fy : '') . ($r === null ? '' : '&round=' . urlencode($r));
$roundName = fn(string $rl) => ($rl === '' ? 'ไม่ระบุรอบ' : $rl) . ($roundTypes[$rl] ?? '' ? ' · ' . str_replace(',', '/', $roundTypes[$rl]) : '');
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <span class="fw-semibold" style="color:<?= h($project['color']) ?>"><?= h($project['name']) ?></span>
  <span class="text-muted small">· <?= h(project_period_text($project)) ?></span>
</div>
<?php if ($fiscalYears || ($rounds && (count($rounds) > 1 || array_key_first($rounds) !== ''))): ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <?php if ($fiscalYears): ?>
  <select class="form-select form-select-sm w-auto" data-goto title="ปีงบประมาณ">
    <option value="project_eas.php?id=<?= $pid ?>">ทุกปีงบประมาณ</option>
    <?php foreach ($fiscalYears as $y): ?><option value="project_eas.php?id=<?= $pid ?>&amp;fy=<?= $y ?>" <?= $fy === $y ? 'selected' : '' ?>>ปีงบประมาณ <?= $y ?></option><?php endforeach; ?>
  </select>
  <?php endif; ?>
  <?php if (count($rounds) > 8): ?>
  <select class="form-select form-select-sm w-auto" data-goto title="รอบ/งวดการแจงนับ">
    <option value="<?= h($roundUrl(null)) ?>">ทุกรอบ (<?= number_format(array_sum($rounds)) ?> EA)</option>
    <?php foreach ($rounds as $rl => $cnt): ?><option value="<?= h($roundUrl($rl)) ?>" <?= $round === $rl ? 'selected' : '' ?>><?= h($roundName($rl)) ?> (<?= number_format($cnt) ?> EA)</option><?php endforeach; ?>
  </select>
  <?php elseif ($rounds): ?>
  <ul class="nav nav-pills small">
    <li class="nav-item"><a class="nav-link<?= $round === null ? ' active' : '' ?>" href="<?= h($roundUrl(null)) ?>">ทุกรอบ (<?= number_format(array_sum($rounds)) ?>)</a></li>
    <?php foreach ($rounds as $rl => $cnt): ?>
    <li class="nav-item"><a class="nav-link<?= $round === $rl ? ' active' : '' ?>" href="<?= h($roundUrl($rl)) ?>"><?= h($roundName($rl)) ?> (<?= number_format($cnt) ?>)</a></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-3 mb-3" data-reveal>
  <?php foreach ([
      ['EA', $n, 'bi-map', 'primary'],
      ['ครัวเรือน', (int)$tot['households'], 'bi-house', 'info'],
      ['ประชากร (คน)', (int)$tot['population'], 'bi-people', 'warning'],
      ['สำรวจเสร็จ', (int)$tot['done'], 'bi-check2-circle', 'success'],
  ] as [$label, $v, $icon, $color]): ?>
  <div class="col-6 col-lg-3 reveal-item">
    <div class="stat-card"><span class="stat-icon text-bg-<?= $color ?>"><i class="bi <?= $icon ?>"></i></span>
      <div><div class="stat-num"><?= number_format($v) ?></div><div class="stat-label"><?= $label ?><?= $label === 'สำรวจเสร็จ' && $n ? ' · ' . round($v / $n * 100) . '%' : '' ?></div></div></div>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($byUserSorted): ?>
<div class="card mb-3" data-reveal>
  <div class="card-header"><strong>ผู้รับผิดชอบ</strong> <span class="small text-muted">— คลิกชื่อเพื่อกรอง · <span class="ea-legend"><i style="background:#22c55e"></i>สำรวจเสร็จ <i style="background:#f59e0b" class="ms-2"></i>กำลังสำรวจ</span></span></div>
  <div class="card-body reveal-item">
    <div class="row g-2">
    <?php foreach ($byUserSorted as $uid => $r): $q2 = $query; $q2['user'] = $uid ?: 'none'; ?>
      <div class="col-md-6 col-xl-4">
        <div class="d-flex small"><a href="?<?= h(http_build_query($keep($q2))) ?>" class="text-decoration-none text-truncate<?= $uid ? '' : ' text-danger' ?>">
          <?= h($uid ? ($names[$uid] ?? 'ผู้ใช้ #' . $uid) : 'ยังไม่กำหนดผู้รับผิดชอบ') ?></a>
          <span class="ms-auto text-nowrap ps-2"><?= (int)$r['done'] ?>/<?= (int)$r['n'] ?> EA</span></div>
        <div class="ea-progress"><span class="done" style="width:<?= $r['done'] / $r['n'] * 100 ?>%"></span><span class="doing" style="width:<?= $r['doing'] / $r['n'] * 100 ?>%"></span></div>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header">
    <form class="row g-2 align-items-center">
      <input type="hidden" name="id" value="<?= $pid ?>">
      <?php if ($round !== null): ?><input type="hidden" name="round" value="<?= h($round) ?>"><?php endif; ?>
      <?php if ($fy !== ''): ?><input type="hidden" name="fy" value="<?= $fy ?>"><?php endif; ?>
      <div class="col-md-3 col-12"><input name="q" class="form-control" placeholder="รหัส EA / หมู่บ้าน / ตำบล" value="<?= h($f['q']) ?>"></div>
      <?= ea_filter_selects($f) ?>
      <div class="col-md-2 col-6"><select name="status" class="form-select"><option value="">ทุกสถานะ</option>
        <?php foreach (EA_STATUSES as $k => [$label]): ?><option value="<?= $k ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-3 col-6"><select name="user" class="form-select"><option value="">ผู้รับผิดชอบทุกคน</option>
        <option value="none" <?= $fUser === 'none' ? 'selected' : '' ?>>ยังไม่กำหนดผู้รับผิดชอบ</option><?= ea_staff_options(is_int($fUser) ? $fUser : null) ?></select></div>
      <div class="col-auto"><button class="btn btn-outline-secondary"><i class="bi bi-search"></i> ค้นหา</button></div>
      <?php if (array_filter($f) || $fStatus !== '' || $fUser !== ''): ?><div class="col-auto"><a href="<?= h($roundUrl($round)) ?>" class="btn btn-link">ล้างตัวกรอง</a></div><?php endif; ?>
    </form>
  </div>
  <form method="post" id="bulkForm">
    <?= csrf_field() ?><input type="hidden" name="action" value="bulk">
    <div class="table-responsive">
      <table class="table table-hover mb-0 ea-table">
        <thead><tr>
          <?php if ($canEdit): ?><th style="width:32px"><input type="checkbox" class="form-check-input" data-check-all=".pe-check" title="เลือกทั้งหน้า"></th><?php endif; ?>
          <th>รหัส EA</th><th>PSU</th><?php if ($round === null && count($rounds) > 1): ?><th>รอบ/งวด</th><?php endif; ?><th>พื้นที่</th><th>เขต</th>
          <th class="ea-num">ครัวเรือน</th><th class="ea-num">ประชากร</th><th>ผู้รับผิดชอบ</th><th>สถานะ</th><th>หมายเหตุ</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <?php if ($canEdit): ?><td><input type="checkbox" class="form-check-input pe-check" name="ids[]" value="<?= (int)$r['pe_id'] ?>"></td><?php endif; ?>
            <td><a href="ea_view.php?id=<?= (int)$r['id'] ?>" class="ea-code text-decoration-none"><?= h($r['ea_code']) ?></a></td>
            <td class="ea-code small"><?= h($r['psu_no'] ?: '-') ?></td>
            <?php if ($round === null && count($rounds) > 1): ?><td class="small text-nowrap"><?= h($r['round_label'] ?: '-') ?><?= $r['enum_type'] !== '' ? '<div class="text-muted">' . h($r['enum_type']) . '</div>' : '' ?></td><?php endif; ?>
            <td><?= h(ea_place($r)) ?><?php if ($r['lat'] !== null && $r['lng'] !== null): ?>
              <a href="https://www.google.com/maps?q=<?= (float)$r['lat'] ?>,<?= (float)$r['lng'] ?>" target="_blank" rel="noopener" class="ms-1" title="พิกัด <?= (float)$r['lat'] ?>, <?= (float)$r['lng'] ?>"><i class="bi bi-geo-alt-fill text-success"></i></a><?php endif; ?></td>
            <td class="small text-nowrap"><?= h(EA_AREA_TYPES[(int)$r['area_type']] ?? '') ?></td>
            <td class="ea-num"><?= number_format((int)$r['households']) ?></td>
            <td class="ea-num"><?= number_format((int)$r['population']) ?></td>
            <td><?= $r['assigned_user_id'] ? h($names[(int)$r['assigned_user_id']] ?? '') : '<span class="text-muted">-</span>' ?></td>
            <td class="text-nowrap"><?= ea_status_badge($r['status']) ?><?= $r['done_at'] ? '<div class="small text-muted">' . thai_date(substr($r['done_at'], 0, 10)) . '</div>' : '' ?></td>
            <td class="note"><?= h($r['pe_note']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="12" class="text-center text-muted py-5"><?= $rounds ? 'ไม่พบ EA ตามเงื่อนไข' : 'ยังไม่ได้กำหนด EA ให้โครงการนี้' . ($canEdit ? ' — กดปุ่ม "เพิ่ม EA"' : '') ?></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($canEdit && $rows): ?>
    <div class="ea-bulk p-2">
      <div class="row g-2 align-items-center small">
        <div class="col-auto text-muted">เลือก <strong data-check-count>0</strong> EA</div>
        <div class="col-auto">
          <select name="scope" class="form-select form-select-sm" id="bulkScope">
            <option value="selected">เฉพาะที่เลือก</option>
            <option value="filtered">ทุกรายการตามตัวกรอง (<?= number_format($n) ?> EA)</option>
          </select>
        </div>
        <div class="col-auto">
          <select name="op" class="form-select form-select-sm" id="bulkOp">
            <option value="assign">กำหนดผู้รับผิดชอบ</option>
            <option value="status">ปรับสถานะ</option>
            <option value="note">บันทึกหมายเหตุ</option>
            <option value="delete">นำออกจากโครงการ</option>
          </select>
        </div>
        <div class="col-auto" data-op="assign"><select name="assign" class="form-select form-select-sm"><option value="">— ไม่กำหนด —</option><?= ea_staff_options() ?></select></div>
        <div class="col-auto d-none" data-op="status"><select name="status" class="form-select form-select-sm">
          <?php foreach (EA_STATUSES as $k => [$label]): ?><option value="<?= $k ?>"><?= h($label) ?></option><?php endforeach; ?></select></div>
        <div class="col-auto d-none" data-op="note"><input name="note" class="form-control form-control-sm" maxlength="255" placeholder="หมายเหตุ (ว่าง = ล้าง)"></div>
        <div class="col-auto"><button class="btn btn-sm btn-primary" id="bulkBtn">ดำเนินการ</button></div>
        <div class="col-auto ms-auto text-muted">หน้า <?= $page ?> / <?= $pages ?></div>
        <div class="col-auto"><?= ea_pager($n, $page, $per, $query) ?></div>
      </div>
    </div>
    <?php elseif ($pages > 1): ?>
    <div class="card-footer d-flex"><span class="ms-auto"><?= ea_pager($n, $page, $per, $query) ?></span></div>
    <?php endif; ?>
  </form>
</div>

<?php if ($canEdit): ?>
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="post" class="modal-content" id="addForm">
      <?= csrf_field() ?><input type="hidden" name="action" value="add">
      <div class="modal-header"><h5 class="modal-title">เพิ่ม EA ให้โครงการ <?= h($project['code']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-2 mb-3">
          <div class="col-md-6"><label class="form-label">รอบ/งวดการสำรวจ <span class="small text-muted">(ไม่บังคับ)</span></label>
            <input name="round_label" class="form-control" maxlength="50" list="roundList" value="<?= h((string)$round) ?>" placeholder="เช่น ม.ค. 2570 (เดือน + ปี พ.ศ.)">
            <datalist id="roundList"><?php foreach (array_keys($rounds) as $rl): if ((string)$rl !== ''): ?><option value="<?= h($rl) ?>"><?php endif; endforeach; ?></datalist></div>
          <div class="col-md-6"><label class="form-label">ผู้รับผิดชอบ <span class="small text-muted">(ไม่บังคับ)</span></label>
            <select name="assign" class="form-select"><option value="">— กำหนดภายหลัง —</option><?= ea_staff_options() ?></select></div>
        </div>
        <ul class="nav nav-tabs mb-3" role="tablist">
          <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#addArea" data-method="area">ตามพื้นที่</button></li>
          <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#addCodes" data-method="codes">วางรหัส EA</button></li>
        </ul>
        <input type="hidden" name="method" value="area" id="addMethod">
        <div class="tab-content">
          <div class="tab-pane fade show active" id="addArea">
            <div class="row g-2">
              <div class="col-md-5"><label class="form-label small">อำเภอ</label><select name="add_district" id="addDistrict" class="form-select">
                <option value="">ทั้งจังหวัด</option><?php foreach (ea_districts() as $c => $nm): ?><option value="<?= h($c) ?>"><?= h($c . ' ' . $nm) ?></option><?php endforeach; ?></select></div>
              <div class="col-md-4"><label class="form-label small">ตำบล</label><select name="add_tambon" id="addTambon" class="form-select" disabled><option value="">ทุกตำบล</option></select></div>
              <div class="col-md-3"><label class="form-label small">เขตการปกครอง</label><select name="add_area" class="form-select"><option value="">ทั้งหมด</option>
                <?php foreach (EA_AREA_TYPES as $k => $label): ?><option value="<?= $k ?>"><?= $k . ' ' . h($label) ?></option><?php endforeach; ?></select></div>
            </div>
            <p class="small text-muted mt-2 mb-0">เพิ่ม EA ที่ใช้งานอยู่ทั้งหมดในพื้นที่ที่เลือก (EA ที่มีในรอบนี้แล้วจะไม่ซ้ำ)</p>
          </div>
          <div class="tab-pane fade" id="addCodes">
            <textarea name="codes" class="form-control font-monospace" rows="7" placeholder="วางรหัส EA 11 หลัก คั่นด้วยการขึ้นบรรทัด ช่องว่าง หรือจุลภาค&#10;เช่น คัดลอกคอลัมน์รหัส EA จากรายชื่อ EA ตัวอย่างของโครงการใน Excel"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button><button class="btn btn-primary"><i class="bi bi-plus-lg"></i> เพิ่ม EA</button></div>
    </form>
  </div>
</div>
<script>
(() => {
  // ตำบลของแต่ละอำเภอ (สำหรับเพิ่ม EA ตามพื้นที่)
  const TAMBONS = <?php $tmap = [];
    foreach (ea_districts() as $c => $nm) {
        foreach (ea_tambons((string)$c) as $tc => $tn) {
            $tmap[(string)$c][] = [(string)$tc, $tn];
        }
    }
    echo json_encode($tmap ?: new stdClass(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  const d = document.getElementById('addDistrict'), t = document.getElementById('addTambon');
  d.addEventListener('change', () => {
    t.innerHTML = '<option value="">ทุกตำบล</option>';
    (TAMBONS[d.value] || []).forEach(([c, n]) => { const o = document.createElement('option'); o.value = c; o.textContent = c + ' ' + n; t.append(o); });
    t.disabled = !d.value;
  });
  document.querySelectorAll('#addModal [data-method]').forEach(b => b.addEventListener('click', () => { document.getElementById('addMethod').value = b.dataset.method; }));
  document.getElementById('addForm').addEventListener('submit', e => {
    if (document.getElementById('addMethod').value === 'area' && !d.value && !confirm('เพิ่ม EA ทั้งจังหวัดให้โครงการนี้?')) { e.preventDefault(); }
  });
  // แถบดำเนินการกับหลายรายการ
  const op = document.getElementById('bulkOp');
  if (op) {
    const sync = () => document.querySelectorAll('#bulkForm [data-op]').forEach(el => el.classList.toggle('d-none', el.dataset.op !== op.value));
    op.addEventListener('change', sync); sync();
    document.getElementById('bulkForm').addEventListener('submit', e => {
      const scope = document.getElementById('bulkScope');
      const n = scope.value === 'filtered' ? <?= $n ?> : document.querySelectorAll('.pe-check:checked').length;
      if (!n) { e.preventDefault(); alert('กรุณาเลือก EA'); return; }
      const label = op.options[op.selectedIndex].text;
      if ((op.value === 'delete' || scope.value === 'filtered') && !confirm(label + ' ' + n.toLocaleString() + ' EA?')) { e.preventDefault(); }
    });
  }
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
