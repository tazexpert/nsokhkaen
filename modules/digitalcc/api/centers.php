<?php
/**
 * API สาธารณะ (อ่านอย่างเดียว) สำหรับหน้าบ้าน
 *   GET api/centers.php                      รายชื่อศูนย์ทั้งหมด + สถิติ
 *   GET api/centers.php?id=5                 รายละเอียดศูนย์ ภาพ ผู้ดูแล กิจกรรม
 *   GET api/centers.php?action=activities    กิจกรรมล่าสุดทุกศูนย์ (&limit=8)
 */
require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

function out($data, int $code = 200): void   // ไม่คืนค่า (จบการทำงานเสมอ) — ใช้ void เพื่อรองรับ PHP 8.0
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function img(?string $f): ?string
{
    return $f ? upload_url($f) : null;
}

function num($v): ?float
{
    return $v === null ? null : (float)$v;
}

try {
    $pdo = db();
    $id = int_param('id');
    $action = $_GET['action'] ?? '';

    /* ----- กิจกรรมล่าสุด ----- */
    if ($action === 'activities') {
        $limit = max(1, min(50, int_param('limit', 8)));
        $st = $pdo->prepare(
            "SELECT a.id, a.title, a.activity_date, a.participants, a.cover_image,
                    c.id AS center_id, c.name AS center_name, c.district,
                    (SELECT file FROM dcc_activity_images ai WHERE ai.activity_id = a.id ORDER BY ai.id LIMIT 1) AS first_image
             FROM dcc_activities a JOIN dcc_centers c ON c.id = a.center_id
             WHERE c.is_active = 1
             ORDER BY a.activity_date IS NULL, a.activity_date DESC, a.id DESC
             LIMIT $limit"
        );
        $st->execute();
        $rows = array_map(fn($r) => [
            'id'            => (int)$r['id'],
            'title'         => $r['title'],
            'activity_date' => $r['activity_date'],
            'participants'  => (int)$r['participants'],
            'image'         => img($r['cover_image'] ?: $r['first_image']),
            'center_id'     => (int)$r['center_id'],
            'center_name'   => $r['center_name'],
            'district'      => $r['district'],
        ], $st->fetchAll());
        out(['activities' => $rows]);
    }

    /* ----- รายละเอียดศูนย์ ----- */
    if ($id > 0) {
        $st = $pdo->prepare('SELECT * FROM dcc_centers WHERE id = ? AND is_active = 1');
        $st->execute([$id]);
        $c = $st->fetch();
        if (!$c) {
            out(['error' => 'ไม่พบข้อมูลศูนย์'], 404);
        }

        $st = $pdo->prepare('SELECT id, file, caption FROM dcc_center_images WHERE center_id = ? ORDER BY id');
        $st->execute([$id]);
        $images = array_map(fn($r) => ['url' => img($r['file']), 'caption' => $r['caption']], $st->fetchAll());

        $st = $pdo->prepare('SELECT name, position, phone, email, line_id, bio, photo FROM dcc_center_staff WHERE center_id = ? ORDER BY sort_order, id');
        $st->execute([$id]);
        $staff = array_map(fn($r) => array_merge($r, ['photo' => img($r['photo'])]), $st->fetchAll());

        $st = $pdo->prepare('SELECT * FROM dcc_activities WHERE center_id = ? ORDER BY activity_date IS NULL, activity_date DESC, id DESC');
        $st->execute([$id]);
        $acts = $st->fetchAll();

        $imgByAct = [];
        if ($acts) {
            $ids = array_column($acts, 'id');
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT activity_id, file, caption FROM dcc_activity_images WHERE activity_id IN ($in) ORDER BY id");
            $st->execute($ids);
            foreach ($st->fetchAll() as $r) {
                $imgByAct[$r['activity_id']][] = ['url' => img($r['file']), 'caption' => $r['caption']];
            }
        }
        $activities = array_map(function ($a) use ($imgByAct) {
            $imgs = $imgByAct[$a['id']] ?? [];
            if ($a['cover_image']) {
                array_unshift($imgs, ['url' => img($a['cover_image']), 'caption' => $a['title']]);
            }
            return [
                'id'            => (int)$a['id'],
                'title'         => $a['title'],
                'activity_date' => $a['activity_date'],
                'organizer'     => $a['organizer'],
                'participants'  => (int)$a['participants'],
                'description'   => $a['description'],
                'images'        => $imgs,
            ];
        }, $acts);

        $services = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)$c['services']))));

        out(['center' => [
            'id'          => (int)$c['id'],
            'code'        => $c['code'],
            'name'        => $c['name'],
            'center_type' => $c['center_type'],
            'house_no'    => $c['house_no'] ?? '',
            'moo'         => $c['moo'] ?? '',
            'address'     => $c['address'],
            'village'     => $c['village'],
            'subdistrict' => $c['subdistrict'],
            'district'    => $c['district'],
            'province'    => $c['province'],
            'postcode'    => $c['postcode'],
            'lat'         => num($c['lat']),
            'lng'         => num($c['lng']),
            'phone'       => $c['phone'],
            'email'       => $c['email'],
            'facebook'    => $c['facebook'],
            'open_hours'  => $c['open_hours'],
            'services'    => $services,
            'description' => $c['description'],
            'cover_image' => img($c['cover_image']) ?? ($images[0]['url'] ?? null),
            'images'      => $images,
            'staff'       => $staff,
            'activities'  => $activities,
            'updated_at'  => $c['updated_at'],
        ]]);
    }

    /* ----- รายชื่อศูนย์ทั้งหมด ----- */
    $rows = $pdo->query(
        "SELECT c.id, c.code, c.name, c.center_type, c.subdistrict, c.district, c.lat, c.lng, c.phone, c.cover_image,
                (SELECT file FROM dcc_center_images ci WHERE ci.center_id = c.id ORDER BY ci.id LIMIT 1) AS first_image,
                (SELECT COUNT(*) FROM dcc_activities a WHERE a.center_id = c.id) AS activity_count,
                (SELECT name FROM dcc_center_staff s WHERE s.center_id = c.id ORDER BY s.sort_order, s.id LIMIT 1) AS staff_name
         FROM dcc_centers c WHERE c.is_active = 1
         ORDER BY c.district, c.name"
    )->fetchAll();

    $centers = array_map(fn($r) => [
        'id'             => (int)$r['id'],
        'code'           => $r['code'],
        'name'           => $r['name'],
        'center_type'    => $r['center_type'],
        'subdistrict'    => $r['subdistrict'],
        'district'       => $r['district'],
        'lat'            => num($r['lat']),
        'lng'            => num($r['lng']),
        'phone'          => $r['phone'],
        'image'          => img($r['cover_image'] ?: $r['first_image']),
        'staff_name'     => $r['staff_name'],
        'activity_count' => (int)$r['activity_count'],
    ], $rows);

    $stats = [
        'centers'      => count($centers),
        'districts'    => count(array_unique(array_filter(array_column($centers, 'district')))),
        'activities'   => array_sum(array_column($centers, 'activity_count')),
    ];

    out(['centers' => $centers, 'stats' => $stats, 'district_list' => KK_DISTRICTS]);
} catch (Throwable $e) {
    error_log('[digitalcc api] ' . $e->getMessage());
    out(['error' => 'ไม่สามารถเชื่อมต่อฐานข้อมูลได้'], 500);
}
