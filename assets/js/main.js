const $ = s => document.querySelector(s);
const $$ = s => document.querySelectorAll(s);

async function fetchJSON(url) {
  const r = await fetch(url);
  return r.ok ? r.json() : {};
}

function debounce(fn, ms=300){
  let t;
  return (...a)=>{ clearTimeout(t); t = setTimeout(()=>fn(...a), ms); };
}

async function runFilter(){
  const params = new URLSearchParams({
    brand: $('#brand') ? $('#brand').value : '',
    model: $('#model') ? $('#model').value : '',
    year_from: $('#year_from') ? $('#year_from').value : '',
    year_to: $('#year_to') ? $('#year_to').value : '',
    complex_part: $('#complex_part') ? $('#complex_part').value : '',
    component: $('#component') ? $('#component').value : '',
    q: $('#search') ? $('#search').value.trim() : ''
  });
  const data = await fetchJSON('/mehanik/api/products.php?' + params.toString());
  if (data && data.products) {
    renderProducts(document.getElementById('products'), data.products);
  } else {
    document.getElementById('products').innerHTML = '<div class="muted">Ошибка загрузки</div>';
  }
}

function addListeners() {
  const selectors = ['#brand','#model','#year_from','#year_to','#complex_part','#component'];
  selectors.forEach(sel=>{
    const el = document.querySelector(sel);
    if (el) el.addEventListener('change', runFilter);
  });
  const s = document.querySelector('#search');
  if (s) s.addEventListener('input', debounce(runFilter, 350));
}

window.addEventListener('DOMContentLoaded', async ()=>{
  if (typeof loadLookups === 'function') {
    await loadLookups();
  }
  await runFilter();
  addListeners();

  const form = document.getElementById('addProductForm');
  if (form) {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(form);
      const r = await fetch('/mehanik/api/add-product.php', { method: 'POST', body: fd });
      const j = await r.json();
      if (j.ok) {
        alert('Сохранено');
        window.location.href = '/mehanik/public/my-products.php';
      } else {
        alert(j.error || 'Ошибка сохранения');
      }
    });
  }
});
