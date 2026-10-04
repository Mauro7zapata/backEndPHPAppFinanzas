<?php
require_once("../db.php");
require_once("../libInversiones.php");

header('Content-Type: application/json; charset=utf-8');

// Resumen del módulo de Inversiones para la pantalla principal.
//   GET Resumen.php -> {
//        kpi: capital activo, por cobrar, vencido, intereses cobrados (mes / año / total), rendimiento mensual esperado...
//        meses: intereses y capital cobrados en los últimos 12 meses,
//        porTipo / porEstado: distribución del capital,
//        inversiones: cada inversión con su avance y su próxima cuota }

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['error' => 'Método no permitido']);
    exit;
}

echo json_encode(invCalcularResumen(date('Y-m-d')), JSON_UNESCAPED_UNICODE);
