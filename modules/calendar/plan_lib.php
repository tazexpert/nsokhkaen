<?php
/**
 * แผนการปฏิบัติงานรายเดือน (คน × วัน × รหัสงาน) — ใช้โดย plan.php / plan_edit.php / plan_import.php / plan_codes.php
 * - รหัสงาน: cal_plan_codes (โครงการสนาม เช่น สรง สศส ธปท โพล สพค + "*" = เวรศาลากลาง)
 * - ช่องหนึ่งมีได้หลายรหัส เช่น "สพค*" = ออกสนาม สพค และอยู่เวรศาลากลาง
 * - เวรศาลากลาง → กิจกรรม kind = 'guard' ในปฏิทิน (ตารางการปฏิบัติงาน + แจ้งเตือน) ผ่าน plan_sync_guard()
 * - เวรสำนักงาน ("เวร") ไม่เก็บในแผน — ดึงจากปฏิทิน (kind = 'duty') มาแสดง
 * - ไม่มีการแจ้งเตือนสำหรับงานสนาม
 */
require_once __DIR__ . '/lib.php';

const PLAN_DUTY_WORD = 'เวร';
const PLAN_MONTHS = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];

/** รหัสงานทั้งหมด [id => row] เรียงตามลำดับ */
function plan_codes(bool $activeOnly = false): array
{
    $out = [];
    foreach (db()->query('SELECT * FROM cal_plan_codes' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY kind = \'guard\', sort_order, id') as $c) {
        $out[(int)$c['id']] = $c;
    }
    return $out;
}

function plan_guard_code_id(array $codes): int
{
    foreach ($codes as $id => $c) {
        if ($c['kind'] === 'guard') {
            return $id;
        }
    }
    return 0;
}

/** "YYYY-MM" → [ปี ค.ศ., เดือน] (ค่าผิด = เดือนปัจจุบัน) */
function plan_ym(?string $m): array
{
    return preg_match('/^(\d{4})-(\d{2})$/', (string)$m, $x) && (int)$x[2] >= 1 && (int)$x[2] <= 12
        ? [(int)$x[1], (int)$x[2]] : [(int)date('Y'), (int)date('n')];
}

function plan_month_text(int $y, int $m): string
{
    return PLAN_MONTHS[$m - 1] . ' ' . ($y + 543);
}

