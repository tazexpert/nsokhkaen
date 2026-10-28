<?php
/**
 * เข้าใช้ด้วย PIN 6 หลัก (ไม่ต้องพิมพ์ชื่อผู้ใช้/รหัสผ่าน) — เช่น สแกน QR Code เบิกวัสดุ
 * PIN ถูกต้อง = login แบบเต็มเหมือนใช้รหัสผ่าน แต่สิทธิ์ยังเป็นไปตามที่กำหนดในแต่ละระบบ
 */
require_once __DIR__ . '/core/bootstrap.php';

if (current_user()) {
    redirect(take_login_target(url('index.php')));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $pin = post('pin');
    throttle_failed_logins();
    if (!preg_match('/^\d{6}$/', $pin)) {
        $error = 'กรุณากรอก PIN 6 หลัก';
    } elseif ($row = find_user_by_pin($pin)) {
        login_user((int)$row['id'], true);
        redirect(take_login_target(url('index.php')));
    } else {
        $_SESSION['login_fails'] = ($_SESSION['login_fails'] ?? 0) + 1;
        audit_log('login_pin_failed', '', 'core', null);
        $error = 'PIN ไม่ถูกต้อง กรุณาตรวจสอบอีกครั้ง';
    }
}
// ข้อความหัวเรื่องตามหน้าที่กำลังจะเข้า
$target = (string)($_SESSION['redirect_after_login'] ?? '');
$title = str_contains($target, '/modules/inventory/scan.php') ? 'สแกน QR Code เพื่อเบิกวัสดุ' : 'เข้าใช้ด้วย PIN';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>กรอก PIN - <?= h(APP_NAME) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= h(url('assets/img/favicon.svg')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --accent: #2f8fe0;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            background: #fafbfc;
            font-family: "Segoe UI", "Sarabun", Tahoma, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .pin-screen {
            width: 100%;
            max-width: 360px;
            padding: 32px 24px 24px;
            text-align: center;
        }
        .pin-title {
            font-size: 1.05rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 4px;
        }
        .pin-subtitle {
            font-size: 0.8rem;
            color: #9aa1a9;
            margin-bottom: 28px;
        }
        .pin-dots {
            display: flex;
            justify-content: center;
            gap: 14px;
            margin-bottom: 28px;
        }
        .pin-dot {
            width: 16px;
            height: 16px;
            border-radius: 50%;
            border: 2px solid var(--accent);
            background: transparent;
            transition: background 0.15s ease;
        }
        .pin-dot.filled {
            background: var(--accent);
        }
        .pin-dots.shake {
            animation: shake 0.4s;
        }
        @keyframes shake {
            10%, 90% { transform: translateX(-2px); }
            20%, 80% { transform: translateX(4px); }
            30%, 50%, 70% { transform: translateX(-8px); }
            40%, 60% { transform: translateX(8px); }
        }
        .pin-hint {
            font-size: 0.78rem;
            color: var(--accent);
            margin-bottom: 20px;
            min-height: 18px;
        }
        .pin-error {
            font-size: 0.78rem;
            color: #e0432f;
            margin-bottom: 20px;
            min-height: 18px;
        }
        .pin-keypad {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 6px;
            max-width: 280px;
            margin: 0 auto;
        }
        .pin-key {
            border: none;
            background: transparent;
            color: var(--accent);
            font-size: 1.6rem;
            font-weight: 500;
            padding: 16px 0;
            border-radius: 50%;
            cursor: pointer;
            -webkit-tap-highlight-color: transparent;
            user-select: none;
        }
        .pin-key:active {
            background: rgba(47, 143, 224, 0.12);
        }
        .pin-key.empty {
            cursor: default;
            pointer-events: none;
        }
        .pin-key.backspace {
            font-size: 1.3rem;
        }
        .pin-footer {
            margin-top: 28px;
            font-size: 0.78rem;
            color: #9aa1a9;
            line-height: 1.6;
        }
        .pin-footer a {
            color: var(--accent);
            text-decoration: none;
        }
        .pin-footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
<div class="pin-screen">
    <div class="pin-title"><?= h($title) ?></div>
    <div class="pin-subtitle"><?= h(ORG_NAME) ?></div>

    <div class="pin-dots" id="pinDots">
        <?php for ($i = 0; $i < 6; $i++): ?>
            <div class="pin-dot"></div>
        <?php endfor; ?>
    </div>

    <?php if ($error): ?>
        <div class="pin-error" id="pinMessage"><?= h($error) ?></div>
    <?php else: ?>
        <div class="pin-hint" id="pinMessage"><i class="bi bi-shield-lock"></i> ใส่รหัส PIN ที่ตั้งไว้</div>
    <?php endif; ?>

    <div class="pin-keypad" id="pinKeypad">
        <?php foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $d): ?>
        <button type="button" class="pin-key" data-digit="<?= $d ?>"><?= $d ?></button>
        <?php endforeach; ?>
        <button type="button" class="pin-key empty"></button>
        <button type="button" class="pin-key" data-digit="0">0</button>
        <button type="button" class="pin-key backspace" id="pinBackspace"><i class="bi bi-backspace"></i></button>
    </div>

    <form method="post" id="pinForm" style="display:none;">
        <?= csrf_field() ?>
        <input type="hidden" name="pin" id="pinValue">
    </form>

    <div class="pin-footer">
        ยังไม่มี PIN? ตั้งได้ที่เมนู "บัญชีของฉัน" หลังเข้าสู่ระบบ หรือติดต่อผู้ดูแลระบบ<br>
        ต้องการ <a href="<?= h(url('login.php')) ?>">เข้าสู่ระบบด้วยชื่อผู้ใช้/รหัสผ่าน</a> แทน
    </div>
</div>

<script>
    let pin = '';
    const dots = document.querySelectorAll('.pin-dot');
    const dotsWrap = document.getElementById('pinDots');
    const keypad = document.getElementById('pinKeypad');
    const form = document.getElementById('pinForm');
    const pinValue = document.getElementById('pinValue');
    const hadError = <?= $error ? 'true' : 'false' ?>;

    function renderDots() {
        dots.forEach((dot, i) => dot.classList.toggle('filled', i < pin.length));
    }

    function submitPin() {
        pinValue.value = pin;
        form.submit();
    }

    function addDigit(d) {
        if (pin.length >= 6) return;
        pin += d;
        renderDots();
        if (pin.length === 6) {
            setTimeout(submitPin, 150);
        }
    }

    function removeDigit() {
        pin = pin.slice(0, -1);
        renderDots();
    }

    keypad.addEventListener('click', (e) => {
        const key = e.target.closest('.pin-key');
        if (!key) return;
        if (key.id === 'pinBackspace') {
            removeDigit();
        } else if (key.dataset.digit !== undefined) {
            addDigit(key.dataset.digit);
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key >= '0' && e.key <= '9') addDigit(e.key);
        else if (e.key === 'Backspace') removeDigit();
    });

    if (hadError) {
        dotsWrap.classList.add('shake');
        setTimeout(() => dotsWrap.classList.remove('shake'), 400);
    }
</script>
</body>
</html>
