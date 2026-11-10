# Sitio de demostración

Es **la misma aplicación** instalada en **otro subdominio**, con **su propia base de datos y su propio `.env`**, llena de datos de ejemplo que cubren todas las funciones de todos los roles. Sirve para enseñársela a un cliente (por ejemplo AMSI) sin tocar producción.

- Dirección: `https://timesheet-demo.cerevelo.com`
- Se activa con `DEMO_MODE=true` en el `.env` de esa instalación y se llena con `php artisan tiempo:demo`.
- Nunca pongas `DEMO_MODE=true` en el `.env` de producción. El comando `tiempo:demo` se niega a correr si no está activo, para no tocar producción por error.

## Qué cambia en modo demo
Con `DEMO_MODE=false` (lo normal) todo funciona exactamente como siempre.

| En modo demo | |
|---|---|
| Códigos de 6 dígitos | No se piden: ni al entrar (aprobadores, dueño y oficina) ni al decidir una hoja (cuentas de aprobador y enlaces del correo). Todo lo demás del flujo es igual: contraseña para firmar, etapas internas y externas, devoluciones, etc. |
| Franja | «Demo · datos de ejemplo» arriba de la web y de las páginas de aprobación, y la cabecera `X-Robots-Tag: noindex` en lo que responde PHP. |
| Pantalla de entrada | Sin botones de acceso rápido: cada persona entra con su correo y la contraseña que se le dio; solo hay que pulsar Continuar. |
| Contraseñas | No se pueden cambiar ni resetear las de las cuentas de ejemplo. |
| Correo | No sale ningún correo real (se fuerza `MAIL_MAILER=log`; la siembra no envía nada). |

## Montarla en GoDaddy (cPanel), paso a paso
1. **Subdominio.** En cPanel → *Domains* (o *Subdomains*) crea `timesheet-demo.cerevelo.com`. Anota su carpeta (*Document Root*), por ejemplo `public_html/timesheet-demo`.
2. **Base de datos nueva.** En *MySQL Databases* crea una base nueva, un usuario nuevo y agrégalo con **ALL PRIVILEGES**. **No reutilices la de producción**: la demo borra y rehace sus datos cada noche.
3. **PHP.** *Select PHP Version* para el subdominio: PHP 8.3 o más con las mismas extensiones que producción (ver `installer/LEEME.md`).
4. **Instalar.** Genera los paquetes (`python installer/build.py`) y sube `instalar.php` y `timesheet-app.zip` al *Document Root* del subdominio. Abre `https://timesheet-demo.cerevelo.com/instalar.php` y sigue el asistente con los datos de la base **nueva**. Los datos de compañía y dueño que pide el asistente solo sirven para terminar la instalación: la demo trae su propia compañía y sus propias cuentas. Deja el correo vacío.
5. **El `.env` de la demo.** En el *File Manager* edita el `.env` de la carpeta de la aplicación (la que el asistente creó fuera de la carpeta pública) y deja estos valores. **No pongas ninguna clave de correo ni la de Brevo.**
   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://timesheet-demo.cerevelo.com
   APP_LOCALE=es
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_DATABASE=<la base nueva>
   DB_USERNAME=<el usuario nuevo>
   DB_PASSWORD=<su contraseña>
   DEMO_MODE=true
   DEMO_PASSWORD=<la contraseña que quieras dar a los tres>
   MAIL_MAILER=log
   ```
6. **Cron temporal, una sola vez** (*Cron Jobs* → hora y minuto a unos 2 minutos de ahora, y bórralo después). Aplica la base, siembra los datos y renueva las cachés; tarda unos 15 a 30 segundos:
   ```
   (cd /home/USUARIO/timesheet-demo && php artisan config:clear && php artisan migrate --force && php artisan tiempo:demo --fresh && php artisan config:cache && php artisan route:cache && php artisan view:cache) > /home/USUARIO/demo-resultado.txt 2>&1
   ```
   (cambia `USUARIO` y la carpeta por las tuyas). Abre `demo-resultado.txt` para ver los conteos de lo sembrado y los avisos, si hubo; bórralo al terminar.
7. **Cron nocturno** para mantener la demo fresca (las fechas son relativas a hoy: siempre las últimas 8 semanas más la actual). Por ejemplo a las 4:00 a. m.:
   ```
   0 4 * * * cd /home/USUARIO/timesheet-demo && php artisan tiempo:demo --fresh > /dev/null 2>&1
   ```
   Y la línea de cada minuto que dio el asistente (recordatorios y avisos).
8. **Que no la indexen.** La web estática no pasa por PHP: agrega esta línea al `.htaccess` de la carpeta pública de la demo y un `robots.txt` con `User-agent: *` y `Disallow: /`:
   ```
   Header set X-Robots-Tag "noindex, nofollow"
   ```
9. **Entra** a `https://timesheet-demo.cerevelo.com/app/` y entra con uno de los tres correos y la contraseña de la demo.

