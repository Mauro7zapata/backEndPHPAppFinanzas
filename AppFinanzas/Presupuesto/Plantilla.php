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
//   GET  Plantilla.php?mes=2&anho=2026          -> lista de gastos sugeridos
//   POST Plantilla.php {mes, anho, nombres?[]}  -> crea los gastos en el
//        presupuesto de ese mes (todos los sugeridos, o solo los de "nombres").

const MESES_ANALISIS = 6;
const MIN_APARICIONES = 2;

function claveGasto($nombre, $idCategoria) {
    return mb_strtolower(trim($nombre), 'UTF-8') . '|' . (int)$idCategoria;
}

function buscarPresupuesto($mes, $anho) {
    global $mysql;
    $stmt = $mysql->prepare("SELECT idPresupuesto FROM presupuestos WHERE Mes = ? AND Anho = ?");
    $stmt->bind_param('ii', $mes, $anho);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    return $fila ? (int)$fila['idPresupuesto'] : null;
}

function gastosFrecuentes($mes, $anho) {
    global $mysql;
    $indice = $anho * 12 + $mes;

    // Presupuestos anteriores más recientes.
    $stmt = $mysql->prepare("SELECT idPresupuesto FROM presupuestos WHERE (Anho * 12 + Mes) < ? ORDER BY (Anho * 12 + Mes) DESC LIMIT " . MESES_ANALISIS);
    $stmt->bind_param('i', $indice);
    $stmt->execute();
    $ids = array_map(function ($r) { return (int)$r['idPresupuesto']; }, $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    if (count($ids) === 0) return [];

    $umbral = max(MIN_APARICIONES, (int)ceil(count($ids) / 2));
    $lista = implode(',', $ids); // enteros ya validados

    $res = $mysql->query("SELECT g.NombreGasto, g.IdCategoria, c.NombreCategoria, g.CostoPrevisto, g.FechaLimite, g.Observaciones, g.idDeuda, p.Anho, p.Mes
        FROM gastos g
        INNER JOIN estados e ON e.idEstado = g.IdEstado
        INNER JOIN categoriagastos c ON c.idCategoriaGastos = g.IdCategoria
        INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto
        WHERE g.idPresupuesto IN ($lista) AND e.NombreEstado <> 'No aplica' AND g.idObligacion IS NULL
        ORDER BY (p.Anho * 12 + p.Mes) DESC, g.idGastos DESC");

    $grupos = [];
    while ($f = $res->fetch_assoc()) {
        $k = claveGasto($f['NombreGasto'], $f['IdCategoria']);
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
                'meses' => [],
            ];
        }
        $grupos[$k]['meses'][$f['Anho'] . '-' . $f['Mes']] = true;
    }

    $salida = [];
    foreach ($grupos as $g) {
        $apariciones = count($g['meses']);
        if ($apariciones < $umbral) continue;
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
    global $mysql;
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

    $stmt = $mysql->prepare("SELECT idEstado FROM estados WHERE TipoEstado = 'Gastos' AND NombreEstado = 'Pendiente' LIMIT 1");
    $stmt->execute();
    $est = $stmt->get_result()->fetch_assoc();
    if (!$est) { echo json_encode(['error' => "No existe el estado 'Pendiente'"]); return; }
    $idEstado = (int)$est['idEstado'];

    // Gastos que ya existen en el presupuesto destino (evita duplicar).
    $existentes = [];
    $stmt = $mysql->prepare("SELECT NombreGasto, IdCategoria FROM gastos WHERE idPresupuesto = ?");
    $stmt->bind_param('i', $idPresupuesto);
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
    echo json_encode(['insertados' => $insertados, 'omitidos' => $omitidos, 'provisiones' => $provisiones['insertados']]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;
    aplicarPlantilla($input);
} else {
    $mes = appfinanzas_entero($_GET['mes'] ?? null);
    $anho = appfinanzas_entero($_GET['anho'] ?? null);
    if ($mes === null || $anho === null || $mes < 1 || $mes > 12) {
        echo json_encode(['error' => 'Mes o año no válidos']);
    } else {
        echo json_encode(gastosFrecuentes($mes, $anho), JSON_UNESCAPED_UNICODE);
    }
}
