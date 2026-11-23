<?php
/**
 * รายละเอียดตัวชี้วัด — แบบฟอร์มรายละเอียดตัวชี้วัด (ชื่อ หน่วยวัด น้ำหนัก คำอธิบาย เกณฑ์การให้คะแนน หมายเหตุ ผู้ควบคุม/ผู้จัดเก็บข้อมูล)
 *   ผู้ปฏิบัติของกิจกรรม: บันทึกความก้าวหน้า + แนบไฟล์หลักฐาน / ลิงก์ · ติ๊ก "ส่งหลักฐานแล้ว" = สถานะส่งแล้ว รอตรวจ
 *   ผู้ควบคุมตัวชี้วัด/ผู้ดูแล: ตรวจและให้คะแนนรายกิจกรรม (คะแนนรวม = ผลรวมคะแนนที่ตรวจแล้ว ไม่เกินคะแนนเต็มของระดับ)
 */
require_once __DIR__ . '/bootstrap.php';
$me = require_module(KPI_MODULE);
$pdo = db();
$id = int_param('id');
$ind = kpi_get($id);
if (!$ind) {
    flash('ไม่พบตัวชี้วัด', 'danger');
    redirect('./');
}
$canManage = kpi_can_manage($ind);
$indUsers = kpi_indicator_users([$id])[$id] ?? [];
$levels = kpi_structure($id, $indUsers);
$acts = [];
foreach ($levels as $l) {
    foreach ($l['activities'] as $a) {
        $acts[(int)$a['id']] = $a;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    $aid = int_param('activity_id');
    $act = $acts[$aid] ?? null;
    $back = 'indicator?id=' . $id . '#act-' . $aid;
    if (!$act) {
        flash('ไม่พบกิจกรรม', 'danger');
        redirect('indicator?id=' . $id);
    }
    if ($action === 'progress') {
        if (!kpi_can_report($ind, $act)) {
            forbidden(KPI_MODULE, 'controller');
        }
        $note = trim(post('note'));
        $link = trim(post('link'));
        $submit = post('submit') === '1';
        try {
            $files = msg_check_files(files_array('files'));
            if ($link !== '' && !cal_safe_link($link)) {
                throw new RuntimeException('ลิงก์ต้องขึ้นต้นด้วย http:// หรือ https://');
            }
            if ($note === '' && $link === '' && !$files && !$submit) {
                throw new RuntimeException('กรุณาระบุความก้าวหน้า ลิงก์ หรือแนบไฟล์หลักฐาน');
            }
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO kpi_progress (activity_id, user_id, note, link, is_submit) VALUES (?, ?, ?, ?, ?)')
                ->execute([$aid, $me['id'], $note ?: null, $link ?: null, $submit ? 1 : 0]);
            $pid = (int)$pdo->lastInsertId();
            kpi_save_files($pid, $files);
            if ($submit && $act['status'] === 'pending') {
                $pdo->prepare("UPDATE kpi_activities SET status = 'submitted', submitted_at = NOW() WHERE id = ?")->execute([$aid]);
            }
            $pdo->commit();
            audit_log('kpi_progress', '#' . $id . ' กิจกรรม #' . $aid . ($submit ? ' (ส่งหลักฐาน)' : '') . ($files ? ' · ' . count($files) . ' ไฟล์' : ''), KPI_MODULE);
            flash($submit ? 'ส่งหลักฐานแล้ว — รอผู้ควบคุมตัวชี้วัดตรวจ' : 'บันทึกความก้าวหน้าแล้ว');
        } catch (RuntimeException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash($ex->getMessage(), 'danger');
        }
        redirect($back);
    }
    if ($action === 'delete_progress') {
        $st = $pdo->prepare('SELECT * FROM kpi_progress WHERE id = ? AND activity_id = ?');
        $st->execute([int_param('progress_id'), $aid]);
        $p = $st->fetch();
        if ($p && ($canManage || (int)$p['user_id'] === (int)$me['id'])) {
            $fs = $pdo->prepare('SELECT file_path FROM kpi_files WHERE progress_id = ?');
            $fs->execute([$p['id']]);
            foreach ($fs->fetchAll(PDO::FETCH_COLUMN) as $path) {
                delete_upload($path);
            }
            $pdo->prepare('DELETE FROM kpi_progress WHERE id = ?')->execute([$p['id']]);
            flash('ลบบันทึกแล้ว');
        }
        redirect($back);
    }
    if ($action === 'review' && $canManage) {
        $status = post('status');
        $status = isset(KPI_STATUS[$status]) ? $status : 'pending';
        $score = trim(post('score')) === '' ? null : round((float)post('score'), 2);
        $pdo->prepare('UPDATE kpi_activities SET status = ?, score = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?')
            ->execute([$status, $status === 'done' ? $score : null, mb_substr(trim(post('review_note')), 0, 500) ?: null, $me['id'], $aid]);
        audit_log('kpi_review', '#' . $id . ' กิจกรรม #' . $aid . ' ' . $status . ($score !== null ? ' ' . $score : ''), KPI_MODULE);
        flash('บันทึกผลการตรวจแล้ว');
        redirect($back);
    }
    redirect($back);
}

$progress = kpi_progress_of(array_keys($acts));
[$got, $max] = kpi_score($levels);
$stats = kpi_activity_stats($levels);
$names = kpi_user_names();
$pct = $stats['total'] ? round(($stats['done'] + $stats['submitted']) / $stats['total'] * 100) : 0;
$myId = (int)$me['id'];

$pageTitle = trim(($ind['code'] !== null && $ind['code'] !== '' ? 'ตัวชี้วัดที่ ' . $ind['code'] . ' : ' : '') . $ind['name']);
$active = 'dashboard';
$pageActions = '<a href="./?' . h(http_build_query(['fy' => $ind['fiscal_year'], 'r' => $ind['round_no']])) . '" class="btn btn-light"><i class="bi bi-arrow-left"></i> ภาพรวม</a>'
    . '<button type="button" class="btn btn-outline-secondary" onclick="print()"><i class="bi bi-printer"></i> พิมพ์</button>'
    . ($canManage ? '<a href="indicator_form?id=' . $id . '" class="btn btn-primary"><i class="bi bi-pencil"></i> แก้ไขตัวชี้วัด</a>' : '');
require __DIR__ . '/header.php';
$names_of = fn(array $ids) => implode(', ', array_filter(array_map(fn($u) => $names[$u] ?? null, $ids)));
?>
<div class="row g-3 mb-3" data-reveal>
  <div class="col-sm-6 col-xl-3 reveal-item"><div class="stat-card h-100"><span class="stat-icon text-bg-primary"><i class="bi bi-award"></i></span>
    <div><div class="stat-num"><?= kpi_num($got) ?> <small class="fs-6">/ <?= kpi_num($max) ?></small></div><div class="stat-label">คะแนนที่ได้ (ตรวจแล้ว)</div></div></div></div>
  <div class="col-sm-6 col-xl-3 reveal-item" style="--d:70ms"><div class="stat-card h-100"><span class="stat-icon text-bg-info"><i class="bi bi-percent"></i></span>
    <div><div class="stat-num"><?= $ind['weight'] !== null && $max > 0 ? kpi_num($got / $max * (float)$ind['weight']) : '-' ?> <small class="fs-6">/ <?= kpi_num($ind['weight']) ?></small></div><div class="stat-label">คะแนนถ่วงน้ำหนัก (ร้อยละ)</div></div></div></div>
  <div class="col-sm-6 col-xl-3 reveal-item" style="--d:140ms"><div class="stat-card h-100"><span class="stat-icon text-bg-success"><i class="bi bi-check2-circle"></i></span>
    <div class="flex-grow-1"><div class="stat-num"><?= $stats['done'] + $stats['submitted'] ?> <small class="fs-6">/ <?= $stats['total'] ?></small></div><div class="stat-label">กิจกรรมที่ส่งแล้ว (ตรวจแล้ว <?= $stats['done'] ?>)</div>
      <div class="kpi-bar mt-1"><span class="bar-fill" style="width:<?= $pct ?>%"></span></div></div></div></div>
  <div class="col-sm-6 col-xl-3 reveal-item" style="--d:210ms"><div class="stat-card h-100"><span class="stat-icon text-bg-<?= $stats['overdue'] ? 'danger' : 'warning' ?>"><i class="bi bi-alarm"></i></span>
    <div><div class="stat-num"><?= $stats['overdue'] ?: $stats['soon'] ?></div><div class="stat-label"><?= $stats['overdue'] ? 'กิจกรรมเกินกำหนด' : 'ครบกำหนดภายใน ' . KPI_WARN_DAYS . ' วัน' ?></div></div></div></div>
</div>

<div class="card mb-3">
  <div class="card-header"><strong><i class="bi bi-bullseye"></i> <?= h(kpi_round_text((int)$ind['fiscal_year'], (int)$ind['round_no'])) ?></strong></div>
  <div class="card-body kpi-detail">
    <dl class="row mb-0">
      <dt class="col-sm-3 col-lg-2">ตัวชี้วัด<?= $ind['code'] !== null && $ind['code'] !== '' ? 'ที่ ' . h($ind['code']) : '' ?></dt><dd class="col-sm-9 col-lg-10 fw-semibold"><?= h($ind['name']) ?></dd>
      <?php if (isset($ind['is_office'])): ?>
      <dt class="col-sm-3 col-lg-2">ประเภท</dt><dd class="col-sm-9 col-lg-10"><?= (int)$ind['is_office'] ? '<span class="badge kpi-badge-office"><i class="bi bi-building-check"></i> ตัวชี้วัดจากสำนักงาน</span>' : '<span class="badge text-bg-secondary">ตัวชี้วัดอื่น / ภายในหน่วยงาน</span>' ?></dd>
      <?php endif; ?>
      <dt class="col-sm-3 col-lg-2">หน่วยวัด</dt><dd class="col-sm-9 col-lg-10"><?= h($ind['unit'] ?: '-') ?></dd>
      <dt class="col-sm-3 col-lg-2">น้ำหนัก</dt><dd class="col-sm-9 col-lg-10"><?= $ind['weight'] !== null ? 'ร้อยละ ' . kpi_num($ind['weight']) : '-' ?></dd>
      <dt class="col-sm-3 col-lg-2">กลุ่มงานที่รับผิดชอบ</dt><dd class="col-sm-9 col-lg-10"><?= h($ind['work_group_name'] ?: '-') ?></dd>
      <dt class="col-sm-3 col-lg-2">ผู้ปฏิบัติ</dt><dd class="col-sm-9 col-lg-10"><?= h($names_of($indUsers) ?: '-') ?></dd>
      <?php if (trim((string)$ind['description']) !== ''): ?>
      <dt class="col-sm-3 col-lg-2">คำอธิบาย</dt><dd class="col-sm-9 col-lg-10 kpi-desc"><?= kpi_text($ind['description']) ?></dd>
      <?php endif; ?>
    </dl>
  </div>
</div>

<h2 class="h5 mb-2"><i class="bi bi-list-ol"></i> เกณฑ์การให้คะแนน</h2>
<?php foreach ($levels as $l): ?>
  <div class="card mb-3 kpi-level-view">
    <div class="card-header d-flex align-items-center gap-2 flex-wrap"><strong>ระดับ <?= (int)$l['level_no'] ?></strong>
      <span class="kpi-issue"><?= kpi_text($l['issue']) ?></span>
      <?php if ($l['max_score'] !== null): ?><span class="badge text-bg-light ms-auto"><?= kpi_num($l['max_score']) ?> คะแนน</span><?php endif; ?></div>
    <div class="list-group list-group-flush">
      <?php foreach ($l['activities'] as $a): $aid = (int)$a['id']; $plist = $progress[$aid] ?? []; $mine = in_array($myId, $a['users'], true); $canReport = kpi_can_report($ind, $a); ?>
        <div class="list-group-item kpi-act-view<?= $mine ? ' mine' : '' ?>" id="act-<?= $aid ?>">
          <div class="row g-2">
            <div class="col-lg-5"><div class="fw-semibold"><?= kpi_text($a['title']) ?></div>
              <div class="small text-muted mt-1"><i class="bi bi-person-check"></i> <?= h($names_of($a['users']) ?: 'ยังไม่ระบุผู้ปฏิบัติ') ?></div></div>
            <div class="col-lg-4 small"><?= trim((string)$a['score_rule']) !== '' ? kpi_text($a['score_rule']) : '<span class="text-muted">-</span>' ?></div>
            <div class="col-lg-3 text-lg-end">
              <div><?= kpi_status_badge($a) ?> <?php if ($a['status'] === 'done'): ?><span class="badge text-bg-primary"><?= kpi_num($a['score']) ?><?= $a['max_score'] !== null ? ' / ' . kpi_num($a['max_score']) : '' ?> คะแนน</span><?php elseif ($a['max_score'] !== null): ?><span class="small text-muted"><?= kpi_num($a['max_score']) ?> คะแนน</span><?php endif; ?></div>
              <?php if ($a['due_date']): ?><div class="small mt-1"><i class="bi bi-calendar-event"></i> ส่งภายใน <?= h(thai_date($a['due_date'])) ?></div><?php endif; ?>
              <?php if ($a['review_note']): ?><div class="small text-muted mt-1"><i class="bi bi-chat-left-text"></i> <?= h($a['review_note']) ?></div><?php endif; ?>
            </div>
          </div>

          <?php if ($plist): ?>
            <div class="kpi-progress mt-2">
              <?php foreach ($plist as $p): ?>
                <div class="kpi-prog-item">
                  <div class="small text-muted"><i class="bi bi-person"></i> <?= h(preg_replace('/\s+/u', ' ', (string)$p['full_name'])) ?> · <?= h(thai_date($p['created_at'])) ?> <?= h(substr($p['created_at'], 11, 5)) ?> น.
                    <?= $p['is_submit'] ? '<span class="badge text-bg-warning ms-1">ส่งหลักฐาน</span>' : '' ?>
                    <?php if ($canManage || (int)$p['user_id'] === $myId): ?>
                      <form method="post" class="d-inline no-print" data-confirm="ลบบันทึกนี้พร้อมไฟล์แนบ?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_progress">
                        <input type="hidden" name="activity_id" value="<?= $aid ?>"><input type="hidden" name="progress_id" value="<?= (int)$p['id'] ?>">
                        <button class="btn btn-link btn-sm text-danger p-0 ms-1" title="ลบ"><i class="bi bi-trash"></i></button></form>
                    <?php endif; ?></div>
                  <?php if ($p['note']): ?><div><?= kpi_text($p['note']) ?></div><?php endif; ?>
                  <?php if ($p['link']): ?><div class="small"><a href="<?= h($p['link']) ?>" target="_blank" rel="noopener"><i class="bi bi-link-45deg"></i> <?= h(mb_strimwidth($p['link'], 0, 80, '…')) ?></a></div><?php endif; ?>
                  <?php if ($p['files']): ?><div class="d-flex flex-wrap gap-1 mt-1">
                    <?php foreach ($p['files'] as $fl): ?><a href="file?id=<?= (int)$fl['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi <?= msg_file_icon($fl['file_name']) ?>"></i> <?= h($fl['file_name']) ?> <span class="small text-muted"><?= msg_file_size((int)$fl['file_size']) ?></span></a><?php endforeach; ?>
                  </div><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if ($canReport || $canManage): ?>
          <div class="d-flex gap-2 mt-2 no-print">
            <?php if ($canReport): ?><button class="btn btn-sm btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#prog-<?= $aid ?>"><i class="bi bi-upload"></i> บันทึกความก้าวหน้า / ส่งหลักฐาน</button><?php endif; ?>
            <?php if ($canManage): ?><button class="btn btn-sm btn-outline-success" type="button" data-bs-toggle="collapse" data-bs-target="#rev-<?= $aid ?>"><i class="bi bi-clipboard-check"></i> ตรวจ / ให้คะแนน</button><?php endif; ?>
          </div>
          <?php if ($canReport): ?>
          <form method="post" enctype="multipart/form-data" class="collapse kpi-subform mt-2" id="prog-<?= $aid ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="progress"><input type="hidden" name="activity_id" value="<?= $aid ?>">
            <div class="row g-2">
              <div class="col-lg-6"><label class="form-label small mb-0">ความก้าวหน้า / รายละเอียด</label><textarea name="note" class="form-control form-control-sm" rows="3"></textarea></div>
              <div class="col-lg-6">
                <label class="form-label small mb-0">ลิงก์หลักฐาน (ถ้ามี)</label><input name="link" type="url" class="form-control form-control-sm mb-2" placeholder="https://...">
                <label class="form-label small mb-0">ไฟล์หลักฐาน</label><input type="file" name="files[]" class="form-control form-control-sm" multiple>
                <div class="form-text">สูงสุด <?= MSG_MAX_FILES ?> ไฟล์ · ไฟล์ละไม่เกิน <?= MSG_FILE_MAX / 1048576 ?> MB · PDF, รูปภาพ, Word, Excel, PowerPoint, ZIP</div></div>
              <div class="col-12 d-flex align-items-center gap-3 flex-wrap">
                <div class="form-check"><input class="form-check-input" type="checkbox" name="submit" value="1" id="sub<?= $aid ?>"<?= $a['status'] !== 'pending' ? ' disabled' : '' ?>>
                  <label class="form-check-label" for="sub<?= $aid ?>">ส่งหลักฐานแล้ว (แจ้งผู้ควบคุมตัวชี้วัดตรวจ)<?= $a['status'] !== 'pending' ? ' — ส่งแล้ว' : '' ?></label></div>
                <button class="btn btn-sm btn-primary ms-auto"><i class="bi bi-save"></i> บันทึก</button>
              </div>
            </div>
          </form>
          <?php endif; ?>
          <?php if ($canManage): ?>
          <form method="post" class="collapse kpi-subform review mt-2" id="rev-<?= $aid ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="review"><input type="hidden" name="activity_id" value="<?= $aid ?>">
            <div class="row g-2 align-items-end">
              <div class="col-sm-4"><label class="form-label small mb-0">สถานะ</label>
                <select name="status" class="form-select form-select-sm"><?php foreach (KPI_STATUS as $k => [$lb]): ?><option value="<?= $k ?>" <?= $a['status'] === $k ? 'selected' : '' ?>><?= $lb ?></option><?php endforeach; ?></select></div>
              <div class="col-sm-3"><label class="form-label small mb-0">คะแนนที่ได้<?= $a['max_score'] !== null ? ' (เต็ม ' . kpi_num($a['max_score']) . ')' : '' ?></label>
                <input name="score" class="form-control form-control-sm" inputmode="decimal" value="<?= $a['score'] !== null ? kpi_num($a['score']) : '' ?>"></div>
              <div class="col-sm-5"><label class="form-label small mb-0">หมายเหตุผลการตรวจ</label><input name="review_note" class="form-control form-control-sm" maxlength="500" value="<?= h($a['review_note']) ?>"></div>
              <div class="col-12 text-end"><button class="btn btn-sm btn-success"><i class="bi bi-check2"></i> บันทึกผลการตรวจ</button></div>
            </div>
            <div class="form-text">คะแนนนับเมื่อสถานะ "ตรวจแล้ว"</div>
          </form>
          <?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (!$l['activities']): ?><div class="list-group-item text-muted small">ยังไม่มีกิจกรรม/หลักฐาน</div><?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
<?php if (!$levels): ?><div class="alert alert-info">ยังไม่มีเกณฑ์การให้คะแนน<?= $canManage ? ' — กด "แก้ไขตัวชี้วัด" เพื่อเพิ่ม' : '' ?></div><?php endif; ?>

<?php if (trim((string)$ind['note']) !== '' || $ind['controller_name'] || $ind['collector_name']): ?>
<div class="card mb-3">
  <div class="card-body">
    <?php if (trim((string)$ind['note']) !== ''): ?><div class="mb-2"><strong>หมายเหตุ:</strong><div class="kpi-desc"><?= kpi_text($ind['note']) ?></div></div><?php endif; ?>
    <?php if ($ind['controller_name']): ?><div><strong>ผู้ควบคุมตัวชี้วัด:</strong> <?= h(preg_replace('/\s+/u', ' ', $ind['controller_name'])) ?><?= $ind['controller_position'] ? ' · ' . h($ind['controller_position']) : '' ?><?= $ind['controller_phone'] ? ' · โทร ' . h($ind['controller_phone']) : '' ?></div><?php endif; ?>
    <?php if ($ind['collector_name']): ?><div><strong>ผู้จัดเก็บข้อมูล:</strong> <?= h(preg_replace('/\s+/u', ' ', $ind['collector_name'])) ?><?= $ind['collector_position'] ? ' · ' . h($ind['collector_position']) : '' ?><?= $ind['collector_phone'] ? ' · โทร ' . h($ind['collector_phone']) : '' ?></div><?php endif; ?>
  </div>
</div>
<?php endif; ?>
<script>
// เปิดฟอร์มของกิจกรรมที่เพิ่งบันทึก (#act-ID) ให้เห็นทันที
if (location.hash.startsWith('#act-')) document.querySelector(location.hash)?.classList.add('kpi-flash');
</script>
<?php require __DIR__ . '/footer.php'; ?>
