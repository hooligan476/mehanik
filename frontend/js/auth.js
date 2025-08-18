const authModal = document.getElementById('authModal');
const loginTab = document.getElementById('loginTab');
const registerTab = document.getElementById('registerTab');
const loginForm = document.getElementById('loginForm');
const registerForm = document.getElementById('registerForm');
const loginButton = document.querySelector('.login-button');
const authModalClose = document.getElementById('authModalClose');

// Валидация телефона (8 цифр после +993)
function validatePhone(phone) {
  if (!phone.startsWith('+993')) return false;
  const rest = phone.slice(4);
  return /^\d{8}$/.test(rest);
}

// Настройка поля телефона
function setupPhoneInput(input) {
  input.addEventListener('input', () => {
    let val = input.value;

    // Если удалили +993 — возвращаем
    if (!val.startsWith('+993')) {
      val = '+993';
    }

    // Убираем все символы кроме цифр после +993
    const afterPrefix = val.slice(4).replace(/\D/g, '');

    // Ограничиваем длину
    input.value = '+993' + afterPrefix.slice(0, 8);
  });

  // Запрет на удаление префикса
  input.addEventListener('keydown', (e) => {
    const allowedKeys = ["Backspace", "ArrowLeft", "ArrowRight", "Delete", "Tab"];
    if (input.selectionStart < 4 && (e.key === "Backspace" || e.key === "Delete")) {
      e.preventDefault();
      return;
    }
    if (!allowedKeys.includes(e.key) && !/^\d$/.test(e.key)) {
      e.preventDefault();
    }
  });
}

// Применяем к обоим полям
setupPhoneInput(document.getElementById('loginPhone'));
setupPhoneInput(document.getElementById('registerPhone'));

// Показываем модалку
loginButton.addEventListener('click', () => {
  authModal.classList.add('show');
  showLoginForm();
});

// Закрытие по кнопке (X)
authModalClose.addEventListener('click', () => {
  authModal.classList.remove('show');
  clearErrors();
});

// Закрытие по клику вне контента
authModal.addEventListener('click', e => {
  if (e.target === authModal) {
    authModal.classList.remove('show');
    clearErrors();
  }
});

// Вкладки
loginTab.addEventListener('click', showLoginForm);
registerTab.addEventListener('click', showRegisterForm);

// Функции переключения форм
function showLoginForm() {
  loginTab.classList.add('active');
  registerTab.classList.remove('active');
  loginForm.classList.remove('hidden');
  registerForm.classList.add('hidden');
  clearErrors();
}

function showRegisterForm() {
  registerTab.classList.add('active');
  loginTab.classList.remove('active');
  registerForm.classList.remove('hidden');
  loginForm.classList.add('hidden');
  clearErrors();
}

// Очистка ошибок
function clearErrors() {
  document.querySelectorAll('.input-error').forEach(el => {
    el.classList.remove('input-error');
  });
}

// Обработка логина
document.getElementById('loginSubmit').addEventListener('click', () => {
  const phoneInput = document.getElementById('loginPhone');
  const phone = phoneInput.value.trim();

  clearErrors();

  if (!validatePhone(phone)) {
    phoneInput.classList.add('input-error');
    return;
  }

  // Здесь может быть отправка запроса на логин

  // Сброс поля
  phoneInput.value = '+993';

  // Закрытие модалки
  authModal.classList.remove('show');
});

// Обработка регистрации
document.getElementById('registerSubmit').addEventListener('click', () => {
  const nameInput = document.getElementById('registerName');
  const phoneInput = document.getElementById('registerPhone');
  const name = nameInput.value.trim();
  const phone = phoneInput.value.trim();

  clearErrors();

  let hasError = false;

  if (!name) {
    nameInput.classList.add('input-error');
    hasError = true;
  }

  if (!validatePhone(phone)) {
    phoneInput.classList.add('input-error');
    hasError = true;
  }

  if (hasError) return;

  // Заглушка: отправка смс для подтверждения
  sendSMS(phone);

  // Очистка
  nameInput.value = '';
  phoneInput.value = '+993';

  // Закрытие модалки
  authModal.classList.remove('show');
});

// Имитация отправки SMS
function sendSMS(phone) {
  console.log(`Отправка SMS на номер ${phone}...`);
  // Здесь будет реальный запрос на API отправки SMS
}
