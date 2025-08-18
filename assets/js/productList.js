function productCard(p){
  const img = p.photo ? `<img src="${p.photo}" alt="${p.name}">` : '';
  const price = Number(p.price).toFixed(2);
  return `<article class="card">
    ${img}
    <div class="card-body">
      <div class="rating">${p.rating ?? ''}</div>
      <h4>${p.name}</h4>
      <div class="price">${price}</div>
      <div class="meta">${p.brand_name||''} ${p.model_name||''}</div>
      <div class="meta">ID: ${p.id}${p.sku?` · ${p.sku}`:''}</div>
      <p class="desc">${(p.description||'').slice(0,120)}</p>
    </div>
  </article>`;
}

function renderProducts(container, items){
  container.innerHTML = items.map(productCard).join('') || '<div class="muted">Ничего не найдено</div>';
}
