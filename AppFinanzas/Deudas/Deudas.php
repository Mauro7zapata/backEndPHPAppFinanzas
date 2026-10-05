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

// Siempre filtra por el usuario autenticado (el parámetro enlazado IdUsuario va primero); $where es una condición adicional sin WHERE.
function seleccionDeuda($where = '') {
    $filtro = 'WHERE d.IdUsuario = ?' . ($where !== '' ? " AND $where" : '');
    return "SELECT d.*, " . SQL_SALDO_DEUDA . " AS saldoActual,
        COALESCE((SELECT SUM(md.Valor) FROM movimientos_deuda md WHERE md.idDeuda = d.idDeuda AND md.Tipo = 'Abono'), 0) AS totalAbonos,
        COALESCE((SELECT SUM(md.Valor) FROM movimientos_deuda md WHERE md.idDeuda = d.idDeuda AND md.Tipo <> 'Abono'), 0) AS totalCargos
        FROM deudas d $filtro ORDER BY d.Activa DESC, d.Tipo, d.Nombre";
}

// Ocurrencia del día $dia (1-31) dentro del período [$ini, $fin] (Y-m-d), ajustando a meses cortos. null si no cae en él.
function fechaDiaEnPeriodo($dia, $ini, $fin) {
    if (!$dia) return null;
    $d = new DateTime($ini); $d->modify('first day of this month');
    for ($i = 0; $i < 3; $i++) {
        $ultimo = (int)$d->format('t');
        $c = sprintf('%s-%02d', $d->format('Y-m'), min((int)$dia, $ultimo));
        if ($c >= $ini && $c <= $fin) return $c;
        $d->modify('+1 month');
    }
    return null;
}

// Fechas de pago/corte que se muestran: las del mes financiero ACTUAL (el pago entra en el presupuesto de este mes aunque ya
// haya pasado); el corte es el del ciclo que se paga (anterior al pago). 'cubierto' = ya hay abonos desde ese corte.
function fechasCicloActual($f) {
    global $mysql;
    $hoyTxt = date('Y-m-d');
    $dia = parametroApp('dia_inicio_mes');
    [$m, $a] = mesFinanciero($hoyTxt, $dia);
    [$ini, $fin] = periodoFinanciero($m, $a, $dia);
    $pago = $f['DiaPago'] ? fechaDiaEnPeriodo($f['DiaPago'], $ini, $fin) : null;
    if ($pago === null) return ['corte' => proximaFechaDia($f['DiaCorte']), 'pago' => proximaFechaDia($f['DiaPago']), 'cubierto' => false];
    $corte = $f['DiaCorte'] ? corteAnteriorA($f['DiaCorte'], $pago) : null;
    $cubierto = false;
    if ($pago < $hoyTxt) {
        $desde = $corte ?? $ini;
        $q = $mysql->prepare("SELECT COALESCE(SUM(Valor),0) AS t FROM movimientos_deuda WHERE idDeuda = ? AND Tipo = 'Abono' AND Fecha >= ?");
        $q->bind_param('is', $f['idDeuda'], $desde);
        $q->execute();
        $cubierto = (float)$q->get_result()->fetch_assoc()['t'] > 0;
        $q->close();
    }
    return ['corte' => $corte, 'pago' => $pago, 'cubierto' => $cubierto];
}

function formatearDeuda($f) {
    $ciclo = fechasCicloActual($f);
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
        // Ciclo del mes financiero actual: corte (anterior al pago) y pago, aunque el pago ya haya pasado.
        'proximoCorte' => $ciclo['corte'],
        'proximoPago' => $ciclo['pago'],
        'pagoCubierto' => $ciclo['cubierto'],
        'tasaAnual' => $f['TasaAnual'] !== null ? (float)$f['TasaAnual'] : null,
        'cuotaMensual' => $f['CuotaMensual'] !== null ? (float)$f['CuotaMensual'] : null,
        'fechaFin' => $f['FechaFin'],
        'activa' => (int)$f['Activa'] === 1,
        'notas' => $f['Notas'],
        'totalAbonos' => (float)$f['totalAbonos'],
        'progreso' => $base > 0 ? round(min(1, (float)$f['totalAbonos'] / $base), 4) : 0,
    ];
}

// Deuda del usuario autenticado (null si no existe o es de otro usuario).
function deudaDelUsuario($id) {
    global $mysql, $uid;
    $q = $mysql->prepare("SELECT idDeuda FROM deudas WHERE idDeuda = ? AND IdUsuario = ?");
    $q->bind_param('ii', $id, $uid);
    $q->execute();
    return $q->get_result()->fetch_assoc() ?: null;
}

