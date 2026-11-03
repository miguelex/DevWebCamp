# DevWebCamp

Aplicación web para la gestión de un evento tecnológico, desarrollada con PHP siguiendo una arquitectura MVC.

Este proyecto parte del proyecto final del curso **Desarrollo Web Completo** de Juan Pablo de la Torre y ha sido posteriormente revisado, migrado y modernizado como parte de mi portfolio personal.

El objetivo de esta evolución ha sido mantener la funcionalidad original mientras se actualizaba la base técnica, se añadían herramientas de calidad, pruebas automatizadas, integración continua y un despliegue real en hosting.

> **Estado actual:** aplicación desplegada y funcional en Hostinger.  
> El modo demostración seguro para acceso público desde el portfolio se encuentra todavía en desarrollo.

## Demo online

La aplicación está desplegada en:

https://devwebcamp.miguedelgado.dev/

Actualmente puede utilizarse para consultar la parte pública del proyecto y validar su funcionamiento real en hosting.

El acceso público al panel de administración se habilitará posteriormente mediante un **modo demostración restringido**, diseñado para evitar modificaciones permanentes o abusos sobre los datos.

## Capturas

Las capturas del proyecto se incorporarán cuando finalice la preparación visual para portfolio.

## Funcionalidades

DevWebCamp incluye, entre otras, las siguientes funcionalidades:

- Página pública del evento.
- Programa de workshops y conferencias.
- Información de ponentes.
- Registro de usuarios.
- Autenticación y gestión de sesiones.
- Panel de administración.
- Gestión CRUD de ponentes.
- Gestión de eventos.
- Gestión de registros y asistentes.
- Procesamiento y almacenamiento de imágenes.
- Generación de distintos tipos de entrada.
- Integración preparada para correo electrónico.
- Integración de pagos heredada del proyecto original.

Algunas integraciones externas, especialmente correo electrónico y pagos, no están habilitadas actualmente en el entorno online.

## Tecnologías

### Backend

- PHP 8.5
- Arquitectura MVC
- MySQL / MariaDB
- Composer
- PHPMailer
- PHP dotenv
- Intervention Image

### Frontend

- HTML
- Sass
- JavaScript
- Gulp
- Swiper
- SweetAlert2

### Calidad y testing

- PHPUnit 11
- PHPStan
- Lint de sintaxis PHP
- Tests unitarios
- Tests de integración
- Tests funcionales HTTP
- GitHub Actions

## Modernización del proyecto

La versión original del proyecto fue desarrollada con una base tecnológica anterior.

La versión actual del repositorio ha pasado por un proceso progresivo de modernización.

### PHP 8.5

La aplicación ha sido adaptada y validada sobre **PHP 8.5**, tanto en desarrollo como en integración continua y en el entorno real de hosting.

### Persistencia y arquitectura

Se ha revisado la capa de acceso a datos manteniendo el contrato funcional del proyecto original y mejorando su compatibilidad con versiones modernas de PHP.

La aplicación mantiene una estructura MVC con separación entre:

- controladores;
- modelos;
- vistas;
- servicios;
- configuración;
- lógica común de aplicación.

### Testing automatizado

El proyecto dispone de pruebas automatizadas para cubrir distintas capas de la aplicación.

```bash
composer test
```

Ejecuta la suite de tests unitarios.

```bash
composer test:integration
```

Ejecuta las pruebas de integración.

```bash
composer stan
```

Ejecuta el análisis estático con PHPStan.

```bash
composer lint:syntax
```

Comprueba la sintaxis PHP del proyecto.

### Integración continua

GitHub Actions ejecuta automáticamente controles de calidad sobre PHP 8.5:

- instalación reproducible de dependencias;
- validación de sintaxis;
- PHPStan;
- tests unitarios;
- tests de integración;
- instalación de dependencias frontend;
- compilación de assets;
- comprobación de que los assets generados están actualizados.

## Frontend

El frontend utiliza un pipeline basado en Gulp.

Para instalar las dependencias:

```bash
npm ci
```

Para generar los assets:

```bash
npm run build
```

Durante el desarrollo:

```bash
npm run dev
```

Los assets generados se almacenan bajo:

