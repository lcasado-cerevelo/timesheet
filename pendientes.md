# Pendientes y decisiones (notas de Luis, 2026-10-25)

Lista viva de lo que Luis pidió y aún no está terminado. Se tacha cuando queda hecho, probado y subido.

## Diseño de la web (parecerse al mockup)
- [ ] Idéntica al mockup (`EBVS Timesheets Mockup – App mockup · all roles.html`): mismo tipo de letra (IBM Plex Sans), mismas "pastillas" (badges) en las tablas, mismas tarjetas, botones, pestañas y espaciados. Revisar pantalla por pantalla contra el mockup.
- [ ] Única diferencia pedida respecto al mockup: usar **todo el ancho de la pantalla**, igual que el encabezado, con márgenes a los lados. No hay menú a la izquierda.
- [ ] Menos opciones arriba: agrupar las relacionadas (hecho en parte: 6 pestañas con pastillas dentro de la página).
- Estado: el rediseño y la traducción al inglés ya están escritos en `server/public/app/` (app.js y style.css), probados en el navegador, **sin commit**.

## Idiomas: español e inglés, en las dos aplicaciones
- [ ] Web y móvil en los dos idiomas (no solo inglés). Incluye correos, PDF, mensajes de error del servidor y pantallas de aprobación por enlace.
- [ ] Las columnas de la exportación a Consultorio 1500 se quedan en español (el importador solo las reconoce así).
- [ ] Por decidir: cómo se elige el idioma (propuesta: por usuario, con uno por omisión para la compañía).
- [ ] Luis dijo "olvídate del estándar del color": aclarar a qué se refería (¿colores del mockup, de la marca por compañía u otra cosa?).

## Aplicación móvil
- [ ] Pantalla **Día**: debajo del día, mostrar el **proyecto** para escogerlo. Se cambia deslizando hacia los lados o tocándolo para abrir la lista de proyectos (la misma que sale al "cambiar proyecto").
- [ ] **Check-in / check-out** como en Time Recording: al tocarlo sale solo un reloj para fijar la hora de entrada y, al hacer check-out, otro reloj para la hora de salida. Los demás botones se quedan igual.
- [ ] Pantalla de entrada: ver abajo la pregunta del total de horas.

## Pregunta contestada: ¿dónde se entra el total de horas trabajadas?
- Móvil: en **Día → Agregar entrada**, el campo "Horas del día". Solo aparece si la persona tiene el modo de captura **"Día completo"**. Con "Desde / hasta" no aparece: el total se calcula de la entrada y la salida.
- Web: en **Timesheet → Add entry**, el campo "Hours for the day", con la misma regla.
- El modo lo fija el dueño por persona en **People → Capture mode** ("Full day allowed" o "From / to required"). Luis tiene "Desde / hasta", por eso no lo ve.
- [ ] Por decidir: si quiere poder escoger entre las dos formas en la misma pantalla (reloj o total de horas) sin importar el modo.

## Otros hallazgos
- Lentitud en desarrollo: las llamadas a la API tardan 150 ms o 1.5 a 3.7 s al azar. Probable causa: la caché de código de PHP revisa los archivos de la carpeta de Windows (puente lento de Docker) cada pocos segundos. Propuesta, sin aplicar: revisar menos seguido en desarrollo o mover `vendor` a un volumen de Docker. En producción no aplica.
- La web ya muestra el marco de la página y "Loading…" al instante mientras llegan los datos.
- Exportación a Consultorio 1500: nombre del proyecto "Paciente - Plan - Contrato" (hecho y subido en dd78698).

## Nuevas decisiones (Luis, 2026-10-25, segunda tanda)
- Móvil, pantalla **Día**, de arriba hacia abajo: el día; el **proyecto** (deslizar o tocar para ver la lista); línea "Entrar ahora / Salir ahora" (Check in now / Check out now); línea "Agregar entrada" (Add entry, desde/hasta); línea "Agregar horas" (Add hours, total del día); línea "Cambiar proyecto" y "Repetir". Entrar/Salir con un reloj, como Time Recording.
- "Repetir": hoy existe como plantilla; se estudia a fondo después.
- **El modo de captura pasa del usuario al proyecto**: por hora (desde/hasta), total de horas del día, o ambos. El proyecto manda; el usuario ya no lo tiene.
- **Servicios dentro del proyecto**, como en Time Recording (proyecto + servicio). En la pantalla de entrada sale el proyecto actual y la lista de sus servicios; si solo hay uno se escoge solo.
- Aplica a web y móvil.
- Por contestar: ¿la tarifa va en el servicio o en el proyecto?; ¿"ambos" permite mezclar los dos modos el mismo día?; ¿qué modo reciben los proyectos que ya existen?; ¿el servicio es obligatorio en cada entrada?

