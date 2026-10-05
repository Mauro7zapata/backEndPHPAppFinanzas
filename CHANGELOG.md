# Changelog · Backend

## 2026-10-04 (6) · Inicio: muestra el último presupuesto creado

| Funcionalidad | Archivo | Cambio | Motivo | Impacto |
|---|---|---|---|---|
| Cobros atrasados | `libInversiones.php`, `Inicio/Dashboard.php` | El aviso dice «3 cuotas atrasadas en 2 inversiones» (se agrega `inversionesAtrasadas` al resumen) | Decía «3 cobros atrasados» pero la lista muestra inversiones y una tenía 2 cuotas atrasadas | Solo cambia el texto; las cifras no |
| Mes por defecto del Inicio | `Inicio/Dashboard.php` | Sin `mes`/`anho`, si existe un presupuesto más reciente que el mes financiero actual (p. ej. octubre creado el 4 de octubre con "el mes empieza el día 25", cuando aún es septiembre) se muestra ese. Un mes que aún no empieza cuenta 0 días transcurridos | El Inicio mostraba septiembre aunque ya estaba creado octubre | Con el mes pedido explícitamente (flechas) no cambia nada |

## 2026-10-04 (5) · Cuentas de usuario (multiusuario)

**Requiere ejecutar `sql/005_multiusuario.sql` (copia de seguridad antes) y actualizar `config.local.php` (ver `config.example.php`). Sube TODO el backend: casi todos los archivos cambiaron.**

| Funcionalidad | Archivo | Cambio | Motivo | Impacto |
|---|---|---|---|---|
| Usuarios y sesiones | `sql/005_multiusuario.sql` | Tablas `usuarios`, `sesiones` (tokens solo como hash SHA-256) e `intentos_acceso`; `IdUsuario` en presupuestos, categorías, estados, deudas, obligaciones, inversiones, plantilla y configuración; claves únicas por usuario; todo lo existente pasa al usuario 1 | Base para comercializar: cada persona ve solo sus datos | Idempotente; no borra datos |
| Registro / login | `Auth/Registro.php`, `Login.php`, `libAuth.php` | Correo + contraseña (`password_hash`), validación de fortaleza, límite de intentos por correo e IP (429), respuesta igual para correo inexistente o clave errónea | Acceso por usuario | Usuario nuevo recibe estados y categorías básicas |
| Google | `Auth/Google.php`, `libAuth.php` | Verifica el ID token con Google (audiencia, emisor, vigencia, correo verificado); crea o vincula la cuenta | Acceso con Google | Requiere `google_client_ids` en la configuración |
| Sesión | `Auth/Refresh.php`, `Logout.php`, `Perfil.php`, `db.php` | Token de acceso de 1 h + token de renovación de 90 días que rota en cada uso; `db.php` exige `Authorization: Bearer` y expone `$uid`; cierre de sesión y de todas las sesiones | Seguridad: ya no hay clave compartida en el APK | La API key compartida solo sirve si se configura `api_key_usuario` (transición) |
| Cuenta | `Auth/CambiarClave.php`, `EliminarCuenta.php` | Cambiar contraseña (cierra otras sesiones) y eliminar cuenta con todos sus datos | Derecho de supresión (Ley 1581) | Irreversible; exige contraseña y escribir ELIMINAR |
| Cuenta inicial | `Auth/Registro.php`, `config.example.php` | El correo de la migración se reclama con `codigo_cuenta_inicial` o entrando con Google con ese correo | Conservar tus datos actuales | Borrar el código al reclamarla |
| Aislamiento | `lib.php`, `libInversiones.php`, `Presupuesto/*`, `Inversiones/*`, `Deudas/*`, `Inicio/Dashboard.php`, `Estados.php`, `Configuracion/Parametros.php` | ~130 consultas filtran por `IdUsuario`; los hijos verifican su padre; los ids foráneos del cliente se validan; ids ajenos responden como inexistentes | Ningún usuario puede leer ni modificar datos de otro | Probado con dos usuarios en todos los endpoints; respuestas del usuario 1 idénticas a las anteriores |
| Errores de id ajeno | `Cuotas.php`, `Deudas.php`, `Obligaciones.php` | `observar`, `actualizar` y `movimiento` con id inexistente ahora devuelven error (antes `updated:true`) | Coherencia | Solo con ids inexistentes o ajenos |

## 2026-10-04 (4) · Observaciones, plan que se recalcula y parámetros

**Requiere ejecutar `sql/004_observaciones_configuracion.sql` en phpMyAdmin (copia de seguridad antes).**

