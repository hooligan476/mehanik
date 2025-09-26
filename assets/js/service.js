// mehanik/assets/js/service.js
// Исправленная логика отзывов — мапинг рейтинга 1..10 -> 0.5..5.0 (DB), исправлен submit/reply/edit

/* Utility selectors */
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

/* ---- map UI rating (1..10) to DB rating (0.5..5.0), rounding to nearest 0.5 ---- */
function mapUiToDbRating(ui) {
  if (ui === null || ui === undefined || ui === '') return '';
  const n = Number(ui);
  if (Number.isNaN(n)) return '';
  // ui in 1..10 maps to db in 0.5..5.0 as ui/2, keep halves
  const raw = n / 2;
  // round to nearest 0.5: multiply by 2, round, divide by 2
  const half = Math.round(raw * 2) / 2;
  // ensure within DB range
  const clamped = Math.max(0.1, Math.min(5.0, half));
  // format with one decimal (decimal(2,1) expected)
  return clamped.toFixed(1);
}

/* Lightbox */
function openLightbox(src) {
  const lb = qs('#lb'); const img = qs('#lbImg');
  if (!lb || !img) return;
  img.src = src; lb.classList.add('active');
}
function closeLightbox(e) {
  if (!e || e.target.id === 'lb' || (e.target.classList && e.target.classList.contains('lb-close'))) {
    const lb = qs('#lb'); if (!lb) return; lb.classList.remove('active'); const img = qs('#lbImg'); if (img) img.src = '';
  }
}

/* Reply / Edit global helpers (used by inline onclick in HTML) */
window.startEdit = function (id) {
  try {
    const card = document.getElementById('review-' + id);
    if (!card) return;
    const commentNode = card.querySelector('.review-comment');
    const nameNode = card.querySelector('.review-name');
    const ratingNode = card.querySelector('.review-rating-value'); // optional span with rating data

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

    // if the card has rating (e.g., <span class="review-rating-value" data-rating="3.5">3.5</span>)
    const hidden = qs('#review_rating_hidden');
    if (ratingNode && hidden) {
      const raw = ratingNode.getAttribute('data-rating') || ratingNode.innerText || '';
      // rating in DB already 0.5..5.0, keep as is
      if (isValidDbRating(raw)) hidden.value = Number(raw).toFixed(1);
    } else if (window.__USER_REVIEW__ && window.__USER_REVIEW__.rating && hidden) {
      // fallback to global userReview rating (if available and in DB scale)
      if (isValidDbRating(window.__USER_REVIEW__.rating)) hidden.value = Number(window.__USER_REVIEW__.rating).toFixed(1);
    }

    if (commentEl) { commentEl.scrollIntoView({ behavior: 'smooth', block: 'center' }); commentEl.focus(); }
  } catch (err) { console.error('startEdit error', err); }
};

window.startReply = function (id, userName) {
  try {
    const form = qs('#reviewForm');
    const commentEl = qs('#comment');
    const parentEl = qs('#parent_id');
    const editEl = qs('#editing_review_id');
    const title = qs('#formTitle');
    const replyInd = qs('#replyIndicator');
    const replyToText = qs('#replyToText');

    if (editEl) editEl.value = '';
    if (parentEl) parentEl.value = String(id);
    if (title) title.textContent = 'Ответить на отзыв';
    if (replyInd && replyToText) { replyToText.textContent = 'Ответ пользователю: ' + (userName || 'Гость'); replyInd.style.display = 'flex'; }
    if (form) form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    if (commentEl) { commentEl.placeholder = 'Ваш ответ...'; commentEl.focus(); }
  } catch (err) { console.error('startReply error', err); }
};

function cancelReply() {
  const parentEl = qs('#parent_id');
  const editEl = qs('#editing_review_id');
  const title = qs('#formTitle');
  const replyInd = qs('#replyIndicator');
  const commentEl = qs('#comment');
  if (parentEl) parentEl.value = '0';
  if (editEl) editEl.value = '';
  if (title) title.textContent = 'Оставить отзыв';
  if (replyInd) replyInd.style.display = 'none';
  if (commentEl) commentEl.placeholder = 'Поделитесь впечатлением...';
}
window.cancelReply = cancelReply;

