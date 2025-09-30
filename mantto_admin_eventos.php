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

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function get_csrf_token(array &$session, $key = 'csrf_eventos')
{
    if (!empty($session[$key])) return $session[$key];
    $session[$key] = function_exists('openssl_random_pseudo_bytes')
        ? bin2hex(openssl_random_pseudo_bytes(16))
        : sha1(uniqid(mt_rand(), true));
    return $session[$key];
}

$csrf = get_csrf_token($_SESSION, 'csrf_eventos');


$msg = isset($_GET['msg']) ? (string)$_GET['msg'] : '';
$err = isset($_GET['err']) ? (string)$_GET['err'] : '';
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
                if ($st = $mysqli->prepare("SELECT 1 FROM mantto_areas WHERE nombre=? LIMIT 1")) {
                    $st->bind_param('s', $nombre);
                    if ($st->execute()) {
                        $st->store_result();
                        if ($st->num_rows > 0) {
                            $r_err = 'Ya existe un área con ese nombre.';
                        } else {
                            $st->close();
                            if ($ins = $mysqli->prepare("INSERT INTO mantto_areas (nombre, activo) VALUES (?, 1)")) {
                                $ins->bind_param('s', $nombre);
                                if ($ins->execute()) {
                                    $r_msg = 'Área agregada.';
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
                    if ($st) $st->close();
                } else {
                    $r_err = 'Error al preparar SELECT área: ' . $mysqli->error;
                }
            }
        }

        if ($action === 'toggle_area') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($id > 0) {
                if ($st = $mysqli->prepare("UPDATE mantto_areas SET activo=IF(activo=1,0,1) WHERE id_area=? LIMIT 1")) {
                    $st->bind_param('i', $id);
                    if ($st->execute()) {
                        $r_msg = 'Área actualizada.';
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
                    if ($del = $mysqli->prepare("DELETE FROM mantto_areas WHERE id_area=? LIMIT 1")) {
                        $del->bind_param('i', $id);
                        if ($del->execute()) {
                            $r_msg = 'Área eliminada.';
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
                if ($st = $mysqli->prepare("SELECT 1 FROM mantto_subareas WHERE id_area=? AND nombre=? LIMIT 1")) {
                    $st->bind_param('is', $id_area, $nombre);
                    if ($st->execute()) {
                        $st->store_result();
                        if ($st->num_rows > 0) {
                            $r_err = 'Ya existe esa subárea en el área seleccionada.';
                        } else {
                            $st->close();
                            if ($ins = $mysqli->prepare("INSERT INTO mantto_subareas (id_area, nombre, activo) VALUES (?, ?, 1)")) {
                                $ins->bind_param('is', $id_area, $nombre);
                                if ($ins->execute()) {
                                    $r_msg = 'Subárea agregada.';
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
                    if ($st) $st->close();
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
                if ($st = $mysqli->prepare("UPDATE mantto_subareas SET activo=IF(activo=1,0,1) WHERE id_subarea=? LIMIT 1")) {
                    $st->bind_param('i', $id);
                    if ($st->execute()) {
                        $r_msg = 'Subárea actualizada.';
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
                if ($del = $mysqli->prepare("DELETE FROM mantto_subareas WHERE id_subarea=? LIMIT 1")) {
                    $del->bind_param('i', $id);
                    if ($del->execute()) {
                        $r_msg = 'Subárea eliminada.';
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

    $query = array('msg' => $r_msg, 'err' => $r_err) + $redir_params;
    header('Location: mantto_admin_eventos.php?' . http_build_query($query));
    exit;
}

/* -------------------------
   GET: cargar catálogos
--------------------------*/
$areas = array();
if ($st = $mysqli->prepare("SELECT id_area, nombre, activo FROM mantto_areas ORDER BY nombre ASC")) {
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
    if ($st = $mysqli->prepare("SELECT id_subarea, nombre, activo FROM mantto_subareas WHERE id_area=? ORDER BY nombre ASC")) {
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
    </style>
</head>

<body>

    <header>
        <h1>Administrador · EVENTOS (Áreas / Subáreas)</h1>
    </header>

    <div class="wrap">
        <div class="top-actions">
            <a href="menu.php" class="btn btn-muted">
                <i class="fa fa-arrow-left"></i> Volver
            </a>
        </div>

        <?php if ($msg !== ''): ?><div class="msg ok"><i class="fa fa-check-circle"></i> <?php echo h($msg); ?></div><?php endif; ?>
        <?php if ($err !== ''): ?><div class="msg err"><i class="fa fa-exclamation-triangle"></i> <?php echo h($err); ?></div><?php endif; ?>

        <div class="row">
            <!-- Panel ÁREAS -->
            <div class="col">
                <div class="card">
                    <h2>Áreas</h2>
                    <form method="post" autocomplete="off" style="margin-bottom:8px">
                        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
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
                                        <td><?php echo h($a['nombre']); ?></td>
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
                                                <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                                                <input type="hidden" name="action" value="toggle_area">
                                                <input type="hidden" name="id" value="<?php echo (int)$a['id_area']; ?>">
                                                <button class="btn btn-warning" type="submit">
                                                    <i class="fa fa-power-off"></i> <?php echo ((int)$a['activo'] === 1 ? 'Off' : 'On'); ?>
                                                </button>
                                            </form>

                                            <form method="post" style="display:inline" class="js-confirm" data-confirm="¿Eliminar el área? (Debe no tener subáreas)">
                                                <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
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
                        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                        <input type="hidden" name="action" value="add_subarea">
                        <label>Área</label>
                        <select name="id_area" required>
                            <option value="">Selecciona un área</option>
                            <?php foreach ($areas as $a): ?>
                                <option value="<?php echo (int)$a['id_area']; ?>" <?php echo ($area_id === (int)$a['id_area'] ? ' selected' : ''); ?>>
                                    <?php echo h($a['nombre']); ?>
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
                                        <td><?php echo h($s['nombre']); ?></td>
                                        <td>
                                            <?php if ((int)$s['activo'] === 1): ?>
                                                <span class="pill pill-on">Activa</span>
                                            <?php else: ?>
                                                <span class="pill pill-off">Inactiva</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="actions">
                                            <form method="post" style="display:inline" class="js-confirm" data-confirm="¿Cambiar estado de la subárea?">
                                                <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                                                <input type="hidden" name="action" value="toggle_subarea">
                                                <input type="hidden" name="id" value="<?php echo (int)$s['id_subarea']; ?>">
                                                <input type="hidden" name="id_area" value="<?php echo (int)$area_id; ?>">
                                                <button class="btn btn-warning" type="submit">
                                                    <i class="fa fa-power-off"></i> <?php echo ((int)$s['activo'] === 1 ? 'Off' : 'On'); ?>
                                                </button>
                                            </form>

                                            <form method="post" style="display:inline" class="js-confirm" data-confirm="¿Eliminar la subárea?">
                                                <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
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
    <script>
        (function() {
            var overlay = document.getElementById('uiModal');
            var card = document.getElementById('uiModalCard');
            var titleEl = document.getElementById('uiModalTitle');
            var bodyEl = document.getElementById('uiModalBody');
            var actionsEl = document.getElementById('uiModalActions');
            var btnX = document.getElementById('uiModalX');

            function clearActions() {
                while (actionsEl.firstChild) {
                    actionsEl.removeChild(actionsEl.firstChild);
                }
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
                        if (typeof opts.onConfirm === 'function') {
                            opts.onConfirm();
                        }
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
            btnX.addEventListener('click', closeModal);
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) closeModal();
            });

            // Mostrar msg/err de PHP en modal (autocerrar a 3s)
            var phpMsg = <?php echo json_encode(isset($msg) ? $msg : ''); ?>;
            var phpErr = <?php echo json_encode(isset($err) ? $err : ''); ?>;
            var autoCloseMs = 3000;

            if (phpErr) {
                openModal({
                    title: 'Error',
                    html: '<p>' + phpErr.replace(/</g, '&lt;') + '</p>',
                    type: 'err'
                });
                setTimeout(function() {
                    closeModal();
                }, autoCloseMs);
            } else if (phpMsg) {
                openModal({
                    title: 'Listo',
                    html: '<p>' + phpMsg.replace(/</g, '&lt;') + '</p>',
                    type: 'ok'
                });
                setTimeout(function() {
                    closeModal();
                }, autoCloseMs);
            }
            // Auto-ocultar las barras .msg después de 3s
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
                        // Detección simple del tipo: errores para "delete_*"
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
        })();
    </script>

</body>

</html>