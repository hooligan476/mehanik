document.addEventListener("DOMContentLoaded", () => {
    const profileBtn = document.getElementById("sidebarProfileBtn");
    const profileModal = document.getElementById("profileModal");
    const closeModal = document.getElementById("profileModalClose");
    const profileIdField = document.getElementById("profileId");

    // Генерация случайного ID
    function generateRandomId() {
        return Math.floor(100000 + Math.random() * 900000);
    }

    // Открыть модалку
    profileBtn.addEventListener("click", () => {
        profileIdField.value = generateRandomId();
        profileModal.style.display = "block";
    });

    // Закрыть по крестику
    closeModal.addEventListener("click", () => {
        profileModal.style.display = "none";
    });

    // Закрыть по клику на фон
    window.addEventListener("click", (e) => {
        if (e.target === profileModal) {
            profileModal.style.display = "none";
        }
    });
});
