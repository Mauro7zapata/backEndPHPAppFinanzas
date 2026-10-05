<?php
require_once("../db.php");
require_once("../lib.php");

header('Content-Type: application/json; charset=utf-8');

// Sugerencias financieras (fase A: reglas, sin IA externa). Todo se calcula con los datos del usuario; no se envía nada a terceros.
//   GET Sugerencias.php[?mes=10&anho=2026]   (por defecto el mes financiero actual)
//
// Respuesta: { mes, anho, salud:{puntaje,nivel,mensaje}, sugerencias:[{id,tipo,icono,titulo,detalle,prioridad,accion}] }
//   tipo: alerta | consejo | logro | motivacion      accion: presupuesto | deudas | movimientos | null
// Las sugerencias vienen ordenadas por importancia (primero lo que requiere atención, luego los logros) y son como máximo 7.

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { echo json_encode(['error' => 'Método no permitido']); exit; }

function cop($v) { return '$' . number_format(round((float)$v), 0, ',', '.'); }
function pct($v) { return number_format((float)$v * 100, 0, ',', '.') . ' %'; }

$sug = [];
function agregar($id, $tipo, $icono, $titulo, $detalle, $prioridad, $accion = null) {
    global $sug;
    $sug[] = ['id' => $id, 'tipo' => $tipo, 'icono' => $icono, 'titulo' => $titulo, 'detalle' => $detalle, 'prioridad' => $prioridad, 'accion' => $accion];
}

$hoyTxt = date('Y-m-d');
$hoy = new DateTime($hoyTxt);
$diaInicioMes = parametroApp('dia_inicio_mes');
[$mesActual, $anhoActual] = mesFinanciero($hoyTxt, $diaInicioMes);
$mes = appfinanzas_entero($_GET['mes'] ?? null);
$anho = appfinanzas_entero($_GET['anho'] ?? null);
if ($mes === null || $anho === null) {
    $mes = $mesActual; $anho = $anhoActual;
    $q = $mysql->prepare("SELECT Mes, Anho FROM presupuestos WHERE IdUsuario = ? ORDER BY Anho DESC, Mes DESC LIMIT 1");
    $q->bind_param('i', $uid); $q->execute();
    $ult = $q->get_result()->fetch_assoc(); $q->close();
    if ($ult && ((int)$ult['Anho'] * 12 + (int)$ult['Mes']) > ($anhoActual * 12 + $mesActual)) { $mes = (int)$ult['Mes']; $anho = (int)$ult['Anho']; }
}
if ($mes < 1 || $mes > 12 || $anho < 2000 || $anho > 2100) { echo json_encode(['error' => 'Mes o año no válido']); exit; }
$esMesActual = ($mes === $mesActual && $anho === $anhoActual);
[$periodoIni, $periodoFin] = periodoFinanciero($mes, $anho, $diaInicioMes);
$diasMes = (int)(new DateTime($periodoIni))->diff(new DateTime($periodoFin))->days + 1;
$diaActual = $esMesActual ? (int)(new DateTime($periodoIni))->diff($hoy)->days + 1 : $diasMes;
$diasRestantes = $esMesActual ? $diasMes - $diaActual : 0;

$nombresMes = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

