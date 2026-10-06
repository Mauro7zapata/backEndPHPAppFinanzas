<?php
require_once("../db.php");
require_once(__DIR__ . '/../lib.php');

header('Content-Type: application/json; charset=utf-8');

// Catálogo «lugares de guardado»: dónde tiene el usuario el dinero de los gastos Guardado/Acumulado
// (bolsillo físico, bolsillo Nequi, meta Nequi...). Requiere la migración 012.
//
//   GET  LugaresGuardado.php                -> {lugares:[{idLugar, nombre, guardado, acumulado, usado, ajuste, saldo, gastos}],
//                                               sinLugar:{...igual}, total}   (saldo = guardado + acumulado + ajuste - usado, de todos los meses)
//   GET  LugaresGuardado.php?historial=1&idLugar=ID -> {movimientos:[{idUso, fecha, tipo (Uso|Ajuste), valor, nota}]}  (idLugar 0 = sin lugar)
//   POST (JSON) accion: crear {nombre} | renombrar {idLugar, nombre} | eliminar {idLugar}
//        usar     {idLugar, valor, fecha?, nota?}   sacas dinero de lo guardado (migración 014)
//        corregir {idLugar, saldoReal, nota?}       declaras cuánto tienes de verdad ahí; la diferencia queda como ajuste
//        eliminarMovimiento {idUso}
//        Al eliminar un lugar, los gastos que lo usaban quedan «sin lugar» (no se pierde ningún dato).
//
// guardado / acumulado = movimientos de gastos en esos estados (todos los meses); usado y ajuste vienen de separado_usos.

define('MSG_MIGRACION_012', 'Falta ejecutar la migración 012 en la base de datos');

function nombreLugarValido($nombre) {
    $n = mb_substr(trim((string)$nombre), 0, 60, 'UTF-8');
    return $n === '' ? null : $n;
}

function detalleLugares() {
    global $mysql, $uid;

    $q = $mysql->prepare("SELECT idLugar, Nombre FROM lugares_guardado WHERE IdUsuario = ? ORDER BY Nombre");
    $q->bind_param('i', $uid);
    $q->execute();
    $filasLugares = $q->get_result()->fetch_all(MYSQLI_ASSOC); // leer antes de lanzar otras consultas
    $saldos = saldoSeparadoPorLugar();
    $fila = function ($id, $nombre, $v) {
        $v = $v ?? ['guardado' => 0.0, 'acumulado' => 0.0, 'usado' => 0.0, 'ajuste' => 0.0, 'gastos' => 0, 'saldo' => 0.0];
        return ['idLugar' => $id, 'nombre' => $nombre, 'guardado' => $v['guardado'], 'acumulado' => $v['acumulado'],
            'usado' => $v['usado'], 'ajuste' => $v['ajuste'], 'saldo' => $v['saldo'], 'total' => $v['saldo'], 'gastos' => $v['gastos']];
    };
    $lugares = [];
    $total = 0.0;
    foreach ($filasLugares as $l) {
        $id = (int)$l['idLugar'];
        $lugares[] = $fila($id, $l['Nombre'], $saldos[$id] ?? null);
        $total += ($saldos[$id]['saldo'] ?? 0.0);
        unset($saldos[$id]);
    }
    // Lo que queda en $saldos[0] (y de lugares ya borrados) es «sin lugar asignado».
    $sin = ['guardado' => 0.0, 'acumulado' => 0.0, 'usado' => 0.0, 'ajuste' => 0.0, 'gastos' => 0, 'saldo' => 0.0];
    foreach ($saldos as $v) foreach ($sin as $k => $_) $sin[$k] += $v[$k];
    $total += $sin['saldo'];
    return ['lugares' => $lugares, 'sinLugar' => $fila(0, 'Sin lugar asignado', $sin), 'total' => round($total, 2)];
}

// Últimos movimientos del saldo (usos y ajustes) de un lugar (0 = sin lugar), del más reciente al más antiguo.
function historialLugar($idLugar) {
    global $mysql, $uid;
    if (!separadoTieneUsos()) return [];
    $q = $mysql->prepare("SELECT idUso, Fecha, Tipo, Valor, Nota FROM separado_usos
        WHERE IdUsuario = ? AND COALESCE(idLugar, 0) = ? ORDER BY Fecha DESC, idUso DESC LIMIT 60");
    $q->bind_param('ii', $uid, $idLugar);
    $q->execute();
    $res = [];
    foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $m) {
        $res[] = ['idUso' => (int)$m['idUso'], 'fecha' => $m['Fecha'], 'tipo' => $m['Tipo'], 'valor' => (float)$m['Valor'], 'nota' => $m['Nota']];
    }
    return $res;
}

function lugarDelUsuarioOCero($v) {
    $id = appfinanzas_entero($v);
    if ($id === null || $id <= 0) return 0;
    return appfinanzas_es_propio('lugares_guardado', $id) ? $id : -1;
}

