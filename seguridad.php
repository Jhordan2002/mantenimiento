<?php
@session_start();

if($_SESSION["autentica"] != "SIP"){
	header("Location:index.html");
	exit();
}

// Verificación de inactividad en el servidor: 3 horas (3*3600 segundos)
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 3 * 3600)) {
    session_unset();
    session_destroy();
    header("Location: ../../index.php");
    exit();
}
$_SESSION['last_activity'] = time();

// Resto de tu código PHP...
?>
<!-- Detectar inactividad y cierre de sesión en otra pestaña -->
<script>
  window.addEventListener("storage", function(event) {
    if (event.key === "logoutEvent") {
      window.location.href = "../../index.php"; // Ajusta la ruta según sea necesario
    }
  });

  // 3 horas en milisegundos (3 * 3600000 = 10800000)
  let tiempoInactividad = 10800000;
  let tiempoExpiracion;

  function reiniciarTiempo() {
    clearTimeout(tiempoExpiracion);
    tiempoExpiracion = setTimeout(cerrarSesion, tiempoInactividad);
  }

  function cerrarSesion() {
    document.body.innerHTML = `
      <div class="logout-overlay">
        <div class="logout-content">
          <img src="img/loading.gif" alt="Cerrando sesión..." class="logout-gif">
          <p class="logout-text">Sesión expirada. Redirigiendo...</p>
        </div>
      </div>
    `;
    setTimeout(() => {
      window.location.href = "salir.php";
    }, 3000);
  }

  // Detectar actividad del usuario
  document.addEventListener("mousemove", reiniciarTiempo);
  document.addEventListener("keypress", reiniciarTiempo);
  document.addEventListener("scroll", reiniciarTiempo);
  document.addEventListener("click", reiniciarTiempo);

  reiniciarTiempo(); // Iniciar el temporizador
</script>

<style>
  .logout-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.9);
    display: flex;
    justify-content: center;
    align-items: center;
    text-align: center;
  }
  .logout-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 15px;
  }
  .logout-gif {
    width: 120px;
  }
  .logout-text {
    font-size: 22px;
    font-weight: bold;
    color: #fff;
  }
</style>