function resetReviewForm() {
  const form = qs('#reviewForm');
  if (!form) return;
  form.reset();
  const editEl = qs('#editing_review_id'); const parent = qs('#parent_id'); const hidden = qs('#review_rating_hidden');
  if (editEl) editEl.value = '';
  if (parent) parent.value = '0';
  if (hidden) hidden.value = '';
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

/* Map init (safe) */
function initMap() {
  try {
    const mapEl = qs('#map'); if (!mapEl) return;
    const loc = window.SERVICE_LOCATION || { lat: 37.95, lng: 58.38, zoom: 13, name: '' };
    const center = { lat: Number(loc.lat) || 37.95, lng: Number(loc.lng) || 58.38 };
    const zoom = Number(loc.zoom) || 13;
    if (typeof google === 'undefined' || typeof google.maps === 'undefined') { mapEl.innerHTML = '<div style="padding:18px;color:#444">Карта недоступна.</div>'; return; }
    const map = new google.maps.Map(mapEl, { center, zoom, streetViewControl: false });
    if (loc && loc.lat && loc.lng) {
      const marker = new google.maps.Marker({ position: center, map: map });
      const infow = new google.maps.InfoWindow({ content: String(loc.name || '') });
      marker.addListener('click', function () { infow.open(map, marker); });
      infow.open(map, marker);
    }
  } catch (err) { console.warn('initMap error:', err); const mapEl = qs('#map'); if (mapEl) mapEl.innerHTML = '<div style="padding:18px;color:#444">Карта недоступна.</div>'; }
}
window.initMap = initMap;

/* -------- Main: picker & form handling -------- */
(function main() {
  window.__USER_REVIEW__ = window.__USER_REVIEW__ || null;

  // TEN-star picker visuals
  const picker = qs('#ten-star-picker');
  if (picker) {
    const labels = qsa('#ten-star-picker label[data-value]');
    labels.forEach(lbl => {
      const v = Number(lbl.getAttribute('data-value'));
      lbl.addEventListener('mouseenter', () => labels.forEach(l => l.classList.toggle('preview', Number(l.getAttribute('data-value')) <= v)));
      lbl.addEventListener('mouseleave', () => labels.forEach(l => l.classList.remove('preview')));
      lbl.addEventListener('click', () => {
        const radio = qs('#pick-' + v);
        if (radio) radio.checked = true;
        labels.forEach(l => l.classList.toggle('active', Number(l.getAttribute('data-value')) <= v));
      });
    });

    // preselect user's rating if present (note: this rating may be DB scale (0.5..5.0) or UI scale; handle both)
    if (window.__USER_REVIEW__ && window.__USER_REVIEW__.rating) {
      // if rating > 5 assume it's UI scale (unlikely), else try to map db->ui: db*2 -> ui
      const r = Number(window.__USER_REVIEW__.rating);
      let uiPick = null;
      if (!Number.isNaN(r)) {
        if (r >= 1 && r <= 10) uiPick = Math.round(r);
        else if (r > 0 && r <= 5) uiPick = Math.round(r * 2);
      }
      if (uiPick) {
        const inp = qs('#pick-' + uiPick);
        if (inp) inp.checked = true;
        labels.forEach(l => l.classList.toggle('active', Number(l.getAttribute('data-value')) <= uiPick));
        const applyBtn = qs('#ten-star-apply');
        if (applyBtn) applyBtn.textContent = 'Редактировать мой отзыв';
      }
    }

    // apply button: copy mapped rating into hidden field (DB scale) and focus form
    const applyBtn = qs('#ten-star-apply');
    if (applyBtn) {
      applyBtn.addEventListener('click', () => {
        const selected = picker.querySelector('input[name="first_rating"]:checked');
        if (!selected) { alert('Пожалуйста, выберите рейтинг (1–10).'); return; }
        const uiVal = selected.value;
        const hidden = qs('#review_rating_hidden');
        if (hidden) {
          const dbVal = mapUiToDbRating(uiVal);
          if (dbVal) hidden.value = dbVal; else hidden.value = '';
        }
        const form = qs('#reviewForm');
        if (!form) { alert('Форма для отзывов не найдена.'); return; }

        // if user already has a review, switch to edit mode and fill comment
        if (window.__USER_REVIEW__ && window.__USER_REVIEW__.id) {
          const editEl = qs('#editing_review_id'); if (editEl) editEl.value = String(window.__USER_REVIEW__.id);
          const commentEl = qs('#comment'); if (commentEl && window.__USER_REVIEW__.comment) commentEl.value = window.__USER_REVIEW__.comment;
          const titleEl = qs('#formTitle'); if (titleEl) titleEl.textContent = 'Редактировать отзыв';
          form.scrollIntoView({ behavior: 'smooth', block: 'center' });
          if (commentEl) commentEl.focus();
          alert('У вас уже есть отзыв — отредактируйте его и нажмите «Сохранить отзыв».');
        } else {
          const editEl = qs('#editing_review_id'); if (editEl) editEl.value = '';
          const parentEl = qs('#parent_id'); if (parentEl) parentEl.value = '0';
          form.scrollIntoView({ behavior: 'smooth', block: 'center' });
          const commentEl = qs('#comment'); if (commentEl) { commentEl.placeholder = 'Вы выбрали ' + uiVal + ' звезд(ы). Напишите комментарий и нажмите «Сохранить отзыв». '; commentEl.focus(); }
        }
      });
    }
  } // end picker

  // send service rating button (left as-is)
  const sendBtn = qs('#ten-star-send-rating');
  if (sendBtn) {
    sendBtn.addEventListener('click', async () => {
      try {
        const pickerEl = qs('#ten-star-picker');
        if (!pickerEl) { alert('Пикер рейтинга не найден'); return; }
        const sel = pickerEl.querySelector('input[name="first_rating"]:checked');
        if (!sel) { alert('Пожалуйста, выберите рейтинг (1–10).'); return; }
        const value = sel.value;
        if (!isValidUiRating(value)) { alert('Неверный рейтинг'); return; }
        const params = new URLSearchParams();
        params.set('action', 'submit_service_rating');
        params.set('rating', String(value)); // service_ratings expects 1..10
        const resp = await fetch(window.location.pathname + window.location.search, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
          body: params.toString(),
          credentials: 'same-origin'
        });
        const json = await resp.json();
        if (!json || !json.ok) { alert((json && json.error) ? json.error : 'Ошибка при сохранении рейтинга'); return; }
        const avgStars = qs('#avgStars'), avgNum = qs('#avgNum'), avgMeta = qs('#avgMeta');
        if (avgStars && typeof json.avg !== 'undefined') avgStars.style.setProperty('--percent', ((Number(json.avg) / 10) * 100) + '%');
        if (avgNum && typeof json.avg !== 'undefined') avgNum.textContent = Number(json.avg).toFixed(1);
        if (avgMeta && typeof json.count !== 'undefined') avgMeta.textContent = (json.count || 0) + ' отзывов';
        alert('Спасибо! Ваш рейтинг сохранён.');
      } catch (err) { console.error(err); alert('Ошибка при отправке рейтинга.'); }
    });
  }

  // Form submit: upsert_review (AJAX) — now maps rating to DB scale and only sends valid value
  const reviewForm = qs('#reviewForm');
  if (reviewForm) {
    reviewForm.addEventListener('submit', async function (ev) {
      ev.preventDefault();
      const submitBtn = reviewForm.querySelector('button[type="submit"]');
      const origText = submitBtn ? submitBtn.textContent : null;
      try {
        if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Отправка...'; submitBtn.style.opacity = '0.6'; }

        const comment = (qs('#comment') && qs('#comment').value || '').trim();
        if (!comment) { alert('Комментарий не может быть пустым.'); return; }

        const editingId = qs('#editing_review_id') ? qs('#editing_review_id').value : '';
        const parentId = qs('#parent_id') ? qs('#parent_id').value : '';
        const userName = qs('#user_name') ? qs('#user_name').value.trim() : '';

        // rating: first try hidden input (should already be DB-scale if filled via apply), otherwise map from picker
        let ratingDb = '';
        const hidden = qs('#review_rating_hidden');
        if (hidden && hidden.value) {
          // hidden might already contain DB value (0.5..5.0) or UI value if older code placed it — accept both by normalizing:
          if (isValidDbRating(hidden.value)) ratingDb = Number(hidden.value).toFixed(1);
          else if (isValidUiRating(hidden.value)) ratingDb = mapUiToDbRating(hidden.value);
        }
        if (!ratingDb) {
          const pickerSel = qs('#ten-star-picker input[name="first_rating"]:checked');
          if (pickerSel && pickerSel.value && isValidUiRating(pickerSel.value)) ratingDb = mapUiToDbRating(pickerSel.value);
        }
        // build payload
        const params = new URLSearchParams();
        params.set('action', 'upsert_review');
        params.set('comment', comment);
        if (editingId) params.set('editing_review_id', String(editingId));
        if (parentId && parentId !== '0') params.set('parent_id', String(parentId));
        if (userName) params.set('user_name', userName);
        if (ratingDb) params.set('review_rating', ratingDb); // only include valid DB rating

        const resp = await fetch(window.location.pathname + window.location.search, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
          body: params.toString(),
          credentials: 'same-origin'
        });

        console.log('upsert_review HTTP', resp.status, resp.statusText);
        const json = await resp.json().catch(e => { console.error('JSON parse failed', e); throw new Error('Сервер вернул некорректный ответ'); });
        if (!json || !json.ok) { console.warn('Server error on upsert_review', json); alert(json.error || 'Ошибка при сохранении отзыва.'); return; }
        // success
        location.reload();
      } catch (err) {
        console.error('Review submit error:', err);
        alert(err.message || 'Ошибка при отправке отзыва. Проверьте консоль.');
      } finally {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.style.opacity = ''; if (origText) submitBtn.textContent = origText; }
      }
    });
  }

  // Delegated handlers for reply/edit buttons (works even without inline onclick)
  document.addEventListener('click', function (e) {
    const t = e.target;
    if (!t) return;
    // Reply: look for text starting with 'Ответ' or class btn-reply
    if (t.matches('.btn-reply') || (t.tagName === 'BUTTON' && t.innerText && t.innerText.trim().toLowerCase().startsWith('ответ'))) {
      const rv = t.closest('[id^="review-"]');
      if (rv) {
        const id = rv.id.replace(/^review-/, '');
        const nm = rv.querySelector('.review-name');
        startReply(Number(id), nm ? nm.innerText.trim() : '');
      }
    }
    // Edit
    if (t.matches('.btn-edit') || (t.tagName === 'BUTTON' && t.innerText && t.innerText.trim().toLowerCase().startsWith('измен'))) {
      const rv = t.closest('[id^="review-"]');
      if (rv) {
        const id = rv.id.replace(/^review-/, '');
        startEdit(Number(id));
      }
    }
  });

})(); // main IIFE end