## Cuentas de ejemplo
Son tres, y **las tres entran con la misma contraseña**. No hay botones «Entrar como …»: cada persona escribe su correo y la contraseña que se le dé. La contraseña es la de `DEMO_PASSWORD` en el `.env` de la demo (si no se pone ninguna, la de ejemplo `Demo-Timesheet#2026`). Después de sembrar, `tiempo:demo` escribe en su salida los tres correos y la contraseña.

| Persona | Cuenta |
|---|---|
| Dueño (aprueba por dentro) | `lcasado@cerevelo.com` (Luis Casado) |
| Consultor | `jrodriguez@cerevelo.com` (Jarvis Rodriguez, $15/h, retención 10 %) |
| Aprobadora del cliente y gerente de proyecto | `kfigueroa@amsipr.com` (Ketsy Figueroa: aprueba y ve «Avance») |

## Qué datos trae
- Un cliente (AMSI) y un proyecto, **AMSI PITS Upgrade**, con cinco servicios (General, Testing, Design, Development, Implementation) a $125 por total de horas del día, con **aprobación interna (Luis) y del cliente (Ketsy)**, y cuatro tareas con presupuesto y fecha estimada.
- Horas inventadas de Jarvis: **el mes anterior completo, todo aprobado con las dos aprobaciones**; **la semana pasada, enviada y sin aprobar**; **esta semana, nada**. Las fechas son relativas a hoy, así que cada vez que se siembra se mueven.
- Facturas de Jarvis (una pagada y otra por pagar), y suficiente historial para Avance, Reportes y los exportes (CSV, Excel, PDF).

Todo se siembra con los servicios reales de la aplicación (entradas, envío, aprobaciones, firmas, comisiones y facturas) mientras el reloj avanza semana a semana, así que las reglas, las firmas y los totales son los de verdad. Lo único que se escribe a mano es el estado «pagada» de las facturas (no hay pantalla que lo marque).

## El comando
```
php artisan tiempo:demo [--fresh] [--brand=cerevelo|amsi]
```
- Solo corre con `DEMO_MODE=true`; si no, termina con un error y no toca nada.
- `--fresh` borra **solo la compañía demo** y todo lo suyo, y la vuelve a crear. Sin `--fresh` no duplica nada (solo actualiza la marca).
- `--brand=amsi` cambia nombre, colores y logo de la compañía de ejemplo (por defecto `cerevelo`).
- Las fechas son relativas a hoy en la zona de la compañía (America/Puerto_Rico); la semana va de domingo a sábado.

## Actualizar la demo a una versión nueva
Igual que producción (ver `installer/LEEME.md`): sube el zip nuevo, extrae `app` y `public` encima y corre un cron de una sola vez con `php artisan migrate --force && php artisan tiempo:demo --fresh && php artisan config:cache && php artisan route:cache && php artisan view:cache`.
