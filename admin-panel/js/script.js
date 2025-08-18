function showPage(id) {
  document.querySelectorAll('.page').forEach(page => {
    page.classList.remove('active');
  });
  document.getElementById(id).classList.add('active');
}

window.onload = function () {
  const canvas = document.getElementById('statsChart');

  if (!canvas) return;

  const ctx = canvas.getContext('2d');

  const statsChart = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: ['Марки', 'Модели', 'Товары', 'Пользователи', 'На модерации'],
      datasets: [{
        label: 'Количество',
        data: [15, 42, 110, 25, 7], // Здесь тестовые значения
        backgroundColor: [
          '#3498db',
          '#1abc9c',
          '#f39c12',
          '#9b59b6',
          '#e74c3c'
        ],
        borderRadius: 5
      }]
    },
    options: {
      responsive: true,
      plugins: {
        title: {
          display: true,
          text: 'Общая статистика сайта'
        },
        legend: {
          display: false
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: {
            precision: 0
          }
        }
      }
    }
  });
};
