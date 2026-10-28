# Backend PHP · AppFinanzas

API REST (PHP + MySQL, multiusuario) para la app Android **PresupuestoPersonal**: presupuesto mensual, gastos,
movimientos, deudas y tarjetas, inversiones (cuotas, aportes, fondo), plata separada por lugar, inteligencia
financiera, comentarios de usuarios y exportación.

## Estructura

```
AppFinanzas/
  db.php                 Configuración + autenticación (API key + sesión de usuario) + conexión MySQL (mysqli en modo excepción).
  config.example.php     Plantilla de configuración (copiar a config.local.php; este último NO va a git).
  lib.php                Funciones comunes: periodo financiero, ciclo de tarjetas, saldo separado, parámetros.
  libAuth.php            Sesiones/tokens, hash de claves, utilidades de autenticación.
  libInversiones.php     Cálculo de resumen de inversiones (puro, probado con tests/).
  Auth/                  Login, Registro, Google, Refresh, Logout, CambiarClave, EliminarCuenta
  Presupuesto/           Presupuestos, Gastos, Movimientos, CategoriaGastos, IngresosExtra, LugaresGuardado, Plantilla
  Deudas/                Deudas (tarjetas y préstamos), Obligaciones, Alertas
  Inversiones/           inversion, Cuotas, Aportes, Fondo, Resumen, Valor, TipoInversion
  Inicio/Dashboard.php   Resumen de la pantalla de inicio (saldo, próximos pagos, alertas)
  Inteligencia/          Sugerencias basadas en los datos reales del usuario
  Configuracion/         Parametros, Comentarios (con imágenes y comunidad), Exportar (JSON para IA)
  Estados.php            Catálogo de estados
sql/                     Migraciones 001–024 (ejecutar en orden; hacer copia de la BD antes)
tests/run.php            Pruebas de la lógica pura (fechas, ciclos de tarjeta, resumen de inversiones)
AMBIENTES.md             Cómo separar desarrollo (dev) y producción (prd)
CHANGELOG.md             Historial de cambios
```

## Instalación / actualización en el servidor

1. Haz una copia de seguridad de la BD (phpMyAdmin > Exportar).
2. Ejecuta en orden las migraciones de `sql/` que aún no hayas aplicado (001 → 024).
3. Copia `AppFinanzas/config.example.php` a `AppFinanzas/config.local.php` en el servidor y completa host, usuario,
   contraseña, nombre de BD y `api_key` (64 hex; la misma que usa la app en `secrets.properties`).
   Para producción deja `'depurar' => false` (no se muestran detalles de errores al cliente).
4. Sube los archivos PHP (incluido `.htaccess`). Sin `config.local.php` el servidor responde 500 «Servidor sin configurar».
5. Elimina del servidor archivos obsoletos si existen: `PlanPagos.php`, `Perfil.php`, `diag.php`, `probar_login.html`.
6. **Rotación de claves**: cambia la contraseña de MySQL en Hostinger y actualiza `config.local.php`; si cambias la
   `api_key`, hay que recompilar la app con la nueva.

## Autenticación

Toda petición lleva la cabecera `X-API-Key: <clave>`; los endpoints de datos además exigen la sesión del usuario
(token de `Auth/Login.php`). Cada consulta se filtra por el `uid` del usuario. Sin clave o con clave incorrecta → `401`.
La clave viaja en el APK: protege contra accesos casuales, no contra quien decompile la app. Usar siempre HTTPS.

## Contrato de respuestas

- Mayoría de endpoints: JSON (`{"id":n}`, `{"updated":bool}`, `{"deleted":bool}`, `{"error":"…"}`).
- Errores de validación: HTTP 200 con `{"error":"mensaje"}` (la app lo muestra); autenticación/servidor: 401/500.
- Gastos, Categorías y Estados conservan respuestas de texto plano por compatibilidad con la app.
- Dinero: se maneja en la moneda original de cada registro (COP, USD…); nunca se convierte entre monedas.

## Pruebas

```bash
php tests/run.php          # lógica pura, no necesita base de datos; sale con código 1 si algo falla
```

Pruebas manuales contra un servidor local:

```bash
APPFINANZAS_CONFIG=/ruta/test_config.php php -S 127.0.0.1:8081 -t AppFinanzas
curl -H "X-API-Key: <clave>" "http://127.0.0.1:8081/Inicio/Dashboard.php"
```