function listarDeudas() {
    global $mysql, $uid;
    $stmt = $mysql->prepare(seleccionDeuda());
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    echo json_encode(array_map('formatearDeuda', $stmt->get_result()->fetch_all(MYSQLI_ASSOC)), JSON_UNESCAPED_UNICODE);
}

function detalleDeuda($id) {
    global $mysql, $uid;
    $stmt = $mysql->prepare(seleccionDeuda('d.idDeuda = ?'));
    $stmt->bind_param('ii', $uid, $id);
    $stmt->execute();
    $f = $stmt->get_result()->fetch_assoc();
    if (!$f) { echo json_encode(['error' => 'La deuda no existe']); return; }
    $deuda = formatearDeuda($f);

    // Los abonos hechos desde el presupuesto traen su gasto, categoría y mes (movimientos -> gastos -> presupuestos).
    $stmt = $mysql->prepare("SELECT md.idMovDeuda, md.Tipo, md.Valor, md.Fecha, md.Nota, md.idMovimiento,
            g.NombreGasto AS gasto, c.NombreCategoria AS categoria, p.Mes AS mesP, p.Anho AS anhoP
        FROM movimientos_deuda md
        INNER JOIN deudas d ON d.idDeuda = md.idDeuda
        LEFT JOIN movimientos m ON m.idMovimiento = md.idMovimiento
        LEFT JOIN gastos g ON g.idGastos = m.idGasto
        LEFT JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto AND p.IdUsuario = d.IdUsuario
        LEFT JOIN categoriagastos c ON c.idCategoriaGastos = g.IdCategoria AND c.IdUsuario = d.IdUsuario
        WHERE md.idDeuda = ? AND d.IdUsuario = ? ORDER BY md.Fecha DESC, md.idMovDeuda DESC LIMIT 100");
    $stmt->bind_param('ii', $id, $uid);
    $stmt->execute();
    $deuda['movimientos'] = array_map(function ($m) {
        return ['idMovDeuda' => (int)$m['idMovDeuda'], 'tipo' => $m['Tipo'], 'valor' => (float)$m['Valor'],
                'fecha' => $m['Fecha'], 'nota' => $m['Nota'], 'desdePresupuesto' => $m['idMovimiento'] !== null,
                'gasto' => $m['gasto'], 'categoria' => $m['categoria'],
                'mes' => $m['mesP'] !== null ? (int)$m['mesP'] : null, 'anho' => $m['anhoP'] !== null ? (int)$m['anhoP'] : null];
    }, $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $deuda['gastos'] = gastosVinculadosDeuda($id);
    $deuda['categoriaSugerida'] = categoriaSugeridaDeuda($id);
    echo json_encode($deuda, JSON_UNESCAPED_UNICODE);
}

// Gastos del presupuesto vinculados a la deuda (los más recientes primero) para asociar un abono hecho desde Deudas.
// 'sugerido' = gasto del mes financiero de $fecha (prefiere el que aún no está pagado).
function gastosVinculadosDeuda($idDeuda, $fecha = null) {
    global $mysql, $uid;
    $stmt = $mysql->prepare("SELECT g.idGastos, g.NombreGasto, g.CostoPrevisto, g.valorGastosMovimiento, e.NombreEstado, p.Mes, p.Anho
        FROM gastos g INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto
        INNER JOIN estados e ON e.idEstado = g.IdEstado
        WHERE g.idDeuda = ? AND p.IdUsuario = ? ORDER BY p.Anho DESC, p.Mes DESC, g.idGastos DESC LIMIT 12");
    $stmt->bind_param('ii', $idDeuda, $uid);
    $stmt->execute();
    $filas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    [$mes, $anho] = mesFinanciero($fecha ?: date('Y-m-d'), parametroApp('dia_inicio_mes'));
    $sugerido = null;
    foreach ($filas as $f) {
        if ((int)$f['Mes'] !== $mes || (int)$f['Anho'] !== $anho) continue;
        if ($sugerido === null || ($sugerido['NombreEstado'] === 'Pagado' && $f['NombreEstado'] !== 'Pagado')) $sugerido = $f;
    }
    return array_map(function ($f) use ($sugerido) {
        return ['idGasto' => (int)$f['idGastos'], 'nombre' => $f['NombreGasto'], 'mes' => (int)$f['Mes'], 'anho' => (int)$f['Anho'],
                'estado' => $f['NombreEstado'], 'previsto' => (float)$f['CostoPrevisto'], 'pagado' => (float)$f['valorGastosMovimiento'],
                'sugerido' => $sugerido !== null && (int)$sugerido['idGastos'] === (int)$f['idGastos']];
    }, $filas);
}

// Categoría del último gasto vinculado a la deuda (para crear automáticamente el gasto de un abono). null si no hay.
function categoriaSugeridaDeuda($idDeuda) {
    global $mysql, $uid;
    $stmt = $mysql->prepare("SELECT g.IdCategoria FROM gastos g INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto
        WHERE g.idDeuda = ? AND p.IdUsuario = ? ORDER BY g.idGastos DESC LIMIT 1");
    $stmt->bind_param('ii', $idDeuda, $uid);
    $stmt->execute();
    $f = $stmt->get_result()->fetch_assoc();
    return $f ? (int)$f['IdCategoria'] : null;
}

// Gasto del presupuesto del mes financiero de $fecha donde reflejar un abono: reutiliza «Pago <deuda>» si ya existe
// o lo crea (vinculado a la deuda). Devuelve su id, o null si ese mes no tiene presupuesto.
function gastoParaAbono($idDeuda, $nombreDeuda, $idCategoria, $fecha, $valor) {
    global $mysql, $uid;
    [$mes, $anho] = mesFinanciero($fecha, parametroApp('dia_inicio_mes'));
    $stmt = $mysql->prepare("SELECT idPresupuesto FROM presupuestos WHERE IdUsuario = ? AND Mes = ? AND Anho = ? LIMIT 1");
    $stmt->bind_param('iii', $uid, $mes, $anho);
    $stmt->execute();
    $p = $stmt->get_result()->fetch_assoc();
    if (!$p) return null;
    $nombre = mb_substr('Pago ' . $nombreDeuda, 0, 100, 'UTF-8');
    $stmt = $mysql->prepare("SELECT idGastos FROM gastos WHERE idPresupuesto = ? AND IdCategoria = ? AND NombreGasto = ? LIMIT 1");
    $stmt->bind_param('iis', $p['idPresupuesto'], $idCategoria, $nombre);
    $stmt->execute();
    $g = $stmt->get_result()->fetch_assoc();
    if ($g) {
        $up = $mysql->prepare("UPDATE gastos SET idDeuda = ? WHERE idGastos = ? AND idDeuda IS NULL");
        $up->bind_param('ii', $idDeuda, $g['idGastos']);
        $up->execute();
        return (int)$g['idGastos'];
    }
    $idEstado = estadoGastoPorNombre('Pendiente');
    if (!$idEstado) return null;
    $obs = 'Creado desde Deudas';
    $sinPago = '0000-00-00';
    $ins = $mysql->prepare("INSERT INTO gastos (NombreGasto, CostoPrevisto, CostoReal, FechaLimite, idPresupuesto, Observaciones, IdEstado, IdCategoria, FechaPago, idDeuda)
        VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?, ?)");
    $ins->bind_param('sdsisiisi', $nombre, $valor, $fecha, $p['idPresupuesto'], $obs, $idEstado, $idCategoria, $sinPago, $idDeuda);
    $ins->execute();
    return (int)$mysql->insert_id;
}

function procesarDeuda($d) {
    global $mysql, $uid;
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
                    $stmt = $mysql->prepare("INSERT INTO deudas (Nombre, Tipo, Acreedor, SaldoInicial, CupoTotal, DiaCorte, DiaPago, TasaAnual, CuotaMensual, FechaFin, Activa, Notas, IdUsuario)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param('sssddiiddsisi', $d['nombre'], $d['tipo'], $acreedor, $d['saldoInicial'], $cupo, $corte, $pago, $tasa, $cuota, $fin, $activa, $notas, $uid);
                    $stmt->execute();
                    echo json_encode(['id' => $mysql->insert_id]);
                } else {
                    $idDeuda = appfinanzas_entero($d['idDeuda'] ?? null);
                    if ($idDeuda === null) { echo json_encode(['error' => 'Identificador de deuda no válido']); return; }
                    if (!deudaDelUsuario($idDeuda)) { echo json_encode(['error' => 'La deuda no existe']); return; }
                    $stmt = $mysql->prepare("UPDATE deudas SET Nombre=?, Tipo=?, Acreedor=?, SaldoInicial=?, CupoTotal=?, DiaCorte=?, DiaPago=?, TasaAnual=?, CuotaMensual=?, FechaFin=?, Activa=?, Notas=? WHERE idDeuda=? AND IdUsuario=?");
                    $stmt->bind_param('sssddiiddsisii', $d['nombre'], $d['tipo'], $acreedor, $d['saldoInicial'], $cupo, $corte, $pago, $tasa, $cuota, $fin, $activa, $notas, $idDeuda, $uid);
                    $stmt->execute();
                    echo json_encode(['updated' => true]);
                }
                break;

            case 'eliminar':
                $id = appfinanzas_entero($d['idDeuda'] ?? null);
                if ($id === null) { echo json_encode(['error' => 'Identificador de deuda no válido']); return; }
                try {
                    $stmt = $mysql->prepare("DELETE FROM deudas WHERE idDeuda = ? AND IdUsuario = ?");
                    $stmt->bind_param('ii', $id, $uid);
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
                if (!deudaDelUsuario($id)) { echo json_encode(['error' => 'La deuda no existe']); return; }
                // Un abono se refleja en el presupuesto: se registra como movimiento del gasto vinculado a la deuda
                // (idGasto explícito; -1 = no asociar; sin idGasto = el gasto de la deuda en el mes de la fecha, si existe).
                $avisoPresupuesto = null;
                if ($d['tipo'] === 'Abono') {
                    $idGasto = array_key_exists('idGasto', $d) ? appfinanzas_entero($d['idGasto']) : null;
                    if ($idGasto !== -1 && ($idGasto === null || $idGasto <= 0)) {
                        if ($idGasto === null) { // sin elección del cliente: el gasto de la deuda en el mes de la fecha
                            foreach (gastosVinculadosDeuda($id, $fecha) as $g) { if ($g['sugerido']) { $idGasto = $g['idGasto']; break; } }
                        }
                        if ($idGasto === null || $idGasto <= 0) { // ninguno: se crea «Pago <deuda>» en el presupuesto de ese mes
                            $idCat = appfinanzas_entero($d['idCategoria'] ?? null);
                            if ($idCat === null || $idCat <= 0) $idCat = categoriaSugeridaDeuda($id);
                            $idGasto = null;
                            if ($idCat !== null && appfinanzas_es_propio('categoriagastos', $idCat)) {
                                $nom = $mysql->prepare("SELECT Nombre FROM deudas WHERE idDeuda = ? AND IdUsuario = ?");
                                $nom->bind_param('ii', $id, $uid);
                                $nom->execute();
                                $nd = $nom->get_result()->fetch_assoc();
                                $idGasto = gastoParaAbono($id, $nd['Nombre'], $idCat, $fecha, (float)$d['valor']);
                                if ($idGasto === null) $avisoPresupuesto = 'No hay presupuesto de ese mes: el abono quedó solo en la deuda.';
                            } else {
                                $avisoPresupuesto = 'Elige una categoría para reflejar el abono en el presupuesto.';
                            }
                        }
                    }
                    if ($idGasto !== null && $idGasto > 0) {
                        $stmt = $mysql->prepare("SELECT g.NombreGasto FROM gastos g INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto
                            WHERE g.idGastos = ? AND g.idDeuda = ? AND p.IdUsuario = ?");
                        $stmt->bind_param('iii', $idGasto, $id, $uid);
                        $stmt->execute();
                        $g = $stmt->get_result()->fetch_assoc();
                        if (!$g) { echo json_encode(['error' => 'El gasto no está vinculado a esta deuda']); return; }
                        $mysql->begin_transaction();
                        try {
                            $tipoMov = 'Gasto';
                            $obs = trim((string)$nota) !== '' ? mb_substr(trim($nota), 0, 500, 'UTF-8') : 'Abono registrado desde Deudas';
                            $ins = $mysql->prepare("INSERT INTO movimientos (tipoMovimiento, valorMovimiento, nombreGasto, observacionMovimiento, fechaMovimiento, idGasto) VALUES (?, ?, ?, ?, ?, ?)");
                            $ins->bind_param('sdsssi', $tipoMov, $d['valor'], $g['NombreGasto'], $obs, $fecha, $idGasto);
                            $ins->execute();
                            $idMov = $mysql->insert_id;
                            sincronizarGasto($idGasto);
                            sincronizarAbonoMovimiento($idMov);
                            $mysql->commit();
                        } catch (Throwable $e) { $mysql->rollback(); throw $e; }
                        echo json_encode(['id' => $idMov, 'enPresupuesto' => true, 'gasto' => $g['NombreGasto']]);
                        break;
                    }
                }
                $stmt = $mysql->prepare("INSERT INTO movimientos_deuda (idDeuda, Tipo, Valor, Fecha, Nota) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param('isdss', $id, $d['tipo'], $d['valor'], $fecha, $nota);
                $stmt->execute();
                echo json_encode(['id' => $mysql->insert_id, 'enPresupuesto' => false, 'aviso' => $avisoPresupuesto]);
                break;

            case 'eliminarMovimiento':
                $id = appfinanzas_entero($d['idMovDeuda'] ?? null);
                if ($id === null) { echo json_encode(['error' => 'Identificador no válido']); return; }
                // Los abonos que vienen del presupuesto se eliminan borrando el movimiento del presupuesto.
                $stmt = $mysql->prepare("DELETE md FROM movimientos_deuda md INNER JOIN deudas d ON d.idDeuda = md.idDeuda
                    WHERE md.idMovDeuda = ? AND md.idMovimiento IS NULL AND d.IdUsuario = ?");
                $stmt->bind_param('ii', $id, $uid);
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
