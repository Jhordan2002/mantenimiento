<?php
session_start();
require 'conexionn.php';
include("seguridad.php");

// Obtenemos el usuario actual
$usuario = isset($_SESSION['usuarioactual']) ? $_SESSION['usuarioactual'] : '';

// Obtener la última notificación aceptada para el usuario
$queryUltima = "SELECT ultima_notificacion FROM usuarios WHERE idusuario = '$usuario'";
$resultUltima = $mysqli->query($queryUltima);

$ultimaNotificacion = '0000-00-00 00:00:00'; // Valor por defecto
if ($resultUltima) {
  $rowUltima = $resultUltima->fetch_assoc();
  if (!empty($rowUltima['ultima_notificacion'])) {
    $ultimaNotificacion = $rowUltima['ultima_notificacion'];
  }
}

// Contar los productos validados hoy (status = 1) registrados después de la última notificación
$query = "SELECT COUNT(*) AS total 
          FROM alta_productos 
          WHERE status = 1
            AND DATE(fecha_registro) = CURDATE()
            AND fecha_registro > ?";
$stmt = $mysqli->prepare($query);
$stmt->bind_param('s', $ultimaNotificacion);
$stmt->execute();
$result = $stmt->get_result();

$totalProductos = 0;
if ($result) {
  $row = $result->fetch_assoc();
  $totalProductos = $row['total'];
}
// $hoy = date('Y-m-d'); // Úsalo si lo necesitas en otro lugar
?>

<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <title>Menú - LORES</title>

  <!-- Favicon -->
  <link rel="icon" type="image/png" href="img/flor.png">

  <!-- Nuestro CSS personalizado para menutiendas -->
  <link rel="stylesheet" href="css/menutiendas.css">

  <!-- Font Awesome para íconos -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
</head>

<body>
  <!-- Barra de navegación -->
  <nav class="main-nav">
    <div class="nav-container">
      <!-- (2) Sección Central: Botón hamburguesa + Menú principal -->
      <div class="nav-center">
        <!-- Botón de menú para móviles -->
        <button class="nav-toggle" id="navToggle">
          <i class="fa fa-chevron-down" id="navIcon"></i>
        </button>
        <!-- (1) Sección Izquierda: Logo LORES -->
        <div class="nav-left">
          <a href="#" class="nav-brand">
            <img src="img/FLORAPP_7.gif" alt="Logo LORES">
            <span>TIENDAS LORES</span>
          </a>
        </div>
        <div class="nav-medio">
          <!-- Menú principal -->
          <ul class="nav-menu" id="navMenu">
            <li class="nav-item dropdown">
              <a href="#" class="nav-link">Información OPE</a>
              <ul class="dropdown-menu">
                <li><a href="morralla.php" class="dropdown-link">Pedido Morralla</a></li>
                <li><a href="historico.php" class="dropdown-link">Envio Toner</a></li>
                <li><a href="bitacoraMayoristas.php" class="dropdown-link">Bitácora Proveedores</a></li>
                <li><a href="bitacoravalores.php" class="dropdown-link">Entrega de Valores</a></li>
                <li><a href="plantillatiendas.php" class="dropdown-link">Plantilla</a></li>
                <li><a href="./proyecto/index.php" class="dropdown-link">Reporte de Ventas</a></li>
              </ul>
            </li>
          </ul>
        </div>

        <!-- (3) Sección Derecha: Notificaciones + Botones -->
        <div class="nav-right">
          <!-- Panel de notificaciones -->
          <!-- Panel de notificaciones -->
          <li class="nav-item notification-item">
            <a href="#" id="notificationBell" class="nav-link nav-notification">
              <i class="fa fa-bell"></i>
              <!-- Mostramos el conteo de productos validados hoy -->
              <span class="notification-badge" id="notificationCount">
                <?php echo ($totalProductos > 0) ? $totalProductos : '0'; ?>
              </span>
            </a>
            <div class="notification-dropdown" id="notificationDropdown">
              <p class="dropdown-header">Notificaciones</p>
              <hr>
              <?php if ($totalProductos > 0): ?>
                <ul class="notifications-list">
                  <li>
                    <div class="notification-title">Altas de Productos</div>
                    <div class="notification-content">
                      Hoy se han validado <strong><?php echo $totalProductos; ?></strong> productos.
                    </div>
                    <button id="aceptarNoticia" style="margin-top: 10px; cursor:pointer;">Aceptar</button>
                  </li>
                </ul>
                <script>
                  // Cuando el usuario hace clic en "Aceptar"
                  document.getElementById('aceptarNoticia')?.addEventListener('click', function() {
                    fetch('aceptar_noticia.php')
                      .then(response => response.text())
                      .then(data => {
                        // Actualizamos la vista del dropdown
                        document.getElementById('notificationDropdown').innerHTML =
                          '<p class="dropdown-header">Notificaciones</p><hr>' +
                          '<p>Notificación aceptada. Actualizando...</p>';
                        // Recargar la página tras 2 seg
                        setTimeout(function() {
                          location.reload();
                        }, 2000);
                      })
                      .catch(error => console.error('Error:', error));
                  });
                </script>
              <?php else: ?>
                <!-- Si no hay notificaciones, mostramos un mensaje genérico -->
                <p class="no-notifications">Sin notificaciones por ahora</p>
              <?php endif; ?>
            </div>
          </li>


          <div class="hamburger-menu">
            <button class="hamburger-button" id="hamburgerButton">
              <i class="fa fa-bars"></i>
            </button>
            <div class="hamburger-dropdown" id="hamburgerDropdown">
              <a href="consultaregistrost.php" class="botonuser" target="contenido">Consultar Personal</a>
              <a href="salir.php" class="nav-logout">Cerrar sesión</a>
            </div>
          </div>
        </div> <!-- fin .nav-container -->
      </div>
  </nav>
  <!-- Archivo JavaScript para menú responsive -->
  <script src="js/menutiendas.js"></script>
</body>

</html>