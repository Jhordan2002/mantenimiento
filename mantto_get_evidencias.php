<?php
@session_start();
require 'conexionn.php';
header('Content-Type: application/json; charset=utf-8');

// Seguridad mínima
if (empty($_SESSION['usuarioactual'])) {
  echo json_encode(['ok'=>false, 'imagenes'=>[], 'msg'=>'no-session']); exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$SAFE_ROOT = rtrim(str_replace('\\','/','D:/Evidencias Mantenimiento'), '/');

function normpath($p){
  $r = realpath($p);
  if ($r === false) return false;
  return str_replace('\\','/',$r);
}

$mainPath = null;
$extras   = [];     // [ev_id => path]
$byPath   = [];     // de-dupe por ruta real (lowercase)
$out      = [];     // salida final

// 1) Principal desde mantto_reportes
if ($id > 0) {
  if ($st = $mysqli->prepare("SELECT evidencia_path FROM mantto_reportes WHERE id_reporte=?")) {
    $st->bind_param('i', $id);
    $st->execute();
    $st->bind_result($p);
    if ($st->fetch()) $mainPath = $p;
    $st->close();
  }
}
if ($mainPath && is_file($mainPath)) {
  $rp = normpath($mainPath);
  if ($rp && strpos($rp, $SAFE_ROOT) === 0) {
    $byPath[strtolower($rp)] = ['url' => 'ver_evidencia.php?id='.$id.'&i=main', 'nombre' => basename($rp), 'path'=>$rp];
  }
}

// 2) Extras desde mantto_evidencias (si existe)
$tbl = $mysqli->query("SELECT 1 FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name='mantto_evidencias' LIMIT 1");
if ($tbl && $tbl->num_rows === 1) {
  if ($q = $mysqli->prepare("SELECT id, path FROM mantto_evidencias WHERE reporte_id=? ORDER BY id ASC")) {
    $q->bind_param('i', $id);
    $q->execute();
    $r = $q->get_result();
    while ($row = $r->fetch_assoc()) {
      $p = $row['path'];
      if (!$p || !is_file($p)) continue;
      $rp = normpath($p);
      if (!$rp || strpos($rp, $SAFE_ROOT) !== 0) continue;
      $k = strtolower($rp);
      // si ya está (por ser igual a la principal), no lo dupliques
      if (!isset($byPath[$k])) {
        $byPath[$k] = ['url' => 'ver_evidencia.php?ev='.$row['id'], 'nombre' => basename($rp), 'path'=>$rp];
      }
    }
  }
}

// 3) Fallback: escanear carpeta SOLO si aún no encontramos nada
if (count($byPath) === 0) {
  // Intentar deducir carpeta: si hay principal usa esa, de lo contrario nada que hacer
  if ($mainPath && is_file($mainPath)) {
    $dir = dirname($mainPath);
  } else {
    $dir = null;
  }

  if ($dir) {
    $dir = rtrim(str_replace('\\','/',$dir), '/');
    $all = [];
    foreach (['jpg','jpeg','png','gif','bmp','webp'] as $ext) {
      $tmp = glob($dir.'/*.'.$ext, GLOB_NOSORT);
      if (is_array($tmp)) $all = array_merge($all, $tmp);
    }
    sort($all);
    foreach ($all as $file) {
      if (!is_file($file)) continue;
      $rp = normpath($file);
      if (!$rp || strpos($rp, $SAFE_ROOT) !== 0) continue;
      $k = strtolower($rp);
      if (isset($byPath[$k])) continue;
      $p64 = rtrim(strtr(base64_encode($rp), '+/', '-_'), '=');
      $byPath[$k] = ['url' => 'ver_evidencia.php?p='.$p64, 'nombre' => basename($rp), 'path'=>$rp];
    }
  }
}

// 4) Salida
foreach ($byPath as $item) {
  $out[] = ['url'=>$item['url'], 'nombre'=>$item['nombre']];
}
echo json_encode(['ok'=>true, 'imagenes'=>$out], JSON_UNESCAPED_UNICODE);
