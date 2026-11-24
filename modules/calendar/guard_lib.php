<?php
/**
 * เวรศาลากลางจากคำสั่งจังหวัด (นำเข้าไฟล์ PDF ที่สแกน — guard_import.php)
 * - เก็บใน cal_guard_shifts: วันที่ × ช่วง (กลางวัน/กลางคืน) × หน้าที่ (ผู้ปฏิบัติหน้าที่เวร/ผู้ตรวจเวร) × บุคลากร
 * - ปฏิทิน: กิจกรรม kind 'guard' ต่อ วันที่ × ช่วง × หน้าที่ ชื่อ "เวรศาลากลาง-กลางคืน · ผู้ตรวจเวร"
 * - เดือนที่มีข้อมูลนี้ = ใช้แทนเวรศาลากลางจากแผนการปฏิบัติงาน (plan_sync_guard ไม่แก้กิจกรรมเวรของเดือนนั้น)
 *   และทำเครื่องหมาย * ในแผนการปฏิบัติงานของเดือนนั้นตามข้อมูลนี้
 */

const GUARD_SHIFTS = ['day' => 'กลางวัน', 'night' => 'กลางคืน'];
const GUARD_ROLES = ['duty' => 'ผู้ปฏิบัติหน้าที่เวร', 'inspector' => 'ผู้ตรวจเวร'];
const GUARD_PLACE = 'ศาลากลางจังหวัดขอนแก่น (หลังเก่า)';

/** มีตาราง cal_guard_shifts (migration 20261215) แล้วหรือยัง */
function guard_has_table(): bool
{
    static $has = null;
    if ($has === null) {
        try {
            $has = (bool)db()->query("SHOW TABLES LIKE 'cal_guard_shifts'")->fetch();
        } catch (PDOException $e) {
            $has = false;
        }
    }
    return $has;
}

/** ชื่อกิจกรรมในปฏิทิน เช่น "เวรศาลากลาง-กลางคืน · ผู้ตรวจเวร" */
function guard_title(string $shift, string $role): string
{
    return CAL_GUARD_TITLE . '-' . GUARD_SHIFTS[$shift] . ' · ' . GUARD_ROLES[$role];
}

