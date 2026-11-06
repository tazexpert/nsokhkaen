<?php
/**
 * มอบหมายผู้ปฏิบัติงาน — สุ่มผู้ปฏิบัติงานให้ EA ของโครงการ (เจ้าหน้าที่โครงการ: editor)
 * 1) เลือกโครงการ + ปี/เดือนที่แจงนับ + พนักงานที่รับผิดชอบ + วิธีเฉลี่ยงาน → สุ่ม
 * 2) ตรวจผลสุ่ม: เปลี่ยนผู้ปฏิบัติงานรายแถว หรือสลับงานระหว่าง 2 แถว / สุ่มใหม่ → บันทึก
 * 3) หลังบันทึกเปิด "แก้ไขการมอบหมาย" เพื่อปรับต่อได้ (หน้าเดียวกัน โหลดผลที่บันทึกไว้)
 * ผู้ได้รับมอบหมายเห็นการแจ้งเตือน (modules/ea/notifications.php)
 */
require_once __DIR__ . '/bootstrap.php';
$me = require_module(EA_MODULE, 'editor');
$pdo = db();
$projects = array_filter(projects_all(), fn($p) => $p['is_active']);
$pid = int_param('project');
$project = projects_all()[$pid] ?? null;
$year = int_param('year');
$month = int_param('month');                       // 0 = ทุกเดือนของปี
$staffAll = ea_staff();

// ปีที่แจงนับของโครงการ
$years = [];
if ($project) {
    $st = $pdo->prepare('SELECT DISTINCT enum_year FROM ea_project_eas WHERE project_id = ? AND enum_year IS NOT NULL ORDER BY enum_year');
    $st->execute([$pid]);
    $years = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if ($years && !in_array($year, $years, true)) {
        $thisYear = (int)date('Y') + 543;
        $year = in_array($thisYear, $years, true) ? $thisYear : end($years);
    }
    if (!$years) {
        $year = 0;                                  // โครงการที่ไม่มีข้อมูลเดือน/ปี → ทุกรอบ
    }
}
if ($month < 0 || $month > 12) {
    $month = 0;
}

/** EA ของโครงการตามเงื่อนไข (เรียงตามรอบและรหัส EA) */
$loadRows = function () use ($pdo, $pid, $year, $month): array {
    $sql = 'SELECT pe.id pe_id, pe.ea_id, pe.round_label, pe.enum_type, pe.psu_no, pe.status, pe.assigned_user_id, a.ea_code, a.district_name, a.tambon_name,
                   a.village_code, a.village_name, a.area_type, a.households, a.population
            FROM ea_project_eas pe JOIN ea_areas a ON a.id = pe.ea_id WHERE pe.project_id = ?';
    $params = [$pid];
    if ($year) {
        $sql .= ' AND pe.enum_year = ?';
        $params[] = $year;
        if ($month) {
            $sql .= ' AND pe.enum_month = ?';
            $params[] = $month;
        }
    }
    $st = $pdo->prepare($sql . ' ORDER BY ' . EA_ROUND_ORDER . ', a.ea_code');
    $st->execute($params);
    return $st->fetchAll();
};
$critQuery = ['project' => $pid, 'year' => $year ?: null, 'month' => $month ?: null];
$critUrl = 'assign.php?' . http_build_query(array_filter($critQuery));
$roundText = $project ? ($year ? ($month ? EA_MONTHS_LONG[$month] . ' ' . $year : 'ทุกเดือน ปี ' . $year) : 'ทุกรอบ') : '';

/**
 * สุ่มผู้ปฏิบัติงาน: งาน (EA หรือกลุ่ม EA เดียวกันหลายเดือน) สุ่มลำดับ แล้วให้คนที่มีภาระน้อยที่สุดในขณะนั้น
 * (เท่ากันหลายคน = สุ่ม) · ภาระ = จำนวน EA หรือจำนวนครัวเรือน
 */
