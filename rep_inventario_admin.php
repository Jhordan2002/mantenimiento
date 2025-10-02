<?php
@session_start();
require 'conexionn.php';
include 'seguridad.php';
$mysqli->set_charset('utf8');

if (empty($_SESSION['usuarioactual'])) {
    header('Location: login.php');
    exit;
}

/* ========== Utils ========== */
function h($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function get_csrf_token(array &$session, $key = 'csrf_inv')
{
    if (!empty($session[$key])) return $session[$key];
    $session[$key] = function_exists('openssl_random_pseudo_bytes')
        ? bin2hex(openssl_random_pseudo_bytes(16))
        : sha1(uniqid(mt_rand(), true));
    return $session[$key];
}
$csrf = get_csrf_token($_SESSION, 'csrf_inv');

$id_almacen = 1; // Almacén central por defecto
$usuario = isset($_SESSION['usuarioactual']) ? $_SESSION['usuarioactual'] : 'sistema';

/* ========== POST Acciones (PRG) ========== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $token  = isset($_POST['csrf']) ? $_POST['csrf'] : '';
    if ($token !== $csrf) {
        $_SESSION['flash_err'] = 'Token inválido. Recarga la página.';
        header('Location: rep_inventario_admin.php?tab=' . $goto_tab, true, 303);
        exit;
    }

    // Transacción 5.6
    if (method_exists($mysqli, 'begin_transaction')) $mysqli->begin_transaction();
    else $mysqli->query('START TRANSACTION');
    $goto_tab = ($action === 'mov_inv') ? 'movs' : 'items';

    try {
        if ($action === 'save_item') {
            $id_item     = isset($_POST['id_item']) ? (int)$_POST['id_item'] : 0;
            $clave       = isset($_POST['clave']) ? trim($_POST['clave']) : '';
            $descripcion = isset($_POST['descripcion']) ? trim($_POST['descripcion']) : '';
            $unidad      = isset($_POST['unidad']) ? trim($_POST['unidad']) : 'pza';
            $precio_s    = isset($_POST['precio']) ? $_POST['precio'] : '0';
            // Normaliza precio (acepta "41,50" o "41.50")
            $precio      = round((float)str_replace(',', '.', $precio_s), 2);
            $activo      = isset($_POST['activo']) ? (int)$_POST['activo'] : 1;

            if ($descripcion === '') throw new Exception('La descripción es obligatoria.');
            if ($precio < 0) throw new Exception('El precio no puede ser negativo.');

            if ($id_item <= 0) {
                // INSERT ítem
                $ins = $mysqli->prepare("
    INSERT INTO rep_items (clave, descripcion, unidad, precio_actual, activo, creado_en)
    VALUES (NULLIF(?,''), ?, ?, ?, ?, NOW())
");

                if (!$ins) throw new Exception('Prepare item: ' . $mysqli->error);
                // tipos: s s s d i
                $ins->bind_param('sssdi', $clave, $descripcion, $unidad, $precio, $activo);
                if (!$ins->execute()) throw new Exception('Exec item: ' . $ins->error);
                $new_id = $ins->insert_id;
                $ins->close();
                if ($new_id <= 0) throw new Exception('No se obtuvo ID del nuevo ítem');

                // Historial de precio (usa SIEMPRE el ID NUEVO)
                $h = $mysqli->prepare("
            INSERT INTO rep_precios_hist (id_item, precio, usuario, fecha)
            VALUES (?,?,?, NOW())
        ");
                if (!$h) throw new Exception('Prepare precio_hist: ' . $mysqli->error);
                // tipos: i d s
                $h->bind_param('ids', $new_id, $precio, $usuario);
                if (!$h->execute()) throw new Exception('Exec precio_hist: ' . $h->error);
                $h->close();

                $_SESSION['flash_ok'] = 'Ítem creado (ID #' . $new_id . ').';
            } else {
                // LEE precio previo
                $prev_precio = null;
                $q = $mysqli->prepare("SELECT precio_actual FROM rep_items WHERE id_item=? LIMIT 1");
                if (!$q) throw new Exception('Prepare get precio: ' . $mysqli->error);
                $q->bind_param('i', $id_item);
                if (!$q->execute()) throw new Exception('Exec get precio: ' . $q->error);
                $q->bind_result($prev_precio);
                $q->fetch();
                $q->close();
                if ($prev_precio === null) throw new Exception('ID inexistente.');

                // UPDATE ítem
                $up = $mysqli->prepare("
    UPDATE rep_items
    SET clave=NULLIF(?,''), descripcion=?, unidad=?, precio_actual=?, activo=?
    WHERE id_item=?
");

                if (!$up) throw new Exception('Prepare up item: ' . $mysqli->error);
                // OJO: SIN espacios en la cadena de tipos
                // tipos: s s s d i i
                $up->bind_param('sssdii', $clave, $descripcion, $unidad, $precio, $activo, $id_item);
                if (!$up->execute()) throw new Exception('Exec up item: ' . $up->error);
                $up->close();

                // Si cambió el precio → historial
                if (round((float)$prev_precio, 2) !== round((float)$precio, 2)) {
                    $h = $mysqli->prepare("
                INSERT INTO rep_precios_hist (id_item, precio, usuario, fecha)
                VALUES (?,?,?, NOW())
            ");
                    if (!$h) throw new Exception('Prepare precio_hist: ' . $mysqli->error);
                    // tipos: i d s
                    $h->bind_param('ids', $id_item, $precio, $usuario);
                    if (!$h->execute()) throw new Exception('Exec precio_hist: ' . $h->error);
                    $h->close();
                }

                $_SESSION['flash_ok'] = 'Ítem actualizado (ID #' . $id_item . ').';
            }
        }

        if ($action === 'toggle_item') {
            $id_item = isset($_POST['id_item']) ? (int)$_POST['id_item'] : 0;
            if ($id_item <= 0) throw new Exception('ID inválido.');

            $t = $mysqli->prepare("UPDATE rep_items SET activo=IF(activo=1,0,1) WHERE id_item=?");
            if (!$t) throw new Exception('Prepare toggle: ' . $mysqli->error);
            $t->bind_param('i', $id_item);
            if (!$t->execute()) throw new Exception('Exec toggle: ' . $t->error);
            $t->close();

            $_SESSION['flash_ok'] = 'Estado del ítem actualizado.';
        }

        if ($action === 'mov_inv') {
            $tipo     = isset($_POST['tipo']) ? $_POST['tipo'] : '';
            $id_item  = isset($_POST['id_item']) ? (int)$_POST['id_item'] : 0;
            $cant_s   = isset($_POST['cantidad']) ? $_POST['cantidad'] : '0';
            $cantidad = (float)str_replace(',', '', $cant_s);
            $ajuste_to_s = isset($_POST['ajuste_to']) ? $_POST['ajuste_to'] : '';
            $ajuste_to   = $ajuste_to_s === '' ? null : (float)str_replace(',', '', $ajuste_to_s);

            if (!in_array($tipo, array('ENTRADA', 'SALIDA', 'AJUSTE'), true)) throw new Exception('Tipo de movimiento inválido.');
            if ($id_item <= 0) throw new Exception('Selecciona un ítem.');
            if ($tipo !== 'AJUSTE' && $cantidad <= 0) throw new Exception('La cantidad debe ser > 0.');

            // Precio vigente (snapshot)
            $desc = '';
            $precio = 0.00;
            $qi = $mysqli->prepare("SELECT descripcion, precio_actual FROM rep_items WHERE id_item=? LIMIT 1");
            if (!$qi) throw new Exception('Prepare get item: ' . $mysqli->error);
            $qi->bind_param('i', $id_item);
            $qi->execute();
            $qi->bind_result($desc, $precio);
            $qi->fetch();
            $qi->close();

            // Existencia actual
            $exist = 0.0;
            $hasRow = false;
            $qe = $mysqli->prepare("SELECT existencia FROM rep_inventario WHERE id_almacen=? AND id_item=? LIMIT 1");
            if (!$qe) throw new Exception('Prepare get exist: ' . $mysqli->error);
            $qe->bind_param('ii', $id_almacen, $id_item);
            $qe->execute();
            $qe->bind_result($exist);
            if ($qe->fetch()) $hasRow = true;
            $qe->close();
            $exist = (float)$exist;

            if ($tipo === 'ENTRADA') {
                // Upsert existencia += cantidad
                $ins = $mysqli->prepare("
                    INSERT INTO rep_inventario (id_almacen, id_item, existencia)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE existencia = existencia + VALUES(existencia)
                ");
                if (!$ins) throw new Exception('Prepare inv entrada: ' . $mysqli->error);
                $ins->bind_param('iid', $id_almacen, $id_item, $cantidad);
                if (!$ins->execute()) throw new Exception('Exec inv entrada: ' . $ins->error);
                $ins->close();

                $costo_total = round($precio * $cantidad, 2);
                $m = $mysqli->prepare("
                    INSERT INTO rep_movs_inv
                    (fecha, id_almacen, id_item, tipo, referencia_tipo, referencia_id, cantidad, costo_unitario, costo_total, usuario)
                    VALUES (NOW(), ?, ?, 'ENTRADA', 'OTRO', NULL, ?, ?, ?, ?)
                ");
                if (!$m) throw new Exception('Prepare mov entrada: ' . $mysqli->error);
                $m->bind_param('iiddds', $id_almacen, $id_item, $cantidad, $precio, $costo_total, $usuario);
                if (!$m->execute()) throw new Exception('Exec mov entrada: ' . $m->error);
                $m->close();
            } elseif ($tipo === 'SALIDA') {
                // Validar stock suficiente (opcional)
                // if ($exist < $cantidad) throw new Exception('Existencia insuficiente.');

                $ins = $mysqli->prepare("
                    INSERT INTO rep_inventario (id_almacen, id_item, existencia)
                    VALUES (?, ?, 0)
                    ON DUPLICATE KEY UPDATE existencia = existencia - ?
                ");
                if (!$ins) throw new Exception('Prepare inv salida: ' . $mysqli->error);
                $ins->bind_param('iid', $id_almacen, $id_item, $cantidad);
                if (!$ins->execute()) throw new Exception('Exec inv salida: ' . $ins->error);
                $ins->close();

                $costo_total = round($precio * $cantidad, 2);
                $m = $mysqli->prepare("
                    INSERT INTO rep_movs_inv
                    (fecha, id_almacen, id_item, tipo, referencia_tipo, referencia_id, cantidad, costo_unitario, costo_total, usuario)
                    VALUES (NOW(), ?, ?, 'SALIDA', 'OTRO', NULL, ?, ?, ?, ?)
                ");
                if (!$m) throw new Exception('Prepare mov salida: ' . $mysqli->error);
                $m->bind_param('iiddds', $id_almacen, $id_item, $cantidad, $precio, $costo_total, $usuario);
                if (!$m->execute()) throw new Exception('Exec mov salida: ' . $m->error);
                $m->close();
            } else { // AJUSTE (a existencia exacta)
                if ($ajuste_to === null || $ajuste_to < 0) throw new Exception('Proporciona la nueva existencia (>=0) para ajuste.');
                $delta = $ajuste_to - $exist;

                if ($hasRow) {
                    $u = $mysqli->prepare("UPDATE rep_inventario SET existencia=? WHERE id_almacen=? AND id_item=?");
                    if (!$u) throw new Exception('Prepare up ajuste: ' . $mysqli->error);
                    $u->bind_param('dii', $ajuste_to, $id_almacen, $id_item);
                    if (!$u->execute()) throw new Exception('Exec up ajuste: ' . $u->error);
                    $u->close();
                } else {
                    $ins = $mysqli->prepare("INSERT INTO rep_inventario (id_almacen, id_item, existencia) VALUES (?,?,?)");
                    if (!$ins) throw new Exception('Prepare ins ajuste: ' . $mysqli->error);
                    $ins->bind_param('iid', $id_almacen, $id_item, $ajuste_to);
                    if (!$ins->execute()) throw new Exception('Exec ins ajuste: ' . $ins->error);
                    $ins->close();
                }

                $cant_log = abs($delta); // registro en positivo; el tipo indica si subió/bajó
                $costo_total = round($precio * $cant_log, 2);
                $m = $mysqli->prepare("
                    INSERT INTO rep_movs_inv
                    (fecha, id_almacen, id_item, tipo, referencia_tipo, referencia_id, cantidad, costo_unitario, costo_total, usuario)
                    VALUES (NOW(), ?, ?, 'AJUSTE', 'AJUSTE', NULL, ?, ?, ?, ?)
                ");
                if (!$m) throw new Exception('Prepare mov ajuste: ' . $mysqli->error);
                $m->bind_param('iiddds', $id_almacen, $id_item, $cant_log, $precio, $costo_total, $usuario);
                if (!$m->execute()) throw new Exception('Exec mov ajuste: ' . $m->error);
                $m->close();
            }

            $_SESSION['flash_ok'] = 'Movimiento registrado.';
        }

        // Commit
        if (method_exists($mysqli, 'commit')) $mysqli->commit();
        else $mysqli->query('COMMIT');

        header('Location: rep_inventario_admin.php', true, 303);
        exit;
    } catch (Exception $ex) {
        if (method_exists($mysqli, 'rollback')) $mysqli->rollback();
        else $mysqli->query('ROLLBACK');
        $_SESSION['flash_err'] = $ex->getMessage();
        header('Location: rep_inventario_admin.php', true, 303);
        exit;
    }
}

/* ========== Cargas (GET) ========== */
// Ítems + existencia (almacén central)
$items = array();
$sqlItems = "
SELECT i.id_item, i.clave, i.descripcion, i.unidad, i.precio_actual, i.activo,
       IFNULL(inv.existencia,0) AS existencia
FROM rep_items i
LEFT JOIN rep_inventario inv
  ON inv.id_item=i.id_item AND inv.id_almacen={$id_almacen}
ORDER BY i.descripcion ASC";
if ($rs = $mysqli->query($sqlItems)) {
    while ($r = $rs->fetch_assoc()) $items[] = $r;
    $rs->free();
}

// Movimientos recientes
$movs = array();
$sqlMov = "
SELECT m.fecha, m.id_item, i.descripcion, m.tipo, m.cantidad, m.costo_unitario, m.costo_total, m.usuario
FROM rep_movs_inv m
LEFT JOIN rep_items i ON i.id_item=m.id_item
ORDER BY m.fecha DESC
LIMIT 100";
if ($rs = $mysqli->query($sqlMov)) {
    while ($r = $rs->fetch_assoc()) $movs[] = $r;
    $rs->free();
}

// Historial de precios reciente
$precios = array();
$sqlPH = "
SELECT h.fecha, h.id_item, i.descripcion, h.precio, h.usuario
FROM rep_precios_hist h
LEFT JOIN rep_items i ON i.id_item=h.id_item
ORDER BY h.fecha DESC
LIMIT 100";
if ($rs = $mysqli->query($sqlPH)) {
    while ($r = $rs->fetch_assoc()) $precios[] = $r;
    $rs->free();
}

/* Flash */
$msg = isset($_SESSION['flash_ok']) ? $_SESSION['flash_ok'] : '';
$err = isset($_SESSION['flash_err']) ? $_SESSION['flash_err'] : '';
// === Tab actual (items | movs | precios) ===
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'items';
$allowed_tabs = array('items', 'movs', 'precios');
if (!in_array($tab, $allowed_tabs, true)) $tab = 'items';

unset($_SESSION['flash_ok'], $_SESSION['flash_err']);
?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Almacén / Inventario</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['ui-sans-serif', 'system-ui', 'Segoe UI', 'Roboto', 'Arial', 'sans-serif']
                    },
                    colors: {
                        brand: {
                            DEFAULT: '#2563eb',
                            dark: '#1d4ed8'
                        }
                    }
                }
            }
        }
    </script>
