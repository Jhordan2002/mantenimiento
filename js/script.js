document.addEventListener("DOMContentLoaded", function () {
  const togglePassword = document.getElementById("togglePassword");
  const passwordInput = document.getElementById("clave");

  togglePassword.addEventListener("click", function () {
    const type =
      passwordInput.getAttribute("type") === "password" ? "text" : "password";
    passwordInput.setAttribute("type", type);
    this.classList.toggle("fa-eye");
    this.classList.toggle("fa-eye-slash");
  });
});
// Función para ajustar estilos según el tamaño de la pantalla
function ajustarEstilos() {
  const anchoVentana = window.innerWidth;
  const altoVentana = window.innerHeight;
  const loginContainer = document.querySelector(".login-container");

  // Ejemplo de ajuste: para pantallas con ancho menor o igual a 401px y alto menor o igual a 808px
  if (anchoVentana <= 401 && altoVentana <= 808 && loginContainer) {
    loginContainer.style.width = "95%"; // Ocupa el 95% del ancho
    loginContainer.style.maxWidth = "350px"; // Limita el ancho máximo
    loginContainer.style.padding = "10px"; // Ajusta el padding para dispositivos pequeños
  } else if (loginContainer) {
    // Restaurar o definir estilos para pantallas mayores
    loginContainer.style.width = ""; // Se utilizan los valores del CSS
    loginContainer.style.maxWidth = "400px";
    loginContainer.style.padding = "40px";
  }
}

// Ejecutar la función al cargar la página
ajustarEstilos();

// Actualizar los estilos cada vez que cambie el tamaño de la ventana
window.addEventListener("resize", ajustarEstilos);
