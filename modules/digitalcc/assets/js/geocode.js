// ค้นหาพิกัดศูนย์จาก OpenStreetMap Nominatim (ทำงานในเบราว์เซอร์ของผู้ใช้)
(function () {
  const table = document.getElementById('geoTable');
  if (!table) return;
  const rows = [...table.querySelectorAll('tbody tr[data-id]')];
  const KK_VIEWBOX = '101.85,17.25,103.35,15.55'; // ขอบเขตจังหวัดขอนแก่นโดยประมาณ
  const NOMINATIM = 'https://nominatim.openstreetmap.org/search';
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const progress = document.getElementById('progress');
  const btnRun = document.getElementById('btnRun');
  const btnSaveAll = document.getElementById('btnSaveAll');

  // ----- แผนที่ -----
  const map = L.map('geoMap').setView([16.43, 102.6], 9);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
  const markers = new Map();

  function parseCoord(text) {
    const m = String(text).match(/(-?\d+(?:\.\d+)?)\s*[, ]\s*(-?\d+(?:\.\d+)?)/);
    if (!m) return null;
    const lat = +m[1], lng = +m[2];
    return lat > 5 && lat < 21 && lng > 97 && lng < 106 ? { lat, lng } : null;
  }

  function setPoint(tr, p, pan) {
    const id = tr.dataset.id;
    let mk = markers.get(id);
    if (!p) { if (mk) { map.removeLayer(mk); markers.delete(id); } return; }
    if (!mk) {
      mk = L.marker([p.lat, p.lng], { draggable: true, title: tr.dataset.name, icon: dccPin() }).addTo(map).bindTooltip(tr.dataset.name);
      mk.on('dragend', () => {
        const q = mk.getLatLng();
        tr.querySelector('.coord').value = q.lat.toFixed(7) + ', ' + q.lng.toFixed(7);
        markDirty(tr);
      });
      mk.on('click', () => tr.scrollIntoView({ block: 'center' }));
      markers.set(id, mk);
    } else mk.setLatLng([p.lat, p.lng]);
    if (pan) map.setView([p.lat, p.lng], 15);
  }

  function markDirty(tr) {
    tr.classList.add('table-warning');
    tr.dataset.dirty = '1';
    btnSaveAll.disabled = !rows.some(r => r.dataset.dirty);
  }

  // ----- ค้นหา -----
  async function nominatim(q) {
    const url = `${NOMINATIM}?format=jsonv2&addressdetails=1&countrycodes=th&accept-language=th&limit=6&viewbox=${KK_VIEWBOX}&bounded=1&q=${encodeURIComponent(q)}`;
    const r = await fetch(url, { headers: { Accept: 'application/json' } });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return r.json();
  }

  // ตรวจว่าผลอยู่ในอำเภอที่ต้องการ โดยดูเฉพาะส่วนที่อยู่ (ไม่นับชื่อสถานที่ เพราะชื่อโรงเรียนมักมีชื่ออำเภออยู่ด้วย)
  function inDistrict(x, dist) {
    const parts = Object.entries(x.address || {})
      .filter(([k]) => ['county', 'state_district', 'district', 'city', 'town', 'municipality'].includes(k))
      .map(([, v]) => v);
    if (!parts.length) parts.push(...x.display_name.split(',').slice(1)); // ไม่มี addressdetails
    return parts.some(v => {
      v = String(v).trim().replace(/^(อำเภอ|อ\.)\s*/, '');
      return v === dist;
    });
  }

  async function searchRow(tr) {
    const name = tr.dataset.name, sub = tr.dataset.sub, dist = tr.dataset.district;
    const cell = tr.querySelector('.result');
    cell.innerHTML = '<span class="spinner-border spinner-border-sm"></span> กำลังค้นหา…';
    const found = [];
    const add = (list, approx) => list.forEach(x => {
      if (found.some(f => f.osm_id === x.osm_id)) return;
      found.push({ ...x, approx, inDistrict: inDistrict(x, dist) });
    });
    try {
      add(await nominatim(name), false);
      if (!found.some(f => f.inDistrict)) {
        await sleep(1100);
        add(await nominatim(name.replace(/^โรงเรียน/, '') + ' ' + dist), false);
      }
      if (!found.some(f => f.inDistrict)) {
        await sleep(1100);
        add(await nominatim(`ตำบล${sub} อำเภอ${dist}`), true);
      }
    } catch (e) {
      cell.innerHTML = `<span class="text-danger"><i class="bi bi-wifi-off"></i> ค้นหาไม่สำเร็จ (${esc(e.message)})</span>`;
      return;
    }

    // เรียง: ตรงอำเภอและไม่ใช่ค่าประมาณก่อน
    found.sort((a, b) => (b.inDistrict - a.inDistrict) || (a.approx - b.approx));
    if (!found.length) {
      cell.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> ไม่พบ — ลองค้นใน Google Maps</span>';
      return;
    }
    const best = found[0];
    const auto = best.inDistrict && !best.approx;
    const opts = found.map((f, i) => {
      const label = (f.approx ? '[ประมาณ: ศูนย์กลางตำบล] ' : '') + (f.inDistrict ? '' : '[คนละอำเภอ] ') + f.display_name;
      return `<option value="${i}"${i === 0 && auto ? ' selected' : ''}>${esc(label.slice(0, 140))}</option>`;
    }).join('');
    cell.innerHTML = `<div class="mb-1">${auto
      ? '<span class="badge text-bg-success">พบในอำเภอเดียวกัน</span>'
      : best.approx && best.inDistrict
        ? '<span class="badge text-bg-warning">ไม่พบชื่อศูนย์ — มีเฉพาะตำแหน่งตำบล</span>'
        : '<span class="badge text-bg-danger">ไม่พบในอำเภอนี้ — ตรวจสอบเอง</span>'}</div>
      <select class="form-select form-select-sm cand"><option value="">— เลือกผลการค้นหา —</option>${opts}</select>`;
    const sel = cell.querySelector('.cand');
    sel.addEventListener('change', () => {
      const f = found[+sel.value];
      if (!f || sel.value === '') return;
      tr.querySelector('.coord').value = (+f.lat).toFixed(7) + ', ' + (+f.lon).toFixed(7);
      setPoint(tr, { lat: +f.lat, lng: +f.lon }, true);
      markDirty(tr);
    });
    if (auto) {
      tr.querySelector('.coord').value = (+best.lat).toFixed(7) + ', ' + (+best.lon).toFixed(7);
      setPoint(tr, { lat: +best.lat, lng: +best.lon });
      markDirty(tr);
    }
  }

  // ----- บันทึก -----
  async function saveRow(tr) {
    const p = parseCoord(tr.querySelector('.coord').value);
    if (!p) { alert('รูปแบบพิกัดไม่ถูกต้อง: ' + tr.dataset.name); return false; }
    const body = new URLSearchParams({ csrf: window.GEO_CSRF, id: tr.dataset.id, lat: p.lat, lng: p.lng });
    const r = await fetch('geocode.php', { method: 'POST', body });
    const j = await r.json().catch(() => ({}));
    if (!r.ok || !j.ok) { alert('บันทึกไม่สำเร็จ: ' + (j.error || r.status)); return false; }
    tr.classList.remove('table-warning');
    tr.classList.add('table-success');
    delete tr.dataset.dirty;
    btnSaveAll.disabled = !rows.some(r => r.dataset.dirty);
    return true;
  }

  // ----- ผูกเหตุการณ์ -----
  rows.forEach(tr => {
    const input = tr.querySelector('.coord');
    const p = parseCoord(input.value);
    if (p) setPoint(tr, p);
    input.addEventListener('input', () => { const q = parseCoord(input.value); if (q) { setPoint(tr, q, true); markDirty(tr); } });
    tr.querySelector('.one').addEventListener('click', () => searchRow(tr));
    tr.querySelector('.save').addEventListener('click', () => saveRow(tr));
  });

  btnRun.addEventListener('click', async () => {
    btnRun.disabled = true;
    const todo = rows.filter(tr => !parseCoord(tr.querySelector('.coord').value));
    for (let i = 0; i < todo.length; i++) {
      progress.textContent = `กำลังค้นหา ${i + 1} / ${todo.length}`;
      await searchRow(todo[i]);
      await sleep(1100); // นโยบาย Nominatim: ไม่เกิน 1 ครั้งต่อวินาที
    }
    const ok = todo.filter(tr => tr.dataset.dirty).length;
    progress.textContent = `ค้นหาเสร็จ — เลือกพิกัดให้อัตโนมัติ ${ok} จาก ${todo.length} ศูนย์ ตรวจบนแผนที่แล้วกด "บันทึกทั้งหมดที่เลือก"`;
    if (markers.size) map.fitBounds(L.featureGroup([...markers.values()]).getBounds(), { padding: [30, 30] });
    btnRun.disabled = false;
  });

  btnSaveAll.addEventListener('click', async () => {
    btnSaveAll.disabled = true;
    let n = 0;
    for (const tr of rows.filter(r => r.dataset.dirty)) if (await saveRow(tr)) n++;
    progress.textContent = `บันทึกพิกัดแล้ว ${n} ศูนย์`;
  });

  setTimeout(() => {
    map.invalidateSize();
    if (markers.size) map.fitBounds(L.featureGroup([...markers.values()]).getBounds(), { padding: [30, 30] });
  }, 200);
})();
