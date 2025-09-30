<?php
/* mantto_admin_eventos.php – Admin de EVENTOS (Áreas / Subáreas)
 * PHP 5.6 + mysqli. CRUD: agregar, activar/desactivar, eliminar.
 * Sin columnas de fechas. Versión “compacta” (tamaños reducidos).
 */
@session_start();
require 'conexionn.php';
include 'seguridad.php';
$mysqli->set_charset('utf8');

if (empty($_SESSION['usuarioactual'])) {
    header('Location: login.php');
    exit;
}

function html_escape($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function get_csrf_token(array &$session, $key = 'csrf_eventos')
{
    if (!empty($session[$key])) return $session[$key];
    $session[$key] = function_exists('openssl_random_pseudo_bytes')
        ? bin2hex(openssl_random_pseudo_bytes(16))
        : sha1(uniqid(mt_rand(), true));
    return $session[$key];
}
function audit_log(mysqli $mysqli, $usuario, $entity, $entityId, $action, $details = '', $remoteAddr = '', $userAgent = '')
{
    $stmt = $mysqli->prepare(
        "INSERT INTO mantto_bitacora (usuario, entidad, entidad_id, accion, detalles, ip, user_agent, fecha)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('ssissss', $usuario, $entity, $entityId, $action, $details, $remoteAddr, $userAgent);
    $stmt->execute();
    $stmt->close();
}


$csrf = get_csrf_token($_SESSION, 'csrf_eventos');
$remoteAddr = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
$userAgent  = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';


// Leer flash messages desde la sesión (y consumarlas)
$msg = '';
$err = '';
if (isset($_SESSION['flash_msg'])) {
    $msg = (string)$_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}
if (isset($_SESSION['flash_err'])) {
    $err = (string)$_SESSION['flash_err'];
    unset($_SESSION['flash_err']);
}

$area_id = isset($_GET['area_id']) ? (int)$_GET['area_id'] : 0;

/* -------------------------
   POST: acciones CRUD
--------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $token  = isset($_POST['csrf']) ? $_POST['csrf'] : '';
    $r_msg = '';
    $r_err = '';
    $redir_params = array();

    if ($token !== $csrf) {
        $r_err = 'Token inválido. Recarga la página.';
    } else {
        if ($action === 'add_area') {
            $nombre = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';
            if ($nombre === '') {
                $r_err = 'Escribe el nombre del área.';
            } else {
                $st = $mysqli->prepare("SELECT 1 FROM mantto_areas WHERE nombre=? LIMIT 1");
                if ($st) {
                    $st->bind_param('s', $nombre);
                    if ($st->execute()) {
                        $st->store_result();
                        if ($st->num_rows > 0) {
                            $r_err = 'Ya existe un área con ese nombre.';
                        } else {
                            $st->close();
                            $ins = $mysqli->prepare("INSERT INTO mantto_areas (nombre, activo) VALUES (?, 1)");
                            if ($ins) {
                                $ins->bind_param('s', $nombre);
                                if ($ins->execute()) {
                                    $r_msg = 'Área agregada.';
                                    $new_id = $ins->insert_id;
                                    audit_log(
                                        $mysqli,
                                        $_SESSION['usuarioactual'],
                                        'area',
                                        (int)$new_id,
                                        'agregar',
                                        json_encode(array(
                                            'id_area' => (int)$new_id,
                                            'nombre'  => $nombre
                                        ), JSON_UNESCAPED_UNICODE),
                                        $remoteAddr,
                                        $userAgent
                                    );
                                } else {
                                    $r_err = 'Error al agregar área: ' . $ins->error;
                                }
                                $ins->close();
                            } else {
                                $r_err = 'Error al preparar INSERT área: ' . $mysqli->error;
                            }
                        }
                    } else {
                        $r_err = 'Error al ejecutar SELECT área: ' . $st->error;
                    }
                    $st && $st->close();
                    $st = null;
                } else {
                    $r_err = 'Error al preparar SELECT área: ' . $mysqli->error;
                }
            }
        }

        if ($action === 'toggle_area') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($id > 0) {
                $areaNombre = null;
                $prev = null;
                if ($s0 = $mysqli->prepare("SELECT nombre, activo FROM mantto_areas WHERE id_area=? LIMIT 1")) {
                    $s0->bind_param('i', $id);
                    if ($s0->execute()) {
                        $s0->bind_result($areaNombre, $prev);
                        $s0->fetch();
                    }
                    $s0->close();
                }
                $accionLog = ((int)$prev === 1) ? 'desactivar' : 'activar';

                if ($st = $mysqli->prepare("UPDATE mantto_areas SET activo=IF(activo=1,0,1) WHERE id_area=? LIMIT 1")) {
                    $st->bind_param('i', $id);
                    if ($st->execute()) {
                        $r_msg = 'Área actualizada.';
                        $details = json_encode(array(
                            'nombre'      => $areaNombre,
                            'prev_activo' => (int)$prev
                        ), JSON_UNESCAPED_UNICODE);
                        audit_log($mysqli, $_SESSION['usuarioactual'], 'area', (int)$id, $accionLog, $details, $remoteAddr, $userAgent);
                    } else {
                        $r_err = 'Error al actualizar área: ' . $st->error;
                    }
                    $st->close();
                } else {
                    $r_err = 'Error al preparar UPDATE área: ' . $mysqli->error;
                }
            }
        }



        if ($action === 'delete_area') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($id > 0) {
                // 1) Obtener info del área ANTES de borrar (para bitácora)
                $areaNombre = null;
                $areaActivo = null;
                if ($sInfo = $mysqli->prepare("SELECT nombre, activo FROM mantto_areas WHERE id_area=? LIMIT 1")) {
                    $sInfo->bind_param('i', $id);
                    if ($sInfo->execute()) {
                        $sInfo->bind_result($areaNombre, $areaActivo);
                        $sInfo->fetch();
                    }
                    $sInfo->close();
                }

                // 2) Verificar si tiene subáreas
                $tiene = 0;
                if ($st = $mysqli->prepare("SELECT COUNT(*) FROM mantto_subareas WHERE id_area=?")) {
                    $st->bind_param('i', $id);
                    if ($st->execute()) {
                        $st->bind_result($tiene);
                        $st->fetch();
                    }
                    $st->close();
                }

                if ($tiene > 0) {
                    $r_err = 'No se puede eliminar: el área tiene subáreas.';
                } else {
                    // 3) Eliminar
                    if ($del = $mysqli->prepare("DELETE FROM mantto_areas WHERE id_area=? LIMIT 1")) {
                        $del->bind_param('i', $id);
                        if ($del->execute()) {
                            $r_msg = 'Área eliminada.';
                            // 4) Enviar detalles reales a bitácora
                            $details = json_encode(
                                array(
                                    'id_area' => $id,
                                    'nombre'  => $areaNombre,
                                    'activo'  => (int)$areaActivo
                                ),
                                JSON_UNESCAPED_UNICODE
                            );
                            audit_log($mysqli, $_SESSION['usuarioactual'], 'area', (int)$id, 'eliminar', $details, $remoteAddr, $userAgent);
                        } else {
                            $r_err = 'Error al eliminar área: ' . $del->error;
                        }
                        $del->close();
                    } else {
                        $r_err = 'Error al preparar DELETE área: ' . $mysqli->error;
                    }
                }
            }
        }


        if ($action === 'add_subarea') {
            $id_area = isset($_POST['id_area']) ? (int)$_POST['id_area'] : 0;
            $nombre  = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';
            $redir_params['area_id'] = $id_area;

            if ($id_area <= 0) {
                $r_err = 'Selecciona un área válida.';
            } elseif ($nombre === '') {
                $r_err = 'Escribe el nombre de la subárea.';
            } else {
                // ¿Existe ya esa subárea en la misma área?
                $st = $mysqli->prepare("SELECT 1 FROM mantto_subareas WHERE id_area=? AND nombre=? LIMIT 1");
                if ($st) {
                    $st->bind_param('is', $id_area, $nombre);
                    if ($st->execute()) {
                        $st->store_result();
                        if ($st->num_rows > 0) {
                            $r_err = 'Ya existe esa subárea en el área seleccionada.';
                        } else {
                            // Insertar subárea
                            $st->close();
                            $ins = $mysqli->prepare("INSERT INTO mantto_subareas (id_area, nombre, activo) VALUES (?, ?, 1)");
                            if ($ins) {
                                $ins->bind_param('is', $id_area, $nombre);
                                if ($ins->execute()) {
                                    $r_msg = 'Subárea agregada.';
                                    $new_id = $ins->insert_id;

                                    // Obtener nombre del área para guardar un detalle entendible
                                    $areaNombre = null;
                                    if ($q = $mysqli->prepare("SELECT nombre FROM mantto_areas WHERE id_area=? LIMIT 1")) {
                                        $q->bind_param('i', $id_area);
                                        if ($q->execute()) {
                                            $q->bind_result($areaNombre);
                                            $q->fetch();
                                        }
                                        $q->close();
                                    }

                                    // Detalle "humano" para la bitácora
                                    $details = json_encode(array(
                                        'id_area'        => (int)$id_area,
                                        'area_nombre'    => $areaNombre,
                                        'subarea_nombre' => $nombre
                                    ), JSON_UNESCAPED_UNICODE);

                                    audit_log(
                                        $mysqli,
                                        $_SESSION['usuarioactual'],
                                        'subarea',
                                        (int)$new_id,
                                        'agregar',
                                        $details,
                                        $remoteAddr,
                                        $userAgent
                                    );
                                } else {
                                    $r_err = 'Error al agregar subárea: ' . $ins->error;
                                }
                                $ins->close();
                            } else {
                                $r_err = 'Error al preparar INSERT subárea: ' . $mysqli->error;
                            }
                        }
                    } else {
                        $r_err = 'Error al ejecutar SELECT subárea: ' . $st->error;
                    }
                    // Cerrar el SELECT si aún está abierto
                    if ($st) {
                        $st->close();
                    }
                } else {
                    $r_err = 'Error al preparar SELECT subárea: ' . $mysqli->error;
                }
            }
        }


        if ($action === 'toggle_subarea') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $id_area = isset($_POST['id_area']) ? (int)$_POST['id_area'] : 0;
            $redir_params['area_id'] = $id_area;
            if ($id > 0) {
                $prev = null;
                $subNombre = null;
                $areaNombre = null;
                $areaFromRow = null;
                if ($s0 = $mysqli->prepare("SELECT nombre, id_area, activo FROM mantto_subareas WHERE id_subarea=? LIMIT 1")) {
                    $s0->bind_param('i', $id);
                    if ($s0->execute()) {
                        $s0->bind_result($subNombre, $areaFromRow, $prev);
                        $s0->fetch();
                    }
                    $s0->close();
                }
                // asegurar id_area
                if ($id_area <= 0 && $areaFromRow) $id_area = (int)$areaFromRow;
                if ($q = $mysqli->prepare("SELECT nombre FROM mantto_areas WHERE id_area=? LIMIT 1")) {
                    $q->bind_param('i', $id_area);
                    if ($q->execute()) {
                        $q->bind_result($areaNombre);
                        $q->fetch();
                    }
                    $q->close();
                }

                $accionLog = ((int)$prev === 1) ? 'desactivar' : 'activar';

                if ($st = $mysqli->prepare("UPDATE mantto_subareas SET activo=IF(activo=1,0,1) WHERE id_subarea=? LIMIT 1")) {
                    $st->bind_param('i', $id);
                    if ($st->execute()) {
                        $r_msg = 'Subárea actualizada.';
                        $details = json_encode(array(
                            'prev_activo'    => (int)$prev,
                            'id_area'        => (int)$id_area,
                            'area_nombre'    => $areaNombre,
                            'subarea_nombre' => $subNombre
                        ), JSON_UNESCAPED_UNICODE);
                        audit_log($mysqli, $_SESSION['usuarioactual'], 'subarea', (int)$id, $accionLog, $details, $remoteAddr, $userAgent);
                    } else {
                        $r_err = 'Error al actualizar subárea: ' . $st->error;
                    }
                    $st->close();
                } else {
                    $r_err = 'Error al preparar UPDATE subárea: ' . $mysqli->error;
                }
            }
        }



        if ($action === 'delete_subarea') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $id_area = isset($_POST['id_area']) ? (int)$_POST['id_area'] : 0;
            $redir_params['area_id'] = $id_area;

            if ($id > 0) {
                // capturar nombres antes de borrar
                $subNombre = null;
                $areaNombre = null;
                $areaFromRow = null;
                if ($si = $mysqli->prepare("SELECT nombre, id_area FROM mantto_subareas WHERE id_subarea=? LIMIT 1")) {
                    $si->bind_param('i', $id);
                    if ($si->execute()) {
                        $si->bind_result($subNombre, $areaFromRow);
                        $si->fetch();
                    }
                    $si->close();
                }
                if ($id_area <= 0 && $areaFromRow) $id_area = (int)$areaFromRow;
                if ($q = $mysqli->prepare("SELECT nombre FROM mantto_areas WHERE id_area=? LIMIT 1")) {
                    $q->bind_param('i', $id_area);
                    if ($q->execute()) {
                        $q->bind_result($areaNombre);
                        $q->fetch();
                    }
                    $q->close();
                }

                if ($del = $mysqli->prepare("DELETE FROM mantto_subareas WHERE id_subarea=? LIMIT 1")) {
                    $del->bind_param('i', $id);
                    if ($del->execute()) {
                        $r_msg = 'Subárea eliminada.';
                        $details = json_encode(array(
                            'id_area'        => (int)$id_area,
                            'area_nombre'    => $areaNombre,
                            'subarea_nombre' => $subNombre
                        ), JSON_UNESCAPED_UNICODE);
                        audit_log($mysqli, $_SESSION['usuarioactual'], 'subarea', (int)$id, 'eliminar', $details, $remoteAddr, $userAgent);
                    } else {
                        $r_err = 'Error al eliminar subárea: ' . $del->error;
                    }
                    $del->close();
                } else {
                    $r_err = 'Error al preparar DELETE subárea: ' . $mysqli->error;
                }
            }
        }
    }

    // Guardar como flash (una sola vez)
    $_SESSION['flash_msg'] = $r_msg;
    $_SESSION['flash_err'] = $r_err;

    // Redirige solo con los params necesarios (p.ej. area_id), sin msg/err
    $qs = $redir_params ? '?' . http_build_query($redir_params) : '';
    header('Location: mantto_admin_eventos.php' . $qs, true, 303); // 303 See Other
    exit;
}

/* -------------------------
   GET: cargar catálogos
--------------------------*/
$areas = array();
$st = $mysqli->prepare("SELECT id_area, nombre, activo FROM mantto_areas ORDER BY nombre ASC");
if ($st) {
    if ($st->execute()) {
        $st->bind_result($a_id, $a_nom, $a_act);
        while ($st->fetch()) {
            $areas[] = array('id_area' => $a_id, 'nombre' => $a_nom, 'activo' => $a_act);
        }
    } else {
        $err = 'Error al listar áreas: ' . $st->error;
    }
    $st->close();
} else {
    $err = 'Error al preparar listado de áreas: ' . $mysqli->error;
}


if ($area_id <= 0 && !empty($areas)) {
    $area_id = (int)$areas[0]['id_area'];
}

$subareas = array();
if ($area_id > 0) {
    $st = $mysqli->prepare("SELECT id_subarea, nombre, activo FROM mantto_subareas WHERE id_area=? ORDER BY nombre ASC");
    if ($st) {
        $st->bind_param('i', $area_id);
        if ($st->execute()) {
            $st->bind_result($s_id, $s_nom, $s_act);
            while ($st->fetch()) {
                $subareas[] = array('id_subarea' => $s_id, 'nombre' => $s_nom, 'activo' => $s_act);
            }
        } else {
            $err = 'Error al listar subáreas: ' . $st->error;
        }
        $st->close();
    } else {
        $err = 'Error al preparar listado de subáreas: ' . $mysqli->error;
    }
}
/* -------------------------
   AUDIT: últimos movimientos
--------------------------*/
$auditRows = array();

if ($stmt = $mysqli->prepare("
    SELECT fecha, usuario, entidad, entidad_id, accion, detalles, ip
    FROM mantto_bitacora
    WHERE entidad IN ('area','subarea')
    ORDER BY fecha DESC
    LIMIT 100
")) {
    if ($stmt->execute()) {
        $stmt->bind_result($b_fecha, $b_usuario, $b_entidad, $b_entidad_id, $b_accion, $b_detalles, $b_ip);
        while ($stmt->fetch()) {
            $auditRows[] = array(
                'fecha'      => $b_fecha,
                'usuario'    => $b_usuario,
                'entidad'    => $b_entidad,
                'entidad_id' => (int)$b_entidad_id,
                'accion'     => $b_accion,
                'detalles'   => $b_detalles,
                'ip'         => $b_ip,
            );
        }
    } else {
        // opcional: muestra el error arriba con tu barra .msg
        $err .= ' | Error historial (exec): ' . $stmt->error;
    }
    $stmt->close();
} else {
    $err .= ' | Error historial (prepare): ' . $mysqli->error;
}


?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Admin · Eventos (Áreas/Subáreas)</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">

    <style>
        /* ======= Estilo COMPACTO ======= */
        :root {
            --bg: #f7f8fb;
            --card: #ffffff;
            --line: #e6e7eb;
            --txt: #1f2937;
            --muted: #6b7280;
            --primary: #b71c1c;
            --ok-bg: #eefcf5;
            --ok-bd: #b7f0d2;
            --ok-tx: #065f46;
            --er-bg: #fff3f3;
            --er-bd: #fecaca;
            --er-tx: #991b1b;
            --shadow: 0 4px 12px rgba(0, 0, 0, .06);
            --radius: 8px;
            --fs-base: 14px;
            /* tamaño base reducido */
            --fs-title: 16px;
            /* h2 */
            --fs-h1: 15px;
            /* header */
            --pad-card: 12px;
            /* padding card */
            --pad-cell: 8px;
            /* celdas tabla */
            --pad-input: 8px 10px;
            /* inputs */
            --pad-btn: 6px 10px;
            /* botones */
        }

        * {
            box-sizing: border-box
        }

        html,
        body {
            font-size: var(--fs-base)
        }

        body {
            font-family: 'Segoe UI', Tahoma, sans-serif;
            background: var(--bg);
            color: var(--txt);
            margin: 0;
            padding: 0;
        }

        header {
            background: linear-gradient(145deg, #e53935 0%, #b71c1c 100%);
            color: #fff;
            padding: 10px 12px;
            text-align: center;
            box-shadow: var(--shadow);
        }

        header h1 {
            margin: 0;
            font-weight: 700;
            letter-spacing: .2px;
            font-size: var(--fs-h1);
        }

        .wrap {
            max-width: 980px;
            margin: 14px auto;
            padding: 0 10px;
        }

        .row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap
        }

        .col {
            flex: 1 1 420px
        }

        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: var(--pad-card);
        }

        h2 {
            margin: 0 0 8px;
            color: var(--primary);
            font-size: var(--fs-title)
        }

        label {
            display: block;
            font-weight: 600;
            margin: 6px 0 4px;
            font-size: 13px
        }

        input[type=text],
        select {
            width: 100%;
            padding: var(--pad-input);
            border: 1px solid var(--line);
            border-radius: 6px;
            font-size: 13px
        }

        input[type=text]:focus,
        select:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, .12)
        }

        .toolbar {
            display: flex;
            gap: 6px;
            align-items: center;
            margin-top: 8px
        }

        .btn {
            display: inline-flex;
            gap: 6px;
            align-items: center;
            padding: var(--pad-btn);
            border-radius: 6px;
            cursor: pointer;
            border: 1px solid transparent;
            font-weight: 600;
            font-size: 12px;
            line-height: 1
        }

        .btn-primary {
            background: #2563eb;
            color: #fff;
            border-color: #2563eb
        }

        .btn-muted {
            background: #f3f4f6;
            color: #111827;
            border: 1px solid #e5e7eb
        }

        .btn-danger {
            background: #dc2626;
            color: #fff;
            border-color: #dc2626
        }

        .btn-warning {
            background: #f59e0b;
            color: #111827;
            border-color: #f59e0b
        }

        .btn i {
            font-size: 12px
        }

        .msg {
            margin: 10px 0;
            padding: 8px 10px;
            border-radius: 6px;
            border: 1px solid transparent;
            font-size: 13px
        }

        .ok {
            background: var(--ok-bg);
            color: var(--ok-tx);
            border-color: var(--ok-bd)
        }

        .err {
            background: var(--er-bg);
            color: var(--er-tx);
            border-color: var(--er-bd)
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 8px;
            border: 1px solid var(--line);
            border-radius: 6px;
            overflow: hidden;
            font-size: 13px
        }

        th,
        td {
            padding: var(--pad-cell);
            border-bottom: 1px solid var(--line);
            vertical-align: middle
        }

        th {
            background: #f8fafc;
            font-weight: 700
        }

        tr:last-child td {
            border-bottom: none
        }

        tbody tr:hover td {
            background: #fcfcfc
        }

        .pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 11px;
            border: 1px solid transparent
        }

        .pill-on {
            background: #e7f8ee;
            color: #166534;
            border-color: #b7efc5
        }

        .pill-off {
            background: #feecec;
            color: #991b1b;
            border-color: #fecaca
        }

        .muted {
            color: var(--muted);
            font-size: 12px
        }

        .actions {
            display: flex;
            gap: 4px;
            flex-wrap: wrap
        }

        .back {
            margin-top: 12px
        }

        a.btn.btn-muted {
            text-decoration: none
        }

        /* Barra de acciones superior (compacta) */
        .top-actions {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 8px;
            margin: 8px 0 10px;
        }

        /* Alinea el botón inferior a la derecha y reduce margen */
        .back {
            margin-top: 10px;
            display: flex;
            justify-content: flex-end;
        }

        /* ===== Modal + Overlay (compacto) ===== */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(17, 24, 39, .55);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 12px;
            z-index: 9999;
        }

        .modal-card {
            background: #fff;
            width: 98%;
            max-width: 520px;
            border: 1px solid #e6e7eb;
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .18);
            overflow: hidden;
            font-size: 14px;
        }

        .modal-head {
            background: linear-gradient(145deg, #e53935 0%, #b71c1c 100%);
            color: #fff;
            padding: 10px 12px;
            display: flex;
            align-items: center;
            justify-content: space-between
        }

        .modal-title {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
            letter-spacing: .2px
        }

        .modal-close {
            background: transparent;
            border: 0;
            color: #fff;
            font-size: 18px;
            cursor: pointer
        }

        .modal-body {
            padding: 12px;
            color: #1f2937;
            line-height: 1.4
        }

        .modal-body .muted {
            color: #6b7280;
            font-size: 12px
        }

        .modal-actions {
            padding: 10px 12px;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            background: #f9fafb;
            border-top: 1px solid #e6e7eb
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 6px;
            border: 1px solid transparent;
            cursor: pointer;
            font-weight: 600;
            font-size: 12px;
            line-height: 1;
            text-decoration: none
        }

        .btn-primary {
            background: #2563eb;
            color: #fff;
            border-color: #2563eb
        }

        .btn-muted {
            background: #f3f4f6;
            color: #111827;
            border: 1px solid #e5e7eb
        }

        .btn-danger {
            background: #dc2626;
            color: #fff;
            border-color: #dc2626
        }

        /* Variantes */
        .modal-card.ok .modal-head {
            background: linear-gradient(145deg, #10b981, #059669)
        }

        .modal-card.err .modal-head {
            background: linear-gradient(145deg, #ef4444, #b91c1c)
        }

        .modal-card.info .modal-head {
            background: linear-gradient(145deg, #3b82f6, #1d4ed8)
        }

        /* ===== Drawer Historial ===== */
        .drawer-overlay {
            position: fixed;
            inset: 0;
            background: rgba(17, 24, 39, .45);
            display: none;
            align-items: stretch;
            justify-content: flex-end;
            z-index: 10000;
        }

        .drawer {
            width: 96%;
            max-width: 700px;
            background: #fff;
            height: 100%;
            box-shadow: -8px 0 24px rgba(0, 0, 0, .18);
            border-left: 1px solid var(--line);
            transform: translateX(100%);
            transition: transform .22s ease;
            display: flex;
            flex-direction: column;
        }

        .drawer.on {
            transform: translateX(0);
        }

        .drawer-head {
            padding: 12px;
            background: #f8fafc;
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .drawer-title {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
            color: var(--primary);
        }

        .drawer-close {
            background: transparent;
            border: 0;
            font-size: 18px;
            cursor: pointer;
        }

        .drawer-body {
            padding: 12px;
            overflow: auto;
        }

        .hist-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            border: 1px solid var(--line);
            border-radius: 6px;
            overflow: hidden;
            font-size: 13px;
        }

        .hist-table th,
        .hist-table td {
            padding: 8px;
            border-bottom: 1px solid var(--line);
            vertical-align: top;
        }

        .hist-table th {
            background: #f8fafc;
            font-weight: 700;
        }

        .hist-tag {
            display: inline-block;
            padding: 2px 8px;
            font-size: 11px;
            border-radius: 999px;
            border: 1px solid #e5e7eb;
            background: #f3f4f6;
        }

        .hist-tag.area {
            background: #eef2ff;
        }

        .hist-tag.subarea {
            background: #ecfeff;
        }

        .hist-badge {
            display: inline-block;
            padding: 2px 8px;
            font-size: 11px;
            border-radius: 999px;
            color: #111827;
            border: 1px solid #e5e7eb;
        }

        .hist-badge.add {
            background: #e7f8ee;
        }

        /* agregar */
        .hist-badge.toggle {
            background: #fff7ed;
        }

        /* activar/desactivar */
        .hist-badge.del {
            background: #feecec;
        }

        /* eliminar */
    </style>
</head>

<body>

    <header>
        <h1>Administrador · EVENTOS (Áreas / Subáreas)</h1>
    </header>

    <div class="wrap">
        <div class="top-actions">
            <button id="btnHistory" class="btn btn-muted">
                <i class="fa fa-history"></i> Historial
            </button>
            <a href="menu.php" class="btn btn-muted">
                <i class="fa fa-arrow-left"></i> Volver
            </a>
        </div>

        <?php if ($msg !== ''): ?><div class="msg ok"><i class="fa fa-check-circle"></i> <?php echo html_escape($msg); ?></div><?php endif; ?>
        <?php if ($err !== ''): ?><div class="msg err"><i class="fa fa-exclamation-triangle"></i> <?php echo html_escape($err); ?></div><?php endif; ?>

        <div class="row">
            <!-- Panel ÁREAS -->
            <div class="col">
                <div class="card">
                    <h2>Áreas</h2>
                    <form method="post" autocomplete="off" style="margin-bottom:8px">
                        <input type="hidden" name="csrf" value="<?php echo html_escape($csrf); ?>">
                        <input type="hidden" name="action" value="add_area">
                        <label>Nueva área</label>
                        <input type="text" name="nombre" maxlength="120" placeholder="Ej. Refrigeración" required>
                        <div class="toolbar">
                            <button class="btn btn-primary" type="submit"><i class="fa fa-plus"></i> Agregar área</button>
                        </div>
                    </form>

                    <table>
                        <thead>
                            <tr>
                                <th style="width:54px">Clave</th>
                                <th>Nombre</th>
                                <th style="width:100px">Estado</th>
                                <th style="width:200px">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($areas)): ?>
                                <?php foreach ($areas as $a): ?>
                                    <tr<?php if ($area_id === (int)$a['id_area']) echo ' style="outline:1px solid #e5e7eb"'; ?>>
                                        <td><?php echo (int)$a['id_area']; ?></td>
                                        <td><?php echo html_escape($a['nombre']); ?></td>
                                        <td>
                                            <?php if ((int)$a['activo'] === 1): ?>
                                                <span class="pill pill-on">Activa</span>
                                            <?php else: ?>
                                                <span class="pill pill-off">Inactiva</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="actions">
                                            <a class="btn btn-muted" href="mantto_admin_eventos.php?area_id=<?php echo (int)$a['id_area']; ?>">
                                                <i class="fa fa-eye"></i> Ver
                                            </a>

                                            <form method="post" style="display:inline" class="js-confirm" data-confirm="¿Cambiar estado del área?">
                                                <input type="hidden" name="csrf" value="<?php echo html_escape($csrf); ?>">
                                                <input type="hidden" name="action" value="toggle_area">
                                                <input type="hidden" name="id" value="<?php echo (int)$a['id_area']; ?>">
                                                <button class="btn btn-warning" type="submit">
                                                    <i class="fa fa-power-off"></i> <?php echo ((int)$a['activo'] === 1 ? 'Off' : 'On'); ?>
                                                </button>
                                            </form>

                                            <form method="post" style="display:inline" class="js-confirm" data-confirm="¿Eliminar el área? (Debe no tener subáreas)">
                                                <input type="hidden" name="csrf" value="<?php echo html_escape($csrf); ?>">
                                                <input type="hidden" name="action" value="delete_area">
                                                <input type="hidden" name="id" value="<?php echo (int)$a['id_area']; ?>">
                                                <button class="btn btn-danger" type="submit"><i class="fa fa-trash"></i> Del</button>
                                            </form>

                                        </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" style="text-align:center; color:var(--muted);">Sin áreas</td>
                                    </tr>
                                <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Panel SUBÁREAS -->
            <div class="col">
                <div class="card">
                    <h2>Subáreas</h2>
                    <form method="post" autocomplete="off" style="margin-bottom:8px">
                        <input type="hidden" name="csrf" value="<?php echo html_escape($csrf); ?>">
                        <input type="hidden" name="action" value="add_subarea">
                        <label>Área</label>
                        <select name="id_area" required>
                            <option value="">Selecciona un área</option>
                            <?php foreach ($areas as $a): ?>
                                <option value="<?php echo (int)$a['id_area']; ?>" <?php echo ($area_id === (int)$a['id_area'] ? ' selected' : ''); ?>>
                                    <?php echo html_escape($a['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label style="margin-top:6px;">Nueva subárea</label>
                        <input type="text" name="nombre" maxlength="120" placeholder="Ej. Cámaras / Climas" required>
                        <div class="toolbar">
                            <button class="btn btn-primary" type="submit"><i class="fa fa-plus"></i> Agregar subárea</button>
                        </div>
                    </form>

                    <table>
                        <thead>
                            <tr>
                                <th style="width:54px">Clave</th>
                                <th>Nombre</th>
                                <th style="width:100px">Estado</th>
                                <th style="width:180px">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($area_id > 0 && !empty($subareas)): ?>
                                <?php foreach ($subareas as $s): ?>
                                    <tr>
                                        <td><?php echo (int)$s['id_subarea']; ?></td>
                                        <td><?php echo html_escape($s['nombre']); ?></td>
                                        <td>
                                            <?php if ((int)$s['activo'] === 1): ?>
                                                <span class="pill pill-on">Activa</span>
                                            <?php else: ?>
                                                <span class="pill pill-off">Inactiva</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="actions">
                                            <form method="post" style="display:inline" class="js-confirm" data-confirm="¿Cambiar estado de la subárea?">
                                                <input type="hidden" name="csrf" value="<?php echo html_escape($csrf); ?>">
                                                <input type="hidden" name="action" value="toggle_subarea">
                                                <input type="hidden" name="id" value="<?php echo (int)$s['id_subarea']; ?>">
                                                <input type="hidden" name="id_area" value="<?php echo (int)$area_id; ?>">
                                                <button class="btn btn-warning" type="submit">
                                                    <i class="fa fa-power-off"></i> <?php echo ((int)$s['activo'] === 1 ? 'Off' : 'On'); ?>
                                                </button>
                                            </form>

                                            <form method="post" style="display:inline" class="js-confirm" data-confirm="¿Eliminar la subárea?">
                                                <input type="hidden" name="csrf" value="<?php echo html_escape($csrf); ?>">
                                                <input type="hidden" name="action" value="delete_subarea">
                                                <input type="hidden" name="id" value="<?php echo (int)$s['id_subarea']; ?>">
                                                <input type="hidden" name="id_area" value="<?php echo (int)$area_id; ?>">
                                                <button class="btn btn-danger" type="submit"><i class="fa fa-trash"></i> Del</button>
                                            </form>

                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php elseif ($area_id > 0): ?>
                                <tr>
                                    <td colspan="4" style="text-align:center; color:var(--muted);">Sin subáreas para el área seleccionada</td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" style="text-align:center; color:var(--muted);">Selecciona un área para ver sus subáreas</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="back">
            <a href="menu.php" class="btn btn-muted"><i class="fa fa-arrow-left"></i> Volver</a>
        </div>
    </div>
    <!-- Modal genérico -->
    <div id="uiModal" class="modal-overlay">
        <div id="uiModalCard" class="modal-card info">
            <div class="modal-head">
                <h3 id="uiModalTitle" class="modal-title">Aviso</h3>
                <button type="button" id="uiModalX" class="modal-close" aria-label="Cerrar">×</button>
            </div>
            <div id="uiModalBody" class="modal-body">
                <p>Contenido…</p>
            </div>
            <div id="uiModalActions" class="modal-actions"><!-- botones por JS --></div>
        </div>
    </div>
    <!-- Drawer Historial -->
    <div id="drawerOverlay" class="drawer-overlay">
        <div id="drawer" class="drawer">
            <div class="drawer-head">
                <h3 class="drawer-title"><i class="fa fa-history"></i> Historial de movimientos</h3>
                <button id="drawerClose" type="button" class="drawer-close" aria-label="Cerrar">×</button>
            </div>
            <div id="drawerBody" class="drawer-body">
                <!-- Se llena por JS -->
            </div>
        </div>
    </div>

    <script>
        (function() {
            /* ===== Modal genérico existente ===== */
            var overlay = document.getElementById('uiModal');
            var card = document.getElementById('uiModalCard');
            var titleEl = document.getElementById('uiModalTitle');
            var bodyEl = document.getElementById('uiModalBody');
            var actionsEl = document.getElementById('uiModalActions');
            var btnX = document.getElementById('uiModalX');

            function clearActions() {
                while (actionsEl.firstChild) actionsEl.removeChild(actionsEl.firstChild);
            }

            function closeModal() {
                overlay.style.display = 'none';
            }

            function openModal(opts) {
                titleEl.textContent = opts.title || 'Aviso';
                bodyEl.innerHTML = opts.html || '';
                card.className = 'modal-card ' + (opts.type || 'info');
                clearActions();
                if (opts.confirm) {
                    var cancel = document.createElement('button');
                    cancel.type = 'button';
                    cancel.className = 'btn btn-muted';
                    cancel.textContent = opts.cancelText || 'Cancelar';
                    cancel.onclick = closeModal;

                    var ok = document.createElement('button');
                    ok.type = 'button';
                    ok.className = (opts.type === 'err' ? 'btn btn-danger' : 'btn btn-primary');
                    ok.textContent = opts.okText || 'Continuar';
                    ok.onclick = function() {
                        closeModal();
                        if (typeof opts.onConfirm === 'function') opts.onConfirm();
                    };
                    actionsEl.appendChild(cancel);
                    actionsEl.appendChild(ok);
                } else {
                    var close = document.createElement('button');
                    close.type = 'button';
                    close.className = 'btn btn-primary';
                    close.textContent = 'Aceptar';
                    close.onclick = closeModal;
                    actionsEl.appendChild(close);
                }
                overlay.style.display = 'flex';
            }
            btnX && btnX.addEventListener('click', closeModal);
            overlay && overlay.addEventListener('click', function(e) {
                if (e.target === overlay) closeModal();
            });

            // Mostrar msg/err PHP en modal + autocierre 3s
            var phpMsg = <?php echo json_encode(isset($msg) ? $msg : ''); ?>;
            var phpErr = <?php echo json_encode(isset($err) ? $err : ''); ?>;
            var autoCloseMs = 3000;

            if (phpErr) {
                openModal({
                    title: 'Error',
                    html: '<p>' + phpErr.replace(/</g, '&lt;') + '</p>',
                    type: 'err'
                });
                setTimeout(closeModal, autoCloseMs);
            } else if (phpMsg) {
                openModal({
                    title: 'Listo',
                    html: '<p>' + phpMsg.replace(/</g, '&lt;') + '</p>',
                    type: 'ok'
                });
                setTimeout(closeModal, autoCloseMs);
            }

            // Auto-ocultar barras .msg
            (function() {
                var bars = document.querySelectorAll('.msg.ok, .msg.err');
                if (!bars.length) return;
                setTimeout(function() {
                    for (var i = 0; i < bars.length; i++) {
                        (function(el) {
                            el.style.transition = 'opacity .3s ease';
                            el.style.opacity = '0';
                            setTimeout(function() {
                                if (el && el.parentNode) el.parentNode.removeChild(el);
                            }, 350);
                        })(bars[i]);
                    }
                }, 3000);
            })();

            // Confirmaciones con modal
            var forms = document.querySelectorAll('form.js-confirm');
            for (var i = 0; i < forms.length; i++) {
                (function(form) {
                    form.addEventListener('submit', function(ev) {
                        ev.preventDefault();
                        var txt = form.getAttribute('data-confirm') || '¿Confirmas esta acción?';
                        var actEl = form.querySelector('input[name=action]');
                        var act = actEl ? actEl.value : '';
                        var tipo = (/^delete_/i.test(act) ? 'err' : 'info');

                        openModal({
                            title: (tipo === 'err' ? 'Confirmar eliminación' : 'Confirmar'),
                            html: '<p>' + txt.replace(/</g, '&lt;') + '</p>',
                            type: tipo,
                            confirm: true,
                            okText: 'Sí, continuar',
                            cancelText: 'Cancelar',
                            onConfirm: function() {
                                form.submit();
                            }
                        });
                    });
                })(forms[i]);
            }

            /* ===== Drawer Historial ===== */
            var btnHistory = document.getElementById('btnHistory');
            var dOverlay = document.getElementById('drawerOverlay');
            var dEl = document.getElementById('drawer');
            var dClose = document.getElementById('drawerClose');
            var dBody = document.getElementById('drawerBody');

            function openDrawer() {
                if (!dOverlay || !dEl) return;
                dOverlay.style.display = 'flex';
                setTimeout(function() {
                    dEl.classList.add('on');
                }, 10);
            }

            function closeDrawer() {
                if (!dOverlay || !dEl) return;
                dEl.classList.remove('on');
                setTimeout(function() {
                    dOverlay.style.display = 'none';
                }, 220);
            }
            dOverlay && dOverlay.addEventListener('click', function(e) {
                if (e.target === dOverlay) closeDrawer();
            });
            dClose && dClose.addEventListener('click', closeDrawer);

            // Datos del servidor (últimos 100)
            var auditData = <?php echo json_encode($auditRows, JSON_UNESCAPED_UNICODE); ?>;

            function escapeHTML(s) {
                return String(s == null ? '' : s)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            }

            function escapeHTML(s) {
                return String(s == null ? '' : s)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            }

            function parseDetails(jsonStr) {
                try {
                    return jsonStr ? JSON.parse(jsonStr) : {};
                } catch (e) {
                    return {};
                }
            }

            // Construye frases “bonitas” en español
            function prettyDetail(entidad, accion, detalles, entidad_id) {
                var e = (entidad || '').toLowerCase();
                var a = (accion || '').toLowerCase();
                var d = parseDetails(detalles);

                // nombres disponibles
                var areaNombre = d.area_nombre || d.nombre || null;
                var subareaNombre = d.subarea_nombre || d.nombre || null;
                var idArea = d.id_area;

                // fallback si no hay nombres
                var refArea = areaNombre ? '«' + areaNombre + '»' : (idArea ? 'Área #' + idArea : 'Área #' + entidad_id);
                var refSubarea = subareaNombre ? '«' + subareaNombre + '»' : 'Subárea #' + entidad_id;

                // según entidad/acción
                if (e === 'area') {
                    if (a === 'agregar') return 'Se creó el área ' + refArea + ' (ID #' + entidad_id + ').';
                    if (a === 'eliminar') return 'Se eliminó el área ' + refArea + ' (ID #' + entidad_id + ').';
                    if (a === 'activar') return 'Se activó el área ' + refArea + '.';
                    if (a === 'desactivar') return 'Se desactivó el área ' + refArea + '.';
                }
                if (e === 'subarea') {
                    if (a === 'agregar') return 'Se creó la subárea ' + refSubarea + ' en ' + refArea + ' (ID #' + entidad_id + ').';
                    if (a === 'eliminar') return 'Se eliminó la subárea ' + refSubarea + ' de ' + refArea + '.';
                    if (a === 'activar') return 'Se activó la subárea ' + refSubarea + '.';
                    if (a === 'desactivar') return 'Se desactivó la subárea ' + refSubarea + '.';
                }

                // fallback genérico
                return (a ? ('Acción: ' + a + '. ') : '') + (detalles ? escapeHTML(detalles) : '');
            }

            function accionBadge(accion) {
                var a = (accion || '').toLowerCase();
                if (a === 'agregar') return '<span class="hist-badge add">agregar</span>';
                if (a === 'eliminar') return '<span class="hist-badge del">eliminar</span>';
                if (a === 'activar' || a === 'desactivar') return '<span class="hist-badge toggle">' + escapeHTML(a) + '</span>';
                return '<span class="hist-badge">' + escapeHTML(accion || '') + '</span>';
            }

            function entidadTag(entidad) {
                var e = (entidad || '').toLowerCase();
                var cls = (e === 'area' ? 'area' : (e === 'subarea' ? 'subarea' : ''));
                return '<span class="hist-tag ' + cls + '">' + escapeHTML(entidad || '') + '</span>';
            }

            function renderHistory(rows) {
                if (!dBody) return;
                if (!rows || !rows.length) {
                    dBody.innerHTML = '<p class="muted">Sin movimientos registrados.</p>';
                    return;
                }
                var html = '<table class="hist-table"><thead><tr>' +
                    '<th style="width:140px">Fecha</th>' +
                    '<th style="width:120px">Usuario</th>' +
                    '<th style="width:110px">Entidad</th>' +
                    '<th style="width:110px">Acción</th>' +
                    '<th style="width:90px">Clave</th>' +
                    '<th>Detalle</th>' +
                    '</tr></thead><tbody>';
                for (var i = 0; i < rows.length; i++) {
                    var r = rows[i];
                    html += '<tr>' +
                        '<td>' + escapeHTML(r.fecha) + '</td>' +
                        '<td>' + escapeHTML(r.usuario) + '</td>' +
                        '<td>' + entidadTag(r.entidad) + '</td>' +
                        '<td>' + accionBadge(r.accion) + '</td>' +
                        '<td>' + escapeHTML(r.entidad_id) + '</td>' +
                        '<td>' + escapeHTML(prettyDetail(r.entidad, r.accion, r.detalles, r.entidad_id)) + '</td>' +
                        '</tr>';
                }
                html += '</tbody></table>';
                dBody.innerHTML = html;
            }


            btnHistory && btnHistory.addEventListener('click', function() {
                renderHistory(auditData);
                openDrawer();
            });
        })();
        // Si por cualquier motivo vinieran msg/err en la URL, los quitamos sin recargar
        (function() {
            var p = new URLSearchParams(location.search);
            if (p.has('msg') || p.has('err')) {
                p.delete('msg');
                p.delete('err');
                var qs = p.toString();
                history.replaceState(null, '', location.pathname + (qs ? '?' + qs : ''));
            }
        })();
    </script>


</body>

</html>