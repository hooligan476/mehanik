// mehanik/assets/js/admin-lookups.js
// Управление вкладками + автоматическое сворачивание длинных списков в админке справочников

(function () {
  'use strict';

  // Настройки для сворачивания списков
  const MIN_TO_COLLAPSE = 12; // если элементов >= этого — список сворачивается
  const VISIBLE_COUNT = 8;    // сколько элементов видно по умолчанию

  // ---- утилиты ----
  function addStyles(css) {
    const style = document.createElement('style');
    style.setAttribute('data-generated', 'admin-lookups-collapse');
    style.appendChild(document.createTextNode(css));
    document.head.appendChild(style);
  }

  // Стили, чтобы скрытые элементы и кнопки выглядели аккуратно
  addStyles(`
    .collapsed-item { display: none !important; }
    .collapse-toggle {
      display: inline-block;
      margin: 8px 0 0;
      padding: 8px 10px;
      border-radius: 8px;
      border: 0;
      cursor: pointer;
      background: #0ea5e9;
      color: #fff;
      font-weight: 600;
    }
    .collapse-toggle.secondary { background: #6b7280; }
    /* чуть сузим стиль для маленьких списков внутри колонок */
    .admin-tools ul { margin: 0; padding-left: 0.6rem; }
  `);

  // ---- табы (как раньше, + ARIA) ----
  function initTabs() {
    const buttons = Array.from(document.querySelectorAll('.tab-btn'));
    const panels = {
      brands: document.getElementById('panel-brands'),
      parts:  document.getElementById('panel-parts'),
      types:  document.getElementById('panel-types'),
      extras: document.getElementById('panel-extras')
    };

    function activate(tab) {
      if (!tab || !panels[tab]) tab = 'brands';

      buttons.forEach(b => {
        const isActive = b.dataset.tab === tab;
        b.classList.toggle('active', isActive);
        b.setAttribute('aria-selected', isActive ? 'true' : 'false');
        b.setAttribute('tabindex', isActive ? '0' : '-1');
      });

      Object.keys(panels).forEach(k => {
        const p = panels[k];
        if (!p) return;
        p.classList.toggle('active', k === tab);
        p.setAttribute('aria-hidden', k === tab ? 'false' : 'true');
      });

      try { history.replaceState(null, '', '#' + tab); } catch (e) { location.hash = '#' + tab; }
    }

    buttons.forEach((btn, i) => {
      btn.addEventListener('click', function (ev) { ev.preventDefault(); activate(btn.dataset.tab || 'brands'); });
      btn.addEventListener('keydown', function (ev) {
        if (ev.key === 'ArrowRight' || ev.key === 'ArrowLeft') {
          ev.preventDefault();
          const next = ev.key === 'ArrowRight' ? (i + 1) : (i - 1);
          const wrapped = (next + buttons.length) % buttons.length;
          buttons[wrapped].focus();
        }
      });
    });

    const preferred = (location.hash || '#brands').replace(/^#/, '');
    activate(preferred in panels ? preferred : 'brands');

    window.addEventListener('hashchange', function () {
      const h = (location.hash || '#brands').replace(/^#/, '');
      if (h in panels) activate(h);
    });
  }

  // ---- авто-сворачивание длинных списков ----
  function initCollapsibleLists() {
    const container = document.querySelector('.admin-tools');
    if (!container) return;

    // Берём все UL внутри .admin-tools — фильтруем служебные (напр. навигация),
    // считаем только прямые <li> элементы (не учитываем вложенные UL/LI).
    const allULs = Array.from(container.querySelectorAll('ul'));

    allULs.forEach(ul => {
      // Игнорируем списки, которые выглядят как маленькие служебные списки:
      // если UL содержит форму или нет прямых LI — не трогаем.
      const directItems = Array.from(ul.children).filter(ch => ch.tagName === 'LI');
      if (directItems.length === 0) return;

      // Не сворачиваем списки, в которых есть управляющие кнопки (например, экспорт и т.п.)
      // (но это только мягкая фильтрация)
      if (ul.closest('#panel-types') && ul.dataset.noCollapse === '1') return;

      if (directItems.length < MIN_TO_COLLAPSE) return;

      // Скрываем все элементы после VISIBLE_COUNT
      const hiddenItems = directItems.slice(VISIBLE_COUNT);
      hiddenItems.forEach(li => {
        li.classList.add('collapsed-item');
        li.setAttribute('aria-hidden', 'true');
      });

      // Создаём кнопку переключения
      const moreCount = hiddenItems.length;
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'collapse-toggle';
      btn.setAttribute('aria-expanded', 'false');
      btn.textContent = `Показать ещё ${moreCount}`;

      // Если UL находится внутри правой колонки подсказок — сделаем более нейтральный стиль
      if (ul.closest('#panel-types') || ul.closest('#panel-extras')) {
        // оставляем основной стиль; но если хотите — можно добавить secondary
      }

      // По клику раскрываем/сворачиваем
      btn.addEventListener('click', function () {
        const expanded = btn.getAttribute('aria-expanded') === 'true';
        if (expanded) {
          // свернуть
          hiddenItems.forEach(li => {
            li.classList.add('collapsed-item');
            li.setAttribute('aria-hidden', 'true');
          });
          btn.setAttribute('aria-expanded', 'false');
          btn.textContent = `Показать ещё ${moreCount}`;
          // прокрутка не трогаем
        } else {
          // показать все
          hiddenItems.forEach(li => {
            li.classList.remove('collapsed-item');
            li.setAttribute('aria-hidden', 'false');
          });
          btn.setAttribute('aria-expanded', 'true');
          btn.textContent = `Свернуть`;
        }
      });

      // Вставляем кнопку после списка. Если список уже имеет соседнюю кнопку — пропускаем.
      // (чтоб не дублировать при повторной инициализации)
      if (!ul.nextElementSibling || !ul.nextElementSibling.classList.contains('collapse-toggle')) {
        ul.parentNode.insertBefore(btn, ul.nextSibling);
      }
    });
  }

  // ---- init on DOM ready ----
  document.addEventListener('DOMContentLoaded', function () {
    try {
      initTabs();
      initCollapsibleLists();
    } catch (e) {
      // безопасный fallback: ничего не ломаем в админке
      // (ошибки можно логировать в консоль при отладке)
      // console.error('admin-lookups init error', e);
    }
  });

})();