function registrarMovimientoSeparado($idLugar, $tipo, $valor, $fecha, $nota) {
    global $mysql, $uid;
    $nota = $nota === null ? null : mb_substr(trim((string)$nota), 0, 250, 'UTF-8');
    if ($nota === '') $nota = null;
    $lugar = $idLugar > 0 ? $idLugar : null;
    $q = $mysql->prepare("INSERT INTO separado_usos (IdUsuario, idLugar, Fecha, Tipo, Valor, Nota) VALUES (?, ?, ?, ?, ?, ?)");
    $q->bind_param('iissds', $uid, $lugar, $fecha, $tipo, $valor, $nota);
    $q->execute();
    return $mysql->insert_id;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        if (isset($_GET['historial'])) {
            $l = lugarDelUsuarioOCero($_GET['idLugar'] ?? 0);
            echo json_encode($l < 0 ? ['error' => 'El lugar no existe'] : ['movimientos' => historialLugar($l)], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(detalleLugares(), JSON_UNESCAPED_UNICODE);
    } catch (mysqli_sql_exception $ex) {
        error_log('[AppFinanzas] LugaresGuardado.php GET: ' . $ex->getMessage());
        echo json_encode(['error' => MSG_MIGRACION_012]);
    }
    exit;
}

$d = json_decode(file_get_contents('php://input'), true);
if (!is_array($d)) $d = $_POST;

try {
    switch ($d['accion'] ?? '') {
        case 'crear':
            $nombre = nombreLugarValido($d['nombre'] ?? '');
            if ($nombre === null) { echo json_encode(['error' => 'Escribe el nombre del lugar']); break; }
            $q = $mysql->prepare("INSERT INTO lugares_guardado (IdUsuario, Nombre) VALUES (?, ?)");
            $q->bind_param('is', $uid, $nombre);
            $q->execute();
            echo json_encode(['id' => $mysql->insert_id]);
            break;

        case 'renombrar':
            $id = appfinanzas_entero($d['idLugar'] ?? null);
            $nombre = nombreLugarValido($d['nombre'] ?? '');
            if ($id === null || $id <= 0 || $nombre === null) { echo json_encode(['error' => 'Datos no válidos']); break; }
            $q = $mysql->prepare("UPDATE lugares_guardado SET Nombre = ? WHERE idLugar = ? AND IdUsuario = ?");
            $q->bind_param('sii', $nombre, $id, $uid);
            $q->execute();
            echo json_encode(['updated' => true]);
            break;

        case 'eliminar':
            $id = appfinanzas_entero($d['idLugar'] ?? null);
            if ($id === null || $id <= 0) { echo json_encode(['error' => 'Identificador no válido']); break; }
            $q = $mysql->prepare("DELETE FROM lugares_guardado WHERE idLugar = ? AND IdUsuario = ?");
            $q->bind_param('ii', $id, $uid);
            $q->execute();
            echo json_encode(['deleted' => $q->affected_rows > 0]);
            break;

        case 'usar':
        case 'corregir':
            if (!separadoTieneUsos()) { echo json_encode(['error' => 'Falta ejecutar la migración 014 en la base de datos']); break; }
            $l = lugarDelUsuarioOCero($d['idLugar'] ?? 0);
            if ($l < 0) { echo json_encode(['error' => 'El lugar no existe']); break; }
            $fecha = $d['fecha'] ?? date('Y-m-d');
            if (!appfinanzas_fecha_valida($fecha)) { echo json_encode(['error' => 'La fecha no es válida (AAAA-MM-DD)']); break; }
            if ($d['accion'] === 'usar') {
                $v = $d['valor'] ?? null;
                if ($v === null || $v === '' || !is_numeric($v) || (float)$v <= 0 || (float)$v >= 100000000000) { echo json_encode(['error' => 'El valor debe ser mayor que cero']); break; }
                $id = registrarMovimientoSeparado($l, 'Uso', round((float)$v, 2), $fecha, $d['nota'] ?? null);
                $saldo = (saldoSeparadoPorLugar()[$l]['saldo'] ?? 0.0);
                echo json_encode(['id' => $id, 'saldoLugar' => $saldo]);
            } else {
                // El usuario declara cuánto tiene de verdad en ese lugar; la diferencia con lo calculado queda como ajuste.
                $real = $d['saldoReal'] ?? null;
                if ($real === null || $real === '' || !is_numeric($real) || (float)$real < 0 || (float)$real >= 100000000000) { echo json_encode(['error' => 'Escribe cuánto tienes ahí hoy (0 o más)']); break; }
                $actual = (saldoSeparadoPorLugar()[$l]['saldo'] ?? 0.0);
                $dif = round((float)$real - $actual, 2);
                if (abs($dif) < 0.005) { echo json_encode(['ajuste' => 0.0, 'saldoLugar' => $actual]); break; }
                registrarMovimientoSeparado($l, 'Ajuste', $dif, $fecha, trim((string)($d['nota'] ?? '')) !== '' ? $d['nota'] : 'Corrección de saldo (la app calculaba ' . number_format($actual, 0, ',', '.') . ')');
                echo json_encode(['ajuste' => $dif, 'saldoLugar' => round((float)$real, 2)]);
            }
            break;

        case 'eliminarMovimiento':
            $id = appfinanzas_entero($d['idUso'] ?? null);
            if ($id === null || $id <= 0) { echo json_encode(['error' => 'Identificador no válido']); break; }
            $q = $mysql->prepare("DELETE FROM separado_usos WHERE idUso = ? AND IdUsuario = ?");
            $q->bind_param('ii', $id, $uid);
            $q->execute();
            echo json_encode(['deleted' => $q->affected_rows > 0]);
            break;

        default:
            echo json_encode(['error' => 'Acción no válida']);
    }
} catch (mysqli_sql_exception $ex) {
    $codigo = (int)$ex->getCode();
    if ($codigo === 1062) { echo json_encode(['error' => 'Ya tienes un lugar con ese nombre']); exit; }
    error_log('[AppFinanzas] LugaresGuardado.php: ' . $ex->getMessage());
    echo json_encode(['error' => $codigo === 1146 ? MSG_MIGRACION_012 : 'Error al procesar la acción']);
}