function ea_random_assign(array $rows, array $staffIds, string $balance, bool $keepSame): array
{
    $shuffle = function (array $a): array {
        for ($i = count($a) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$a[$i], $a[$j]] = [$a[$j], $a[$i]];
        }
        return $a;
    };
    $units = [];
    foreach ($rows as $r) {
        $k = $keepSame ? 'ea' . $r['ea_id'] : 'pe' . $r['pe_id'];
        $units[$k]['ids'][] = (int)$r['pe_id'];
        $units[$k]['w'] = ($units[$k]['w'] ?? 0) + ($balance === 'households' ? max(1, (int)$r['households']) : 1);
    }
    $units = $shuffle(array_values($units));
    if ($balance === 'households') {               // งานหนักก่อน → เฉลี่ยได้ใกล้เคียงกว่า (ลำดับสุ่มคงไว้เมื่อเท่ากัน)
        $order = array_keys($units);
        usort($order, fn($a, $b) => [$units[$b]['w'], $a] <=> [$units[$a]['w'], $b]);
        $units = array_map(fn($i) => $units[$i], $order);
    }
    $load = array_fill_keys($shuffle($staffIds), 0);
    $result = [];
    foreach ($units as $u) {
        $min = min($load);
        $cands = array_keys(array_filter($load, fn($v) => $v === $min));
        $uid = $cands[random_int(0, count($cands) - 1)];
        $load[$uid] += $u['w'];
        foreach ($u['ids'] as $id) {
            $result[$id] = $uid;
        }
    }
    return $result;
}

$mode = 'form';
$errors = [];
$assign = [];       // pe_id => user_id (ผลที่แสดงในตาราง)
$chosen = array_values(array_filter(array_map('intval', (array)($_POST['staff'] ?? $_GET['staff'] ?? [])), fn($id) => isset($staffAll[$id])));
$balance = (($_POST['balance'] ?? $_GET['balance'] ?? '') === 'households') ? 'households' : 'count';
$keepSame = isset($_POST['action']) ? post('keep_same') === '1' : (($_GET['keep_same'] ?? '1') === '1');
$scope = (($_POST['scope'] ?? '') === 'unassigned') ? 'unassigned' : 'all';
$rows = $project ? $loadRows() : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $project) {
    check_csrf();
    $action = post('action');
    if ($action === 'random') {
        if (!$rows) {
            $errors[] = 'ไม่พบ EA ของโครงการตามเงื่อนไขที่เลือก';
        } elseif (count($chosen) < 1) {
            $errors[] = 'กรุณาเลือกพนักงานที่รับผิดชอบอย่างน้อย 1 คน';
        } else {
            $target = $scope === 'unassigned' ? array_values(array_filter($rows, fn($r) => !$r['assigned_user_id'])) : $rows;
            if (!$target) {
                $errors[] = 'EA ตามเงื่อนไขมีผู้ปฏิบัติงานครบแล้ว — เลือก "สุ่มใหม่ทั้งหมด" หรือ "แก้ไขการมอบหมาย"';
            } else {
                foreach ($rows as $r) {
                    $assign[(int)$r['pe_id']] = $r['assigned_user_id'] ? (int)$r['assigned_user_id'] : null;
                }
                $result = ea_random_assign($target, $chosen, $balance, $keepSame);
                $randomIds = array_keys($result);          // EA ที่สุ่มรอบนี้ (ใช้แสดงวงล้อ)
                $assign = $result + $assign;
                $mode = 'edit';
            }
        }
    } elseif ($action === 'save') {
        $valid = array_flip(array_map(fn($r) => (int)$r['pe_id'], $rows));
        $old = [];
        foreach ($rows as $r) {
            $old[(int)$r['pe_id']] = $r['assigned_user_id'] ? (int)$r['assigned_user_id'] : null;
        }
        $upd = $pdo->prepare('UPDATE ea_project_eas SET assigned_user_id = ?, assigned_at = IF(? IS NULL, NULL, NOW()), assigned_by = ? WHERE id = ? AND project_id = ?');
        $changed = 0;
        $pdo->beginTransaction();
        foreach ((array)($_POST['assign'] ?? []) as $peId => $uid) {
            $peId = (int)$peId;
            $uid = ctype_digit((string)$uid) && isset($staffAll[(int)$uid]) ? (int)$uid : null;
            if (!isset($valid[$peId]) || $old[$peId] === $uid) {
                continue;
            }
            $upd->execute([$uid, $uid, (int)$me['id'], $peId, $pid]);
            $changed++;
        }
        $pdo->commit();
        $msg = 'บันทึกการมอบหมายผู้ปฏิบัติงาน ' . $project['code'] . ' ' . $roundText . ' แล้ว (เปลี่ยน ' . number_format($changed) . ' EA)';
        audit_log('ea_assign', $msg, EA_MODULE);
        flash($msg);
        redirect($critUrl . '&mode=edit');
    } elseif ($action === 'edit') {
        $mode = 'edit';
    }
}
if ($project && ($_GET['mode'] ?? '') === 'edit') {
    $mode = 'edit';
}
if ($mode === 'edit' && !$assign) {               // แก้ไขการมอบหมายที่บันทึกไว้
    foreach ($rows as $r) {
        $assign[(int)$r['pe_id']] = $r['assigned_user_id'] ? (int)$r['assigned_user_id'] : null;
    }
}
if ($mode === 'edit') {
    if (!$rows) {
        $mode = 'form';
        $errors[] = 'ไม่พบ EA ของโครงการตามเงื่อนไขที่เลือก';
    }
    // ผู้ปฏิบัติงานในตาราง = ที่เลือก + ที่ได้รับมอบหมายอยู่แล้ว (เรียงตามลำดับตำแหน่ง)
    $inTable = array_flip(array_merge($chosen, array_values(array_filter($assign))));
    $chosen = array_values(array_filter(array_keys($staffAll), fn($id) => isset($inTable[$id])));
}
// ค่าเริ่มต้นของพนักงานที่เลือกในฟอร์ม: ผู้ที่เคยได้รับมอบหมายในโครงการนี้
if ($mode === 'form' && $project && !$chosen && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $st = $pdo->prepare('SELECT DISTINCT assigned_user_id FROM ea_project_eas WHERE project_id = ? AND assigned_user_id IS NOT NULL');
    $st->execute([$pid]);
    $chosen = array_values(array_filter(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), fn($id) => isset($staffAll[$id])));
}
$assignedCount = count(array_filter($rows, fn($r) => $r['assigned_user_id']));

