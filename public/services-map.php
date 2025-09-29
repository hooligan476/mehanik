<?php
// mehanik/public/services-map.php
session_start();
require_once __DIR__ . '/../middleware.php';
require_once __DIR__ . '/../db.php';

// helper to produce public URL (копия)
function toPublicUrl($rel) {
    if (!$rel) return '';
    if (preg_match('#^https?://#i',$rel)) return $rel;
    if (strpos($rel, '/') === 0) return $rel;
    return '/mehanik/' . ltrim($rel, '/');
}

$user = $_SESSION['user'] ?? null;
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Сервисы на карте — Mehanik (Google Maps)</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/mehanik/assets/css/header.css">
  <link rel="stylesheet" href="/mehanik/assets/css/style.css">

  <style>
    body{font-family:Inter,system-ui,Arial;background:#f6f8fb;color:#222}
    .container{max-width:1200px;margin:18px auto;padding:0 16px}
    #map { width:100%; height:74vh; border-radius:10px; border:1px solid #e6eef7; box-shadow:0 8px 24px rgba(12,20,30,.04); }
    .topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
    .controls { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; align-items:center }
    .controls input, .controls select { padding:8px; border-radius:8px; border:1px solid #e6eef7; min-width:120px }
    .btn{background:#0b57a4;color:#fff;padding:8px 12px;border-radius:8px;text-decoration:none;font-weight:700}
    .btn-ghost{background:transparent;color:#0b57a4;border:1px solid #dbeeff;padding:8px 12px;border-radius:8px}
    .legend { background: #fff; padding:8px; border-radius:8px; box-shadow:0 6px 18px rgba(0,0,0,.06); font-size:.9rem; }
    /* Header-map style */
.map-topbar {
  display: flex;
  gap: 16px;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 14px;
  flex-wrap: wrap;
}

.map-topbar .title-wrap {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}

.map-topbar h1 {
  margin: 0;
  color: #0b57a4;
  font-size: 1.35rem;
  font-weight: 800;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.map-topbar .subtitle {
  color: #6b7280;
  font-size: .95rem;
}

/* Right actions group */
.map-topbar .actions {
  display: flex;
  gap: 8px;
  align-items: center;
}

/* User badge */
.user-badge {
  display: inline-flex;
  gap: 8px;
  align-items: center;
  background: #fff;
  border: 1px solid #e6eef7;
  padding: 6px 10px;
  border-radius: 999px;
  font-weight: 700;
  color: #0b57a4;
  box-shadow: 0 6px 18px rgba(11,87,164,0.04);
}

/* small icon inside badge */
.user-badge .avatar {
  width: 28px;
  height: 28px;
  border-radius: 999px;
  background: linear-gradient(180deg,#cfe7ff,#dbeeff);
  display: inline-flex;
  align-items:center;
  justify-content:center;
  font-weight:800;
  color:#063a66;
}

/* Mobile behavior: stack */
@media (max-width:760px) {
  .map-topbar { gap:10px; }
  .map-topbar h1 { font-size:1.12rem; }
  .map-topbar .actions { width:100%; justify-content: space-between; }
  .map-topbar .actions .btn { flex: 0 0 auto; }
}
    @media(max-width:760px){ #map{ height:62vh; } }
  </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<div class="container">
  <div class="container">
  <div class="map-topbar">
    <div class="title-wrap">
      <h1 title="Сервисы на карте — Туркменистан">Сервисы на карте — Туркменистан</h1>
      <div class="subtitle">Кликните на маркер, чтобы открыть карточку сервиса</div>
    </div>

    <div class="actions">
      <a href="services.php" class="btn btn-ghost" title="Вернуться к списку">← Список</a>

      <?php if (!empty($user)): ?>
        <!-- Показываем имя пользователя и кнопку добавить -->
        <div class="user-badge" title="<?= htmlspecialchars($user['email'] ?? $user['name'] ?? '') ?>">
          <span class="avatar">
            <?= htmlspecialchars(mb_substr($user['name'] ?? $user['email'] ?? 'U',0,1)) ?>
          </span>
          <span style="font-weight:700; margin-right:8px; font-size:.95rem;"><?= htmlspecialchars($user['name'] ?? $user['email'] ?? 'Пользователь') ?></span>
        </div>
        <a href="add-service.php" class="btn" title="Добавить сервис">+ Добавить</a>
      <?php else: ?>
        <a href="login.php" class="btn" title="Войти">Войти</a>
      <?php endif; ?>
    </div>
  </div>
</div>


  <div class="controls">
    <input id="filter-name" placeholder="По имени (частично)" />
    <select id="filter-city">
      <option value="">Все города/районы</option>
    </select>
    <select id="filter-rating">
      <option value="0">Рейтинг: Любой</option>
      <option value="1">≥ 1.0</option>
      <option value="2">≥ 2.0</option>
      <option value="3">≥ 3.0</option>
      <option value="4">≥ 4.0</option>
      <option value="4.5">≥ 4.5</option>
    </select>
    <button id="filter-clear" class="btn btn-ghost">Сбросить</button>
    <div style="flex:1"></div>
    <div class="legend">Кластеры показывают количество сервисов и средний рейтинг внутри кластера.</div>
  </div>

  <div id="map"></div>
</div>

<!-- Google Maps JS: замените ключ -->
<script src="https://maps.googleapis.com/maps/api/js?key=YOUR_GOOGLE_MAPS_API_KEY&libraries=places"></script>

<!-- MarkerClusterer (официальная библиотека) -->
<script src="https://developers.google.com/maps/documentation/javascript/examples/markerclusterer/markerclusterer.js"></script>

<script>
(function(){
  // Helpers
  function escapeHtml(s){
    if(!s && s !== 0) return '';
    return String(s)
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;')
      .replace(/'/g,'&#039;');
  }

  const defaultCenter = { lat: 39.0, lng: 58.0 };
  const defaultZoom = 6;

  const map = new google.maps.Map(document.getElementById('map'), {
    center: defaultCenter,
    zoom: defaultZoom,
    mapTypeControl: false,
    streetViewControl: false,
  });

  let allMarkers = []; // { marker, data }
  let clusterer = null;
  let infoWindow = new google.maps.InfoWindow();

  // Fetch data from API
  fetch('/mehanik/api/services-map-data.php')
    .then(r => r.json())
    .then(json => {
      if(!json || !json.ok){
        console.error('API error', json);
        return;
      }
      const arr = json.services || [];

      // Build unique cities list (city from API)
      const citySet = new Set();
      arr.forEach(s => {
        if (s.city && s.city.trim() !== '') citySet.add(s.city.trim());
      });
      const citySelect = document.getElementById('filter-city');
      Array.from(citySet).sort().forEach(city => {
        const opt = document.createElement('option');
        opt.value = city;
        opt.textContent = city;
        citySelect.appendChild(opt);
      });

      // Create markers
      arr.forEach(s => {
        const lat = parseFloat(s.latitude);
        const lng = parseFloat(s.longitude);
        if (!isFinite(lat) || !isFinite(lng)) return;

        const position = { lat: lat, lng: lng };

        // marker icon — используем logo если есть, иначе стандартная точка
        let icon = null;
        if (s.logo) {
          // Создаём иконку с фиксированным размером (маркер-мини)
          icon = {
            url: s.logo,
            scaledSize: new google.maps.Size(48, 48), // уменьшить/увеличить по желанию
          };
        } else {
          // простой круглый маркер (SVG data URL)
          const svg = encodeURIComponent(
            '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48">' +
              '<circle cx="24" cy="24" r="10" fill="#0b57a4" />' +
            '</svg>'
          );
          icon = {
            url: 'data:image/svg+xml;charset=UTF-8,' + svg,
            scaledSize: new google.maps.Size(48,48)
          };
        }

        const marker = new google.maps.Marker({
          position,
          title: s.name || '',
          icon: icon,
        });

        const popupHtml = (function(){
          let html = '<div style="min-width:220px">';
          html += '<strong><a href="/mehanik/public/service.php?id=' + encodeURIComponent(s.id) + '" target="_blank">' + escapeHtml(s.name) + '</a></strong>';
          if (s.address) html += '<div style="margin-top:6px;">' + escapeHtml(s.address) + '</div>';
          if (s.phone) html += '<div style="margin-top:6px;">📞 ' + escapeHtml(s.phone) + '</div>';
          if (s.email) html += '<div style="margin-top:6px;">✉️ ' + escapeHtml(s.email) + '</div>';
          if (s.avg_rating !== undefined) html += '<div style="margin-top:6px;font-weight:700">Рейтинг: ' + (parseFloat(s.avg_rating).toFixed(1)) + '</div>';
          if (s.logo) html += '<div style="margin-top:8px;"><img src="' + escapeHtml(s.logo) + '" style="width:100%;height:auto;border-radius:6px;border:1px solid #eee"></div>';
          html += '</div>';
          return html;
        })();

        marker.addListener('click', function(){
          infoWindow.setContent(popupHtml);
          infoWindow.open(map, marker);
        });

        allMarkers.push({ marker: marker, data: s });
      });

      // Create clusterer with custom renderer that shows count + avg rating of cluster
      // Using MarkerClusterer from Google example (markerclusterer.js)
      // We'll implement renderer that returns HTML element for cluster icon.
      // NOTE: different markerclusterer versions have different APIs; the one used here
      // expects an array of Marker objects and accepts 'renderer' with render function.
      // If your markerclusterer version differs, adapt accordingly.

      // Extract marker objects
      const markerObjs = allMarkers.map(x => x.marker);

      // Custom renderer that builds cluster icon element
      const renderer = {
        render: ({ count, markers, position }) => {
          // compute average rating in cluster
          let sum = 0, n = 0;
          markers.forEach(m => {
            const found = allMarkers.find(x => x.marker === m);
            if (found && found.data && found.data.avg_rating) {
              const val = parseFloat(found.data.avg_rating) || 0;
              sum += val;
              n++;
            }
          });
          const avg = n ? (sum / n) : 0;

          // Build HTML element
          const div = document.createElement('div');
          div.className = 'custom-cluster';
          // Inline styles to make it readable; you can move to CSS
          div.style.background = 'rgba(255,255,255,0.95)';
          div.style.border = '2px solid rgba(11,87,164,0.12)';
          div.style.borderRadius = '32px';
          div.style.padding = '6px 10px';
          div.style.boxShadow = '0 6px 18px rgba(0,0,0,0.08)';
          div.style.display = 'flex';
          div.style.flexDirection = 'column';
          div.style.alignItems = 'center';
          div.style.justifyContent = 'center';
          div.style.minWidth = '56px';
          div.style.minHeight = '56px';
          div.style.fontWeight = '700';
          div.style.color = '#0b57a4';
          div.style.textAlign = 'center';
          div.style.fontSize = '14px';
          div.style.lineHeight = '1';

          const countEl = document.createElement('div');
          countEl.textContent = count;
          countEl.style.fontSize = '16px';
          countEl.style.marginBottom = '4px';

          const avgEl = document.createElement('div');
          avgEl.textContent = 'avg ' + (avg ? avg.toFixed(1) : '—');
          avgEl.style.fontSize = '12px';
          avgEl.style.color = '#374151';
          avgEl.style.fontWeight = '600';

          div.appendChild(countEl);
          div.appendChild(avgEl);

          return new google.maps.Marker({
            position,
            icon: {
              url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(`
                <svg xmlns="http://www.w3.org/2000/svg" width="80" height="80">
                  <foreignObject x="0" y="0" width="80" height="80">
                    <div xmlns="http://www.w3.org/1999/xhtml" style="display:flex;align-items:center;justify-content:center;width:80px;height:80px;">
                      ${div.outerHTML}
                    </div>
                  </foreignObject>
                </svg>
              `),
              scaledSize: new google.maps.Size(64,64),
            }
          });
        }
      };

      // Create clusterer
      clusterer = new MarkerClusterer(map, markerObjs, {
        renderer: renderer,
        // gridSize, maxZoom etc можно настроить
      });

      // Fit bounds to markers
      if (markerObjs.length) {
        const bounds = new google.maps.LatLngBounds();
        markerObjs.forEach(m => bounds.extend(m.getPosition()));
        map.fitBounds(bounds);
      }

      // Filters
      setupFilters();
    })
    .catch(err => {
      console.error('Fetch error', err);
    });


  // Filter logic: reads controls, filters allMarkers and rebuilds clusterer
  function setupFilters() {
    const nameInp = document.getElementById('filter-name');
    const citySel = document.getElementById('filter-city');
    const ratingSel = document.getElementById('filter-rating');
    const clearBtn = document.getElementById('filter-clear');

    function applyFilters() {
      const name = (nameInp.value || '').trim().toLowerCase();
      const city = (citySel.value || '').trim().toLowerCase();
      const minRating = parseFloat(ratingSel.value) || 0;

      // Build filtered marker list
      const visibleMarkers = [];
      allMarkers.forEach(obj => {
        const s = obj.data;
        const matchesName = !name || (s.name && s.name.toLowerCase().includes(name));
        const matchesCity = !city || (s.city && s.city.toLowerCase() === city);
        const matchesRating = (parseFloat(s.avg_rating) || 0) >= minRating;

        if (matchesName && matchesCity && matchesRating) {
          visibleMarkers.push(obj.marker);
          obj.marker.setMap(map); // ensure it's on map (clusterer will manage display)
        } else {
          obj.marker.setMap(null); // remove from map (so clusterer won't show)
        }
      });

      // Rebuild clusterer: clear and add only visible markers
      if (clusterer) {
        clusterer.clearMarkers();
        clusterer.addMarkers(visibleMarkers);
      } else {
        // fallback: if clusterer not ready, just show markers
        visibleMarkers.forEach(m => m.setMap(map));
      }
    }

    nameInp.addEventListener('input', debounce(applyFilters, 250));
    citySel.addEventListener('change', applyFilters);
    ratingSel.addEventListener('change', applyFilters);
    clearBtn.addEventListener('click', function(){
      nameInp.value = '';
      citySel.value = '';
      ratingSel.value = '0';
      applyFilters();
    });
  }

  // Simple debounce
  function debounce(fn, wait){
    let t;
    return function(){
      clearTimeout(t);
      t = setTimeout(() => fn.apply(this, arguments), wait);
    };
  }

})();
</script>

</body>
</html>
