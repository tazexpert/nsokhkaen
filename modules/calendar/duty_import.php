<?php
/**
 * นำเข้าเวรสำนักงานรายเดือน (CSV / Excel .xlsx) — ผู้บันทึกกิจกรรมขึ้นไป
 *   คอลัมน์: วันที่ | ผู้รับผิดชอบ  (หลายคนในวันเดียว: คั่นด้วย , หรือแยกหลายแถววันที่เดียวกันก็ได้)
 *   ในปฏิทินแสดงเป็น "เวรสำนักงาน" ไม่ระบุเวลา/สถานที่ และใช้สีแถวต่างจากกิจกรรม
 *   วันที่มีเวรอยู่แล้ว → แทนที่ผู้รับผิดชอบด้วยข้อมูลใหม่ (นำเข้าซ้ำเพื่อแก้ไขได้)
 */
require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/libs/simplexlsx/SimpleXLSX.php';
$me = require_module(CAL_MODULE, 'editor');
$pdo = db();
$sessKey = 'cal_duty_import';

// ---------- ไฟล์ตัวอย่าง ----------
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="duty_template.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['วันที่', 'ผู้รับผิดชอบ']);
    $users = array_values(cal_users());
    $first = strtotime(date('Y-m-01', strtotime('first day of next month')));
    for ($i = 0, $n = 0; $i < 31 && date('m', $first + $i * 86400) === date('m', $first); $i++) {
        $ts = $first + $i * 86400;
        if (in_array((int)date('w', $ts), [0, 6], true)) {          // ตัวอย่าง: เวรวันเสาร์-อาทิตย์
            $name = $users ? preg_replace('/\s+/u', ' ', $users[$n++ % count($users)]['full_name']) : 'ชื่อ นามสกุล';
            fputcsv($out, [date('j/n/', $ts) . ((int)date('Y', $ts) + 543), $name]);
        }
    }
    fclose($out);
    exit;
}

/** อ่านไฟล์ → [วันที่ => [date, users[], missing[], lines[], errors[]], errors] */
function cal_duty_parse(string $path, string $name): array
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $table = [];
    if ($ext === 'xlsx') {
        $x = \Shuchkin\SimpleXLSX::parse($path);
        if (!$x) {
            return [[], ['อ่านไฟล์ Excel ไม่ได้: ' . \Shuchkin\SimpleXLSX::parseError()]];
        }
        $table = $x->rows(0);
    } elseif ($ext === 'csv' || $ext === 'txt') {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, text_to_utf8(file_get_contents($path)));    // ตัด BOM + แปลง Windows-874
        rewind($fh);
        while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $table[] = $r;
        }
    } else {
        return [[], ['รองรับไฟล์ .csv หรือ .xlsx เท่านั้น']];
    }
    // หาคอลัมน์จากหัวตาราง — ไม่มีหัวตาราง = คอลัมน์ 1 วันที่, คอลัมน์ 2 ผู้รับผิดชอบ
    $dateCol = 0;
    $peopleCol = 1;
    $start = 0;
    foreach (array_slice($table, 0, 5, true) as $i => $r) {
        $hd = array_map(fn($v) => preg_replace('/\s+/u', '', (string)$v), $r);
        $dc = array_search('วันที่', $hd, true);
        if ($dc !== false) {
            $dateCol = $dc;
            foreach ($hd as $c => $v) {
                if ($c !== $dc && preg_match('/ผู้รับผิดชอบ|ผู้อยู่เวร|ผู้ปฏิบัติ|ชื่อ|เวร/u', $v)) {
                    $peopleCol = $c;
                    break;
                }
            }
            $start = $i + 1;
            break;
        }
    }
    $days = [];
    $errors = [];
    foreach (array_slice($table, $start, null, true) as $i => $r) {
        $line = $i + 1;
        $dv = trim((string)($r[$dateCol] ?? ''));
        $pv = trim((string)($r[$peopleCol] ?? ''));
        if ($dv === '' && $pv === '') {
            continue;
        }
        $date = parse_date_flexible($dv);
        if (!$date) {
            $errors[] = "แถว $line: วันที่ไม่ถูกต้อง \"$dv\"";
            continue;
        }
        [$ids, $missing] = cal_parse_people($pv);
        $d = &$days[$date];
        $d ??= ['date' => $date, 'users' => [], 'missing' => [], 'lines' => []];
        $d['users'] = array_values(array_unique(array_merge($d['users'], $ids)));
        $d['missing'] = array_merge($d['missing'], $missing);
        $d['lines'][] = $line;
        unset($d);
    }
    ksort($days);
    return [$days, $errors];
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
            [$days, $errors] = cal_duty_parse($f['tmp_name'], $f['name']);
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
        redirect('index.php' . (preg_match('/^\d{4}-\d{2}$/', post('month')) ? '?m=' . post('month') : ''));
    }
}

$active = 'duty';
$pageTitle = 'นำเข้าเวรสำนักงาน';
$pageActions = '<a href="?template=1" class="btn btn-outline-success"><i class="bi bi-download"></i> ดาวน์โหลดไฟล์ตัวอย่าง</a>';
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
        <input type="file" name="file" accept=".csv,.xlsx" class="form-control mb-3" required>
        <button class="btn btn-primary"><i class="bi bi-eye"></i> ตรวจสอบข้อมูลก่อนนำเข้า</button>
      </div>
    </form>
  </div>
  <div class="col-lg-7"><div class="card"><div class="card-body small">
    <strong>รูปแบบไฟล์</strong> — 2 คอลัมน์ (แถวแรกเป็นหัวตาราง)
    <table class="table table-sm table-bordered my-2 w-auto"><tr class="table-light"><td>วันที่</td><td>ผู้รับผิดชอบ</td></tr>
      <tr><td>1/11/2569</td><td>นางสาวจงกล ยงค์สถิตย์สุข</td></tr>
      <tr><td>2/11/2569</td><td>นายโสภณ เพ็งค่ำ, นางรุ่งกาล บุราณรักษ์</td></tr></table>
    <ul class="mb-0">
      <li><strong>วันที่</strong>: 1/11/2569 · 1/11/2026 · 2026-11-01 · 1 พ.ย. 2569</li>
      <li><strong>ผู้รับผิดชอบ</strong>: ชื่อ-นามสกุล (ไม่ใส่คำนำหน้าก็ได้) — หลายคนคั่นด้วย <code>,</code> หรือแยกหลายแถววันที่เดียวกัน</li>
      <li>ในปฏิทินแสดงเป็น <span class="cal-ev duty d-inline-block px-2">เวรสำนักงาน</span> ไม่ระบุเวลา/สถานที่</li>
      <li>นำเข้าเดือนเดิมซ้ำได้ — วันที่มีเวรอยู่แล้วจะ<strong>เปลี่ยนผู้อยู่เวร</strong>ตามไฟล์ใหม่</li>
    </ul>
  </div></div></div>
</div>
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
            <td class="text-nowrap"><?= h(cal_date_text($x['date'])) ?> <span class="small text-muted">(แถว <?= h(implode(', ', $x['lines'])) ?>)</span></td>
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
    <a href="duty_import.php" class="btn btn-light btn-lg">เลือกไฟล์ใหม่</a>
  </div>
</form>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
