// mehanik/assets/js/services.js
(function(){
  const searchEl = document.getElementById('svc-search');
  const sortEl = document.getElementById('svc-sort');
  const list = document.getElementById('services-list');
  if (!list) return;

  const debounceMs = 350;
  let t;

  function escapeHtml(s){ return String(s||'').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

  function statusClassInline(status) {
    if (!status) return 'background:#fff7ed;color:#b45309;border:1px solid rgba(245,158,11,0.08);';
    const st = String(status).toLowerCase();
    if (st === 'approved') return 'background:#ecfdf5;color:#16a34a;border:1px solid rgba(16,185,129,0.08);';
    if (st === 'active') return 'background:#eef2ff;color:#3730a3;border:1px solid rgba(59,130,246,0.08);';
    if (st === 'rejected' || st === 'declined') return 'background:#fff1f2;color:#b91c1c;border:1px solid rgba(239,68,68,0.08);';
    return 'background:#fff7ed;color:#b45309;border:1px solid rgba(245,158,11,0.08);';
  }

  function renderList(data) {
    list.innerHTML = '';
    if (!data || data.length === 0) {
      list.innerHTML = '<div style="padding:18px;background:#fff;border-radius:10px;border:1px solid #eef3f7;">Сервисов не найдено.</div>';
      return;
    }
    data.forEach(s => {
      const avg = Number(s.avg_rating||0).toFixed(1);
      const cnt = Number(s.reviews_count||0);
      const percent = Math.max(0, Math.min(100, (Number(avg)/5)*100));
      const logoHtml = s.logo ? `<img src="${escapeHtml(s.logo)}" alt="${escapeHtml(s.name)}" style="width:110px;height:110px;object-fit:cover;border-radius:8px;border:1px solid #e6eef7;">` :
        '<div style="width:110px;height:110px;border-radius:8px;background:#fbfdff;border:1px dashed #e6eef7;display:flex;align-items:center;justify-content:center;color:#9aa3ad;font-weight:700;">No img</div>';

      const statusInline = statusClassInline(s.status);
      const addressHtml = s.address ? `<div style="color:#6b7280;font-size:.95rem;">• ${escapeHtml(s.address)}</div>` : '';

      const canManage = s.user_id && (window.MEHANIK_USER_ID && Number(window.MEHANIK_USER_ID) === Number(s.user_id));
      // Note: server handles permission-sensitive actions (delete/edit) — JS shows only open link buttons by default.
      const card = document.createElement('div');
      card.className = 'card';
      card.style = 'background:#fff;padding:12px;border-radius:10px;border:1px solid #eef6fb;box-shadow:0 8px 24px rgba(12,20,30,0.04);display:flex;gap:12px;align-items:flex-start;';
      card.innerHTML = `
        <div style="width:110px;flex-shrink:0;">${logoHtml}</div>
        <div style="flex:1;min-width:0;">
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <a href="service.php?id=${encodeURIComponent(s.id)}" style="font-weight:800;color:#0b57a4;text-decoration:none;font-size:1.05rem;">${escapeHtml(s.name)}</a>
            <div style="${statusInline};padding:6px 10px;border-radius:999px;font-weight:700;font-size:.78rem;">${escapeHtml((s.status||'').toUpperCase())}</div>
          </div>
          <div style="color:#334155;margin-top:8px;font-size:.95rem;line-height:1.3;">${escapeHtml((s.description||'').slice(0,240))}${(s.description||'').length>240?'...':''}</div>
          <div style="display:flex;align-items:center;gap:12px;margin-top:10px;flex-wrap:wrap;">
            <div style="position:relative;">
              <div style="color:#eee;font-size:0.95rem;">★★★★★★★★★★</div>
              <div style="position:absolute;left:0;top:0;overflow:hidden;white-space:nowrap;width:${percent}%;color:gold;font-size:0.95rem;">★★★★★★★★★★</div>
            </div>
            <div style="font-weight:700;color:#111;">${avg} (${cnt})</div>
            ${addressHtml}
          </div>
          <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
            <a href="service.php?id=${encodeURIComponent(s.id)}" style="display:inline-block;padding:8px 12px;border-radius:8px;background:#f0f7ff;border:1px solid #dbeeff;color:#0b57a4;text-decoration:none;font-weight:700;">Открыть</a>
            <a href="appointment.php?id=${encodeURIComponent(s.id)}" style="display:inline-block;padding:8px 12px;border-radius:8px;background:#fff;border:1px solid #e6eef7;color:#0b57a4;text-decoration:none;font-weight:700;">Записаться</a>
            ${canManage ? `<a href="edit-service.php?id=${encodeURIComponent(s.id)}" style="display:inline-block;padding:8px 12px;border-radius:8px;background:#f0f7ff;border:1px solid #dbeeff;color:#0b57a4;text-decoration:none;font-weight:700;">Редактировать</a>` : ''}
          </div>
        </div>
      `;
      list.appendChild(card);
    });
  }

  async function load(q, sort) {
    const params = new URLSearchParams();
    if (q) params.set('q', q);
    if (sort) params.set('sort', sort);
    params.set('format', 'json');
    const url = '/mehanik/api/services.php?' + params.toString();
    try {
      const res = await fetch(url, { credentials: 'same-origin' });
      const json = await res.json();
      if (json.success) renderList(json.data);
    } catch (e) {
      console.warn('API error', e);
    }
  }

  function navigateAndLoad() {
    const q = searchEl.value.trim();
    const sort = sortEl ? sortEl.value : '';
    const url = new URL(window.location.href);
    if (q) url.searchParams.set('q', q); else url.searchParams.delete('q');
    if (sort) url.searchParams.set('sort', sort); else url.searchParams.delete('sort');
    history.replaceState(null, '', url.toString());
    load(q, sort);
  }

  // initial load (use query params)
  const params = new URLSearchParams(window.location.search);
  searchEl.value = params.get('q') || searchEl.value || '';
  if (sortEl && params.get('sort')) sortEl.value = params.get('sort');

  load(searchEl.value.trim(), sortEl ? sortEl.value : '');

  searchEl.addEventListener('input', function(){ clearTimeout(t); t = setTimeout(navigateAndLoad, debounceMs); });
  searchEl.addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); clearTimeout(t); navigateAndLoad(); } });
  if (sortEl) sortEl.addEventListener('change', function(){ clearTimeout(t); navigateAndLoad(); });
})();
