<?php
/**
 * รายละเอียดโอนเปลี่ยนแปลงครั้งที่ N/ปี (คลิกจากภาพรวมงบประมาณ) — ทุกแถวของเอกสารที่เป็นการโอนครั้งนั้น (bud_transfers ผูกด้วยที่เอกสาร)
 *   แสดง วันที่ · ที่เอกสาร · รายการ · ได้รับ / เบิกจ่าย
 *   ช่องรายการ: ข้อความในรายละเอียด (ถ้ามี) ไม่มี = ค่าใช้จ่ายของหมวด
 */
require_once __DIR__ . '/bootstrap.php';
require_module(BUD_MODULE, 'viewer');
$fy = bud_fy_param();
$transfers = bud_transfers($fy);
$k = (string)($_GET['k'] ?? '');
if (!isset($transfers[$k])) {
    $k = (string)(array_key_first($transfers) ?? '');
}
$tr = $transfers[$k] ?? null;
$sources = bud_sources(true);

$pageTitle = $tr ? $tr['title'] : 'โอนเปลี่ยนแปลง ปีงบประมาณ ' . $fy;
$active = 'dashboard';
$pageActions = '<a href="./?fy=' . $fy . '" class="btn btn-light"><i class="bi bi-arrow-left"></i> ภาพรวมงบประมาณ</a>'
    . '<button type="button" class="btn btn-outline-secondary" onclick="print()"><i class="bi bi-printer"></i> พิมพ์</button>';
require __DIR__ . '/header.php';
?>
<?php if (!$tr): ?>
  <div class="alert alert-info">ปีงบประมาณ <?= $fy ?> ยังไม่มีรายการ "โอนเปลี่ยนแปลงครั้งที่ …"</div>
<?php else: ?>
<ul class="nav nav-pills mb-3 no-print">
  <?php foreach ($transfers as $x): ?>
    <li class="nav-item"><a class="nav-link<?= (string)$x['k'] === $k ? ' active' : '' ?>" href="transfer?<?= h(http_build_query(['fy' => $fy, 'k' => $x['k']])) ?>" title="<?= h($x['title']) ?>"><?= h($x['label'] !== '' ? 'ครั้งที่ ' . $x['label'] : mb_strimwidth($x['title'], 0, 30, '…')) ?></a></li>
  <?php endforeach; ?>
</ul>

<div class="row g-3 mb-3" data-reveal>
  <?php foreach ([['จำนวนรายการ', number_format($tr['count']), 'bi-list-ol', '#475569'],
                  ['ได้รับ (บาท)', bud_money($tr['receive']), 'bi-box-arrow-in-down', BUD_COLORS['receive']],
                  ['เบิกจ่าย (บาท)', bud_money($tr['spend']), 'bi-box-arrow-up', BUD_COLORS['spend']]] as $i => [$lbl, $v, $ic, $c]): ?>
    <div class="col-sm-4 reveal-item" style="--d:<?= $i * 70 ?>ms">
      <div class="card h-100"><div class="card-body py-2">
        <div class="small text-muted"><i class="bi <?= $ic ?>" style="color:<?= $c ?>"></i> <?= $lbl ?></div>
        <div class="fs-4 fw-bold" style="color:<?= $c ?>"><?= $v ?></div>
      </div></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-header"><strong><?= h($tr['title']) ?></strong>
    <span class="small text-muted ms-2"><?= h(thai_date($tr['from'])) ?><?= $tr['to'] !== $tr['from'] ? ' – ' . h(thai_date($tr['to'])) : '' ?></span></div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0 bud-transfer">
      <thead class="table-light"><tr><th style="width:8.5em">วันที่</th><th>ที่เอกสาร</th><th>รายการ</th>
        <th class="text-end">ได้รับ</th><th class="text-end">เบิกจ่าย</th><?php if (bud_can('editor')): ?><th class="no-print"></th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($tr['txns'] as $t): $s = $sources[bud_source_key($t)] ?? null; $sp = $t['txn_type'] === 'spend'; ?>
        <tr>
          <td class="text-nowrap"><?= h(thai_date($t['txn_date'])) ?></td>
          <td class="font-monospace text-nowrap"><?= h($t['doc_no']) ?></td>
          <td><?php if (trim((string)$t['detail']) !== ''): ?><?= nl2br(h(trim($t['detail']))) ?>
            <?php else: /* ไม่ใส่รายละเอียด → ค่าใช้จ่ายของหมวด (ไม่มี = รายการ / ชื่อหมวด) — สีเดียวกับรายละเอียด */ ?><?= h(($s['activity'] ?? '') !== '' ? $s['activity'] : (($s['expense'] ?? '') !== '' ? $s['expense'] : ($s['label'] ?? '-'))) ?><?php endif; ?></td>
          <td class="text-end text-nowrap"><?= $sp ? '' : bud_money($t['amount']) ?></td>
          <td class="text-end text-nowrap"><?= $sp ? bud_money($t['amount']) : '' ?></td>
          <?php if (bud_can('editor')): ?><td class="no-print"><a href="txn_form?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-light" title="แก้ไข"><i class="bi bi-pencil"></i></a></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr class="table-light fw-bold"><td colspan="3" class="text-end">รวม</td>
        <td class="text-end"><?= bud_money($tr['receive']) ?></td><td class="text-end"><?= bud_money($tr['spend']) ?></td><?php if (bud_can('editor')): ?><td class="no-print"></td><?php endif; ?></tr></tfoot>
    </table>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
