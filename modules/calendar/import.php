<?php
/**
 * นำเข้ากิจกรรมจากไฟล์ CSV (หรือ Excel .xlsx) — ผู้บันทึกกิจกรรมขึ้นไป
 *   คอลัมน์: วันที่ | ถึงวันที่ | เวลาเริ่ม | เวลาสิ้นสุด | กิจกรรม | สถานที่ | ผู้รับผิดชอบ | ลิงก์ | หมายเหตุ
 *   ผู้รับผิดชอบ: ชื่อ-นามสกุล (หรือชื่อผู้ใช้) คั่นด้วย , หรือ ; — หรือคำว่า ทุกคน / ข้าราชการ / พนักงาน
 * ขั้นตอน: อัปโหลด → ตรวจสอบ → ยืนยัน (ผู้รับผิดชอบได้รับแจ้งเตือนเหมือนเพิ่มทีละรายการ)
 */
require_once __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/libs/simplexlsx/SimpleXLSX.php';
$me = require_module(CAL_MODULE, 'editor');
$pdo = db();
$sessKey = 'cal_import';
const CAL_IMPORT_HEADERS = ['วันที่', 'ถึงวันที่', 'เวลาเริ่ม', 'เวลาสิ้นสุด', 'กิจกรรม', 'สถานที่', 'ผู้รับผิดชอบ', 'ลิงก์', 'หมายเหตุ'];

// ---------- ไฟล์ตัวอย่าง ----------
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="calendar_template.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, CAL_IMPORT_HEADERS);
    $d = fn($days) => date('j/n/', strtotime("+$days days")) . ((int)date('Y', strtotime("+$days days")) + 543);
    fputcsv($out, [$d(1), '', '09:00', '12:00', 'ประชุมประจำเดือน', 'ห้องประชุมชั้น 2', 'ทุกคน', 'https://example.com/agenda', 'เตรียมรายงานผลการดำเนินงาน']);
    fputcsv($out, [$d(2), $d(4), '08:30', '16:30', 'ลงพื้นที่สำรวจ', 'อ.ชุมแพ', 'พนักงาน', '', '']);
    fputcsv($out, [$d(5), '', '', '', 'อบรมหลักสูตร Big Data', 'กรุงเทพฯ', 'ข้าราชการ', '', 'ทั้งวัน (ไม่ใส่เวลา)']);
    fputcsv($out, [$d(6), '', '13:00', '15:00', 'ตรวจเยี่ยมศูนย์ดิจิทัลชุมชน', 'บ้านเสือเฒ่า', 'นายณชพัฒน์ ไชยคำ, นางสาวศิรินันท์ นามพรหม', '', '']);
    fclose($out);
    exit;
}

/** หัวคอลัมน์ → ฟิลด์ */
function cal_header_field(string $h): ?string
{
    $h = preg_replace('/\s+/u', '', mb_strtolower($h));
    $map = [
        'end_date' => ['ถึงวันที่', 'วันที่สิ้นสุด', 'enddate'], 'event_date' => ['วันที่', 'วันที่เริ่ม', 'date'],
        'start_time' => ['เวลาเริ่ม', 'เริ่ม', 'start'], 'end_time' => ['เวลาสิ้นสุด', 'สิ้นสุด', 'ถึงเวลา', 'end'],
        'title' => ['กิจกรรม', 'ชื่อกิจกรรม', 'เรื่อง', 'title'], 'location' => ['สถานที่', 'location'],
        'people' => ['ผู้รับผิดชอบ', 'ผู้เข้าร่วม', 'ผู้ปฏิบัติงาน'], 'link' => ['ลิงก์', 'ลิงค์', 'ลิงก์ประกอบ', 'ลิงค์ประกอบ', 'link', 'url'],
        'note' => ['หมายเหตุ', 'note'],
    ];
    foreach ($map as $f => $names) {
        if (in_array($h, $names, true)) {
            return $f;
        }
    }
    return null;
}

