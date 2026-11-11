<?php
/**
 * นำเข้าเวรสำนักงานรายเดือน (Excel .xlsx ตามแบบฟอร์มตารางเวร) — ผู้ดูแลปฏิทิน
 *   แต่ละชีต = 1 เดือน (ชื่อชีตเป็นชื่อเดือน เช่น "ตุลาคม") · แถวแรก = วันที่ 1–31 · คอลัมน์ A = ชื่อผู้อยู่เวร
 *   ช่องที่ใส่เครื่องหมาย (เช่น "/") = อยู่เวรวันนั้น · ปี: จากปีงบประมาณที่เลือก (ต.ค.–ธ.ค. = ปีก่อนหน้า) หรือปีในชื่อชีต
 *   ในปฏิทินแสดงเป็น "เวรสำนักงาน" ไม่ระบุเวลา/สถานที่ และใช้สีแถวต่างจากกิจกรรม
 *   วันที่มีเวรอยู่แล้ว → แทนที่ผู้รับผิดชอบด้วยข้อมูลใหม่ (นำเข้าซ้ำเพื่อแก้ไขได้)
 */
require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/libs/simplexlsx/SimpleXLSX.php';
require_once APP_ROOT . '/libs/simplexlsx/SimpleXLSXGen.php';
$me = require_module(CAL_MODULE, 'admin');   // นำเข้าเวรสำนักงาน: เฉพาะผู้ดูแลปฏิทิน
$pdo = db();
$sessKey = 'cal_duty_import';
$curFy = (int)date('Y') + 543 + ((int)date('n') >= 10 ? 1 : 0);      // ปีงบประมาณปัจจุบัน (พ.ศ.)
const DUTY_FY_MONTHS = [10, 11, 12, 1, 2, 3, 4, 5, 6, 7, 8, 9];

/** เดือน/ปี ค.ศ. จากชื่อชีต ("ตุลาคม", "ต.ค. 69", "พฤศจิกายน 2569") — ไม่มีปีในชื่อ = ตามปีงบประมาณ */
function cal_duty_sheet_month(string $name, int $fy): ?array
{
    $k = preg_replace('/[\s.]/u', '', $name);
    $short = ['', 'มค', 'กพ', 'มีค', 'เมย', 'พค', 'มิย', 'กค', 'สค', 'กย', 'ตค', 'พย', 'ธค'];
    $m = null;
    for ($i = 1; $i <= 12; $i++) {
        if (str_contains($k, thai_month_long($i)) || str_starts_with($k, $short[$i])) {
            $m = $i;
            break;
        }
    }
    if (!$m) {
        return null;
    }
    if (preg_match('/(\d{4}|\d{2})(?!.*\d)/u', $name, $y)) {
        $y = (int)$y[1];
        $y = $y < 100 ? $y + 2500 : $y;              // 69 → 2569
        return [$m, $y > 2400 ? $y - 543 : $y];
    }
    return [$m, $fy - 543 - ($m >= 10 ? 1 : 0)];
}