## Reglas acordadas (Luis, 2026-10-25, tercera tanda)
- Tarifa: en el **servicio**, con una por omisión en el proyecto.
- **El servicio** define la tarifa y cómo se entra: **detalle** (desde/hasta), **total de horas**, o **ambos**.
- La **persona** conserva su preferencia (detalle o total). Solo se usa cuando el servicio permite ambos.
- Quién decide: si el servicio no es "ambos", manda el servicio. Si es "ambos", manda la preferencia de la persona.
- El proyecto ya no define el modo. Cada proyecto tiene siempre al menos un servicio; si solo hay uno, se escoge solo. El servicio es obligatorio en cada entrada.
- Proyectos que ya existen: reciben un servicio por omisión con modo "ambos" y la tarifa del proyecto, para que todo siga igual.
- Ideas por confirmar: servicio facturable o no (como "Internal" del mockup); tarifa congelada al aprobar; servicios inactivos sin borrar historial; la persona puede cambiar su preferencia desde el teléfono.

## Móvil: margen inferior (Luis, 2026-10-25)
- [ ] Dejar siempre un margen abajo en todas las pantallas de la app móvil (áreas seguras / safe area insets), para que nada choque con la barra de navegación del celular. Incluye la barra de pestañas, los botones de abajo y los modales.

## Estado al 2026-10-25 (noche)
Hecho y probado (112 pruebas del servidor, 49 del móvil, tsc en verde), SIN commit: servicios, modo por servicio, tarifas guardadas en la entrada, dos idiomas en servidor/web/móvil, web con el diseño del mockup (letra IBM Plex, pastillas, ancho completo, Todas las semanas, aprobaciones en dos paneles, reglas agrupadas), móvil con pantalla Día nueva y margen inferior.
Falta: probar el móvil en teléfono/emulador; revisar la web contra el mockup pantalla por pantalla con Luis; decisiones del móvil (entrada vieja sin servicio, horas de Entrar/Salir ahora, Cambiar proyecto con reloj corriendo, aviso de llegada con varios servicios, reportes por servicio); "Repetir" a fondo; carpeta branding/ (de Luis) sin subir; Laravel Boost (lo pide server/CLAUDE.md) sin instalar.

## Por construir cuando Luis diga "empieza y construye" (2026-10-25)
- **Cuenta opcional del aprobador**: casilla "Puede entrar al sistema" en Aprobadores. Invitación por correo; el correo es el que ya tiene el aprobador (se muestra, no se escribe) y solo crea su contraseña nueva. Al entrar: correo + contraseña + código de 6 dígitos por correo + "recordar este equipo 30 días". Dentro solo ve Aprobaciones (Pendientes, Aprobadas, Devueltas, Todas). Los enlaces de los correos siguen igual. El código solo se pide a aprobadores, no a consultores ni empleados.
- **Ver contraseña** (ojo): ocultar el botón nativo de Edge (::-ms-reveal) para que no salga doble, y usar autocomplete correcto.
- **Login web más fuerte y mejor presentado**, como el mockup (ver propuestas en la conversación): marca de la compañía, recuperar contraseña por correo, aviso de mayúsculas, errores claros.
- **Móvil**: el campo "Servidor" no debe verse en el login. Paso de conexión solo la primera vez (o servidor fijado al compilar), luego oculto; "Cambiar servidor" escondido en Ajustes.

## Decisiones de Luis sobre cuentas y contraseñas (2026-10-25, para construir cuando diga "empieza y construye")
- Móvil: el servidor se **fija al compilar** (una compilación por compañía). El login nunca muestra el servidor.
- Web: **cada compañía tiene su propio dominio o subdominio**; el login toma la marca de la compañía según el dominio. Los enlaces de los correos usan el dominio de la compañía.
- "Recordar este equipo" es **configurable** en Reglas (días; 0 = apagado).
- **El dueño o administrador nunca ve ni escribe contraseñas.** Al crear a una persona no se escribe contraseña: recibe una invitación para crearla. Si el dueño resetea la contraseña, la persona **debe crear una nueva** al entrar (siempre). Se anula la anterior y se cierran sus sesiones.
- Cada persona puede **cambiar su contraseña** (web y móvil) y para eso **debe escribir la actual**. Si no la sabe, llama al dueño para que se la resetee.
- Sin recuperación por correo hecha por la persona misma (por decisión de Luis): solo el reseteo del dueño.
- Código por correo: pregunta abierta si se pide también al aprobar (casilla "Pedir siempre código" del aprobador ya existe).
- Confirmado por Luis: el código al aprobar solo cuando se marque la casilla del aprobador. Sin recuperación de contraseña por cuenta propia (llamar al dueño). **Ojito para ver la contraseña en todos los campos de contraseña** (web y móvil), como un solo componente compartido; en la web se oculta el botón nativo de Edge para que no salga doble. El ojito solo muestra lo que la persona escribió, nunca una clave guardada en el servidor.