| Funcionalidad | Archivo | Cambio | Motivo | Impacto |
|---|---|---|---|---|
| Observaciones por cuota | `sql/004_...sql`, `libInversiones.php`, `Inversiones/Cuotas.php` | Columna `PlanPagos.Observaciones` (500); `actualizar` y `cobrar` aceptan `observaciones`; acción nueva `observar`; las cuotas devuelven `observaciones` | Poder anotar algo sobre cada cuota | Campo opcional; contrato anterior intacto |
| Plan que se recalcula | `libInversiones.php` (`invSincronizarPlan`), `Inversiones/inversion.php` | Si cambia la fecha final de una inversión con plazo, las cuotas pendientes se regeneran hasta la nueva fecha (octubre → diciembre: 7 → 9) y `NroCuotas` se actualiza; si se acorta, sobran y se eliminan solo las pendientes; **las cobradas no se tocan** y las observaciones se conservan; responde `plan {total, agregadas, eliminadas}` | Que el número de cuotas siga la fecha | Solo cuando cambia `FechaFin` (tipos con plazo; no acciones ni ganancia fija) |
| Recalcular siguientes | `Inversiones/Cuotas.php` | `actualizar` con `recalcularSiguientes: true` corre las cuotas pendientes siguientes mes a mes desde la nueva fecha | Al mover una fecha, las demás la siguen | Opcional; por defecto no cambia nada |
| Nota en la notificación | `Deudas/Alertas.php`, `lib.php` | `pagarGasto` y `cobrarCuota` aceptan `observaciones` (se guarda en el movimiento / en la cuota) | Escribir una observación al tocar "Ya pagué/Ya cobré" | Opcional |
| Parámetros | `sql/004_...sql`, `lib.php`, `Configuracion/Parametros.php` (nuevo) | Tabla `configuracion` (clave/valor) con días de aviso, día de inicio del mes financiero (1 a 31; en meses cortos empieza el último día), % de alerta del presupuesto; GET/POST validados por rangos | Configurar la app sin tocar código | Sin la migración se usan los valores por defecto |
| Mes financiero | `Inicio/Dashboard.php`, `lib.php` | El mes actual, los días restantes y el gasto diario usan el día de inicio configurado (inicio el 25: del 25 oct al 24 nov es "octubre"); aviso al alcanzar el % del presupuesto configurado; la agenda trae `idInversion` y `mes/anho` | Meses que empiezan el día de pago | Con inicio = 1 todo queda igual que antes |
| Plantilla con check | `Presupuesto/Plantilla.php`, `sql/004_...sql` | Tabla `plantilla_gastos` (nombre + categoría) y acciones `marcar` / `?marcados=1`; si hay gastos marcados la plantilla son exactamente esos (valor y día de su aparición más reciente), si no hay ninguno se mantiene la detección automática | Elegir con un check qué gastos se repiten cada mes | Compatible: sin marcas todo funciona como antes |
| Avisos | `Deudas/Alertas.php` | Los días de anticipación por defecto salen de los parámetros | Configurable desde la app | Igual que antes si no se cambian |

## 2026-10-04 (3) · Alertas con destino

| Funcionalidad | Archivo | Cambio | Motivo | Impacto |
|---|---|---|---|---|
| Alertas | `Deudas/Alertas.php` | Las alertas de cobro incluyen `idInversion`; las de gasto incluyen `mes` y `anho` de su presupuesto | Que al tocar la notificación la app abra directamente la inversión o el presupuesto | Campos nuevos; los anteriores no cambian |

## 2026-10-04 (2) · Resumen para la pantalla de inicio

| Funcionalidad | Archivo | Cambio | Motivo | Impacto |
|---|---|---|---|---|
| Dashboard de inicio | `Inicio/Dashboard.php` (nuevo) | GET `?mes&anho` (por defecto el mes actual en Bogotá): presupuesto (total, gastado, por pagar, restante, gasto diario disponible), top categorías, tendencia 6 meses, deudas, obligaciones, inversiones, agenda de pagos y cobros, hábito de registro (racha y puntaje) y mensajes de ánimo/alerta generados solo con datos reales | Una sola llamada para mostrar "cómo estoy" al abrir la app | Solo lectura; endpoint nuevo |
| Cálculo de inversiones reutilizable | `libInversiones.php`, `Inversiones/Resumen.php` | El cálculo del resumen pasó a `invCalcularResumen()`; `Resumen.php` solo lo invoca | Reutilizarlo en el dashboard sin duplicar | Misma respuesta que antes |

## 2026-10-04 · Inversiones: resumen, plan de cobros y alertas