$pageTitle = 'มอบหมายผู้ปฏิบัติงาน';
$active = 'assign';
require __DIR__ . '/header.php';
?>
<?php foreach ($errors as $e): ?><div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle"></i> <?= h($e) ?></div><?php endforeach; ?>

<form method="get" class="card mb-3">
  <div class="card-body row g-2 align-items-end">
    <div class="col-md-5"><label class="form-label small">โครงการ</label>
      <select name="project" class="form-select" onchange="this.form.year && (this.form.year.value = ''); this.form.submit()">
        <option value="">— เลือกโครงการ —</option>
        <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $pid === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['code'] . ' — ' . $p['name']) ?></option><?php endforeach; ?>
      </select></div>
    <?php if ($project && $years): ?>
    <div class="col-md-2 col-6"><label class="form-label small">ปีที่แจงนับ</label>
      <select name="year" class="form-select" onchange="this.form.submit()"><?php foreach ($years as $y): ?><option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2 col-6"><label class="form-label small">เดือน</label>
      <select name="month" class="form-select" onchange="this.form.submit()"><option value="0">ทุกเดือน</option>
        <?php foreach (EA_MONTHS_LONG as $i => $m): ?><option value="<?= $i ?>" <?= $month === $i ? 'selected' : '' ?>><?= h($m) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <?php if ($project): ?>
    <div class="col-md-3 small text-muted pb-2">EA ตามเงื่อนไข <strong class="text-body"><?= number_format(count($rows)) ?></strong> รายการ ·
      มีผู้ปฏิบัติงานแล้ว <?= number_format($assignedCount) ?></div>
    <?php endif; ?>
  </div>
</form>

<?php if (!$project): ?>
  <p class="text-muted">เลือกโครงการ แล้วระบุปี/เดือนที่แจงนับ เพื่อมอบหมายผู้ปฏิบัติงานให้ EA</p>