## Estado al 2026-10-26: construido y probado, SIN commit
Cuentas de aprobador (invitación, código por correo, recordar equipo configurable, vista Aprobaciones), contraseñas sin que nadie las maneje (invitación, reseteo del dueño, cambio con la actual, comando para el dueño), ojito en todos los campos, login web con marca por dominio, dominio por compañía, móvil con servidor fijo al compilar, ícono del reloj de arena. 144 pruebas del servidor, 62 del móvil, tsc en verde.
Por probar en la vida real: móvil en teléfono/emulador (ojito, servidor fijo, ícono), Caddy con varios dominios, Turnstile con el aprobador, correos reales con Brevo.
Prueba en desarrollo: se creó la cuenta de aprobador de Jorge Ramos (jorge@cliente.test, clave de prueba) y se aprobó la hoja de Ana de la semana 25 oct.

## Lista aprobada en conversación, a construir cuando Luis diga "empieza y construye" (2026-10-26)
1. Web: botón Editar en Aprobadores y Clientes (el servidor ya lo permite).
2. Regla: un proyecto facturable debe tener cliente; los internos (no facturables) pueden quedar sin cliente.
3. Móvil: buscador para escoger proyecto escribiendo (sin filtro por cliente; el cliente sale del proyecto). Recientes primero, sin tildes ni mayúsculas, funciona sin conexión.
4. Marca: opción "mostrar el nombre junto al logo" (apagada = solo el logo) y logo horizontal más alto en el encabezado.
5. Marca: además del cuadro de color, campos para escribir el color en hexadecimal y en RGB (R, G, B), sincronizados con el selector.
6. Colores de Cerevelo recomendados: principal #04297C = RGB(4, 41, 124); acento #087A85 = RGB(8, 122, 133). Alternativa de principal: #0641BA = RGB(6, 65, 186). Turquesa vivo del logo #3BF3E2 = RGB(59, 243, 226), solo para detalles.
(Hecho ya: el arreglo que bloqueaba guardar la marca por el contraste del verde viejo; falta subirlo a GitHub.)
7. Reglas: "Recordar por (días desde el último uso)" vale igual para TODOS (sesión de web y móvil) y, para aprobadores, también el tiempo que un equipo de confianza se salta el código. Va en una tarjeta propia "Sign-in" (Acceso) de Reglas, no dentro de Approvals. Mínimo 1 día, por omisión 30. Sin conexión, la app móvil sigue funcionando con lo guardado; la contraseña se pide una vez al volver la señal y entonces se sincroniza todo.
7b. Corrección (Luis): la regla "Recordar por (días)" SÍ admite 0 = no recordar (pedir contraseña cada vez que se abre; a aprobadores, también el código cada vez). Web: la sesión vive solo mientras el navegador sigue abierto. Móvil con 0: pide la contraseña cada vez que se abre la app, lo que exige señal; la ayuda de la regla debe avisarlo. Pendiente que Luis decida si en el móvil con 0 se permite anotar horas sin señal en un modo limitado.
7c. Decidido por Luis: con "Recordar = 0", sin señal el móvil permite solo anotar horas (modo limitado, sin ver lo anterior); la contraseña se pide al volver la señal y entonces se sincroniza todo.

## Estado al 2026-10-26 (tarde): lista 1–7 construida y probada, SIN subir
154 pruebas del servidor, 84 del móvil, tsc y revisión de la web en verde. Falta ver el móvil en un teléfono o emulador (buscador, modo limitado, ojito, ícono, servidor fijo), probar Caddy con varios dominios y el flujo del aprobador con Turnstile real, y decidir si se instala Laravel Boost (lo pide server/CLAUDE.md; no se instaló).

