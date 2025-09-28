(function(){
  // expects window.ADD_CAR_CONFIG to be set via server-side before this script is loaded
  const cfg = window.ADD_CAR_CONFIG || {};
  window.VEHICLE_BODIES_BY_TYPE = cfg.VEHICLE_BODIES_BY_TYPE || window.VEHICLE_BODIES_BY_TYPE || {};
  window.DISTRICTS_BY_REGION = cfg.DISTRICTS_BY_REGION || window.DISTRICTS_BY_REGION || {};

  const brandEl = document.getElementById('brand');
  const modelEl = document.getElementById('model');
  const vehicleTypeEl = document.getElementById('vehicle_type');
  const bodyEl = document.getElementById('body_type');
  const fuelEl = document.getElementById('fuel');
  const transEl = document.getElementById('transmission');

  const MIN_YEAR = Number(cfg.MIN_YEAR || 0);
  const MAX_YEAR = Number(cfg.MAX_YEAR || 9999);
  const BASE_PUBLIC = cfg.BASE_PUBLIC || '/mehanik/public';

  async function loadModels(brandId) {
    modelEl.innerHTML = '<option value="">Загрузка...</option>';
    if (!brandId) { modelEl.innerHTML = '<option value="">— выберите модель —</option>'; return; }
    try {
      const res = await fetch(`/mehanik/api/get-models.php?brand_id=${encodeURIComponent(brandId)}`);
      if (!res.ok) throw new Error('network');
      const data = await res.json();
      modelEl.innerHTML = '<option value="">— выберите модель —</option>';
      (Array.isArray(data) ? data : []).forEach(m => {
        const o = document.createElement('option'); o.value = m.id; o.textContent = m.name;
        modelEl.appendChild(o);
      });
    } catch (e) {
      console.error('Ошибка загрузки моделей', e);
      modelEl.innerHTML = '<option value="">— выберите модель —</option>';
    }
  }

  function populateBodiesFor(typeValue) {
    bodyEl.innerHTML = '<option value="">— выберите кузов —</option>';
    if (!typeValue) return;
    const items = window.VEHICLE_BODIES_BY_TYPE[typeValue] || [];
    if (items.length === 0) {
      fetch(`/mehanik/api/get-bodies.php?vehicle_type=${encodeURIComponent(typeValue)}`)
        .then(r => r.ok ? r.json() : Promise.reject('no') )
        .then(data => {
          (Array.isArray(data) ? data : []).forEach(b => {
            const o = document.createElement('option'); o.value = b.id ?? b.key ?? b.name; o.textContent = b.name ?? b.label ?? b.value;
            bodyEl.appendChild(o);
          });
        })
        .catch(()=>{ /* ignore */ });
      return;
    }
    items.forEach(b => {
      const o = document.createElement('option'); o.value = b.id ?? b.key ?? b.name; o.textContent = b.name;
      bodyEl.appendChild(o);
    });
  }

  if (brandEl) brandEl.addEventListener('change', () => loadModels(brandEl.value));
  if (vehicleTypeEl) vehicleTypeEl.addEventListener('change', () => populateBodiesFor(vehicleTypeEl.value));

  // photos uploader + AJAX submit
  const dropzone = document.getElementById('dropzone');
  const photosInput = document.getElementById('p_photos');
  const previews = document.getElementById('previews');
  const MAX = 10; // keep in sync with server-side
  const ALLOWED = ['image/jpeg','image/png','image/webp'];
  let files = [];
  let mainIndex = null;

  function render() {
    previews.innerHTML = '';
    files.forEach((f, idx) => {
      const w = document.createElement('div'); w.className = 'preview-item';
      const img = document.createElement('img'); w.appendChild(img);
      const fr = new FileReader(); fr.onload = e => img.src = e.target.result; fr.readAsDataURL(f);

      const actions = document.createElement('div'); actions.className = 'actions';
      const btnMain = document.createElement('button'); btnMain.type='button'; btnMain.textContent='★'; btnMain.title='Сделать главным';
      const btnDel = document.createElement('button'); btnDel.type='button'; btnDel.textContent='✕'; btnDel.title='Удалить';
      actions.appendChild(btnMain); actions.appendChild(btnDel);
      w.appendChild(actions);

      if (idx === mainIndex) {
        const badge = document.createElement('div'); badge.className = 'main-badge'; badge.textContent = 'Главное'; w.appendChild(badge);
      }

      btnMain.addEventListener('click', () => { mainIndex = idx; render(); });
      btnDel.addEventListener('click', () => {
        files.splice(idx,1);
        if (mainIndex !== null) {
          if (idx === mainIndex) mainIndex = null;
          else if (idx < mainIndex) mainIndex--;
        }
        render();
      });

      previews.appendChild(w);
    });
  }

  function addIncoming(list) {
    const inc = Array.from(list || []);
    if (files.length + inc.length > MAX) { alert('Максимум ' + MAX + ' фото'); return; }
    for (let f of inc) {
      if (!ALLOWED.includes(f.type)) { alert('Неподдерживаемый формат: ' + f.name); continue; }
      files.push(f);
    }
    if (mainIndex === null && files.length) mainIndex = 0;
    render();
  }

  dropzone.addEventListener('click', () => photosInput.click());
  dropzone.addEventListener('dragover', e => { e.preventDefault(); dropzone.classList.add('dragover'); });
  dropzone.addEventListener('dragleave', e => { e.preventDefault(); dropzone.classList.remove('dragover'); });
  dropzone.addEventListener('drop', e => { e.preventDefault(); dropzone.classList.remove('dragover'); addIncoming(e.dataTransfer.files); });

  photosInput.addEventListener('change', (e) => { addIncoming(e.target.files); photosInput.value=''; });

  // on submit -> build FormData and send (XHR) so photos included properly
  const form = document.getElementById('addCarForm');
  if (form) {
    form.addEventListener('submit', function(e){
      // front validation
      const brand = document.getElementById('brand').value;
      const model = document.getElementById('model').value;
      const year = parseInt(document.getElementById('year').value || '0', 10);
      const minY = MIN_YEAR;
      const maxY = MAX_YEAR;
      const fuel = fuelEl.value;
      const trans = transEl.value;
      if (!brand || !model) { e.preventDefault(); alert('Пожалуйста выберите бренд и модель'); return false; }
      if (!year || year < minY || year > maxY) { e.preventDefault(); alert('Выберите корректный год'); return false; }
      if (!fuel) { e.preventDefault(); alert('Пожалуйста выберите тип топлива'); return false; }
      if (!trans) { e.preventDefault(); alert('Пожалуйста выберите коробку передач'); return false; }

      e.preventDefault();
      const fd = new FormData();
      Array.from(form.elements).forEach(el => {
        if (!el.name) return;
        if (el.type === 'file') return;
        if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) return;
        fd.append(el.name, el.value);
      });

      // append files: main -> photo, others -> photos[]
      files.forEach((f, idx) => {
        if (idx === mainIndex) fd.append('photo', f, f.name);
        else fd.append('photos[]', f, f.name);
      });

      const xhr = new XMLHttpRequest();
      xhr.open('POST', form.action, true);
      xhr.setRequestHeader('X-Requested-With','XMLHttpRequest');

      xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) return;
        if (xhr.status >= 200 && xhr.status < 300) {
          try {
            const j = JSON.parse(xhr.responseText || '{}');
            if (j && j.ok) {
              window.location.href = BASE_PUBLIC + '/my-cars.php';
              return;
            } else if (j && j.error) {
              alert('Ошибка: ' + j.error);
              return;
            }
          } catch (err) {
            location.reload();
          }
        } else {
          alert('Ошибка сервера при сохранении');
        }
      };
      xhr.send(fd);
    });
  }

  // regions/districts: populate function
  function populateDistrictsFor(regionId) {
    const regionEl = document.getElementById('region_id');
    const districtEl = document.getElementById('district_id');
    if (!districtEl) return;
    districtEl.innerHTML = '<option value="">Загрузка...</option>';
    if (!regionId) { districtEl.innerHTML = '<option value="">— выберите этрап —</option>'; return; }
    const items = window.DISTRICTS_BY_REGION[regionId] || [];
    if (items.length) {
      districtEl.innerHTML = '<option value="">— выберите этрап —</option>';
      items.forEach(d => {
        const o = document.createElement('option'); o.value = d.id; o.textContent = d.name; districtEl.appendChild(o);
      });
      return;
    }
    // fallback: ajax
    fetch(`/mehanik/api/get-districts.php?region_id=${encodeURIComponent(regionId)}`)
      .then(r => r.ok ? r.json() : Promise.reject('no'))
      .then(data => {
        districtEl.innerHTML = '<option value="">— выберите этрап —</option>';
        (Array.isArray(data) ? data : []).forEach(d => {
          const o = document.createElement('option'); o.value = d.id; o.textContent = d.name; districtEl.appendChild(o);
        });
      })
      .catch(()=>{ districtEl.innerHTML = '<option value="">— выберите этрап —</option>'; });
  }

  const regionEl = document.getElementById('region_id');
  if (regionEl) regionEl.addEventListener('change', () => populateDistrictsFor(regionEl.value));

})();