<?php elseif ($mode === 'form'): ?>
<form method="post" action="<?= h($critUrl) ?>" class="card">
  <?= csrf_field() ?>
  <div class="card-header d-flex align-items-center"><strong>พนักงานที่รับผิดชอบ</strong>
    <span class="small text-muted ms-2">เลือกแล้ว <strong data-check-count>0</strong> คน</span>
    <div class="form-check ms-auto mb-0"><input type="checkbox" class="form-check-input" id="allStaff" data-check-all=".staff-check"><label for="allStaff" class="form-check-label small">เลือกทั้งหมด</label></div>
  </div>
  <div class="card-body">
    <div class="row g-1">
      <?php foreach ($staffAll as $id => $u): ?>
      <div class="col-md-4 col-sm-6"><div class="form-check">
        <input type="checkbox" class="form-check-input staff-check" name="staff[]" value="<?= $id ?>" id="s<?= $id ?>" <?= in_array($id, $chosen, true) ? 'checked' : '' ?>>
        <label class="form-check-label" for="s<?= $id ?>"><?= h($u['full_name']) ?> <span class="small text-muted"><?= h(implode(' / ', staff_position_lines($u))) ?></span></label>
      </div></div>
      <?php endforeach; ?>
    </div>
    <hr>
    <div class="row g-3 small">
      <div class="col-md-4"><div class="fw-semibold mb-1">เฉลี่ยงานตาม</div>
        <div class="form-check"><input class="form-check-input" type="radio" name="balance" value="count" id="bc" <?= $balance === 'count' ? 'checked' : '' ?>><label class="form-check-label" for="bc">จำนวน EA เท่า ๆ กัน</label></div>
        <div class="form-check"><input class="form-check-input" type="radio" name="balance" value="households" id="bh" <?= $balance === 'households' ? 'checked' : '' ?>><label class="form-check-label" for="bh">จำนวนครัวเรือนใกล้เคียงกัน</label></div></div>
      <div class="col-md-4"><div class="fw-semibold mb-1">EA ที่สุ่ม</div>
        <div class="form-check"><input class="form-check-input" type="radio" name="scope" value="all" id="sa" checked><label class="form-check-label" for="sa">ทั้งหมด (สุ่มใหม่ทุก EA)</label></div>
        <div class="form-check"><input class="form-check-input" type="radio" name="scope" value="unassigned" id="su"><label class="form-check-label" for="su">เฉพาะ EA ที่ยังไม่มีผู้ปฏิบัติงาน (<?= number_format(count($rows) - $assignedCount) ?>)</label></div></div>
      <div class="col-md-4"><div class="fw-semibold mb-1">ตัวเลือก</div>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="keep_same" value="1" id="ks" <?= $keepSame ? 'checked' : '' ?>>
          <label class="form-check-label" for="ks">EA เดียวกันในหลายเดือน ให้ผู้ปฏิบัติงานคนเดิม</label></div></div>
    </div>
  </div>
  <div class="card-footer d-flex gap-2 flex-wrap">
    <button name="action" value="random" class="btn btn-primary" <?= $rows ? '' : 'disabled' ?>><i class="bi bi-shuffle"></i> สุ่มผู้ปฏิบัติงาน</button>
    <?php if ($assignedCount): ?><button name="action" value="edit" class="btn btn-outline-secondary"><i class="bi bi-pencil-square"></i> แก้ไขการมอบหมายที่บันทึกไว้</button><?php endif; ?>
    <span class="small text-muted align-self-center">ผลการสุ่มยังไม่บันทึก — ตรวจ/สลับงานได้ก่อนกดบันทึก</span>
  </div>
</form>
<?php else:
    $names = [];
    foreach ($pdo->query('SELECT id, full_name FROM core_users') as $u) {
        $names[(int)$u['id']] = $u['full_name'];
    }
    $isRandom = ($_POST['action'] ?? '') === 'random';