## Rehacer pantallas como el mockup y aprobación interna (Luis, 2026-10-26, noche)
En construcción (3 agentes): Hoja semanal (tabla editable por proyecto/servicio/tipo de hora, aviso de semanas pasadas, ID NUMBER, reloj como ícono con modal, toasts en vez de alertas pegadas), Personas (invitar por correo con Worker/Approver/Back office, tabla con 2-STEP y STATUS, términos del consultor) y Aprobaciones (conteos, filtros, columnas). Número de empleado, tipos de hora (REG, OT, HOL, Sick, VAC, NB) para registrar un día aunque no se trabajara (vacaciones, enfermedad).
Decidido: servicios por "desde/hasta" se editan en un cuadrito al tocar la celda; el nombre va en la invitación.

**Pendiente de construir (esperando respuestas de Luis): APROBACIÓN INTERNA.**
- Aprobador interno = un empleado más (supervisor). El PROYECTO tiene la opción "requiere aprobación interna" y se escogen uno o más aprobadores internos.
- Flujo: la persona envía la hoja → se notifica al aprobador interno → cuando la aprueba, pasa al aprobador externo, que certifica al final.
- La tabla de Personas muestra como "Approver" a quien sea aprobador interno en algún proyecto (se deduce de los proyectos).
- Preguntas abiertas: ¿basta uno de varios aprobadores internos de un proyecto o hacen falta todos?; hoja con proyectos distintos: ¿cada proyecto con su(s) aprobador(es) interno(s) aprueba su parte y la hoja pasa al externo cuando todas estén aprobadas?; si el interno devuelve, ¿vuelve toda la hoja a la persona?

**Pendiente (instrucción de Luis): el detalle de horas del informe que acompaña la factura** (hoja para el cliente en PDF/Excel/CSV, líneas de la factura de venta y detalle del consultor) debe seguir cómo se entró: si se entró hora por hora, sale hora por hora (desde/hasta); si se entró el total de horas, sale el total. Reemplaza el ajuste por proyecto "Invoice detail" (total/por día/con horas), que desaparece.

## Estado al 2026-10-26 (noche): construido y probado, SIN subir
Pantallas del mockup, aprobación interna y detalle de horas por cómo se entró. 182 pruebas del servidor, 84 del móvil, tsc en verde. Flujo interno probado con usuarios reales (Ana envía, Marcos aprueba o devuelve, el externo recibe solo después). Reglas de aprobación interna sin confirmar por Luis (basta uno, parte por proyecto, devolución completa, etc.); están en InternalApprovalService.
Pendiente: aprobación interna en el móvil (no pedida), ver el móvil en teléfono, revisar los PDF/Excel del detalle de horas, subir a GitHub.

## Aprobadores por servicio (Luis, 2026-10-26) — servidor construido (ver diseno.md v1.10); falta web/móvil
- Cada SERVICIO dice quiénes aprueban, según su modo (none/internal/external/both). La lista de supervisores internos pasa del proyecto al servicio.
- Interno: NO se puede omitir; hay que escoger al menos un supervisor (uno o varios; basta que apruebe uno).
- Externo: los aprobadores externos se ligan a CLIENTES (un aprobador puede servir a varios clientes). En el servicio se escogen entre los del cliente del proyecto. Si se omite, se envía a TODOS los aprobadores externos de ese cliente. Si el cliente no tiene ninguno, se PROHÍBE guardar un servicio que requiera aprobación externa (y el proyecto debe tener cliente).
- Una hoja puede tener varios aprobadores externos (servicios de distintos clientes): cada uno recibe solo sus horas; la hoja se completa cuando todas las partes estén aprobadas.
- Por omisión (sin respuesta de Luis): el "aprobador" de la persona deja de mandar y queda solo como respaldo de servicios viejos sin configurar; un aprobador sin clientes no sale en ningún servicio; con varios externos basta uno.
- Selección múltiple (Luis, 2026-10-26): las listas pueden ser grandes, así que NADA de casillas sueltas. Un solo componente reutilizable: casilla de búsqueda que filtra mientras escribes, lista desplegable con scroll (máx. unas 8 filas visibles) y los escogidos como fichas con ✕ arriba. Se usa en: supervisores internos y aprobadores externos del servicio, clientes de un aprobador, personas asignadas y proyectos asignados. Lo de editar en ventana emergente se queda como está.

