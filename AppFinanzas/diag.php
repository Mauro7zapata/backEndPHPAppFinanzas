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
$col = function ($tabla, $columna) use ($mysql) {
    $q = $mysql->prepare("SELECT COUNT(*) n FROM information_schema.columns WHERE table_schema = DATABASE() AND LOWER(table_name) = LOWER(?) AND LOWER(column_name) = LOWER(?)");
    $q->bind_param('ss', $tabla, $columna); $q->execute();
    return (int)$q->get_result()->fetch_assoc()['n'] > 0;
};
$tab = function ($n) use ($t) { return in_array(strtolower($n), array_map('strtolower', $t)); };
$r['migraciones_ok'] = [
    '005_multiusuario' => $tab('usuarios') && $tab('sesiones') && $col('gastos', 'IdUsuario') === $col('gastos', 'IdUsuario'),
    '010_finalizado' => $col('presupuestos', 'IdEstado') && $col('presupuestos', 'CierreManual'),
    '011_fondo' => $tab('fondo_inversion'),
    '012_lugares' => $tab('lugares_guardado') && $col('gastos', 'idLugar'),
    '013_moneda_inv' => $col('Inversiones', 'Moneda'),
    '014_saldo_separado' => $tab('separado_usos'),
    '015_acciones' => $col('Inversiones', 'ValorActual') && $tab('valoraciones_inversion') && $col('PlanPagos', 'Reinvertido'),
    '018_deudas_moneda' => $col('movimientos_deuda', 'Moneda') && $col('deudas', 'SaldoInicialUsd'),
];
// Últimas líneas del log de errores de PHP, si Hostinger lo dejó junto a los archivos.
foreach (glob(__DIR__ . '/{error_log,*/error_log}', GLOB_BRACE) as $f) {
    $l = array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: [], -6);
    $r['error_log'][str_replace(__DIR__, '', $f)] = array_map(function ($x) { return mb_substr($x, 0, 300); }, $l);
}
echo json_encode($r, JSON_PRETTY_PRINT);
