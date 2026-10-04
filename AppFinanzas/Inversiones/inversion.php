<?php
require_once("../db.php");
require_once("../libInversiones.php");

header('Content-Type: application/json; charset=utf-8');

// CRUD de inversiones (dinero prestado o invertido).
//   GET  inversion.php            -> lista (formato histórico de la app)
//   GET  inversion.php?id=N       -> una inversión (lista de un elemento)
//   POST inversion.php (JSON o formulario) accion: crear | actualizar | eliminar
//
// Los campos que no aplican al tipo de inversión se envían vacíos y se guardan como 0 / NULL.
// "Interes" es la tasa MENSUAL (%) sobre el saldo.
// Al eliminar una inversión se eliminan también sus cuotas.

function numeroDe($d, $k) {
    $v = $d[$k] ?? null;
    return ($v === null || $v === '') ? 0.0 : $v;
}

function validarInversion($d, $esCrear) {
    global $mysql;
    if (trim($d['Nombre'] ?? '') === '' || mb_strlen($d['Nombre'], 'UTF-8') > 255) return 'El nombre es obligatorio (máx. 255 caracteres)';
    $tipo = appfinanzas_entero($d['IdTipo'] ?? null);
    if ($tipo === null) return 'El tipo de inversión no es válido';
    $q = $mysql->prepare("SELECT 1 FROM TablaTipoInversion WHERE idTipo = ?");
    $q->bind_param('i', $tipo); $q->execute();
    if (!$q->get_result()->fetch_row()) return 'El tipo de inversión no existe';
    foreach (['CapitalInvertido', 'CuotaPactada'] as $k) {
        $v = numeroDe($d, $k);
        if (!is_numeric($v) || $v < 0 || $v >= 10000000000) return "El valor de $k no es válido";
    }
    if ((float)numeroDe($d, 'CapitalInvertido') <= 0) return 'El capital invertido debe ser mayor que cero';
    $tasa = numeroDe($d, 'Interes');
    if (!is_numeric($tasa) || $tasa < 0 || $tasa > 100) return 'La tasa mensual debe estar entre 0 y 100';
    foreach (['NroCuotas', 'PeriodicidadPagoDividendos'] as $k) {
        $v = numeroDe($d, $k);
        if (appfinanzas_entero($v) === null || $v < 0 || $v > 1000) return "El valor de $k no es válido";
    }
    if (!appfinanzas_fecha_valida($d['FechaInicio'] ?? '')) return 'La fecha de inicio no es válida (AAAA-MM-DD)';
    $fin = $d['FechaFin'] ?? null;
    if ($fin !== null && $fin !== '') {
        if (!appfinanzas_fecha_valida($fin)) return 'La fecha final no es válida (AAAA-MM-DD)';
        if ($fin < $d['FechaInicio']) return 'La fecha final no puede ser anterior a la de inicio';
    }
    if (!$esCrear || isset($d['idEstado'])) {
        $est = appfinanzas_entero($d['idEstado'] ?? null);
        if ($est === null) return 'El estado no es válido';
        $q = $mysql->prepare("SELECT 1 FROM estados WHERE idEstado = ? AND TipoEstado = 'Inversion'");
        $q->bind_param('i', $est); $q->execute();
        if (!$q->get_result()->fetch_row()) return 'El estado no corresponde a una inversión';
    }
    return null;
}