/** อ่านไฟล์ → [rows, errors] */
function cal_parse_file(string $path, string $name): array
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
        $raw = text_to_utf8(file_get_contents($path));     // ตัด BOM + แปลง Windows-874 (Excel ภาษาไทย)
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $table[] = $r;
        }
    } else {
        return [[], ['รองรับไฟล์ .csv หรือ .xlsx เท่านั้น']];
    }
    $map = null;
    $rows = [];
    $errors = [];
    foreach ($table as $i => $r) {
        $line = $i + 1;
        if ($map === null) {
            $mm = [];
            foreach ($r as $c => $hd) {
                $f = cal_header_field((string)$hd);
                if ($f && !isset($mm[$f])) {
                    $mm[$f] = $c;
                }
            }
            if (isset($mm['event_date'], $mm['title'])) {
                $map = $mm;
            }
            continue;
        }
        $get = fn($f) => isset($map[$f]) ? trim((string)($r[$map[$f]] ?? '')) : '';
        if ($get('title') === '' && $get('event_date') === '') {
            continue;
        }
        $row = ['line' => $line, 'title' => mb_substr($get('title'), 0, 255), 'location' => mb_substr($get('location'), 0, 255),
                'link' => $get('link'), 'note' => $get('note'), 'people_text' => $get('people'), 'errors' => []];
        $row['event_date'] = parse_date_flexible($get('event_date'));
        $row['end_date'] = $get('end_date') !== '' ? parse_date_flexible($get('end_date')) : null;
        $row['start_time'] = cal_parse_time($get('start_time'));
        $row['end_time'] = cal_parse_time($get('end_time'));
        [$row['users'], $row['missing']] = cal_parse_people($row['people_text']);
        if ($row['title'] === '') {
            $row['errors'][] = 'ไม่มีชื่อกิจกรรม';
        }
        if (!$row['event_date']) {
            $row['errors'][] = 'วันที่ไม่ถูกต้อง "' . $get('event_date') . '"';
        }
        if ($get('end_date') !== '' && !$row['end_date']) {
            $row['errors'][] = 'ถึงวันที่ไม่ถูกต้อง';
        } elseif ($row['end_date'] && $row['event_date'] && $row['end_date'] < $row['event_date']) {
            $row['errors'][] = 'ถึงวันที่ก่อนวันที่เริ่ม';
        }
        if ($row['end_date'] === $row['event_date']) {
            $row['end_date'] = null;
        }
        if ($row['start_time'] === false || $row['end_time'] === false) {
            $row['errors'][] = 'รูปแบบเวลาไม่ถูกต้อง (ใช้ 09:00)';
        }
        if ($row['link'] !== '' && !cal_safe_link($row['link'])) {
            $row['errors'][] = 'ลิงก์ต้องขึ้นต้นด้วย http:// หรือ https://';
        }
        if (!$row['users']) {
            $row['errors'][] = 'ไม่พบผู้รับผิดชอบ';
        }
        $rows[] = $row;
    }
    if ($map === null) {
        $errors[] = 'ไม่พบแถวหัวตาราง — ต้องมีคอลัมน์ "วันที่" และ "กิจกรรม" (ดาวน์โหลดไฟล์ตัวอย่าง)';
    }
    return [$rows, $errors];
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
            [$rows, $errors] = cal_parse_file($f['tmp_name'], $f['name']);
            if ($rows) {
                $dup = $pdo->prepare('SELECT id FROM cal_events WHERE event_date = ? AND title = ? AND start_time <=> ? LIMIT 1');
                foreach ($rows as &$r) {
                    $r['duplicate'] = false;
                    if (!$r['errors']) {
                        $dup->execute([$r['event_date'], $r['title'], $r['start_time']]);
                        $r['duplicate'] = (bool)$dup->fetchColumn();
                    }
                }
                unset($r);
                $_SESSION[$sessKey] = ['file' => $f['name'], 'rows' => $rows];
                $step = 'preview';
            }
        }
    } elseif (post('action') === 'import' && !empty($_SESSION[$sessKey])) {
        $skipDup = !empty($_POST['skip_duplicate']);
        $n = $skipped = $people = 0;
        $pdo->beginTransaction();
        $ins = $pdo->prepare('INSERT INTO cal_events (event_date, end_date, start_time, end_time, title, location, link, note, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insU = $pdo->prepare('INSERT IGNORE INTO cal_event_users (event_id, user_id) VALUES (?, ?)');
        foreach ($_SESSION[$sessKey]['rows'] as $r) {
            if ($r['errors'] || ($skipDup && $r['duplicate'])) {
                $skipped++;
                continue;
            }
            $ins->execute([$r['event_date'], $r['end_date'], $r['start_time'], $r['end_time'], $r['title'],
                           $r['location'] ?: null, $r['link'] ?: null, $r['note'] ?: null, $me['id']]);
            $eid = (int)$pdo->lastInsertId();
            foreach ($r['users'] as $uid) {
                $insU->execute([$eid, $uid]);
            }
            $people += count($r['users']);
            $n++;
        }
        $pdo->commit();
        unset($_SESSION[$sessKey]);
        audit_log('cal_import', "$n กิจกรรม ข้าม $skipped", CAL_MODULE);
        flash("นำเข้ากิจกรรม $n รายการ" . ($skipped ? " · ข้าม $skipped รายการ" : '') . ($n ? ' — แจ้งเตือนผู้รับผิดชอบแล้ว' : ''), $n ? 'success' : 'warning');
        redirect('list.php');
    }
}

