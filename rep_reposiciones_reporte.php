<?php
@session_start();
require 'conexionn.php';
include 'seguridad.php';
$mysqli->set_charset('utf8');

if (empty($_SESSION['usuarioactual'])) {
    header('Location: login.php');
    exit;
}

/* ====== Utils ====== */
function htmlEscape($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function normalizeDateYmd($dateString)
{
    $date = date_create_from_format('Y-m-d', (string)$dateString);
    if ($date === false) return null;
    return date_format($date, 'Y-m-d');
}

function addOneDay($dateYmd)
{
    $timestamp = strtotime($dateYmd . ' +1 day');
    return $timestamp ? date('Y-m-d', $timestamp) : null;
}


/* ====== Catálogos mínimos ====== */
$items = [];
if ($st = $mysqli->prepare("SELECT id_item, descripcion FROM rep_items WHERE activo=1 ORDER BY descripcion")) {
    $st->execute();
    $st->bind_result($iid, $idesc);
    while ($st->fetch()) $items[] = ['id_item' => $iid, 'desc' => $idesc];
    $st->close();
}
$sucursales = [];
$sqlSuc = "
SELECT DISTINCT u.clave_suc, u.sucursal
FROM usuarios u
WHERE u.estado='1' AND u.sucursal IS NOT NULL
ORDER BY u.sucursal";
if ($rs = $mysqli->query($sqlSuc)) {
    while ($r = $rs->fetch_assoc()) $sucursales[] = $r;
    $rs->free();
}

/* ====== Filtros ====== */
$group = isset($_GET['group']) ? $_GET['group'] : 'det'; // det|folio|sucursal|item|dia
$allowed_groups = ['det', 'folio', 'sucursal', 'item', 'dia'];
if (!in_array($group, $allowed_groups, true)) $group = 'det';

$today = (new DateTime())->format('Y-m-d');
$first = (new DateTime('first day of this month'))->format('Y-m-d');

$f1 = normalizeDateYmd(isset($_GET['f1']) ? $_GET['f1'] : $first);
$f2 = normalizeDateYmd(isset($_GET['f2']) ? $_GET['f2'] : $today);

if (!$f1) $f1 = $first;
if (!$f2) $f2 = $today;
$f2p1 = addOneDay($f2); // exclusivo

$suc = isset($_GET['suc']) ? (int)$_GET['suc'] : 0;
$item_id = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$solo_mat = isset($_GET['solo_mat']) ? (int)$_GET['solo_mat'] : 0; // usar rep_items.afecta_inventario=1

$export = isset($_GET['export']) ? $_GET['export'] : ''; // csv

/* ====== Construcción dinámica de consulta ====== */
$types = '';
$params = [];

// WHERE base por rango de fechas (en encabezado)
$w = " r.fecha >= ? AND r.fecha < ? ";
$types .= 'ss';
$params[] = $f1;
$params[] = $f2p1;

if ($suc > 0) {
    $w .= " AND r.clave_suc = ? ";
    $types .= 'i';
    $params[] = $suc;
}
if ($item_id > 0) {
    $w .= " AND d.id_item = ? ";
    $types .= 'i';
    $params[] = $item_id;
}
if ($q !== '') {
    $w .= " AND ( d.descripcion_snap LIKE CONCAT('%',?,'%') OR r.sucursal_nombre LIKE CONCAT('%',?,'%') OR r.cuenta LIKE CONCAT('%',?,'%') ) ";
    $types .= 'sss';
    $params[] = $q;
    $params[] = $q;
    $params[] = $q;
}
if ($solo_mat === 1) {
    // solo renglones con items que afectan inventario
    $w .= " AND COALESCE(it.afecta_inventario,1)=1 ";
}

$sql = '';
$headers = [];
$bind_types = $types;
$bind_params = $params;

switch ($group) {
    case 'folio':
        $sql = "
        SELECT r.id_repo AS folio,
               DATE_FORMAT(r.fecha,'%Y-%m-%d %H:%i') AS fecha,
               r.clave_suc, r.sucursal_nombre, r.cuenta, r.usuario,
               SUM(d.cantidad) AS piezas,
               SUM(d.total) AS total
        FROM rep_reposicion r
        JOIN rep_reposicion_det d ON d.id_repo=r.id_repo
        LEFT JOIN rep_items it ON it.id_item=d.id_item
        WHERE $w
        GROUP BY r.id_repo
        ORDER BY r.fecha DESC, r.id_repo DESC";
        $headers = ['Folio', 'Fecha', 'Clave suc', 'Sucursal', 'Cuenta', 'Usuario', 'Piezas', 'Total'];
        break;

    case 'sucursal':
        $sql = "
        SELECT r.clave_suc, r.sucursal_nombre,
               COUNT(DISTINCT r.id_repo) AS folios,
               SUM(d.cantidad) AS piezas,
               SUM(d.total) AS total
        FROM rep_reposicion r
        JOIN rep_reposicion_det d ON d.id_repo=r.id_repo
        LEFT JOIN rep_items it ON it.id_item=d.id_item
        WHERE $w
        GROUP BY r.clave_suc, r.sucursal_nombre
        ORDER BY total DESC";
        $headers = ['Clave suc', 'Sucursal', 'Folios', 'Piezas', 'Total'];
        break;

    case 'item':
        $sql = "
        SELECT d.id_item, d.descripcion_snap,
               SUM(d.cantidad) AS piezas,
               SUM(d.total) AS total,
               CASE WHEN SUM(d.cantidad)>0 THEN SUM(d.total)/SUM(d.cantidad) ELSE 0 END AS precio_prom
        FROM rep_reposicion r
        JOIN rep_reposicion_det d ON d.id_repo=r.id_repo
        LEFT JOIN rep_items it ON it.id_item=d.id_item
        WHERE $w
        GROUP BY d.id_item, d.descripcion_snap
        ORDER BY total DESC";
        $headers = ['ID Ítem', 'Descripción', 'Cantidad', 'Total', 'P. Prom'];
        break;

    case 'dia':
        $sql = "
        SELECT DATE(r.fecha) AS dia,
               COUNT(DISTINCT r.id_repo) AS folios,
               SUM(d.cantidad) AS piezas,
               SUM(d.total) AS total
        FROM rep_reposicion r
        JOIN rep_reposicion_det d ON d.id_repo=r.id_repo
        LEFT JOIN rep_items it ON it.id_item=d.id_item
        WHERE $w
        GROUP BY DATE(r.fecha)
        ORDER BY dia DESC";
        $headers = ['Día', 'Folios', 'Piezas', 'Total'];
        break;

    default: // det
        $sql = "
        SELECT r.id_repo AS folio,
               DATE_FORMAT(r.fecha,'%Y-%m-%d %H:%i') AS fecha,
               r.clave_suc, r.sucursal_nombre,
               d.id_item, d.descripcion_snap,
               d.cantidad, d.precio_unitario, d.total,
               r.usuario
        FROM rep_reposicion r
        JOIN rep_reposicion_det d ON d.id_repo=r.id_repo
        LEFT JOIN rep_items it ON it.id_item=d.id_item
        WHERE $w
        ORDER BY r.fecha DESC, r.id_repo DESC";
        $headers = ['Folio', 'Fecha', 'Clave suc', 'Sucursal', 'ID Ítem', 'Descripción', 'Cant.', 'P.U.', 'Importe', 'Usuario'];
        break;
}

/* ====== Ejecutar ====== */
$stmt = $mysqli->prepare($sql);
if (!$stmt) die('Prepare: ' . $mysqli->error);
if ($bind_types !== '') $stmt->bind_param($bind_types, ...$bind_params);
$stmt->execute();
$res = $stmt->get_result();
$rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

/* ====== Export CSV ====== */
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=reporte_reposiciones_' . $group . '_' . $f1 . '_a_' . $f2 . '.csv');
    $out = fopen('php://output', 'w');
    // BOM para Excel
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, $headers);
    foreach ($rows as $r) {
        fputcsv($out, array_values($r));
    }
    fclose($out);
    exit;
}
?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Reporte de reposiciones</title>
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
                <h1 class="text-base sm:text-lg font-extrabold tracking-tight">Reporte de reposiciones</h1>
            </div>
            <nav class="flex items-center gap-2">
                <a href="menu.php" class="inline-flex items-center px-3 py-2 rounded-lg text-brand border border-brand hover:bg-brand hover:text-white transition">← Menú</a>
            </nav>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-6 space-y-6">

        <!-- Filtros -->
        <form class="bg-white border border-slate-200 rounded-2xl shadow-lg shadow-slate-200/40 p-4 grid grid-cols-1 md:grid-cols-6 gap-3">
            <div>
                <label class="block text-xs font-bold mb-1">Desde</label>
                <input type="date" name="f1" value="<?php echo htmlEscape($f1); ?>" class="w-full rounded-xl border-slate-300 px-3 py-2.5 text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold mb-1">Hasta</label>
                <input type="date" name="f2" value="<?php echo htmlEscape($f2); ?>" class="w-full rounded-xl border-slate-300 px-3 py-2.5 text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold mb-1">Sucursal</label>
                <select name="suc" class="w-full rounded-xl border-slate-300 bg-slate-50 px-3 py-2.5 text-sm">
                    <option value="0">Todas</option>
                    <?php foreach ($sucursales as $s): ?>
                        <option value="<?php echo (int)$s['clave_suc']; ?>" <?php echo $suc == (int)$s['clave_suc'] ? 'selected' : ''; ?>>
                            <?php echo htmlEscape($s['sucursal']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold mb-1">Ítem</label>
                <select name="item_id" class="w-full rounded-xl border-slate-300 bg-slate-50 px-3 py-2.5 text-sm">
                    <option value="0">Todos</option>
                    <?php foreach ($items as $it): ?>
                        <option value="<?php echo (int)$it['id_item']; ?>" <?php echo $item_id == (int)$it['id_item'] ? 'selected' : ''; ?>>
                            <?php echo htmlEscape($it['desc']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold mb-1">Buscar</label>
                <input type="text" name="q" value="<?php echo htmlEscape($q); ?>" placeholder="Descripción, sucursal o cuenta" class="w-full rounded-xl border-slate-300 px-3 py-2.5 text-sm">
            </div>
            <div class="flex items-center gap-2 mt-6">
                <input type="checkbox" id="sm" name="solo_mat" value="1" <?php echo $solo_mat ? 'checked' : ''; ?> class="rounded border-slate-300">
                <label for="sm" class="text-sm">Solo materiales (afectan inventario)</label>
            </div>

            <div class="md:col-span-6 flex items-center justify-between mt-1">
                <div class="inline-flex rounded-xl border border-slate-200 bg-white p-1">
                    <?php
                    $tabs = ['det' => 'Detalle', 'folio' => 'Por folio', 'sucursal' => 'Por sucursal', 'item' => 'Por ítem', 'dia' => 'Por día'];
                    foreach ($tabs as $k => $lbl) {
                        $cls = $group === $k ? 'bg-brand text-white' : 'text-slate-700 hover:bg-slate-50';
                        $qs = $_GET;
                        $qs['group'] = $k;
                        $href = '?' . http_build_query($qs);
                        echo '<a href="' . htmlEscape($href) . '" class="px-3 py-2 rounded-lg text-sm font-bold ' . $cls . '">' . $lbl . '</a>';
                    }
                    ?>
                </div>
                <div class="flex items-center gap-2">
                    <button class="px-4 py-2.5 rounded-xl bg-brand text-white text-sm font-extrabold hover:bg-brand-dark shadow-sm">Aplicar</button>
                    <?php
                    $qs = $_GET;
                    $qs['export'] = 'csv';
                    $href = '?' . http_build_query($qs);
                    ?>
                    <a href="<?php echo htmlEscape($href); ?>" class="px-4 py-2.5 rounded-xl border border-slate-300 text-sm font-bold hover:bg-slate-50">Exportar CSV</a>
                </div>
            </div>
        </form>

        <!-- Tabla -->
        <section class="bg-white border border-slate-200 rounded-2xl shadow-lg shadow-slate-200/40">
            <div class="p-4 border-b border-slate-100 flex items-center justify-between">
                <h2 class="text-lg font-extrabold">Resultados (<?php echo count($rows); ?>)</h2>
                <div class="text-sm text-slate-600">Rango: <?php echo htmlEscape($f1 . ' a ' . $f2); ?></div>
            </div>
            <div class="p-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-slate-700">
                        <tr class="border-b border-slate-200">
                            <?php foreach ($headers as $hcol): ?>
                                <th class="px-3 py-2 text-left font-bold"><?php echo htmlEscape($hcol); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if ($rows): foreach ($rows as $r): ?>
                                <tr class="bg-white hover:bg-slate-50">
                                    <?php foreach (array_values($r) as $i => $v):
                                        $align = is_numeric($v) ? 'text-right tabular-nums' : 'text-left';
                                        if (is_numeric($v) && ($headers[$i] === 'Importe' || $headers[$i] === 'Total' || $headers[$i] === 'P.U.' || $headers[$i] === 'P. Prom')) {
                                            $v = '$ ' . number_format((float)$v, 2);
                                        } elseif (is_numeric($v) && ($headers[$i] === 'Cant.' || $headers[$i] === 'Piezas')) {
                                            $v = number_format((float)$v, 3);
                                        }
                                    ?>
                                        <td class="px-3 py-2 <?php echo $align; ?>"><?php echo htmlEscape((string)$v); ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach;
                        else: ?>
                            <tr>
                                <td class="px-3 py-4 text-center text-slate-500" colspan="<?php echo count($headers); ?>">Sin datos en el rango seleccionado</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

    </main>
</body>

</html>