function plan_get(int $y, int $m): ?array
{
    $st = db()->prepare('SELECT p.*, u.full_name AS updated_by_name FROM cal_plans p LEFT JOIN core_users u ON u.id = p.updated_by
                         WHERE p.plan_year = ? AND p.plan_month = ?');
    $st->execute([$y, $m]);
    return $st->fetch() ?: null;
}

/**
 * รายชื่อในแผน: ผู้มีบัญชีเรียงตามลำดับบุคลากร (sort_staff) แล้วตามด้วยผู้ไม่มีบัญชีตามลำดับที่เพิ่ม
 * แต่ละแถว: id, user_id, name, (username, sort_order, position ของผู้มีบัญชี)
 */
function plan_people(int $planId): array
{
    $st = db()->prepare('SELECT pp.id, pp.user_id, pp.sort_order AS pp_order, COALESCE(u.full_name, pp.name) AS name,
                                u.username, u.sort_order, u.position
                         FROM cal_plan_people pp LEFT JOIN core_users u ON u.id = pp.user_id
                         WHERE pp.plan_id = ? ORDER BY pp.sort_order, pp.id');
    $st->execute([$planId]);
    return plan_sort_people($st->fetchAll());
}

function plan_sort_people(array $rows): array
{
    $acc = array_values(array_filter($rows, fn($r) => !empty($r['user_id'])));
    $ext = array_values(array_filter($rows, fn($r) => empty($r['user_id'])));
    return array_merge(array_values(sort_staff($acc)), $ext);
}

/** รหัสงานในแผน [person_id][day] => [code_id, ...] */
function plan_cells(int $planId): array
{
    $st = db()->prepare('SELECT c.person_id, c.day, c.code_id FROM cal_plan_cells c JOIN cal_plan_people pp ON pp.id = c.person_id
                         JOIN cal_plan_codes k ON k.id = c.code_id WHERE pp.plan_id = ? ORDER BY k.kind = \'guard\', k.sort_order, k.id');
    $st->execute([$planId]);
    $out = [];
    foreach ($st as $r) {
        $out[(int)$r['person_id']][(int)$r['day']][] = (int)$r['code_id'];
    }
    return $out;
}

/** เวรสำนักงานจากปฏิทิน [user_id][day] => true */
function plan_duty_map(int $y, int $m): array
{
    $from = sprintf('%04d-%02d-01', $y, $m);
    $to = date('Y-m-t', strtotime($from));
    $st = db()->prepare("SELECT eu.user_id, e.event_date, COALESCE(e.end_date, e.event_date) AS end_date
                         FROM cal_events e JOIN cal_event_users eu ON eu.event_id = e.id
                         WHERE e.kind = 'duty' AND e.event_date <= ? AND COALESCE(e.end_date, e.event_date) >= ?");
    $st->execute([$to, $from]);
    $out = [];
    foreach ($st as $r) {
        for ($d = max($r['event_date'], $from); $d <= min($r['end_date'], $to); $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $out[(int)$r['user_id']][(int)substr($d, 8, 2)] = true;
        }
    }
    return $out;
}

/**
 * ข้อความในช่อง → [code ids, รหัสที่ไม่รู้จัก, มีคำว่า "เวร" หรือไม่]
 * รองรับ "สพค*", "สพค *", "สพค,โพล", "สพค+โพล" — "*" = เวรศาลากลาง · "เวร" = เวรสำนักงาน (ไม่เก็บ ดึงจากปฏิทิน)
 */
function plan_parse_cell(string $v, array $codes): array
{
    $v = trim(preg_replace('/\s+/u', ' ', $v));
    if ($v === '') {
        return [[], [], false];
    }
    $byCode = [];
    foreach ($codes as $id => $c) {
        $byCode[mb_strtolower($c['code'])] = $id;
    }
    $ids = [];
    $unknown = [];
    if (str_contains($v, '*') && isset($byCode['*'])) {
        $ids[] = $byCode['*'];
    }
    $v = str_replace('*', ' ', $v);
    $duty = false;
    foreach (preg_split('/[\s,+\/、]+/u', $v, -1, PREG_SPLIT_NO_EMPTY) as $tok) {
        $key = mb_strtolower($tok);
        if ($tok === PLAN_DUTY_WORD) {
            $duty = true;
        } elseif (isset($byCode[$key])) {
            $ids[] = $byCode[$key];
        } else {
            $unknown[] = $tok;
        }
    }
    return [array_values(array_unique($ids)), $unknown, $duty];
}

/** code ids → ข้อความแสดงในช่อง เช่น "สพค*" */
function plan_cell_text(array $ids, array $codes): string
{
    $txt = '';
    $guard = false;
    foreach ($ids as $id) {
        if (!isset($codes[$id])) {
            continue;
        }
        if ($codes[$id]['kind'] === 'guard') {
            $guard = true;
        } else {
            $txt .= ($txt === '' ? '' : ' ') . $codes[$id]['code'];
        }
    }
    return $txt . ($guard ? '*' : '');
}

/**
 * บันทึกแผนทั้งเดือน (แทนที่ของเดิม) แล้วสร้าง/ปรับเวรศาลากลางในปฏิทิน
 * $people: [['user_id' => int|null, 'name' => string, 'cells' => [day => [code ids]]], ...]
 */
function plan_save(int $y, int $m, array $people, ?string $note, int $actorId): array
{
    $pdo = db();
    $days = (int)date('t', strtotime(sprintf('%04d-%02d-01', $y, $m)));
    $pdo->beginTransaction();
    try {
        $plan = plan_get($y, $m);
        if ($plan) {
            $planId = (int)$plan['id'];
            $pdo->prepare('UPDATE cal_plans SET note = ?, updated_by = ?, updated_at = NOW() WHERE id = ?')->execute([$note, $actorId, $planId]);
            $pdo->prepare('DELETE FROM cal_plan_people WHERE plan_id = ?')->execute([$planId]);     // ช่องถูกลบตาม (CASCADE)
        } else {
            $pdo->prepare('INSERT INTO cal_plans (plan_year, plan_month, note, updated_by) VALUES (?, ?, ?, ?)')->execute([$y, $m, $note, $actorId]);
            $planId = (int)$pdo->lastInsertId();
        }
        $insP = $pdo->prepare('INSERT INTO cal_plan_people (plan_id, user_id, name, sort_order) VALUES (?, ?, ?, ?)');
        $insC = $pdo->prepare('INSERT IGNORE INTO cal_plan_cells (person_id, day, code_id) VALUES (?, ?, ?)');
        $seen = [];
        foreach (array_values($people) as $i => $p) {
            $uid = !empty($p['user_id']) ? (int)$p['user_id'] : null;
            if ($uid && isset($seen[$uid])) {
                continue;                        // ชื่อซ้ำในแผนเดียวกัน
            }
            $seen[$uid ?? 'x' . $i] = true;
            $insP->execute([$planId, $uid, mb_substr(trim((string)$p['name']), 0, 150), $i + 1]);
            $pid = (int)$pdo->lastInsertId();
            foreach ($p['cells'] ?? [] as $day => $ids) {
                if ((int)$day >= 1 && (int)$day <= $days) {
                    foreach ($ids as $cid) {
                        $insC->execute([$pid, (int)$day, (int)$cid]);
                    }
                }
            }
        }
        $guard = plan_sync_guard($y, $m, $actorId);
        $pdo->commit();
        return ['plan_id' => $planId, 'guard' => $guard];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** ลบแผนทั้งเดือน (รวมเวรศาลากลางในปฏิทินของเดือนนั้น) */
function plan_delete(int $y, int $m, int $actorId): void
{
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM cal_plans WHERE plan_year = ? AND plan_month = ?')->execute([$y, $m]);
    plan_sync_guard($y, $m, $actorId);
    $pdo->commit();
}

/**
 * เวรศาลากลางในแผน → กิจกรรม kind = 'guard' วันละ 1 รายการ
 * ผู้รับผิดชอบที่ไม่เปลี่ยน คงเวลามอบหมายเดิม (ไม่แจ้งเตือนซ้ำ) · ผู้ไม่มีบัญชีระบุชื่อไว้ในหมายเหตุ
 * คืน [created, updated, deleted]
 */
function plan_sync_guard(int $y, int $m, int $actorId): array
{
    $pdo = db();
    $from = sprintf('%04d-%02d-01', $y, $m);
    $to = date('Y-m-t', strtotime($from));
    $want = [];      // date => ['users' => [...], 'names' => [...]]
    $st = $pdo->prepare("SELECT c.day, pp.user_id, pp.name FROM cal_plan_cells c
                         JOIN cal_plan_people pp ON pp.id = c.person_id JOIN cal_plans p ON p.id = pp.plan_id
                         JOIN cal_plan_codes k ON k.id = c.code_id
                         WHERE p.plan_year = ? AND p.plan_month = ? AND k.kind = 'guard' ORDER BY pp.sort_order, pp.id");
    $st->execute([$y, $m]);
    foreach ($st as $r) {
        $date = sprintf('%04d-%02d-%02d', $y, $m, $r['day']);
        $want[$date] ??= ['users' => [], 'names' => []];
        if ($r['user_id']) {
            $want[$date]['users'][] = (int)$r['user_id'];
        } else {
            $want[$date]['names'][] = $r['name'];
        }
    }
    $have = [];
    $st = $pdo->prepare("SELECT id, event_date FROM cal_events WHERE kind = 'guard' AND event_date BETWEEN ? AND ? ORDER BY id");
    $st->execute([$from, $to]);
    foreach ($st as $r) {
        if (isset($have[$r['event_date']])) {             // ซ้ำวันเดียวกัน (ไม่ควรเกิด) → ลบส่วนเกิน
            $pdo->prepare('DELETE FROM cal_events WHERE id = ?')->execute([$r['id']]);
            continue;
        }
        $have[$r['event_date']] = (int)$r['id'];
    }
    $created = $updated = $deleted = 0;
    foreach ($have as $date => $eid) {
        if (!isset($want[$date])) {
            $pdo->prepare('DELETE FROM cal_events WHERE id = ?')->execute([$eid]);
            $deleted++;
        }
    }
    $insE = $pdo->prepare("INSERT INTO cal_events (kind, event_date, title, location, note, created_by) VALUES ('guard', ?, ?, ?, ?, ?)");
    $updE = $pdo->prepare('UPDATE cal_events SET note = ?, title = ? WHERE id = ?');
    $insU = $pdo->prepare('INSERT IGNORE INTO cal_event_users (event_id, user_id) VALUES (?, ?)');
    foreach ($want as $date => $w) {
        $note = $w['names'] ? 'ผู้ปฏิบัติงานที่ไม่มีบัญชีในระบบ: ' . implode(', ', $w['names']) : null;
        $users = array_values(array_unique($w['users']));
        if (isset($have[$date])) {
            $eid = $have[$date];
            $cur = cal_assignees([$eid])[$eid] ?? [];
            $add = array_diff($users, $cur);
            $del = array_diff($cur, $users);
            if ($del) {
                $pdo->exec('DELETE FROM cal_event_users WHERE event_id = ' . $eid . ' AND user_id IN (' . implode(',', array_map('intval', $del)) . ')');
            }
            foreach ($add as $uid) {
                $insU->execute([$eid, $uid]);
            }
            $updE->execute([$note, CAL_GUARD_TITLE, $eid]);
            if ($add || $del) {
                $updated++;
            }
        } else {
            $insE->execute([$date, CAL_GUARD_TITLE, 'ศาลากลางจังหวัดขอนแก่น', $note, $actorId]);
            $eid = (int)$pdo->lastInsertId();
            foreach ($users as $uid) {
                $insU->execute([$eid, $uid]);
            }
            $created++;
        }
    }
    return [$created, $updated, $deleted];
}

/* ---------- นำเข้าไฟล์ Excel รูปแบบ "แผนการปฏิบัติงาน…ประจำเดือน …" ---------- */

/**
 * ชื่อ → [id ผู้ใช้|null, วิธีจับคู่ 'exact' | 'near' | ''] — เทียบแบบไม่สนช่องว่าง/คำนำหน้า
 * ไม่ตรงทุกตัวอักษร → เลือกชื่อที่ใกล้เคียงที่สุด (≥ 90%) เช่น สะกดต่างกัน 1 ตัว (ให้ผู้ใช้ยืนยันในหน้าตรวจสอบ)
 */
function plan_match_user(string $name): array
{
    static $users = null;
    $strip = fn(string $n) => preg_replace('/^(นางสาว|นาง|นาย|ว่าที่ร้อยตรี|ว่าที่ร\.ต\.|ดร\.)/u', '', preg_replace('/\s+/u', '', $n));
    if ($users === null) {
        $users = [];
        foreach (db()->query('SELECT id, full_name FROM core_users WHERE is_active = 1 ORDER BY username REGEXP \'^[0-9]+$\' DESC, id') as $u) {
            $users[] = ['id' => (int)$u['id'], 'key' => $strip($u['full_name'])];
        }
    }
    $key = $strip($name);
    if ($key === '') {
        return [null, ''];
    }
    foreach ($users as $u) {
        if ($u['key'] === $key) {
            return [$u['id'], 'exact'];
        }
    }
    $best = null;
    $bestPct = 0;
    $second = 0;
    foreach ($users as $u) {
        similar_text($u['key'], $key, $pct);
        if ($pct > $bestPct) {
            $second = $bestPct;
            $bestPct = $pct;
            $best = $u['id'];
        } elseif ($pct > $second) {
            $second = $pct;
        }
    }
    return $bestPct >= 90 && $bestPct - $second >= 5 ? [$best, 'near'] : [null, ''];
}

/** หาเดือน/ปีจากข้อความหัวตาราง เช่น "…ประจำเดือน พฤศจิกายน พ.ศ. 2569" (ทนคำสะกดผิดเล็กน้อย) */
function plan_detect_month(string $text): ?array
{
    if (!preg_match('/(25\d\d|20\d\d)/u', $text, $ym)) {
        return null;
    }
    $year = (int)$ym[1] > 2400 ? (int)$ym[1] - 543 : (int)$ym[1];
    $best = 0;
    $bestScore = 0;
    $words = preg_split('/[\s\n]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($words as $w) {
        foreach (PLAN_MONTHS as $i => $mn) {
            $w2 = preg_replace('/^(ประจำเดือน|เดือน)/u', '', $w);
            if ($w2 === '') {
                continue;
            }
            similar_text($w2, $mn, $pct);
            if ($pct > $bestScore) {
                $bestScore = $pct;
                $best = $i + 1;
            }
        }
    }
    return $bestScore >= 80 ? [$year, $best] : null;
}

/**
 * อ่านไฟล์ .xlsx → ['month' => [y, m]|null, 'people' => [...], 'unknown' => [[ชื่อ, วัน, รหัส]], 'duty' => [[user_id|null, name, day]], 'errors' => []]
 * โครงสร้าง: หัวตารางมีเลขวันที่ 1…N เรียงกัน · คอลัมน์ก่อนวันที่ 1 = ลำดับ / ชื่อ / นามสกุล · หยุดที่แถว "หมายเหตุ"
 */
function plan_parse_xlsx(string $path, array $codes): array
{
    require_once APP_ROOT . '/libs/simplexlsx/SimpleXLSX.php';
    $res = ['month' => null, 'people' => [], 'unknown' => [], 'duty' => [], 'errors' => []];
    $x = \Shuchkin\SimpleXLSX::parse($path);
    if (!$x) {
        $res['errors'][] = 'อ่านไฟล์ Excel ไม่ได้: ' . \Shuchkin\SimpleXLSX::parseError();
        return $res;
    }
    $rows = $x->rows(0);
    $headRow = null;
    $dayCol = [];
    foreach (array_slice($rows, 0, 15, true) as $ri => $r) {
        $r = array_values($r);
        foreach ($r as $ci => $v) {
            if ((string)$v === '1' || $v === 1 || $v === 1.0) {
                $cols = [];
                for ($d = 1; $d <= 31 && isset($r[$ci + $d - 1]) && (int)$r[$ci + $d - 1] === $d && trim((string)$r[$ci + $d - 1]) !== ''; $d++) {
                    $cols[$d] = $ci + $d - 1;
                }
                if (count($cols) >= 28) {
                    $headRow = $ri;
                    $dayCol = $cols;
                    break 2;
                }
            }
        }
    }
    if ($headRow === null) {
        $res['errors'][] = 'ไม่พบหัวตารางวันที่ 1–28/31 ในไฟล์ (ตรวจว่าเป็นไฟล์แผนการปฏิบัติงานรูปแบบเดิม)';
        return $res;
    }
    $title = '';
    foreach (array_slice($rows, 0, $headRow, true) as $r) {
        $title .= ' ' . implode(' ', array_map('strval', $r));
    }
    $res['month'] = plan_detect_month($title);
    $firstDay = $dayCol[1];
    foreach (array_slice($rows, $headRow + 1, null, true) as $ri => $r) {
        $r = array_values($r);
        $lead = array_map(fn($v) => trim((string)$v), array_slice($r, 0, $firstDay));
        $joined = implode(' ', $lead);
        if (str_contains($joined, 'หมายเหตุ')) {
            break;
        }
        // ชื่อ = คอลัมน์ก่อนวันที่ 1 ที่ไม่ใช่ตัวเลขลำดับ
        $nameParts = array_filter($lead, fn($v) => $v !== '' && !preg_match('/^\d+$/', $v));
        $name = trim(preg_replace('/\s+/u', ' ', implode(' ', $nameParts)));
        if ($name === '' || in_array($name, ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'], true)) {
            continue;
        }
        [$uid, $how] = plan_match_user($name);
        $cells = [];
        foreach ($dayCol as $d => $ci) {
            $v = trim((string)($r[$ci] ?? ''));
            if ($v === '') {
                continue;
            }
            [$ids, $unk, $duty] = plan_parse_cell($v, $codes);
            if ($ids) {
                $cells[$d] = $ids;
            }
            foreach ($unk as $u) {
                $res['unknown'][] = [$name, $d, $u];
            }
            if ($duty) {
                $res['duty'][] = [$uid, $name, $d];
            }
        }
        $res['people'][] = ['user_id' => $uid, 'match' => $how, 'name' => $name, 'cells' => $cells];
    }
    if (!$res['people']) {
        $res['errors'][] = 'ไม่พบรายชื่อผู้ปฏิบัติงานในไฟล์';
    }
    return $res;
}
