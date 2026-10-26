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
- LÍMITE CONOCIDO (móvil): la app móvil no conoce las partes. Si una línea cae en un día ya aprobado, el servidor la rechaza (423) y el móvil marca toda la semana como bloqueada hasta reinstalar/limpiar. Hacerlo por día en el móvil queda pendiente.
- Pendiente: mostrar la nota por línea en los PDF/Excel de la factura si Luis lo quiere distinto; revisar el correo al aprobador con "parte N" en un cliente real.
