async function loadLookups() {
  const data = await fetchJSON('/mehanik/api/products.php');
  if (!data || !data.lookups) return;

  const { brands, models, complex_parts, components } = data.lookups;

  const brandSel = $('#brand');
  if (brandSel) {
    brandSel.innerHTML = '<option value="">Все бренды</option>' +
      brands.map(b => `<option value="${b.id}">${b.name}</option>`).join('');
  }

  const modelSel = $('#model');
  if (modelSel) {
    modelSel.innerHTML = '<option value="">Все модели</option>';
    brandSel.addEventListener('change', () => {
      modelSel.innerHTML = '<option value="">Все модели</option>' +
        models.filter(m => !brandSel.value || m.brand_id == brandSel.value)
              .map(m => `<option value="${m.id}">${m.name}</option>`).join('');
      runFilter();
    });
  }

  const cpSel = $('#complex_part');
  if (cpSel) {
    cpSel.innerHTML = '<option value="">Все комплексные части</option>' +
      complex_parts.map(cp => `<option value="${cp.id}">${cp.name}</option>`).join('');
  }

  const compSel = $('#component');
  if (compSel) {
    compSel.innerHTML = '<option value="">Все компоненты</option>';
    cpSel.addEventListener('change', () => {
      compSel.innerHTML = '<option value="">Все компоненты</option>' +
        components.filter(c => !cpSel.value || c.complex_part_id == cpSel.value)
                  .map(c => `<option value="${c.id}">${c.name}</option>`).join('');
      runFilter();
    });
  }
}
