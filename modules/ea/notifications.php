<?php
/** การแจ้งเตือนระบบ EA: ได้รับมอบหมาย EA (ยังสำรวจไม่เสร็จ) ภายใน 60 วัน — รวมตามโครงการและรอบ */
return function (array $user): array {
    $st = db()->prepare("SELECT pe.project_id, pe.round_label, COUNT(*) n, MAX(pe.assigned_at) t, p.code
                         FROM ea_project_eas pe JOIN core_projects p ON p.id = pe.project_id
                         WHERE pe.assigned_user_id = ? AND pe.status <> 'done' AND pe.assigned_at >= ?
                         GROUP BY pe.project_id, pe.round_label, p.code ORDER BY t DESC LIMIT 10");
    $st->execute([(int)$user['id'], date('Y-m-d H:i:s', strtotime('-60 days'))]);
    $items = [];
    foreach ($st as $r) {
        $items[] = [
            'title' => 'ได้รับมอบหมาย EA โครงการ ' . $r['code'] . ($r['round_label'] !== '' ? ' ' . $r['round_label'] : ''),
            'text'  => number_format((int)$r['n']) . ' EA ที่ยังสำรวจไม่เสร็จ',
            'href'  => module_url('ea', 'project_eas?' . http_build_query(['id' => $r['project_id'], 'round' => $r['round_label'], 'user' => $user['id']])),
            'time'  => $r['t'], 'icon' => 'bi-map', 'color' => 'success',
        ];
    }
    return $items;
};