/** เวรของเดือน เรียงตามวันที่ ช่วง หน้าที่ */
function guard_month_rows(int $y, int $m): array
{
    if (!guard_has_table()) {
        return [];
    }
    $st = db()->prepare("SELECT * FROM cal_guard_shifts WHERE guard_date BETWEEN ? AND ?
        ORDER BY guard_date, FIELD(shift, 'day', 'night'), FIELD(role, 'duty', 'inspector'), sort_order, id");
    $from = sprintf('%04d-%02d-01', $y, $m);
    $st->execute([$from, date('Y-m-t', strtotime($from))]);
    return $st->fetchAll();
}

/** เดือนนี้นำเข้าเวรศาลากลางจากคำสั่งจังหวัดแล้วหรือยัง */
function guard_month_has(int $y, int $m): bool
{
    if (!guard_has_table()) {
        return false;
    }
    $from = sprintf('%04d-%02d-01', $y, $m);
    $st = db()->prepare('SELECT 1 FROM cal_guard_shifts WHERE guard_date BETWEEN ? AND ? LIMIT 1');
    $st->execute([$from, date('Y-m-t', strtotime($from))]);
    return (bool)$st->fetch();
}

/**
 * บันทึกเวรของเดือน (แทนที่ของเดิมทั้งเดือน) → กิจกรรมในปฏิทิน + เครื่องหมาย * ในแผนการปฏิบัติงาน
 * $rows = [['date' => Y-m-d, 'shift' => day|night, 'role' => duty|inspector, 'user_id' => int|null, 'name' => string], ...]
 * คืน ['rows' => n, 'events' => [created, updated, deleted], 'plan' => n วันที่ทำเครื่องหมาย]
 */
function guard_save_month(int $y, int $m, array $rows, int $actorId): array
{
    $pdo = db();
    $from = sprintf('%04d-%02d-01', $y, $m);
    $to = date('Y-m-t', strtotime($from));
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM cal_guard_shifts WHERE guard_date BETWEEN ? AND ?')->execute([$from, $to]);
        $ins = $pdo->prepare('INSERT INTO cal_guard_shifts (guard_date, shift, role, user_id, name, sort_order, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach (array_values($rows) as $i => $r) {
            $ins->execute([$r['date'], $r['shift'], $r['role'], $r['user_id'] ?: null, mb_substr($r['name'], 0, 150), $i + 1, $actorId]);
        }
        $ev = guard_sync_events($y, $m, $actorId);
        $plan = guard_mark_plan($y, $m);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['rows' => count($rows), 'events' => $ev, 'plan' => $plan];
}

/**
 * cal_guard_shifts ของเดือน → กิจกรรม kind 'guard' (วันที่ × ช่วง × หน้าที่) — แทนเวรศาลากลางเดิมของเดือนนั้นทั้งหมด
 * ผู้รับผิดชอบที่ไม่เปลี่ยน คงเวลามอบหมายเดิม (ไม่แจ้งเตือนซ้ำ) · คืน [created, updated, deleted]
 */
function guard_sync_events(int $y, int $m, int $actorId): array
{
    $pdo = db();
    $from = sprintf('%04d-%02d-01', $y, $m);
    $to = date('Y-m-t', strtotime($from));
    $want = [];                                    // "date|title" => [date, shift, role, users, names]
    foreach (guard_month_rows($y, $m) as $r) {
        $title = guard_title($r['shift'], $r['role']);
        $k = $r['guard_date'] . '|' . $title;
        $want[$k] ??= ['date' => $r['guard_date'], 'shift' => $r['shift'], 'title' => $title, 'users' => [], 'names' => []];
        if ($r['user_id']) {
            $want[$k]['users'][] = (int)$r['user_id'];
        } else {
            $want[$k]['names'][] = $r['name'];
        }
    }
    $have = [];
    $st = $pdo->prepare("SELECT id, event_date, title FROM cal_events WHERE kind = 'guard' AND event_date BETWEEN ? AND ? ORDER BY id");
    $st->execute([$from, $to]);
    $created = $updated = $deleted = 0;
    foreach ($st->fetchAll() as $r) {
        $k = $r['event_date'] . '|' . $r['title'];
        if (!isset($want[$k]) || isset($have[$k])) {         // ไม่มีในคำสั่ง / เวรจากแผนเดิม ("เวรศาลากลาง") / ซ้ำ → ลบ
            $pdo->prepare('DELETE FROM cal_events WHERE id = ?')->execute([$r['id']]);
            $deleted++;
            continue;
        }
        $have[$k] = (int)$r['id'];
    }
    $insE = $pdo->prepare("INSERT INTO cal_events (kind, event_date, start_time, end_time, title, location, note, created_by) VALUES ('guard', ?, ?, ?, ?, ?, ?, ?)");
    $updE = $pdo->prepare('UPDATE cal_events SET start_time = ?, end_time = ?, location = ?, note = ? WHERE id = ?');
    $insU = $pdo->prepare('INSERT IGNORE INTO cal_event_users (event_id, user_id) VALUES (?, ?)');
    foreach ($want as $k => $w) {
        [$st1, $et1, $time] = $w['shift'] === 'day' ? ['08:30:00', '16:30:00', 'เวลา 08.30 – 16.30 น.'] : ['16:30:00', null, 'เวลา 16.30 น. – 08.30 น. ของวันรุ่งขึ้น'];
        $note = 'ตามคำสั่งจังหวัดขอนแก่น · ' . $time . ($w['names'] ? "\nไม่มีบัญชีในระบบ: " . implode(', ', $w['names']) : '');
        $users = array_values(array_unique($w['users']));
        if (isset($have[$k])) {
            $eid = $have[$k];
            $cur = cal_assignees([$eid])[$eid] ?? [];
            $add = array_diff($users, $cur);
            $del = array_diff($cur, $users);
            if ($del) {
                $pdo->exec('DELETE FROM cal_event_users WHERE event_id = ' . $eid . ' AND user_id IN (' . implode(',', array_map('intval', $del)) . ')');
            }
            foreach ($add as $uid) {
                $insU->execute([$eid, $uid]);
            }
            $updE->execute([$st1, $et1, GUARD_PLACE, $note, $eid]);
            if ($add || $del) {
                $updated++;
            }
        } else {
            $insE->execute([$w['date'], $st1, $et1, $w['title'], GUARD_PLACE, $note, $actorId]);
            $eid = (int)$pdo->lastInsertId();
            foreach ($users as $uid) {
                $insU->execute([$eid, $uid]);
            }
            $created++;
        }
    }
    return [$created, $updated, $deleted];
}

/** เครื่องหมาย * (เวรศาลากลาง) ในแผนการปฏิบัติงานของเดือน = ตามคำสั่งจังหวัด (ถ้ามีแผนเดือนนั้น) · คืนจำนวนคน×วัน */
function guard_mark_plan(int $y, int $m): int
{
    $pdo = db();
    $st = $pdo->prepare('SELECT id FROM cal_plans WHERE plan_year = ? AND plan_month = ?');
    $st->execute([$y, $m]);
    $planId = $st->fetchColumn();
    if (!$planId) {
        return 0;
    }
    $people = [];                                  // user_id => person_id
    $st = $pdo->prepare('SELECT id, user_id FROM cal_plan_people WHERE plan_id = ? AND user_id IS NOT NULL');
    $st->execute([$planId]);
    foreach ($st as $r) {
        $people[(int)$r['user_id']] = (int)$r['id'];
    }
    $pdo->prepare('DELETE g FROM cal_plan_guard g JOIN cal_plan_people pp ON pp.id = g.person_id WHERE pp.plan_id = ?')->execute([$planId]);
    $ins = $pdo->prepare('INSERT IGNORE INTO cal_plan_guard (person_id, day) VALUES (?, ?)');
    $n = 0;
    foreach (guard_month_rows($y, $m) as $r) {
        if ($r['user_id'] && isset($people[(int)$r['user_id']])) {
            $ins->execute([$people[(int)$r['user_id']], (int)substr($r['guard_date'], 8, 2)]);
            $n += $ins->rowCount();
        }
    }
    return $n;
}