</head>

<body class="bg-gradient-to-b from-white to-slate-50 text-slate-900">
    <header class="sticky top-0 z-40 bg-white/90 backdrop-blur border-b border-slate-200">
        <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="inline-block w-2.5 h-2.5 rounded-full bg-brand shadow-[0_0_0_6px_rgba(37,99,235,.15)]"></span>
                <h1 class="text-base sm:text-lg font-extrabold tracking-tight">Almacén / Inventario</h1>
            </div>
            <nav class="flex items-center gap-2">
                <a href="menu.php" class="inline-flex items-center px-3 py-2 rounded-lg text-brand border border-brand hover:bg-brand hover:text-white transition">
                    ← Volver al menú
                </a>
            </nav>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-6 space-y-6">
        <!-- Mensajes -->
        <?php if ($msg): ?>
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 p-3 text-sm font-bold"><?php echo h($msg); ?></div>
        <?php endif; ?>
        <?php if ($err): ?>
            <div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-800 p-3 text-sm font-bold"><?php echo h($err); ?></div>
        <?php endif; ?>
        <!-- NAV de pestañas -->
        <nav class="max-w-7xl mx-auto mb-2">
            <div class="inline-flex rounded-xl border border-slate-200 bg-white p-1">
                <a href="?tab=items"
                    class="px-3 py-2 rounded-lg text-sm font-bold <?php echo $tab === 'items' ? 'bg-brand text-white' : 'text-slate-700 hover:bg-slate-50'; ?>">
                    Ítems
                </a>
                <a href="?tab=movs"
                    class="px-3 py-2 rounded-lg text-sm font-bold <?php echo $tab === 'movs' ? 'bg-brand text-white' : 'text-slate-700 hover:bg-slate-50'; ?>">
                    Movimientos
                </a>
                <a href="?tab=precios"
                    class="px-3 py-2 rounded-lg text-sm font-bold <?php echo $tab === 'precios' ? 'bg-brand text-white' : 'text-slate-700 hover:bg-slate-50'; ?>">
                    Historial de precios
                </a>
            </div>
        </nav>

        <!-- Panel 1: Alta/Edición de Ítems -->
        <section id="sec-items" data-sec="items"
            class="bg-white border border-slate-200 rounded-2xl shadow-lg shadow-slate-200/40"
            style="<?php echo $tab === 'items' ? '' : 'display:none'; ?>">

            <div class="p-5 sm:p-6 border-b border-slate-100">
                <h2 class="text-lg font-extrabold">Ítems / Piezas / Equipos</h2>
                <p class="text-sm text-slate-500 mt-1">Da de alta, edita y cambia el precio (se registra en historial).</p>
            </div>
            <div class="p-5 sm:p-6">
                <form method="post" class="grid grid-cols-1 md:grid-cols-6 gap-4">
                    <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                    <input type="hidden" name="action" value="save_item">

                    <div class="md:col-span-2">
                        <label class="block text-sm font-bold mb-1">Clave (SKU) opcional</label>
                        <input type="text" name="clave" maxlength="50"
                            class="w-full rounded-xl border-slate-300 px-3 py-2.5 text-sm">
                    </div>

                    <div class="md:col-span-3">
                        <label class="block text-sm font-bold mb-1">Descripción</label>
                        <input type="text" name="descripcion" maxlength="180" required
                            class="w-full rounded-xl border-slate-300 px-3 py-2.5 text-sm">
                    </div>

                    <div>
                        <label class="block text-sm font-bold mb-1">Unidad</label>
                        <input type="text" name="unidad" maxlength="20" value="pza"
                            class="w-full rounded-xl border-slate-300 px-3 py-2.5 text-sm">
                    </div>

                    <div>
                        <label class="block text-sm font-bold mb-1">Precio actual</label>
                        <input type="number" name="precio" min="0" step="0.01" value="0.00"
                            class="w-full rounded-xl border-slate-300 px-3 py-2.5 text-sm text-right tabular-nums">
                    </div>

                    <div>
                        <label class="block text-sm font-bold mb-1">Activo</label>
                        <select name="activo"
                            class="w-full rounded-xl border-slate-300 bg-slate-50 focus:bg-white px-3 py-2.5 text-sm">
                            <option value="1">Sí</option>
                            <option value="0">No</option>
                        </select>
                    </div>

                    <div class="md:col-span-6 flex items-center justify-end gap-3">
                        <button type="submit"
                            class="inline-flex items-center px-4 py-2.5 rounded-xl bg-brand text-white text-sm font-extrabold hover:bg-brand-dark shadow-sm">
                            Guardar ítem
                        </button>
                    </div>
                </form>

                <!-- Tabla de Ítems -->
                <div class="mt-6 overflow-x-auto rounded-xl border border-slate-200">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-slate-700">
                            <tr class="border-b border-slate-200">
                                <th class="text-left font-bold px-3 py-2">ID</th>
                                <th class="text-left font-bold px-3 py-2">Clave</th>
                                <th class="text-left font-bold px-3 py-2">Descripción</th>
                                <th class="text-right font-bold px-3 py-2">Precio</th>
                                <th class="text-right font-bold px-3 py-2">Existencia</th>
                                <th class="text-center font-bold px-3 py-2">Estado</th>
                                <th class="text-center font-bold px-3 py-2">Acción</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (!empty($items)): foreach ($items as $it): ?>
                                    <tr class="bg-white hover:bg-slate-50">
                                        <td class="px-3 py-2"><?php echo (int)$it['id_item']; ?></td>
                                        <td class="px-3 py-2"><?php echo h($it['clave']); ?></td>
                                        <td class="px-3 py-2"><?php echo h($it['descripcion']); ?></td>
                                        <td class="px-3 py-2 text-right tabular-nums">$ <?php echo number_format((float)$it['precio_actual'], 2); ?></td>
                                        <td class="px-3 py-2 text-right tabular-nums"><?php echo number_format((float)$it['existencia'], 3); ?></td>
                                        <td class="px-3 py-2 text-center">
                                            <?php if ((int)$it['activo'] === 1): ?>
                                                <span class="px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-bold">Activo</span>
                                            <?php else: ?>
                                                <span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-500 border border-slate-300 text-xs font-bold">Inactivo</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-3 py-2 text-center">
                                            <form method="post" class="inline">
                                                <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                                                <input type="hidden" name="action" value="toggle_item">
                                                <input type="hidden" name="id_item" value="<?php echo (int)$it['id_item']; ?>">
                                                <button class="inline-flex items-center px-3 py-1.5 rounded-lg border border-slate-300 text-xs font-bold hover:bg-slate-50" type="submit">
                                                    Cambiar estado
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach;
                            else: ?>
                                <tr>
                                    <td colspan="7" class="px-3 py-3 text-center text-slate-500">Sin ítems</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- Panel 2: Movimientos -->
        <section id="sec-movs" data-sec="movs"
            class="bg-white border border-slate-200 rounded-2xl shadow-lg shadow-slate-200/40"
            style="<?php echo $tab === 'movs' ? '' : 'display:none'; ?>">

            <div class="p-5 sm:p-6 border-b border-slate-100">
                <h2 class="text-lg font-extrabold">Movimientos de inventario</h2>
                <p class="text-sm text-slate-500 mt-1">Registra ENTRADA, SALIDA o AJUSTE. Se usa el precio vigente del ítem.</p>
            </div>
            <div class="p-5 sm:p-6">
                <form method="post" class="grid grid-cols-1 md:grid-cols-6 gap-4">
                    <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                    <input type="hidden" name="action" value="mov_inv">

                    <div class="md:col-span-3">
                        <label class="block text-sm font-bold mb-1">Ítem</label>
                        <select name="id_item" required class="w-full rounded-xl border-slate-300 bg-slate-50 focus:bg-white px-3 py-2.5 text-sm">
                            <option value="">— Selecciona —</option>
                            <?php foreach ($items as $it): ?>
                                <option value="<?php echo (int)$it['id_item']; ?>">
                                    <?php echo h($it['descripcion']); ?> (ID <?php echo (int)$it['id_item']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-bold mb-1">Tipo</label>
                        <select id="mov_tipo" name="tipo" required class="w-full rounded-xl border-slate-300 bg-slate-50 focus:bg-white px-3 py-2.5 text-sm">
                            <option value="">— Selecciona tipo —</option>
                            <option value="ENTRADA">ENTRADA</option>
                            <option value="SALIDA">SALIDA</option>
                            <option value="AJUSTE">AJUSTE</option>
                        </select>
                    </div>

                    <!-- Cantidad (visible por defecto y para ENTRADA/SALIDA) -->
                    <div id="mov_rowCantidad">
                        <label class="block text-sm font-bold mb-1">Cantidad</label>
                        <input id="mov_inpCantidad" type="number" name="cantidad" min="0" step="0.001" value="1"
                            class="w-full rounded-xl border-slate-300 px-3 py-2.5 text-sm text-right tabular-nums">
                    </div>

                    <!-- Nueva existencia (solo AJUSTE; oculto por defecto) -->
                    <div id="mov_rowAjuste" style="display:none;">
                        <label class="block text-sm font-bold mb-1">Nueva existencia</label>
                        <input id="mov_inpAjuste" type="number" name="ajuste_to" min="0" step="0.001" disabled
                            class="w-full rounded-xl border-slate-300 px-3 py-2.5 text-sm text-right tabular-nums" placeholder="Ej. 100.000">
                    </div>

                    <div class="md:col-span-6 flex items-center justify-end">
                        <button type="submit" class="inline-flex items-center px-4 py-2.5 rounded-xl bg-brand text-white text-sm font-extrabold hover:bg-brand-dark shadow-sm">
                            Guardar movimiento
                        </button>
                    </div>
                </form>


                <!-- Tabla de Movimientos -->
                <div class="mt-6 overflow-x-auto rounded-xl border border-slate-200">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-slate-700">
                            <tr class="border-b border-slate-200">
                                <th class="text-left font-bold px-3 py-2">Fecha</th>
                                <th class="text-left font-bold px-3 py-2">Ítem</th>
                                <th class="text-left font-bold px-3 py-2">Tipo</th>
                                <th class="text-right font-bold px-3 py-2">Cantidad</th>
                                <th class="text-right font-bold px-3 py-2">P. Unitario</th>
                                <th class="text-right font-bold px-3 py-2">Total</th>
                                <th class="text-left font-bold px-3 py-2">Usuario</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (!empty($movs)): foreach ($movs as $m): ?>
                                    <tr class="bg-white hover:bg-slate-50">
                                        <td class="px-3 py-2"><?php echo h($m['fecha']); ?></td>
                                        <td class="px-3 py-2"><?php echo h($m['descripcion']); ?></td>
                                        <td class="px-3 py-2">
                                            <span class="px-2 py-0.5 rounded-full border text-xs font-bold
                    <?php
                                    echo $m['tipo'] === 'ENTRADA' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : ($m['tipo'] === 'SALIDA' ? 'bg-amber-50 text-amber-700 border-amber-200' :
                                        'bg-sky-50 text-sky-700 border-sky-200');
                    ?>">
                                                <?php echo h($m['tipo']); ?>
                                            </span>
                                        </td>
                                        <td class="px-3 py-2 text-right tabular-nums"><?php echo number_format((float)$m['cantidad'], 3); ?></td>
                                        <td class="px-3 py-2 text-right tabular-nums">$ <?php echo number_format((float)$m['costo_unitario'], 2); ?></td>
                                        <td class="px-3 py-2 text-right tabular-nums">$ <?php echo number_format((float)$m['costo_total'], 2); ?></td>
                                        <td class="px-3 py-2"><?php echo h($m['usuario']); ?></td>
                                    </tr>
                                <?php endforeach;
                            else: ?>
                                <tr>
                                    <td colspan="7" class="px-3 py-3 text-center text-slate-500">Sin movimientos</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- Panel 3: Historial de precios -->
        <section id="sec-precios" data-sec="precios"
            class="bg-white border border-slate-200 rounded-2xl shadow-lg shadow-slate-200/40"
            style="<?php echo $tab === 'precios' ? '' : 'display:none'; ?>">

            <div class="p-5 sm:p-6 border-b border-slate-100">
                <h2 class="text-lg font-extrabold">Historial de precios</h2>
                <p class="text-sm text-slate-500 mt-1">Últimos cambios de precio registrados por usuario.</p>
            </div>
            <div class="p-5 sm:p-6">
                <div class="overflow-x-auto rounded-xl border border-slate-200">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-slate-700">
                            <tr class="border-b border-slate-200">
                                <th class="text-left font-bold px-3 py-2">Fecha</th>
                                <th class="text-left font-bold px-3 py-2">Ítem</th>
                                <th class="text-right font-bold px-3 py-2">Precio</th>
                                <th class="text-left font-bold px-3 py-2">Usuario</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (!empty($precios)): foreach ($precios as $p): ?>
                                    <tr class="bg-white hover:bg-slate-50">
                                        <td class="px-3 py-2"><?php echo h($p['fecha']); ?></td>
                                        <td class="px-3 py-2"><?php echo h($p['descripcion']); ?></td>
                                        <td class="px-3 py-2 text-right tabular-nums">$ <?php echo number_format((float)$p['precio'], 2); ?></td>
                                        <td class="px-3 py-2"><?php echo h($p['usuario']); ?></td>
                                    </tr>
                                <?php endforeach;
                            else: ?>
                                <tr>
                                    <td colspan="4" class="px-3 py-3 text-center text-slate-500">Sin cambios de precio recientes</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
    <script>
        (function() {
            var tipo = document.getElementById('mov_tipo');
            var rowC = document.getElementById('mov_rowCantidad');
            var rowA = document.getElementById('mov_rowAjuste');
            var inpC = document.getElementById('mov_inpCantidad');
            var inpA = document.getElementById('mov_inpAjuste');

            function updateFields() {
                var v = tipo.value;
                if (v === 'AJUSTE') {
                    // Solo AJUSTE → mostrar Nueva existencia
                    rowC.style.display = 'none';
                    rowA.style.display = '';
                    inpC.disabled = true;
                    inpC.value = '';
                    inpA.disabled = false;
                } else {
                    // ENTRADA / SALIDA / sin selección → mostrar Cantidad
                    rowC.style.display = '';
                    rowA.style.display = 'none';
                    inpC.disabled = false;
                    inpA.disabled = true;
                    inpA.value = '';
                }
            }

            if (tipo.addEventListener) tipo.addEventListener('change', updateFields, false);
            else tipo.attachEvent('onchange', updateFields);

            // Estado inicial
            updateFields();
        })();
    </script>


</body>

</html>