## Envío por partes y notas (decidido con Luis el 2026-10-26; CONSTRUIDO y probado, sin subir a git)
- `week_ending` se queda como la fecha de la semana. Una semana puede tener varias hojas (parte 1, 2, 3); cada una se aprueba, se devuelve y se factura aparte.
- No se guardan "desde/hasta": se calculan de la línea más vieja y la más nueva de cada hoja (servidor; web y móvil leen eso).
- Se envían días corridos (incluye fin de semana; los días sin horas entran solos con 0). Los días enviados quedan bloqueados.
- Opción de la compañía "Permitir enviar por partes", apagada por defecto.
- Pantalla: marca "enviar hasta aquí" en el encabezado de cada día (marca los anteriores solos) y un botón con el resumen ("Enviar lun–mié · 3 días · 24 h"). Días enviados con candado y estado.
- Notas: ícono de nota por celda (servicio + día; usa time_entries.note) y una nota del ENVÍO. Al enviar se copia a las celdas sin nota (marcadas "viene del envío"). Casilla "Exigir nota" por servicio, apagada por defecto.
- CASO A RESOLVER (Luis): se enviaron lun, mar y mié por separado; el jueves el aprobador devuelve el martes. Hay que poder reenviar el martes (misma hoja, versión+1) y enviar el jueves (hoja nueva), aunque el miércoles (ya enviado) quede en medio.
  Solución propuesta: "enviar hasta aquí" toma los días ABIERTOS (borrador o devueltos) hasta ese día, en orden, y crea una hoja por cada bloque seguido de días abiertos; un día devuelto se reenvía en su propia hoja. No se puede enviar un día si queda antes un día abierto sin enviar.
- Aprobación por día en sí queda cubierta por esto (cada parte es una hoja). Parcial por servicio y estado por línea: descartados por ahora.
- Accountisi por IP: descartado (IP del hosting compartido cambia). Posible proxy con IP fija si más adelante se necesita.

### Cómo quedó (2026-10-26)
- Servidor: migración 2026_11_02 (timesheets.part/period_start/period_end/submission_note, time_entries.note_from_submission, services.require_note, regla partial_submission). El borrador no tiene número de parte (null) y lo toma al enviarse, así las partes salen 1, 2, 3 sin huecos. Se guardan period_start/period_end porque los días sin horas también pertenecen a una hoja.
- `POST /api/sheets/submit-days {through, note}`; `GET /api/sheets/week` ahora trae `parts`, `days` (estado de cada día) y las líneas de todas las partes. Una hoja en borrador de una semana que ya tiene partes envía los bloques abiertos hasta hoy.
- Web: ícono de nota por celda, nota del envío, "enviar hasta aquí" en el encabezado de cada día, tarjeta "Enviada por partes" (con el motivo de una devolución), regla "Permitir enviar la semana por partes" y casilla "Exigir una nota en cada línea" en el servicio.
- Pruebas: PartialSubmissionTest (13, incluye el caso del martes devuelto) y CompanyBaseUrlTest.
- Móvil (2026-10-26): ya reconoce el día. `GET /sheets/week` trae el estado de cada día; la app bloquea solo los días aprobados (lockedDays), muestra el estado de cada día en la semana y el motivo de cada parte devuelta, y "Enviar" manda los días abiertos y las partes devueltas. No tiene todavía "enviar hasta aquí" ni las notas por línea del móvil (se pueden escribir en la entrada; la nota del envío no existe en el móvil).
- Aprobadores (2026-10-26): probado con ApproverPartsTest (7): un correo por parte con "parte N" y sus fechas, la página por enlace muestra solo esa parte, aprobar una parte no toca las otras, el martes devuelto se reenvía en su hoja (versión 2) y el jueves es una parte nueva, la cuenta de aprobador ve cada parte con su periodo y solo sus líneas, el supervisor interno aprueba/devuelve parte por parte y el externo solo recibe la aprobada. El aviso de horas por semana cuenta todas las partes.
- Pendiente: mostrar la nota por línea en los PDF/Excel de la factura si Luis lo quiere distinto; revisar el correo al aprobador con "parte N" en un cliente real.

