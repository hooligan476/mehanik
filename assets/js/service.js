/**
 * public/assets/js/service.js
 * Весь JavaScript, вынесённый из public/service.php.
 *
 * Примечания:
 * - Сторонние серверные значения (например, userReview или координаты карты)
 *   должны быть установлены на странице **до** подключения этого файла, например:
 *     <script>window.__USER_REVIEW__ = <?= json_encode($userReview ?? null) ?>;</script>
 *     <script>window.SERVICE_LOCATION = { lat: 37.95, lng: 58.38, zoom: 13, name: '...' };</script>
 *   Если они отсутствуют — в коде предусмотрены разумные значения по умолчанию.
 *
 * - Поместите этот файл по пути: mehanik/assets/js/service.js
 * - Подключите в service.php:
 *     <script>window.__USER_REVIEW__ = <?= json_encode($userReview ?? null, JSON_UNESCAPED_UNICODE) ?>;</script>
 *     <script>window.SERVICE_LOCATION = { lat: <?= $lat ?>, lng: <?= $lng ?>, zoom: <?= $zoom ?>, name: <?= json_encode($service['name'] ?? '') ?> };</script>
 *     <script src="/mehanik/assets/js/service.js"></script>
 */

/* ---------------- Lightbox и утилиты ---------------- */
function openLightbox(src) {
  const lb = document.getElementById('lb');
  const img = document.getElementById('lbImg');
  if (!lb || !img) return;
  img.src = src;
  lb.classList.add('active');
}
function closeLightbox(e) {
  // Закрываем если кликнули по оверлею или по кресту
  if (!e || e.target.id === 'lb' || (e.target.classList && e.target.classList.contains('lb-close'))) {
    const lb = document.getElementById('lb');
    if (!lb) return;
    lb.classList.remove('active');
    const img = document.getElementById('lbImg');
    if (img) img.src = '';
  }
}

/* ---------------- Редактирование / Ответ / Форма ---------------- */
function startEdit(id) {
  try {
    const card = document.getElementById('review-' + id);
    if (!card) return;
    const commentNode = card.querySelector('.review-comment');
    const nameNode = card.querySelector('.review-name');
    const commentText = commentNode ? commentNode.innerText.trim() : '';
    const userNameText = nameNode ? nameNode.innerText.trim() : '';
    const form = document.getElementById('reviewForm');
    const commentEl = document.getElementById('comment');
    const nameEl = document.getElementById('user_name');
    const editIdEl = document.getElementById('editing_review_id');
    const parentIdEl = document.getElementById('parent_id');
    const formTitle = document.getElementById('formTitle');
    const replyInd = document.getElementById('replyIndicator');
    if (commentEl) commentEl.value = commentText;
    if (nameEl && userNameText) nameEl.value = userNameText;
    if (editIdEl) editIdEl.value = id;
    if (parentIdEl) parentIdEl.value = 0;
    if (formTitle) formTitle.textContent = 'Редактировать отзыв';
    if (replyInd) replyInd.style.display = 'none';
    if (form) form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    if (commentEl) commentEl.focus();
  } catch (e) { console.error('startEdit error', e); }
}

function startReply(id, userName) {
  try {
    const form = document.getElementById('reviewForm');
    const commentEl = document.getElementById('comment');
    const parentIdEl = document.getElementById('parent_id');
    const editIdEl = document.getElementById('editing_review_id');
    const formTitle = document.getElementById('formTitle');
    const replyInd = document.getElementById('replyIndicator');
    const replyToText = document.getElementById('replyToText');
    if (editIdEl) editIdEl.value = '';
    if (parentIdEl) parentIdEl.value = id;
    if (formTitle) formTitle.textContent = 'Ответить на отзыв';
    if (replyInd && replyToText) { replyToText.textContent = 'Ответ пользователю: ' + (userName || 'Гость'); replyInd.style.display = 'flex'; }
    if (form) form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    if (commentEl) { commentEl.placeholder = 'Ваш ответ...'; commentEl.focus(); }
  } catch (e) { console.error('startReply error', e); }
}

