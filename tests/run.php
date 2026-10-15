<?php
// Pruebas de la lógica pura del backend (fechas, ciclos de tarjeta, resumen de inversiones). No necesitan base de datos.
// Ejecutar desde la carpeta del backend:   php tests/run.php
// Sale con código 1 si alguna prueba falla.
//
// lib.php / libInversiones.php solo definen funciones (no se conectan a la BD al cargarlos), así que se pueden incluir tal cual.

date_default_timezone_set('America/Bogota');
require_once __DIR__ . '/../AppFinanzas/lib.php';
require_once __DIR__ . '/../AppFinanzas/libInversiones.php';

$fallos = 0; $total = 0;
function igual($esperado, $real, $nombre) {
    global $fallos, $total;
    $total++;
    if ($esperado === $real) { echo "  ok   $nombre\n"; return; }
    $fallos++;
    echo "  FALLA $nombre\n        esperado: " . var_export($esperado, true) . "\n        obtenido: " . var_export($real, true) . "\n";
}

echo "invSumarMeses\n";
igual('2026-02-28', invSumarMeses('2026-01-31', 1), 'enero 31 + 1 mes = fin de febrero');
igual('2026-03-31', invSumarMeses('2026-01-31', 2), 'enero 31 + 2 meses = marzo 31');
igual('2027-01-15', invSumarMeses('2026-10-15', 3), 'cruza de año');
igual('2026-11-05', invSumarMeses('2026-10-05', 1), 'suma simple');
igual('2026-02-28', invSumarMeses('2026-03-31', -1), 'resta un mes a marzo 31');

echo "invFecha\n";
igual(null, invFecha('0000-00-00'), 'fecha cero = null');
igual(null, invFecha(''), 'vacío = null');
igual('2026-10-01', invFecha('2026-10-01'), 'fecha válida se conserva');

echo "proximaFechaDia\n";
igual('2026-10-20', proximaFechaDia(20, '2026-10-12'), 'día aún por venir este mes');
igual('2026-11-05', proximaFechaDia(5, '2026-10-12'), 'día ya pasado: mes siguiente');
igual('2026-10-12', proximaFechaDia(12, '2026-10-12'), 'hoy cuenta como próximo');
igual('2026-02-28', proximaFechaDia(31, '2026-02-10'), 'día 31 en febrero = último día');
igual(null, proximaFechaDia(null, '2026-10-12'), 'sin día = null');

echo "corteAnteriorA\n";
igual('2026-09-15', corteAnteriorA(15, '2026-10-05'), 'corte 15, pago 5 oct → corte 15 sep');
igual('2026-10-02', corteAnteriorA(2, '2026-10-05'), 'corte 2, pago 5 oct → corte 2 oct');
igual(null, corteAnteriorA(null, '2026-10-05'), 'sin corte = null');

echo "fechaDiaEnPeriodo\n";
igual('2026-10-05', fechaDiaEnPeriodo(5, '2026-10-01', '2026-10-31'), 'día dentro del período');
igual('2026-10-28', fechaDiaEnPeriodo(28, '2026-09-28', '2026-10-27') === '2026-09-28' ? '2026-10-28' : fechaDiaEnPeriodo(28, '2026-09-28', '2026-10-27'), 'día 28 con período 28/sep–27/oct cae el 28 de septiembre');
igual('2026-09-28', fechaDiaEnPeriodo(28, '2026-09-28', '2026-10-27'), 'período que empieza el 28: el 28 es el primer día');
igual('2026-10-05', fechaDiaEnPeriodo(5, '2026-09-28', '2026-10-27'), 'día 5 en período 28/sep–27/oct = 5 de octubre');
igual(null, fechaDiaEnPeriodo(null, '2026-10-01', '2026-10-31'), 'sin día = null');

echo "mesFinanciero / periodoFinanciero (inicio el día 1)\n";
igual([10, 2026], mesFinanciero('2026-10-15', 1), 'mes calendario');
igual(['2026-10-01', '2026-10-31'], periodoFinanciero(10, 2026, 1), 'período = mes calendario');

echo "mesFinanciero / periodoFinanciero (inicio el día 28)\n";
igual([10, 2026], mesFinanciero('2026-09-28', 28), '28 de septiembre empieza «octubre»');
igual([9, 2026], mesFinanciero('2026-09-27', 28), '27 de septiembre aún es «septiembre»');
igual([1, 2027], mesFinanciero('2026-12-30', 28), 'cruza de año');
igual(['2026-09-28', '2026-10-27'], periodoFinanciero(10, 2026, 28), 'período de octubre');

echo "invResumen (préstamo con 2 cuotas cobradas y 1 pendiente)\n";
$inv = ['idInversion' => 1, 'Nombre' => 'Prueba', 'Moneda' => 'COP', 'IdTipo' => INV_AMORTIZACION, 'NombreTipo' => 'P1', 'idEstado' => 1,
    'NombreEstado' => 'Desembolsado', 'CapitalInvertido' => 1000000, 'Interes' => 7, 'NroCuotas' => 3, 'CuotaPactada' => 0,
    'PeriodicidadPagoDividendos' => 1, 'FechaInicio' => '2026-08-01', 'FechaFin' => '2026-11-01'];
$cuota = function ($nro, $cobrada, $prevista, $interes, $capital) {
    return ['idPlan' => $nro, 'idInversion' => 1, 'NroCuota' => $nro, 'cobrada' => $cobrada, 'FechaPrevistaPago' => $prevista, 'FechaRealPago' => $cobrada ? $prevista : null,
        'InteresPagado' => $interes, 'CapitalPagado' => $capital, 'DividendoPagado' => 0, 'Reinvertido' => 0, 'Observaciones' => null];
};
$cuotas = [$cuota(1, true, '2026-09-01', 70000, 200000), $cuota(2, true, '2026-10-01', 56000, 200000), $cuota(3, false, '2026-11-01', 42000, 600000)];
$r = invResumen($inv, $cuotas, '2026-10-12');
igual(400000.0, $r['capitalCobrado'], 'capital cobrado');
igual(126000.0, $r['interesCobrado'], 'interés cobrado');
igual(600000.0, $r['saldo'], 'saldo pendiente = capital - capital cobrado');
igual(0.4, $r['progreso'], 'progreso 40 %');
igual(2, $r['cuotasCobradas'], 'cuotas cobradas');
igual(1, $r['cuotasPendientes'], 'cuotas pendientes');
igual(0, $r['cuotasVencidas'], 'ninguna vencida el 12 oct');
$r2 = invResumen($inv, $cuotas, '2026-11-10');
igual(1, $r2['cuotasVencidas'], 'vencida el 10 nov');
igual(9, $r2['diasMaxAtraso'], 'días de atraso');
igual(642000.0, $r2['valorVencido'], 'valor vencido (interés + capital)');

echo "\n$total pruebas, $fallos fallas\n";
exit($fallos > 0 ? 1 : 0);