## Seguridad y semana (2026-10-26)
- Cabeceras de seguridad (middleware SecurityHeaders + .htaccess + Caddyfile): CSP sin scripts en línea (permite Cloudflare Turnstile y Google Fonts), HSTS con HTTPS, X-Frame-Options DENY, `no-store` en la API.
- Tope absoluto de sesión, por reglas (Settings → Rules → Sign-in): dueño y oficina 7 días, el resto 14, 0 = sin tope. Cuenta desde que se escribió la contraseña aunque se use a diario. Un equipo recordado nunca vale más que ese tope.
- Código por correo también para dueño y oficina (web y móvil; regla `two_step_office`). Solo se exige si el correo está configurado de verdad. Si el correo se rompe y nadie puede entrar: cron de una sola vez `php artisan tiempo:two-step off`.
- Contraseñas: no pueden ser el correo ni contenerlo, ni de las comunes o con patrón (1234567890, aaaa…), ni estar en filtraciones (Have I Been Pwned, solo se envían 5 caracteres del SHA-1; si no responde, no bloquea).
- Móvil: pantalla del código al entrar y el teléfono queda recordado (device_token en el almacén seguro).
- Inicio de la semana configurable (regla `week_starts_on`: 0 domingo = por defecto, 1 lunes). Web y móvil lo leen de las reglas; las hojas ya creadas no se mueven. Las pruebas históricas fijan lunes.

## Factura del consultor con aprobación interna y comisiones (Luis, 2026-11-02) — SOLO DISCUTIDO, nada construido
Contexto: hoy la factura del consultor sale de hojas APROBADAS del todo (también por el cliente); un cliente lento detiene el pago de un consultor con pago mensual. La factura es de hoja completa (`contractor_invoice_id` en la hoja), no por proyecto; eso se queda así.
- **Opción en el consultor** (no en el proyecto), en sus condiciones de facturación: "se factura con la aprobación interna". Con ella puede facturar hojas que ya tienen la aprobación interna aunque sigan pendientes del cliente. La factura de venta al cliente (Accountisi) NO cambia: sigue exigiendo la aprobación del cliente.
- Si el cliente devuelve o reduce horas después: la factura del consultor no se toca (es asunto de Luis con el cliente). Si el cliente resolviera por MÁS horas: ajuste en positivo en la próxima factura del consultor. Nunca ajustes en negativo.
- **Comisiones** (mismo caso): el consultor debe poder VER si le aplica alguna comisión, pero la comisión NO se libera hasta que llegue la aprobación externa. Mientras la hoja no tenga la aprobación externa no se permite liberar. Solo comisiones liberadas entran en la factura (`CommissionResult` en estado `released`).
- Dueño y oficina también llenan horas y deben poder facturarlas: hoy solo factura quien es "Consultant" y tiene tarifa mayor que 0 (`ContractorInvoiceService::create`). En la lista de Personas el tipo del dueño sale siempre "—" aunque tenga uno guardado; la ventana de editar sí lo muestra ("Consultant" cuando no es `employee`). Falta decidir si se separa "quién factura" del tipo de captura (employee = día completo, 40 h).
- Sin confirmar: quién aprueba la hoja del dueño (nadie aprueba por otro) y que el correo de su factura le llega a él mismo.
- **Estado (2026-11-02): construido, SIN probar ni subir.** Casilla `contractor_terms.bill_on_internal_approval` (migración 2026_11_20, pantalla Editar persona, español); `ContractorInvoiceService::create` toma también hojas enviadas en etapa externa con al menos una aprobación interna real (no omitida) cuando el consultor tiene la opción. Las comisiones no cambiaron: `CommissionService` ya cuenta solo horas de hojas APROBADAS y `TaskController` rechaza completar una tarea con horas sin aprobar, así que no se liberan antes de la aprobación externa; el consultor ya ve su avance y `pending_hours`. Pruebas escritas en `InvoiceOnInternalApprovalTest` (5), no corridas: Docker no estaba encendido. NO construido: el ajuste en positivo si el cliente aprueba más horas que las facturadas (la hoja facturada queda ligada a su factura y esas horas extra no se cobrarían); y lo del dueño/oficina (arriba).
- **Ajuste en positivo (2026-11-02): construido y probado.** Al facturar una hoja se guarda `timesheets.invoiced_snapshot` (horas facturables por proyecto|servicio|tarifa; migración 2026_11_20_000100). Si esa hoja termina APROBADA con más horas que las facturadas, la diferencia sale como línea `kind: adjustment` ("Ajuste · proyecto · semana") en la próxima factura del consultor, a la tarifa de esas horas, sin importar el período; nunca hay ajustes en negativo. Las hojas facturadas antes de este cambio no tienen foto y no se ajustan. Hoja del dueño (decidido): no aprueba lo suyo; otro aprobador interno la aprueba y, si no hay otro, la parte interna se omite y cuenta como aprobada (regla R4 de `InternalApprovalService`, ya existía). Con la casilla "Facturar con la aprobación interna" también cuenta la parte omitida. Para facturar sus horas el dueño necesita tipo Consultant y tarifa mayor que 0 (es dato, no código). Pruebas: `InvoiceOnInternalApprovalTest` (8 en verde).
- **Cliente que no aprueba (2026-11-02): construido, probado en servidor (SheetCloseVoidHoldTest, 9 en verde); pantalla sin ver en navegador.** Solo dueño, en la hoja que espera al cliente (Aprobaciones): (1) **Cerrar sin aprobación del cliente** (motivo + firma): queda aprobada por la compañía (`closed_by_company`, `decided_via=company_close`), cuenta para facturar al consultor y para la comisión, y NO entra en la factura de venta al cliente. (2) **Anular hoja** (estado `void`, motivo + firma): borra las entradas (queda el detalle en el historial), libera los días, no se permite si ya se facturó o tiene tarea con comisión calculada. (3) **Pausa** hasta una fecha: solo detiene los recordatorios (externos e internos); no toca las fechas de las horas ni la marca de atraso. Comisión: las horas de un consultor con la casilla y aprobación interna ya cuentan para calcularla (`Timesheet::scopeCountsForCommission`); completar la tarea con `release` se rechaza (`awaiting_client`) y `release()` también mientras haya horas esperando al cliente (`CommissionService::awaitingClient`); se libera cuando el cliente apruebe o el dueño cierre la hoja. Migración 2026_11_21. Sin hacer: anular una hoja ya aprobada desde la pantalla (el servidor sí lo permite si no está facturada), aviso al consultor al anular, y mover fechas de horas (decidido NO hacerlo).