// ------------------------------------------------------------------ presupuesto del mes y del anterior
function presupuestoDe($mes, $anho) {
    global $mysql, $uid;
    $s = $mysql->prepare("SELECT idPresupuesto, ValorPresupuesto + COALESCE(ExtrasMes,0) AS total FROM presupuestos WHERE IdUsuario = ? AND Mes = ? AND Anho = ? LIMIT 1");
    $s->bind_param('iii', $uid, $mes, $anho); $s->execute();
    $r = $s->get_result()->fetch_assoc(); $s->close();
    return $r ?: null;
}
// Gasto de consumo por categoría (sin lo que se ahorra: Guardado / Acumulado).
function consumoPorCategoria($idPresupuesto) {
    global $mysql, $uid;
    $s = $mysql->prepare("SELECT c.NombreCategoria AS nombre, SUM(g.valorGastosMovimiento) AS valor
        FROM gastos g INNER JOIN categoriagastos c ON c.idCategoriaGastos = g.IdCategoria AND c.IdUsuario = ?
        INNER JOIN estados e ON e.idEstado = g.IdEstado AND e.IdUsuario = c.IdUsuario
        WHERE g.idPresupuesto = ? AND e.NombreEstado NOT IN ('Guardado','Acumulado')
        GROUP BY c.idCategoriaGastos, c.NombreCategoria HAVING valor > 0");
    $s->bind_param('ii', $uid, $idPresupuesto); $s->execute();
    $out = [];
    foreach ($s->get_result()->fetch_all(MYSQLI_ASSOC) as $f) $out[$f['nombre']] = (float)$f['valor'];
    $s->close();
    return $out;
}

$p = presupuestoDe($mes, $anho);
$pm = $mes - 1; $pa = $anho; if ($pm < 1) { $pm = 12; $pa--; }
$pAnt = presupuestoDe($pm, $pa);
$sm = $mes + 1; $sa = $anho; if ($sm > 12) { $sm = 1; $sa++; }

$total = 0.0; $previsto = 0.0; $pagado = 0.0; $ahorrado = 0.0; $consumo = 0.0;
$vencidos = 0; $valorVencido = 0.0; $nGastos = 0; $nPagados = 0; $proximos7 = 0.0; $nProximos7 = 0;
$cats = []; $catsAnt = [];
if ($p) {
    $idP = (int)$p['idPresupuesto'];
    $total = (float)$p['total'];
    $s = $mysql->prepare("SELECT e.NombreEstado AS estado, COUNT(*) AS n, COALESCE(SUM(g.CostoPrevisto),0) AS previsto, COALESCE(SUM(g.valorGastosMovimiento),0) AS pagado
        FROM gastos g INNER JOIN estados e ON e.idEstado = g.IdEstado AND e.IdUsuario = ? WHERE g.idPresupuesto = ? GROUP BY e.NombreEstado");
    $s->bind_param('ii', $uid, $idP); $s->execute();
    foreach ($s->get_result()->fetch_all(MYSQLI_ASSOC) as $f) {
        $nGastos += (int)$f['n']; $previsto += (float)$f['previsto']; $pagado += (float)$f['pagado'];
        if (in_array($f['estado'], ['Guardado', 'Acumulado'], true)) $ahorrado += (float)$f['pagado'];
        if ($f['estado'] === 'Pagado') $nPagados += (int)$f['n'];
    }
    $consumo = $pagado - $ahorrado;

    $s = $mysql->prepare("SELECT g.CostoPrevisto - g.valorGastosMovimiento AS falta, g.FechaLimite FROM gastos g
        INNER JOIN estados e ON e.idEstado = g.IdEstado AND e.IdUsuario = ?
        WHERE g.idPresupuesto = ? AND e.NombreEstado IN ('Pendiente','En proceso') AND g.FechaLimite IS NOT NULL AND g.FechaLimite <> '0000-00-00'");
    $s->bind_param('ii', $uid, $idP); $s->execute();
    foreach ($s->get_result()->fetch_all(MYSQLI_ASSOC) as $g) {
        $falta = max(0.0, (float)$g['falta']);
        $d = (int)$hoy->diff(new DateTime($g['FechaLimite']))->format('%r%a');
        if ($d < 0) { $vencidos++; $valorVencido += $falta; }
        elseif ($d <= 7) { $proximos7 += $falta; $nProximos7++; }
    }
    $cats = consumoPorCategoria($idP);
}
if ($pAnt) $catsAnt = consumoPorCategoria((int)$pAnt['idPresupuesto']);
$mesLbl = $nombresMes[$mes];

// ------------------------------------------------------------------ 0) sin presupuesto
if (!$p) {
    agregar('sin-presupuesto', 'consejo', '📝', "Crea el presupuesto de $mesLbl", 'Sin presupuesto no puedo medir tu avance. Créalo en 2 minutos usando tu plantilla de gastos fijos.', 95, 'presupuesto');
}

// ------------------------------------------------------------------ 1) vencidos
if ($vencidos > 0) {
    agregar('vencidos', 'alerta', '⏰', $vencidos === 1 ? 'Tienes 1 pago vencido' : "Tienes $vencidos pagos vencidos",
        'Suman ' . cop($valorVencido) . ' por pagar. Ponerte al día primero evita recargos e intereses de mora.', 100, 'presupuesto');
}

// ------------------------------------------------------------------ 2) pagos de los próximos 7 días
if ($nProximos7 > 0) {
    agregar('proximos7', 'consejo', '📅', "En 7 días vencen " . cop($proximos7), "Son $nProximos7 " . ($nProximos7 === 1 ? 'pago' : 'pagos') . '. Resérvalo ya para que el dinero no se gaste en otra cosa.', 80, 'presupuesto');
}

// ------------------------------------------------------------------ 3) plan vs dinero disponible
if ($p && $total > 0) {
    $libre = $total - $previsto;
    if ($libre < 0) {
        agregar('sobreplan', 'alerta', '⚠️', 'Planeaste gastar más de lo que tienes',
            'Tus gastos previstos superan el presupuesto por ' . cop(-$libre) . '. Recorta algún gasto o ajusta el presupuesto antes de que el mes avance.', 90, 'presupuesto');
    } elseif ($libre / $total >= 0.05) {
        agregar('sin-asignar', 'consejo', '💡', 'Tienes ' . cop($libre) . ' sin asignar',
            'Dale un destino a ese dinero: ahorro, abono extra a una deuda o un fondo de emergencia. Lo que no se asigna, se gasta sin notarlo.', 55, 'presupuesto');
    }

    // Ritmo de gasto (solo mes en curso, con al menos 5 días de datos).
    if ($esMesActual && $diaActual >= 5 && $consumo > 0) {
        $proy = $consumo / $diaActual * $diasMes;
        if ($proy > $total * 1.05) {
            agregar('ritmo-alto', 'alerta', '📈', 'A este ritmo te pasarías ' . cop($proy - $total),
                'Llevas ' . cop($consumo) . " en $diaActual días. Si reduces unos " . cop(($proy - $total) / max(1, $diasRestantes)) . ' por día lo que resta del mes, cierras dentro del presupuesto.', 92, 'presupuesto');
        } elseif ($proy <= $total * 0.85 && $consumo > 0) {
            agregar('ritmo-bueno', 'logro', '🎯', 'Vas por debajo del ritmo',
                'Proyectas cerrar con ' . cop($total - $proy) . ' de sobra. ¡Sigue así!', 40);
        }
    }
}

// ------------------------------------------------------------------ 4) categorías: cambios frente al mes anterior
if ($cats && $catsAnt) {
    $mayor = null; $mejor = null;
    foreach ($cats as $n => $v) {
        $a = $catsAnt[$n] ?? 0.0;
        if ($a < 50000) continue;
        $dif = $v - $a; $r = $dif / $a;
        if ($dif >= 50000 && $r >= 0.15 && ($mayor === null || $dif > $mayor['dif'])) $mayor = ['n' => $n, 'r' => $r, 'dif' => $dif, 'v' => $v];
        if ($dif <= -50000 && $r <= -0.15 && ($mejor === null || $dif < $mejor['dif'])) $mejor = ['n' => $n, 'r' => $r, 'dif' => $dif, 'v' => $v];
    }
    if ($mayor) {
        agregar('cat-sube', 'consejo', '🔍', "Gastaste " . pct($mayor['r']) . " más en {$mayor['n']}",
            'Son ' . cop($mayor['dif']) . ' más que el mes pasado (' . cop($mayor['v']) . ' en total). Revisa si fue algo puntual o un hábito nuevo.', 70, 'movimientos');
    }
    if ($mejor) {
        agregar('cat-baja', 'logro', '👏', "Redujiste " . pct(-$mejor['r']) . " en {$mejor['n']}",
            'Ahorraste ' . cop(-$mejor['dif']) . ' frente al mes pasado. Ese es el tipo de hábito que construye riqueza.', 45);
    }
}
// Concentración: una categoría domina el gasto
if ($cats && $consumo > 0) {
    arsort($cats);
    $nombre = array_key_first($cats);
    $part = $cats[$nombre] / $consumo;
    if ($part >= 0.4 && count($cats) >= 3) {
        agregar('concentracion', 'consejo', '🥧', "$nombre es el " . pct($part) . ' de tu gasto',
            'Es tu mayor categoría (' . cop($cats[$nombre]) . '). Si quieres ahorrar más, es donde un pequeño recorte tiene más efecto.', 50, 'movimientos');
    }
}

// ------------------------------------------------------------------ 5) ahorro
if ($p && $total > 0) {
    $tasa = $ahorrado / $total;
    if ($ahorrado <= 0) {
        agregar('ahorro-cero', 'consejo', '🐖', 'Págate primero: separa ' . cop($total * 0.1),
            'Aún no has apartado ahorro en ' . $mesLbl . '. La regla simple: ahorra el 10 % apenas recibes ingresos y gasta lo demás.', 65, 'presupuesto');
    } elseif ($tasa >= 0.2) {
        agregar('ahorro-excelente', 'logro', '🏆', 'Estás ahorrando el ' . pct($tasa) . ' de tu presupuesto',
            'Llevas ' . cop($ahorrado) . ' guardados. Superar el 20 % es una meta que casi nadie logra: ¡excelente!', 60);
    } elseif ($tasa >= 0.1) {
        agregar('ahorro-bien', 'logro', '👍', 'Ahorras el ' . pct($tasa) . ' de tu presupuesto',
            'Llevas ' . cop($ahorrado) . ' guardados. Si subes poco a poco hacia el 20 %, tu colchón crece mucho más rápido.', 58);
    } else {
        agregar('ahorro-bajo', 'consejo', '🌱', 'Ahorro actual: ' . pct($tasa),
            'Llevas ' . cop($ahorrado) . '. Intenta llegar al 10 % (' . cop($total * 0.1) . '): sube un poco cada mes y casi no lo notarás.', 62, 'presupuesto');
    }
}

// ------------------------------------------------------------------ 6) deudas y tarjetas
$s = $mysql->prepare("SELECT d.idDeuda, d.Nombre, d.Tipo, d.CupoTotal, d.TasaAnual, d.CuotaMensual, " . SQL_SALDO_DEUDA . " AS saldo FROM deudas d WHERE d.IdUsuario = ? AND d.Activa = 1");
$s->bind_param('i', $uid); $s->execute();
$deudas = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
$conTasa = [];
$interesMes = 0.0; $totalDeuda = 0.0;
foreach ($deudas as $d) {
    $saldo = max(0.0, (float)$d['saldo']);
    if ($saldo <= 0) continue;
    $totalDeuda += $saldo;
    if ($d['Tipo'] === 'Tarjeta' && (float)$d['CupoTotal'] > 0) {
        $uso = $saldo / (float)$d['CupoTotal'];
        if ($uso >= 0.7) {
            agregar('cupo-' . $d['idDeuda'], 'alerta', '💳', "{$d['Nombre']}: usas el " . pct($uso) . ' del cupo',
                'Pasar del 30 % del cupo suele afectar tu historial de crédito. Un abono de ' . cop($saldo - (float)$d['CupoTotal'] * 0.3) . ' la dejaría en el 30 %.', 85, 'deudas');
        }
    }
    if ($d['TasaAnual'] !== null && (float)$d['TasaAnual'] > 0) {
        $mensual = pow(1 + (float)$d['TasaAnual'] / 100, 1 / 12) - 1;
        $interes = $saldo * $mensual;
        $interesMes += $interes;
        $conTasa[] = ['n' => $d['Nombre'], 'ea' => (float)$d['TasaAnual'], 'saldo' => $saldo, 'interes' => $interes];
    }
}
if ($conTasa) {
    usort($conTasa, function ($a, $b) { return $b['ea'] <=> $a['ea']; });
    $top = $conTasa[0];
    if (count($conTasa) >= 2) {
        agregar('avalancha', 'consejo', '🎯', "Prioriza abonar a {$top['n']}",
            'Es tu deuda más cara (' . number_format($top['ea'], 1, ',', '.') . ' % E.A.). Paga el mínimo en las demás y manda todo extra a esta: es lo que más intereses te ahorra.', 75, 'deudas');
    }
    if ($interesMes >= 20000) {
        agregar('intereses', 'consejo', '💸', 'Tus deudas te cuestan ~' . cop($interesMes) . ' al mes en intereses',
            'Calculado con las tasas que registraste. Cada abono extra baja esa cifra el mes siguiente.', 72, 'deudas');
    }
}

// ------------------------------------------------------------------ 7) obligaciones anuales próximas
$s = $mysql->prepare("SELECT Nombre, ValorEstimado, FechaVencimiento, CicloInicio FROM obligaciones WHERE IdUsuario = ? AND Activa = 1 AND FechaVencimiento >= ? AND FechaVencimiento <= DATE_ADD(?, INTERVAL 60 DAY) ORDER BY FechaVencimiento LIMIT 1");
$s->bind_param('iss', $uid, $hoyTxt, $hoyTxt); $s->execute();
$o = $s->get_result()->fetch_assoc(); $s->close();
if ($o) {
    $dias = (int)$hoy->diff(new DateTime($o['FechaVencimiento']))->days;
    agregar('obligacion', 'consejo', '🗓️', "{$o['Nombre']} vence en $dias días",
        'Estimado: ' . cop($o['ValorEstimado']) . '. Verifica que ya tengas ese dinero provisionado para no sacarlo de golpe de tu flujo del mes.', 68, 'deudas');
}

// ------------------------------------------------------------------ 8) próximo mes
if ($esMesActual && $p && $diasRestantes <= 7 && !presupuestoDe($sm, $sa)) {
    agregar('crear-siguiente', 'consejo', '🗂️', 'Prepara el presupuesto de ' . $nombresMes[$sm],
        'Tu mes termina en ' . $diasRestantes . ' días. Crearlo desde tu plantilla toma un minuto y empiezas el mes con el plan listo.', 66, 'presupuesto');
}

// ------------------------------------------------------------------ 9) hábito de registro y motivación
$s = $mysql->prepare("SELECT DISTINCT m.fechaMovimiento AS f FROM movimientos m INNER JOIN gastos g ON g.idGastos = m.idGasto
    INNER JOIN presupuestos pr ON pr.idPresupuesto = g.idPresupuesto AND pr.IdUsuario = ?
    WHERE m.fechaMovimiento >= DATE_SUB(?, INTERVAL 30 DAY) AND m.fechaMovimiento <= ? ORDER BY f DESC");
$s->bind_param('iss', $uid, $hoyTxt, $hoyTxt); $s->execute();
$diasConMov = array_column($s->get_result()->fetch_all(MYSQLI_ASSOC), 'f'); $s->close();
$sinRegistro = $diasConMov ? (int)(new DateTime($diasConMov[0]))->diff($hoy)->days : 99;
if ($esMesActual) {
    if ($sinRegistro >= 3) {
        agregar('habito', 'consejo', '✍️', $sinRegistro >= 99 ? 'Registra tu primer pago' : "Llevas $sinRegistro días sin registrar pagos",
            'Anotar cada pago el mismo día mantiene tus cifras reales y es el hábito que más mejora tus finanzas.', 64, 'movimientos');
    } elseif (count($diasConMov) >= 5) {
        agregar('racha', 'logro', '🔥', count($diasConMov) . ' días registrando en el último mes',
            'La constancia es lo que cambia las finanzas. Cada registro te da control real de tu dinero.', 35);
    }
}
if ($nGastos > 0 && $vencidos === 0 && $nPagados > 0) {
    agregar('al-dia', 'logro', '✅', 'Vas al día con tus pagos',
        "Has pagado $nPagados de $nGastos gastos del mes y no tienes ninguno vencido.", 38);
}

// ------------------------------------------------------------------ salud financiera (0-100, con reglas simples y visibles)
$punt = 0; $partes = [];
$punt += $vencidos === 0 ? 30 : max(0, 30 - 10 * $vencidos);                         // pagos al día
if ($total > 0) {
    $punt += ($previsto <= $total) ? 25 : max(0, (int)round(25 * ($total / max($previsto, 1))));  // plan dentro del presupuesto
    $punt += (int)round(25 * min(1, ($ahorrado / $total) / 0.15));                   // ahorro (meta 15 %)
} 
$usoMax = 0.0;
foreach ($deudas as $d) { if ($d['Tipo'] === 'Tarjeta' && (float)$d['CupoTotal'] > 0) $usoMax = max($usoMax, max(0, (float)$d['saldo']) / (float)$d['CupoTotal']); }
$punt += $usoMax <= 0.3 ? 10 : ($usoMax <= 0.7 ? 5 : 0);                              // uso de tarjetas
$punt += $sinRegistro <= 3 ? 10 : ($sinRegistro <= 7 ? 5 : 0);                         // hábito
$punt = max(0, min(100, $punt));
if (!$p) { $nivel = 'Sin datos'; $mensaje = 'Crea tu presupuesto para empezar a medir tu salud financiera.'; $punt = 0; }
elseif ($punt >= 80) { $nivel = 'Excelente'; $mensaje = '¡Tus finanzas van muy bien! Sigue con esa disciplina.'; }
elseif ($punt >= 60) { $nivel = 'Buena'; $mensaje = 'Vas por buen camino. Un par de ajustes y llegas a excelente.'; }
elseif ($punt >= 40) { $nivel = 'En construcción'; $mensaje = 'Hay base, y hay oportunidades claras. Empieza por la primera sugerencia.'; }
else { $nivel = 'Atención'; $mensaje = 'Es el momento de tomar el control. Un paso a la vez, empezando por la primera sugerencia.'; }

// ------------------------------------------------------------------ salida: lo urgente primero, máximo 7 y al menos un logro/ánimo si existe
usort($sug, function ($a, $b) { return $b['prioridad'] <=> $a['prioridad']; });
$salida = array_slice($sug, 0, 7);
$hayAnimo = false;
foreach ($salida as $x) if ($x['tipo'] === 'logro') $hayAnimo = true;
if (!$hayAnimo) {
    foreach ($sug as $x) if ($x['tipo'] === 'logro') { array_pop($salida); $salida[] = $x; break; }
}
if (!$salida && $p) {
    $salida[] = ['id' => 'todo-bien', 'tipo' => 'motivacion', 'icono' => '🌟', 'titulo' => 'Todo en orden por ahora',
        'detalle' => 'No veo alertas en tus datos. Sigue registrando tus pagos para que mis sugerencias sean cada vez más precisas.', 'prioridad' => 10, 'accion' => null];
}
echo json_encode(['mes' => $mes, 'anho' => $anho,
    'salud' => ['puntaje' => $punt, 'nivel' => $nivel, 'mensaje' => $mensaje],
    'sugerencias' => $salida], JSON_UNESCAPED_UNICODE);
