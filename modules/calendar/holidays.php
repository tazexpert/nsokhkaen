<?php
/**
 * วันหยุดราชการ / วันหยุดพิเศษ (ผู้ดูแลปฏิทิน) — นอกจากเสาร์-อาทิตย์ เช่น วันหยุดชดเชย วันหยุดพิเศษตามมติ ครม.
 *   แสดงเป็นวันหยุด (สีเทา + ชื่อวันหยุด) ในปฏิทินรายเดือน ตารางกิจกรรม และแผนการปฏิบัติงาน
 *   "เพิ่มวันหยุดประจำปี" = วันหยุดราชการที่วันที่คงที่ของปีที่เลือก (วันพืชมงคล/วันหยุดตามจันทรคติ/ชดเชย/พิเศษ เพิ่มเอง)
 *   วันแรงงานแห่งชาติไม่ใช่วันหยุดราชการ จึงไม่อยู่ในรายการ
 */
require_once __DIR__ . '/bootstrap.php';
$me = require_module(CAL_MODULE, 'admin');
$pdo = db();
$year = (int)($_GET['y'] ?? date('Y'));                      // ค.ศ.
$year = $year >= 2000 && $year <= 2100 ? $year : (int)date('Y');
$hasTable = cal_has_holidays();

// วันหยุดราชการที่วันที่คงที่ [MM-DD => ชื่อ]
const CAL_FIXED_HOLIDAYS = [
    '01-01' => 'วันขึ้นปีใหม่', '04-06' => 'วันจักรี', '04-13' => 'วันสงกรานต์', '04-14' => 'วันสงกรานต์', '04-15' => 'วันสงกรานต์',
    '05-04' => 'วันฉัตรมงคล',                                  // วันแรงงานแห่งชาติ (1 พ.ค.) ไม่ใช่วันหยุดราชการ
    '06-03' => 'วันเฉลิมพระชนมพรรษาสมเด็จพระนางเจ้าฯ พระบรมราชินี', '07-28' => 'วันเฉลิมพระชนมพรรษาพระบาทสมเด็จพระเจ้าอยู่หัว',
    '08-12' => 'วันแม่แห่งชาติ', '10-13' => 'วันนวมินทรมหาราช', '10-23' => 'วันปิยมหาราช', '12-05' => 'วันพ่อแห่งชาติ',
    '12-10' => 'วันรัฐธรรมนูญ', '12-31' => 'วันสิ้นปี',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasTable) {
    check_csrf();
    $action = post('action');
    if ($action === 'add') {
        $date = post('date');
        $name = trim(post('name'));
        if (!DateTime::createFromFormat('!Y-m-d', $date) || $name === '') {
            flash('กรุณาเลือกวันที่และระบุชื่อวันหยุด', 'danger');
        } else {
            $pdo->prepare('INSERT INTO cal_holidays (holiday_date, name, created_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)')
                ->execute([$date, mb_substr($name, 0, 150), $me['id']]);
            audit_log('cal_holiday_add', $date . ' ' . $name, CAL_MODULE);
            flash('บันทึกวันหยุด ' . thai_date($date) . ' — ' . $name . ' แล้ว');
            $year = (int)substr($date, 0, 4);
        }
    } elseif ($action === 'delete') {
        $date = post('date');
        $pdo->prepare('DELETE FROM cal_holidays WHERE holiday_date = ?')->execute([$date]);
        audit_log('cal_holiday_delete', $date, CAL_MODULE);
        flash('ลบวันหยุด ' . thai_date($date) . ' แล้ว');
        $year = (int)substr($date, 0, 4);
    } elseif ($action === 'preset') {
        $ins = $pdo->prepare('INSERT IGNORE INTO cal_holidays (holiday_date, name, created_by) VALUES (?, ?, ?)');
        $n = 0;
        foreach (CAL_FIXED_HOLIDAYS as $md => $name) {
            $ins->execute([$year . '-' . $md, $name, $me['id']]);
            $n += $ins->rowCount();
        }
        audit_log('cal_holiday_preset', 'ปี ' . ($year + 543) . ' เพิ่ม ' . $n . ' วัน', CAL_MODULE);
        flash('เพิ่มวันหยุดประจำปี ' . ($year + 543) . ' แล้ว ' . $n . ' วัน (วันที่มีอยู่แล้วไม่เปลี่ยน)');
    }
    redirect('holidays?y=' . $year);
}

$list = $hasTable ? cal_holidays($year . '-01-01', $year . '-12-31') : [];
$active = 'holiday';
$pageTitle = 'วันหยุด';
$headExtra = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css" integrity="sha384-RkASv+6KfBMW9eknReJIJ6b3UnjKOKC5bOUaNgIY778NFbQ8MtWq9Lr/khUgqtTt" crossorigin="anonymous">';
require __DIR__ . '/header.php';
?>
<?php if (!$hasTable): ?>
  <div class="alert alert-warning">ต้องรัน migration <code>database/migrations/20261217_cal_holidays.sql</code> ก่อนใช้งาน</div>
