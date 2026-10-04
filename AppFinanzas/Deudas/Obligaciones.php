<?php
require_once("../db.php");
require_once("../lib.php");

header('Content-Type: application/json; charset=utf-8');

// Obligaciones anuales (renta, impuesto vehicular, SOAT, tecnomecánica, ...).
//
//   GET  Obligaciones.php?mes=M&anho=A  -> lista con ahorrado y cuota de provisión sugerida para ese mes (por defecto, el actual)
//   POST Obligaciones.php (JSON)        -> accion: crear | actualizar | eliminar | pagar
//
// "pagar" cierra el ciclo: el vencimiento avanza un año y el conteo de lo ahorrado empieza de nuevo.

function validarObligacion($d) {
    if (trim($d['nombre'] ?? '') === '' || mb_strlen($d['nombre'], 'UTF-8') > 90) return 'El nombre es obligatorio (máx. 90 caracteres)';
    if (appfinanzas_entero($d['idCategoria'] ?? null) === null) return 'La categoría no es válida';
    if (!is_numeric($d['valorEstimado'] ?? null) || $d['valorEstimado'] <= 0 || $d['valorEstimado'] >= 1000000000) return 'El valor estimado no es válido';
    if (!appfinanzas_fecha_valida($d['fechaVencimiento'] ?? '')) return 'La fecha de vencimiento no es válida (use AAAA-MM-DD)';
    return null;
}

// La categoría debe ser del usuario autenticado (no se revela si existe para otro usuario).
function categoriaDelUsuario($idCategoria) {
    global $mysql, $uid;
    $id = appfinanzas_entero($idCategoria);
    if ($id === null) return false;
    $q = $mysql->prepare("SELECT 1 FROM categoriagastos WHERE idCategoriaGastos = ? AND IdUsuario = ?");
    $q->bind_param('ii', $id, $uid);
    $q->execute();
    return (bool)$q->get_result()->fetch_row();
}

function listarObligaciones($mes, $anho) {
    global $mysql, $uid;
    $stmt = $mysql->prepare("SELECT o.*, c.NombreCategoria FROM obligaciones o
        INNER JOIN categoriagastos c ON c.idCategoriaGastos = o.IdCategoria AND c.IdUsuario = o.IdUsuario
        WHERE o.IdUsuario = ? ORDER BY o.Activa DESC, o.FechaVencimiento");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    $hoy = new DateTime('today');
    $salida = [];
    foreach ($res->fetch_all(MYSQLI_ASSOC) as $o) {
        $ahorrado = ahorradoObligacion($o['idObligacion'], $o['CicloInicio']);
        $venc = new DateTime($o['FechaVencimiento']);
        $salida[] = [
            'idObligacion' => (int)$o['idObligacion'],
            'nombre' => $o['Nombre'],
            'idCategoria' => (int)$o['IdCategoria'],
            'nombreCategoria' => $o['NombreCategoria'],
            'valorEstimado' => (float)$o['ValorEstimado'],
            'fechaVencimiento' => $o['FechaVencimiento'],
            'diasParaVencer' => (int)$hoy->diff($venc)->format('%r%a'),
            'ahorrado' => $ahorrado,
            'faltante' => max(0, round((float)$o['ValorEstimado'] - $ahorrado, 2)),
            'cuotaProvision' => cuotaProvision($o['ValorEstimado'], $ahorrado, $o['FechaVencimiento'], $mes, $anho),
            'activa' => (int)$o['Activa'] === 1,
            'notas' => $o['Notas'],
        ];
    }
    echo json_encode($salida, JSON_UNESCAPED_UNICODE);
}

function procesarObligacion($d) {
    global $mysql, $uid;
    $accion = $d['accion'] ?? '';
    try {
        switch ($accion) {
            case 'crear':
            case 'actualizar':
                if ($e = validarObligacion($d)) { echo json_encode(['error' => $e]); return; }
                if (!categoriaDelUsuario($d['idCategoria'])) { echo json_encode(['error' => 'La categoría no existe']); return; }
                $notas = $d['notas'] ?? null;
                $activa = array_key_exists('activa', $d) ? ($d['activa'] ? 1 : 0) : 1;
                if ($accion === 'crear') {
                    // El ciclo empieza el mes actual: lo provisionado antes de crear la obligación no se cuenta.
                    $ciclo = date('Y-m-01');
                    $stmt = $mysql->prepare("INSERT INTO obligaciones (Nombre, IdCategoria, ValorEstimado, FechaVencimiento, CicloInicio, Activa, Notas, IdUsuario) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param('sidssisi', $d['nombre'], $d['idCategoria'], $d['valorEstimado'], $d['fechaVencimiento'], $ciclo, $activa, $notas, $uid);
                    $stmt->execute();
                    echo json_encode(['id' => $mysql->insert_id]);
                } else {
                    $idObl = appfinanzas_entero($d['idObligacion'] ?? null);
                    if ($idObl === null) { echo json_encode(['error' => 'Identificador no válido']); return; }
                    $q = $mysql->prepare("SELECT 1 FROM obligaciones WHERE idObligacion = ? AND IdUsuario = ?");
                    $q->bind_param('ii', $idObl, $uid); $q->execute();
                    if (!$q->get_result()->fetch_row()) { echo json_encode(['error' => 'La obligación no existe']); return; }
                    $stmt = $mysql->prepare("UPDATE obligaciones SET Nombre=?, IdCategoria=?, ValorEstimado=?, FechaVencimiento=?, Activa=?, Notas=? WHERE idObligacion=? AND IdUsuario=?");
                    $stmt->bind_param('sidsisii', $d['nombre'], $d['idCategoria'], $d['valorEstimado'], $d['fechaVencimiento'], $activa, $notas, $idObl, $uid);
                    $stmt->execute();
                    echo json_encode(['updated' => true]);
                }
                break;

            case 'eliminar':
                $id = appfinanzas_entero($d['idObligacion'] ?? null);
                if ($id === null) { echo json_encode(['error' => 'Identificador no válido']); return; }
                $stmt = $mysql->prepare("DELETE FROM obligaciones WHERE idObligacion = ? AND IdUsuario = ?");
                $stmt->bind_param('ii', $id, $uid);
                $stmt->execute();
                echo json_encode(['deleted' => $stmt->affected_rows > 0]);
                break;

            case 'pagar':
                $id = appfinanzas_entero($d['idObligacion'] ?? null);
                if ($id === null) { echo json_encode(['error' => 'Identificador no válido']); return; }
                $ciclo = date('Y-m-01', strtotime('first day of next month'));
                $stmt = $mysql->prepare("UPDATE obligaciones SET FechaVencimiento = DATE_ADD(FechaVencimiento, INTERVAL 1 YEAR), CicloInicio = ? WHERE idObligacion = ? AND IdUsuario = ?");
                $stmt->bind_param('sii', $ciclo, $id, $uid);
                $stmt->execute();
                echo json_encode(['updated' => $stmt->affected_rows > 0]);
                break;

            default:
                echo json_encode(['error' => 'Acción no válida']);
        }
    } catch (mysqli_sql_exception $e) {
        error_log('[AppFinanzas] Obligaciones: ' . $e->getMessage());
        echo json_encode(['error' => $e->getCode() == 1452 ? 'La categoría no existe' : 'Error al procesar la obligación']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    procesarObligacion(is_array($input) ? $input : $_POST);
} else {
    $mes = appfinanzas_entero($_GET['mes'] ?? null) ?: (int)date('n');
    $anho = appfinanzas_entero($_GET['anho'] ?? null) ?: (int)date('Y');
    listarObligaciones($mes, $anho);
}