function procesarAccion($data) {
    global $mysql;
    $accion = $data['accion'] ?? '';

    try {
        switch ($accion) {
            case 'crear':
            case 'actualizar':
                $crear = ($accion === 'crear');
                if ($e = validarInversion($data, $crear)) { echo json_encode(['error' => $e], JSON_UNESCAPED_UNICODE); return; }
                $nombre = trim($data['Nombre']);
                $tipo = appfinanzas_entero($data['IdTipo']);
                $capital = (float)numeroDe($data, 'CapitalInvertido');
                $inicio = $data['FechaInicio'];
                $fin = (isset($data['FechaFin']) && $data['FechaFin'] !== '') ? $data['FechaFin'] : null;
                $tasa = (float)numeroDe($data, 'Interes');
                $nro = (int)numeroDe($data, 'NroCuotas');
                $cuota = (float)numeroDe($data, 'CuotaPactada');
                $per = (int)numeroDe($data, 'PeriodicidadPagoDividendos');
                $estado = isset($data['idEstado']) ? appfinanzas_entero($data['idEstado']) : invEstadoId('Inversion', 'Desembolsado');

                if ($crear) {
                    $stmt = $mysql->prepare("INSERT INTO Inversiones (Nombre, IdTipo, CapitalInvertido, FechaInicio, FechaFin, Interes, NroCuotas, CuotaPactada, PeriodicidadPagoDividendos, idEstado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param('sidssdidii', $nombre, $tipo, $capital, $inicio, $fin, $tasa, $nro, $cuota, $per, $estado);
                    $stmt->execute();
                    echo json_encode(['id' => $mysql->insert_id]);
                } else {
                    $id = appfinanzas_entero($data['idInversion'] ?? null);
                    if ($id === null) { echo json_encode(['error' => 'Falta idInversion']); return; }
                    $anterior = invCargar($id);
                    $stmt = $mysql->prepare("UPDATE Inversiones SET Nombre=?, IdTipo=?, CapitalInvertido=?, FechaInicio=?, FechaFin=?, Interes=?, NroCuotas=?, CuotaPactada=?, PeriodicidadPagoDividendos=?, idEstado=? WHERE idInversion=?");
                    $stmt->bind_param('sidssdidiii', $nombre, $tipo, $capital, $inicio, $fin, $tasa, $nro, $cuota, $per, $estado, $id);
                    $stmt->execute();
                    $actualizada = $stmt->affected_rows > 0;
                    // Si cambió la fecha final, el plan se ajusta al nuevo plazo (las cuotas cobradas no se tocan).
                    $plan = null;
                    if ($anterior && invFecha($anterior['FechaFin']) !== $fin && $fin !== null) $plan = invSincronizarPlan($id);
                    echo json_encode(['updated' => $actualizada || $plan !== null, 'plan' => $plan]);
                }
                break;

            case 'eliminar':
                $id = appfinanzas_entero($data['idInversion'] ?? null);
                if ($id === null) { echo json_encode(['error' => 'Falta idInversion']); return; }
                $mysql->begin_transaction();
                try {
                    $stmt = $mysql->prepare("DELETE FROM PlanPagos WHERE idInversion = ?");
                    $stmt->bind_param('i', $id); $stmt->execute();
                    $stmt = $mysql->prepare("DELETE FROM Inversiones WHERE idInversion = ?");
                    $stmt->bind_param('i', $id); $stmt->execute();
                    $mysql->commit();
                } catch (Throwable $e) { $mysql->rollback(); throw $e; }
                echo json_encode(['deleted' => $stmt->affected_rows > 0]);
                break;

            default:
                echo json_encode(['error' => 'Acción no válida']);
        }
    } catch (mysqli_sql_exception $e) {
        error_log('[AppFinanzas] inversion.php: ' . $e->getMessage());
        echo json_encode(['error' => 'Error al procesar la acción']);
    }
}

function filaInversion($row) {
    return [
        "idInversion" => (int)$row['idInversion'],
        "Nombre" => $row['Nombre'],
        "IdTipo" => (int)$row['IdTipo'],
        "FechaInicio" => $row['FechaInicio'],
        "FechaFin" => invFecha($row['FechaFin']),
        "Interes" => (float)$row['Interes'],
        "NroCuotas" => (int)$row['NroCuotas'],
        "CuotaPactada" => (float)$row['CuotaPactada'],
        "PeriodicidadPagoDividendos" => (int)$row['PeriodicidadPagoDividendos'],
        "CapitalInvertido" => (float)$row['CapitalInvertido'],
        "idEstado" => (int)$row['idEstado'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentType = $_SERVER["CONTENT_TYPE"] ?? '';
    if (strpos($contentType, "application/json") !== false) {
        $input = json_decode(file_get_contents('php://input'), true);
        if (is_array($input) && isset($input[0])) {
            foreach ($input as $data) procesarAccion($data);
        } else {
            procesarAccion(is_array($input) ? $input : []);
        }
    } else {
        procesarAccion($_POST);
    }
} else {
    $id = appfinanzas_entero($_GET['id'] ?? null);
    if ($id !== null) {
        $stmt = $mysql->prepare("SELECT * FROM Inversiones WHERE idInversion = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $filas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $filas = $mysql->query("SELECT * FROM Inversiones ORDER BY idEstado ASC, FechaInicio DESC")->fetch_all(MYSQLI_ASSOC);
    }
    echo json_encode(array_map('filaInversion', $filas), JSON_UNESCAPED_UNICODE);
}
