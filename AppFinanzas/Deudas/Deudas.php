<?php
require_once("../db.php");
require_once("../lib.php");

header('Content-Type: application/json; charset=utf-8');

// Deudas: tarjetas de crédito, préstamos y deudas informales.
//
//   GET  Deudas.php            -> lista de deudas con saldo calculado
//   GET  Deudas.php?id=N       -> una deuda con sus últimos movimientos
//   POST Deudas.php (JSON)     -> accion: crear | actualizar | eliminar | movimiento | eliminarMovimiento
//
// Saldo actual = saldoInicial + cargos + intereses - abonos.
// Los abonos de los gastos del presupuesto vinculados a la deuda se crean solos (ver lib.php).

const TIPOS_DEUDA = ['Tarjeta', 'Prestamo', 'Informal'];
const TIPOS_MOVIMIENTO_DEUDA = ['Cargo', 'Abono', 'Interes'];

function numeroOpcional($v) {
    return ($v === null || $v === '') ? null : $v;
}

function validarDeuda($d) {
    if (trim($d['nombre'] ?? '') === '' || mb_strlen($d['nombre'], 'UTF-8') > 100) return 'El nombre de la deuda es obligatorio (máx. 100 caracteres)';
    if (!in_array($d['tipo'] ?? '', TIPOS_DEUDA, true)) return 'El tipo de deuda no es válido';
    if (!is_numeric($d['saldoInicial'] ?? null) || $d['saldoInicial'] < 0 || $d['saldoInicial'] >= 1000000000) return 'El saldo inicial no es válido';
    foreach (['cupoTotal', 'cuotaMensual'] as $c) {
        $v = numeroOpcional($d[$c] ?? null);
        if ($v !== null && (!is_numeric($v) || $v < 0 || $v >= 1000000000)) return "El valor de $c no es válido";
    }
    $tasa = numeroOpcional($d['tasaAnual'] ?? null);
    if ($tasa !== null && (!is_numeric($tasa) || $tasa < 0 || $tasa > 999)) return 'La tasa anual no es válida';
    foreach (['diaCorte', 'diaPago'] as $c) {
        $v = numeroOpcional($d[$c] ?? null);
        if ($v !== null && (appfinanzas_entero($v) === null || $v < 1 || $v > 31)) return "El día ($c) debe estar entre 1 y 31";
    }
    $fin = numeroOpcional($d['fechaFin'] ?? null);
    if ($fin !== null && !appfinanzas_fecha_valida($fin)) return 'La fecha final no es válida (use AAAA-MM-DD)';
    return null;
}

function seleccionDeuda($where = '') {
    return "SELECT d.*, " . SQL_SALDO_DEUDA . " AS saldoActual,
        COALESCE((SELECT SUM(md.Valor) FROM movimientos_deuda md WHERE md.idDeuda = d.idDeuda AND md.Tipo = 'Abono'), 0) AS totalAbonos,
        COALESCE((SELECT SUM(md.Valor) FROM movimientos_deuda md WHERE md.idDeuda = d.idDeuda AND md.Tipo <> 'Abono'), 0) AS totalCargos
        FROM deudas d $where ORDER BY d.Activa DESC, d.Tipo, d.Nombre";
}

function formatearDeuda($f) {
    $saldo = round((float)$f['saldoActual'], 2);
    $cupo = $f['CupoTotal'] !== null ? (float)$f['CupoTotal'] : null;
    $base = (float)$f['SaldoInicial'] + (float)$f['totalCargos'];
    return [
        'idDeuda' => (int)$f['idDeuda'],
        'nombre' => $f['Nombre'],
        'tipo' => $f['Tipo'],
        'acreedor' => $f['Acreedor'],
        'saldoInicial' => (float)$f['SaldoInicial'],
        'saldoActual' => $saldo,
        'cupoTotal' => $cupo,
        'cupoDisponible' => $cupo !== null ? round($cupo - $saldo, 2) : null,
        'diaCorte' => $f['DiaCorte'] !== null ? (int)$f['DiaCorte'] : null,
        'diaPago' => $f['DiaPago'] !== null ? (int)$f['DiaPago'] : null,
        'proximoCorte' => proximaFechaDia($f['DiaCorte']),
        'proximoPago' => proximaFechaDia($f['DiaPago']),
        'tasaAnual' => $f['TasaAnual'] !== null ? (float)$f['TasaAnual'] : null,
        'cuotaMensual' => $f['CuotaMensual'] !== null ? (float)$f['CuotaMensual'] : null,
        'fechaFin' => $f['FechaFin'],
        'activa' => (int)$f['Activa'] === 1,
        'notas' => $f['Notas'],
        'totalAbonos' => (float)$f['totalAbonos'],
        'progreso' => $base > 0 ? round(min(1, (float)$f['totalAbonos'] / $base), 4) : 0,
    ];
}

function listarDeudas() {
    global $mysql;
    $res = $mysql->query(seleccionDeuda());
    echo json_encode(array_map('formatearDeuda', $res->fetch_all(MYSQLI_ASSOC)), JSON_UNESCAPED_UNICODE);
}

