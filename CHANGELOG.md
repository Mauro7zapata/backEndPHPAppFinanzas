# Changelog · Backend

## 2026-10-03 (2) — Deudas, obligaciones anuales y alertas
**Requiere ejecutar `sql/002_deudas_obligaciones.sql` (idempotente, no toca datos existentes).**
| Funcionalidad | Archivo | Cambio | Motivo | Impacto |
|---|---|---|---|---|
| Esquema | sql/002_deudas_obligaciones.sql | Tablas `deudas`, `movimientos_deuda`, `obligaciones`; columnas `gastos.idDeuda` e `idObligacion` (FK SET NULL) | Registrar tarjetas, préstamo y deudas informales; obligaciones anuales | Solo agrega; sin cambios en datos |
| Deudas | Deudas/Deudas.php (nuevo) | CRUD, cargos/abonos/intereses manuales, saldo calculado, próximos corte/pago, progreso | Saldo y progreso por deuda | Endpoint nuevo |
| Abonos automáticos | lib.php, Presupuesto/Movimientos.php | Un movimiento de un gasto vinculado a una deuda crea/actualiza/borra su abono | Pagar desde el presupuesto baja la deuda sin doble registro | Pagos del presupuesto actualizan deudas |
| Vínculo gasto-deuda | Presupuesto/Gastos.php | Acepta `idDeuda` (0 = sin deuda; si no se envía, no cambia) y devuelve `idDeuda`/`idObligacion` | Clientes viejos siguen funcionando | Campos nuevos en el JSON |
| Obligaciones | Deudas/Obligaciones.php (nuevo) | CRUD, ahorrado del ciclo, cuota de provisión = (valor − ahorrado) ÷ meses restantes, `pagar` (+1 año) | Renta, SOAT, tecnomecánica, impuestos | Endpoint nuevo |
| Provisión mensual | Presupuesto/Plantilla.php, lib.php | Al aplicar la plantilla se crea un gasto "Provisión: X" en estado Acumulado por obligación; la plantilla ya arrastra `idDeuda` y no repite provisiones | Que el ahorro anual se planee mes a mes | `provisiones` en la respuesta |
| Alertas | Deudas/Alertas.php (nuevo) | Pagos por vencer/vencidos, obligaciones próximas y cortes de tarjeta; acciones `pagarGasto` y `pagarObligacion` | Notificaciones del celular | Endpoint nuevo |
| Zona horaria | db.php | `date_default_timezone_set('America/Bogota')` | Fechas de vencimiento correctas | Cálculos de "hoy" en hora de Colombia |

## 2026-10-03 — Orden, plantilla y vínculo gasto↔movimiento
| Funcionalidad | Archivo | Cambio | Motivo | Impacto |
|---|---|---|---|---|
| Orden por estado | Presupuesto/Gastos.php | Listados por mes/presupuesto ordenados Pendiente→En proceso→Guardado→Acumulado→Pagado→No aplica, luego fecha límite | Ver primero lo urgente | Cambia el orden de la lista |
| Estado automático | Presupuesto/Movimientos.php | `sincronizarGasto`: ≥95 % del previsto ⇒ Pagado (+FechaPago); >0 ⇒ En proceso; sin movimientos ⇒ Pendiente. No toca Guardado/Acumulado/No aplica | El movimiento debe reflejarse en el gasto | El estado cambia solo al crear/editar/borrar movimientos |
| Corrección | Presupuesto/Movimientos.php | Un JSON de objeto único se procesa como un movimiento (antes se iteraba por campos) | Bug latente | Ninguno para el cliente actual |
| Plantilla | Presupuesto/Plantilla.php (nuevo) | GET sugiere gastos frecuentes (últimos 6 presupuestos, aparición ≥50 %, mín. 2); POST los crea como Pendiente sin duplicar | Evitar crear gastos mes a mes | Endpoint nuevo, sin migración SQL |

## 2026-10-03 · Seguridad y módulo de presupuesto

| Funcionalidad | Archivo | Cambio | Motivo | Impacto |
|---|---|---|---|---|
| Credenciales | `db.php`, `config.example.php`, `.gitignore`, `.htaccess` | Usuario/clave de BD salen del código; se leen de `config.local.php` (fuera de git) | La clave estaba en el repositorio e historial | **Hay que crear `config.local.php` en el servidor y rotar la clave de BD** |
| Autenticación | `db.php` | Exige cabecera `X-API-Key` (comparación en tiempo constante); falla cerrado si no hay configuración | Cualquiera con la URL podía leer/borrar datos | La app debe enviar la clave (ya implementado en `ApiClient`) |
| Manejo de errores | `db.php` | Excepciones SQL → error genérico + log; `display_errors` apagado; errores mysqli uniformes en todas las versiones de PHP | Antes se filtraban rutas/SQL y se rompía el JSON | Mensajes de error más claros, sin detalles internos |
| Presupuestos | `Presupuesto/Presupuestos.php` | Validación de mes/año/valores; montos como entero (`i`) en lugar de `d`; `Mes` ya no se envía como texto; `eliminar` acepta `idPresupuesto` (lo que envía la app) además de `id`; no permite eliminar un presupuesto con gastos (la BD los borraba en cascada); `ExtrasMes` nulo → 0; listas vacías devuelven `[]` | `eliminar` leía `id` y la app enviaba `idPresupuesto` (reportaba éxito sin borrar); riesgo de borrado en cascada; `ExtrasMes` NULL rompía el parseo en la app | Mensajes de éxito sin cambios |
| Gastos | `Presupuesto/Gastos.php` | Validación de nombre/montos; `detalle` indefinido ya no genera warning; errores SQL con mensajes claros; eliminar con movimientos explica el motivo; eliminar inexistente/sin id ya no dice "eliminado" | Mensajes engañosos y SQL expuesto | Sin cambios en rutas/campos |
| Movimientos | `Presupuesto/Movimientos.php` | Valida tipo, valor, fecha real (AAAA-MM-DD) y gasto asociado; no expone SQL | Fechas inválidas / gastos inexistentes producían errores crudos | Sin cambios de contrato |
| Categorías | `Presupuesto/CategoriaGastos.php` | Se elimina el `echo` de depuración; no permite borrar categorías con gastos | Gastos huérfanos desaparecían de listados pero seguían en totales | Mensaje nuevo al intentar borrar una categoría en uso |
| Estados | `Estados.php` | No permite borrar estados en uso (gastos/inversiones/pagos) | Mismo problema de huérfanos | Mensaje nuevo |
| Base de datos | `sql/001_integridad_presupuesto.sql` | UNIQUE (Anho, Mes); FK gastos→categoría/estado; triggers con `COALESCE` y recálculo del gasto anterior al mover un movimiento; recálculo de `valorGastosMovimiento` | Datos descuadrados (gastos 120 y 187) y NULL al borrar el último movimiento | Requiere ejecutar la migración (con respaldo previo) |
