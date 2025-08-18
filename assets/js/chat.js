const win = document.getElementById('chatWindow');
const form = document.getElementById('chatForm');
async function load(){
  const j = await fetch('/mehanik/api/chat.php').then(r=>r.json());
  win.innerHTML = j.messages.map(m=>`<div class="msg ${m.sender}"><b>${m.sender}:</b> ${m.content} <span>${m.created_at}</span></div>`).join('');
  win.scrollTop = win.scrollHeight;
}
async function sendMessage(text){
  const fd = new FormData(); fd.append('action','send'); fd.append('content', text);
  await fetch('/mehanik/api/chat.php',{method:'POST',body:fd});
  await load();
}
form.addEventListener('submit', async (e)=>{
  e.preventDefault();
  const v = document.getElementById('message').value.trim(); if(!v) return;
  document.getElementById('message').value='';
  await sendMessage(v);
});
setInterval(load, 3000);
load();
