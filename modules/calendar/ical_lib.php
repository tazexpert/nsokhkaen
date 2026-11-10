<?php
/**
 * ส่งออก "งานของฉัน" เป็น iCalendar (.ics) — นำเข้า/สมัครรับใน Google Calendar และปฏิทิน iOS
 * ลิงก์สมัครรับใช้รหัสลับต่อคน (cal_ical_tokens) เพราะแอปปฏิทินเข้าสู่ระบบไม่ได้
 */

const CAL_ICAL_PAST_DAYS = 180;     // ช่วงข้อมูล: ย้อนหลัง 6 เดือน
const CAL_ICAL_AHEAD_DAYS = 400;    //            ล่วงหน้า ~13 เดือน

/** รหัสลับของผู้ใช้ (สร้างใหม่เมื่อยังไม่มี หรือ $renew = true → ลิงก์เดิมใช้ไม่ได้) */
function cal_ical_token(int $userId, bool $renew = false): string
{
    if (!$renew) {
        $st = db()->prepare('SELECT token FROM cal_ical_tokens WHERE user_id = ?');
        $st->execute([$userId]);
        if ($t = $st->fetchColumn()) {
            return $t;
        }
    }
    $t = bin2hex(random_bytes(24));
    db()->prepare('INSERT INTO cal_ical_tokens (user_id, token) VALUES (?, ?) ON DUPLICATE KEY UPDATE token = VALUES(token), created_at = NOW(), last_used_at = NULL')
        ->execute([$userId, $t]);
    return $t;
}

/** ลิงก์สมัครรับ (https) */
function cal_ical_feed_url(string $token): string
{
    return APP_URL . 'modules/calendar/ical.php?t=' . $token;
}

/** กิจกรรมที่ผู้ใช้รับผิดชอบในช่วงข้อมูล (รวมกิจกรรมส่วนตัวที่ตนรับผิดชอบ และเวร) */
function cal_ical_events(int $userId): array
{
    $from = date('Y-m-d', strtotime('-' . CAL_ICAL_PAST_DAYS . ' days'));
    $to = date('Y-m-d', strtotime('+' . CAL_ICAL_AHEAD_DAYS . ' days'));
    $st = db()->prepare('SELECT e.* FROM cal_events e JOIN cal_event_users eu ON eu.event_id = e.id AND eu.user_id = ?
                         WHERE e.event_date <= ? AND COALESCE(e.end_date, e.event_date) >= ? ORDER BY e.event_date, e.start_time');
    $st->execute([$userId, $to, $from]);
    return $st->fetchAll();
}

/** ข้อความใน iCal: escape \ ; , และขึ้นบรรทัด */
function cal_ical_text(string $s): string
{
    return str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ["\\\\", '\;', '\,', '\n', '\n', '\n'], $s);
}

/** ตัดบรรทัดยาวเกิน 75 ไบต์ตามมาตรฐาน (ไม่ตัดกลางตัวอักษร UTF-8) */
function cal_ical_fold(string $line): string
{
    $out = '';
    $cur = '';
    foreach (mb_str_split($line) as $ch) {
        if (strlen($cur) + strlen($ch) > ($out === '' ? 75 : 74)) {
            $out .= $cur . "\r\n ";
            $cur = '';
        }
        $cur .= $ch;
    }
    return $out . $cur . "\r\n";
}

/** เวลาไทย → UTC รูปแบบ iCal */
function cal_ical_utc(string $date, string $time): string
{
    $d = new DateTime($date . ' ' . $time, new DateTimeZone('Asia/Bangkok'));
    $d->setTimezone(new DateTimeZone('UTC'));
    return $d->format('Ymd\THis\Z');
}

/** สร้างไฟล์ .ics */
function cal_ical_build(array $events, string $calName): string
{
    $host = parse_url(APP_URL, PHP_URL_HOST) ?: 'nsokhkaen';
    $now = gmdate('Ymd\THis\Z');
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//NSO Khon Kaen//Calendar//TH', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
              'X-WR-CALNAME:' . cal_ical_text($calName), 'X-WR-TIMEZONE:Asia/Bangkok',
              'REFRESH-INTERVAL;VALUE=DURATION:PT1H', 'X-PUBLISHED-TTL:PT1H'];
    foreach ($events as $e) {
        $end = $e['end_date'] ?: $e['event_date'];
        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:cal-event-' . (int)$e['id'] . '@' . $host;
        $lines[] = 'DTSTAMP:' . $now;
        $lines[] = 'LAST-MODIFIED:' . cal_ical_utc(substr((string)$e['updated_at'], 0, 10), substr((string)$e['updated_at'], 11, 8) ?: '00:00:00');
        if ($e['start_time']) {
            $start = cal_ical_utc($e['event_date'], $e['start_time']);
            $finish = $e['end_time'] ? cal_ical_utc($end, $e['end_time']) : '';
            if ($finish === '' || $finish <= $start) {
                $finish = cal_ical_utc($end, date('H:i:s', strtotime($e['start_time'] . ' +1 hour')));
                if ($finish <= $start) {
                    $finish = gmdate('Ymd\THis\Z', strtotime(str_replace(['T', 'Z'], [' ', ' UTC'], $start)) + 3600);
                }
            }
            $lines[] = 'DTSTART:' . $start;
            $lines[] = 'DTEND:' . $finish;
        } else {                                   // ทั้งวัน: DTEND = วันถัดจากวันสุดท้าย
            $lines[] = 'DTSTART;VALUE=DATE:' . str_replace('-', '', $e['event_date']);
            $lines[] = 'DTEND;VALUE=DATE:' . date('Ymd', strtotime($end . ' +1 day'));
        }
        $title = $e['title'] . ($e['kind'] === 'duty' ? ' (เวรสำนักงาน)' : '');
        $lines[] = 'SUMMARY:' . cal_ical_text($title);
        if ($e['location']) {
            $lines[] = 'LOCATION:' . cal_ical_text($e['location']);
        }
        $url = APP_URL . 'modules/calendar/event_view.php?id=' . (int)$e['id'];
        $desc = trim(implode("\n", array_filter([(string)$e['note'], $e['link'] ? 'ลิงก์: ' . $e['link'] : '', 'รายละเอียด: ' . $url])));
        $lines[] = 'DESCRIPTION:' . cal_ical_text($desc);
        $lines[] = 'URL:' . $url;
        if (($e['visibility'] ?? '') === 'private') {
            $lines[] = 'CLASS:PRIVATE';
        }
        $lines[] = 'END:VEVENT';
    }
    $lines[] = 'END:VCALENDAR';
    return implode('', array_map('cal_ical_fold', $lines));
}

/** ส่งไฟล์ .ics ($download = ให้เบราว์เซอร์บันทึกเป็นไฟล์) */
function cal_ical_send(string $ics, bool $download): void   // ไม่คืนค่า (จบการทำงานเสมอ)
{
    header('Content-Type: text/calendar; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="my-calendar.ics"');
    echo $ics;
    exit;
}
