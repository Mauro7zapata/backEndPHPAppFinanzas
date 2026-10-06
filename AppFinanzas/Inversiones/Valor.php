<?php
require_once("../db.php");
require_once("../libInversiones.php");

header('Content-Type: application/json; charset=utf-8');

// Valor actual de una inversión (el saldo que muestra la plataforma donde la tienes; pensado para acciones).
// Cada registro queda en un historial y el más reciente es el «valor actual» con el que se calcula la ganancia o pérdida.
//   GET  Valor.php?idInversion=N -> {valorActual, fecha, valoraciones: [{idValoracion, fecha, valor}]}
//   POST Valor.php (JSON) accion:
//        registrar {idInversion, valor, fecha?}   guarda el saldo de la plataforma (fecha por defecto: hoy; un mismo día se reemplaza)
//        eliminar {idValoracion}                  borra un registro del historial
// Requiere la migración 015.

function valorValido($v) {
    return $v !== null && $v !== '' && is_numeric($v) && (float)$v >= 0 && (float)$v < 10000000000;
}

// Deja Inversiones.ValorActual con el registro más reciente del historial (o NULL si ya no hay).
function sincronizarValorActual($idInversion) {
    global $mysql, $uid;
    $q = $mysql->prepare("SELECT Fecha, Valor FROM valoraciones_inversion WHERE idInversion = ? AND IdUsuario = ? ORDER BY Fecha DESC, idValoracion DESC LIMIT 1");
    $q->bind_param('ii', $idInversion, $uid);
    $q->execute();
    $f = $q->get_result()->fetch_assoc();
    $valor = $f ? (float)$f['Valor'] : null;
    $fecha = $f ? $f['Fecha'] : null;
    $q = $mysql->prepare("UPDATE Inversiones SET ValorActual = ?, FechaValorActual = ? WHERE idInversion = ? AND IdUsuario = ?");
    $q->bind_param('dsii', $valor, $fecha, $idInversion, $uid);
    $q->execute();
}

if (!invTieneValor()) {
    echo json_encode(['error' => 'Falta ejecutar la migración 015 (valor actual de acciones) en la base de datos'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = appfinanzas_entero($_GET['idInversion'] ?? null);
    $inv = $id === null ? null : invCargar($id);
    if (!$inv) { echo json_encode(['error' => 'La inversión no existe']); exit; }
    $q = $mysql->prepare("SELECT idValoracion, Fecha, Valor FROM valoraciones_inversion WHERE idInversion = ? AND IdUsuario = ? ORDER BY Fecha DESC, idValoracion DESC LIMIT 60");
    $q->bind_param('ii', $id, $uid);
    $q->execute();
    $lista = array_map(function ($f) {
        return ['idValoracion' => (int)$f['idValoracion'], 'fecha' => $f['Fecha'], 'valor' => (float)$f['Valor']];
    }, $q->get_result()->fetch_all(MYSQLI_ASSOC));
    echo json_encode([
        'valorActual' => $inv['ValorActual'] !== null ? (float)$inv['ValorActual'] : null,
        'fecha' => invFecha($inv['FechaValorActual']),
        'valoraciones' => $lista,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$d = json_decode(file_get_contents('php://input'), true);
if (!is_array($d)) $d = $_POST;

try {
    switch ($d['accion'] ?? '') {
        case 'registrar':
            $id = appfinanzas_entero($d['idInversion'] ?? null);
            $inv = $id === null ? null : invCargar($id);
            if (!$inv) { echo json_encode(['error' => 'La inversión no existe']); break; }
            if (!valorValido($d['valor'] ?? null)) { echo json_encode(['error' => 'El valor no es válido']); break; }
            $fecha = $d['fecha'] ?? date('Y-m-d');
            if (!appfinanzas_fecha_valida($fecha)) { echo json_encode(['error' => 'La fecha no es válida (AAAA-MM-DD)']); break; }
            $valor = round((float)$d['valor'], 2);
            // Un registro por día: si ya había uno ese día, se reemplaza.
            $q = $mysql->prepare("DELETE FROM valoraciones_inversion WHERE idInversion = ? AND IdUsuario = ? AND Fecha = ?");
            $q->bind_param('iis', $id, $uid, $fecha); $q->execute();
            $q = $mysql->prepare("INSERT INTO valoraciones_inversion (idInversion, IdUsuario, Fecha, Valor) VALUES (?, ?, ?, ?)");
            $q->bind_param('iisd', $id, $uid, $fecha, $valor); $q->execute();
            sincronizarValorActual($id);
            echo json_encode(['saved' => true]);
            break;

        case 'eliminar':
            $idV = appfinanzas_entero($d['idValoracion'] ?? null);
            $q = $mysql->prepare("SELECT idInversion FROM valoraciones_inversion WHERE idValoracion = ? AND IdUsuario = ?");
            $q->bind_param('ii', $idV, $uid); $q->execute();
            $f = $q->get_result()->fetch_assoc();
            if (!$f) { echo json_encode(['error' => 'El registro no existe']); break; }
            $q = $mysql->prepare("DELETE FROM valoraciones_inversion WHERE idValoracion = ? AND IdUsuario = ?");
            $q->bind_param('ii', $idV, $uid); $q->execute();
            sincronizarValorActual((int)$f['idInversion']);
            echo json_encode(['deleted' => true]);
            break;

        default:
            echo json_encode(['error' => 'Acción no válida']);
    }
} catch (mysqli_sql_exception $e) {
    error_log('[AppFinanzas] Valor.php: ' . $e->getMessage());
    echo json_encode(['error' => 'Error al procesar la acción']);
}
