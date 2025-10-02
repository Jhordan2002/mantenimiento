<?php
@session_start();
require 'conexionn.php';
include 'seguridad.php';
$mysqli->set_charset('utf8');

/* ========== Util ========== */
function html_escape($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function cleanCuenta($s)
{
    $s = str_replace(array("\r", "\n", "\xc2\xa0", ' '), '', (string)$s);
    return trim($s);
}
function get_csrf_token(array &$session, $key = 'csrf_repo')
{
    if (!empty($session[$key])) return $session[$key];
    $session[$key] = function_exists('openssl_random_pseudo_bytes')
        ? bin2hex(openssl_random_pseudo_bytes(16))
        : sha1(uniqid(mt_rand(), true));
    return $session[$key];
}
$csrf = get_csrf_token($_SESSION, 'csrf_repo');

/* ========== Catálogos ========== */
$items = array();
if ($st = $mysqli->prepare("SELECT id_item, descripcion, precio_actual FROM rep_items WHERE activo=1 ORDER BY descripcion")) {
    if ($st->execute()) {
        $st->bind_result($iid, $idesc, $p);
        while ($st->fetch()) $items[] = array('id_item' => $iid, 'desc' => $idesc, 'precio' => $p);
    }
    $st->close();
}

$sucursales = array();
$sqlSuc = "
SELECT DISTINCT
  u.clave_suc,
  u.sucursal,
  TRIM(REPLACE(REPLACE(REPLACE(u.cuenta, '\r',''), '\n',''), ' ', '')) AS cuenta_limpia
FROM usuarios u
WHERE u.estado='1' AND u.sucursal IS NOT NULL
ORDER BY u.sucursal";
if ($rs = $mysqli->query($sqlSuc)) {
    while ($r = $rs->fetch_assoc()) $sucursales[] = $r;
    $rs->free();
}

/* ========== POST: guardar (PRG) ========== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['csrf']) ? $_POST['csrf'] : '';
    if ($token !== $csrf) {
        $_SESSION['flash_err'] = 'Token inválido. Recarga la página.';
        header('Location: rep_reposiciones_captura.php', true, 303);
        exit;
    }

    $usuario         = isset($_SESSION['usuarioactual']) ? $_SESSION['usuarioactual'] : 'sistema';
    $clave_suc       = isset($_POST['clave_suc']) ? (int)$_POST['clave_suc'] : 0;
    $sucursal_nombre = isset($_POST['sucursal_nombre']) ? trim($_POST['sucursal_nombre']) : '';
    $cuenta          = isset($_POST['cuenta']) ? cleanCuenta($_POST['cuenta']) : '';
    $id_almacen      = 1; // almacén central

    $itm_ids = isset($_POST['item_id']) && is_array($_POST['item_id']) ? $_POST['item_id'] : array();
    $cant    = isset($_POST['cantidad']) && is_array($_POST['cantidad']) ? $_POST['cantidad'] : array();

    $err = '';
    if ($clave_suc <= 0 || $sucursal_nombre === '') $err = 'Selecciona una sucursal válida.';
    if (!$err && empty($itm_ids)) $err = 'Agrega al menos un renglón de reposición.';

    $rows = array();
    if (!$err) {
        $n = count($itm_ids);
        for ($i = 0; $i < $n; $i++) {
            $iid  = (int)$itm_ids[$i];
            $qtyS = isset($cant[$i]) ? $cant[$i] : '0';
            $qty  = (float)str_replace(',', '.', $qtyS);
            if ($iid > 0 && $qty > 0) $rows[] = array($iid, $qty);
        }
        if (empty($rows)) $err = 'Cantidades inválidas.';
    }

    if ($err) {
        $_SESSION['flash_err'] = $err;
        header('Location: rep_reposiciones_captura.php', true, 303);
        exit;
    }

    // Transacción compatible 5.6
    if (method_exists($mysqli, 'begin_transaction')) $mysqli->begin_transaction();
    else $mysqli->query('START TRANSACTION');

    try {
        // Encabezado (sin nota)
        $insH = $mysqli->prepare("
 INSERT INTO rep_reposicion (fecha, clave_suc, sucursal_nombre, cuenta, total, usuario)
 VALUES (NOW(), ?, ?, ?, 0, ?)");
        if (!$insH) throw new Exception('Prepare encabezado: ' . $mysqli->error);
        $insH->bind_param('isss', $clave_suc, $sucursal_nombre, $cuenta, $usuario);
        if (!$insH->execute()) throw new Exception('Exec encabezado: ' . $insH->error);
        $id_repo = $insH->insert_id;
        $insH->close();

        $total_repo = 0.00;

        // Preparadas
        $qPrecio = $mysqli->prepare("
    SELECT descripcion, precio_actual, COALESCE(afecta_inventario,1)
    FROM rep_items
    WHERE id_item=? LIMIT 1
");
        if (!$qPrecio) throw new Exception('Prepare precio: ' . $mysqli->error);


        $insD = $mysqli->prepare("
            INSERT INTO rep_reposicion_det (id_repo, id_item, descripcion_snap, cantidad, precio_unitario, total)
            VALUES (?, ?, ?, ?, ?, ?)");
        if (!$insD) throw new Exception('Prepare detalle: ' . $mysqli->error);

        $upInv = $mysqli->prepare("
    INSERT INTO rep_inventario (id_almacen, id_item, existencia)
    VALUES (?, ?, 0)
    ON DUPLICATE KEY UPDATE existencia = existencia - ?
");
        if (!$upInv) throw new Exception('Prepare inventario: ' . $mysqli->error);


        // Movimientos (sin nota)
        $insMov = $mysqli->prepare("
  INSERT INTO rep_movs_inv
  (fecha, id_almacen, id_item, tipo, referencia_tipo, referencia_id, cantidad, costo_unitario, costo_total, usuario)
  VALUES (NOW(), ?, ?, 'REPOSICION', 'REPOSICION', ?, ?, ?, ?, ?)
");
        if (!$insMov) throw new Exception('Prepare mov: ' . $mysqli->error);


        for ($k = 0; $k < count($rows); $k++) {
            $id_item = $rows[$k][0];
            $qty     = $rows[$k][1];

            // Precio + desc + flag de inventario
            $desc = '';
            $pu   = 0.00;
            $aff  = 1; // por defecto afecta inventario
            $qPrecio->bind_param('i', $id_item);
            if (!$qPrecio->execute()) throw new Exception('Exec precio: ' . $qPrecio->error);
            $qPrecio->bind_result($desc, $pu, $aff);
            $qPrecio->fetch();
            $qPrecio->free_result();


            $line_total = round($pu * $qty, 2);
            $total_repo = round($total_repo + $line_total, 2);

            // Detalle
            $insD->bind_param('iisddd', $id_repo, $id_item, $desc, $qty, $pu, $line_total);
            if (!$insD->execute()) throw new Exception('Exec detalle: ' . $insD->error);
            // Inventario: RESTA existencia solo si el ítem afecta inventario
            if ((int)$aff === 1) {
                // placeholders: id_almacen (i), id_item (i), qty (d)
                $upInv->bind_param('iid', $id_almacen, $id_item, $qty);
                if (!$upInv->execute()) throw new Exception('Exec inventario: ' . $upInv->error);
            }


            // Movimiento: solo materiales (mano de obra NO genera mov de inventario)
            if ((int)$aff === 1) {
                // tipos: i,i,i,d,d,d,s
                $insMov->bind_param('iiiddds', $id_almacen, $id_item, $id_repo, $qty, $pu, $line_total, $usuario);
                if (!$insMov->execute()) throw new Exception('Exec mov: ' . $insMov->error);
            }
        }

        // Total encabezado
        $upH = $mysqli->prepare("UPDATE rep_reposicion SET total=? WHERE id_repo=?");
        if (!$upH) throw new Exception('Prepare total: ' . $mysqli->error);
        $upH->bind_param('di', $total_repo, $id_repo);
        if (!$upH->execute()) throw new Exception('Exec total: ' . $upH->error);
        $upH->close();

        // Bitácora (igual)
        if (function_exists('audit_log')) {
            $details = json_encode(array(
                'clave_suc' => (int)$clave_suc,
                'sucursal_nombre' => $sucursal_nombre,
                'cuenta' => $cuenta,
                'total' => $total_repo
            ), JSON_UNESCAPED_UNICODE);
            audit_log(
                $mysqli,
                $usuario,
                'reposicion',
                (int)$id_repo,
                'agregar',
                $details,
                isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
                isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''
            );
        }

        // Commit
        if (method_exists($mysqli, 'commit')) $mysqli->commit();
        else $mysqli->query('COMMIT');

        $_SESSION['flash_ok'] = 'Reposición guardada (Folio #' . $id_repo . ').';
        header('Location: rep_reposiciones_captura.php', true, 303);
        exit;
    } catch (Exception $ex) {
        if (method_exists($mysqli, 'rollback')) $mysqli->rollback();
        else $mysqli->query('ROLLBACK');
        $_SESSION['flash_err'] = 'Error al guardar: ' . $ex->getMessage();
        header('Location: rep_reposiciones_captura.php', true, 303);
        exit;
    }
}

/* ========== Flash (PRG) ========== */
$msg = isset($_SESSION['flash_ok']) ? $_SESSION['flash_ok'] : '';
$err = isset($_SESSION['flash_err']) ? $_SESSION['flash_err'] : '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);
?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Reposiciones · Nueva</title>
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
                <h1 class="text-base sm:text-lg font-extrabold tracking-tight">Reposiciones · Nueva</h1>
            </div>
            <nav class="flex items-center gap-2">
                <a href="menu.php" class="inline-flex items-center px-3 py-2 rounded-lg text-brand border border-brand hover:bg-brand hover:text-white transition">
                    ← Volver al menú
                </a>
            </nav>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-6">
        <div class="bg-white border border-slate-200 rounded-2xl shadow-lg shadow-slate-200/40">
            <div class="p-5 sm:p-6 border-b border-slate-100">
                <h2 class="text-lg font-extrabold">Datos de la reposición</h2>
            </div>

            <form method="post" id="fm" class="p-5 sm:p-6">
                <input type="hidden" name="csrf" value="<?php echo html_escape($csrf); ?>">
                <input type="hidden" name="sucursal_nombre" id="sucnom">
                <input type="hidden" name="cuenta" id="cta">

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-bold mb-1">Sucursal</label>
                        <select name="clave_suc" id="suc" required
                            class="w-full rounded-xl border-slate-300 bg-slate-50 focus:bg-white focus:border-brand focus:ring-brand px-3 py-2.5 text-sm">
                            <option value="">— Selecciona —</option>
                            <?php foreach ($sucursales as $s): ?>
                                <option
                                    value="<?php echo (int)$s['clave_suc']; ?>"
                                    data-nom="<?php echo html_escape($s['sucursal']); ?>"
                                    data-cta="<?php echo html_escape($s['cuenta_limpia']); ?>">
                                    <?php echo html_escape($s['sucursal']); ?> — <?php echo html_escape($s['cuenta_limpia']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-bold mb-1">Cuenta</label>
                        <input type="text" id="cta_vis" readonly
                            class="w-full rounded-xl border-slate-300 bg-slate-50 px-3 py-2.5 text-sm"
                            placeholder="Se llena al elegir sucursal">
                    </div>
                </div>

                <div class="mt-6">
                    <div class="flex items-center justify-between gap-3 mb-3">
                        <h3 class="text-sm font-extrabold uppercase tracking-wide text-slate-600">Renglones</h3>
                        <div class="flex items-center gap-2">
                            <button type="button" id="add"
                                class="inline-flex items-center gap-2 px-3 py-2 rounded-xl border border-slate-300 text-sm font-bold hover:bg-slate-50">
                                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M8 3v10M3 8h10" />
                                </svg>
                                Agregar renglón
                            </button>

                            <button type="button" id="clear"
                                class="inline-flex items-center gap-2 px-3 py-2 rounded-xl border border-rose-200 text-rose-700 text-sm font-bold hover:bg-rose-50">
                                Limpiar
                            </button>

                            <!-- 🔵 NUEVO botón plantilla -->
                            <button type="button" id="pulir"
                                class="inline-flex items-center gap-2 px-3 py-2 rounded-xl border border-sky-300 text-sky-700 text-sm font-bold hover:bg-sky-50">
                                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M3 8h10M3 12h7M3 4h7" />
                                </svg>
                                Material para pulir
                            </button>
                        </div>

                    </div>

                    <div class="overflow-x-auto rounded-xl border border-slate-200">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50 text-slate-700">
                                <tr class="border-b border-slate-200">
                                    <th class="text-left font-bold px-3 py-2 w-[48%]">Reposicion</th>
                                    <th class="text-left font-bold px-3 py-2 w-[14%]">Cantidad</th>
                                    <th class="text-right font-bold px-3 py-2 w-[18%]">Precio unitario</th>
                                    <th class="text-right font-bold px-3 py-2 w-[18%]">Importe</th>
                                    <th class="px-2 py-2 w-10"></th>
                                </tr>
                            </thead>
                            <tbody id="tb" class="divide-y divide-slate-100"></tbody>
                        </table>
                    </div>

                    <div class="mt-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 rounded-xl border border-dashed border-slate-300 px-4 py-3 bg-white">
                        <div class="text-right">
                            <span class="text-sm font-bold text-slate-600 mr-3">Total:</span>
                            <span id="grand" class="font-extrabold text-lg tabular-nums">$ 0.00</span>
                        </div>
                    </div>

                    <div class="mt-5 flex items-center justify-end gap-3">
                        <a href="menu.php"
                            class="inline-flex items-center px-4 py-2.5 rounded-xl border border-slate-300 text-sm font-bold hover:bg-slate-50">Cancelar</a>
                        <button type="submit" id="save"
                            class="inline-flex items-center px-4 py-2.5 rounded-xl bg-brand text-white text-sm font-extrabold hover:bg-brand-dark shadow-sm">
                            Guardar reposición
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </main>

    <div id="toast" class="fixed right-4 top-4 z-50 hidden min-w-[260px] max-w-md rounded-xl border p-3 text-sm font-bold shadow-lg"></div>

    <script>
        var ITEMS = <?php echo json_encode($items); ?>;

        (function() {
            var suc = document.getElementById('suc');

            function onSuc() {
                var o = suc.options[suc.selectedIndex];
                var nom = o ? (o.getAttribute('data-nom') || '') : '';
                var cta = o ? (o.getAttribute('data-cta') || '') : '';
                document.getElementById('sucnom').value = nom;
                document.getElementById('cta').value = cta;
                document.getElementById('cta_vis').value = cta;
            }
            if (suc.addEventListener) suc.addEventListener('change', onSuc, false);
            else suc.attachEvent('onchange', onSuc);
        })();

        var TB = document.getElementById('tb');
        document.getElementById('add').onclick = function() {
            addRow();
        };
        document.getElementById('clear').onclick = function() {
            TB.innerHTML = '';
            calc();
            addRow();
        };

        function money(n) {
            n = isFinite(n) ? n : 0;
            var s = n.toFixed(2);
            return '$ ' + s.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }

        function addRow(iid, qty) {
            // Si ya existe el ítem en la tabla, solo aumenta la cantidad
            if (iid) {
                var rows = TB.getElementsByTagName('tr');
                for (var r = 0; r < rows.length; r++) {
                    var s0 = rows[r].getElementsByTagName('select')[0];
                    if (s0 && parseInt(s0.value || '0', 10) === parseInt(iid, 10)) {
                        var q0 = rows[r].querySelector('input[name="cantidad[]"]');
                        var curr = parseFloat(q0.value || '0');
                        var addv = (qty == null ? 1 : qty);
                        q0.value = (curr + addv);
                        calc();
                        return;
                    }
                }
            }

            var tr = document.createElement('tr');
            tr.className = "bg-white hover:bg-slate-50";

            var sel = '<select name="item_id[]" required class="w-full rounded-lg border border-slate-300 focus:border-brand focus:ring-brand px-2.5 py-2 text-sm"><option value="">—</option>';
            for (var i = 0; i < ITEMS.length; i++) {
                var it = ITEMS[i];
                sel += '<option value="' + it.id_item + '" data-p="' + (it.precio || 0) + '">' +
                    String(it.desc).replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</option>';
            }
            sel += '</select>';

            tr.innerHTML =
                '<td class="px-3 py-2">' + sel + '</td>' +
                '<td class="px-3 py-2"><input type="number" name="cantidad[]" min="0" step="0.001" value="1" class="w-28 rounded-lg border border-slate-300 px-2.5 py-2 text-right tabular-nums"/></td>' +
                '<td class="px-3 py-2 text-right tabular-nums pu">$ 0.00</td>' +
                '<td class="px-3 py-2 text-right tabular-nums lt">$ 0.00</td>' +
                '<td class="px-2 py-2 text-right"><button type="button" class="inline-flex items-center justify-center w-9 h-9 rounded-lg border border-slate-200 hover:bg-rose-50 hover:border-rose-300 text-rose-600 font-black" onclick="rmRow(this)">×</button></td>';

            TB.appendChild(tr);

            var s = tr.getElementsByTagName('select')[0];
            var q = tr.querySelector('input[name="cantidad[]"]');

            function updatePU() {
                var pu = parseFloat(s.options[s.selectedIndex] ? (s.options[s.selectedIndex].getAttribute('data-p') || '0') : '0');
                tr.querySelector('.pu').innerHTML = money(pu);
                // Recalcula importe de la fila
                var qtyv = parseFloat(q.value || '0');
                tr.querySelector('.lt').innerHTML = money((qtyv * pu) || 0);
            }

            function onChange() {
                updatePU();
                calc();
            }

            if (s.addEventListener) {
                s.addEventListener('change', onChange, false);
                q.addEventListener('input', calc, false);
            } else {
                s.attachEvent('onchange', onChange);
                q.attachEvent('onkeyup', calc);
            }

            // Preselección si viene iid/qty
            if (iid) s.value = String(iid);
            if (qty != null) q.value = qty;

            updatePU();
            calc();
        }
        // Plantilla de "Material para pulir"
        var TPL_PULIR = [{
                name: 'CRISTALIZADOR',
                qty: 1
            },
            {
                name: 'PASTA BLANCA',
                qty: 1
            },
            {
                name: 'MAGIC CUBETA',
                qty: 1
            },
            {
                name: 'DISCO BLANCO',
                qty: 1
            },
            {
                name: 'DISCO NEGRO',
                qty: 1
            },
            {
                name: 'DISCO CANELA',
                qty: 1
            },
            {
                name: 'RUST OFF',
                qty: 1
            },
            {
                name: 'MANO DE OBRA',
                qty: 1
            } // este no descuenta inventario en backend
        ];

        // Normaliza texto para match por nombre (sin acentos, case-insensitive)
        function norm(s) {
            return String(s || '')
                .toLowerCase()
                .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                .replace(/\s+/g, ' ')
                .trim();
        }

        function findItemIdByName(name) {
            var n = norm(name);
            for (var i = 0; i < ITEMS.length; i++) {
                if (norm(ITEMS[i].desc) === n) return ITEMS[i].id_item;
            }
            return null;
        }

        // Carga masiva de la plantilla
        (function() {
            var btn = document.getElementById('pulir');
            if (!btn) return;
            btn.onclick = function() {
                var missing = [];
                for (var i = 0; i < TPL_PULIR.length; i++) {
                    var tpl = TPL_PULIR[i];
                    var id = findItemIdByName(tpl.name);
                    if (id) {
                        addRow(id, tpl.qty);
                    } else {
                        missing.push(tpl.name);
                    }
                }
                if (missing.length) {
                    alert('No encontrados en catálogo: ' + missing.join(', '));
                }
            };
        })();


        function rmRow(btn) {
            var tr = btn.parentNode.parentNode;
            tr.parentNode.removeChild(tr);
            calc();
        }

        function calc() {
            var rows = TB.getElementsByTagName('tr'),
                tot = 0;
            for (var i = 0; i < rows.length; i++) {
                var tr = rows[i];
                var s = tr.getElementsByTagName('select')[0];
                var q = parseFloat(tr.querySelector('input[name="cantidad[]"]').value || '0');
                var pu = parseFloat(s && s.options[s.selectedIndex] ? (s.options[s.selectedIndex].getAttribute('data-p') || '0') : '0');
                var lt = (q * pu) || 0;
                var ltCell = tr.querySelector('.lt');
                if (ltCell) ltCell.innerHTML = money(lt);
                tot += lt;
            }
            document.getElementById('grand').innerHTML = money(tot);
        }

        addRow();

        (function() {
            var msg = <?php echo json_encode($msg); ?>;
            var err = <?php echo json_encode($err); ?>;
            var t = document.getElementById('toast');
            if (err) {
                t.className = "fixed right-4 top-4 z-50 min-w=[260px] max-w-md rounded-xl border border-rose-200 bg-rose-50 text-rose-800 p-3 text-sm font-bold shadow-lg";
                t.textContent = err;
                t.style.display = 'block';
                setTimeout(function() {
                    t.style.opacity = '0';
                    t.style.transform = 'translateY(-6px)';
                }, 2800);
                setTimeout(function() {
                    t.style.display = 'none';
                    t.removeAttribute('style');
                }, 3400);
            } else if (msg) {
                t.className = "fixed right-4 top-4 z-50 min-w=[260px] max-w-md rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 p-3 text-sm font-bold shadow-lg";
                t.textContent = msg;
                t.style.display = 'block';
                setTimeout(function() {
                    t.style.opacity = '0';
                    t.style.transform = 'translateY(-6px)';
                }, 2800);
                setTimeout(function() {
                    t.style.display = 'none';
                    t.removeAttribute('style');
                }, 3400);
            }
        })();
    </script>
</body>

</html>