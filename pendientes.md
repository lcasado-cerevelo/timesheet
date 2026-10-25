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
