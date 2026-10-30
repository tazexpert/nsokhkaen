<?php
/**
 * บันทึกการใช้งาน (ผู้ดูแลระบบกลาง)
 * - แท็บ "สถิติรายบุคคล" (ค่าเริ่มต้น): สรุปจาก core_audit_log ตามช่วงเวลา — เข้าสู่ระบบ / วันที่ใช้งาน / การทำรายการ / ระบบที่ใช้
 *   / เข้าสู่ระบบไม่สำเร็จ / ใช้งานล่าสุด · เรียงตามลำดับบุคลากร (sort_staff) หรือตามจำนวนการเข้าใช้
 * - แท็บ "บันทึก (log)": 300 รายการล่าสุด ค้นหา / กรองตามผู้ใช้ได้
 */
require_once __DIR__ . '/../core/bootstrap.php';
require_superadmin();

const AUDIT_LOGIN_ACTIONS = ['login', 'login_pin'];
const AUDIT_NON_WORK = ['login', 'login_pin', 'logout', 'login_failed', 'login_pin_failed'];
$periods = ['7' => '7 วัน', '30' => '30 วัน', '90' => '90 วัน', 'year' => 'ปีนี้', 'all' => 'ทั้งหมด'];
$tab = ($_GET['tab'] ?? '') === 'log' ? 'log' : 'stats';
$period = isset($periods[$_GET['period'] ?? '']) ? $_GET['period'] : '30';
$sort = ($_GET['sort'] ?? '') === 'logins' ? 'logins' : 'staff';
$since = match ($period) {
    'all'   => '1970-01-01 00:00:00',
    'year'  => date('Y-01-01 00:00:00'),
    default => date('Y-m-d 00:00:00', strtotime('-' . ((int)$period - 1) . ' days')),
};
$labels = [
    'login' => 'เข้าสู่ระบบ', 'login_pin' => 'เข้าใช้ด้วย PIN', 'logout' => 'ออกจากระบบ',
    'login_failed' => 'เข้าสู่ระบบไม่สำเร็จ', 'login_pin_failed' => 'PIN ไม่ถูกต้อง',
    'user_create' => 'เพิ่มผู้ใช้', 'user_update' => 'แก้ไขผู้ใช้/สิทธิ์', 'user_delete' => 'ลบผู้ใช้',
    'user_toggle_active' => 'เปิด/ปิดบัญชี', 'change_password' => 'เปลี่ยนรหัสผ่าน', 'set_pin' => 'ตั้ง PIN',
    'remove_pin' => 'ลบ PIN', 'settings_update' => 'แก้ไขค่าระบบ', 'change_photo' => 'เปลี่ยนรูปโปรไฟล์',
    'notebook.edit' => 'แก้ไขสมุดบันทึก', 'notebook.restore' => 'นำสมุดบันทึกเวอร์ชันเดิมกลับมา', 'message.send' => 'ส่งข้อความ',
];
/** ชื่อ/ไอคอน/สีของระบบ */
$modInfo = function (string $key): array {
    if ($key === 'core') {
        return ['ระบบกลาง', 'bi-gear', '#64748b'];
    }
    $m = module_def($key);
    return [$m['short'] ?? $key, $m['icon'] ?? 'bi-grid', $m['color'] ?? '#64748b'];
};
$pdo = db();