?>
<form method="post" action="<?= h($critUrl) ?>" id="assignForm">
  <?= csrf_field() ?>
  <?php foreach ($chosen as $id): ?><input type="hidden" name="staff[]" value="<?= $id ?>"><?php endforeach; ?>
  <input type="hidden" name="balance" value="<?= h($balance) ?>"><input type="hidden" name="scope" value="<?= h($scope) ?>">
  <?php if ($keepSame): ?><input type="hidden" name="keep_same" value="1"><?php endif; ?>

  <div class="alert <?= $isRandom ? 'alert-info' : 'alert-light border' ?> d-flex align-items-center flex-wrap gap-2">
    <span><i class="bi <?= $isRandom ? 'bi-shuffle' : 'bi-pencil-square' ?>"></i>
      <?= $isRandom ? 'ผลการสุ่ม (ยังไม่บันทึก)' : 'แก้ไขการมอบหมาย' ?> — <strong><?= h($project['code']) ?></strong> <?= h($roundText) ?> ·
      เปลี่ยนผู้ปฏิบัติงานในช่อง หรือกด <i class="bi bi-arrow-left-right"></i> ที่ 2 แถวเพื่อสลับงาน</span>
    <span class="ms-auto d-flex gap-2">
      <?php if ($isRandom): ?><button type="button" class="btn btn-sm btn-outline-warning" id="wheelReplay"><i class="bi bi-bullseye"></i> ดูวงล้ออีกครั้ง</button><?php endif; ?>
      <?php if ($chosen): ?><button name="action" value="random" class="btn btn-sm btn-outline-primary" formnovalidate><i class="bi bi-shuffle"></i> สุ่มใหม่</button><?php endif; ?>
      <a href="<?= h($critUrl) ?>" class="btn btn-sm btn-light">เปลี่ยนเงื่อนไข / พนักงาน</a>
    </span>
  </div>

  <div class="card mb-3" data-reveal>
    <div class="card-header"><strong>สรุปงานรายคน</strong> <span class="small text-muted">— ปรับตามการเปลี่ยนแปลงในตารางทันที</span></div>
    <div class="card-body reveal-item">
      <div class="table-responsive"><table class="table table-sm mb-0 align-middle" id="sumTable">
        <thead><tr><th>ผู้ปฏิบัติงาน</th><th class="ea-num">EA</th><th class="ea-num">ครัวเรือน</th><th class="ea-num">ประชากร</th><th style="width:35%"></th></tr></thead>
        <tbody></tbody>
      </table></div>
    </div>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover mb-0 ea-table align-middle" id="assignTable">
        <thead><tr><th>รอบ/งวด</th><th>รหัส EA</th><th>PSU</th><th>พื้นที่</th><th class="ea-num">ครัวเรือน</th><th class="ea-num">ประชากร</th><th>สถานะ</th><th style="min-width:240px">ผู้ปฏิบัติงาน</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $id = (int)$r['pe_id']; $cur = $assign[$id] ?? null; $orig = $r['assigned_user_id'] ? (int)$r['assigned_user_id'] : null; ?>
          <tr data-hh="<?= (int)$r['households'] ?>" data-pop="<?= (int)$r['population'] ?>" data-ea="<?= (int)$r['ea_id'] ?>">
            <td class="small text-nowrap"><?= h($r['round_label'] ?: '-') ?></td>
            <td class="ea-code"><?= h($r['ea_code']) ?></td>
            <td class="ea-code small"><?= h($r['psu_no'] ?: '-') ?></td>
            <td class="small"><?= h(ea_place($r)) ?></td>
            <td class="ea-num"><?= number_format((int)$r['households']) ?></td>
            <td class="ea-num"><?= number_format((int)$r['population']) ?></td>
            <td><?= ea_status_badge($r['status']) ?></td>
            <td><select name="assign[<?= $id ?>]" class="form-select form-select-sm as-sel" data-orig="<?= (int)$orig ?>">
              <option value="">— ไม่กำหนด —</option>
              <optgroup label="พนักงานที่รับผิดชอบ"><?php foreach ($chosen as $uid): ?><option value="<?= $uid ?>" <?= $cur === $uid ? 'selected' : '' ?>><?= h($names[$uid] ?? '') ?></option><?php endforeach; ?></optgroup>
              <optgroup label="บุคลากรอื่น"><?php foreach ($staffAll as $uid => $u): if (in_array($uid, $chosen, true)) { continue; } ?><option value="<?= $uid ?>" <?= $cur === $uid ? 'selected' : '' ?>><?= h($u['full_name']) ?></option><?php endforeach; ?></optgroup>
            </select></td>
            <td><button type="button" class="btn btn-sm btn-light as-swap" title="สลับงาน: กดที่แถวนี้ แล้วกดที่อีกแถว"><i class="bi bi-arrow-left-right"></i></button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer d-flex align-items-center gap-2 ea-bulk">
      <button name="action" value="save" class="btn btn-success"><i class="bi bi-check-lg"></i> บันทึกการมอบหมาย</button>
      <span class="small text-muted">เปลี่ยนจากที่บันทึกไว้ <strong id="changedCount">0</strong> EA</span>
      <a href="project_eas.php?<?= h(http_build_query(array_filter(['id' => $pid, 'fy' => null]))) ?>" class="btn btn-link ms-auto">ไปหน้า EA ของโครงการ</a>
    </div>
  </div>
