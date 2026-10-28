<?php
/**
 * แนบไฟล์ใบยืมหลายรายการในหน้าเดียว (ผู้ดูแล)
 *   ?ids=1,2,3  = รายการที่เพิ่งนำเข้าจาก CSV
 *   (ไม่ระบุ)   = รายการที่กำลังยืมแต่ยังไม่มีไฟล์ใบยืม
 */
require_once __DIR__ . '/bootstrap.php';
$me = require_module(INV_MODULE, 'admin');
$pdo = db();
$ids = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? $_POST['ids'] ?? '')))));
$idsQs = $ids ? '?ids=' . implode(',', $ids) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $saved = 0;
    $errors = [];
    $files = $_FILES['files'] ?? null;
    $docNos = (array)($_POST['doc_no'] ?? []);
    $find = $pdo->prepare('SELECT * FROM inv_loans WHERE id = ?');
    $upd = $pdo->prepare('UPDATE inv_loans SET file_path = ?, file_name = ? WHERE id = ?');
    $updDoc = $pdo->prepare('UPDATE inv_loans SET doc_no = ? WHERE id = ?');
    foreach ($docNos as $lid => $doc) {
        $doc = mb_substr(trim((string)$doc), 0, 100);
        $updDoc->execute([$doc !== '' ? $doc : null, (int)$lid]);
    }
    foreach ((array)($files['name'] ?? []) as $lid => $name) {
        $lid = (int)$lid;
        $f = ['name' => $name, 'type' => $files['type'][$lid] ?? '', 'tmp_name' => $files['tmp_name'][$lid] ?? '',
              'error' => $files['error'][$lid] ?? UPLOAD_ERR_NO_FILE, 'size' => $files['size'][$lid] ?? 0];
        if ($f['error'] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $find->execute([$lid]);
        $loan = $find->fetch();
        if (!$loan) {
            continue;
        }
        try {
            [$path, $orig] = save_loan_file($f);
            $upd->execute([$path, $orig, $lid]);
            if ($loan['file_path']) {
                delete_upload($loan['file_path']);
            }
            $saved++;
        } catch (RuntimeException $ex) {
            $errors[] = $loan['loan_no'] . ': ' . $ex->getMessage();
        }
    }
    if ($saved) {
        audit_log('loan_attach', $saved . ' ไฟล์', INV_MODULE);
    }
    flash($saved ? "แนบไฟล์ใบยืมแล้ว $saved รายการ" : 'บันทึกเลขที่หนังสือแล้ว (ไม่ได้เลือกไฟล์ใหม่)', $saved ? 'success' : 'info');
    foreach ($errors as $e) {
        flash($e, 'danger');
    }
    redirect('loan_attach.php' . $idsQs);
}

$sql = 'SELECT l.*, (SELECT GROUP_CONCAT(COALESCE(i.asset_no, i.brand_model, i.item_name) ORDER BY i.sort_order SEPARATOR ", ")
        FROM inv_loan_items i WHERE i.loan_id = l.id) AS item_list FROM inv_loans l';
if ($ids) {
    $loans = $pdo->query($sql . ' WHERE l.id IN (' . implode(',', $ids) . ') ORDER BY l.id')->fetchAll();
} else {
    $loans = $pdo->query($sql . " WHERE l.status = 'borrowed' AND (l.file_path IS NULL OR l.file_path = '') ORDER BY l.id")->fetchAll();
}
$missing = count(array_filter($loans, fn($l) => !$l['file_path']));

$pageTitle = $ids ? 'รายการที่นำเข้า — แนบไฟล์ใบยืม' : 'รายการยืมที่ยังไม่มีไฟล์ใบยืม';
$pageActions = '<a href="loans.php" class="btn btn-light"><i class="bi bi-arrow-left"></i> รายการยืม-คืน</a>';
require __DIR__ . '/header.php';
?>
<?php if (!$loans): ?>
  <div class="alert alert-success"><i class="bi bi-check2-circle"></i> ทุกรายการที่กำลังยืมมีไฟล์ใบยืมครบแล้ว</div>
<?php else: ?>
<form method="post" enctype="multipart/form-data">
  <?= csrf_field() ?><input type="hidden" name="ids" value="<?= h(implode(',', $ids)) ?>">
  <div class="card">
    <div class="card-header d-flex align-items-center flex-wrap gap-2">
      <strong><?= count($loans) ?> รายการ</strong>
      <?= $missing ? '<span class="badge text-bg-warning">ยังไม่มีไฟล์ใบยืม ' . $missing . '</span>' : '<span class="badge text-bg-success">แนบไฟล์ครบแล้ว</span>' ?>
      <span class="small text-muted ms-auto">เลือกไฟล์ (PDF/รูป ไม่เกิน 10 MB) ทีละแถว แล้วกดบันทึกครั้งเดียว</span>
    </div>
    <div class="table-responsive">
      <table class="table align-middle mb-0">
        <thead><tr><th>เลขที่</th><th>ผู้ยืม</th><th>ครุภัณฑ์</th><th>ระยะเวลายืม</th><th style="width:170px">เลขที่หนังสือ e-office</th><th style="min-width:280px">ไฟล์ใบยืม</th></tr></thead>
        <tbody>
        <?php foreach ($loans as $l): ?>
          <tr class="<?= $l['file_path'] ? '' : 'table-warning' ?>">
            <td><a href="loan_view.php?id=<?= (int)$l['id'] ?>" class="fw-semibold"><?= h($l['loan_no']) ?></a>
              <?php if ($l['status'] !== 'borrowed'): ?><div><?= loan_status_badge($l['status']) ?></div><?php endif; ?></td>
            <td><?= h($l['borrower_name']) ?><div class="small text-muted"><?= h($l['borrower_position']) ?></div></td>
            <td class="small font-monospace" style="max-width:260px"><?= h($l['item_list']) ?></td>
            <td class="small text-nowrap"><?= h(thai_date($l['start_date'])) ?> – <?= h(thai_date($l['due_date'])) ?></td>
            <td><input name="doc_no[<?= (int)$l['id'] ?>]" class="form-control form-control-sm" value="<?= h($l['doc_no']) ?>" maxlength="100"></td>
            <td>
              <?php if ($l['file_path']): ?>
                <div class="small mb-1"><a href="loan_file.php?id=<?= (int)$l['id'] ?>" target="_blank"><i class="bi bi-paperclip"></i> <?= h($l['file_name']) ?></a> <span class="text-muted">(เลือกไฟล์ใหม่เพื่อแทนที่)</span></div>
              <?php endif; ?>
              <input type="file" name="files[<?= (int)$l['id'] ?>]" accept=".pdf,image/jpeg,image/png,image/webp" class="form-control form-control-sm">
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="sticky-actions">
    <button class="btn btn-primary btn-lg"><i class="bi bi-upload"></i> บันทึกไฟล์ใบยืม</button>
  </div>
</form>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
