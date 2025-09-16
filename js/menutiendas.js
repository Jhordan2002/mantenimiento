document.addEventListener("DOMContentLoaded", function () {
  const navToggle = document.getElementById("navToggle");
  const navMenu = document.getElementById("navMenu");
  const navIcon = document.getElementById("navIcon");

  if (navToggle && navMenu && navIcon) {
    navToggle.addEventListener("click", function () {
      navMenu.classList.toggle("open");

      // Cambiar ícono de flecha según el estado del menú
      if (navMenu.classList.contains("open")) {
        navIcon.classList.remove("fa-chevron-down");
        navIcon.classList.add("fa-chevron-up");
      } else {
        navIcon.classList.remove("fa-chevron-up");
        navIcon.classList.add("fa-chevron-down");
      }
    });
  }

  // --- Toggle de dropdowns en móviles ---
  const dropdownItems = document.querySelectorAll(".nav-item.dropdown");
  dropdownItems.forEach(function (item) {
    const link = item.querySelector(".nav-link");
    if (link) {
      link.addEventListener("click", function (e) {
        if (window.innerWidth < 720) {
          e.preventDefault();
          dropdownItems.forEach(function (otherItem) {
            if (otherItem !== item) {
              otherItem.classList.remove("open");
            }
          });
          item.classList.toggle("open");
        }
      });
    }
  });

  // --- Cerrar dropdowns si se hace clic fuera del nav ---
  document.addEventListener("click", function (e) {
    if (!e.target.closest("nav")) {
      dropdownItems.forEach(function (item) {
        item.classList.remove("open");
      });
    }
  });

  // --- Notificaciones ---
  const notificationBell = document.getElementById("notificationBell");
  const notificationDropdown = document.getElementById("notificationDropdown");

  if (notificationBell && notificationDropdown) {
    notificationBell.addEventListener("click", function (e) {
      e.preventDefault();
      e.stopPropagation(); // evita que se cierre justo al abrirlo
      notificationDropdown.style.display =
        notificationDropdown.style.display === "block" ? "none" : "block";
    });
  }

  document.addEventListener("click", function (e) {
    if (!e.target.closest(".notification-item")) {
      notificationDropdown.style.display = "none";
    }
  });

  // === Toggle menú hamburguesa ===
  const hamburgerButton = document.getElementById("hamburgerButton");
  const hamburgerDropdown = document.getElementById("hamburgerDropdown");

  if (hamburgerButton && hamburgerDropdown) {
    // Abrir o cerrar al hacer clic
    hamburgerButton.addEventListener("click", function (e) {
      e.stopPropagation(); // evita que se cierre instantáneamente
      hamburgerDropdown.classList.toggle("open");
    });

    // Cerrar si se hace clic fuera
    document.addEventListener("click", function (e) {
      if (!e.target.closest(".hamburger-menu")) {
        hamburgerDropdown.classList.remove("open");
      }
    });

    // Cerrar si se hace clic en un enlace dentro del menú
    hamburgerDropdown.querySelectorAll("a").forEach(link => {
      link.addEventListener("click", () => {
        hamburgerDropdown.classList.remove("open");
      });
    });
  }
// Añadir animación de pulso si hay notificaciones
document.addEventListener("DOMContentLoaded", function () {
  const badge = document.getElementById("notificationCount");
  if (badge && parseInt(badge.textContent) > 0) {
    badge.classList.add("pulse");
  }
});

});
