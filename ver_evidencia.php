<?php
@session_start();
require 'conexionn.php';

// Seguridad mínima
if (empty($_SESSION['usuarioactual'])) { http_response_code(403); exit; }

// Raíz permitida para servir imágenes
$SAFE_ROOT = rtrim(str_replace('\\','/','D:/Evidencias Mantenimiento'), '/');

// Detección MIME por extensión simple
function guess_mime($path){
  $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
  if (in_array($ext, ['jpg','jpeg'])) return 'image/jpeg';
  if ($ext === 'png') return 'image/png';
  if ($ext === 'gif') return 'image/gif';
  if ($ext === 'webp') return 'image/webp';
  if ($ext === 'bmp') return 'image/bmp';
  return 'application/octet-stream';
}

$path = null;

// Caso 1: imagen principal de mantto_reportes
if (isset($_GET['id']) && isset($_GET['i']) && $_GET['i'] === 'main') {
  $id = (int)$_GET['id'];
  if ($st = $mysqli->prepare("SELECT evidencia_path FROM mantto_reportes WHERE id_reporte=?")) {
    $st->bind_param('i', $id);
    $st->execute();
    $st->bind_result($p);
    if ($st->fetch()) $path = $p;
    $st->close();
  }
}
// Caso 2: imagen extra en mantto_evidencias
elseif (isset($_GET['ev'])) {
  $ev = (int)$_GET['ev'];
  $tbl = $mysqli->query("SELECT 1 FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name='mantto_evidencias' LIMIT 1");
  if ($tbl && $tbl->num_rows === 1) {
    if ($q = $mysqli->prepare("SELECT path FROM mantto_evidencias WHERE id=?")) {
      $q->bind_param('i', $ev);
      $q->execute();
      $q->bind_result($p);
      if ($q->fetch()) $path = $p;
      $q->close();
    }
  }
}
// Caso 3: path directo codificado (fallback de escaneo)
elseif (isset($_GET['p'])) {
  $p64 = $_GET['p'];
  $raw = base64_decode(strtr($p64, '-_', '+/'), true);
  if ($raw !== false) {
    $candidate = str_replace('\\','/',$raw);
    // Validar que esté bajo SAFE_ROOT
    $real = str_replace('\\','/', realpath($candidate));
    if ($real && strpos($real, $SAFE_ROOT) === 0) {
      $path = $real;
    }
  }
}

// Validación final
if (!$path || !is_file($path)) { http_response_code(404); exit; }

$mime = guess_mime($path);
header('Content-Type: '.$mime);
header('Content-Length: '.filesize($path));
header('Cache-Control: private, max-age=86400');
readfile($path);
