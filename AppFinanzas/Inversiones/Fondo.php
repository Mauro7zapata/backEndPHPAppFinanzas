<?php
require_once("../db.php");
require_once("../libInversiones.php");

header('Content-Type: application/json; charset=utf-8');

// Fondo de inversión: cuánto dinero tienes DISPONIBLE para invertir y cuánto está INVERTIDO.
// Requiere la migración 011 (tabla fondo_inversion).
//
//   GET  Fondo.php        -> {fondo: {disponible, invertido, patrimonio, ingresos, retiros, descuadre, cobrado, desembolsado, perdido, ...},
//                             movimientos: [{idMovFondo, fecha, tipo, valor, nota}]}
//   POST Fondo.php (JSON) accion:
//        ingreso   {fecha, valor, nota?}   dinero que pasa al fondo para invertir
//        retiro    {fecha, valor, nota?}   dinero que sale del fondo (gasto personal, otra cuenta...)
//        conciliar {saldoReal, nota?}      declaras cuánto tienes realmente disponible; la diferencia con lo que calcula la app
//                                          queda como DESCUADRE (un «Ajuste»). La primera vez, si tienes más de lo calculado,
//                                          se registra como «Saldo inicial» (un Ingreso) y no como descuadre.
//        eliminar  {idMovFondo}
//
// Disponible = Ingresos - Retiros + Ajustes + Cobrado (capital, intereses, dividendos) - Capital desembolsado en inversiones.

function validarValorFondo($v) {
    return ($v !== null && $v !== '' && is_numeric($v) && (float)$v > 0 && (float)$v < 100000000000);
}

function insertarMovFondo($fecha, $tipo, $valor, $nota) {
    global $mysql, $uid;
    $nota = $nota === null ? null : mb_substr(trim((string)$nota), 0, 250, 'UTF-8');
    if ($nota === '') $nota = null;
    $q = $mysql->prepare("INSERT INTO fondo_inversion (IdUsuario, Fecha, Tipo, Valor, Nota) VALUES (?, ?, ?, ?, ?)");
    $q->bind_param('issds', $uid, $fecha, $tipo, $valor, $nota);
    $q->execute();
    return $mysql->insert_id;
}

function estadoFondo() {
    global $mysql, $uid;
    $res = invCalcularResumen(date('Y-m-d'));
    $fondo = $res['fondo'];
    if ($fondo === null) return null;
    $q = $mysql->prepare("SELECT idMovFondo, Fecha, Tipo, Valor, Nota FROM fondo_inversion WHERE IdUsuario = ? ORDER BY Fecha DESC, idMovFondo DESC LIMIT 100");
    $q->bind_param('i', $uid);
    $q->execute();
    $movs = [];
    foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $m) {
        $movs[] = ['idMovFondo' => (int)$m['idMovFondo'], 'fecha' => $m['Fecha'], 'tipo' => $m['Tipo'], 'valor' => (float)$m['Valor'], 'nota' => $m['Nota']];
    }
    return ['fondo' => $fondo, 'movimientos' => $movs];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $e = estadoFondo();
        echo json_encode($e ?? ['error' => 'Falta ejecutar la migración 011 en la base de datos'], JSON_UNESCAPED_UNICODE);
    } catch (mysqli_sql_exception $ex) {
        error_log('[AppFinanzas] Fondo.php GET: ' . $ex->getMessage());
        echo json_encode(['error' => 'Falta ejecutar la migración 011 en la base de datos']);
    }
    exit;
}

$d = json_decode(file_get_contents('php://input'), true);
if (!is_array($d)) $d = $_POST;

try {
    switch ($d['accion'] ?? '') {
        case 'ingreso':
        case 'retiro':
            if (!validarValorFondo($d['valor'] ?? null)) { echo json_encode(['error' => 'El valor debe ser mayor que cero']); break; }
            $fecha = $d['fecha'] ?? date('Y-m-d');
            if (!appfinanzas_fecha_valida($fecha)) { echo json_encode(['error' => 'La fecha no es válida (AAAA-MM-DD)']); break; }
            $id = insertarMovFondo($fecha, $d['accion'] === 'ingreso' ? 'Ingreso' : 'Retiro', round((float)$d['valor'], 2), $d['nota'] ?? null);
            echo json_encode(['id' => $id]);
            break;

        case 'conciliar':
            $real = $d['saldoReal'] ?? null;
            if ($real === null || $real === '' || !is_numeric($real) || (float)$real < 0 || (float)$real >= 100000000000) {
                echo json_encode(['error' => 'Escribe cuánto dinero tienes disponible hoy (0 o más)']); break;
            }
            $real = round((float)$real, 2);
            $e = estadoFondo();
            if ($e === null) { echo json_encode(['error' => 'Falta ejecutar la migración 011 en la base de datos']); break; }
            $esperado = (float)$e['fondo']['disponible'];
            $dif = round($real - $esperado, 2);
            if (abs($dif) < 0.5) { echo json_encode(['descuadre' => 0.0, 'esperado' => $esperado, 'real' => $real, 'tipo' => null]); break; }
            $nota = trim((string)($d['nota'] ?? ''));
            if ($e['fondo']['movimientos'] === 0 && $dif > 0) {
                // Primera vez: lo que tienes de más es tu punto de partida, no un descuadre.
                insertarMovFondo(date('Y-m-d'), 'Ingreso', $dif, 'Saldo inicial' . ($nota !== '' ? ': ' . $nota : ''));
                echo json_encode(['descuadre' => 0.0, 'esperado' => $esperado, 'real' => $real, 'tipo' => 'Saldo inicial', 'valor' => $dif]);
            } else {
                insertarMovFondo(date('Y-m-d'), 'Ajuste', $dif, 'Descuadre: la app calculaba ' . number_format($esperado, 0, ',', '.') . ($nota !== '' ? ' · ' . $nota : ''));
                echo json_encode(['descuadre' => $dif, 'esperado' => $esperado, 'real' => $real, 'tipo' => 'Ajuste', 'valor' => $dif]);
            }
            break;

        case 'eliminar':
            $id = appfinanzas_entero($d['idMovFondo'] ?? null);
            if ($id === null) { echo json_encode(['error' => 'Identificador no válido']); break; }
            $q = $mysql->prepare("DELETE FROM fondo_inversion WHERE idMovFondo = ? AND IdUsuario = ?");
            $q->bind_param('ii', $id, $uid);
            $q->execute();
            echo json_encode(['deleted' => $q->affected_rows > 0]);
            break;

        default:
            echo json_encode(['error' => 'Acción no válida']);
    }
} catch (mysqli_sql_exception $ex) {
    error_log('[AppFinanzas] Fondo.php: ' . $ex->getMessage());
    echo json_encode(['error' => $ex->getCode() == 1146 ? 'Falta ejecutar la migración 011 en la base de datos' : 'Error al procesar la acción']);
}
