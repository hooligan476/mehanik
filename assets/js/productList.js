// productList.js

function productCard(p) {
  const img = p.photo ? `<img src="${p.photo}" alt="${p.name}">` : '<div class="no-image">Нет фото</div>';
  const price = p.price ? Number(p.price).toFixed(2) : '0.00';
  const availability = p.availability ?? 0;
  const brandModel = (p.brand_name || '') + (p.model_name ? ' ' + p.model_name : '');
  const sku = p.sku ? ` · ${p.sku}` : '';
  const desc = p.description ? p.description.slice(0, 120) + (p.description.length > 120 ? '…' : '') : '';

  return `
    <article class="card">
      ${img}
      <div class="card-body">
        <div class="rating">${p.rating ?? ''}</div>
        <h4>${p.name}</h4>
        <div class="price">Цена: ${price}</div>
        <div class="meta">${brandModel}</div>
        <div class="meta">ID: ${p.id}${sku}</div>
        <div class="meta">Доступно: ${availability}</div>
        <p class="desc">${desc}</p>
        <a href="/mehanik/public/product.php?id=${p.id}" class="btn">Подробнее</a>
      </div>
    </article>
  `;
}

function renderProducts(container, items) {
  container.innerHTML = items && items.length
    ? items.map(productCard).join('')
    : '<div class="muted">Ничего не найдено</div>';
}

// Функция для загрузки товаров через API с фильтрами
async function loadProducts(filters = {}) {
  const container = document.getElementById('products');
  const params = new URLSearchParams(filters);
  try {
    const res = await fetch(`/mehanik/api/get-products.php?${params.toString()}`);
    const data = await res.json();
    renderProducts(container, data);
  } catch (e) {
    container.innerHTML = `<div class="muted">Ошибка загрузки товаров</div>`;
    console.error('Ошибка загрузки товаров:', e);
  }
}

// Экспорт функций для глобального доступа
window.productList = { productCard, renderProducts, loadProducts };
