// มอบหมายผู้ปฏิบัติงาน: วงล้อลุ้นผลการสุ่ม (assign.php)
// - ปุ่ม [data-wheel-open] (สุ่มผู้ปฏิบัติงาน / สุ่มใหม่) เปิดวงล้อเท่านั้น → กด "เริ่มสุ่ม" ในวงล้อจึงสุ่มที่เซิร์ฟเวอร์ (AJAX)
//   วงล้อหมุนหยุดทีละคนแสดงจำนวน EA ที่ได้ → "ดูผลและตรวจสอบ" ไปหน้าผลสุ่ม (ยังไม่บันทึก สลับงานได้)
// - หน้าแสดงผลสุ่ม: ปุ่ม "ดูวงล้ออีกครั้ง" เล่นผลเดิมจากตาราง (ไม่สุ่มใหม่)
// - เปิดเต็มจอได้ (Fullscreen API; ถ้าเบราว์เซอร์ไม่รองรับ ขยายเต็มหน้าต่าง)
document.addEventListener('DOMContentLoaded', () => {
  const dataEl = document.getElementById('wheelData');
  if (!dataEl) { return; }
  const data = JSON.parse(dataEl.textContent);
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const COLORS = ['#2563eb', '#16a34a', '#f59e0b', '#dc2626', '#9333ea', '#0891b2', '#db2777', '#65a30d', '#ea580c', '#4f46e5', '#0d9488', '#ca8a04'];
  const shortName = n => n.replace(/^(นางสาว|นาง|นาย|ว่าที่ร้อยตรี|ว่าที่ ร\.ต\.|น\.ส\.)\s*/, '').split(/\s+/)[0];
  const nf = n => Number(n).toLocaleString('th-TH');
  const wait = ms => new Promise(r => setTimeout(r, ms));

  // ---------- โครงหน้าต่างวงล้อ ----------
  const ov = document.createElement('div');
  ov.className = 'wheel-overlay';
  ov.innerHTML = `
    <div class="wheel-box" role="dialog" aria-modal="true" aria-label="สุ่มผู้ปฏิบัติงาน">
      <div class="wheel-head"><i class="bi bi-bullseye"></i> สุ่มผู้ปฏิบัติงาน <span class="text-muted fw-normal">${escapeHtml(data.title)}</span>
        <span class="ms-auto d-flex gap-1">
          <button type="button" class="btn btn-sm btn-light" data-wheel-skip hidden>ข้าม <i class="bi bi-skip-forward"></i></button>
          <button type="button" class="btn btn-sm btn-light" data-wheel-full title="เต็มจอ"><i class="bi bi-arrows-fullscreen"></i></button>
          <button type="button" class="btn btn-sm btn-light" data-wheel-x title="ปิด"><i class="bi bi-x-lg"></i></button>
        </span></div>
      <div class="wheel-body">
        <div class="wheel-wrap">
          <div class="wheel-pointer"></div>
          <svg viewBox="0 0 320 320" class="wheel-svg"><g class="wheel-rot"></g>
            <circle cx="160" cy="160" r="26" fill="#fff" stroke="#e2e8f0" stroke-width="3"/>
            <text x="160" y="161" text-anchor="middle" dominant-baseline="middle" font-size="12" font-weight="700" fill="#0f766e">EA</text></svg>
          <div class="wheel-now" aria-live="polite"></div>
          <button type="button" class="btn btn-warning btn-lg wheel-start" data-wheel-start><i class="bi bi-play-circle-fill"></i> เริ่มสุ่ม</button>
        </div>
        <div class="wheel-list"></div>
      </div>
      <div class="wheel-foot"><span class="small text-muted wheel-progress"></span>
        <button type="button" class="btn btn-success ms-auto" data-wheel-done hidden><i class="bi bi-table"></i> ดูผลและตรวจสอบ</button></div>
      <div class="wheel-confetti"></div>
    </div>`;
  document.body.append(ov);
  const $ = sel => ov.querySelector(sel);
  const rot = $('.wheel-rot'), now = $('.wheel-now'), list = $('.wheel-list'), progress = $('.wheel-progress');
  const startBtn = $('[data-wheel-start]'), doneBtn = $('[data-wheel-done]'), skipBtn = $('[data-wheel-skip]');
  let staff = [], cfg = null, angle = 0, runId = 0, state = 'idle', lastResult = null;

  const draw = () => {
    const N = staff.length, seg = 360 / N, R = 150;
    const pt = (deg, r) => { const a = (deg - 90) * Math.PI / 180; return [160 + r * Math.cos(a), 160 + r * Math.sin(a)]; };
    rot.innerHTML = staff.map((s, i) => {
      const a0 = i * seg, a1 = (i + 1) * seg, mid = a0 + seg / 2;
      const [x0, y0] = pt(a0, R), [x1, y1] = pt(a1, R), [tx, ty] = pt(mid, R * 0.6);
      const shape = N === 1 ? `<circle cx="160" cy="160" r="${R}" fill="${s.color}"/>`
        : `<path d="M160 160 L${x0} ${y0} A${R} ${R} 0 ${seg > 180 ? 1 : 0} 1 ${x1} ${y1} Z" fill="${s.color}" stroke="#fff" stroke-width="2"/>`;
      return shape + `<text x="${tx}" y="${ty}" transform="rotate(${mid > 180 ? mid + 90 : mid - 90} ${tx} ${ty})" text-anchor="middle"
        dominant-baseline="middle" font-size="${N > 10 ? 11 : 14}" font-weight="600" fill="#fff">${escapeHtml(shortName(s.name).slice(0, 14))}</text>`;
    }).join('');
  };
  const spinTo = async (i, dur, id) => {
    // หมุนตามเข็มนาฬิกา 3–4 รอบ แล้วหยุดในช่องของคน i (คลาดจากกึ่งกลางเล็กน้อย)
    const seg = 360 / staff.length;
    const target = 360 - (i * seg + seg / 2 + (Math.random() - 0.5) * seg * 0.6);
    angle = angle - (angle % 360) + 360 * (3 + Math.floor(Math.random() * 2)) + target;
    rot.style.transition = reduce ? 'none' : `transform ${dur}ms cubic-bezier(.15,.85,.25,1)`;
    rot.style.transform = `rotate(${angle}deg)`;
    if (!reduce) { await wait(dur); }
    return id === runId;
  };
  const addResult = s => {
    const r = s.r, el = document.createElement('div');
    el.className = 'wheel-item';
    el.style.setProperty('--c', s.color);
    el.innerHTML = `<span class="wheel-dot"></span><div class="flex-grow-1 min-w-0"><div class="fw-semibold text-truncate">${escapeHtml(s.name)}</div>
      <div class="small text-muted text-truncate">${escapeHtml(r.codes.slice(0, 4).join(', '))}${r.n > 4 ? ' …' : ''}</div></div>
      <div class="text-end text-nowrap"><div class="fs-5 fw-bold" style="color:${s.color}">${nf(r.n)} EA</div><div class="small text-muted">${nf(r.hh)} ครัวเรือน</div></div>`;
    list.append(el);
    list.scrollTop = list.scrollHeight;
  };
  const confetti = () => {
    const box = $('.wheel-confetti');
    box.innerHTML = '';
    if (reduce) { return; }
    for (let i = 0; i < 60; i++) {
      const p = document.createElement('i');
      p.style.left = Math.random() * 100 + '%';
      p.style.background = COLORS[i % COLORS.length];
      p.style.animationDelay = Math.random() * 0.6 + 's';
      p.style.setProperty('--x', (Math.random() * 120 - 60) + 'px');
      box.append(p);
    }
  };
  const winners = () => staff.filter(s => s.r && s.r.n > 0);
  const finish = total => {
    runId++;
    state = 'done';
    list.innerHTML = '';
    const w = winners();
    w.sort((a, b) => a.order - b.order).forEach(addResult);
    now.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill"></i> สุ่มครบแล้ว</span>';
    progress.textContent = 'มอบหมาย ' + nf(total) + ' EA ให้ ' + nf(w.length) + ' คน — ตรวจ/สลับงานได้ก่อนบันทึก';
    skipBtn.hidden = true;
    doneBtn.hidden = false;
    doneBtn.focus();
    confetti();
  };
  const animate = async total => {
    const id = ++runId;
    state = 'spinning';
    skipBtn.hidden = false;
    list.innerHTML = '';
    const order = winners().sort(() => Math.random() - 0.5);
    order.forEach((s, k) => { s.order = k; });
    if (reduce) { finish(total); return; }
    for (let k = 0; k < order.length; k++) {
      const s = order[k];
      now.textContent = 'กำลังหมุน…';
      progress.textContent = 'คนที่ ' + (k + 1) + ' / ' + order.length;
      if (!await spinTo(staff.indexOf(s), k === 0 ? 3200 : 2200, id)) { return; }
      now.innerHTML = `<span style="color:${s.color}">${escapeHtml(s.name)}</span>`;
      addResult(s);
      await wait(700);
      if (id !== runId) { return; }
    }
    finish(total);
  };

  // เปิดวงล้อ (สถานะพร้อมสุ่ม) — opts: staff [{id,name}], start: async () => {results:[{id,n,hh,codes}], total, next?}, onDone
  const open = opts => {
    cfg = opts;
    staff = opts.staff.map((s, i) => ({ ...s, color: COLORS[i % COLORS.length], r: null, order: 0 }));
    angle = 0;
    rot.style.transition = 'none';
    rot.style.transform = 'rotate(0deg)';
    draw();
    state = 'ready';
    runId++;
    list.innerHTML = `<div class="wheel-ready"><div class="display-6 fw-bold">${nf(staff.length)} คน</div>
      <div class="text-muted">${opts.hint || ''}</div><div class="mt-3">กด <strong>เริ่มสุ่ม</strong> เพื่อสุ่มผู้ปฏิบัติงาน</div></div>`;
    now.textContent = '';
    progress.textContent = '';
    startBtn.hidden = false;
    startBtn.disabled = false;
    doneBtn.hidden = true;
    skipBtn.hidden = true;
    $('.wheel-confetti').innerHTML = '';
    ov.classList.add('show');
    document.body.style.overflow = 'hidden';
    startBtn.focus();
    if (opts.autoStart) { startBtn.click(); }
  };
  startBtn.addEventListener('click', async () => {
    if (state !== 'ready') { return; }
    startBtn.disabled = true;
    now.textContent = 'กำลังสุ่ม…';
    let res;
    try {
      res = await cfg.start();
    } catch (e) {
      res = { ok: false, error: 'สุ่มไม่สำเร็จ: ' + e.message };
    }
    if (!res || res.ok === false) {
      now.innerHTML = '<span class="text-danger small">' + escapeHtml((res && res.error) || 'สุ่มไม่สำเร็จ') + '</span>';
      startBtn.disabled = false;
      return;
    }
    lastResult = res;
    const byId = new Map(res.results.map(r => [String(r.id), r]));
    staff.forEach(s => { s.r = byId.get(String(s.id)) || { n: 0, hh: 0, codes: [] }; });
    startBtn.hidden = true;
    animate(res.total);
  });
  const exitFull = () => { if (document.fullscreenElement) { document.exitFullscreen().catch(() => {}); } ov.classList.remove('wheel-max'); };
  const close = () => {
    runId++;
    exitFull();
    ov.classList.remove('show');
    document.body.style.overflow = '';
    state = 'idle';
  };
  const done = () => {
    if (cfg && cfg.onDone) { cfg.onDone(lastResult); } else { close(); }
  };
  doneBtn.addEventListener('click', done);
  skipBtn.addEventListener('click', () => { if (state === 'spinning') { finish(lastResult.total); } });
  // ปิด: ถ้าสุ่มแล้ว = ไปดูผล (ผลสุ่มเก็บไว้แล้ว) · ยังไม่สุ่ม = ยกเลิก
  $('[data-wheel-x]').addEventListener('click', () => { (state === 'spinning' || state === 'done') && cfg.onDone ? done() : close(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && ov.classList.contains('show') && !document.fullscreenElement) { $('[data-wheel-x]').click(); } });
  // เต็มจอ
  $('[data-wheel-full]').addEventListener('click', () => {
    if (document.fullscreenElement || ov.classList.contains('wheel-max')) { exitFull(); return; }
    ov.classList.add('wheel-max');
    if (ov.requestFullscreen) { ov.requestFullscreen().catch(() => {}); }
  });
  document.addEventListener('fullscreenchange', () => {
    if (!document.fullscreenElement) { ov.classList.remove('wheel-max'); }
    $('[data-wheel-full] i').className = 'bi ' + (document.fullscreenElement ? 'bi-fullscreen-exit' : 'bi-arrows-fullscreen');
  });

  // ---------- ปุ่มสุ่มผู้ปฏิบัติงาน / สุ่มใหม่ ----------
  document.querySelectorAll('[data-wheel-open]').forEach(btn => btn.addEventListener('click', e => {
    e.preventDefault();
    const form = btn.form;
    const people = [...form.querySelectorAll('input[name="staff[]"]')].filter(i => i.type === 'hidden' || i.checked)
      .map(i => ({ id: +i.value, name: i.dataset.name || '' }));
    if (!people.length) { alert('กรุณาเลือกพนักงานที่รับผิดชอบอย่างน้อย 1 คน'); return; }
    if (window.eaAssignDirty && window.eaAssignDirty() && !confirm('สุ่มใหม่ — ผลที่แก้ไขในตารางจะถูกแทนที่ ดำเนินการต่อ?')) { return; }
    open({
      staff: people,
      hint: 'EA ตามเงื่อนไข ' + nf(data.eaCount) + ' รายการ',
      start: async () => {
        const fd = new FormData(form);
        fd.delete('assign');
        [...fd.keys()].filter(k => k.startsWith('assign[')).forEach(k => fd.delete(k));
        fd.set('action', 'random');
        fd.set('ajax', '1');
        const r = await fetch(form.getAttribute('action') || location.href, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } });
        return r.json();
      },
      onDone: res => { location.href = res.next; },
    });
  }));

  // ---------- หน้าแสดงผลสุ่ม: ดูวงล้ออีกครั้ง (ผลเดิมจากตาราง) ----------
  const replay = document.getElementById('wheelReplay');
  if (replay && data.replay) {
    const ids = new Set(data.ids.map(String));
    replay.addEventListener('click', () => {
      const people = [...document.querySelectorAll('#assignForm input[name="staff[]"]')].map(i => ({ id: +i.value, name: i.dataset.name || '' }));
      open({
        staff: people,
        hint: 'ผลการสุ่มเดิม ' + nf(ids.size) + ' EA',
        start: async () => {
          const by = new Map();
          document.querySelectorAll('#assignTable .as-sel').forEach(sel => {
            if (!ids.has(sel.name.match(/\d+/)[0]) || !sel.value) { return; }
            const tr = sel.closest('tr');
            const r = by.get(sel.value) || { id: sel.value, n: 0, hh: 0, codes: [] };
            r.n++; r.hh += +tr.dataset.hh;
            const code = tr.querySelector('.ea-code').textContent.trim();
            if (!r.codes.includes(code)) { r.codes.push(code); }
            by.set(sel.value, r);
          });
          return { ok: true, results: [...by.values()], total: ids.size };
        },
        onDone: () => {
          close();
          document.querySelectorAll('#assignTable tbody tr').forEach((tr, i) => {
            if (i < 40 && !reduce) { tr.classList.remove('as-flash'); void tr.offsetWidth; tr.style.setProperty('--d', (i * 20) + 'ms'); tr.classList.add('as-flash'); }
          });
        },
      });
    });
  }
});
