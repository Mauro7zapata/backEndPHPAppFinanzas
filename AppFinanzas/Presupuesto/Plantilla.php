<?php
require_once("../db.php");
require_once("../lib.php");

header('Content-Type: application/json; charset=utf-8');

// Plantilla de gastos frecuentes.
//
// Un gasto se considera "frecuente" si aparece (mismo nombre y categoría) en al
// menos la mitad de los últimos MESES_ANALISIS presupuestos anteriores al mes
// destino (mínimo 2 apariciones). Los gastos marcados "No aplica" no cuentan.
// Los valores sugeridos (costo y día límite) salen de su aparición más reciente.
//
// Si el usuario marcó gastos con el check "Repetir cada mes" (tabla plantilla_gastos), la plantilla
// son exactamente esos gastos; si no hay ninguno marcado se usa la detección automática de arriba.
//
//   GET  Plantilla.php?mes=2&anho=2026          -> lista de gastos sugeridos
//   GET  Plantilla.php?marcados=1               -> [{nombre, idCategoria}] gastos marcados
//   POST Plantilla.php {accion:'marcar', nombre, idCategoria, marcado:true|false}  -> marca o desmarca un gasto
//   POST Plantilla.php {mes, anho, nombres?[]}  -> crea los gastos en el
//        presupuesto de ese mes (todos los sugeridos, o solo los de "nombres").

const MESES_ANALISIS = 6;
const MIN_APARICIONES = 2;

function claveGasto($nombre, $idCategoria) {
    return mb_strtolower(trim($nombre), 'UTF-8') . '|' . (int)$idCategoria;
}

function buscarPresupuesto($mes, $anho) {
    global $mysql, $uid;
    $stmt = $mysql->prepare("SELECT idPresupuesto FROM presupuestos WHERE Mes = ? AND Anho = ? AND IdUsuario = ?");
    $stmt->bind_param('iii', $mes, $anho, $uid);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    return $fila ? (int)$fila['idPresupuesto'] : null;
}

// Gastos marcados por el usuario: [clave => true]. Vacío si no hay ninguno (o si falta la migración 004).
function plantillaMarcados() {
    global $mysql, $uid;
    $m = [];
    try {
        $stmt = $mysql->prepare("SELECT Nombre, IdCategoria FROM plantilla_gastos WHERE IdUsuario = ?");
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $f) $m[claveGasto($f['Nombre'], $f['IdCategoria'])] = true;
    } catch (mysqli_sql_exception $e) {
    }
    return $m;
}