</form>
<?php if ($isRandom): ?>
<script type="application/json" id="wheelData"><?= json_encode([
    'staff' => array_map(fn($id) => ['id' => $id, 'name' => $names[$id] ?? ''], $chosen),
    'ids'   => $randomIds ?? [],
    'title' => $project['code'] . ' ' . $roundText,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php $footerScripts[] = asset_v('modules/ea/assets/js/wheel.js'); ?>
<?php endif; ?>
<style>
  #assignTable tr.as-picked > td { background: #fff7d6; }
  #assignTable tr.as-changed .as-sel { border-color: #f59e0b; background-color: #fffbeb; }
  #assignTable tr.as-flash > td { animation: asFlash .9s ease-out; }
  @keyframes asFlash { from { background: #d1fae5; } to { background: transparent; } }
  @media (prefers-reduced-motion: reduce) { #assignTable tr.as-flash > td { animation: none; } }
</style>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const table = document.getElementById('assignTable');
  const sels = [...table.querySelectorAll('.as-sel')];
  const sumBody = document.querySelector('#sumTable tbody');
  const nf = n => n.toLocaleString('th-TH');
  const nameOf = v => { const o = sels[0].querySelector('option[value="' + v + '"]'); return o ? o.textContent : ''; };
  const keepSame = <?= $keepSame ? 'true' : 'false' ?>;
  let dirty = false;                       // แก้ไขในตารางหลังเปิดหน้า (เตือนก่อนสุ่มใหม่)
  const refresh = () => {
    const sum = new Map();
    let changed = 0;
    sels.forEach(s => {
      const tr = s.closest('tr');
      const isChanged = s.value !== (s.dataset.orig === '0' ? '' : s.dataset.orig);
      tr.classList.toggle('as-changed', isChanged);
      changed += isChanged ? 1 : 0;
      const k = s.value || '';
      const v = sum.get(k) || { n: 0, hh: 0, pop: 0 };
      v.n++; v.hh += +tr.dataset.hh; v.pop += +tr.dataset.pop;
      sum.set(k, v);
    });
    document.getElementById('changedCount').textContent = nf(changed);
    const order = [...sels[0].querySelectorAll('option')].map(o => o.value);
    const max = Math.max(1, ...[...sum.values()].map(v => v.n));
    sumBody.innerHTML = '';
    [...sum.entries()].sort((a, b) => order.indexOf(a[0]) - order.indexOf(b[0])).forEach(([k, v]) => {
      const tr = document.createElement('tr');
      const name = k ? nameOf(k) : 'ยังไม่กำหนดผู้ปฏิบัติงาน';
      tr.innerHTML = '<td class="' + (k ? '' : 'text-danger') + '">' + escapeHtml(name) + '</td><td class="ea-num">' + nf(v.n) + '</td><td class="ea-num">' + nf(v.hh)
        + '</td><td class="ea-num">' + nf(v.pop) + '</td><td><span class="bar-track d-block"><span class="bar-fill" style="width:' + (v.n / max * 100) + '%"></span></span></td>';
      sumBody.append(tr);
    });
  };
  // เปลี่ยนผู้ปฏิบัติงาน: EA เดียวกันในหลายเดือนเปลี่ยนตามกัน (เมื่อเลือก "คนเดิม")
  sels.forEach(s => s.addEventListener('change', () => {
    if (keepSame) {
      const ea = s.closest('tr').dataset.ea;
      table.querySelectorAll('tr[data-ea="' + ea + '"] .as-sel').forEach(o => { o.value = s.value; });
    }
    dirty = true;
    refresh();
  }));
  // สลับงาน 2 แถว
  let picked = null;
  table.addEventListener('click', e => {
    const btn = e.target.closest('.as-swap');
    if (!btn) { return; }
    const tr = btn.closest('tr');
    if (!picked) { picked = tr; tr.classList.add('as-picked'); return; }
    if (picked === tr) { tr.classList.remove('as-picked'); picked = null; return; }
    const groupOf = row => keepSame ? [...table.querySelectorAll('tr[data-ea="' + row.dataset.ea + '"]')] : [row];
    const a = picked.querySelector('.as-sel').value, b = tr.querySelector('.as-sel').value;
    groupOf(picked).forEach(r => { r.querySelector('.as-sel').value = b; });
    groupOf(tr).forEach(r => { r.querySelector('.as-sel').value = a; });
    [picked, tr].forEach(r => { r.classList.remove('as-picked', 'as-flash'); void r.offsetWidth; r.classList.add('as-flash'); });
    picked = null;
    dirty = true;
    refresh();
  });
  document.getElementById('assignForm').addEventListener('submit', e => {
    if (e.submitter && e.submitter.value === 'random' && dirty
        && !confirm('สุ่มใหม่ — ผลที่แก้ไขในตารางจะถูกแทนที่ ดำเนินการต่อ?')) { e.preventDefault(); }
  });
  refresh();
});
</script>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