$active = 'import';
$pageTitle = 'นำเข้ากิจกรรมจากไฟล์ CSV';
$pageActions = '<a href="?template=1" class="btn btn-outline-success"><i class="bi bi-download"></i> ดาวน์โหลดไฟล์ตัวอย่าง</a>';
require __DIR__ . '/header.php';
$users = cal_users();
?>
<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>

<?php if ($step === 'upload'): ?>
<div class="row g-3">
  <div class="col-lg-5">
    <form method="post" enctype="multipart/form-data" class="card">
      <div class="card-header"><strong><i class="bi bi-upload"></i> เลือกไฟล์</strong></div>
      <div class="card-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="preview">
        <input type="file" name="file" accept=".csv,.xlsx" class="form-control mb-3" required>
        <button class="btn btn-primary"><i class="bi bi-eye"></i> ตรวจสอบข้อมูลก่อนนำเข้า</button>
      </div>
    </form>
  </div>
  <div class="col-lg-7"><div class="card"><div class="card-body small">
    <strong>รูปแบบไฟล์</strong> (แถวแรกเป็นหัวตาราง — ดาวน์โหลดไฟล์ตัวอย่างแล้วแก้ใน Excel ได้เลย)
    <div class="table-responsive"><table class="table table-sm table-bordered my-2 text-nowrap"><tr class="table-light"><?php foreach (CAL_IMPORT_HEADERS as $hd): ?><td><?= h($hd) ?></td><?php endforeach; ?></tr>
      <tr><td>29/10/2569</td><td></td><td>09:00</td><td>12:00</td><td>ประชุมประจำเดือน</td><td>ห้องประชุมชั้น 2</td><td>ทุกคน</td><td>https://…</td><td></td></tr></table></div>
    <ul class="mb-0">
      <li><strong>วันที่</strong>: 29/10/2569 · 29/10/2026 · 2026-10-29 · 29 ต.ค. 2569 — <strong>ถึงวันที่</strong> ใส่เฉพาะกิจกรรมหลายวัน</li>
      <li><strong>เวลา</strong>: 09:00 หรือ 9.00 — ไม่ใส่ทั้งสองช่อง = ทั้งวัน</li>
      <li><strong>ผู้รับผิดชอบ</strong>: ชื่อ-นามสกุล (ไม่ใส่คำนำหน้าก็ได้) หรือชื่อผู้ใช้ คั่นด้วย <code>,</code> —
        หรือพิมพ์ <code>ทุกคน</code> <code>ข้าราชการ</code> <code>พนักงาน</code> (ผสมกันได้ เช่น <code>ข้าราชการ, นางสาวศิรินันท์ นามพรหม</code>)</li>
      <li>บันทึกจาก Excel เป็น "CSV UTF-8" หรือ CSV ธรรมดาก็ได้ (ระบบแปลงภาษาไทยให้) · รองรับไฟล์ .xlsx ด้วย</li>
    </ul>
  </div></div></div>
