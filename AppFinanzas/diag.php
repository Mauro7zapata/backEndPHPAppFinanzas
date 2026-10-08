<?php
// DIAGNÓSTICO TEMPORAL: súbelo, ábrelo una vez en el navegador y BÓRRALO del servidor.
define('APPFINANZAS_SIN_SESION', true);
ini_set('display_errors', '1');
header('Content-Type: application/json; charset=utf-8');
$r = ['php' => PHP_VERSION];
register_shutdown_function(function () use (&$r) {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo json_encode(['fatal' => $e['message'], 'archivo' => basename($e['file']), 'linea' => $e['line']]);
    }
});
require __DIR__ . '/db.php';   // si falla aquí, responde su propio error (configuración o conexión)
$r['bd'] = $mysql->query("SELECT DATABASE() d")->fetch_assoc()['d'];
$t = [];
foreach ($mysql->query("SHOW TABLES") as $f) $t[] = array_values($f)[0];
$r['tablas'] = count($t);
foreach (['usuarios', 'sesiones', 'intentos_acceso', 'deudas', 'movimientos_deuda', 'Inversiones', 'gastos', 'presupuestos'] as $n) {
    $r['existe'][$n] = in_array(strtolower($n), array_map('strtolower', $t));
}
$c = $mysql->query("SELECT COUNT(*) n FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'movimientos_deuda' AND column_name = 'Moneda'")->fetch_assoc();
$r['migracion_018'] = (int)$c['n'] > 0;
echo json_encode($r, JSON_PRETTY_PRINT);
