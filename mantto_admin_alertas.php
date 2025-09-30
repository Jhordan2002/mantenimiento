<?php
/* mantto_admin_alertas.php – Admin de ALERTAS (Eventos/Emergencias)
 * PHP 5.6 + mysqli. CRUD: agregar, activar/desactivar, eliminar, buscar.
 * Sin columnas de fechas. Estilo COMPACTO, consistente con mantto_admin_eventos.
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

function get_csrf_token(array &$session, $key = 'csrf_alertas')
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


$csrf = get_csrf_token($_SESSION, 'csrf_alertas');
$remoteAddr = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
$userAgent  = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
$msg = isset($_GET['msg']) ? (string)$_GET['msg'] : '';
$err = isset($_GET['err']) ? (string)$_GET['err'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $token  = isset($_POST['csrf']) ? $_POST['csrf'] : '';
    $r_msg  = '';
    $r_err = '';

    if ($token !== $csrf) {
        $r_err = 'Token inválido. Recarga la página.';
    } else {
        if ($action === 'agregar') {
            $codigo = isset($_POST['codigo']) ? trim($_POST['codigo']) : '';
            $nombre = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';

            if ($codigo === '' || $nombre === '') {
                $r_err = 'Completa código y nombre.';
            } else {
                $codigo = strtoupper($codigo);
                if ($st = $mysqli->prepare("SELECT 1 FROM mantto_alertas WHERE codigo=? OR nombre=? LIMIT 1")) {
                    $st->bind_param('ss', $codigo, $nombre);
                    if ($st->execute()) {
                        $st->store_result();
                        if ($st->num_rows > 0) {
                            $r_err = 'Ya existe una alerta con ese código o nombre.';
                        } else {
                            $st->close();
                            if ($ins = $mysqli->prepare("INSERT INTO mantto_alertas (codigo, nombre, activo) VALUES (?, ?, 1)")) {
                                $ins->bind_param('ss', $codigo, $nombre);
                                if ($ins->execute()) {
                                    $r_msg = 'Alerta agregada.';
                                    $new_id = $ins->insert_id;
                                    audit_log(
                                        $mysqli,
                                        $_SESSION['usuarioactual'],
                                        'alerta',
                                        (int)$new_id,
                                        'agregar',
                                        json_encode(array('codigo' => $codigo, 'nombre' => $nombre)),
                                        $remoteAddr,
                                        $userAgent
                                    );
                                } else {
                                    $r_err = 'Error al agregar: ' . $ins->error;
                                }
                                $ins->close();
                            } else {
                                $r_err = 'Error al preparar INSERT: ' . $mysqli->error;
                            }
                        }
                    } else {
                        $r_err = 'Error al ejecutar SELECT: ' . $st->error;
                    }
                    if ($st) $st->close();
                } else {
                    $r_err = 'Error al preparar SELECT: ' . $mysqli->error;
                }
            }
        }

        if ($action === 'toggle') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($id > 0) {
                // Leer estado previo para saber si activamos o desactivamos
                $prev = null;
                if ($s0 = $mysqli->prepare("SELECT activo FROM mantto_alertas WHERE id_alerta=? LIMIT 1")) {
                    $s0->bind_param('i', $id);
                    if ($s0->execute()) {
                        $s0->bind_result($prev);
                        $s0->fetch();
                    }
                    $s0->close();
                }
                $accionLog = ($prev === 1) ? 'desactivar' : 'activar';

                if ($st = $mysqli->prepare("UPDATE mantto_alertas SET activo=IF(activo=1,0,1) WHERE id_alerta=? LIMIT 1")) {
                    $st->bind_param('i', $id);
                    if ($st->execute()) {
                        $r_msg = 'Estado actualizado.';
                        audit_log(
                            $mysqli,
                            $_SESSION['usuarioactual'],
                            'alerta',
                            (int)$id,
                            $accionLog,
                            json_encode(array('prev_activo' => (int)$prev)),
                            $remoteAddr,
                            $userAgent
                        );
                    } else {
                        $r_err = 'Error al actualizar: ' . $st->error;
                    }
                    $st->close();
                } else {
                    $r_err = 'Error al preparar UPDATE: ' . $mysqli->error;
                }
            }
        }


        if ($action === 'eliminar') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($id > 0) {
                if ($st = $mysqli->prepare("DELETE FROM mantto_alertas WHERE id_alerta=? LIMIT 1")) {
                    $st->bind_param('i', $id);
                    if ($st->execute()) {
                        $r_msg = 'Alerta eliminada.';
                        audit_log($mysqli, $_SESSION['usuarioactual'], 'alerta', (int)$id, 'eliminar', '', $remoteAddr, $userAgent);
                    } else {
                        $r_err = 'Error al eliminar: ' . $st->error;
                    }

                    $st->close();
                } else {
                    $r_err = 'Error al preparar DELETE: ' . $mysqli->error;
                }
            }
        }
    }

    header('Location: mantto_admin_alertas.php?' . http_build_query(array('msg' => $r_msg, 'err' => $r_err)));
    exit;
}

/* Listado (con/ sin filtro) */
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$rows = array();
$sqlBase = "SELECT id_alerta, codigo, nombre, activo FROM mantto_alertas";

