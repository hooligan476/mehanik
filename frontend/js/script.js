
// Элементы DOM для фильтра
const brandSelect = document.getElementById("brandSelect");
const modelSelect = document.getElementById("modelSelect");
const yearSelect = document.getElementById("yearSelect");
const partSelect = document.getElementById("partSelect");
const componentSelect = document.getElementById("componentSelect");
const productList = document.getElementById("productList");

// Вспомогательная функция для заполнения select
function populateSelect(select, items, addEmpty = true) {
  let options = addEmpty ? ['<option value="">--Выберите--</option>'] : [];
  options = options.concat(items.map(item => `<option value="${item}">${item}</option>`));
  select.innerHTML = options.join('');
}

// Инициализация фильтра
populateSelect(brandSelect, Object.keys(carModels));
populateSelect(yearSelect, years);
populateSelect(partSelect, Object.keys(parts));
componentSelect.innerHTML = '<option value="">--Выберите--</option>';

// Обработчики фильтра

brandSelect.addEventListener("change", () => {
  const brand = brandSelect.value;
  if (brand) {
    populateSelect(modelSelect, carModels[brand]);
  } else {
    populateSelect(modelSelect, [], true);
  }
  yearSelect.value = "";
  populateSelect(partSelect, Object.keys(parts));
  componentSelect.innerHTML = '<option value="">--Выберите--</option>';
  showProducts();
});

modelSelect.addEventListener("change", showProducts);
yearSelect.addEventListener("change", showProducts);

partSelect.addEventListener("change", () => {
  const part = partSelect.value;
  if (part) {
    populateSelect(componentSelect, parts[part]);
  } else {
    componentSelect.innerHTML = '<option value="">--Выберите--</option>';
  }
  showProducts();
});

componentSelect.addEventListener("change", showProducts);

// Функция генерации тестовых товаров с учётом фильтров
function getFilteredProducts() {
  const brand = brandSelect.value;
  const model = modelSelect.value;
  const year = yearSelect.value;
  const part = partSelect.value;
  const component = componentSelect.value;

  if (!brand) return [];

  const products = [];

  for (let i = 1; i <= 5; i++) {
    const prodPart = part || "Разное";
    const prodComponent = component || "Разное";
    const prodModel = model || "Любая модель";
    const prodYear = year || "Любой год";

    products.push({
      id: `${brand}-${i}`,
      name: `${prodPart} ${prodComponent} #${i}`,
      brand: brand,
      model: prodModel,
      year: prodYear,
      photo: "images/sample.png",
      price: `${100 + i * 50} TMT`
    });
  }

  return products;
}

// Отображение товаров в main
function showProducts() {
  const products = getFilteredProducts();

  if (products.length === 0) {
    productList.innerHTML = "<h2>Выберите марку для отображения товаров</h2>";
    return;
  }

  productList.innerHTML = `
    <div class="product-wrapper">
      <h2 class="product-title">Результаты поиска</h2>
      <div class="product-grid">
        ${products.map(p => `
          <div class="product-card" data-id="${p.id}">
            <img src="${p.photo}" alt="${p.name}">
            <h3>${p.name}</h3>
            <p><strong>Марка:</strong> ${p.brand}</p>
            <p><strong>Модель:</strong> ${p.model}</p>
            <p><strong>Год:</strong> ${p.year}</p>
            <p><strong>Цена:</strong> ${p.price}</p>
          </div>
        `).join("")}
      </div>
    </div>
  `;

  // Клик по карточке открывает модалку с подробностями
  document.querySelectorAll(".product-card").forEach(card => {
    card.addEventListener("click", () => {
      const prodId = card.getAttribute("data-id");
      const prod = products.find(p => p.id === prodId);
      openProductModal(prod);
    });
  });
}

// --- Модалка товара ---

const productModal = document.getElementById("productModal");
const productModalClose = document.querySelector("#productModal .modal-close");
const productModalBody = document.getElementById("modalDetails");

function openProductModal(product) {
  if (!product) return;
  productModalBody.innerHTML = `
    <h2>${product.name}</h2>
    <img src="${product.photo}" alt="${product.name}" style="max-width: 100%; height: auto; margin-bottom: 15px;">
    <p><strong>Марка:</strong> ${product.brand}</p>
    <p><strong>Модель:</strong> ${product.model}</p>
    <p><strong>Год:</strong> ${product.year}</p>
    <p><strong>Цена:</strong> ${product.price}</p>
    <p>Описание товара здесь...</p>
  `;
  productModal.classList.remove("hidden");
}

productModalClose.addEventListener("click", () => {
  productModal.classList.add("hidden");
});
productModal.addEventListener("click", e => {
  if (e.target === productModal) {
    productModal.classList.add("hidden");
  }
});