if ($tab === 'stats') {
    $in = fn(array $a) => implode(',', array_map(fn($x) => $pdo->quote($x), $a));
    // ต่อผู้ใช้: จำนวนครั้งเข้าสู่ระบบ / วันที่มีการใช้งาน / การทำรายการ / ใช้งานล่าสุด
    $st = $pdo->prepare('SELECT user_id,
                                SUM(action IN (' . $in(AUDIT_LOGIN_ACTIONS) . ')) AS logins,
                                COUNT(DISTINCT DATE(created_at)) AS days,
                                SUM(action NOT IN (' . $in(AUDIT_NON_WORK) . ')) AS acts,
                                MAX(created_at) AS last_at
                         FROM core_audit_log WHERE user_id IS NOT NULL AND created_at >= ? GROUP BY user_id');
    $st->execute([$since]);
    $stat = [];
    foreach ($st as $r) {
        $stat[(int)$r['user_id']] = $r;
    }
    // ต่อผู้ใช้ต่อระบบ (ไม่นับการเข้า/ออกจากระบบ)
    $st = $pdo->prepare('SELECT user_id, module_key, COUNT(*) n FROM core_audit_log
                         WHERE user_id IS NOT NULL AND created_at >= ? AND action NOT IN (' . $in(AUDIT_NON_WORK) . ')
                         GROUP BY user_id, module_key ORDER BY n DESC');
    $st->execute([$since]);
    $mods = [];
    foreach ($st as $r) {
        $mods[(int)$r['user_id']][$r['module_key']] = (int)$r['n'];
    }
    // เข้าสู่ระบบไม่สำเร็จ (บันทึกเป็นชื่อผู้ใช้ที่พิมพ์)
    $st = $pdo->prepare("SELECT SUBSTRING(detail, 11) AS uname, COUNT(*) n FROM core_audit_log
                         WHERE action = 'login_failed' AND detail LIKE 'username: %' AND created_at >= ? GROUP BY uname");
    $st->execute([$since]);
    $failed = array_column($st->fetchAll(), 'n', 'uname');
    $st = $pdo->prepare("SELECT COUNT(*) FROM core_audit_log WHERE action IN ('login_failed', 'login_pin_failed') AND created_at >= ?");
    $st->execute([$since]);
    $failedTotal = (int)$st->fetchColumn();

    // ผู้ใช้: ที่เปิดใช้งาน + ที่ปิดแล้วแต่มีการใช้งานในช่วงนี้
    $users = $pdo->query('SELECT id, username, sort_order, position, full_name, photo, is_active, last_login_at FROM core_users')->fetchAll();
    $users = array_filter($users, fn($u) => $u['is_active'] || isset($stat[(int)$u['id']]));
    $users = array_values(sort_staff($users));
    if ($sort === 'logins') {
        usort($users, fn($a, $b) => [(int)($stat[(int)$b['id']]['logins'] ?? 0), (int)($stat[(int)$b['id']]['acts'] ?? 0)]
                                <=> [(int)($stat[(int)$a['id']]['logins'] ?? 0), (int)($stat[(int)$a['id']]['acts'] ?? 0)]);
    }
    $maxLogins = max([1, ...array_map(fn($u) => (int)($stat[(int)$u['id']]['logins'] ?? 0), $users)]);
    $activeUsers = count(array_filter($users, fn($u) => (int)($stat[(int)$u['id']]['logins'] ?? 0) > 0 || (int)($stat[(int)$u['id']]['acts'] ?? 0) > 0));
    $totalLogins = array_sum(array_map(fn($r) => (int)$r['logins'], $stat));
    $totalActs = array_sum(array_map(fn($r) => (int)$r['acts'], $stat));
} else {
    $q = trim($_GET['q'] ?? '');
    $uid = int_param('user');
    $conds = [];
    $params = [];
    if ($q !== '') {
        $conds[] = '(a.action LIKE ? OR a.detail LIKE ? OR u.full_name LIKE ? OR u.username LIKE ? OR a.ip LIKE ?)';
        $params = array_fill(0, 5, "%$q%");
    }
    if ($uid) {
        $conds[] = 'a.user_id = ?';
        $params[] = $uid;
        $st = $pdo->prepare('SELECT full_name FROM core_users WHERE id = ?');
        $st->execute([$uid]);
        $uidName = $st->fetchColumn() ?: '';
    }
    $st = $pdo->prepare('SELECT a.*, u.full_name, u.username FROM core_audit_log a LEFT JOIN core_users u ON u.id = a.user_id'
                        . ($conds ? ' WHERE ' . implode(' AND ', $conds) : '') . ' ORDER BY a.id DESC LIMIT 300');
    $st->execute($params);
    $rows = $st->fetchAll();
}

$pageTitle = 'บันทึกการใช้งาน';
$active = 'audit';
require APP_ROOT . '/core/layout/header.php';
$qs = fn(array $p) => h(url('admin/audit.php?' . http_build_query(array_filter($p + ['period' => $period !== '30' ? $period : null, 'sort' => $sort !== 'staff' ? $sort : null]))));
?>
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link<?= $tab === 'stats' ? ' active' : '' ?>" href="<?= h(url('admin/audit.php')) ?>"><i class="bi bi-bar-chart-line"></i> สถิติรายบุคคล</a></li>
  <li class="nav-item"><a class="nav-link<?= $tab === 'log' ? ' active' : '' ?>" href="<?= h(url('admin/audit.php?tab=log')) ?>"><i class="bi bi-journal-text"></i> บันทึก (log)</a></li>
</ul>

<?php if ($tab === 'stats'): ?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
  <div class="btn-group btn-group-sm" role="group" aria-label="ช่วงเวลา">
    <?php foreach ($periods as $k => $label): ?>
      <a href="<?= $qs(['period' => $k === '30' ? null : $k]) ?>" class="btn btn-<?= $period === (string)$k ? 'primary' : 'outline-primary' ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </div>
  <span class="small text-muted"><?= $period === 'all' ? 'ข้อมูลทั้งหมด' : 'ตั้งแต่ ' . h(thai_date($since)) . ' ถึงวันนี้' ?></span>
  <div class="btn-group btn-group-sm ms-auto" role="group" aria-label="เรียงลำดับ">
    <a href="<?= $qs(['sort' => null]) ?>" class="btn btn-<?= $sort === 'staff' ? 'secondary' : 'outline-secondary' ?>"><i class="bi bi-sort-numeric-down"></i> ตามลำดับบุคลากร</a>
    <a href="<?= $qs(['sort' => 'logins']) ?>" class="btn btn-<?= $sort === 'logins' ? 'secondary' : 'outline-secondary' ?>"><i class="bi bi-sort-down"></i> เข้าใช้มากที่สุด</a>
  </div>
</div>

<div class="row g-3 mb-3" data-reveal>
  <?php foreach ([
      ['text-bg-primary', 'bi-people', $activeUsers . ' <small class="fs-6 text-muted">/ ' . count($users) . ' คน</small>', 'ผู้ใช้ที่เข้าใช้งาน'],
      ['text-bg-success', 'bi-box-arrow-in-right', number_format($totalLogins), 'เข้าสู่ระบบ (ครั้ง)'],
      ['text-bg-info', 'bi-check2-square', number_format($totalActs), 'การทำรายการในระบบ'],
      ['text-bg-danger', 'bi-shield-exclamation', number_format($failedTotal), 'เข้าสู่ระบบไม่สำเร็จ'],
  ] as [$color, $icon, $num, $label]): ?>
    <div class="col-6 col-lg-3 reveal-item">
      <div class="stat-card h-100">
        <span class="stat-icon <?= $color ?>"><i class="bi <?= $icon ?>"></i></span>
        <div><div class="stat-num"><?= $num ?></div><div class="stat-label"><?= h($label) ?></div></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card reveal-item" data-reveal>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 audit-stats">
      <thead><tr>
        <th class="text-center" style="width:52px">ลำดับ</th><th>ผู้ใช้</th>
        <th style="min-width:200px">เข้าสู่ระบบ</th>
        <th class="text-center" title="จำนวนวันที่มีการใช้งาน">วันที่ใช้งาน</th>
        <th class="text-center" title="บันทึก/แก้ไข/อนุมัติ ฯลฯ (ไม่นับการเข้า-ออกจากระบบ)">ทำรายการ</th>
        <th>ระบบที่ใช้</th>
        <th class="text-center" title="พิมพ์รหัสผ่านผิด">ไม่สำเร็จ</th>
        <th>ใช้งานล่าสุด</th>
      </tr></thead>
      <tbody>
      <?php foreach ($users as $i => $u):
        $s = $stat[(int)$u['id']] ?? null;
        $logins = (int)($s['logins'] ?? 0);
        $fail = (int)($failed[$u['username']] ?? 0);
        $idle = !$s; ?>
        <tr class="<?= $idle ? 'audit-idle' : '' ?>">
          <td class="text-center text-muted"><?= $i + 1 ?></td>
          <td>
            <a href="<?= h(url('admin/audit.php?tab=log&user=' . (int)$u['id'])) ?>" class="d-flex align-items-center gap-2 text-reset text-decoration-none" title="ดูบันทึกการใช้งานของผู้ใช้นี้">
              <?= user_avatar($u, 32, 'flex-shrink-0') ?>
              <span class="min-w-0"><span class="d-block fw-semibold text-truncate"><?= h(preg_replace('/\s+/u', ' ', $u['full_name'])) ?><?= $u['is_active'] ? '' : ' <span class="badge text-bg-secondary">ปิดใช้งาน</span>' ?></span>
                <small class="d-block text-muted text-truncate"><?= h($u['position'] ?: ($u['username'] ?: '-')) ?></small></span>
            </a>
          </td>
          <td>
            <div class="d-flex align-items-center gap-2">
              <span class="bar-track"><span class="bar-fill" style="width:<?= round($logins / $maxLogins * 100) ?>%"></span></span>
              <span class="text-end fw-semibold" style="min-width:2.5rem"><?= number_format($logins) ?></span>
            </div>
          </td>
          <td class="text-center"><?= $s ? (int)$s['days'] : '<span class="text-muted">-</span>' ?></td>
          <td class="text-center"><?= $s && $s['acts'] ? number_format((int)$s['acts']) : '<span class="text-muted">-</span>' ?></td>
          <td>
            <?php foreach (array_slice($mods[(int)$u['id']] ?? [], 0, 6, true) as $mk => $n): [$mn, $mi, $mc] = $modInfo($mk); ?>
              <span class="audit-mod" style="--c:<?= h($mc) ?>" data-bs-toggle="tooltip" title="<?= h($mn) ?>: <?= $n ?> รายการ"><i class="bi <?= h($mi) ?>"></i><?= $n ?></span>
            <?php endforeach; ?>
            <?php if (empty($mods[(int)$u['id']])): ?><span class="text-muted">-</span><?php endif; ?>
          </td>
          <td class="text-center"><?= $fail ? '<span class="badge text-bg-warning">' . $fail . '</span>' : '<span class="text-muted">-</span>' ?></td>
          <td class="small text-nowrap">
            <?php if ($s): ?><?= h(time_ago($s['last_at'])) ?>
            <?php elseif ($u['last_login_at']): ?><span class="text-muted" title="นอกช่วงเวลาที่เลือก">เข้าระบบล่าสุด <?= h(time_ago($u['last_login_at'])) ?></span>
            <?php else: ?><span class="text-muted">ยังไม่เคยเข้าใช้</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$users): ?><tr><td colspan="8" class="text-center text-muted py-4">ไม่มีข้อมูล</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer small text-muted">
    <i class="bi bi-info-circle"></i> นับจากบันทึกการใช้งาน: "วันที่ใช้งาน" = จำนวนวันที่มีการเข้าระบบหรือทำรายการ · "ทำรายการ" = บันทึก/แก้ไข/อนุมัติ/นำเข้า ฯลฯ (ไม่นับการเข้า-ออกจากระบบ) · คลิกชื่อเพื่อดูบันทึกรายบุคคล
  </div>
</div>
<style>
  .audit-stats .min-w-0 { min-width: 0; }
  .audit-stats .bar-track { max-width: 220px; }
  .audit-idle td { color: var(--bs-secondary-color); }
  .audit-idle .avatar { opacity: .55; }
  .audit-mod { display: inline-flex; align-items: center; gap: .25rem; margin: 1px 2px; padding: 1px 7px; border-radius: 999px; font-size: .8rem;
               color: var(--c); background: color-mix(in srgb, var(--c) 12%, transparent); white-space: nowrap; }
</style>

<?php else: ?>
<div class="card">
  <div class="card-header">
    <form class="row g-2"><input type="hidden" name="tab" value="log">
      <?php if ($uid): ?><input type="hidden" name="user" value="<?= $uid ?>"><?php endif; ?>
      <div class="col-md-5"><input name="q" class="form-control" placeholder="ค้นหาผู้ใช้ / การกระทำ / IP" value="<?= h($q) ?>"></div>
      <div class="col-auto"><button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button></div>
      <?php if ($uid): ?>
        <div class="col-auto align-self-center"><span class="badge text-bg-primary fs-6 fw-normal"><i class="bi bi-person"></i> <?= h($uidName) ?>
          <a href="<?= h(url('admin/audit.php?' . http_build_query(array_filter(['tab' => 'log', 'q' => $q])))) ?>" class="text-white ms-1" aria-label="ล้างตัวกรองผู้ใช้"><i class="bi bi-x-lg"></i></a></span></div>
      <?php endif; ?>
      <div class="col-auto ms-auto small text-muted align-self-center">แสดง 300 รายการล่าสุด</div></form>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead><tr><th>เวลา</th><th>ผู้ใช้</th><th>ระบบ</th><th>การกระทำ</th><th>รายละเอียด</th><th>IP</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr class="<?= str_contains($r['action'], 'failed') ? 'table-warning' : '' ?>">
          <td class="text-nowrap small"><?= h(thai_date($r['created_at'])) ?> <?= h(substr($r['created_at'], 11, 5)) ?></td>
          <td><?= h($r['full_name'] ?? '-') ?></td>
          <td class="small"><?= h($modInfo($r['module_key'])[0]) ?></td>
          <td><?= h($labels[$r['action']] ?? $r['action']) ?></td>
          <td class="small text-muted"><?= h($r['detail']) ?></td>
          <td class="small text-muted"><?= h($r['ip']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">ไม่มีข้อมูล</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php require APP_ROOT . '/core/layout/footer.php'; ?>