/** อ่านไฟล์ตารางเวร → [วันที่ => [date, users[], missing[], lines[]], errors] */
function cal_duty_parse(string $path, string $name, int $fy): array
{
    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'xlsx') {
        return [[], ['รองรับไฟล์ Excel (.xlsx) ตามแบบฟอร์มตารางเวรเท่านั้น']];
    }
    $x = \Shuchkin\SimpleXLSX::parse($path);
    if (!$x) {
        return [[], ['อ่านไฟล์ Excel ไม่ได้: ' . \Shuchkin\SimpleXLSX::parseError()]];
    }
    $days = [];
    $errors = [];
    $people = [];                                     // ชื่อในไฟล์ → [ids, missing]
    foreach ($x->sheetNames() as $si => $sheet) {
        $ym = cal_duty_sheet_month((string)$sheet, $fy);
        $rows = $x->rows($si);
        if (!$ym) {
            if (array_filter(array_map(fn($r) => implode('', $r), $rows))) {
                $errors[] = "ชีต \"$sheet\": ไม่รู้ว่าเป็นเดือนใด — ตั้งชื่อชีตเป็นชื่อเดือน เช่น ตุลาคม (ข้ามชีตนี้)";
            }
            continue;
        }
        [$m, $y] = $ym;
        // แถวหัวตาราง: แถวแรกที่มีเลขวันที่ 1, 2, 3 …
        $head = null;
        $dayCol = [];
        foreach (array_slice($rows, 0, 5, true) as $ri => $r) {
            $map = [];
            foreach ($r as $ci => $v) {
                if (is_numeric($v) && (float)$v == (int)$v && (int)$v >= 1 && (int)$v <= 31) {
                    $map[$ci] = (int)$v;
                }
            }
            if (count($map) >= 3) {
                $head = $ri;
                $dayCol = $map;
                break;
            }
        }
        if ($head === null) {
            $errors[] = "ชีต \"$sheet\": ไม่พบแถววันที่ (1, 2, 3 …) ในแถวแรก";
            continue;
        }
        $nameCol = min(array_keys($dayCol)) > 0 ? 0 : null;
        foreach (array_slice($rows, $head + 1, null, true) as $ri => $r) {
            $person = trim(preg_replace('/\s+/u', ' ', (string)($r[$nameCol] ?? '')));
            if ($nameCol === null || $person === '') {
                continue;
            }
            $people[$person] ??= cal_parse_people($person);
            [$ids, $missing] = $people[$person];
            foreach ($dayCol as $ci => $day) {
                $mark = trim((string)($r[$ci] ?? ''));
                if ($mark === '' || $mark === '0' || $mark === '-') {
                    continue;
                }
                if (!checkdate($m, $day, $y)) {
                    $errors[] = "ชีต \"$sheet\" แถว " . ($ri + 1) . ": ไม่มีวันที่ $day ในเดือนนี้ (ข้าม)";
                    continue;
                }
                $date = sprintf('%04d-%02d-%02d', $y, $m, $day);
                $d = &$days[$date];
                $d ??= ['date' => $date, 'users' => [], 'missing' => [], 'lines' => []];
                $d['users'] = array_values(array_unique(array_merge($d['users'], $ids)));
                $d['missing'] = array_values(array_unique(array_merge($d['missing'], $missing)));
                $d['lines'][] = $sheet . ' แถว ' . ($ri + 1);
                unset($d);
            }
        }
    }
    ksort($days);
    return [$days, $errors];
}

// ---------- ไฟล์ตัวอย่าง: ตารางเวร 12 เดือนของปีงบประมาณ (ชื่อผู้อยู่เวรย้อนหลัง 6 เดือน) ----------
if (isset($_GET['template'])) {
    $fy = (int)($_GET['fy'] ?? $curFy) ?: $curFy;
    $users = cal_users();
    $recent = $pdo->query("SELECT DISTINCT eu.user_id FROM cal_events e JOIN cal_event_users eu ON eu.event_id = e.id
        WHERE e.kind = 'duty' AND e.event_date >= CURDATE() - INTERVAL 6 MONTH")->fetchAll(PDO::FETCH_COLUMN);
    $names = [];
    foreach ($users as $id => $u) {
        if (in_array((string)$id, array_map('strval', $recent), true) || (!$recent && $u['type'] !== 'driver')) {
            $names[] = preg_replace('/\s+/u', ' ', $u['full_name']);
        }
    }
    $xlsx = null;
    foreach (DUTY_FY_MONTHS as $m) {
        $y = $fy - 543 - ($m >= 10 ? 1 : 0);
        $rows = [array_merge([''], range(1, (int)date('t', mktime(0, 0, 0, $m, 1, $y))))];
        foreach ($names as $n) {
            $rows[] = [$n];
        }
        $xlsx = $xlsx ? $xlsx->addSheet($rows, thai_month_long($m)) : \Shuchkin\SimpleXLSXGen::fromArray($rows, thai_month_long($m));
        $xlsx->setColWidth('A', 30)->setColWidth('B:AF', 4)->freezePanes('B2');
    }
    $xlsx->downloadAs('duty_template_FY' . $fy . '.xlsx');
    exit;
}

$step = 'upload';
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (post('action') === 'preview') {
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            $errors[] = 'กรุณาเลือกไฟล์';
        } else {
            $fy = (int)post('fy') ?: $curFy;
            [$days, $errors] = cal_duty_parse($f['tmp_name'], $f['name'], $fy);
            if ($days) {
                $ex = $pdo->prepare("SELECT id FROM cal_events WHERE kind = 'duty' AND event_date = ? ORDER BY id LIMIT 1");
                foreach ($days as &$d) {
                    $ex->execute([$d['date']]);
                    $d['existing'] = (int)$ex->fetchColumn();
                    $d['old_users'] = $d['existing'] ? (cal_assignees([$d['existing']])[$d['existing']] ?? []) : [];
                }
                unset($d);
                $_SESSION[$sessKey] = ['file' => $f['name'], 'days' => $days];
                $step = 'preview';
            } elseif (!$errors) {
                $errors[] = 'ไม่พบข้อมูลในไฟล์';
            }
        }
    } elseif (post('action') === 'import' && !empty($_SESSION[$sessKey])) {
        $new = $updated = $same = $skipped = 0;
        $pdo->beginTransaction();
        $ins = $pdo->prepare("INSERT INTO cal_events (kind, event_date, title, created_by) VALUES ('duty', ?, ?, ?)");
        $insU = $pdo->prepare('INSERT IGNORE INTO cal_event_users (event_id, user_id) VALUES (?, ?)');
        $delU = $pdo->prepare('DELETE FROM cal_event_users WHERE event_id = ?');
        foreach ($_SESSION[$sessKey]['days'] as $d) {
            if (!$d['users']) {
                $skipped++;
                continue;
            }
            if ($d['existing']) {
                $a = $d['old_users'];
                $b = $d['users'];
                sort($a);
                sort($b);
                if ($a === $b) {
                    $same++;
                    continue;
                }
                $eid = $d['existing'];
                $delU->execute([$eid]);                 // แทนที่ผู้อยู่เวร → แจ้งเตือนผู้อยู่เวรใหม่
                $updated++;
            } else {
                $ins->execute([$d['date'], CAL_DUTY_TITLE, $me['id']]);
                $eid = (int)$pdo->lastInsertId();
                $new++;
            }
            foreach ($d['users'] as $uid) {
                $insU->execute([$eid, $uid]);
            }
        }
        $pdo->commit();
        unset($_SESSION[$sessKey]);
        audit_log('cal_duty_import', "เพิ่ม $new แก้ไข $updated เหมือนเดิม $same ข้าม $skipped", CAL_MODULE);
        flash("นำเข้าเวรสำนักงาน: เพิ่ม $new วัน" . ($updated ? " · เปลี่ยนผู้อยู่เวร $updated วัน" : '') . ($same ? " · เหมือนเดิม $same วัน" : '')
            . ($skipped ? " · ข้าม $skipped วัน (ไม่พบผู้รับผิดชอบ)" : '') . ($new + $updated ? ' — แจ้งเตือนผู้อยู่เวรแล้ว' : ''), $new + $updated ? 'success' : 'warning');
        redirect('./' . (preg_match('/^\d{4}-\d{2}$/', post('month')) ? '?m=' . post('month') : ''));
    }
}