</div>
<?php else: $d = $_SESSION[$sessKey]; $ok = array_filter($d['rows'], fn($r) => !$r['errors']); $dups = array_filter($ok, fn($r) => $r['duplicate']); ?>
<form method="post">
  <?= csrf_field() ?><input type="hidden" name="action" value="import">
  <div class="card mb-3"><div class="card-body d-flex flex-wrap gap-3 align-items-center">
    <div><strong><?= h($d['file']) ?></strong> · <?= count($d['rows']) ?> แถว · พร้อมนำเข้า <strong class="text-success"><?= count($ok) ?></strong>
      <?= count($d['rows']) - count($ok) ? '· มีปัญหา <strong class="text-danger">' . (count($d['rows']) - count($ok)) . '</strong> (จะข้าม)' : '' ?></div>
    <?php if ($dups): ?><div class="form-check ms-auto"><input class="form-check-input" type="checkbox" name="skip_duplicate" value="1" id="skipDup" checked>
      <label class="form-check-label" for="skipDup">ข้ามกิจกรรมที่มีอยู่แล้ว (วันที่+เวลาเริ่ม+ชื่อเดียวกัน) <?= count($dups) ?> รายการ</label></div><?php endif; ?>
  </div></div>
  <div class="card">
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead><tr><th>แถว</th><th>วันที่</th><th>เวลา</th><th>กิจกรรม</th><th>สถานที่</th><th>ผู้รับผิดชอบ</th><th>สถานะ</th></tr></thead>
        <tbody>
        <?php foreach ($d['rows'] as $r): $bad = (bool)$r['errors']; ?>
          <tr class="<?= $bad ? 'table-danger' : ($r['duplicate'] ? 'table-secondary' : '') ?>">
            <td class="text-muted small"><?= (int)$r['line'] ?></td>
            <td class="small text-nowrap"><?= $r['event_date'] ? h(cal_range_text($r)) : '-' ?></td>
            <td class="small text-nowrap"><?= in_array(false, [$r['start_time'], $r['end_time']], true) ? '-' : h(cal_time_text($r)) ?></td>
            <td><?= h($r['title']) ?></td>
            <td class="small"><?= h($r['location']) ?></td>
            <td class="small cal-people"><?= cal_assignee_html($r['users'], $me['id'], 4) ?>
              <?php if ($r['missing']): ?><div class="text-danger">ไม่พบ: <?= h(implode(', ', $r['missing'])) ?></div><?php endif; ?></td>
            <td class="small"><?= $bad ? '<span class="text-danger">' . h(implode(' · ', $r['errors'])) . '</span>'
                : ($r['duplicate'] ? '<span class="badge text-bg-secondary">มีอยู่แล้ว</span>' : '<span class="badge text-bg-success">นำเข้า</span>') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="sticky-actions">
    <button class="btn btn-primary btn-lg" <?= $ok ? '' : 'disabled' ?>><i class="bi bi-check2-circle"></i> ยืนยันนำเข้า</button>
    <a href="import.php" class="btn btn-light btn-lg">เลือกไฟล์ใหม่</a>
  </div>
</form>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
