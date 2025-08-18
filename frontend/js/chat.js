const chatButton = document.getElementById('chatButton');
const chatPopup = document.getElementById('chatPopup');
const chatBody = document.getElementById('chatBody');
const chatInput = document.getElementById('chatInput');

chatButton.addEventListener('click', toggleChat);

function toggleChat() {
  chatPopup.style.display = chatPopup.style.display === 'block' ? 'none' : 'block';
}

function sendMessage() {
  const message = chatInput.value.trim();
  if (!message) return;

  const messageDiv = document.createElement('div');
  messageDiv.classList.add('chat-message', 'user');
  messageDiv.textContent = message;
  chatBody.appendChild(messageDiv);

  chatInput.value = '';
  chatBody.scrollTop = chatBody.scrollHeight;

  // Optional: автоответ от бота
  setTimeout(() => {
    const reply = document.createElement('div');
    reply.classList.add('chat-message', 'bot');
    reply.textContent = 'Спасибо за сообщение! Мы скоро ответим.';
    chatBody.appendChild(reply);
    chatBody.scrollTop = chatBody.scrollHeight;
  }, 1000);
}
