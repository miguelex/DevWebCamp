# PORT-4: autenticación y autorización

> **Estado:** PORT-4 «En curso» en Jira. La rama `PORT-4-adaptar-autenticacion-y-autorizacion-al-nuevo-sistema` parte de `main` en `d4e9168` y está publicada. Bloque 1 caracterizado, pendiente de revisión; bloques 2–5 pendientes. No hay cambios productivos ni commits PORT-4.
>
> **Fuente:** [PORT-4](https://miguelexd.atlassian.net/browse/PORT-4), hija de PORT-1. Jira solo contiene el título «Adaptar autenticación y autorización al nuevo sistema»: no hay descripción ni criterios de aceptación. Este plan recoge el alcance propuesto y aprobado por el usuario; no atribuye estos detalles a Jira.

## Alcance y decisión principal

Adaptar la autenticación y autorización de DevWebCamp a una estructura coherente con `php-template`, sin copiar sus componentes literalmente. Conservar las rutas y los flujos válidos de login, logout, registro de cuenta, confirmación, recuperación y acceso por rol. Los defectos de seguridad observados se prueban como **estado anterior a corregir**, no como contratos de compatibilidad.

`Usuario` conserva por ahora la tabla `usuarios_devwebcamp` y su contrato de persistencia PDO de PORT-3. Las claves de sesión actuales (`id`, `nombre`, `apellido`, `email`, `admin`) y sus consumidores deben tratarse explícitamente al migrar; no sustituirlas silenciosamente por el `user_id` del template. Cualquier cambio incompatible de token, ruta, respuesta HTTP o esquema necesita análisis y aprobación antes de implementarse.

## Mapa para revisión

| Área | Código actual | Contrato o problema |
| --- | --- | --- |
| Login/logout | `AuthController`, `Usuario` | bcrypt, cuenta confirmada, sesión por cinco claves; POST `/logout`. |
| Cuenta/token | `AuthController`, `Usuario`, `Email` | Token de cuenta `uniqid()` de 13 caracteres; confirmación y reset consumen el token; email es efecto externo, excluido de los runners. |
| Autorización | `is_auth()`, `is_admin()`, controladores | Guards dispersos y redirecciones sin terminar ejecución. |
| Administración | `config/routes.php`, controladores admin, Router | Router selecciona layout por URI, pero no autoriza. Tres páginas admin son accesibles sin sesión. |
| Inscripción | `RegistroController` | Depende de `$_SESSION['id']`; registro presencial da acceso a conferencias y token de boleto. |
| Template | `AuthMiddleware`, `User`, `Router` | Referencia conceptual; `AuthMiddleware` requiere `Router::redirect()/use()` y `user_id`, ausentes aquí. `User` usa otro esquema. |

### Contratos válidos a preservar

- Login con email y contraseña correctos de un usuario confirmado; rechazo de contraseña incorrecta y usuario no confirmado.
- Hash bcrypt generado por `Usuario::hashPassword()` y verificado con `password_verify()`.
- La identidad y rol efectivos del usuario autenticado; el usuario normal no debe recibir acceso admin. Mantener compatibilidad temporal con las claves de sesión consumidas por inscripción y vistas.
- Rutas públicas existentes (`/login`, `/logout`, `/registro`, `/olvide`, `/reestablecer`, `/confirmar-cuenta`) y resultados funcionales de confirmación y restablecimiento para tokens válidos.
- Acceso legítimo de administrador a sus rutas; acceso legítimo del usuario normal a su inscripción y conferencias cuando corresponde al paquete 1.
- Los contratos de persistencia, tipos escalares y JSON cerrados en PORT-3.

### Defectos observados: NO preservar como requisitos

| Defecto | Evidencia del bloque 1 | Dirección para PORT-4 |
| --- | --- | --- |
| `/admin/dashboard`, `/admin/registrados`, `/admin/regalos` accesibles anónimamente y por usuario normal | GET devuelve 200 con layout admin. | Denegar acceso antes de renderizar. |
| Redirección de guard sin `return`/`exit` | GET de ponentes/eventos devuelve 302 pero conserva cuerpo admin; POST de eliminación con `id=0` sobrescribe `/login` por `/admin/...`. | Detener despacho/acción al denegar. |
| Registro público permite asignación masiva | `Usuario::sincronizar($_POST)` acepta `id`, `admin`, `confirmado`, `token`, `password`. | Lista explícita de campos permitidos por flujo; no confiar en atributos enviados. |
| Sesión débil | `is_auth()` confía en `nombre`; `is_admin()` confía en `admin` de sesión; login no regenera ID; logout no destruye cookie/sesión. | Fijar identidad y ciclo de sesión verificables. |
| Tokens de cuenta predecibles y sin expiración observada | `Usuario::crearToken()` usa `uniqid()`; no hay campo/validación de caducidad. | Revisar formato/compatibilidad y consultar antes de cualquier cambio de esquema. |
| Redirección anónima de conferencias termina en `/` | GET `/finalizar-registro/conferencias` devuelve 302 `/` tras continuar ejecutando lógica. | Cortar al negar acceso. |

El boleto por token y otras reglas de inscripción merecen verificación de autorización en PORT-4, pero no se rediseñarán pagos ni selección de conferencias. El defecto visual de boletos y el include legacy de conferencias no se corrigen incidentalmente.

## Bloques de trabajo

1. **Caracterización legacy (este bloque).** `tests/auth/legacy_contract.php` prueba login, logout, claves de sesión, confirmación/reset, token y asignación masiva con usuarios sintéticos dentro de una transacción con rollback y huella antes/después. `tests/auth/http_contract.php` levanta un servidor local efímero con sesiones aisladas, verifica GET/POST seguros por rol, redirecciones/layouts/inscripción, y compara huellas de filas sin escribir en BD. Ningún runner envía correos, paga o sube archivos.
2. **Compatibilidad y ciclo de sesión.** Introducir una abstracción mínima adaptada a las claves actuales; verificar identidad, regeneración del ID, logout efectivo y consumidores existentes. Sin modernizar Router por arrastre.
3. **Ciclo de cuenta.** Adaptar login/registro/confirmación/recuperación con lista de campos permitidos, controles de token y pruebas aisladas. No cambiar esquema, token existente o servicio de correo sin decisión explícita.
4. **Autorización efectiva.** Guardar todas las rutas admin GET/POST y las de inscripción, detener ejecución antes de consultas/render/escrituras, y verificar acceso de anónimo/normal/admin. Priorizar los tres endpoints admin públicos y los POST cuya redirección se sobrescribe.
5. **Regresión y cierre técnico.** Repetir pruebas de auth, baseline PDO/HTTP, fixtures, rutas públicas/API/admin y revisión integral del diff. Documentar compatibilidad temporal y deuda residual; la regresión integral sigue reservada a PORT-9.

Cada bloque se revisa y valida de forma independiente antes de commitearlo. No se implementa el siguiente bloque automáticamente.

## Ejecutar el bloque 1

Requiere la BD local `devwebcamp` con los fixtures sintéticos del baseline (2 usuarios confirmados —uno admin y uno normal—, 1 inscripción del normal, 0 relaciones de eventos), PHP con `pdo_mysql` y acceso a loopback.

```bash
php tests/auth/legacy_contract.php
php tests/auth/http_contract.php
php -l tests/auth/legacy_contract.php
php -l tests/auth/http_contract.php
```

El runner CLI aborta salvo `DB_NAME=devwebcamp`, hace toda su DML sintética en **una única conexión y transacción**, ejecuta `ROLLBACK` aun ante error y compara conteos/huellas. La versión final usa IDs negativos explícitos para no adelantar `AUTO_INCREMENT`: se comprobó 18→18 en usuarios y 24→24 en registros. Las primeras ejecuciones exploratorias sí utilizaron IDs generados y pudieron adelantar esos contadores aun con rollback; no se han reiniciado, porque eso requeriría una modificación de esquema. Las filas y huellas de fixtures permanecieron intactas. El runner HTTP usa los dos usuarios sintéticos ya existentes para construir sesiones temporales equivalentes a login, arranca un servidor solo en loopback y ejecuta POST admin únicamente con `id=0` (ninguna fila seleccionable). No conoce ni imprime contraseñas de fixtures. Compara huellas de usuarios, inscripciones, relaciones, ponentes y eventos antes/después. Ambos eliminan sus archivos de sesión temporales.

La prueba HTTP **no representa login positivo a través de HTTP**: ese flujo se prueba con usuarios sintéticos en transacción mediante el controlador real. Tampoco invoca POST `/registro` o `/olvide`, porque mandarían correo real; la generación, consumo y validación de tokens se caracteriza sin enviarlo. No se prueban pagos, uploads ni CRUD administrativo real.

## Exclusiones y criterios de parada

- Sin cambio de código productivo, esquema, fixtures permanentes, Composer o dependencias en el bloque 1.
- Sin `User` del template, middleware copiado, rediseño del Router, repositorios, frontend/build, pagos o correo real.
- PORT-5: herramientas generales de calidad/análisis. PORT-6: infraestructura general de tests. PORT-7: frontend/build. PORT-8: CI. PORT-9: regresión integral.
- Detenerse ante una diferencia no caracterizada de sesión, rol, tipos, token, redirección o huellas; ante cualquier escritura no revertida; o si corregir un defecto requiere ampliar el alcance o cambiar esquema. Los tests que fijan defectos deberán evolucionar explícitamente al corregirlos: nunca convertir esas salidas inseguras en requisito de compatibilidad.
