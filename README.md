# Backend PHP · AppFinanzas

API REST en PHP + MySQL para la app Android **PresupuestoPersonal** (presupuesto mensual, gastos, movimientos,
inversiones y plan de pagos).

## Estructura

```
AppFinanzas/
  db.php               Configuración + autenticación (API key) + conexión MySQL. Lo incluyen TODOS los endpoints.
  config.example.php   Plantilla de configuración (copiar a config.local.php).
  config.local.php     ¡NO está en git! Credenciales de BD y API key del servidor.
  Estados.php          Catálogo de estados
  Presupuesto/         Presupuestos, Gastos, Movimientos, CategoriaGastos
  Inversiones/         inversion, PlanPagos, TipoInversion
sql/                   Migraciones de base de datos (ejecutar en orden)
```

## Instalación / actualización en el servidor

1. **Rota la contraseña de MySQL** en el panel de Hostinger (la anterior estuvo escrita en el código y en el historial de git).
2. Copia `AppFinanzas/config.example.php` a `AppFinanzas/config.local.php` en el servidor y completa:
   host, usuario, **nueva** contraseña, nombre de BD y `api_key` (64 hex; la misma que usa la app en `secrets.properties`).
3. Haz una copia de seguridad de la BD (phpMyAdmin > Exportar) y ejecuta `sql/001_integridad_presupuesto.sql`.
4. Sube los archivos PHP (incluido `.htaccess`). Sin `config.local.php` el servidor responde 500 "Servidor sin configurar".
5. Instala la nueva versión de la app (con `api.key` en `secrets.properties`). Las versiones antiguas de la app
   recibirán 401 porque no envían la clave.

## Autenticación

Todas las peticiones deben incluir la cabecera `X-API-Key: <clave>`. Sin ella (o incorrecta) → `401 {"status":"error","message":"No autorizado"}`.
La clave viaja en el APK: protege contra accesos casuales y bots, no contra alguien que decompile tu app. Úsala siempre sobre HTTPS.

## Contrato de respuestas (heredado, se mantiene por compatibilidad con la app)

- Gastos / Categorías / Estados: texto plano ("Gasto insertado correctamente.", …). La app detecta el éxito buscando ese texto.
- Presupuestos: JSON `{"status":"success|error","message":"…"}`.
- Movimientos / Inversiones / PlanPagos / TipoInversión: JSON (`{"id":n}`, `{"updated":bool}`, `{"deleted":bool}`, `{"error":"…"}`).
- Errores de validación se devuelven con HTTP 200 y mensaje claro (la app muestra el mensaje); errores de autenticación/servidor usan 401/500.

## Pruebas locales

```bash
APPFINANZAS_CONFIG=/ruta/test_config.php php -S 127.0.0.1:8081 -t AppFinanzas
curl -H "X-API-Key: <clave>" "http://127.0.0.1:8081/Presupuesto/Presupuestos.php?mes=1&anho=2025"
```