## Rol de oficina y dueño que factura sus horas (Luis, 2026-11-05) — HECHO, sin probar por falta de PHP 8.4 en el entorno de la sesión
- Rol `office` (usuarios.role): ve hojas de todos, aprueba solo como aprobador interno, avance, reportes/exportes, cliente-hoja, facturas, marca comisiones como pagadas, ve la lista de personas SIN tarifas. No toca reglas, marca, integraciones, personas, tarifas, proyectos, clientes ni aprobadores; no reabre/cierra/anula hojas ni libera comisiones. Middleware `staff` (dueño u oficina) y `owner` (solo dueño). `User::isStaff()`.
- El rol se edita en la ventana de la persona (nadie cambia el suyo) y se elige al invitar.
- Dueño y oficina facturan sus horas igual que un consultor: tipo Consultant + tarifa en sus condiciones de facturación. Decisión: NO se separa "quién factura" del tipo; el tipo del dueño ahora sale en la lista de Personas.
- La pantalla Invoices muestra "Tus semanas": listas para facturar (con horas y monto), las que esperan aprobación y, si no puede facturar, el motivo (no es consultor / falta la tarifa). `GET /api/contractor-invoices/status`.
- Falta: correr la suite completa en un entorno con PHP 8.4 (nuevas: OfficeRoleTest, OwnerInvoiceTest).

## Enlace con Employee Tracker (Luis, 2026-11-03) — SOLO ANOTADO, nada construido
Luis quiere que Timesheet y Employee Tracker (`F:\Visual Studio 2022\Projects\EmpTrac`) se enlacen o sincronicen de alguna manera. Lo que se ve razonable, por decidir con él:
- **Turno → horas.** Employee Tracker registra el inicio y fin de turno de cada empleado (`Sessions`) y el resumen escrito al terminar (`ShiftSummaries`). Timesheet podría crear o proponer la entrada del día (modo `total` o `detail` con desde/hasta) a partir del turno, con la nota del resumen, para que el empleado solo confirme. Nunca se enviaría sola: la hoja la envía y firma la persona.
- **Horas → verificación.** Al revés: el aprobador interno podría ver, junto a cada día de la hoja, si Employee Tracker registró turno ese día y cuántas horas, como dato de apoyo (no como bloqueo).
- **Cómo.** Employee Tracker no tiene API de lectura hoy (solo escritura desde el cliente y los endpoints de admin). Haría falta un endpoint `GET /api/sessions?employee&from&to` con JWT de servicio, y en Timesheet una integración como la de Accountisi (URL + credencial cifrada, pantalla Integration), con un emparejamiento empleado ↔ usuario por correo.
- **Dependencia.** Employee Tracker tiene su propia lista de pendientes prometidos (`docs/PENDIENTES-PROMETIDO.md` en ese proyecto): panel web, reportes, exportación. El enlace va después de eso.

