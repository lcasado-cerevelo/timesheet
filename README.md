# Tiempo — registro de horas con aprobación

Aplicación de registro de tiempo para consultores y empleados, con aprobación por email, marca configurable,
exportación a Excel (incluida la plantilla de Consultorio 1500) e integración opcional con Accountisi.

| Carpeta | Qué es |
|---|---|
| `server/` | Servidor y API: Laravel 13, PHP 8.4, MariaDB/MySQL. Incluye la web (`/app`) y las páginas de aprobación sin cuenta. |
| `mobile/` | Aplicación móvil Android e iPhone: React Native (Expo 52, expo-router). Funciona sin conexión. |
| `tools/` | PHP 8.4, Composer y MariaDB 11.4 portátiles para desarrollar sin instalar nada en Windows. |
| `diseno.md`, `analisis-preliminar.md` | Decisiones de diseño. |

## Probar en esta máquina

```bash
export PATH="$PWD/tools/php:$PATH"
cd server
php artisan migrate:fresh
php artisan tiempo:demo          # compañía de ejemplo (Cerevelo) con personas y proyectos
php artisan storage:link
php artisan serve                # http://127.0.0.1:8000/app/
```

Usuarios de ejemplo (clave `demo-password-123`): `luis@demo.test` (dueño), `ana@demo.test` (consultora, desde/hasta),
`marcos@demo.test` (empleado, día completo). En desarrollo los emails se escriben en `server/storage/logs/laravel.log`
(`MAIL_MAILER=log`): ahí está el enlace de aprobación.

Crear una compañía real: `php artisan tiempo:instalar "Mi Compañía" dueno@correo.com --nombre="Luis"`.

### Pruebas

```bash
cd server && php artisan test        # 48 pruebas; también pasan contra MariaDB
cd mobile && npx jest && npx tsc --noEmit
```

Contra MariaDB (el motor del hosting barato): `tools/mariadb-x/.../bin/mariadbd.exe --datadir=tools/mariadb-data --port=3307`
y `DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3307 DB_DATABASE=tiempo_test DB_USERNAME=root php artisan test`.

## Con Docker (entorno igual para todos)

Alternativa a las herramientas de `tools/`: un entorno parecido a un hosting Linux, con la aplicación (PHP 8.4 + Apache), MariaDB 11.4,
el programador de tareas y un buzón de correo de prueba. El código se edita en Windows y se ve al instante dentro del contenedor.

```bash
docker compose up -d --build                         # primera vez: baja las imágenes y construye
docker compose exec -u www-data app php artisan tiempo:demo      # datos de ejemplo (clave demo-password-123)
docker compose exec -u www-data -e DB_DATABASE=tiempo_test app php artisan test    # las pruebas, siempre con la base tiempo_test
docker compose down                                  # apaga (la base se conserva); con -v también la borra
```

| Qué | Dónde |
|---|---|
| Web y API | http://localhost:8180/app/ |
| Correos de prueba (aprobaciones, facturas) | http://localhost:8125 |
| MariaDB desde esta máquina | `127.0.0.1:3380`, base `tiempo`, usuario `tiempo`, clave `tiempo_dev` |

Los puertos son poco comunes a propósito para no chocar con otros contenedores. Los contenedores se llaman `tiempo-app`, `tiempo-scheduler`,
`tiempo-db` y `tiempo-mail`; no tocan ningún otro contenedor ni volumen. Para crear una compañía real:
`docker compose exec -u www-data app php artisan tiempo:instalar "Mi Compañía" dueno@correo.com`.

Dos cuidados: (1) usa siempre `-u www-data` en `exec`; como `root` los archivos de `storage` quedarían ilegibles para Apache. (2) Las pruebas borran las tablas de la base que usan, por eso se niegan a correr si la base no es de pruebas (`tiempo_test` o SQLite en memoria); la base `tiempo` de desarrollo no se toca. Las pruebas pasan igual en Windows (`tools/`) y en este contenedor (Linux, PHP 8.4, MariaDB 11.4).