if ($q !== '') {
    $sql = $sqlBase . " WHERE codigo LIKE ? OR nombre LIKE ? ORDER BY nombre ASC";
    $st = $mysqli->prepare($sql);
    if ($st) {
        $like = '%' . $q . '%';
        $st->bind_param('ss', $like, $like);
        if ($st->execute()) {
            $st->bind_result($c_id, $c_cod, $c_nom, $c_act);
            while ($st->fetch()) {
                $rows[] = array('id_alerta' => $c_id, 'codigo' => $c_cod, 'nombre' => $c_nom, 'activo' => $c_act);
            }
        } else {
            $err = 'Error al ejecutar (filtro): ' . $st->error;
        }
        $st->close();
    } else {
        $err = 'Error al preparar (filtro): ' . $mysqli->error;
    }
} else {
    $sql = $sqlBase . " ORDER BY nombre ASC";
    $st = $mysqli->prepare($sql);
    if ($st) {
        if ($st->execute()) {
            $st->bind_result($c_id, $c_cod, $c_nom, $c_act);
            while ($st->fetch()) {
                $rows[] = array('id_alerta' => $c_id, 'codigo' => $c_cod, 'nombre' => $c_nom, 'activo' => $c_act);
            }
        } else {
            $err = 'Error al ejecutar (lista): ' . $st->error;
        }
        $st->close();
    } else {
        $err = 'Error al preparar (lista): ' . $mysqli->error;
    }
}
?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Admin · Alertas (Eventos/Emergencias)</title>
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
            --fs-title: 16px;
            --fs-h1: 15px;
            --pad-card: 12px;
            --pad-cell: 8px;
            --pad-input: 8px 10px;
            --pad-btn: 6px 10px;
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

        .top-actions {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 8px;
            margin: 8px 0 10px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: var(--pad-card);
        }

        .row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap
        }

        .col {
            flex: 1 1 420px
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

        input[type=text] {
            width: 100%;
            padding: var(--pad-input);
            border: 1px solid var(--line);
            border-radius: 6px;
            font-size: 13px
        }

        input[type=text]:focus {
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

        .search-box {
            display: flex;
            gap: 8px;
            align-items: flex-end
        }

        .search-box input {
            flex: 1
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

        .muted {
            color: var(--muted);
            font-size: 12px
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

        .actions {
            display: flex;
            gap: 4px;
            flex-wrap: wrap
        }

        .footer-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 8px
        }

        .back {
            margin-top: 12px;
            display: flex;
            justify-content: flex-end
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

        /* Variantes por tipo (éxito/error/info) */
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
        <h1>Administrador · Alertas (Eventos/Emergencias)</h1>
    </header>

    <div class="wrap">

        <!-- Botón volver arriba -->
        <div class="top-actions">
            <a href="menu.php" class="btn btn-muted"><i class="fa fa-arrow-left"></i> Volver</a>
        </div>

        <?php if ($msg !== ''): ?><div class="msg ok"><i class="fa fa-check-circle"></i> <?php echo html_escape($msg); ?></div><?php endif; ?>
        <?php if ($err !== ''): ?><div class="msg err"><i class="fa fa-exclamation-triangle"></i> <?php echo html_escape($err); ?></div><?php endif; ?>


        <div class="card">
            <h2>Alta rápida</h2>
            <div class="row">
                <div class="col">
                    <form method="post" autocomplete="off">
                        <input type="hidden" name="csrf" value="<?php echo html_escape($csrf); ?>">
                        <input type="hidden" name="action" value="agregar">
                        <label>Código (ej. SIN_LUZ)</label>
                        <input type="text" name="codigo" maxlength="30" placeholder="SIN_LUZ" required>
                        <label>Nombre</label>
                        <input type="text" name="nombre" maxlength="120" placeholder="Sucursal sin luz" required>
                        <div class="toolbar">
                            <button class="btn btn-primary" type="submit"><i class="fa fa-plus"></i> Agregar alerta</button>
                        </div>
                    </form>
                </div>
                <div class="col">
                    <form method="get" class="search-box" autocomplete="off">
                        <div style="flex:1">
                            <label>Buscar</label>
                            <input type="text" name="q" value="<?php echo html_escape($q); ?>" placeholder="Código o nombre…">
                        </div>
                        <div style="display:flex; gap:6px">
                            <button class="btn btn-muted" type="submit"><i class="fa fa-search"></i> Filtrar</button>
                            <?php if ($q !== ''): ?>
                                <a class="btn btn-muted" href="mantto_admin_alertas.php"><i class="fa fa-times"></i> Quitar filtro</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <h2 style="margin-top:12px">Listado</h2>
            <table>
                <thead>
                    <tr>
                        <th style="width:54px">CLAVE</th>
                        <th style="width:160px">Código</th>
                        <th>Nombre</th>
                        <th style="width:100px">Estado</th>
                        <th style="width:200px">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($rows)): ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?php echo (int)$row['id_alerta']; ?></td>
                                <td><?php echo html_escape($row['codigo']); ?></td>
                                <td><?php echo html_escape($row['nombre']); ?></td>
                                <td>
                                    <?php if ((int)$row['activo'] === 1): ?>
                                        <span class="pill pill-on">Activa</span>
                                    <?php else: ?>
                                        <span class="pill pill-off">Inactiva</span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions">
                                    <form method="post" style="display:inline" class="js-confirm" data-confirm="¿Cambiar estado de esta alerta?">
                                        <input type="hidden" name="csrf" value="<?php echo html_escape($csrf); ?>">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?php echo (int)$row['id_alerta']; ?>">
                                        <button class="btn btn-warning" type="submit"><i class="fa fa-power-off"></i> <?php echo ((int)$row['activo'] === 1 ? 'Off' : 'On'); ?></button>
                                    </form>

                                    <form method="post" style="display:inline" class="js-confirm" data-confirm="¿Eliminar definitivamente esta alerta?">
                                        <input type="hidden" name="csrf" value="<?php echo html_escape($csrf); ?>">
                                        <input type="hidden" name="action" value="eliminar">
                                        <input type="hidden" name="id" value="<?php echo (int)$row['id_alerta']; ?>">
                                        <button class="btn btn-danger" type="submit"><i class="fa fa-trash"></i> Del</button>
                                    </form>

                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align:center; color:var(--muted);">Sin resultados</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="footer-bar">
                <span class="muted">Total: <?php echo count($rows); ?> alerta(s)</span>
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
                <div id="uiModalActions" class="modal-actions">
                    <!-- Se llena por JS -->
                </div>
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
                        ok.className = opts.type === 'err' ? 'btn btn-danger' : 'btn btn-primary';
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

                // Cerrar por X o click en overlay
                btnX.addEventListener('click', closeModal);
                overlay.addEventListener('click', function(e) {
                    if (e.target === overlay) closeModal();
                });

                // Mensajes PHP (?msg= / ?err=) -> modal (autocerrar a 3s)
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


                // Confirmaciones con modal elegante
                var forms = document.querySelectorAll('form.js-confirm');
                for (var i = 0; i < forms.length; i++) {
                    (function(form) {
                        form.addEventListener('submit', function(ev) {
                            ev.preventDefault();
                            var txt = form.getAttribute('data-confirm') || '¿Confirmas esta acción?';
                            openModal({
                                title: 'Confirmar',
                                html: '<p>' + txt.replace(/</g, '&lt;') + '</p>',
                                type: 'info',
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