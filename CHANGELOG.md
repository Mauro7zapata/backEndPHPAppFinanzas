# Changelog · Backend

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