Si Docker Desktop no arranca en Windows y su registro dice `The file cannot be accessed by the system` sobre un archivo `.sock`
(en `%LOCALAPPDATA%\Docker\run` o `%LOCALAPPDATA%\docker-secrets-engine`), son sockets viejos que Windows no deja borrar; se borran desde
WSL con `rm -f` (solo esos archivos) y Docker los recrea solo.

## Producción en un servidor con Docker (por ejemplo AWS EC2)

Una sola máquina pequeña con Docker. `docker-compose.prod.yml` levanta: **Caddy** (HTTPS con Let's Encrypt, gratis y automático),
la aplicación (imagen de producción: código adentro, sin depuración), el programador de tareas y MariaDB. Solo Caddy abre puertos
(80 y 443); la aplicación y la base no son alcanzables desde internet.

```bash
cp .env.prod.example .env.prod          # rellena los valores marcados CAMBIAR (dominio, claves, Brevo...)
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
docker compose -f docker-compose.prod.yml --env-file .env.prod exec app php artisan tiempo:instalar "Mi Compañía" dueno@correo.com
```

**HTTPS (Let's Encrypt).** En Cloudflare: registro `A` del dominio hacia la IP del servidor con la **nube gris** (solo DNS), para que la
verificación de Let's Encrypt llegue directo al servidor. Abre los puertos 80 y 443 en el grupo de seguridad de AWS. Los certificados
quedan en el volumen `caddy_data`: no lo borres (Let's Encrypt limita cuántos se piden por semana). Con la nube naranja (proxy de
Cloudflare) la verificación se complica; si algún día lo quieres, se usa un certificado de origen de Cloudflare en vez de Let's Encrypt.
La aplicación confía en el encabezado `X-Forwarded-Proto` de Caddy (`TRUSTED_PROXIES=*`, seguro porque solo Caddy la alcanza) y
fuerza `https` en todos los enlaces cuando `APP_URL` empieza con `https://`.

**Correo con Brevo.** En Brevo, SMTP y API → SMTP: copia el servidor (`smtp-relay.brevo.com`), el usuario y la clave SMTP a `.env.prod`.
El remitente (`MAIL_FROM_ADDRESS`) debe estar verificado en Brevo y el dominio autenticado (SPF y DKIM) para que los emails de
aprobación lleguen a la bandeja de entrada. Para comprobarlo:
`docker compose -f docker-compose.prod.yml --env-file .env.prod exec app php artisan tiempo:probar-correo tu@correo.com`.

**Login.** Cinco intentos fallidos con un mismo correo desde una misma IP bloquean ese par por 15 minutos, y además cada IP tiene un
límite de peticiones por minuto. En la web se puede añadir el desafío anti-bots de **Cloudflare Turnstile** (gratis, funciona con la
nube gris): crea el widget en el panel de Cloudflare → Turnstile y pon `TURNSTILE_SITE_KEY` y `TURNSTILE_SECRET` en `.env.prod`. La app
móvil no puede mostrar ese desafío; su login se protege con el límite por IP y el bloqueo por cuenta. Con la nube gris, las reglas de
límite de Cloudflare no se aplican (necesitan el proxy); el límite lo hace la propia aplicación.

**Programador de tareas.** El contenedor `scheduler` corre `schedule:work`. Si prefieres no depender del contenedor (o en un hosting),
pon `CRON_KEY` (32+ caracteres) y haz un `POST https://TU-DOMINIO/api/cron/run` cada minuto con el encabezado `X-Cron-Key: <la clave>`
desde cualquier cosa que pueda hacerlo (cron, AWS EventBridge, el Hangfire de Accountisi). Envía los avisos de "la hoja cambió" en cada
llamada y los recordatorios una sola vez al día después de las 8:00 (hora de Puerto Rico), aunque lo llamen varios sitios a la vez.

**Respaldo y restauración.** `./scripts/backup.sh` guarda la base (MariaDB) y los archivos (logos y PDF de facturas) en `backups/`, y
comprueba que no estén dañados. `./scripts/restore.sh <db.sql.gz> <archivos.tar.gz>` los restaura (pide escribir RESTAURAR). Para
mudarte a otro servidor: copia el `.env.prod` con la **misma `APP_KEY`**, levanta el compose, corre `restore.sh`. Sin la misma `APP_KEY`,
lo cifrado (la contraseña de Accountisi) no se puede leer y hay que escribirlo de nuevo en Integración. Guarda los respaldos y la
`APP_KEY` fuera del servidor (por ejemplo en S3). Actualizar a una versión nueva: `git pull` y el mismo `up -d --build`; la base y los archivos
están en volúmenes y se conservan.

## Poner en producción (GoDaddy u otro hosting con PHP 8.3+)

1. Sube `server/` (sin `tests/`) y ejecuta `composer install --no-dev --optimize-autoloader`
   (si el plan no tiene SSH: corre ese comando en tu máquina y sube también `vendor/`).
2. El dominio debe apuntar a la carpeta `server/public`. Si el hosting solo deja usar `public_html`, copia ahí el contenido de
   `public/` y en su `index.php` cambia las rutas `__DIR__.'/../...'` para que apunten a donde subiste el resto.
3. Copia `.env.production.example` a `.env`, rellena base de datos y correo, y corre:
   ```bash
   php artisan key:generate --force
   php artisan migrate --force
   php artisan storage:link
   php artisan config:cache && php artisan route:cache
   php artisan tiempo:instalar "Tu Compañía" tu@correo.com --nombre="Tu Nombre"
   ```
4. **Cron** (cada minuto): `* * * * * php /ruta/server/artisan schedule:run >> /dev/null 2>&1`.
   Envía los avisos de "la hoja cambió" (tras la espera de 10 minutos) y los recordatorios de hojas sin decidir (8:00 a. m.).
5. **HTTPS obligatorio** (los enlaces de aprobación y el token de la app viajan por ahí).
6. Correo con un servicio de envío (SES, Postmark, Brevo) y SPF/DKIM del dominio del remitente.
7. Permisos: `storage/` y `bootstrap/cache/` escribibles por el servidor web; nada de `.env` dentro de la carpeta pública.
8. Opcional: en `config/cors.php` limita `allowed_origins` al dominio de la web si sirves la app web móvil desde otro sitio
   (la app nativa no usa CORS).

## Marca, reglas y personas (todo desde la web, sin tocar código)

- **Marca:** Configuración → Marca (nombre, logo, colores, remitente, pie). Se aplica a web, emails, PDF y app móvil.
- **Reglas:** semanas hacia atrás, fecha límite, incremento, máximo por día, recordatorio a los 7 días, vigencia de enlaces,
  espera antes de avisar un cambio (10 min), horas sobre las cuales se pide código.
- **Personas:** el dueño fija por usuario si se permite el día completo o se exige desde/hasta; consultores llevan tarifa,
  retención e IVU; empleados llevan horas por semana (por omisión 40; solo se avisa si se pasan).
- **Proyectos:** solo los administra el dueño. Para Consultorio 1500, cada paciente es un proyecto con los campos
  `plan_medico` y `numero_contrato`.

## Exportar tablas (solo en la web)

Cada tabla de la web (mi semana, reportes, aprobaciones pendientes, facturas, proyectos, personas, aprobadores y clientes) tiene
botones **CSV**, **Excel** y **PDF**. Excel y PDF salen con el **logo y los colores de la compañía** (los de Configuración → Marca);
el CSV lleva solo los datos. Se generan en el servidor con los permisos de quien pide: un miembro solo exporta lo suyo y las
tablas de administración son del dueño. API: `GET|POST /api/tables/{tabla}/export?format=csv|xlsx|pdf`.

**Se exporta lo filtrado.** Cada tabla tiene su barra de filtros (buscar, estado, cliente, tipo, fechas, etc.; "Limpiar filtros" los quita) y los botones de exportar llevan esos mismos filtros: el archivo trae solo las filas que se ven, con los totales recalculados, y en el encabezado del Excel y del PDF queda escrito el filtro aplicado. La búsqueda ignora mayúsculas y tildes. La app móvil no tiene
estos botones; allí se exporta con las plantillas (por ejemplo Consultorio 1500).

## Hoja de tiempo para el cliente

Web → **Hoja para cliente** (solo el dueño). Eliges cliente y período (y, si quieres, proyectos o una persona) y ves la hoja:
fecha, persona, horario, horas y la **descripción** de lo trabajado, con subtotales por proyecto y total. Se descarga en **PDF**
(con tu logo y colores) o en **Excel**, y se puede **enviar por email** al cliente con un mensaje. Por omisión sale solo lo
aprobado y facturable, sin tarifas; hay casillas para incluir horas sin aprobar (salen marcadas), no facturables e importes.
Ver o descargar no envía nada: el email sale únicamente cuando pulsas "Enviar la hoja por email" y confirmas.
API: `POST /api/client-timesheet/{preview|download|email}`. Es el equivalente de la lista que Dash imprime o exporta.

## Aprobación

El email lleva el resumen (sin tarifas) y un botón que abre una página con la hoja completa; abrirla no aprueba nada.
Enlaces de un solo uso, ligados a la versión de la hoja, con vencimiento. Si la hoja cambia antes de decidirse, el enlace
anterior deja de servir y sale un email nuevo ("Esta hoja cambió"). A los 7 días llega un recordatorio con una pantalla de
todas las pendientes (pide un código de 6 dígitos al mismo correo). Una hoja aprobada no se puede cambiar.

## Integración con Accountisi

Configuración → Integración. La URL es configurable (localhost hoy, el servidor de Amazon mañana). Dos modos:

- **Usuario de servicio** (funciona hoy): crea en Accountisi un usuario sin MFA con permisos de facturas y cuentas por pagar.
- **API key** (a futuro): Accountisi todavía no la tiene; el cliente ya la envía como `Authorization: Bearer`.

Crea facturas de venta (proyecto en el comentario; nueva o añadida a una abierta) y la cuenta por pagar de la factura del
consultor (en borrador). Probado el 2026-10-24 contra la API real de Accountisi con la base `AccountisiTest` (nunca contra
Cerevelo): conexión, clientes, suplidores, factura de venta y cuenta por pagar. El usuario de servicio necesita permisos de
crear facturas y cuentas por pagar. Ojo: en la cuenta por pagar, Accountisi calcula el impuesto según el artículo usado y la
retención según el suplidor al postear; los montos de IVU y retención del PDF del consultor pueden no coincidir con los de
Accountisi hasta que el contador defina cuál manda.

## App móvil

```bash
cd mobile
npm install --legacy-peer-deps
npx expo start                       # Expo Go sirve para lo básico
eas build --profile development --platform android   # para reloj fijo, geocercas y notificaciones con botones
```

En la pantalla de entrada se escribe la URL del servidor. La marca (logo y colores) llega del servidor al iniciar sesión.
Una sola aplicación sirve a todas las compañías.

## Pendiente / límites conocidos

- Notificación del reloj con botón "Parar" y avisos al llegar a un lugar: escritos con `expo-notifications` y
  `expo-location`, **sin probar en un teléfono** (la web de prueba no los ejecuta). Requieren compilación nativa (EAS).
- iPhone: la Live Activity en pantalla de bloqueo necesita un módulo nativo; no está hecha. El reloj sí funciona (hora de inicio guardada).
- Exportar sin conexión desde el móvil no está; el archivo se genera en el servidor.
- Apple Developer (99 USD/año) hace falta para publicar en iPhone.
- Integración Accountisi: añadir la credencial de servicio (API key) a Accountisi y definir qué impuesto y retención manda en las cuentas por pagar.