$active = 'duty';
$pageTitle = 'นำเข้าเวรสำนักงาน';
$pageActions = '<a href="?template=1&amp;fy=' . $curFy . '" class="btn btn-outline-success" id="tplLink"><i class="bi bi-download"></i> ดาวน์โหลดแบบฟอร์ม</a>';
require __DIR__ . '/header.php';
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>

<?php if ($step === 'upload'): ?>
<div class="row g-3">
  <div class="col-lg-5">
    <form method="post" enctype="multipart/form-data" class="card">
      <div class="card-header"><strong><i class="bi bi-upload"></i> เลือกไฟล์เวรสำนักงาน</strong></div>
      <div class="card-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="preview">
        <label class="form-label">ปีงบประมาณ</label>
        <select name="fy" class="form-select mb-3" id="fySel">
          <?php for ($y = $curFy + 1; $y >= $curFy - 1; $y--): ?><option value="<?= $y ?>" <?= $y === $curFy ? 'selected' : '' ?>><?= $y ?> (ต.ค. <?= $y - 1 ?> – ก.ย. <?= $y ?>)</option><?php endfor; ?>
        </select>
        <label class="form-label">ไฟล์ตารางเวร (.xlsx)</label>
        <input type="file" name="file" accept=".xlsx" class="form-control mb-3" required>
        <button class="btn btn-primary"><i class="bi bi-eye"></i> ตรวจสอบข้อมูลก่อนนำเข้า</button>
      </div>
    </form>
  </div>
  <div class="col-lg-7"><div class="card"><div class="card-body small">
    <strong>รูปแบบไฟล์</strong> — Excel 1 ชีตต่อ 1 เดือน (ชื่อชีต = ชื่อเดือน)
    <div class="table-responsive"><table class="table table-sm table-bordered my-2 w-auto text-center"><tr class="table-light"><td></td><td>1</td><td>2</td><td>3</td><td>4</td><td>…</td><td>31</td></tr>
      <tr><td class="text-start">นายสุวิทย์ นาโพธิ์</td><td></td><td></td><td>/</td><td></td><td></td><td></td></tr>
      <tr><td class="text-start">นายธีรชัย หนูจิต</td><td>/</td><td></td><td></td><td></td><td></td><td>/</td></tr></table></div>
    <ul class="mb-0">
      <li><strong>ชื่อชีต</strong>: ชื่อเดือน เช่น ตุลาคม พฤศจิกายน — ปีตามปีงบประมาณที่เลือก (ต.ค.–ธ.ค. เป็นปีก่อนหน้า) หรือใส่ปีในชื่อชีต เช่น "ตุลาคม 2569"</li>
      <li><strong>แถวแรก</strong>: วันที่ 1–31 · <strong>คอลัมน์ A</strong>: ชื่อ-นามสกุล (ไม่ใส่คำนำหน้าก็ได้)</li>
      <li>ใส่เครื่องหมาย <code>/</code> (หรือข้อความใด ๆ) ในวันที่อยู่เวร — วันเดียวกันหลายคนได้</li>
      <li>ในปฏิทินแสดงเป็น <span class="cal-ev duty d-inline-block px-2">เวรสำนักงาน</span> ไม่ระบุเวลา/สถานที่</li>
      <li>นำเข้าเดือนเดิมซ้ำได้ — วันที่มีเวรอยู่แล้วจะ<strong>เปลี่ยนผู้อยู่เวร</strong>ตามไฟล์ใหม่</li>
    </ul>
  </div></div></div>
