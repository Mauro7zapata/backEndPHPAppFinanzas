<?php
require_once("../db.php");
require_once("../libAuth.php");

// POST (con Bearer) {confirmar: "ELIMINAR", clave?}  -> borra la cuenta y TODOS sus datos (derecho de supresión, Ley 1581).
// Si la cuenta tiene clave, se exige. Es irreversible.
authSoloPost();
$d = authCuerpo();
$u = authBuscarPorId($uid);
if (!$u) appfinanzas_responder_error(401, 'Sesión expirada');
if (($d['confirmar'] ?? '') !== 'ELIMINAR') appfinanzas_responder_error(400, 'Escribe ELIMINAR para confirmar');
authExigirCupo('u' . $uid);
if (!empty($u['ClaveHash']) && !password_verify((string)($d['clave'] ?? ''), $u['ClaveHash'])) {
    authFalloIntento('u' . $uid);
    appfinanzas_responder_error(401, 'La contraseña no es correcta');
}

$mysql->begin_transaction();
try {
    // Orden: hijos primero (hay claves foráneas sin cascada hacia categorías y estados).
    $sql = [
        "DELETE md FROM movimientos_deuda md INNER JOIN deudas d ON d.idDeuda = md.idDeuda WHERE d.IdUsuario = ?",
        "DELETE m FROM movimientos m INNER JOIN gastos g ON g.idGastos = m.idGasto INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto WHERE p.IdUsuario = ?",
        "DELETE g FROM gastos g INNER JOIN presupuestos p ON p.idPresupuesto = g.idPresupuesto WHERE p.IdUsuario = ?",
        "DELETE FROM presupuestos WHERE IdUsuario = ?",
        "DELETE pp FROM PlanPagos pp INNER JOIN Inversiones i ON i.idInversion = pp.idInversion WHERE i.IdUsuario = ?",
        "DELETE FROM Inversiones WHERE IdUsuario = ?",
        "DELETE FROM obligaciones WHERE IdUsuario = ?",
        "DELETE FROM deudas WHERE IdUsuario = ?",
        "DELETE FROM plantilla_gastos WHERE IdUsuario = ?",
        "DELETE FROM configuracion WHERE IdUsuario = ?",
        "DELETE FROM categoriagastos WHERE IdUsuario = ?",
        "DELETE FROM estados WHERE IdUsuario = ?",
        "DELETE FROM sesiones WHERE IdUsuario = ?",
        "DELETE FROM usuarios WHERE IdUsuario = ?",
    ];
    foreach ($sql as $s) {
        $q = $mysql->prepare($s);
        $q->bind_param('i', $uid);
        $q->execute();
        $q->close();
    }
    $mysql->commit();
} catch (Throwable $e) {
    $mysql->rollback();
    throw $e;
}
authResponder(['status' => 'ok']);
