# Instalar Cerevelo Timesheet en un hosting (GoDaddy, cPanel o similar)

Necesitas dos archivos, que están en `dist/hosting/`:
- `instalar.php`
- `timesheet-app.zip`

## Antes de empezar (en cPanel)
1. **PHP 8.3 o más** (Select PHP Version). Activa las extensiones: `mbstring`, `intl`, `gd`, `zip`, `curl`, `fileinfo`, `openssl`, `pdo_mysql` (en CloudLinux se llama `nd_pdo_mysql`), `bcmath`, `exif`, `xml`.
2. **Base de datos vacía** (MySQL Databases): crea la base, crea el usuario y agrégalo a la base con **ALL PRIVILEGES**. Apunta los tres nombres EXACTAMENTE como los muestra cPanel (algunos hostings les ponen un prefijo, otros no).

## Dónde subirlos
Sube los dos archivos con el File Manager a UNA de estas carpetas:
- **Con subdominio propio** (por ejemplo `timesheet.tudominio.com`): la carpeta que sirve ese subdominio (su *Document Root*). Se entra por `https://timesheet.tudominio.com/app/`.
- **Sin subdominio**: una carpeta dentro de `public_html`, por ejemplo `public_html/timesheet`. Se entra por `https://tudominio.com/timesheet/app/`.

## Instalar
1. Abre `https://…/instalar.php` (en la misma carpeta donde lo subiste).
2. Sigue los 6 pasos: requisitos, ubicación, base de datos (prueba la conexión), compañía y dueño, correo (opcional) e instalar.
3. Al terminar el asistente se borra solo y te da el enlace para entrar y **una línea de cron**.
4. En cPanel → **Cron Jobs** crea una tarea *Once Per Minute* con esa línea (envía recordatorios y avisos).

## Seguridad
- La aplicación (código y claves) se instala en una carpeta **fuera** de la carpeta pública. El asistente no deja instalarla dentro.
- Si el asistente no pudo borrarse solo, bórralo a mano.

## Para actualizar a una versión nueva
Genera un `timesheet-app.zip` nuevo (`python installer/build.py`), súbelo y extrae encima de la carpeta de la aplicación. No se toca el `.env`.
