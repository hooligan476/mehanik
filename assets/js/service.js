// mehanik/assets/js/service.js
// Исправленная логика: review rating uses UI scale 1..10; send_service_rating keeps 1..10; ratingTouched preserved

function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
function qsa(sel, ctx) { return Array.from((ctx || document).querySelectorAll(sel)); }

function isValidUiRating(v) {
  if (v === null || v === undefined || v === '') return false;
  const n = Number(v);
  return !Number.isNaN(n) && n >= 1 && n <= 10;
}
function isValidDbRating(v) {
  if (v === null || v === undefined || v === '') return false;
  const n = Number(v);
  return !Number.isNaN(n) && n >= 0.1 && n <= 5.0;
}

// map UI rating (1..10) -> DB rating (0.5..5.0) — still available if needed elsewhere
function mapUiToDbRating(ui) {
  if (ui === null || ui === undefined || ui === '') return '';
  const n = Number(ui);
  if (Number.isNaN(n)) return '';
  const raw = n / 2;
  const half = Math.round(raw * 2) / 2;
  const clamped = Math.max(0.1, Math.min(5.0, half));
  return clamped.toFixed(1);
}

// normalize arbitrary raw (from DOM or from window.__USER_REVIEW__.rating) TO UI SCALE integer 1..10
function normalizeToUiRating(raw) {
  if (raw === null || raw === undefined || raw === '') return '';
  const s = String(raw).trim();
  if (s === '') return '';
  const n = Number(s);
  if (Number.isNaN(n)) return '';
  // if it's likely DB scale 0.1..5.0 convert *2
  if (n > 0 && n <= 5) {
    // round to nearest 0.5 then *2 to get UI integer (0.5->1,1.0->2 etc)
    const ui = Math.round(n * 2);
    if (ui >= 1 && ui <= 10) return String(ui);
    return '';
  }
  // if it's already in 1..10
  if (n >= 1 && n <= 10) {
    return String(Math.round(n));
  }
  return '';
}

/* Lightbox (image-only helper kept for backward compatibility) */
function openLightbox(src) {
  // backward-compatible: show image in #lb (image mode)
  openLightboxMedia(src, 'image');
}

/* Close lightbox (improved to handle both image and video) */
function closeLightbox(e) {
  // allow calls without event
  if (e && e.target && e.target.classList && e.target.classList.contains('lb-close')) {
    // clicked close button -> proceed
  } else if (e && e.target && e.target.id && e.target.id !== 'lb' && !(e.target.classList && e.target.classList.contains('lb-close'))) {
    // clicked inner element — ignore
    return;
  }

  const lb = qs('#lb');
  if (!lb) return;

  const lbImg = qs('#lbImg');
  const lbVideo = qs('#lbVideo');

  // Pause and cleanup video
  if (lbVideo) {
    try { lbVideo.pause(); } catch (err) {}
    // remove sources
    while (lbVideo.firstChild) lbVideo.removeChild(lbVideo.firstChild);
    try { lbVideo.removeAttribute('src'); } catch (e) {}
    try { lbVideo.load && lbVideo.load(); } catch (e) {}
    lbVideo.style.display = 'none';
  }

  if (lbImg) {
    lbImg.src = '';
    lbImg.style.display = 'none';
  }

  lb.style.display = 'none';
  lb.classList.remove('active');
  lb.setAttribute('aria-hidden', 'true');
  // re-enable scrolling
  document.body.style.overflow = '';
}

/* LIGHTBOX: open image or video. Used by service.php media grid. */
function openLightboxMedia(url, type, mime) {
  const lb = qs('#lb');
  if (!lb) return;
  const lbImg = qs('#lbImg');
  const lbVideo = qs('#lbVideo');

  // cleanup previous
  if (lbImg) { lbImg.style.display = 'none'; lbImg.src = ''; }
  if (lbVideo) {
    try { lbVideo.pause(); } catch (e) {}
    while (lbVideo.firstChild) lbVideo.removeChild(lbVideo.firstChild);
    lbVideo.style.display = 'none';
    lbVideo.removeAttribute('src');
  }

  if (type === 'image') {
    if (!lbImg) return;
    lbImg.src = url;
    lbImg.style.display = 'block';
    if (lbVideo) lbVideo.style.display = 'none';
  } else if (type === 'video') {
    if (!lbVideo) return;
    // create source element
    const srcEl = document.createElement('source');
    srcEl.src = url;
    if (mime) srcEl.type = mime;
    lbVideo.appendChild(srcEl);
    lbVideo.style.display = 'block';
    // try to autoplay - may be blocked by browser if not muted
    lbVideo.muted = false;
    lbVideo.controls = true;
    // Attempt play, but ignore failures
    const p = lbVideo.play();
    if (p && typeof p.then === 'function') {
      p.catch(function(){ /* autoplay blocked - user must press play */ });
    }
  } else {
    // unknown type - try image fallback
    if (lbImg) { lbImg.src = url; lbImg.style.display = 'block'; }
  }

  lb.style.display = 'flex';
  lb.classList.add('active');
  lb.setAttribute('aria-hidden', 'false');
  // prevent body scrolling while open
  document.body.style.overflow = 'hidden';
}