function gastosFrecuentes($mes, $anho) {
    global $mysql, $uid;
    $indice = $anho * 12 + $mes;
    $marcados = plantillaMarcados();

    // Presupuestos anteriores más recientes.
    $limite = $marcados ? 36 : MESES_ANALISIS;
    $stmt = $mysql->prepare("SELECT idPresupuesto FROM presupuestos WHERE (Anho * 12 + Mes) < ? AND IdUsuario = ? ORDER BY (Anho * 12 + Mes) DESC LIMIT " . $limite);
    $stmt->bind_param('ii', $indice, $uid);
    $stmt->execute();
    $ids = array_map(function ($r) { return (int)$r['idPresupuesto']; }, $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    if (count($ids) === 0) return [];

    $umbral = max(MIN_APARICIONES, (int)ceil(count($ids) / 2));
    $lista = implode(',', $ids); // enteros ya validados

    // $lista son ids de presupuestos del usuario (ya enteros); el filtro por IdUsuario se repite por seguridad.
    $stmt = $mysql->prepare("SELECT g.NombreGasto, g.IdCategoria, c.NombreCategoria, g.CostoPrevisto, g.FechaLimite, g.Observaciones, g.idDeuda, p.Anho, p.Mes
        FROM gastos g
        INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto
        INNER JOIN estados e ON e.idEstado = g.IdEstado AND e.IdUsuario = p.IdUsuario
        INNER JOIN categoriagastos c ON c.idCategoriaGastos = g.IdCategoria AND c.IdUsuario = p.IdUsuario
        WHERE g.idPresupuesto IN ($lista) AND p.IdUsuario = ? AND e.NombreEstado <> 'No aplica' AND g.idObligacion IS NULL
        ORDER BY (p.Anho * 12 + p.Mes) DESC, g.idGastos DESC");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();

    $grupos = [];
    while ($f = $res->fetch_assoc()) {
        $k = claveGasto($f['NombreGasto'], $f['IdCategoria']);
        if ($marcados && !isset($marcados[$k])) continue;
        if (!isset($grupos[$k])) {
            // Primera fila = aparición más reciente
            $dia = $f['FechaLimite'] ? (int)substr($f['FechaLimite'], 8, 2) : null;
            $grupos[$k] = [
                'nombre' => trim($f['NombreGasto']),
                'idCategoria' => (int)$f['IdCategoria'],
                'nombreCategoria' => $f['NombreCategoria'],
                'costoPrevisto' => $f['CostoPrevisto'],
                'diaLimite' => $dia,
                'observaciones' => $f['Observaciones'],
                'idDeuda' => $f['idDeuda'] !== null ? (int)$f['idDeuda'] : null,
                'marcado' => (bool)$marcados,
                'meses' => [],
            ];
        }
        $grupos[$k]['meses'][$f['Anho'] . '-' . $f['Mes']] = true;
    }

    $salida = [];
    foreach ($grupos as $g) {
        $apariciones = count($g['meses']);
        if (!$marcados && $apariciones < $umbral) continue;
        $g['frecuencia'] = $apariciones;
        $g['mesesAnalizados'] = count($ids);
        unset($g['meses']);
        $salida[] = $g;
    }
    usort($salida, function ($a, $b) {
        return [$b['frecuencia'], $a['nombreCategoria'], $a['nombre']] <=> [$a['frecuencia'], $b['nombreCategoria'], $b['nombre']];
    });
    return $salida;
}

function fechaLimiteDelMes($dia, $mes, $anho) {
    if (!$dia) return null;
    $ultimo = (int)date('t', mktime(0, 0, 0, $mes, 1, $anho));
    return sprintf('%04d-%02d-%02d', $anho, $mes, min($dia, $ultimo));
}

function aplicarPlantilla($data) {
    global $mysql, $uid;
    $mes = appfinanzas_entero($data['mes'] ?? null);
    $anho = appfinanzas_entero($data['anho'] ?? null);
    if ($mes === null || $anho === null || $mes < 1 || $mes > 12 || $anho < 2000 || $anho > 2100) {
        echo json_encode(['error' => 'Mes o año no válidos']);
        return;
    }
    $idPresupuesto = buscarPresupuesto($mes, $anho);
    if ($idPresupuesto === null) {
        echo json_encode(['error' => 'Primero debes crear el presupuesto de ese mes']);
        return;
    }

    $sugeridos = gastosFrecuentes($mes, $anho);
    if (isset($data['nombres']) && is_array($data['nombres'])) {
        $permitidos = array_flip(array_map(function ($n) { return mb_strtolower(trim($n), 'UTF-8'); }, $data['nombres']));
        $sugeridos = array_values(array_filter($sugeridos, function ($g) use ($permitidos) {
            return isset($permitidos[mb_strtolower($g['nombre'], 'UTF-8')]);
        }));
    }

    $stmt = $mysql->prepare("SELECT idEstado FROM estados WHERE TipoEstado = 'Gastos' AND NombreEstado = 'Pendiente' AND IdUsuario = ? LIMIT 1");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $est = $stmt->get_result()->fetch_assoc();
    if (!$est) { echo json_encode(['error' => "No existe el estado 'Pendiente'"]); return; }
    $idEstado = (int)$est['idEstado'];

    // Un presupuesto finalizado a mano no admite gastos nuevos hasta reabrirlo.
    if (presupuestoCerradoManual($idPresupuesto)) { echo json_encode(['error' => 'El presupuesto está finalizado. Reábrelo para agregar gastos.']); return; }

    // Gastos que ya existen en el presupuesto destino (evita duplicar).
    $existentes = [];
    $stmt = $mysql->prepare("SELECT g.NombreGasto, g.IdCategoria FROM gastos g INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto
        WHERE g.idPresupuesto = ? AND p.IdUsuario = ?");
    $stmt->bind_param('ii', $idPresupuesto, $uid);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $f) {
        $existentes[claveGasto($f['NombreGasto'], $f['IdCategoria'])] = true;
    }

    $insertados = 0; $omitidos = 0;
    $provisiones = ['insertados' => 0, 'omitidos' => 0];
    $mysql->begin_transaction();
    try {
        // Provisión de obligaciones anuales (gastos "Acumulado"), salvo que el cliente pida lo contrario.
        if (($data['incluirObligaciones'] ?? true) !== false) {
            $provisiones = provisionarObligaciones($mes, $anho, $idPresupuesto);
        }
        $ins = $mysql->prepare("INSERT INTO gastos (IdCategoria, NombreGasto, CostoPrevisto, CostoReal, FechaLimite, FechaPago, Observaciones, IdEstado, idPresupuesto, idDeuda)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($sugeridos as $g) {
            if (isset($existentes[claveGasto($g['nombre'], $g['idCategoria'])])) { $omitidos++; continue; }
            $costo = (float)$g['costoPrevisto'];
            $fechaLimite = fechaLimiteDelMes($g['diaLimite'], $mes, $anho);
            $fechaPago = $fechaLimite ?? sprintf('%04d-%02d-01', $anho, $mes);
            $obs = $g['observaciones'] ?? '';
            $ins->bind_param('isddsssiii', $g['idCategoria'], $g['nombre'], $costo, $costo, $fechaLimite, $fechaPago, $obs, $idEstado, $idPresupuesto, $g['idDeuda']);
            $ins->execute();
            $insertados++;
        }
        $mysql->commit();
    } catch (Throwable $e) {
        $mysql->rollback();
        throw $e;
    }
    if ($insertados > 0 || $provisiones['insertados'] > 0) evaluarCierrePresupuesto($idPresupuesto);
    echo json_encode(['insertados' => $insertados, 'omitidos' => $omitidos, 'provisiones' => $provisiones['insertados']]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;
    if (($input['accion'] ?? '') === 'marcar') {
        $nombre = trim((string)($input['nombre'] ?? ''));
        $cat = appfinanzas_entero($input['idCategoria'] ?? null);
        if ($nombre === '' || mb_strlen($nombre, 'UTF-8') > 255 || $cat === null) { echo json_encode(['error' => 'Gasto no válido']); exit; }
        // La categoría debe ser del usuario (no se revela si existe para otro usuario).
        if (!appfinanzas_es_propio('categoriagastos', $cat)) { echo json_encode(['error' => 'Gasto no válido']); exit; }
        try {
            if (!empty($input['marcado'])) {
                $stmt = $mysql->prepare("INSERT IGNORE INTO plantilla_gastos (IdUsuario, Nombre, IdCategoria) VALUES (?, ?, ?)");
            } else {
                $stmt = $mysql->prepare("DELETE FROM plantilla_gastos WHERE IdUsuario = ? AND Nombre = ? AND IdCategoria = ?");
            }
            $stmt->bind_param('isi', $uid, $nombre, $cat);
            $stmt->execute();
            echo json_encode(['marcado' => !empty($input['marcado'])]);
        } catch (mysqli_sql_exception $e) {
            error_log('[AppFinanzas] Plantilla: ' . $e->getMessage());
            echo json_encode(['error' => 'No se pudo guardar. ¿Ejecutaste la migración 004?']);
        }
        exit;
    }
    aplicarPlantilla($input);
} elseif (isset($_GET['marcados'])) {
    $m = [];
    try {
        $stmt = $mysql->prepare("SELECT Nombre, IdCategoria FROM plantilla_gastos WHERE IdUsuario = ? ORDER BY Nombre");
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $f) $m[] = ['nombre' => $f['Nombre'], 'idCategoria' => (int)$f['IdCategoria']];
    } catch (mysqli_sql_exception $e) {
    }
    echo json_encode($m, JSON_UNESCAPED_UNICODE);
} else {
    $mes = appfinanzas_entero($_GET['mes'] ?? null);
    $anho = appfinanzas_entero($_GET['anho'] ?? null);
    if ($mes === null || $anho === null || $mes < 1 || $mes > 12) {
        echo json_encode(['error' => 'Mes o año no válidos']);
    } else {
        echo json_encode(gastosFrecuentes($mes, $anho), JSON_UNESCAPED_UNICODE);
    }
}
