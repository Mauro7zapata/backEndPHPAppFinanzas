<?php
require_once("../db.php");
require_once("../lib.php");

header('Content-Type: application/json; charset=utf-8');

// Parámetros de la app guardados en el servidor (tabla configuracion).
//   GET  Parametros.php               -> {"parametros": {clave: valor, ...}, "reglas": {clave: {min, max, porDefecto}}}
//   POST Parametros.php (JSON)        -> {"dias_aviso_pagos": 5, ...}  guarda solo las claves conocidas y válidas

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = json_decode(file_get_contents('php://input'), true);
    if (!is_array($d)) $d = $_POST;
    $guardados = [];
    foreach ($d as $clave => $valor) {
        if (!isset(PARAMETROS_APP[$clave])) continue;
        $n = appfinanzas_entero($valor);
        [$def, $min, $max] = PARAMETROS_APP[$clave];
        if ($n === null || $n < $min || $n > $max) {
            echo json_encode(['error' => "El valor de $clave debe estar entre $min y $max"], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $guardados[$clave] = $n;
    }
    if (!$guardados) { echo json_encode(['error' => 'No se recibió ningún parámetro válido'], JSON_UNESCAPED_UNICODE); exit; }
    try {
        // PK (IdUsuario, Clave): cada usuario guarda sus propios parámetros.
        $stmt = $mysql->prepare("INSERT INTO configuracion (IdUsuario, Clave, Valor) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE Valor = VALUES(Valor)");
        foreach ($guardados as $clave => $n) {
            $txt = (string)$n;
            $stmt->bind_param('iss', $uid, $clave, $txt);
            $stmt->execute();
        }
    } catch (mysqli_sql_exception $e) {
        error_log('[AppFinanzas] Parametros: ' . $e->getMessage());
        echo json_encode(['error' => 'No se pudo guardar. ¿Ejecutaste la migración 004?'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['updated' => count($guardados)]);
    exit;
}

$reglas = [];
foreach (PARAMETROS_APP as $k => $r) $reglas[$k] = ['porDefecto' => $r[0], 'min' => $r[1], 'max' => $r[2]];
echo json_encode(['parametros' => parametrosApp(), 'reglas' => $reglas], JSON_UNESCAPED_UNICODE);
