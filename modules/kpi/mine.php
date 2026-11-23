<?php
/** งานของฉัน: กิจกรรม/หลักฐานที่ได้รับมอบ (ทุกปีงบประมาณ/รอบ ที่ยังไม่ตรวจ + ที่ตรวจแล้วในรอบปัจจุบัน) เรียงตามวันที่ต้องส่ง */
require_once __DIR__ . '/bootstrap.php';
$me = require_module(KPI_MODULE);
$myId = (int)$me['id'];
[$fy, $round] = kpi_period_of(date('Y-m-d'));
// ตัวชี้วัดที่เป็นผู้ปฏิบัติ (ทั้งระดับตัวชี้วัด หรือระบุรายกิจกรรม)
$st = db()->prepare('SELECT DISTINCT i.id FROM kpi_indicators i
    LEFT JOIN kpi_indicator_users iu ON iu.indicator_id = i.id AND iu.user_id = ?
    LEFT JOIN kpi_activities a ON a.indicator_id = i.id LEFT JOIN kpi_activity_users au ON au.activity_id = a.id AND au.user_id = ?
    WHERE iu.user_id IS NOT NULL OR au.user_id IS NOT NULL');
$st->execute([$myId, $myId]);
$ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
$indUsers = kpi_indicator_users($ids);
$items = [];
foreach ($ids as $iid) {
    $ind = kpi_get($iid);
    foreach (kpi_structure($iid, $indUsers[$iid] ?? []) as $l) {
        foreach ($l['activities'] as $a) {
            $current = (int)$ind['fiscal_year'] === $fy && (int)$ind['round_no'] === $round;
            if (in_array($myId, $a['users'], true) && ($a['status'] !== 'done' || $current)) {
                $items[] = $a + ['ind' => $ind, 'level_no' => $l['level_no']];
            }
        }
    }
}
// ยังไม่ส่งก่อน (ตามวันที่ต้องส่ง · ไม่ระบุวันไว้ท้าย) → ส่งแล้ว → ตรวจแล้ว
$rank = ['pending' => 0, 'submitted' => 1, 'done' => 2];
usort($items, fn($a, $b) => [$rank[$a['status']], $a['due_date'] ?? '9999', $a['id']] <=> [$rank[$b['status']], $b['due_date'] ?? '9999', $b['id']]);

$pageTitle = 'งานของฉัน';
$active = 'mine';
require __DIR__ . '/header.php';
?>
<div class="card">
  <div class="card-header"><strong><i class="bi bi-person-check"></i> กิจกรรม/หลักฐานที่ได้รับมอบ (<?= count($items) ?>)</strong></div>
  <div class="list-group list-group-flush" data-reveal>
    <?php foreach ($items as $i => $a): ?>
      <a href="indicator?id=<?= (int)$a['indicator_id'] ?>#act-<?= (int)$a['id'] ?>" class="list-group-item list-group-item-action d-flex gap-3 align-items-center reveal-item" style="--d:<?= min($i, 10) * 40 ?>ms">
        <div class="text-nowrap" style="min-width:7.5rem"><?= kpi_status_badge($a) ?>
          <div class="small text-muted mt-1"><?= $a['due_date'] ? 'ส่งภายใน ' . h(thai_date($a['due_date'])) : 'ไม่ระบุวันส่ง' ?></div></div>
        <div class="min-w-0"><div class="fw-semibold"><?= h(mb_strimwidth(preg_replace('/\s+/u', ' ', $a['title']), 0, 180, '…')) ?></div>
          <div class="small text-muted"><?= h(kpi_round_text((int)$a['ind']['fiscal_year'], (int)$a['ind']['round_no'], false)) ?> · ตัวชี้วัด<?= $a['ind']['code'] ? 'ที่ ' . h($a['ind']['code']) : '' ?> <?= h(mb_strimwidth($a['ind']['name'], 0, 100, '…')) ?> · ระดับ <?= (int)$a['level_no'] ?></div></div>
        <?php if ($a['status'] === 'done'): ?><span class="badge text-bg-primary ms-auto"><?= kpi_num($a['score']) ?> คะแนน</span><?php endif; ?>
      </a>
    <?php endforeach; ?>
    <?php if (!$items): ?><div class="list-group-item text-center text-muted py-5"><i class="bi bi-inbox fs-2 d-block mb-1"></i>ยังไม่มีกิจกรรมที่ได้รับมอบ</div><?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
