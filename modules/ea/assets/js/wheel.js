// มอบหมายผู้ปฏิบัติงาน: วงล้อลุ้นผลการสุ่ม — หมุนหยุดที่ผู้ปฏิบัติงานทีละคน แล้วแสดงจำนวน EA ที่ได้
// ผลจริงคำนวณที่เซิร์ฟเวอร์แล้ว (อยู่ในตาราง) วงล้อเป็นการแสดงผลเท่านั้น
document.addEventListener('DOMContentLoaded', () => {
  const dataEl = document.getElementById('wheelData');
  if (!dataEl) { return; }
  const data = JSON.parse(dataEl.textContent);
  const randomIds = new Set(data.ids.map(String));
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const COLORS = ['#2563eb', '#16a34a', '#f59e0b', '#dc2626', '#9333ea', '#0891b2', '#db2777', '#65a30d', '#ea580c', '#4f46e5', '#0d9488', '#ca8a04'];
  const staff = data.staff.map((s, i) => ({ ...s, color: COLORS[i % COLORS.length] }));
  if (!staff.length || !randomIds.size) { return; }
  const shortName = n => n.replace(/^(นางสาว|นาง|นาย|ว่าที่ร้อยตรี|ว่าที่ ร\.ต\.|น\.ส\.)\s*/, '').split(/\s+/)[0];
  const nf = n => n.toLocaleString('th-TH');

  // ผลของแต่ละคน จากตาราง (เฉพาะ EA ที่สุ่มรอบนี้)
  const results = () => {
    const by = new Map(staff.map(s => [String(s.id), { n: 0, hh: 0, codes: [] }]));
    document.querySelectorAll('#assignTable .as-sel').forEach(sel => {
      const id = sel.name.match(/\d+/)[0];
      if (!randomIds.has(id) || !by.has(sel.value)) { return; }
      const tr = sel.closest('tr'), r = by.get(sel.value);
      r.n++; r.hh += +tr.dataset.hh;
      const code = tr.querySelector('.ea-code').textContent.trim();
      if (!r.codes.includes(code)) { r.codes.push(code); }
    });
    return by;
  };

  // ---------- วงล้อ (SVG) ----------
  const N = staff.length, seg = 360 / N, R = 150;
  const pt = (deg, r) => { const a = (deg - 90) * Math.PI / 180; return [160 + r * Math.cos(a), 160 + r * Math.sin(a)]; };
  let slices = '';
  staff.forEach((s, i) => {
    const a0 = i * seg, a1 = (i + 1) * seg, mid = a0 + seg / 2;
    const [x0, y0] = pt(a0, R), [x1, y1] = pt(a1, R);
    const path = N === 1 ? `<circle cx="160" cy="160" r="${R}" fill="${s.color}"/>`
      : `<path d="M160 160 L${x0} ${y0} A${R} ${R} 0 ${seg > 180 ? 1 : 0} 1 ${x1} ${y1} Z" fill="${s.color}" stroke="#fff" stroke-width="2"/>`;
    const [tx, ty] = pt(mid, R * 0.6);
    const label = escapeHtml(shortName(s.name).slice(0, 14));
    slices += path + `<text x="${tx}" y="${ty}" transform="rotate(${mid > 180 ? mid + 90 : mid - 90} ${tx} ${ty})" text-anchor="middle" dominant-baseline="middle"
      font-size="${N > 10 ? 11 : 14}" font-weight="600" fill="#fff">${label}</text>`;
  });
  const ov = document.createElement('div');
  ov.className = 'wheel-overlay';
  ov.innerHTML = `
    <div class="wheel-box" role="dialog" aria-modal="true" aria-label="ลุ้นผลการสุ่มผู้ปฏิบัติงาน">
      <div class="wheel-head"><i class="bi bi-bullseye"></i> ลุ้นผลการสุ่มผู้ปฏิบัติงาน <span class="text-muted">${escapeHtml(data.title)}</span>
        <button type="button" class="btn btn-sm btn-light ms-auto" data-wheel-skip>ข้าม <i class="bi bi-skip-forward"></i></button></div>
      <div class="wheel-body">
        <div class="wheel-wrap">
          <div class="wheel-pointer"></div>
          <svg viewBox="0 0 320 320" class="wheel-svg"><g class="wheel-rot">${slices}</g>
            <circle cx="160" cy="160" r="26" fill="#fff" stroke="#e2e8f0" stroke-width="3"/>
            <text x="160" y="161" text-anchor="middle" dominant-baseline="middle" font-size="12" font-weight="700" fill="#0f766e">EA</text></svg>
          <div class="wheel-now" aria-live="polite"></div>
        </div>
        <div class="wheel-list"></div>
      </div>
      <div class="wheel-foot"><span class="small text-muted wheel-progress"></span>
        <button type="button" class="btn btn-success ms-auto" data-wheel-close disabled><i class="bi bi-table"></i> ดูผลและตรวจสอบ</button></div>
      <div class="wheel-confetti"></div>
    </div>`;
  document.body.append(ov);
  const rot = ov.querySelector('.wheel-rot'), now = ov.querySelector('.wheel-now'), list = ov.querySelector('.wheel-list');
  const progress = ov.querySelector('.wheel-progress'), closeBtn = ov.querySelector('[data-wheel-close]');
  let angle = 0, runId = 0;
  const wait = ms => new Promise(r => setTimeout(r, ms));

  const spinTo = async (i, dur, id) => {
    // หมุนตามเข็มนาฬิกาอย่างน้อย 3–4 รอบ แล้วหยุดในช่องของคน i (คลาดจากกึ่งกลางเล็กน้อยให้ดูเป็นธรรมชาติ)
    const target = 360 - (i * seg + seg / 2 + (Math.random() - 0.5) * seg * 0.6);
    const base = angle - (angle % 360);
    angle = base + 360 * (3 + Math.floor(Math.random() * 2)) + target;
    rot.style.transition = reduce ? 'none' : `transform ${dur}ms cubic-bezier(.15,.85,.25,1)`;
    rot.style.transform = `rotate(${angle}deg)`;
    if (!reduce) { await wait(dur); }
    return id === runId;
  };
  const addResult = (s, r, k) => {
    const el = document.createElement('div');
    el.className = 'wheel-item';
    el.style.setProperty('--c', s.color);
    el.innerHTML = `<span class="wheel-dot"></span><div class="flex-grow-1 min-w-0"><div class="fw-semibold text-truncate">${escapeHtml(s.name)}</div>
      <div class="small text-muted text-truncate">${escapeHtml(r.codes.slice(0, 4).join(', '))}${r.codes.length > 4 ? ' …' : ''}</div></div>
      <div class="text-end text-nowrap"><div class="fs-5 fw-bold" style="color:${s.color}">${nf(r.n)} EA</div><div class="small text-muted">${nf(r.hh)} ครัวเรือน</div></div>`;
    list.append(el);
    list.scrollTop = list.scrollHeight;
  };
  const confetti = () => {
    if (reduce) { return; }
    const box = ov.querySelector('.wheel-confetti');
    box.innerHTML = '';
    for (let i = 0; i < 60; i++) {
      const p = document.createElement('i');
      p.style.left = Math.random() * 100 + '%';
      p.style.background = COLORS[i % COLORS.length];
      p.style.animationDelay = Math.random() * 0.6 + 's';
      p.style.setProperty('--x', (Math.random() * 120 - 60) + 'px');
      box.append(p);
    }
  };
  const finish = (by, order) => {
    list.innerHTML = '';
    order.forEach(i => addResult(staff[i], by.get(String(staff[i].id))));
    now.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill"></i> สุ่มครบแล้ว</span>';
    progress.textContent = 'มอบหมาย ' + nf(randomIds.size) + ' EA ให้ ' + nf(order.length) + ' คน — ตรวจ/สลับงานได้ก่อนบันทึก';
    closeBtn.disabled = false;
    closeBtn.focus();
    confetti();
  };
  const play = async () => {
    const id = ++runId;
    ov.classList.add('show');
    document.body.style.overflow = 'hidden';
    list.innerHTML = '';
    closeBtn.disabled = true;
    ov.querySelector('.wheel-confetti').innerHTML = '';
    const by = results();
    // ลำดับการลุ้น: สุ่มลำดับคนที่ได้งาน
    const order = staff.map((s, i) => i).filter(i => by.get(String(staff[i].id)).n > 0).sort(() => Math.random() - 0.5);
    if (reduce) { finish(by, order); return; }
    for (let k = 0; k < order.length; k++) {
      const i = order[k], s = staff[i];
      now.textContent = 'กำลังหมุน…';
      progress.textContent = 'คนที่ ' + (k + 1) + ' / ' + order.length;
      if (!await spinTo(i, k === 0 ? 3200 : 2200, id)) { return; }
      now.innerHTML = `<span style="color:${s.color}">${escapeHtml(s.name)}</span>`;
      addResult(s, by.get(String(s.id)), k);
      await wait(700);
      if (id !== runId) { return; }
    }
    finish(by, order);
  };
  const close = () => {
    runId++;
    ov.classList.remove('show');
    document.body.style.overflow = '';
    document.querySelectorAll('#assignTable tbody tr').forEach((tr, i) => {
      if (i < 40 && !reduce) { tr.classList.remove('as-flash'); void tr.offsetWidth; tr.style.setProperty('--d', (i * 20) + 'ms'); tr.classList.add('as-flash'); }
    });
  };
  ov.querySelector('[data-wheel-skip]').addEventListener('click', () => {
    if (!closeBtn.disabled) { close(); return; }
    runId++;
    const by = results();
    finish(by, staff.map((s, i) => i).filter(i => by.get(String(staff[i].id)).n > 0));
  });
  closeBtn.addEventListener('click', close);
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && ov.classList.contains('show')) { close(); } });
  const replay = document.getElementById('wheelReplay');
  if (replay) { replay.addEventListener('click', play); }
  play();
});