<?php else: ?>
<div class="row g-3" data-reveal>
  <div class="col-lg-4 reveal-item">
    <form method="post" class="card mb-3">
      <?= csrf_field() ?><input type="hidden" name="action" value="add">
      <div class="card-header"><strong><i class="bi bi-calendar-plus"></i> เพิ่มวันหยุด</strong></div>
      <div class="card-body">
        <div class="mb-2"><label class="form-label">วันที่ <span class="text-danger">*</span></label>
          <input type="text" name="date" class="form-control thai-date" required></div>
        <div class="mb-3"><label class="form-label">ชื่อวันหยุด <span class="text-danger">*</span></label>
          <input name="name" class="form-control" maxlength="150" required placeholder="เช่น วันหยุดชดเชย / วันหยุดพิเศษ"></div>
        <button class="btn btn-primary"><i class="bi bi-check2"></i> บันทึก</button>
        <div class="small text-muted mt-2">วันที่มีอยู่แล้ว = แก้ชื่อวันหยุด · เสาร์-อาทิตย์เป็นวันหยุดอยู่แล้ว ไม่ต้องเพิ่ม</div>
      </div>
    </form>
    <form method="post" class="card" onsubmit="return confirm('เพิ่มวันหยุดราชการประจำปี <?= $year + 543 ?> (<?= count(CAL_FIXED_HOLIDAYS) ?> วันที่วันที่คงที่)?')">
      <?= csrf_field() ?><input type="hidden" name="action" value="preset">
      <div class="card-header"><strong><i class="bi bi-magic"></i> เพิ่มวันหยุดประจำปี <?= $year + 543 ?></strong></div>
      <div class="card-body small">
        <p class="mb-2">เพิ่มวันหยุดราชการที่วันที่คงที่ทุกปี (<?= count(CAL_FIXED_HOLIDAYS) ?> วัน เช่น วันขึ้นปีใหม่ วันสงกรานต์ วันพ่อแห่งชาติ)
          — วันที่เปลี่ยนทุกปี ให้เพิ่มเอง: <b>วันพืชมงคล</b> (ตามประกาศ) วันหยุดตามจันทรคติ (มาฆบูชา วิสาขบูชา อาสาฬหบูชา เข้าพรรษา) วันหยุดชดเชย และวันหยุดพิเศษ</p>
        <button class="btn btn-outline-primary btn-sm"><i class="bi bi-plus-lg"></i> เพิ่มวันหยุดประจำปี</button>
      </div>
    </form>
  </div>
  <div class="col-lg-8 reveal-item" style="--d:70ms">
    <div class="card">
      <div class="card-header d-flex align-items-center gap-2">
        <a href="?y=<?= $year - 1 ?>" class="btn btn-light btn-sm" aria-label="ปีก่อน"><i class="bi bi-chevron-left"></i></a>
        <strong>วันหยุดปี <?= $year + 543 ?></strong> <span class="badge text-bg-light border"><?= count($list) ?> วัน</span>
        <a href="?y=<?= $year + 1 ?>" class="btn btn-light btn-sm" aria-label="ปีถัดไป"><i class="bi bi-chevron-right"></i></a>
      </div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead><tr><th style="width:14rem">วันที่</th><th>ชื่อวันหยุด</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($list as $d => $name): $w = (int)date('w', strtotime($d)); ?>
            <tr<?= $d < date('Y-m-d') ? ' class="text-muted"' : '' ?>>
              <td class="text-nowrap"><?= h(cal_date_text($d, true)) ?><?= $w === 0 || $w === 6 ? ' <span class="badge text-bg-light border">เสาร์-อาทิตย์</span>' : '' ?></td>
              <td><?= h($name) ?></td>
              <td class="text-end"><form method="post" class="d-inline" onsubmit="return confirm('ลบวันหยุด <?= h(addslashes($name)) ?>?')"><?= csrf_field() ?>
                <input type="hidden" name="action" value="delete"><input type="hidden" name="date" value="<?= h($d) ?>">
                <button class="btn btn-sm btn-outline-danger" title="ลบ"><i class="bi bi-trash"></i></button></form></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$list): ?><tr><td colspan="3" class="text-center text-muted py-4">ยังไม่มีวันหยุดของปี <?= $year + 543 ?> — เพิ่มวันหยุดประจำปี หรือเพิ่มทีละวัน</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js" integrity="sha384-5JqMv4L/Xa0hfvtF06qboNdhvuYXUku9ZrhZh3bSk8VXF0A/RuSLHpLsSV9Zqhl6" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/th.js" integrity="sha384-E6OVFg1zUc/pckGxry0YZ6VeZ1E17l7Jl8ZQx58jbEfxxkz9LqknQhiIrA9zg/8b" crossorigin="anonymous"></script>
<script src="<?= h(asset_v('modules/calendar/assets/js/thai-picker.js')) ?>"></script>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
