// --- Модалка добавления продукта ---
const addProductModal = document.getElementById("addProductModal");
const addProductCloseBtn = document.getElementById("addProductCloseBtn");

// Открыть модалку
document.getElementById("addProductBtn").addEventListener("click", () => {
  addProductModal.classList.remove("addProduct-hidden");
  populateAddProductBrands();
  populateAddProductYears();
  populateAddProductPartCategories();

  // Очистка моделей и компонентов
  document.getElementById("addProductModel").innerHTML = '<option disabled selected>Выберите модель</option>';
  document.getElementById("addProductComponent").innerHTML = '<option disabled selected>Выберите компонент</option>';
});

// Закрыть модалку
addProductCloseBtn.addEventListener("click", () => {
  addProductModal.classList.add("addProduct-hidden");
});

// Заполнение списка марок
function populateAddProductBrands() {
  const brandSelect = document.getElementById("addProductBrand");
  brandSelect.innerHTML = "<option disabled selected>Выберите марку</option>";
  Object.keys(carModels).forEach(brand => {
    const option = document.createElement("option");
    option.value = brand;
    option.textContent = brand;
    brandSelect.appendChild(option);
  });
}

// Заполнение моделей при выборе марки
document.getElementById("addProductBrand").addEventListener("change", (e) => {
  const modelSelect = document.getElementById("addProductModel");
  modelSelect.innerHTML = "<option disabled selected>Выберите модель</option>";
  carModels[e.target.value].forEach(model => {
    const option = document.createElement("option");
    option.value = model;
    option.textContent = model;
    modelSelect.appendChild(option);
  });
});

// Заполнение годов
function populateAddProductYears() {
  const yearSelect = document.getElementById("addProductYear");
  yearSelect.innerHTML = "<option disabled selected>Выберите год</option>";
  years.forEach(year => {
    const option = document.createElement("option");
    option.value = year;
    option.textContent = year;
    yearSelect.appendChild(option);
  });
}

// Заполнение комплексных частей
function populateAddProductPartCategories() {
  const categorySelect = document.getElementById("addProductPartCategory");
  categorySelect.innerHTML = "<option disabled selected>Выберите часть</option>";
  Object.keys(parts).forEach(partCategory => {
    const option = document.createElement("option");
    option.value = partCategory;
    option.textContent = partCategory;
    categorySelect.appendChild(option);
  });
}

// Заполнение компонентов по выбранной части
document.getElementById("addProductPartCategory").addEventListener("change", (e) => {
  const componentSelect = document.getElementById("addProductComponent");
  componentSelect.innerHTML = "<option disabled selected>Выберите компонент</option>";
  parts[e.target.value].forEach(component => {
    const option = document.createElement("option");
    option.value = component;
    option.textContent = component;
    componentSelect.appendChild(option);
  });
});

// Обработка формы добавления продукта
document.getElementById("addProductForm").addEventListener("submit", function (e) {
  e.preventDefault();

  const fieldsToCheck = [
    "addProductBrand",
    "addProductModel",
    "addProductYear",
    "addProductPartCategory",
    "addProductComponent",
    "addProductName",
    "addProductQuality",
    "addProductDescription",
    "addProductImage",
    "addProductPrice"
  ];

  let formValid = true;

  fieldsToCheck.forEach(id => {
    const field = document.getElementById(id);

    field.addEventListener("input", () => field.classList.remove("input-error"));
    field.addEventListener("change", () => field.classList.remove("input-error"));

    const isEmpty = (field.type === "file")
      ? field.files.length === 0
      : !field.value || field.value.includes("Выберите");

    if (isEmpty) {
      field.classList.add("input-error");
      formValid = false;
    }
  });

  if (!formValid) {
    alert("Пожалуйста, заполните все поля формы перед добавлением товара.");
    return;
  }

  const brand = document.getElementById("addProductBrand").value;
  const model = document.getElementById("addProductModel").value;
  const year = document.getElementById("addProductYear").value;
  const partCategory = document.getElementById("addProductPartCategory").value;
  const component = document.getElementById("addProductComponent").value;
  const name = document.getElementById("addProductName").value.trim();
  const condition = document.getElementById("addProductQuality").value;
  const description = document.getElementById("addProductDescription").value.trim();
  const photoInput = document.getElementById("addProductImage");
  const photoName = photoInput.files[0].name;
  const price = document.getElementById("addProductPrice").value.trim();

  alert(`
Добавлен продукт:
Марка: ${brand}
Модель: ${model}
Год: ${year}
Часть: ${partCategory}
Компонент: ${component}
Название: ${name}
Состояние: ${condition}
Цена: ${price} TMT
Фото: ${photoName}
Описание: ${description}
  `);

  addProductModal.classList.add("addProduct-hidden");
  this.reset();

  document.getElementById("addProductModel").innerHTML = '<option disabled selected>Выберите модель</option>';
  document.getElementById("addProductComponent").innerHTML = '<option disabled selected>Выберите компонент</option>';
});