function cancelReply() {
  const parentIdEl = document.getElementById('parent_id');
  const editIdEl = document.getElementById('editing_review_id');
  const formTitle = document.getElementById('formTitle');
  const replyInd = document.getElementById('replyIndicator');
  const commentEl = document.getElementById('comment');
  if (parentIdEl) parentIdEl.value = 0;
  if (editIdEl) editIdEl.value = '';
  if (formTitle) formTitle.textContent = 'Оставить отзыв';
  if (replyInd) replyInd.style.display = 'none';
  if (commentEl) commentEl.placeholder = 'Поделитесь впечатлением...';
}

function resetReviewForm() {
  const f = document.getElementById('reviewForm');
  if (!f) return;
  f.reset();
  const editIdEl = document.getElementById('editing_review_id');
  const parentIdEl = document.getElementById('parent_id');
  const formTitle = document.getElementById('formTitle');
  const replyInd = document.getElementById('replyIndicator');
  const reviewRatingHidden = document.getElementById('review_rating_hidden');
  if (editIdEl) editIdEl.value = '';
  if (parentIdEl) parentIdEl.value = 0;
  if (formTitle) formTitle.textContent = 'Оставить отзыв';
  if (replyInd) replyInd.style.display = 'none';
  if (reviewRatingHidden) reviewRatingHidden.value = '';
}

/* ---------------- Рейтинг сотрудника (AJAX) ---------------- */
async function sendStaffRating(staffId, rating) {
  try {
    const fd = new FormData();
    fd.append('action', 'submit_staff_rating');
    fd.append('staff_id', staffId);
    fd.append('rating', rating);

    const resp = await fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd
    });
    const json = await resp.json();
    if (!json || !json.ok) {
      alert((json && json.error) ? json.error : 'Ошибка при отправке рейтинга сотрудника');
      return;
    }
    alert('Спасибо! Рейтинг сотрудника сохранён.');
    // Перезагрузим страницу, чтобы актуализировать данные (простая надёжная стратегия)
    location.reload();
  } catch (err) {
    console.error(err);
    alert('Ошибка отправки рейтинга сотрудника.');
  }
}

/* ---------------- Map init (использует window.SERVICE_LOCATION при наличии) ----------------
   Рекомендуется из service.php перед подключением этого файла пробросить:
     <script>
       window.SERVICE_LOCATION = { lat: <?= (float)$service['latitude'] ?>, lng: <?= (float)$service['longitude'] ?>, zoom: 15, name: <?= json_encode($service['name']) ?> };
     </script>
*/
function initMap() {
  try {
    var mapEl = document.getElementById('map');
    if (!mapEl) return;

    // Параметры: берём из глобальной переменной, если задана, иначе дефолт
    var loc = window.SERVICE_LOCATION || { lat: 37.95, lng: 58.38, zoom: 13, name: '' };
    var center = { lat: parseFloat(loc.lat) || 37.95, lng: parseFloat(loc.lng) || 58.38 };
    var zoom = parseInt(loc.zoom || loc.z || 13, 10);

    if (typeof google === 'undefined' || typeof google.maps === 'undefined') {
      // Если библиотека не загружена — покажем сообщение
      mapEl.innerHTML = '<div style="padding:18px;color:#444">Карта недоступна.</div>';
      console.warn('Google Maps не загружен');
      return;
    }

    var map = new google.maps.Map(mapEl, {
      center: center,
      zoom: zoom,
      streetViewControl: false
    });

    if (loc && loc.lat && loc.lng) {
      var marker = new google.maps.Marker({ position: center, map: map });
      var infow = new google.maps.InfoWindow({ content: String(loc.name || '') });
      marker.addListener('click', function () { infow.open(map, marker); });
      infow.open(map, marker);
    }
  } catch (err) {
    console.warn('initMap error:', err);
    var mapEl = document.getElementById('map');
    if (mapEl) mapEl.innerHTML = '<div style="padding:18px;color:#444">Карта недоступна.</div>';
  }
}