## Proyectos internos, horas en curso y filtro sin cliente (Luis, 2026-11-05)
- **Hecho.** La factura del consultor paga todas sus horas aprobadas, se cobren o no al cliente (proyectos internos como Cepas, sin cliente y no facturables).
- **Hecho.** Pestaña "En curso" en Aprobaciones (dueño y oficina, solo lectura): horas de borradores aún sin enviar, por persona y semana. Endpoint `GET /api/in-progress`.
- **Hecho.** Opción "Sin cliente (interno)" (`customer_id=none`) en el filtro de cliente de En curso, la lista de Proyectos y su exportación. Los otros filtros de cliente (hoja del cliente, facturas de venta, Consultorio 1500) necesitan un cliente real y no la llevan.
- **Sin hacer.** Ver las pantallas nuevas en el navegador; anular desde la interfaz una hoja ya aprobada; aviso al consultor cuando se anula una hoja; campo de descanso en el móvil.

## Reportes para el gerente de proyecto (Luis, 2026-11-11) — HECHO, sin probar (sin PHP 8.4 en el entorno)
- El gerente de proyecto (aprobador con cuenta y la marca) ve en **Reportes** las mismas pantallas y opciones que el dueño, solo de los clientes de los que es parte: **Hours report** (con sus botones CSV/Excel/PDF de la tabla) y **Client timesheet** (vista previa, PDF/Excel/CSV y envío por correo). **No** tiene la pantalla **Export** (la de Consultorio 1500).
- Hours report: las horas de todos en los proyectos de sus clientes (`/api/approver/reports/summary`, y `/api/tables/report/export` para exportar). Client timesheet: `/api/approver/client-timesheet/{options,preview,download,email}`. Cuenta lo que ya salió de la etapa interna (enviado al cliente o aprobado); borradores y etapa interna nunca; lo no facturable solo si el proyecto muestra la cortesía. `ManagerReportService`.
- Aplica a web, demo y producción por igual; el móvil no se tocó.

## Aprobar varias semanas a la vez (Luis, 2026-11-11) — SOLO ANALIZADO, nada construido
Idea: en Aprobaciones, casillas para marcar varias hojas y aprobarlas juntas; el panel derecho muestra todas las marcadas.
Decisiones recomendadas (por confirmar con Luis):
1. Solo **aprobar** en lote; **devolver** sigue de una en una (cada devolución lleva su comentario).
2. Se pueden **mezclar personas y semanas** en un mismo lote.
3. **Tope de 25 hojas** por lote.
4. **Una sola contraseña** por lote; cada hoja guarda su propia firma digital, historial y versión (como si se aprobara una a una).
5. **Solo web** por ahora (el móvil no).
Diseño: casilla en cada fila + «seleccionar todas las visibles»; sin nada marcado, el panel sigue como hoy (la hoja que se toca); con varias marcadas, el panel apila todas (persona, semana, líneas, notas, firmas) y un botón «Aprobar N semanas». Al terminar, resumen: cuántas se aprobaron y cuáles fallaron y por qué (cambió después de enviarse, ya la aprobó otro, etc.); las que fallan quedan sin aprobar.
Construcción: una ruta de lote por tipo de cuenta (dueño/oficina `/sheets/approve-many`, interno `/internal/sheets/approve-many`, externo con cuenta `/approver/sheets/approve-many`) que verifique la contraseña una vez y llame a la lógica de una hoja (`SheetService`) una por una, sin debilitar reglas (nadie aprueba lo suyo, la etapa externa no la sustituye el dueño, se compara la versión). Pruebas: lote completo, fallo parcial, hoja ya aprobada, hoja de otro, contraseña incorrecta.
Quedan fuera los aprobadores por enlace del correo (aprueban hoja por hoja).

## Reporte de horas de la compañía (Luis, 2026-11-11) — HECHO, sin probar (sin PHP 8.4 en el entorno)
- El Hours report era personal (solo las horas de quien lo abre, en cualquier estado). Ahora, para el dueño y la oficina, muestra las horas de **toda la compañía**; para el gerente de proyecto, las de todos en los proyectos de sus clientes (solo lo que ya salió de la etapa interna); para los demás, las suyas.
- Filtros: persona, cliente y proyecto. **Agrupar por**: proyecto, día, semana, cliente y **persona**.
- Cada total sale separado por estado de la hoja: aprobadas, enviadas (esperando), devueltas y borrador, para que cuadre con la hoja del cliente (que solo lleva las aprobadas). La tabla exportable (CSV/Excel/PDF) trae las mismas columnas. `HoursReportService`.