/* KEYBOARD: close on Esc; also stop video when closing via keyboard */
document.addEventListener('keydown', function(e){
  if (e.key === 'Escape' || e.key === 'Esc') {
    const lb = qs('#lb');
    if (lb && (lb.style.display === 'flex' || lb.classList.contains('active'))) {
      closeLightbox();
    }
  }
});

/* startEdit / startReply */
window.startEdit = function (id) {
  try {
    const card = document.getElementById('review-' + id);
    if (!card) return;
    const commentNode = card.querySelector('.review-comment');
    const nameNode = card.querySelector('.review-name');
    const ratingNode = card.querySelector('.review-rating-value, .review-rating-num, [data-rating]');

    const commentEl = qs('#comment');
    const nameEl = qs('#user_name');
    const editIdEl = qs('#editing_review_id');
    const parentIdEl = qs('#parent_id');
    const formTitle = qs('#formTitle');
    const replyInd = qs('#replyIndicator');

    if (commentEl && commentNode) commentEl.value = commentNode.innerText.trim();
    if (nameEl && nameNode) nameEl.value = nameNode.innerText.trim();
    if (editIdEl) editIdEl.value = String(id);
    if (parentIdEl) parentIdEl.value = '0';
    if (formTitle) formTitle.textContent = 'Редактировать отзыв';
    if (replyInd) replyInd.style.display = 'none';

    // Try to extract UI-scale rating from ratingNode (may be DB-scale or UI-scale)
    const hidden = qs('#review_rating_hidden');
    if (hidden) {
      let raw = '';
      if (ratingNode) {
        if (ratingNode.getAttribute && ratingNode.getAttribute('data-rating')) raw = ratingNode.getAttribute('data-rating');
        else if (ratingNode.dataset && ratingNode.dataset.rating) raw = ratingNode.dataset.rating;
        else raw = (ratingNode.innerText || '').trim();
      } else if (window.__USER_REVIEW__ && typeof window.__USER_REVIEW__.rating !== 'undefined' && window.__USER_REVIEW__.rating !== null) {
        raw = String(window.__USER_REVIEW__.rating);
      }
      const ui = normalizeToUiRating(raw);
      // **Important:** do not auto-mark ratingTouched here. We only prefill the hidden so user can edit if they want.
      hidden.value = ui; // empty string if none
      if (window.__service_js_internal__) window.__service_js_internal__.ratingTouched = false;
    }

    if (commentEl) { commentEl.scrollIntoView({ behavior: 'smooth', block: 'center' }); commentEl.focus(); }
  } catch (err) { console.error('startEdit error', err); }
};

window.startReply = function (id, userName) {
  try {
    const form = qs('#reviewForm'); const commentEl = qs('#comment'); const parentEl = qs('#parent_id'); const editEl = qs('#editing_review_id');
    const title = qs('#formTitle'); const replyInd = qs('#replyIndicator'); const replyToText = qs('#replyToText');
    if (editEl) editEl.value = '';
    if (parentEl) parentEl.value = String(id);
    if (title) title.textContent = 'Ответить на отзыв';
    if (replyInd && replyToText) { replyToText.textContent = 'Ответ пользователю: ' + (userName || 'Гость'); replyInd.style.display = 'flex'; }
    if (form) form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    if (commentEl) { commentEl.placeholder = 'Ваш ответ...'; commentEl.focus(); }
  } catch (err) { console.error('startReply error', err); }
};

function cancelReply() { const parentEl = qs('#parent_id'); const editEl = qs('#editing_review_id'); const title = qs('#formTitle'); const replyInd = qs('#replyIndicator'); const commentEl = qs('#comment'); if (parentEl) parentEl.value='0'; if (editEl) editEl.value=''; if (title) title.textContent='Оставить отзыв'; if (replyInd) replyInd.style.display='none'; if (commentEl) commentEl.placeholder='Поделитесь впечатлением...'; }
window.cancelReply = cancelReply;

