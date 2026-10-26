# Instalación en cPanel (GoDaddy): timesheet.cerevelo.com

Supuestos (ya hechos en cPanel): el subdominio `timesheet.cerevelo.com` tiene como Document Root `timesheet/server/public`; PHP 8.4 con sus extensiones; la base y el usuario `rfj1sbmpkfe3_timesheet`; y el registro DNS A `timesheet` → `173.201.180.75`.
En todos los comandos, `rfj1sbmpkfe3` es tu usuario de cPanel.

## 1. Subir el zip
1. cPanel → **File Manager**. Entra a tu carpeta personal (`/home/rfj1sbmpkfe3`), **no** a `public_html`.
2. **Upload** → sube `timesheet-cpanel.zip`.
3. Clic derecho sobre el zip → **Extract**, destino `/home/rfj1sbmpkfe3/`. Debe quedar `/home/rfj1sbmpkfe3/timesheet/server/`.
4. En el File Manager, **Settings** (arriba a la derecha) → marca **Show Hidden Files (dotfiles)** → **Save**. Así se ve el `.env`.

## 2. La contraseña de la base
1. Abre `timesheet/server/.env` → **Edit**.
2. Cambia `PUT_THE_DATABASE_PASSWORD_HERE` por la contraseña de la base de datos. **Save Changes**.

## 3. Crear las tablas y la compañía (una sola vez, con Cron Jobs)
Como no hay terminal, se usa una tarea de cron que se borra enseguida.

1. cPanel → **Cron Jobs**. En **Add New Cron Job**, **Common Settings** = `Once Per Minute (* * * * *)`.
2. En **Command** pega esto, en UNA sola línea. Cambia `tucorreo@cerevelo.com` y `UnaClaveLarga123` (12 caracteres o más; luego la cambias dentro de la aplicación):

```
cd /home/rfj1sbmpkfe3/timesheet/server && /opt/alt/php84/usr/bin/php artisan migrate --force && /opt/alt/php84/usr/bin/php artisan storage:link ; /opt/alt/php84/usr/bin/php artisan tiempo:instalar "Cerevelo" tucorreo@cerevelo.com --nombre="Luis" --clave="UnaClaveLarga123" ; /opt/alt/php84/usr/bin/php artisan config:cache ; /opt/alt/php84/usr/bin/php artisan route:cache ; /opt/alt/php84/usr/bin/php artisan view:cache >> /home/rfj1sbmpkfe3/timesheet/install-result.txt 2>&1
```
3. **Add New Cron Job**. Espera 2 minutos.
4. En el File Manager abre `/home/rfj1sbmpkfe3/timesheet/install-result.txt`: debe mostrar las migraciones ("DONE") y la compañía creada. Si dice `No such file` en la ruta de PHP, cámbiala por `/usr/local/bin/php` y repite.
5. **Borra la tarea de cron** (sección **Current Cron Jobs → Delete**). Si la dejas, repite todo cada minuto.
6. Borra también `install-result.txt`.

## 4. El programador de tareas (recordatorios y avisos)
En **Cron Jobs**, **Common Settings** = `Once Per Minute`, y en **Command**:
```
cd /home/rfj1sbmpkfe3/timesheet/server && /opt/alt/php84/usr/bin/php artisan schedule:run >/dev/null 2>&1
```
Esta sí se queda.

## 5. HTTPS
cPanel → **SSL/TLS Status** → marca `timesheet.cerevelo.com` → **Run AutoSSL**. Funciona cuando el DNS ya apunta a `173.201.180.75`. Verifica en https://dnschecker.org (tipo A).

## 6. Entrar
`https://timesheet.cerevelo.com/app/` con el correo y la clave del paso 3. Cámbiala en **Settings → My account**.
Luego: **Settings → Rules** (Domain = `timesheet.cerevelo.com`), **Settings → Branding** (colores `#04297C` y `#087A85`, logo), **People**, **Clients**, **Projects**.

## 7. Correo y Turnstile
- **Brevo:** Seguridad → Direcciones IP autorizadas → agrega la IP **de salida** del servidor. Suele ser `173.201.180.75`; si Brevo rechaza un correo, el rechazo indica la IP exacta.
- **Turnstile:** agrega `timesheet.cerevelo.com` a la lista de nombres del widget.

## 8. Respaldos
- cPanel → **Backup** → descarga la copia de la base (**Download a MySQL Database Backup**) y de `timesheet`. Hazlo cada semana.
- Guarda aparte `timesheet/server/.env`, sobre todo `APP_KEY`: sin ella no se descifra la contraseña de Accountisi.

## 9. Actualizar a una versión nueva
Sube el zip nuevo, extrae encima (conserva el `.env`), y crea una tarea de cron de una vez con `migrate --force`, `config:cache`, `route:cache` y `view:cache` como arriba.

## Si algo falla
- **Página en blanco o error 500:** mira `timesheet/server/storage/logs/laravel.log`. Casi siempre falta una extensión de PHP o la contraseña de la base está mal.
- **403 o se ve el código PHP:** el Document Root no es `timesheet/server/public`.
- **No llegan correos:** IP no autorizada en Brevo o remitente sin verificar.
- **El login no deja pasar:** el dominio falta en Turnstile.