function detalleDeuda($id) {
    global $mysql;
    $stmt = $mysql->prepare(seleccionDeuda('WHERE d.idDeuda = ?'));
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $f = $stmt->get_result()->fetch_assoc();
    if (!$f) { echo json_encode(['error' => 'La deuda no existe']); return; }
    $deuda = formatearDeuda($f);

    $stmt = $mysql->prepare("SELECT idMovDeuda, Tipo, Valor, Fecha, Nota, idMovimiento FROM movimientos_deuda WHERE idDeuda = ? ORDER BY Fecha DESC, idMovDeuda DESC LIMIT 100");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $deuda['movimientos'] = array_map(function ($m) {
        return ['idMovDeuda' => (int)$m['idMovDeuda'], 'tipo' => $m['Tipo'], 'valor' => (float)$m['Valor'],
                'fecha' => $m['Fecha'], 'nota' => $m['Nota'], 'desdePresupuesto' => $m['idMovimiento'] !== null];
    }, $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    echo json_encode($deuda, JSON_UNESCAPED_UNICODE);
}

function procesarDeuda($d) {
    global $mysql;
    $accion = $d['accion'] ?? '';
    try {
        switch ($accion) {
            case 'crear':
            case 'actualizar':
                if ($e = validarDeuda($d)) { echo json_encode(['error' => $e]); return; }
                $cupo = numeroOpcional($d['cupoTotal'] ?? null);
                $corte = numeroOpcional($d['diaCorte'] ?? null);
                $pago = numeroOpcional($d['diaPago'] ?? null);
                $tasa = numeroOpcional($d['tasaAnual'] ?? null);
                $cuota = numeroOpcional($d['cuotaMensual'] ?? null);
                $fin = numeroOpcional($d['fechaFin'] ?? null);
                $acreedor = $d['acreedor'] ?? null;
                $notas = $d['notas'] ?? null;
                $activa = array_key_exists('activa', $d) ? ($d['activa'] ? 1 : 0) : 1;
                if ($accion === 'crear') {
                    $stmt = $mysql->prepare("INSERT INTO deudas (Nombre, Tipo, Acreedor, SaldoInicial, CupoTotal, DiaCorte, DiaPago, TasaAnual, CuotaMensual, FechaFin, Activa, Notas)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param('sssddiiddsis', $d['nombre'], $d['tipo'], $acreedor, $d['saldoInicial'], $cupo, $corte, $pago, $tasa, $cuota, $fin, $activa, $notas);
                    $stmt->execute();
                    echo json_encode(['id' => $mysql->insert_id]);
                } else {
                    if (appfinanzas_entero($d['idDeuda'] ?? null) === null) { echo json_encode(['error' => 'Identificador de deuda no válido']); return; }
                    $stmt = $mysql->prepare("UPDATE deudas SET Nombre=?, Tipo=?, Acreedor=?, SaldoInicial=?, CupoTotal=?, DiaCorte=?, DiaPago=?, TasaAnual=?, CuotaMensual=?, FechaFin=?, Activa=?, Notas=? WHERE idDeuda=?");
                    $stmt->bind_param('sssddiiddsisi', $d['nombre'], $d['tipo'], $acreedor, $d['saldoInicial'], $cupo, $corte, $pago, $tasa, $cuota, $fin, $activa, $notas, $d['idDeuda']);
                    $stmt->execute();
                    echo json_encode(['updated' => true]);
                }
                break;

            case 'eliminar':
                $id = appfinanzas_entero($d['idDeuda'] ?? null);
                if ($id === null) { echo json_encode(['error' => 'Identificador de deuda no válido']); return; }
                try {
                    $stmt = $mysql->prepare("DELETE FROM deudas WHERE idDeuda = ?");
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    echo json_encode(['deleted' => $stmt->affected_rows > 0]);
                } catch (mysqli_sql_exception $e) {
                    echo json_encode(['error' => $e->getCode() == 1451
                        ? 'No se puede eliminar la deuda porque tiene movimientos registrados. Márcala como inactiva.'
                        : 'Error al eliminar la deuda']);
                }
                break;

            case 'movimiento': // cargo / interés / abono manual (compras con tarjeta, intereses, pagos fuera del presupuesto)
                $id = appfinanzas_entero($d['idDeuda'] ?? null);
                if ($id === null) { echo json_encode(['error' => 'Identificador de deuda no válido']); return; }
                if (!in_array($d['tipo'] ?? '', TIPOS_MOVIMIENTO_DEUDA, true)) { echo json_encode(['error' => 'Tipo de movimiento no válido']); return; }
                if (!appfinanzas_monto_valido($d['valor'] ?? null) || $d['valor'] <= 0) { echo json_encode(['error' => 'El valor no es válido']); return; }
                $fecha = $d['fecha'] ?? date('Y-m-d');
                if (!appfinanzas_fecha_valida($fecha)) { echo json_encode(['error' => 'La fecha no es válida (use AAAA-MM-DD)']); return; }
                $nota = $d['nota'] ?? null;
                $stmt = $mysql->prepare("INSERT INTO movimientos_deuda (idDeuda, Tipo, Valor, Fecha, Nota) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param('isdss', $id, $d['tipo'], $d['valor'], $fecha, $nota);
                $stmt->execute();
                echo json_encode(['id' => $mysql->insert_id]);
                break;

            case 'eliminarMovimiento':
                $id = appfinanzas_entero($d['idMovDeuda'] ?? null);
                if ($id === null) { echo json_encode(['error' => 'Identificador no válido']); return; }
                // Los abonos que vienen del presupuesto se eliminan borrando el movimiento del presupuesto.
                $stmt = $mysql->prepare("DELETE FROM movimientos_deuda WHERE idMovDeuda = ? AND idMovimiento IS NULL");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                echo json_encode(['deleted' => $stmt->affected_rows > 0]);
                break;

            default:
                echo json_encode(['error' => 'Acción no válida']);
        }
    } catch (mysqli_sql_exception $e) {
        error_log('[AppFinanzas] Deudas: ' . $e->getMessage());
        echo json_encode(['error' => 'Error al procesar la deuda']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    procesarDeuda(is_array($input) ? $input : $_POST);
} elseif (isset($_GET['id'])) {
    $id = appfinanzas_entero($_GET['id']);
    if ($id === null) echo json_encode(['error' => 'Identificador no válido']); else detalleDeuda($id);
} else {
    listarDeudas();
}
