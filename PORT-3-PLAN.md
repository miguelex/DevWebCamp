# PORT-3: plan aprobado para migrar la persistencia a PDO

> **Estado:** alcance aprobado; PORT-3 sigue «En curso» en Jira. Los bloques 1, 2, 3 y 4 están completados y commiteados; el bloque 5 está implementado y validado técnicamente, pendiente de revisión y commit. Los cuatro commits recientes permanecen en la rama local y todavía no se ha hecho push. No se autorizan cambios de esquema ni de los fixtures locales.
>
> **Referencia:** [PORT-3](https://miguelexd.atlassian.net/browse/PORT-3), dentro de PORT-1. La historia no contiene criterios de aceptación explícitos en Jira; los límites y requisitos aquí descritos fueron aprobados para este trabajo, sin atribuirlos al texto de Jira.

## Objetivo y límite

Sustituir exclusivamente la conexión `mysqli` y la implementación de ActiveRecord por PDO sin cambiar los contratos funcionales que consumen los controladores. Esto incluye preservar los tipos escalares legacy —cadenas y enteros en hidratación, comparaciones estrictas y JSON—; una conversión silenciosa es una regresión. `~/dev/php-template` es una referencia de diseño, no una fuente para copiar clases sin adaptación. PORT-3 no rediseña autenticación/autorización (PORT-4), frontend, pagos, correo ni la regresión integral reservada para PORT-9.

`BaseRepository`, `RepositoryInterface`, `Migration`, `Migrator`, `bin/console`, seeders y cambios de esquema quedan fuera del alcance actual de PORT-3. `Usuario` no se sustituye por `User` del template: su autenticación y autorización pertenecen a PORT-4. Los defectos legacy se caracterizan, pero no se corrigen incidentalmente; si alguno impide la equivalencia, se detiene el trabajo para decidir expresamente cómo proceder.

## Estado auditado

| Elemento | Situación actual |
| --- | --- |
| `config/database.php` | Crea una conexión `mysqli` con `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`. |
| `src/Core/App.php` | Carga `.env` de raíz o, si no existe, `includes/.env`; pasa `$db` a `ActiveRecord::setDB()`. No mezcla ambos archivos. |
| `src/Core/ActiveRecord.php` | Mantiene la conexión estática, compone SQL manualmente, hidrata objetos y proporciona CRUD, consultas y alertas. |
| Modelos | Nueve clases para ocho tablas; `EventoHorario` consulta también `eventos`. `Usuario` usa `usuarios_devwebcamp`. |
| Controladores | No acceden directamente a `mysqli` ni a `$db`; usan los modelos. No hay transacciones de aplicación. |
| Relaciones | Los controladores resuelven referencias con `find()` repetidos, incluso dentro de bucles. No hay JOIN; `eventos_registros` tiene claves foráneas pero no un modelo ni escrituras actuales. |

Las claves foráneas locales enlazan `eventos` con `categorias`, `dias`, `horas` y `ponentes`; `registros` con `paquetes` y `usuarios_devwebcamp`; y `eventos_registros` con `eventos` y `registros`. No se propone modificar estas tablas en el corte de conexión.

### API pública legacy que debe seguir funcionando

| API | Contrato relevante |
| --- | --- |
| `setDB()` | Instala la conexión compartida para todos los modelos. |
| `all('ASC'/'DESC')`, `ordenar()`, `paginar()`, `total()` | Orden, paginación y conteos usados por páginas, APIs y administración. |
| `find()`, `where()`, `whereArray()` | Los dos primeros devuelven un objeto o ninguno; `whereArray()` devuelve una lista. |
| `guardar()`, `crear()`, `actualizar()`, `eliminar()` | `guardar()` elige inserción o actualización; la inserción devuelve `['resultado' => ..., 'id' => ...]`; actualización y eliminación devuelven el resultado de la operación. |
| `sincronizar()`, `atributos()`, `sanitizarAtributos()`, `validar()`, `setAlerta()`, `getAlertas()` | Comportamiento de campos persistibles, carga de formularios y alertas que consumen modelos y controladores. |
| `consultarSQL()`, `get()` | Métodos públicos sin consumidores externos encontrados. `get()` genera SQL inválido (`LIMIT` antes de `ORDER BY`) y devuelve solo el primer resultado. No corregirlo ni eliminarlo silenciosamente. |

Los consumidores principales son `AuthController` (usuario), `RegistroController` (inscripción y boleto), `EventosController` y `PonentesController` (CRUD), `PaginasController` y las APIs (lecturas). `DashboardController`, `RegalosController` y `RegistradosController` no realizan consultas directas.

### Incompatibilidades y deuda detectadas

1. **Tipos de lectura:** una comprobación de solo lectura en la BD local mostró que `mysqli` devuelve `id`, `paquete_id`, `confirmado` y `admin` como cadenas, mientras PDO con consultas nativas los devuelve como enteros. `PDO::ATTR_STRINGIFY_FETCHES=true` devolvió cadenas en la misma prueba. Las comparaciones estrictas con `'1'` y `'3'` en páginas e inscripción y los tipos del JSON pueden cambiar si no se fija una política de compatibilidad.
2. **SQL dinámico:** PDO parametriza valores, no identificadores. Tabla, columna, orden, límite y desplazamiento deben quedar restringidos/validados antes de interpolarlos. Las consultas actuales interpolan valores y nombres directamente.
3. **Defectos legacy:** `crear()` introduce un espacio al comienzo del primer valor insertado; `get()` tiene el orden de cláusulas inválido; `whereArray([])` produciría una condición incompleta. Documentarlos y decidir explícitamente cualquier corrección, no mezclarla con la migración sin pruebas.
4. **Errores y nulos:** cambian las excepciones, los valores `NULL`, los resultados de escritura y potencialmente los códigos HTTP si se copia el tratamiento de fallos del template. La conexión debe conservar la precedencia de entorno actual y no mostrar secretos.
5. **PHP 8.2:** ActiveRecord emite deprecaciones por interpolación `${var}`. `Ponente` y `Registro` crean propiedades dinámicas en sus constructores y también emiten deprecaciones. Las relaciones añadidas dinámicamente por controladores requieren evaluación separada.
6. **Semántica de autenticación:** `Usuario` conserva tabla, nombres de campos, hash, confirmación, token, rol y sesiones actuales. Reemplazarlo por el `User` del template corresponde a PORT-4 y no debe ocurrir aquí.

## Qué adaptar de `php-template`

| Componente de referencia | Decisión aprobada para el alcance actual |
| --- | --- |
| `src/Core/Database.php` | Adaptar la conexión PDO, las opciones y el tratamiento de errores a las variables y al arranque actuales. No importar sus claves `DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` como requisito inmediato ni omitir silenciosamente la conexión local. |
| `src/Exceptions/DatabaseException.php` | Reutilizable como excepción propia, sin exponer credenciales ni cambiar el contrato HTTP sin análisis. |
| `src/Core/ActiveRecord.php` | Aprovechar consultas preparadas e hidratación, pero adaptar nombres, firmas, retornos, tipos de lectura, atributos y alertas al contrato legacy. No copiar literalmente. |
| `BaseRepository.php`, `RepositoryInterface.php` | Fuera del alcance actual de PORT-3; no incorporarlos para sustituir ActiveRecord por PDO. |
| `Migration.php`, `Migrator.php`, `bin/console`, seeders | Fuera del alcance actual de PORT-3, al igual que los cambios de esquema. La migración de ejemplo del template crea `users`, tabla incompatible con el esquema actual. No ejecutar DDL ni importar ejemplos. |
| `Models/User.php`, `Repositories/UserRepository.php` | No incorporar: su tabla `users`, columnas y contrato de autenticación no representan `usuarios_devwebcamp` y se solapan con PORT-4. |

## Bloques incrementales propuestos

Cada bloque debe pasar sus verificaciones y poder revisarse/commitearse de forma independiente. Los bloques 1 a 4 están completados y commiteados; el bloque 5 está implementado y validado, pero aún requiere revisión independiente antes del commit.

### Bloque 1: caracterización del comportamiento actual

- Incorporar pruebas de contrato de ActiveRecord, tipos de hidratación, consultas, retornos y JSON, sin modificar producción.
- Fijar evidencia HTTP de lecturas públicas, API y rutas administrativas representativas, más conteos/snapshot de fixtures sin exponer secretos.
- **Validación:** las pruebas describen el comportamiento actual. Revisar `git status --short` y los archivos sin seguimiento además de `git diff`, porque este último no muestra archivos untracked.

### Bloque 2: conexión PDO aislada

- Añadir `Database` y `DatabaseException` adaptadas a las claves existentes y a la selección `.env` ya implementada.
- Probar conexión, charset, errores y consulta de solo lectura; el bootstrap productivo sigue usando `mysqli`.
- **Validación:** arranque y HTTP legacy idénticos; conexión PDO verificada por prueba aislada; sin nuevas dependencias ni cambio en `composer.lock`.

### Bloque 3: compatibilidad de los modelos

- Declarar explícitamente campos que hoy se crean dinámicamente y comprobar construcción, hidratación, validación y serialización.
- Mantener `mysqli`; no cambiar tabla `usuarios_devwebcamp`, reglas de negocio ni relaciones manuales.
- **Validación:** pruebas del bloque 1 y smoke HTTP sin desviaciones. Las deprecaciones eliminadas deben identificarse frente a las que siguen siendo legacy.

### Bloque 4: cambio atómico de ActiveRecord y bootstrap

- Adaptar ActiveRecord a PDO y cambiar `App::boot()` en el mismo bloque; no dejar conexiones mixtas para un mismo flujo.
- Mantener la API utilizada, retornos y representación de escalares compatibles; usar parámetros para valores e identificadores permitidos para SQL dinámico.
- **Validación:** contrato de consultas, tipos, JSON, lectura de todas las tablas/modelos y HTTP representativo; detenerse ante cualquier cambio funcional no previsto.

### Bloque 5: escrituras controladas y cierre técnico

- Probar inserción, actualización, eliminación, `lastInsertId` y fallos en una **BD desechable** con datos sintéticos. Usar una misma conexión PDO para transacción y ActiveRecord; hacer rollback y comprobar filas/conteos.
- Repetir regresión HTTP acotada y comparar con el baseline. Mantener separados los defectos legacy y los cambios atribuibles a PORT-3.
- **Validación:** pruebas estáticas, pruebas de lectura/escritura y revisión del diff completo antes de solicitar cierre.

**Diferencias de driver aceptadas:** `get()` conserva su SQL inválido, pero pasa de `mysqli_sql_exception` a `PDOException`. Un fallo de conexión puede cambiar de clase de excepción y de cuerpo HTTP, sin exponer credenciales ni DSN. En escrituras reales bajo `mysqli` con modo estricto, los errores SQL podían lanzar `mysqli_sql_exception`; con `PDO::ERRMODE_EXCEPTION`, esos mismos errores lanzan `PDOException`. Esta diferencia de clase se acepta en PORT-3: la operación sigue fallando y no se exponen credenciales ni DSN. Cuando `execute()` o la operación no lanza una excepción, se conservan los retornos legacy aplicables. No se modifica código productivo para simular excepciones de `mysqli`.

## Estrategia de validación transversal

- **Estática:** `php -l` en PHP versionado, autoload/reflexión de clases, Composer validate, `composer.lock` y versiones bloqueadas sin actualizaciones por arrastre.
- **Lecturas:** comparar orden, páginas, conteos, resultados ausentes, claves/tipos de objetos y JSON de las APIs antes/después. Incluir usuario, inscripción, evento, `EventoHorario` y referencias del boleto.
- **Escrituras:** usar tablas y datos sintéticos en BD desechable; no enviar correos, realizar pagos ni ejecutar uploads. Una transacción revertida protege filas, pero en MySQL puede avanzar `AUTO_INCREMENT`; el DDL tampoco es rollback transaccional fiable.
- **Fixtures:** registrar conteos y huellas de datos no sensibles antes/después; no sustituir los dos usuarios y la inscripción local existentes. Para login positivo, utilizar credenciales sintéticas disponibles o preparar un fixture aislado con autorización específica.
- **HTTP:** comprobar `/`, páginas públicas, APIs, `/404` y ruta desconocida, boleto/conferencias con fixture, y lectura administrativa protegida cuando haya credenciales de desarrollo. Comparar códigos, redirecciones, layouts y contenido relevante; la regresión integral queda para PORT-9.

### Registro separado de deprecaciones PHP 8.2

Los runners funcionales del bloque 1 silencian `E_DEPRECATED` para comparar contratos sin ruido. Esto **no** equivale a dar por resueltas las deprecaciones. Ejecutar separadamente desde la raíz, sin modificar archivos ni base de datos:

```bash
php -d error_reporting=32767 -r '
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ($severity === E_DEPRECATED) {
        echo basename($file), ":", $line, " ", $message, PHP_EOL;
        return true;
    }
    return false;
});
require "vendor/autoload.php";
new App\Models\Ponente();
new App\Models\Registro();
'
```

Inventario legacy conocido: interpolación `${var}` en `src/Core/ActiveRecord.php`; creación de propiedades dinámicas en los constructores de `src/Models/Ponente.php` y `src/Models/Registro.php`. Conservar la salida de esta comprobación separada de los resultados funcionales y compararla en los bloques siguientes: anotar qué avisos desaparecen y cuáles permanecen. No corregir un aviso por sí solo fuera del alcance autorizado.

## Decisiones aprobadas y criterio de parada

1. Sustituir `mysqli`/ActiveRecord por PDO conservando API, resultados y tipos legacy, sin rediseñar la lógica de negocio.
2. Excluir repositorios, migraciones, CLI, seeders y cambios de esquema de PORT-3; mantener `Usuario` y reservar autenticación/autorización para PORT-4.
3. Caracterizar los defectos existentes sin corregirlos incidentalmente. Si una prueba demuestra que alguno impide la equivalencia, **detenerse y consultar** antes de modificar su comportamiento.

**Estado actual:** rama `PORT-3-migrar-modelos-y-persistencia-al-nuevo-activerecord-pdo`; Jira PORT-3 «En curso». Los bloques 1 a 4 están completados y commiteados (`813a19f`, `b8f7080`, `6d0d68b`, `de7bae2`); el bloque 5 está validado y pendiente de revisión/commit. Los cuatro commits recientes no se han enviado a origin. El bloque 1 no modificó código productivo, esquema ni fixtures. `.atl/` permanece fuera del staging y no se ha inspeccionado ni modificado.