**Requiere ejecutar `sql/003_inversiones.sql` en phpMyAdmin (copia de seguridad antes).**

| Funcionalidad | Archivo | Cambio | Motivo | Impacto |
|---|---|---|---|---|
| Limpieza de datos | `sql/003_inversiones.sql` | Fechas `0000-00-00` → NULL (cuotas sin cobrar, inversiones sin fecha final); 7 cuotas con estado de *Gastos* pasan a *Pagos > Pendiente*; índice `(IdEstado, FechaPrevistaPago)` | Fechas inválidas rompían lecturas y cálculos | No borra registros; idempotente |
| Lógica de inversiones | `libInversiones.php` (nuevo) | Cuotas por tipo (amortiza, interés fijo, indefinido, ganancia fija, cobranza), saldo, propuesta de cuota, generación del plan, cobro y liquidación automática | Un solo lugar para las reglas | Sin impacto en lo existente |
| Resumen | `Inversiones/Resumen.php` (nuevo) | Lista con avance, próxima cuota y atrasos + estadísticas (capital activo, intereses del mes/año, por cobrar a 30 días, vencido, cobros por mes, capital por tipo) | Pantalla principal de inversiones | Solo lectura |
| Cuotas | `Inversiones/Cuotas.php` (nuevo) | `generar` (con `simular`), `crear`, `actualizar`, `cobrar`, `deshacer`, `eliminar`; GET con `propuesta=anterior\|recalculada` | Crear todas las cuotas pendientes y agregar una nueva basada en la anterior o recalculada | Endpoint nuevo |
| CRUD inversión | `Inversiones/inversion.php` | Corregido el `echo "va a crear"` que corrompía la respuesta JSON; validación completa (tipo, estado, fechas, rangos); campos que no aplican se guardan 0/NULL; `eliminar` borra también las cuotas en una transacción; devuelve fechas nulas como `null` | La respuesta de *crear* no era JSON válido; sin validaciones | Mismo contrato para las pantallas antiguas |
| Alertas | `Deudas/Alertas.php` | Nuevo tipo `cobro` (cuotas por cobrar de inversiones activas, hasta 25) y acción `cobrarCuota` | Avisos de cobro en la barra de notificaciones | La app debe actualizarse para mostrarlos |

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

- **Mes financiero nombrado por el mes que termina** (`lib.php` `mesFinanciero`/`periodoFinanciero`): con `dia_inicio_mes` = 28, el 28 de septiembre empieza «octubre» (28/sep–27/oct). Día 1 = mes calendario. Afecta Inicio/Dashboard (mes por defecto, días restantes).

- **Inversiones: agregar capital** (`sql/006_aportes_inversion.sql`, `Inversiones/Aportes.php`, `libInversiones.php` `invAportes`, `Cuotas.php` devuelve `aportes`, `inversion.php` borra aportes al eliminar): tabla de aportes (fecha, valor, observación); cada aporte suma al capital de la inversión y al editar/eliminar se ajusta la diferencia. Requiere ejecutar la migración 006.

- **Inicio → «Lo que viene»**: los pagos del presupuesto ahora son solo los del mes que estás viendo con las flechas (antes mostraba los de cualquier mes cercano). Deudas, cobros de inversiones y obligaciones siguen apareciendo siempre. (`Inicio/Dashboard.php`)

- **Inicio → «Lo que viene» clasificado** (`InicioUi.kt`, `Dashboard.php`): los pagos y cobros se agrupan por origen (Presupuesto del mes, Cobros de inversiones, Deudas y tarjetas, Obligaciones anuales) y el servidor envía hasta 6 de cada clase.


- **Prioridad por categoría + orden y agrupación del presupuesto** (`Categoria.kt`, `CategoriasActivity.kt`, `PresupuestoActivity.kt`; servidor `CategoriaGastos.php`, `sql/007_prioridad_categoria.sql`): cada categoría tiene prioridad (Alta, Media, Baja, Sin prioridad). En Presupuesto hay «Orden» (estado, prioridad, fecha límite, mayor valor) y «Agrupar por categoría» con subtotales; la elección se recuerda. Requiere ejecutar la migración 007 antes de subir CategoriaGastos.php.
- **Inicio**: el saludo incluye el nombre del usuario («Buenas tardes, Mauro 👋»). (`InicioUi.kt`)

- **Gastos: errores al guardar más claros** (`Presupuesto/Gastos.php` `mensajeErrorGasto`): el mensaje indica el código de MySQL (y la causa real con `depurar`), y avisa si el gasto ya existe en el presupuesto.
