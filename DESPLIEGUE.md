# Despliegue en un Windows (con el instalador)

Si el servidor es un Windows (Windows 10/11 o Windows Server 2022), usa el instalador y no tienes que hacer nada a mano. Copia al servidor **dos archivos**
que están en `dist\`: `instalar-tiempo.ps1` y `tiempo-produccion.zip` (en la misma carpeta). Abre PowerShell **como administrador** en esa carpeta y corre:

```powershell
powershell -ExecutionPolicy Bypass -File .\instalar-tiempo.ps1 -CorreoDueno tucorreo@cerevelo.com
```

Qué hace, en orden: revisa el servidor (versión de Windows, memoria, disco, virtualización); revisa que los puertos 80 y 443 estén libres; abre el Firewall de
Windows; activa WSL2 (si pide reiniciar, reinicia y vuelve a ejecutar el mismo comando); instala Docker Desktop; desempaqueta el zip en `C:\Tiempo`;
construye y levanta la aplicación; espera el certificado HTTPS; crea la compañía y el dueño (imprime la contraseña una sola vez, y la pide copiar antes de seguir);
y programa un respaldo diario a las 3:00 a. m. en `C:\Tiempo	imesheetackups`.

- **Solo revisar, sin cambiar nada:** agrega `-SoloRevisar`.
- **IIS:** si IIS ocupa el puerto 80/443, el instalador lista los sitios que sirve y **no lo detiene** por su cuenta. Si no los necesitas, vuelve a correr con `-DetenerIIS`.
- **Después de reiniciar el servidor:** Docker Desktop arranca al iniciar sesión, así que entra una vez por Escritorio remoto para que todo vuelva a subir.
- **Respaldo a mano:** `powershell -ExecutionPolicy Bypass -File scriptsackup.ps1`. Copia la carpeta `backups` a otro lugar y guarda aparte `.env.prod`.
- **Si Docker no arranca:** casi siempre es la virtualización anidada (muchas instancias de AWS no la permiten) o Windows Server. Una instancia Linux pequeña es la alternativa más simple (abajo).

---

# Despliegue en AWS: timesheet.cerevelo.com

Una sola máquina con Docker. `docker-compose.prod.yml` levanta Caddy (HTTPS con Let's Encrypt), la aplicación, el programador de tareas y MariaDB.
**Lo único que subes al servidor es un archivo: `dist/tiempo-produccion.zip`** (en esta carpeta, en tu computadora). Trae el código, la configuración de Docker
y `.env.prod`, que es el archivo de claves (dominio, clave de la aplicación, claves de la base de datos, Brevo y Turnstile) y **ya está lleno**. No tienes
que escribir nada en él. `.env.prod` vive junto al `docker-compose.prod.yml` en el servidor: **no está dentro de la imagen de Docker ni en GitHub**, y la
aplicación lo recibe solo al arrancar. No hace falta ningún correo para Let's Encrypt.

## 1. En AWS (una vez)
1. **Máquina EC2:** Ubuntu 24.04 LTS, mínimo `t3.small` (2 GB de memoria; construir la imagen pide memoria) y 30 GB de disco.
2. **Grupo de seguridad:** entrada TCP 22 (SSH) solo desde tu IP, y TCP 80 y 443 desde cualquier lugar. Nada más.
3. **IP elástica:** asígnala a la máquina, para que la IP no cambie si se reinicia.

## 2. En Cloudflare (una vez)
- Registro **A**: nombre `timesheet`, destino la IP elástica, con la **nube gris** (solo DNS). Con la nube naranja Let's Encrypt no podría emitir el certificado.
- **Turnstile:** en el widget que ya tienes, agrega `timesheet.cerevelo.com` a la lista de nombres de sitio.

## 3. En Brevo (una vez)
- **Seguridad → Direcciones IP autorizadas:** agrega la IP elástica del servidor. Sin esto, Brevo rechaza los correos.
- Verifica que `no-reply@cerevelo.com` esté como remitente verificado y que el dominio tenga SPF y DKIM.

## 4. En el servidor
```bash
# Una vez: instalar Docker
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker $USER      # cierra la sesión y vuelve a entrar
```

Desde TU computadora, en la carpeta del proyecto, manda el zip:
```bash
scp -i tu-llave.pem dist/tiempo-produccion.zip ubuntu@IP-ELASTICA:~/
```

En el servidor:
```bash
sudo apt-get install -y unzip
unzip tiempo-produccion.zip && cd timesheet && chmod 600 .env.prod
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
docker compose -f docker-compose.prod.yml --env-file .env.prod logs -f caddy   # mira que obtenga el certificado (Ctrl+C para salir)
```
La primera vez construye la imagen (varios minutos) y aplica las migraciones solo.

## 5. Crear la compañía
```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod exec app php artisan tiempo:instalar "Cerevelo" tucorreo@cerevelo.com --nombre="Luis"
```
Imprime una contraseña **una sola vez**. Entra a `https://timesheet.cerevelo.com/app/`, y cámbiala en **Settings → My account**.

Después, ya dentro:
1. **Settings → Rules:** en la tarjeta Company, **Domain** = `timesheet.cerevelo.com`. Revisa el idioma por omisión y "Recordar por (días)".
2. **Settings → Branding:** colores `#04297C` y `#087A85`, y el logo.
3. **People:** invita a las personas (llegan por correo). **Approvers**, **Clients** y **Projects** con sus servicios.

## 6. Respaldos (haz esto el primer día)
```bash
./scripts/backup.sh                 # crea backups/tiempo-db-FECHA.sql.gz y tiempo-archivos-FECHA.tar.gz
```
- Programa un respaldo diario (`crontab -e`): `0 3 * * * cd /home/ubuntu/timesheet && ./scripts/backup.sh`
- **Saca los respaldos de la máquina** (por ejemplo a un bucket S3) y guarda aparte, en un lugar seguro, `.env.prod`, sobre todo la `APP_KEY`: sin ella no se descifra la contraseña de Accountisi al restaurar.
- Restaurar en otra máquina: `./scripts/restore.sh respaldo-db.sql.gz respaldo-archivos.tar.gz` (escribe RESTAURAR para confirmar).

## 7. Actualizar a una versión nueva
Genera un zip nuevo en tu computadora (el mismo proceso), mándalo, desempácalo **encima** de la carpeta `timesheet` del servidor (conserva su `.env.prod`) y repite:
```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
```
(Si en el servidor tienes el repositorio de GitHub clonado en vez del zip: `git pull` y el mismo comando.)

## 8. Si algo falla
- **No hay certificado / el navegador dice conexión no segura:** revisa `docker compose ... logs caddy`. Casi siempre es que el registro A está con nube naranja, apunta a otra IP, o los puertos 80 y 443 no están abiertos.
- **No llegan correos:** `docker compose ... exec app php artisan tiempo:probar-correo tu@correo.com`. Casi siempre es la IP sin autorizar en Brevo o el remitente sin verificar.
- **El login no deja pasar:** verifica que el dominio esté en la lista de Turnstile y que el reloj del servidor esté en hora.
- **Ver los registros de la aplicación:** `docker compose ... logs -f app`.

## 9. La aplicación móvil
La APK ya sale con el servidor fijo en `https://timesheet.cerevelo.com` (el empleado nunca lo ve ni lo escribe). Se instala copiando el archivo al teléfono
(permite instalar de orígenes desconocidos). La APK está firmada con la llave de depuración: sirve para repartirla directo, pero para Google Play hará falta
una llave propia.