</div>
<script>
document.getElementById('fySel').addEventListener('change', e => { document.getElementById('tplLink').href = '?template=1&fy=' + e.target.value; });
</script>
<?php else:
    $d = $_SESSION[$sessKey];
    $ok = array_filter($d['days'], fn($x) => $x['users']);
    $months = array_count_values(array_map(fn($x) => substr($x['date'], 0, 7), $d['days']));
    arsort($months);
    $users = cal_users(); ?>
<form method="post">
  <?= csrf_field() ?><input type="hidden" name="action" value="import"><input type="hidden" name="month" value="<?= h((string)array_key_first($months)) ?>">
  <div class="card mb-3"><div class="card-body">
    <strong><?= h($d['file']) ?></strong> · <?= count($d['days']) ?> วัน · พร้อมนำเข้า <strong class="text-success"><?= count($ok) ?></strong>
    <?= count($d['days']) - count($ok) ? '· ไม่พบผู้รับผิดชอบ <strong class="text-danger">' . (count($d['days']) - count($ok)) . '</strong> วัน (จะข้าม)' : '' ?>
  </div></div>
  <div class="card">
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0 cal-table">
        <thead><tr><th>วันที่</th><th>ผู้รับผิดชอบ</th><th>สถานะ</th></tr></thead>
        <tbody>
        <?php foreach ($d['days'] as $x):
            $a = $x['old_users']; $b = $x['users']; sort($a); sort($b); ?>
          <tr class="<?= $x['users'] ? 'cal-duty' : 'table-danger' ?>">
            <td class="text-nowrap"><?= h(cal_date_text($x['date'])) ?> <span class="small text-muted">(<?= h(implode(', ', array_unique(array_map(fn($l) => explode(' แถว ', $l)[0], $x['lines'])))) ?>)</span></td>
            <td class="cal-people"><?= $x['users'] ? cal_assignee_html($x['users'], (int)$me['id'], 8) : '' ?>
              <?php if ($x['missing']): ?><div class="small text-danger">ไม่พบ: <?= h(implode(', ', $x['missing'])) ?></div><?php endif; ?>
              <?php if ($x['existing'] && $a !== $b && $a): ?><div class="small text-muted">เดิม: <?= h(implode(', ', array_map(fn($id) => isset($users[$id]) ? cal_person_text($users[$id]) : '#' . $id, $a))) ?></div><?php endif; ?></td>
            <td class="small text-nowrap"><?= !$x['users'] ? '<span class="text-danger">ข้าม</span>'
                : (!$x['existing'] ? '<span class="badge text-bg-success">เพิ่ม</span>'
                : ($a === $b ? '<span class="badge text-bg-secondary">เหมือนเดิม</span>' : '<span class="badge text-bg-warning">เปลี่ยนผู้อยู่เวร</span>')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="sticky-actions mt-3">
    <button class="btn btn-primary btn-lg" <?= $ok ? '' : 'disabled' ?>><i class="bi bi-check2-circle"></i> ยืนยันนำเข้า</button>
    <a href="duty_import" class="btn btn-light btn-lg">เลือกไฟล์ใหม่</a>
  </div>
</form>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
