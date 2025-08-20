function postForm(url, form){
  const fd = new FormData(form);
  return fetch(url,{method:'POST',body:fd}).then(r=>r.json());
}

const loginForm = document.getElementById('loginForm');
if(loginForm){
  loginForm.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const res = await postForm('/mehanik/api/auth-login.php', loginForm);
    if(res.ok) location.href='/mehanik/public/index.php'; else alert(res.error||'Ошибка');
  });
}

const registerForm = document.getElementById('registerForm');
if(registerForm){
  registerForm.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const res = await postForm('/mehanik/api/auth-register.php', registerForm);
    if(res.ok) location.href='/mehanik/public/index.php'; else alert(res.error||'Ошибка');
  });
}
