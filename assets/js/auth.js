// -------------------- ОТПРАВКА ФОРМЫ --------------------
async function postForm(url, form) {
  try {
    const fd = new FormData(form);

    console.log("Отправка данных на сервер:", url);
    for (let [key, value] of fd.entries()) {
      console.log(key, value);
    }

    const response = await fetch(url, {
      method: "POST",
      body: fd,
      credentials: "same-origin" // важно для работы с сессиями
    });

    const text = await response.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch {
      data = { ok: false, error: text };
    }

    if (!response.ok) {
      console.error("HTTP ошибка:", response.status, data);
      return data;
    }

    console.log("Ответ сервера:", data);
    return data;

  } catch (err) {
    console.error("Ошибка запроса:", err);
    return { ok: false, error: "Ошибка соединения с сервером" };
  }
}

// -------------------- ЛОГИН --------------------
const loginForm = document.getElementById("loginForm");
if (loginForm) {
  loginForm.addEventListener("submit", async (e) => {
    e.preventDefault();

    const res = await postForm("http://localhost/mehanik/api/auth-login.php", loginForm);

    if (res.ok) {
      alert("Успешный вход!");
      location.href = "http://localhost/mehanik/public/index.php";
    } else {
      alert(res.error || "Неверный email или пароль");
    }
  });
}

// -------------------- РЕГИСТРАЦИЯ --------------------
const registerForm = document.getElementById("registerForm");
if (registerForm) {
  registerForm.addEventListener("submit", async (e) => {
    e.preventDefault();

    const res = await postForm("http://localhost/mehanik/api/auth-register.php", registerForm);

    if (res.ok) {
      alert("Регистрация успешна!");
      location.href = "http://localhost/mehanik/public/index.php";
    } else {
      alert(res.error || "Ошибка регистрации");
    }
  });
}