```text
public/build/
```

## Instalación local

### Requisitos

- PHP 8.5
- Composer
- MySQL o MariaDB
- Node.js y npm
- Extensiones PHP necesarias para la aplicación

### 1. Clonar el repositorio

```bash
git clone https://github.com/miguelex/DevWebCamp.git
cd DevWebCamp
```

### 2. Instalar dependencias PHP

```bash
composer install
```

### 3. Instalar dependencias frontend

```bash
npm ci
```

### 4. Configurar variables de entorno

Copiar `.env.example` como `.env` y configurar:

```dotenv
DB_HOST=
DB_USER=
DB_PASS=
DB_NAME=

EMAIL_HOST=
EMAIL_PORT=
EMAIL_USER=
EMAIL_PASS=

HOST=http://localhost:3000
```

El archivo `.env` no debe versionarse.

### 5. Compilar frontend

```bash
npm run build
```

## Despliegue en Hostinger

La aplicación se encuentra desplegada en **Hostinger** utilizando PHP 8.5.

Para mejorar la seguridad del despliegue se separa la aplicación privada de los archivos expuestos por el servidor web.

Conceptualmente:

```text
devwebcamp-app/
├── config/
├── includes/
├── src/
├── views/
├── vendor/
├── composer.json
├── composer.lock
└── .env

public_html/devwebcamp/
├── index.php
├── .htaccess
├── build/
└── img/
```

La aplicación utiliza dos raíces configurables:

```text
APP_ROOT
APP_PUBLIC_ROOT
```

De esta forma:

- el código PHP privado queda fuera del document root;
- `.env` no es accesible públicamente;
- los assets y uploads permanecen dentro de la raíz pública;
- la misma aplicación puede funcionar tanto en desarrollo local como en hosting compartido.

Las dependencias de producción se instalan con:

```bash
composer install --no-dev --optimize-autoloader
```

## Datos del entorno online

El entorno desplegado utiliza una base de datos independiente preparada específicamente para el proyecto publicado.

Los datos utilizados se mantienen separados de cualquier entorno previo y conservan la coherencia funcional de la aplicación.

Se mantienen las relaciones necesarias entre:

- eventos;
- categorías;
- días;
- horarios;
- ponentes;
- paquetes;
- usuarios;
- registros.

Las credenciales, dumps y otros artefactos privados de despliegue no forman parte del repositorio.

## Estado del proyecto

La migración técnica principal está completada.

Actualmente están finalizadas:

- migración de estructura base;
- adaptación de persistencia;
- autenticación y autorización;
- herramientas de calidad;
- pruebas automatizadas;
- modernización del frontend;
- GitHub Actions;
- validación funcional;
- migración a PHP 8.5;
- despliegue y validación en Hostinger.

### Trabajo pendiente

Antes de publicar DevWebCamp como demo abierta desde el portfolio quedan principalmente:

- implementación del modo demostración;
- definición de usuarios demo;
- restricciones de permisos;
- datos iniciales controlados;
- protección contra modificaciones no deseadas;
- validación de seguridad del entorno demo;
- integración final con el portfolio.

También se mantienen algunas deudas técnicas heredadas que se tratarán de forma independiente.

## Decisiones de seguridad

El entorno público no contiene credenciales en el repositorio.

Se mantienen fuera de Git:

- `.env`;
- credenciales de base de datos;
- credenciales SMTP;
- dumps de datos;
- datos privados de usuarios;
- archivos auxiliares de despliegue.

El modo demo se diseñará específicamente para impedir que un visitante pueda modificar de forma permanente información sensible o llenar la base de datos con contenido no deseado.

## Autoría

Proyecto original basado en el curso **Desarrollo Web Completo** de Juan Pablo de la Torre.

Modernización, migración a PHP 8.5, testing, integración continua, adaptación para hosting y evolución para portfolio:

**Miguel Ángel Delgado**

GitHub: https://github.com/miguelex

## Licencia

Este repositorio se mantiene principalmente con fines educativos y como proyecto de portfolio.

Consulta las condiciones y licencias de las dependencias utilizadas antes de reutilizar el proyecto en otros contextos.