function resetReviewForm() {
  const form = qs('#reviewForm'); if (!form) return; form.reset();
  const editEl = qs('#editing_review_id'); const parent = qs('#parent_id'); const hidden = qs('#review_rating_hidden');
  if (editEl) editEl.value = ''; if (parent) parent.value = '0'; if (hidden) hidden.value = '';
  if (window.__service_js_internal__) window.__service_js_internal__.ratingTouched = false;
  cancelReply();
}
window.resetReviewForm = resetReviewForm;

/* Staff rating (unchanged) */
async function sendStaffRating(staffId, rating) {
  try {
    const fd = new FormData();
    fd.append('action', 'submit_staff_rating');
    fd.append('staff_id', staffId);
    fd.append('rating', rating);
    const resp = await fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd,
      credentials: 'same-origin'
    });
    const json = await resp.json();
    if (!json || !json.ok) { alert((json && json.error) ? json.error : 'Ошибка при отправке рейтинга сотрудника.'); return; }
    location.reload();
  } catch (err) { console.error('sendStaffRating error', err); alert('Ошибка отправки рейтинга сотрудника.'); }
}
window.sendStaffRating = sendStaffRating;

/* Map init (unchanged) */
function initMap() {
  try {
    const mapEl = qs('#map'); if (!mapEl) return;
    const loc = window.SERVICE_LOCATION || { lat:37.95,lng:58.38,zoom:13,name:'' };
    const center = { lat: Number(loc.lat)||37.95, lng: Number(loc.lng)||58.38 };
    const zoom = Number(loc.zoom)||13;
    if (typeof google === 'undefined' || typeof google.maps === 'undefined') { mapEl.innerHTML = '<div style="padding:18px;color:#444">Карта недоступна.</div>'; return; }
    const map = new google.maps.Map(mapEl, { center, zoom, streetViewControl:false });
    if (loc && loc.lat && loc.lng) {
      const marker = new google.maps.Marker({ position:center, map:map });
      const infow = new google.maps.InfoWindow({ content: String(loc.name||'') });
      marker.addListener('click', function(){ infow.open(map, marker); });
      infow.open(map, marker);
    }
  } catch (err) { console.warn('initMap error:', err); const mapEl = qs('#map'); if (mapEl) mapEl.innerHTML='<div style="padding:18px;color:#444">Карта недоступна.</div>'; }
}
window.initMap = initMap;