// Если скрипт подключён раньше чем Google Maps, безопасно — callback будет вызван при загрузке API.
// В service.php подключите Google Maps с callback=initMap, как было раньше.

/* ---------------- Главная логика: picker, отправка рейтинга, отправка отзыва через AJAX ---------------- */
(function () {
  // fallback: если сервер не установил window.__USER_REVIEW__, сделаем null
  window.__USER_REVIEW__ = window.__USER_REVIEW__ || null;

  // 10-star picker behaviour
  const picker = document.getElementById('ten-star-picker');
  if (picker) {
    const labels = Array.from(picker.querySelectorAll('label[data-value]'));
    const applyBtn = document.getElementById('ten-star-apply');

    labels.forEach(lbl => {
      const v = parseInt(lbl.getAttribute('data-value'), 10);

      lbl.addEventListener('mouseenter', () => {
        labels.forEach(l => {
          const lv = parseInt(l.getAttribute('data-value'), 10);
          l.classList.toggle('preview', lv <= v);
        });
      });

      lbl.addEventListener('mouseleave', () => { labels.forEach(l => l.classList.remove('preview')); });

      lbl.addEventListener('click', () => {
        const value = v;
        const radio = document.getElementById('pick-' + value);
        if (radio) radio.checked = true;
        labels.forEach(l => {
          const lv = parseInt(l.getAttribute('data-value'), 10);
          l.classList.toggle('active', lv <= value);
        });
      });
    });

    // Если у пользователя уже есть отзыв — подсветим рейтинг
    if (window.__USER_REVIEW__ && window.__USER_REVIEW__.rating) {
      const r = Math.round(window.__USER_REVIEW__.rating);
      const radio = document.getElementById('pick-' + r);
      if (radio) radio.checked = true;
      labels.forEach(l => {
        const lv = parseInt(l.getAttribute('data-value'), 10);
        l.classList.toggle('active', lv <= r);
      });
      if (applyBtn) applyBtn.textContent = 'Редактировать мой отзыв';
    }

    // applyBtn behavior: копируем рейтинг в скрытое поле формы и прокручиваем к форме
    if (applyBtn) {
      applyBtn.addEventListener('click', () => {
        const selected = picker.querySelector('input[name="first_rating"]:checked');
        if (!selected) { alert('Пожалуйста, выберите рейтинг (1–10).'); return; }
        const val = selected.value;
        const form = document.getElementById('reviewForm');
        if (!form) { alert('Форма добавления отзыва не найдена.'); return; }
        const hidden = document.getElementById('review_rating_hidden');
        if (hidden) hidden.value = val;

        if (window.__USER_REVIEW__ && window.__USER_REVIEW__.id) {
          const editIdEl = form.querySelector('#editing_review_id');
          if (editIdEl) editIdEl.value = window.__USER_REVIEW__.id;
          const commentEl = form.querySelector('#comment');
          if (commentEl && window.__USER_REVIEW__.comment) commentEl.value = window.__USER_REVIEW__.comment;
          const titleEl = document.getElementById('formTitle');
          if (titleEl) titleEl.textContent = 'Редактировать отзыв';
          form.scrollIntoView({ behavior: 'smooth', block: 'center' });
          if (commentEl) commentEl.focus();
          alert('У вас уже есть отзыв — отредактируйте его и нажмите «Сохранить отзыв».');
        } else {
          const editIdEl = form.querySelector('#editing_review_id');
          const parentIdEl = form.querySelector('#parent_id');
          if (editIdEl) editIdEl.value = '';
          if (parentIdEl) parentIdEl.value = '0';
          const commentEl = form.querySelector('#comment');
          form.scrollIntoView({ behavior: 'smooth', block: 'center' });
          if (commentEl) { commentEl.focus(); commentEl.placeholder = 'Вы выбрали ' + val + ' звезд(ы). Напишите комментарий и нажмите «Сохранить отзыв».'; }
        }
      });
    }
  }

  // Send only service rating via AJAX
  const sendBtn = document.getElementById('ten-star-send-rating');
  if (sendBtn) {
    sendBtn.addEventListener('click', async function () {
      try {
        const pickerEl = document.getElementById('ten-star-picker');
        if (!pickerEl) { alert('Пикер рейтинга не найден'); return; }
        const sel = pickerEl.querySelector('input[name="first_rating"]:checked');
        if (!sel) { alert('Пожалуйста, выберите рейтинг (1–10).'); return; }
        const value = sel.value;
        const fd = new FormData();
        fd.append('action', 'submit_service_rating');
        fd.append('rating', value);

        const resp = await fetch(window.location.pathname + window.location.search, {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: fd
        });

        const json = await resp.json();
        if (!json) { alert('Ошибка сервера'); return; }
        if (!json.ok) { alert(json.error || 'Ошибка при сохранении рейтинга'); return; }

        // Обновим UI среднего рейтинга
        const avgStars = document.getElementById('avgStars');
        const avgNum = document.getElementById('avgNum');
        const avgMeta = document.getElementById('avgMeta');
        if (avgStars) avgStars.style.setProperty('--percent', ((json.avg / 10) * 100) + '%');
        if (avgNum) avgNum.textContent = parseFloat(json.avg).toFixed(1);
        if (avgMeta) avgMeta.textContent = (json.count || 0) + ' отзывов';

        alert('Спасибо! Ваш рейтинг сохранён.');
      } catch (err) {
        console.error(err);
        alert('Ошибка при отправке рейтинга.');
      }
    });
  }

  // ---- Улучшенный обработчик отправки формы отзыва (заменяет старый) ----
(function() {
  const reviewForm = document.getElementById('reviewForm');
  if (!reviewForm) return;

  reviewForm.addEventListener('submit', async function (e) {
    e.preventDefault();

    const submitBtn = reviewForm.querySelector('button[type="submit"]');
    const originalBtnText = submitBtn ? submitBtn.textContent : null;
    try {
      // Disable button to avoid double submit
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.style.opacity = '0.6';
        submitBtn.textContent = 'Отправка...';
      }

      const fd = new FormData(reviewForm);

      // ensure hidden rating is included if present
      const hidden = document.getElementById('review_rating_hidden');
      if (hidden && hidden.value) fd.set('review_rating', hidden.value);

      const resp = await fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd,
        credentials: 'same-origin'
      });

      // log status & headers for debugging
      console.log('Review submit: HTTP', resp.status, resp.statusText);

      const text = await resp.text();
      // Try parse JSON but if parse fails, show raw text in console and alert
      let json = null;
      try {
        json = text ? JSON.parse(text) : null;
      } catch (parseErr) {
        console.warn('Не удалось распарсить JSON от сервера при отправке отзыва:', parseErr);
        console.log('Ответ сервера (raw):', text);
        alert('Сервер вернул неожидимый ответ. Посмотрите консоль (Network / Console) для деталей.');
        return;
      }

      if (!json) {
        console.warn('Пустой/невалидный JSON:', text);
        alert('Ошибка сервера при сохранении отзыва. Смотрите консоль для деталей.');
        return;
      }

      if (!json.ok) {
        // вывoдим сообщение ошибки от сервера (если есть) и логируем полные данные
        console.warn('Сервер вернул ошибку при отправке отзыва:', json);
        alert(json.error || 'Ошибка при сохранении отзыва: сервер вернул ошибку. Проверьте консоль.');
        return;
      }

      // OK: обновляем интерфейс
      // Если сервер вернул action 'inserted'/'updated', просто перезагрузим или локально вставим
      console.log('Отзыв успешно отправлен, ответ сервера:', json);
      // В большинстве случаев проще обновить страницу, чтобы отрисовать дерево отзывов корректно
      location.reload();

    } catch (err) {
      console.error('Ошибка при отправке отзыва (fetch):', err);
      alert('Ошибка при отправке отзыва. Откройте консоль и вкладку Network, чтобы увидеть ответ сервера.');
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.style.opacity = '';
        if (originalBtnText) submitBtn.textContent = originalBtnText;
      }
    }
  });
})();


})(); // end IIFE
