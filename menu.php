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
              <a href="#" class="nav-link">Mantenimiento</a>
              <ul class="dropdown-menu">
                <li><a href="mantto_admin_alertas.php" class="dropdown-link">Emergencias (Alertas)</a></li>
                <li><a href="mantto_admin_eventos.php" class="dropdown-link">Eventos (Áreas/Subáreas)</a></li>
              </ul>
            </li>
            <li class="nav-item dropdown">
              <a href="#" class="nav-link">Reposiciones</a>
              <ul class="dropdown-menu">
                <li><a href="rep_reposiciones_reporte.php" class="dropdown-link">Reporte de Reposiciones</a></li>
                <li><a href="rep_reposiciones_captura.php" class="dropdown-link">Capturar Reposicion</a></li>
                <li><a href="rep_inventario_admin.php" class="dropdown-link">Inventario</a></li>
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
<?php
// ====== LISTA DE REPORTES (panel de inicio) ======
$reportes = array();

/* 1) Verificar si existe la tabla mantto_evidencias */
$hasEvid = false;
if ($chk = $mysqli->query("
  SELECT 1
  FROM information_schema.tables
  WHERE table_schema = DATABASE()
    AND table_name   = 'mantto_evidencias'
  LIMIT 1
")) {
  $hasEvid = (bool)$chk->num_rows;
  $chk->free();
}

/* 2) SQL según exista o no mantto_evidencias
      Reglas:
      - Si hay extras => fotos = COUNT(extras)
      - Si NO hay extras => fotos = (evidencia_path ? 1 : 0)
      Además: traemos Sucursal (desde usuarios.sucursal por clave_suc), Área y Subárea por nombre.
*/
if ($hasEvid) {
  // m.cnt será NULL cuando no existan extras para ese reporte (LEFT JOIN)
  $sql = "
    SELECT
      r.id_reporte AS id,
      r.folio,
      r.fecha_alta,
      r.empleado,
      r.descripcion,
      r.prioridad,
      r.id_alerta,
      r.evidencia_path,
      u.sucursal AS sucursal_nombre,
      a.nombre  AS area_nombre,
      sa.nombre AS subarea_nombre,
      IFNULL(m.cnt, CASE WHEN r.evidencia_path IS NULL OR r.evidencia_path = '' THEN 0 ELSE 1 END) AS fotos
    FROM mantto_reportes r
    /* nombre de sucursal a partir de clave_suc (puede haber varios usuarios por sucursal, tomamos MAX) */
    LEFT JOIN (
      SELECT clave_suc, MAX(sucursal) AS sucursal
      FROM usuarios
      GROUP BY clave_suc
    ) u ON u.clave_suc = r.clave_suc
    LEFT JOIN mantto_areas    a  ON a.id_area     = r.id_area
    LEFT JOIN mantto_subareas sa ON sa.id_subarea = r.id_subarea
    LEFT JOIN (
      SELECT reporte_id, COUNT(*) AS cnt
      FROM mantto_evidencias
      GROUP BY reporte_id
    ) m ON m.reporte_id = r.id_reporte
    ORDER BY r.fecha_alta DESC
    LIMIT 100
  ";
} else {
  // No hay tabla de extras: cuenta solo la principal
  $sql = "
    SELECT
      r.id_reporte AS id,
      r.folio,
      r.fecha_alta,
      r.empleado,
      r.descripcion,
      r.prioridad,
      r.id_alerta,
      r.evidencia_path,
      u.sucursal AS sucursal_nombre,
      a.nombre  AS area_nombre,
      sa.nombre AS subarea_nombre,
      (CASE WHEN r.evidencia_path IS NULL OR r.evidencia_path = '' THEN 0 ELSE 1 END) AS fotos
    FROM mantto_reportes r
    LEFT JOIN (
      SELECT clave_suc, MAX(sucursal) AS sucursal
      FROM usuarios
      GROUP BY clave_suc
    ) u ON u.clave_suc = r.clave_suc
    LEFT JOIN mantto_areas    a  ON a.id_area     = r.id_area
    LEFT JOIN mantto_subareas sa ON sa.id_subarea = r.id_subarea
    ORDER BY r.fecha_alta DESC
    LIMIT 100
  ";
}


/* 3) Ejecutar y llenar $reportes (NO sobreescribas 'fotos' después) */
$res = $mysqli->query($sql);
if ($res === false) {
  echo '<div style="margin:90px 16px 0;padding:8px 12px;border:1px solid #f5c6cb;background:#f8d7da;color:#721c24;border-radius:8px">';
  echo 'Error SQL (menu.php): '.htmlspecialchars($mysqli->error).'</div>';
} else {
  while ($row = $res->fetch_assoc()) {
    // IMPORTANTE: no recalcules $row["fotos"] aquí
    $reportes[] = $row;
  }
  $res->free();
}
?>



<main class="rep-wrap">
  <section class="rep-card">
    <div class="rep-head">
      <h2>Reportes de Mantenimiento</h2>
      <span class="rep-sub">Últimos 100 reportes</span>
    </div>

    <div class="rep-table-wrap">
      <table class="rep-table">
<thead>
  <tr>
    <th>Fecha/Hora</th>
    <th>Folio</th>
    <th>Sucursal</th>
    <th>Área</th>
    <th>Subárea</th>
    <th>Prioridad</th>
    <th>Empleado</th>
    <th>Descripción</th>
    <th>Imágenes</th>
    <th>Asignar</th>
  </tr>
</thead>

        <tbody>
          <?php if (empty($reportes)): ?>
            <tr><td colspan="7" class="rep-empty">No hay reportes aún.</td></tr>
          <?php else: foreach ($reportes as $r): ?>
<tr>
  <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($r['fecha_alta'])), ENT_QUOTES, 'UTF-8') ?></td>
  <td><?= htmlspecialchars($r['folio'], ENT_QUOTES, 'UTF-8') ?></td>
  <td><?= htmlspecialchars($r['sucursal_nombre'] ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
  <td><?= htmlspecialchars($r['area_nombre'] ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
  <td><?= htmlspecialchars($r['subarea_nombre'] ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
  <td>
    <?php
      $prio = strtoupper($r['prioridad'] ?: 'MEDIA');
      $cls  = ($prio === 'ALTA') ? 'prio-alta' : 'prio-normal';
    ?>
    <span class="rep-prio <?= $cls ?>"><?= htmlspecialchars($prio, ENT_QUOTES, 'UTF-8') ?></span>
  </td>
  <td><?= htmlspecialchars($r['empleado'], ENT_QUOTES, 'UTF-8') ?></td>
  <td class="rep-desc"><?= htmlspecialchars($r['descripcion'], ENT_QUOTES, 'UTF-8') ?></td>
  <td>
    <button class="rep-btn rep-btn-img"
            data-id="<?= (int)$r['id'] ?>"
            title="Ver imágenes">
      <i class="fa fa-picture-o"></i> (<?= (int)$r['fotos'] ?>)
    </button>
  </td>
  <td>
    <button class="rep-btn rep-btn-assign"
            data-id="<?= (int)$r['id'] ?>"
            data-folio="<?= htmlspecialchars($r['folio'], ENT_QUOTES, 'UTF-8') ?>"
            title="Asignar responsable">
      <i class="fa fa-user-plus"></i>
    </button>
  </td>
</tr>

          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>

<!-- ===== Modales ===== -->
<div class="rep-modal" id="modalImgs" aria-hidden="true">
  <div class="rep-modal__overlay" data-close="1"></div>
  <div class="rep-modal__content">
    <div class="rep-modal__head">
      <h3>Imágenes del reporte</h3>
      <button class="rep-modal__close" data-close="1" title="Cerrar">&times;</button>
    </div>
    <div class="rep-gallery" id="repGallery"></div>
  </div>
</div>

<div class="rep-modal" id="modalAssign" aria-hidden="true">
  <div class="rep-modal__overlay" data-close="1"></div>
  <div class="rep-modal__content">
    <div class="rep-modal__head">
      <h3>Asignar responsable</h3>
      <button class="rep-modal__close" data-close="1" title="Cerrar">&times;</button>
    </div>
    <form id="assignForm" class="rep-form">
      <input type="hidden" name="reporte_id" id="assignReporteId">
      <div class="rep-form-row">
        <label>Folio</label>
        <input type="text" id="assignFolio" readonly>
      </div>
      <div class="rep-form-row">
        <label>Responsable</label>
        <input type="text" name="responsable" id="assignResp" placeholder="Nombre del responsable" required>
      </div>
      <div class="rep-form-row">
        <label>Comentario (opcional)</label>
        <textarea name="comentario" id="assignComent" rows="3" placeholder="Indicaciones o notas"></textarea>
      </div>
      <div class="rep-actions">
        <button type="submit" class="rep-btn rep-btn-primary"><i class="fa fa-check"></i> Asignar</button>
        <button type="button" class="rep-btn" data-close="1">Cancelar</button>
      </div>
    </form>
  </div>
</div>


  <!-- Archivo JavaScript para menú responsive -->
  <script src="js/menutiendas.js"></script>
<script>
// ===== Helpers modal =====
function openModal(id){ var m=document.getElementById(id); if(!m) return; m.setAttribute('aria-hidden','false'); }
function closeModal(id){ var m=document.getElementById(id); if(!m) return; m.setAttribute('aria-hidden','true'); }

document.addEventListener('click', function(ev){
  var t = ev.target;
  if (t.dataset && t.dataset.close) {
    closeModal(t.closest('.rep-modal').id);
  }
});

// ===== Ver imágenes: carga por AJAX (rutas robustas) =====
document.addEventListener('click', function(ev){
  var btn = ev.target.closest('.rep-btn-img');
  if (!btn) return;

  var rid = btn.getAttribute('data-id');
  var gallery = document.getElementById('repGallery');
  gallery.innerHTML = '<p style="padding:8px;">Cargando imágenes...</p>';
  openModal('modalImgs');

  // Base del path actual (por si menu.php está en subcarpetas)
  var base = location.pathname.replace(/[^\/]+$/, '');

  fetch(base + 'mantto_get_evidencias.php?id=' + encodeURIComponent(rid), {
    cache:'no-store',
    credentials:'same-origin'
  })
    .then(function(r){ return r.json(); })
    .then(function(j){
      if (!j || !j.ok) {
        gallery.innerHTML = '<p style="padding:8px;color:#b91c1c;">Sin sesión o error al cargar.</p>';
        return;
      }
      if (!j.imagenes || !j.imagenes.length) {
        gallery.innerHTML = '<p style="padding:8px;">Sin imágenes para este reporte.</p>';
        return;
      }

      gallery.innerHTML = '';

      j.imagenes.forEach(function(img){
        // Contenedor uniforme con aspect-ratio
        var fig = document.createElement('figure');
        fig.className = 'rep-thumb';

        var el = document.createElement('img');
        el.src = base + img.url;          // ruta resuelta
        el.alt = img.nombre || 'evidencia';
        el.loading = 'lazy';
        el.decoding = 'async';
        el.onerror = function(){
          fig.innerHTML = '<div style="font-size:12px;color:#b91c1c;padding:6px;text-align:center;">No se pudo cargar</div>';
        };

        // Si quieres que al hacer click abra la imagen en grande en otra pestaña:
        fig.style.cursor = 'zoom-in';
        fig.addEventListener('click', function(){
          window.open(el.src, '_blank');
        });

        fig.appendChild(el);
        gallery.appendChild(fig);
      });
    })
    .catch(function(){
      gallery.innerHTML = '<p style="padding:8px;color:#b91c1c;">Error de red al cargar imágenes.</p>';
    });
});
</script>
<script>
(function setDataLabels(){
  var table = document.querySelector('.rep-table');
  if (!table) return;
  var headers = Array.prototype.map.call(
    table.querySelectorAll('thead th'),
    function(th){ return th.textContent.trim(); }
  );
  table.querySelectorAll('tbody tr').forEach(function(tr){
    Array.prototype.forEach.call(tr.children, function(td, idx){
      td.setAttribute('data-label', headers[idx] || '');
    });
  });
})();
</script>


</body>

</html>