/* Main */
(function main() {
  window.__service_js_internal__ = window.__service_js_internal__ || { ratingTouched: false };
  window.__USER_REVIEW__ = window.__USER_REVIEW__ || null;

  // TEN-star picker
  const picker = qs('#ten-star-picker');
  if (picker) {
    const labels = qsa('#ten-star-picker label[data-value]');
    labels.forEach(lbl => {
      const v = Number(lbl.getAttribute('data-value'));
      lbl.addEventListener('mouseenter', () => labels.forEach(l => l.classList.toggle('preview', Number(l.getAttribute('data-value')) <= v)));
      lbl.addEventListener('mouseleave', () => labels.forEach(l => l.classList.remove('preview')));
      lbl.addEventListener('click', () => {
        window.__service_js_internal__.ratingTouched = true;
        const radio = qs('#pick-' + v); if (radio) radio.checked = true;
        labels.forEach(l => l.classList.toggle('active', Number(l.getAttribute('data-value')) <= v));
      });
    });

    qsa('#ten-star-picker input[name="first_rating"]').forEach(r => {
      r.addEventListener('change', (e) => {
        if (e.target && e.target.checked) {
          window.__service_js_internal__.ratingTouched = true;
          const v = Number(e.target.value);
          labels.forEach(l => l.classList.toggle('active', Number(l.getAttribute('data-value')) <= v));
        }
      });
    });

    // preselect user's rating visually (do NOT mark touched)
    if (window.__USER_REVIEW__ && typeof window.__USER_REVIEW__.rating !== 'undefined' && window.__USER_REVIEW__.rating !== null && window.__USER_REVIEW__.rating !== '') {
      const ui = normalizeToUiRating(window.__USER_REVIEW__.rating);
      if (ui) {
        const inp = qs('#pick-' + ui);
        if (inp) inp.checked = true;
        labels.forEach(l => l.classList.toggle('active', Number(l.getAttribute('data-value')) <= Number(ui)));
        const applyBtn = qs('#ten-star-apply');
        if (applyBtn) applyBtn.textContent = 'Редактировать мой отзыв';
      }
    }

    const applyBtn = qs('#ten-star-apply');
    if (applyBtn) {
      applyBtn.addEventListener('click', () => {
        const selected = picker.querySelector('input[name="first_rating"]:checked');
        if (!selected) { alert('Пожалуйста, выберите рейтинг (1–10).'); return; }
        const uiVal = selected.value;
        const hidden = qs('#review_rating_hidden');
        // IMPORTANT: store UI scale integer in hidden
        if (hidden) hidden.value = String(Math.round(Number(uiVal)));
        window.__service_js_internal__.ratingTouched = true;

        const form = qs('#reviewForm'); if (!form) { alert('Форма не найдена'); return; }
        if (window.__USER_REVIEW__ && window.__USER_REVIEW__.id) {
          const editEl = qs('#editing_review_id'); if (editEl) editEl.value = String(window.__USER_REVIEW__.id);
          const commentEl = qs('#comment'); if (commentEl && window.__USER_REVIEW__.comment) commentEl.value = window.__USER_REVIEW__.comment;
          const titleEl = qs('#formTitle'); if (titleEl) titleEl.textContent = 'Редактировать отзыв';
          form.scrollIntoView({ behavior:'smooth', block:'center' }); if (commentEl) commentEl.focus();
          alert('У вас уже есть отзыв — отредактируйте его и нажмите «Сохранить отзыв».');
        } else {
          const editEl = qs('#editing_review_id'); if (editEl) editEl.value = '';
          const parentEl = qs('#parent_id'); if (parentEl) parentEl.value = '0';
          form.scrollIntoView({ behavior:'smooth', block:'center' });
          const commentEl = qs('#comment'); if (commentEl) { commentEl.placeholder = 'Вы выбрали ' + uiVal + ' звезд(ы). Напишите комментарий и нажмите «Сохранить отзыв». '; commentEl.focus(); }
        }
      });
    }
  }

  /* Send service rating (button) — unchanged: sends UI 1..10 */
  const sendBtn = qs('#ten-star-send-rating');
  if (sendBtn) {
    sendBtn.addEventListener('click', async () => {
      try {
        const pickerEl = qs('#ten-star-picker'); if (!pickerEl) { alert('Пикер рейтинга не найден'); return; }
        const sel = pickerEl.querySelector('input[name="first_rating"]:checked'); if (!sel) { alert('Выберите рейтинг'); return; }
        const value = sel.value; if (!isValidUiRating(value)) { alert('Неверный рейтинг'); return; }
        const params = new URLSearchParams(); params.set('action','submit_service_rating'); params.set('rating', String(value));
        const resp = await fetch(window.location.pathname + window.location.search, {
          method:'POST',
          headers:{ 'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With':'XMLHttpRequest' },
          body: params.toString(), credentials:'same-origin'
        });
        if (!resp.ok) { const txt = await resp.text().catch(()=>null); throw new Error('HTTP ' + resp.status + (txt?(': '+txt):'')); }
        const json = await resp.json().catch(()=>{ throw new Error('Сервер вернул некорректный ответ'); });
        if (!json || !json.ok) { alert(json && json.error ? json.error : 'Ошибка при сохранении рейтинга'); return; }
        const avgStars = qs('#avgStars'), avgNum = qs('#avgNum'), avgMeta = qs('#avgMeta');
        if (avgStars && typeof json.avg !== 'undefined') avgStars.style.setProperty('--percent', ((Number(json.avg)/10)*100) + '%');
        if (avgNum && typeof json.avg !== 'undefined') avgNum.textContent = Number(json.avg).toFixed(1);
        if (avgMeta && typeof json.count !== 'undefined') avgMeta.textContent = (json.count || 0) + ' отзывов';
        alert('Спасибо! Ваш рейтинг сохранён.');
        setTimeout(()=>location.reload(), 600);
      } catch (err) { console.error('send service rating error', err); alert(err.message || 'Ошибка'); }
    });
  }

  // Review submit: send review_rating only if ratingTouched === true, and send UI 1..10 value
  const reviewForm = qs('#reviewForm');
  if (reviewForm) {
    reviewForm.addEventListener('submit', async function(ev){
      ev.preventDefault();
      const submitBtn = reviewForm.querySelector('button[type="submit"]');
      const origText = submitBtn ? submitBtn.textContent : null;
      try {
        if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Отправка...'; submitBtn.style.opacity='0.6'; }
        const comment = (qs('#comment') && qs('#comment').value || '').trim();
        if (!comment) { alert('Комментарий не может быть пустым.'); return; }
        const editingId = qs('#editing_review_id') ? qs('#editing_review_id').value : '';
        const parentId = qs('#parent_id') ? qs('#parent_id').value : '';
        const userName = qs('#user_name') ? qs('#user_name').value.trim() : '';

        let ratingUi = '';
        if (window.__service_js_internal__ && window.__service_js_internal__.ratingTouched) {
          const hidden = qs('#review_rating_hidden');
          if (hidden && hidden.value && isValidUiRating(hidden.value)) ratingUi = String(Math.round(Number(hidden.value)));
          // fallback to picker
          if (!ratingUi) {
            const pickerSel = qs('#ten-star-picker input[name="first_rating"]:checked');
            if (pickerSel && pickerSel.value && isValidUiRating(pickerSel.value)) ratingUi = String(Math.round(Number(pickerSel.value)));
          }
        } else ratingUi = '';

        const params = new URLSearchParams();
        params.set('action','upsert_review'); params.set('comment', comment);
        if (editingId) params.set('editing_review_id', String(editingId));
        if (parentId && parentId !== '0') params.set('parent_id', String(parentId));
        if (userName) params.set('user_name', userName);
        if (ratingUi) params.set('review_rating', ratingUi); // send UI 1..10 only when set

        const resp = await fetch(window.location.pathname + window.location.search, {
          method:'POST',
          headers:{ 'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With':'XMLHttpRequest' },
          body: params.toString(), credentials:'same-origin'
        });

        console.log('upsert_review HTTP', resp.status, resp.statusText);
        const json = await resp.json().catch(e => { console.error('JSON parse failed', e); throw new Error('Сервер вернул некорректный ответ'); });
        if (!json || !json.ok) { console.warn('Server error on upsert_review', json); alert(json.error || 'Ошибка при сохранении отзыва.'); return; }
        location.reload();
      } catch (err) {
        console.error('Review submit error:', err);
        alert(err.message || 'Ошибка при отправке отзыва.');
      } finally {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.style.opacity=''; if (origText) submitBtn.textContent = origText; }
      }
    });
  }

  // delegated reply/edit
  document.addEventListener('click', function(e){
    const t = e.target;
    if (!t) return;
    if (t.matches('.btn-reply') || (t.tagName==='BUTTON' && t.innerText && t.innerText.trim().toLowerCase().startsWith('ответ'))) {
      const rv = t.closest('[id^="review-"]'); if (rv) { const id = rv.id.replace(/^review-/,''); const nm = rv.querySelector('.review-name'); startReply(Number(id), nm?nm.innerText.trim():''); }
    }
    if (t.matches('.btn-edit') || (t.tagName==='BUTTON' && t.innerText && t.innerText.trim().toLowerCase().startsWith('измен'))) {
      const rv = t.closest('[id^="review-"]'); if (rv) { const id = rv.id.replace(/^review-/,''); startEdit(Number(id)); }
    }
  });

  // hideInvalidReviewStars (same as before)
  function hideInvalidReviewStars() {
    qsa('.review-stars').forEach(function(starsEl){
      try {
        let parent = starsEl.parentElement || starsEl.closest('div');
        let numEl = null;
        if (parent) numEl = parent.querySelector('.review-rating-num, .review-rating-value, [data-rating]');
        if (!numEl) {
          let next = starsEl.nextSibling;
          while(next && next.nodeType !== 1) next = next.nextSibling;
          if (next && (next.classList && (next.classList.contains('review-rating-num') || next.classList.contains('review-rating-value')))) {
            numEl = next;
          }
        }
        if (!numEl) { starsEl.style.display='none'; return; }
        let raw = '';
        if (numEl.getAttribute && numEl.getAttribute('data-rating')) raw = numEl.getAttribute('data-rating');
        else if (numEl.dataset && numEl.dataset.rating) raw = numEl.dataset.rating;
        else raw = (numEl.innerText || '').trim();
        if (!raw || raw.trim().toLowerCase() === 'null') { starsEl.style.display='none'; if (numEl) numEl.style.display='none'; return; }
        if (!isValidDbRating(raw) && !isValidUiRating(raw)) { starsEl.style.display='none'; if (numEl) numEl.style.display='none'; return; }
        starsEl.style.display='';
      } catch (e) { console.error('hideInvalidReviewStars', e); }
    });
  }
  hideInvalidReviewStars();
  setTimeout(hideInvalidReviewStars, 400);
})();
