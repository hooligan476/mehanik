function deleteUserRow(button) {
    const row = button.closest('tr');
    row.remove();
  }
// Открытие модального окна при клике на пользователя
document.querySelectorAll('#usersTable tbody tr').forEach(row => {
    row.addEventListener('click', function (e) {
      // Чтобы не сработало на кнопке "Удалить"
      if (e.target.tagName.toLowerCase() === 'button') return;
  
      const cells = this.querySelectorAll('td');
      const userData = {
        id: cells[0].textContent,
        name: cells[1].textContent,
        phone: cells[2].textContent,
        products: cells[3].textContent,
        ip: cells[4].textContent,
        location: cells[5].textContent,
        address: cells[6].textContent
      };
  
      showUserModal(userData);
    });
  });
  
  function showUserModal(user) {
    const modal = document.getElementById('userModal');
    const infoBox = document.getElementById('userInfo');
    const productList = document.getElementById('userProducts');
  
    infoBox.innerHTML = `
      <p><strong>ID:</strong> ${user.id}</p>
      <p><strong>Имя:</strong> ${user.name}</p>
      <p><strong>Телефон:</strong> ${user.phone}</p>
      <p><strong>IP:</strong> ${user.ip}</p>
      <p><strong>Локация:</strong> ${user.location}</p>
      <p><strong>Адрес:</strong> ${user.address}</p>
    `;
  
    // Пример товаров (можно потом получать с сервера)
    const fakeProducts = parseInt(user.products) || 0;
    productList.innerHTML = '';
    for (let i = 1; i <= fakeProducts; i++) {
      const li = document.createElement('li');
      li.textContent = `Товар №${i}`;
      productList.appendChild(li);
    }
  
    modal.style.display = 'block';
  }
  
  function closeModal() {
    document.getElementById('userModal').style.display = 'none';
  }
  // Вкладки
function openTab(tabId) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab').forEach(el => el.classList.remove('active'));
    document.getElementById(tabId).classList.add('active');
    event.target.classList.add('active');
  }
  
  // Открытие модалки
  document.querySelectorAll('#usersTable tbody tr').forEach(row => {
    row.addEventListener('click', function (e) {
      if (e.target.tagName.toLowerCase() === 'button') return;
  
      const cells = this.querySelectorAll('td');
      const userData = {
        id: cells[0].textContent,
        name: cells[1].textContent,
        phone: cells[2].textContent,
        products: parseInt(cells[3].textContent) || 0,
        ip: cells[4].textContent,
        location: cells[5].textContent,
        address: cells[6].textContent
      };
  
      // Заполняем форму
      document.getElementById('inputId').value = userData.id;
      document.getElementById('inputName').value = userData.name;
      document.getElementById('inputPhone').value = userData.phone;
      document.getElementById('inputIp').value = userData.ip;
      document.getElementById('inputLocation').value = userData.location;
      document.getElementById('inputAddress').value = userData.address;
  
      // Список товаров
      const productList = document.getElementById('userProducts');
      productList.innerHTML = '';
      for (let i = 1; i <= userData.products; i++) {
        const li = document.createElement('li');
        li.textContent = `Товар №${i}`;
        productList.appendChild(li);
      }
  
      document.getElementById('userModal').style.display = 'block';
    });
  });
  
  function closeModal() {
    document.getElementById('userModal').style.display = 'none';
  }
  
  // Псевдо-сохранение данных (можно позже заменить на отправку в backend)
  function saveUserData() {
    const name = document.getElementById('inputName').value;
    const phone = document.getElementById('inputPhone').value;
    const location = document.getElementById('inputLocation').value;
    const address = document.getElementById('inputAddress').value;
  
    alert('✅ Данные сохранены!\n\nИмя: ' + name + '\nТелефон: ' + phone);
  }
  
  