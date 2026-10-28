# Timesheet — diseño v0.1 (2026-10-24)

Reemplaza las decisiones abiertas de `analisis-preliminar.md`. Lo marcado **[Confirmar]** es supuesto mío.

## 1. Alcance

Aplicación web de registro de tiempo multiempresa. Primeros usuarios: Luis, sus consultores y EBVS. Cualquiera puede usarla con su propia marca. Sin horas por correo. Sin empleados W-2 en el primer corte: personas que registran horas = consultores (y Luis).

Base de diseño: ciclo semanal del mockup EBVS + estados de aprobación de Accountisi (borrador, sometida, aprobada, rechazada, facturada) + la idea de Dash de marcar lo facturado y lo pagado.

## 2. Marca configurable (por empresa)

- Nombre comercial, logo (claro y oscuro), color principal y color de acento.
- Remitente y plantilla de los emails (nombre, logo, color).
- PDF de factura del consultor con la misma marca.
- Dominio propio opcional (`tiempo.tuempresa.com`) en una fase posterior.
- Cada cliente final (por ejemplo EBVS) ve **tu** logo y tus colores en lo que le llegue, porque la marca pertenece a la empresa dueña de la cuenta.

## 3. Roles

| Rol | Hace |
|---|---|
| Dueño | Configura todo, aprueba, ve todo, dispara integraciones. |
| Consultor | Registra y somete sus semanas, ve su historial. |
| Aprobador | Persona que **te paga a ti o al cliente**, designada por proyecto o por persona. Puede ser externo y **sin cuenta**: actúa por enlace seguro en el email. Si tiene cuenta, también puede entrar. |
| Cliente (solo lectura) | Opcional, fase posterior. |

Reglas: un dueño puede ser también consultor y aprobador. Nadie aprueba sus propias horas salvo el dueño único de una cuenta personal (se registra como autoaprobación).

## 4. Flujo de la semana

`borrador → sometida → aprobada | devuelta → (corregida y vuelve a sometida) → facturada → pagada`

- Semana termina el domingo. Líneas por proyecto, 7 días, horas facturables y no facturables, copiar semana anterior.
- Reglas por empresa (las del mockup): semanas hacia atrás, fecha límite, "tarde", incremento de hora, máximo por día, reabrir N días, segunda aprobación sobre un monto.
- Una semana aprobada no se edita. Para corregir se reabre (con motivo y rastro) antes de facturarla. Una semana facturada no se toca; se corrige con nota de crédito en Accountisi.
- Auditoría de cada cambio de estado: quién, cuándo, desde qué canal (app o enlace de email).

## 5. Aprobación por email (recomendación)

**Qué lleva el email:** nombre del consultor, semana, total de horas y desglose por proyecto. **No** lleva tarifas ni importes, **no** adjunta documentos y **no** incluye comentarios internos. Así, si el email se reenvía por error, la fuga es mínima.

**Cómo se aprueba:** el email trae un botón "Revisar y decidir". Ese botón abre una página del sistema con la hoja completa y dos botones, **Aprobar** y **Devolver** (devolver exige comentario). La decisión se toma en esa página, no en el email.

**Por qué no aprobar con un clic directo desde el email:** los filtros de seguridad de correo y los antivirus abren los enlaces por su cuenta para revisarlos. Un enlace que aprueba al abrirse aprobaría horas sin que nadie las mire. Un botón que lleva a una página con confirmación evita eso y sigue siendo un solo clic más.

**Por qué no pedir usuario y contraseña:** para el aprobador externo es el dolor de cabeza que quieres evitar. El enlace sirve de prueba de que controla ese correo.

**Seguridad del enlace:**
- Token aleatorio largo, de un solo uso para decidir, ligado a **esa versión** de la hoja. Si el consultor la cambia, el enlace deja de valer y se manda uno nuevo.
- Vence a los 7 días (configurable). Se puede reenviar uno nuevo desde la app.
- La página no muestra datos de otras semanas ni de otras personas.
- Se registra IP, fecha y navegador de la decisión.
- Para semanas sobre el monto de segunda aprobación, o si lo activas por aprobador, se pide además un código de 6 dígitos enviado al mismo correo antes de aprobar.
- Recordatorio automático al aprobador si pasan N días sin decidir.

**Si un aprobador prefiere cuenta**, puede crearla después sin cambiar nada del flujo.

## 6. Módulo de integraciones (activable por empresa)

El núcleo no depende de ninguna integración. Cada integración es un conector que se enciende en Configuración.

### 6.1 Accountisi (API)

- Configuración: URL base, credencial, empresa de Accountisi. La URL puede ser `localhost` hoy y el servidor de Amazon mañana, o ambas aplicaciones en el mismo servidor. La app **nunca** asume la ruta ni que Accountisi es un servicio de Windows.
- Autenticación: Accountisi hoy solo acepta sesión de usuario (`/api/auth/login`, con MFA opcional). **[Confirmar]** Lo recomendable es añadirle a Accountisi una credencial de servicio por empresa (API key o token de larga duración, con permisos `TIME.*`, `AR.INVOICE.CREATE`). Sin eso, el timesheet tendría que guardar un usuario y contraseña y renovar sesiones.
- Sincronización de catálogos (solo lectura): clientes, proyectos, servicios y tarifas. El timesheet guarda los identificadores de Accountisi, no copia la contabilidad.
- **Generar factura:** desde la pantalla de facturación eliges un **cliente** y las semanas aprobadas. El sistema crea la factura en Accountisi a nombre del cliente, con líneas por servicio y horas, y escribe el **proyecto en el comentario/nota de la factura**. **[Confirmar]** Hay que verificar en el esquema de `ar.Invoice` cuál campo recibe ese comentario.
- **"A qué factura":** **[Confirmar]** Lo entiendo como: al facturar puedes elegir **crear una factura nueva** o **añadir las horas a una factura abierta** del mismo cliente. Dime si era otra cosa.
- Las horas facturadas guardan la referencia de la factura y la tarifa congelada; Accountisi las vuelve inmutables.
- Pago al consultor (cuenta por pagar con retención e IVU): fase posterior, activable aparte.

### 6.2 Excel para Consultorio 1500

- Exportación de un archivo `.xlsx` con las semanas aprobadas de un período, listo para importar en Consultorio 1500.
- **Necesito el formato:** un Excel de ejemplo que Consultorio 1500 importe hoy (o la lista de columnas) y a qué pantalla de Consultorio entra. No encontré el código fuente de Consultorio en esta máquina, solo su base de datos, así que no puedo deducir el formato.
- Se registra cada exportación (período, fecha, usuario, hash) para no exportar dos veces lo mismo.

### 6.3 Futuras

Contabilidad de terceros, correo de salida propio, nómina. Mismo molde de conector.

## 7. Modelo de datos mínimo

Empresa (con marca y reglas) · Usuario · Cliente · Proyecto · Semana · LíneaDeSemana · Aprobación (token, versión, decisión, auditoría) · TérminosConsultor (tarifa, retención, IVU) · DocumentoGenerado · Integración (tipo, configuración cifrada, estado) · VínculoExterno (entidad local ↔ id en Accountisi) · BitácoraDeExportación.

## 8. Pendientes de ti

1. Ejemplo del Excel de importación de Consultorio 1500.
2. Confirmar si "a qué factura" significa nueva o añadir a una abierta.
3. ¿Aceptas añadir una credencial de servicio (API key) a Accountisi?
4. ¿El primer corte incluye la factura PDF del consultor hacia ti, o solo registrar, aprobar y facturar al cliente vía Accountisi?
5. Tecnología: sugiero .NET con SQL Server, como Accountisi, para correr en el mismo servidor. Dime si prefieres otra.

---

# Adenda v0.2 (2026-10-24) — requisitos nuevos de Luis

El mockup no trae especificaciones de tecnología (su página de notas de diseño no está en el archivo). La tecnología sigue abierta; ver sección 5.

## 1. Aprobaciones atrasadas y aprobación en lote

- A los 7 días sin decisión (configurable) se envía al aprobador un recordatorio con un enlace a **una pantalla con todas sus hojas pendientes**, cada una con su detalle (persona, semana, horas por proyecto) y botones Aprobar / Devolver por hoja, más **Aprobar todas**.
- El enlace del lote pertenece al aprobador, no a una hoja. Al decidir, el sistema revisa la versión de cada hoja; si una cambió después de enviarse el email, no se aprueba y se marca para revisión individual.
- "Aprobar todas" excluye las hojas sobre el monto de segunda aprobación. Devolver siempre exige comentario, hoja por hoja.
- Como el lote es una credencial más poderosa, pide un código de 6 dígitos enviado al mismo correo antes de aprobar en masa.
- Recordatorios repetidos cada N días y aviso al dueño de los aprobadores que no responden.

## 2. Aplicación móvil (Android e iPhone)

- Registrar horas, ver el historial de la semana y **reportes básicos**: horas por proyecto, por cliente, por período, facturable contra no facturable.
- **Reloj**: botón "empezar" y "terminar". El reloj es una **hora de inicio guardada**, no un proceso que corre; el tiempo transcurrido se calcula al mirarlo. Por eso sobrevive al cierre de la app.
  - Android: notificación permanente con Pausar / Parar.
  - iPhone: Live Activity en pantalla de bloqueo con el contador.
- **Lugares de trabajo**: se asocia un proyecto a un lugar (geocerca). Al llegar, notificación local: "Estás en [lugar]. ¿Prendo el reloj para [proyecto]?" con un toque para aceptar. Requiere permiso de ubicación "siempre". iPhone permite hasta 20 geocercas a la vez.
- Funciona sin conexión: guarda local y sincroniza después.
- Una sola aplicación para todas las empresas; la marca (logo y colores) se carga del servidor al iniciar sesión.
- Descartada la web instalable (PWA): en iPhone no puede vigilar un lugar en segundo plano ni mantener el reloj en notificaciones.
- Unidad base del modelo: la **entrada de tiempo** (fecha, proyecto, inicio y fin opcionales, horas, nota). La hoja semanal agrupa entradas y es lo que se somete y aprueba. Así el reloj y la cuadrícula del mockup conviven.

## 3. Plantillas de exportación

- Las plantillas definen archivo (xlsx o csv), columnas, orden, encabezados, formato de fechas y horas, filtros y agrupación. Se crean y editan en la app y se pueden exportar desde la móvil.
- Plantilla inicial **Consultorio 1500**, creada en la instalación. Sigue pendiente el Excel de ejemplo que esa aplicación importa hoy.

## 4. Integración con Accountisi

- **Credencial de servicio (API key)** en Accountisi: aceptada. Se diseña y se agrega allá con permisos acotados (`TIME.*`, `AR.INVOICE.CREATE`, `AP.BILL.CREATE` o equivalente). **[Confirmar]** nombres reales de permisos al leer el código de la API.
- **Factura de venta:** desde el timesheet eliges cliente, período o semanas, y se crea la factura en Accountisi. El proyecto va como comentario de la factura.
- **Nivel de detalle por proyecto:** cada proyecto declara cómo se factura: solo horas totales, horas por día, o con hora desde y hasta. Eso decide qué datos pide la pantalla de facturar y qué escribe en las líneas.
- **Primer corte, factura del proveedor (consultor):** el consultor genera su factura PDF desde sus semanas aprobadas. Llega a Luis por email y entra a Accountisi como **cuenta por pagar en borrador** para que Luis la revise antes de postearla. **[Confirmar]** que la API de bills admite borrador.

## 5. Tecnología — pendiente de decisión

Ver la discusión en la conversación. Opciones sobre la mesa: PHP (Laravel) con MySQL/MariaDB para alojamiento barato, o PostgreSQL con un servidor propio. La integración con Accountisi es por API HTTP, así que no obliga a .NET.

---

# Adenda v0.3 (2026-10-24) — tecnología decidida y reglas de la hoja

## Tecnología (decidida por Luis)

- **Servidor:** PHP con Laravel y MariaDB/MySQL. Hecho para correr en hosting compartido barato (GoDaddy) y también en un servidor propio.
- **Aplicación móvil:** React Native (Luis ya lo tiene). Cuenta de Google Play ya pagada. Falta cuenta de Apple Developer (unos 99 USD al año) para iPhone.
- La app móvil usa **la misma API** que la web. No guarda lógica propia de aprobación: pide todo al servidor.
- Correo de salida: servicio de envío (SES, Postmark o Brevo), no el correo del hosting.

## Reglas de estado de la hoja

Estados: `borrador → enviada → aprobada | devuelta`. `devuelta → (se corrige) → enviada`.

1. **Aprobada = bloqueada.** Ya no se cambia, ni desde la web ni desde la app móvil. El bloqueo lo impone el servidor, no la pantalla. Lo mismo para una hoja ya facturada.
2. **Devuelta:** el consultor ve el comentario del aprobador, corrige y la vuelve a enviar a aprobación, desde la web o desde el móvil.
3. **Enviada y cambiada antes de aprobarse:** se cancela el enlace anterior y se manda al aprobador un email nuevo que dice "Esta hoja cambió desde el último envío", con lo que cambió (antes y después). La hoja sigue en `enviada`.
   - Para no mandar cinco emails si el consultor corrige varias líneas seguidas, el aviso sale 10 minutos después del último cambio (configurable). **[Confirmar]**
4. **Crear y enviar desde el móvil:** el consultor crea la hoja y toca "Enviar a aprobación". Mismo resultado que en la web.
5. **Sin conexión:** si el consultor edita en el móvil sin señal y mientras tanto la hoja se aprobó, el servidor rechaza el cambio y la app le avisa. Los cambios sin enviar no se pierden: quedan como una hoja de ajuste.
6. Cada hoja guarda un número de versión y una bitácora: quién la cambió, cuándo, qué cambió y desde qué canal.
7. **Cambio de lo que había en v0.1:** se elimina "reabrir una hoja aprobada". Si algo estaba mal en una hoja aprobada, se registra un **ajuste** en la hoja de la semana siguiente (horas positivas o negativas, con motivo), que también se aprueba. **[Confirmar]**

---

# Adenda v0.4 (2026-10-24) — formato de importación de Consultorio 1500

Fuente: `C:\src\consumo-ai\anthropic\src\Consultorio1500.Data\Importacion\ImportadorExcelService.cs` y `LectorEncabezados.cs`. Lee con ClosedXML, así que no necesita Excel instalado, y el exportador nuestro tampoco.

## Cómo lee el archivo

- Archivo `.xlsx`. Procesa **solo hojas llamadas `Atendidos <año>`** (ej. `Atendidos 2026`). Cualquier otra hoja se ignora.
- La **primera fila usada es el encabezado**. Las columnas se reconocen por **contenido normalizado** (sin tildes, minúsculas, símbolos como espacio), no por posición. El orden no importa.
- Una fila = una visita ya hecha. Produce Paciente + Servicio y, si el plan no es privado, una Reclamación; y si trae montos pagados, un Pago.
- Se omiten filas sin nombre o cuyo nombre empieza con "total". Una fila sin fecha legible se omite con advertencia.

## Columnas que reconoce

| Campo | Encabezado debe… | Obligatorio |
|---|---|---|
| Paciente | contener "asegurado", o ser exactamente "nombre" | **Sí** |
| Fecha del servicio | contener "fecha" (si no, una columna exactamente "mes") | **Sí** |
| Contrato | contener "contrato" | No |
| Plan médico | contener "plan" y "medico" (vacío = plan Privado) | No |
| Facturado con plan | contener "facturada" y "con plan" | No |
| Facturado sin plan (privado) | contener "facturada" y "sin plan" | No |
| Recargo por cancelación | contener "recargo" y "cancelacion" | No |
| Copago | exactamente "copago" | No |
| Pagado por el plan | contener "pagado" y "plan", sin "copago" | No |
| Retención de Hacienda | contener "hacienda" | No |
| Descripción | exactamente "descripcion" | No |

Fechas: celdas de fecha de Excel (o número de serie). Montos: números.

## Qué significa para el timesheet

1. **Consultorio 1500 no maneja horas.** Lo que importa son visitas con paciente, plan, contrato y monto. La plantilla "Consultorio 1500" tiene que producir esas filas, no un listado de horas.
2. Mapeo propuesto **[Confirmar]**: el *cliente o proyecto* del timesheet = paciente; la fecha de la entrada = fecha del servicio; plan médico y número de contrato = campos adicionales del proyecto; monto facturado = horas × tarifa del proyecto (puesto en "Facturada con plan" o "sin plan" según el plan); descripción = nota de la entrada.
3. **Duplicados:** el importador descarta una visita con el mismo paciente, fecha y monto. Dos sesiones el mismo día con el mismo monto se perderían. Solución: la plantilla agrupa por paciente y día y suma el monto.
4. Se puede importar varias veces el mismo archivo sin duplicar, así que reexportar un período es seguro.
5. El nombre de la hoja debe llevar el año (`Atendidos 2026`); si el período cruza años, una hoja por año.
6. Consultorio asigna la oficina al importar (la elige quien importa), no viene en el archivo.

## Sobre generar el .xlsx

No hace falta Excel en ninguna máquina. El servidor Laravel genera el archivo con una librería (PhpSpreadsheet u OpenSpout) y la app móvil lo descarga y lo comparte. Exportar sin conexión desde el móvil queda para una fase posterior (se puede generar local con una librería de JavaScript).

## Móvil sin conexión

La app guarda local entradas y hojas, marca lo pendiente y sincroniza al reconectar. El servidor decide los conflictos; ver reglas de v0.3.

---

# Adenda v0.5 (2026-10-24) — app móvil estilo "Time Recording", total por día y empleados

## 1. Referencia: Time Recording (com.dynamicg.timerecording 7.93)

Analicé el APK sin instalarlo (manifiesto, permisos y textos). No vi sus pantallas, solo lo que dicen sus recursos. Es una app de "punch" con estas piezas, que adoptamos como guía de uso (diseño propio; no se reutiliza su código, nombre ni imágenes):

- **Punch** grande para iniciar y parar; la tarea actual y el tiempo corriendo.
- **Tareas** (= nuestros proyectos) con cambio rápido de tarea ("Switch task").
- **Día** con lista de entradas y **total del día**, **vista de calendario** para corregir.
- **Semana y período de reporte** con meta diaria, semanal y mensual, y aviso al alcanzarla.
- **Reportes:** lista, subtotales por tarea o fecha, total general, formato decimal, tarifa por hora que se puede cambiar por tarea.
- **Exportar** a CSV y Excel; **respaldo** local automático.
- **Lugares (geocerca):** al entrar o salir de un lugar propone o hace el punch, con notificación.
- **Widget** y notificación para parar sin abrir la app; recordatorio de "check-in".
- Descartamos por ahora: pausas automáticas, sincronizar con calendario, plugins, anuncios.

## 2. Pantallas de la app móvil (React Native)

1. **Hoy:** botón Iniciar/Parar, proyecto actual, tiempo corriendo, entradas del día, total del día, botón "Agregar entrada" y "Enviar semana a aprobación".
2. **Semana:** cuadrícula proyecto × día con totales, estado de la hoja (borrador, enviada, aprobada, devuelta con el comentario), botones Enviar y Reenviar.
3. **Calendario:** toque en un día para ver o corregir sus entradas.
4. **Proyectos:** lista con cambio rápido, lugar asociado, tarifa (solo se ve la propia).
5. **Reportes:** por día, por proyecto, por período; subtotales y total; horas o decimal; compartir o exportar.
6. **Exportar:** elegir plantilla (la de Consultorio 1500 viene creada), período y compartir el .xlsx. Sin conexión queda para fase posterior.
7. **Ajustes:** lugares, recordatorios, modo de captura, respaldo, cuenta.

Funciona sin conexión: todo se guarda local y se sincroniza al reconectar (reglas de v0.3).

## 3. Dos modos de captura: por reloj o total por día

- **Por reloj / horas:** entradas con inicio y fin (o duración).
- **Total por día:** una sola cifra por proyecto y día (por ejemplo 8.0 horas), sin hora de inicio ni de fin.
- Se elige **por persona** (un empleado de 8 horas diarias casi siempre usa total por día) y puede **forzarse por proyecto**.
- Un proyecto cuya factura exige "hora desde y hasta" (v0.2 sección 4) **no admite total por día**; la app lo avisa al crearlo.
- Ambos modos producen las mismas hojas semanales y pasan por la misma aprobación.

## 4. Empleados y consultores

| | Consultor | Empleado |
|---|---|---|
| Registra horas | Sí | Sí |
| Aprobación de hoja | Sí | Sí, igual |
| Factura PDF hacia ti / cuenta por pagar en Accountisi | **Sí** | **No** |
| Retención de servicios e IVU | Sí | No |
| Se le paga por | factura | nómina |
| Tarifa que se guarda | tarifa de facturación | tarifa por hora o sueldo |
| Horas extra | no aplica | regla por empresa (después de 40 h/semana u 8 h/día, factor 1.5) **[Confirmar]** |

- El tipo (consultor o empleado) es un atributo de la persona, no un rol aparte; los permisos de captura y aprobación son los mismos.
- Las horas de un empleado **pueden seguir siendo facturables al cliente final** (tú facturas a tu cliente el trabajo de tu empleado), pero el empleado nunca genera factura.

## 5. Nómina hacia Accountisi

Hallazgo: **Accountisi no tiene módulo de nómina**. Su propio diseño de tiempo dice que la nómina quedó fuera por decisión del cliente. Por eso:

- **Primer corte (propuesta):** al cerrar un período de pago (semanal, quincenal o mensual, configurable), el timesheet genera un **resumen de nómina**: por empleado, horas regulares, horas extra y **pago bruto** (horas × tarifa). Se puede ver, exportar a Excel o CSV y enviar a Accountisi como **asiento de diario en borrador** (gasto de sueldos contra sueldos por pagar, usando `/api/journal`) para que lo revises antes de postear.
- **No calcula** retenciones del empleado ni contribuciones patronales. Eso es de un módulo de nómina propio, que es una decisión aparte y grande. **[Confirmar]**
- Solo se incluyen hojas aprobadas de empleados que no se hayan pagado antes en otro período; cada hoja guarda a qué corrida de nómina pertenece, igual que una hoja guarda su factura.

## 6. Pendientes nuevos

1. ¿Los empleados cobran por hora o con sueldo fijo? ¿Hay horas extra y con qué regla?
2. ¿Frecuencia de pago de nómina (semanal, quincenal, mensual)?
3. ¿Para el primer corte basta el resumen de nómina y el asiento en borrador, sin retenciones?
4. ¿Cuáles pantallas de Time Recording te gustan más? Con 2 o 3 capturas ajusto el diseño a lo que ya usas.

---

# Adenda v0.6 (2026-10-24) — captura por usuario, sin nómina, pantallas de entrada

Esta adenda **reemplaza** de v0.5: la sección 3 (modos de captura), la sección 4 (empleados) y la sección 5 (nómina). Todo lo demás de v0.5 sigue.

## 1. Modo de captura: lo decide el dueño, por usuario

Cada usuario (consultor o empleado) tiene un ajuste que fija el **dueño**:

| Modo | El usuario puede… |
|---|---|
| **Día completo** | Entrar un total por día por proyecto (ej. 8.0). No pide hora desde ni hasta. |
| **Desde / hasta** | Solo entrar entradas con hora de inicio y fin (o con el reloj). Se exige. |

- Es independiente del proyecto y del tipo de usuario.
- Un proyecto cuya facturación exige hora desde y hasta (v0.2) solo acepta usuarios en modo "desde/hasta". Si alguien en "día completo" intenta cargarlo, la app lo avisa y no deja guardar.
- El usuario no puede cambiar su propio modo.

## 2. Empleados, sin nómina

- **Se elimina la nómina** (resumen, asiento de diario, corridas, horas extra con factor). Tampoco se define cómo se les paga: eso se decide al generar la factura del período.
- El empleado sigue siendo un tipo de usuario: registra, somete y su hoja se aprueba igual. **No genera factura.** Sus horas pueden facturarse a un cliente final.
- Campo nuevo en el empleado: **horas de trabajo por semana** (por omisión 40).
- **Aviso, no bloqueo:** si las horas de la semana pasan de ese número, la app y la hoja muestran una advertencia ("pasa de 40 h"). El aprobador la ve en el email y en la pantalla de aprobación. No cambia ningún cálculo; queda para uso futuro.

## 3. Exportar

- Plantillas de exportación (v0.2 y v0.4) siguen como están. La de **Consultorio 1500** viene creada en la instalación; el usuario puede crear otras con columnas, orden, encabezados y filtros propios.
- Formato `.xlsx` generado en el servidor; no necesita Excel instalado.

## 4. Pantallas de entrada de Time Recording (vistas en un emulador)

Instalé el APK en un emulador de Android (ya cerrado y desinstalado) y recorrí la entrada de datos. Lo que hace:

1. **Pantalla Día.** Seis botones en cuadrícula de 2 columnas arriba: *Check in now*, *Add check-in*, *Check out now*, *Add check-out*, *Switch task*, *Template*. Los que no aplican se ven grises (con la sesión abierta, *Check in now* se apaga y *Check out now* se prende).
2. **Lista del día** debajo, con columnas **Entrada · Salida · Total · Tarea**, un ícono de nota por fila, "Click to change" para asignar la tarea, y el **total del día** en negrita debajo. Un menú de tres puntos por fila.
3. **Nota del día** en una franja amarilla fija abajo.
4. **Barra inferior** con tres vistas: **Día · Semana · Mes**.
5. **Agregar entrada** (*Add check-in*): abre un reloj analógico grande con la hora actual; se puede pasar a teclado; Cancelar / OK. Es el mismo diálogo para la salida.
6. **Cambiar de tarea con sesión abierta:** avisa "Ya entraste a las 8:19, no se puede iniciar otra tarea" y ofrece dos caminos: *poner nueva hora* (con botón +01 para sumar minutos) o *cambiar la tarea actual*.
7. **Vista Semana** (rango 18.10–24.10): filtro "Tarea: Todas | Matriz de tareas", una tabla por día con subtotal y un **Total** de la semana abajo; flechas **<< >>** para cambiar de semana y **Cerrar**.
8. Botón de deshacer (flecha curva) en la barra superior después de una acción.

Nota: una entrada con la misma hora de entrada y salida suma 00:00; el reloj no pide nada más. Es el tipo de entrada rápida que querías.

## 5. Cómo lo llevamos a nuestra app móvil

- **Misma estructura:** pantalla Día con botones grandes en cuadrícula, lista Entrada/Salida/Total/Proyecto, total del día, nota del día, barra Día · Semana · Mes. Se conserva esa disposición porque ya la usas.
- **Usuario en "Día completo":** la pantalla Día cambia: en vez de los botones de reloj, ofrece *Agregar horas* (teclado numérico con un total por proyecto), *Cambiar proyecto*, y la lista muestra Proyecto · Total del día. El reloj no aparece.
- **Usuario en "Desde/hasta":** igual a Time Recording, con el reloj y el diálogo de hora.
- **Aprobación:** la vista Semana incluye el estado (borrador, enviada, aprobada, devuelta), el comentario de devolución, y el botón *Enviar a aprobación*, que no existe en Time Recording.
- **Lo que mejoramos:** el proyecto se pide al entrar (no queda "Click to change" vacío), y el cambio de proyecto con sesión abierta cierra la anterior y abre la nueva en un solo paso.
- Diseño visual propio: colores y logo de la empresa; solo se imita la disposición.

## 6. Pendientes

1. Confirmar si cada cliente o proyecto de Consultorio 1500 es un paciente.
2. Confirmar si se vende instalado en el cliente o alojado por ti.
3. ¿Los proyectos se eligen de una lista fija que administra el dueño, o el usuario puede crear los suyos?

---

# Adenda v0.7 (2026-10-24) — decisiones cerradas

1. **Proyectos:** los administra solo el **dueño de la compañía**. Los usuarios eligen de esa lista; no crean proyectos.
2. **Consultorio 1500:** cada paciente es un proyecto (un "proyecto de paciente"). El plan médico y el número de contrato son campos del proyecto. Cierra la pregunta de v0.4.
3. **Alojamiento:** no condiciona el diseño. Un solo código, con la empresa (`company_id`) en todas las tablas y la configuración (base de datos, correo, URL de Accountisi) en el archivo de entorno. Corre igual alojado por Luis o en el hosting de un cliente.
4. **Marca:** nada está fijo en el código. El nombre, el logo y los colores son datos de cada compañía. La empresa de Luis es **Cerevelo**; EBVS es un cliente. El mockup de EBVS solo sirve de ejemplo de pantallas. Los colores y el logo de Cerevelo se cargan al configurar la cuenta; hasta entonces la app usa una marca neutra.

---

# Adenda v0.8 (2026-10-24) — pantalla de marca

La marca se cambia desde la aplicación, sin tocar código ni volver a publicar la app móvil.

- **Configuración → Marca**, solo para el dueño de la compañía: nombre comercial, logo (subir imagen; versión para fondo oscuro opcional), color principal, color de acento, remitente y texto al pie de los emails.
- **Vista previa** al cambiar (pantalla de entrada, botón, email de aprobación y factura PDF) antes de guardar.
- El cambio se aplica a todo a la vez: web, emails de aprobación, PDF de facturas y app móvil. La app móvil lee la marca del servidor al abrir; no hay que instalar nada nuevo.
- Se validan los colores (que el texto se lea sobre ellos) y el tamaño y tipo del logo.
- Si mañana lo usa EBVS: se crea su compañía (o se edita la marca de la existente) y se ponen su logo y sus colores en esa pantalla. Cada compañía tiene la suya.
- Se guarda el historial de cambios de marca (quién y cuándo), con opción de volver a la anterior.

---

# Adenda v0.9 (2026-10-24) — lo construido

Ver `README.md` para ejecutar, probar y poner en producción.

- **Servidor** (`server/`, Laravel 13 + MariaDB): captura con modo por usuario, hojas con estados y bloqueo al aprobar, aprobación por email y en lote (códigos de 6 dígitos), recordatorios por cron, marca editable con historial, plantillas de exportación (Consultorio 1500 verificada con el importador real de esa aplicación), factura del consultor en PDF, integración con Accountisi, web en `/app`. 49 pruebas; también pasan en MariaDB 11.4.
- **Móvil** (`mobile/`, Expo 52): Día, Semana, Mes, Reportes y Ajustes; reloj; sin conexión con sincronización idempotente; exportación; marca desde el servidor. 20 pruebas de la lógica. Pantallas probadas en su versión web.
- **Sin probar:** notificación fija del reloj con botón Parar, avisos al llegar a un lugar y recordatorio semanal (necesitan teléfono y compilación EAS); integración con la API real de Accountisi (solo respuestas simuladas); Live Activity de iPhone (no hecha).
- **Decisión de seguridad:** no se aceptan logos SVG (pueden llevar scripts).

---

# Adenda v1.0 (2026-10-24) — hoja de tiempo para el cliente

Dash no tiene un informe aparte: la "hoja" es su lista de horas (empleado, proyecto, cliente, fecha, total y descripción) que se
imprime o exporta. Aquí se genera desde la app: por cliente y período, con la descripción de cada entrada, subtotales por proyecto
y total; PDF con la marca, Excel y envío opcional por email. Solo aprobado y facturable por omisión, sin tarifas. Ver README.

---

# Adenda v1.1 (2026-10-24) — exportar tablas

Toda tabla de la **web** se exporta a CSV, Excel y PDF; Excel y PDF con el logo y los colores de la compañía. La app móvil no
exporta tablas (solo las plantillas de exportación, como Consultorio 1500). Ver README.

---

# Adenda v1.2 (2026-10-24) — se exporta lo filtrado

Las tablas de la web tienen filtros y la exportación (CSV, Excel, PDF) usa exactamente los mismos: solo salen las filas visibles, con totales recalculados y el filtro escrito en el encabezado. Las reglas de búsqueda están duplicadas a propósito en `app.js` y en `Datasets.php`; se verificó en pantalla que dan las mismas filas.

---

# Adenda v1.3 (2026-10-25) — Docker para desarrollo

`docker-compose.yml` (raíz) y `server/Dockerfile`: app (PHP 8.4 + Apache, puerto 8180), MariaDB 11.4 (3380), Mailpit para ver los emails (8125) y el programador de tareas. El código se edita en Windows y se ve dentro del contenedor. Las pruebas corren en una base aparte (`tiempo_test`) y se niegan a correr contra otra. No toca los contenedores `sephas-hud-search-api` ni `teikem-sql-local`.

---

# Adenda v1.4 (2026-10-25) — producción en Docker

- **HTTPS:** Caddy con Let's Encrypt (gratis, automático) en el mismo servidor; el dominio en Cloudflare con la nube gris (solo DNS).
  La aplicación confía en el `X-Forwarded-Proto` de Caddy (`TRUSTED_PROXIES`) y fuerza `https` en los enlaces si `APP_URL` es https.
- **Correo:** Brevo por SMTP; comando `tiempo:probar-correo` para comprobar la configuración.
- **Login:** bloqueo por correo+IP tras 5 fallos (15 min), límite de peticiones por IP y Cloudflare Turnstile en el login web (opcional).
  La app móvil no puede mostrar Turnstile: su login se protege con el límite y el bloqueo.
- **Programador:** contenedor `scheduler` y, además, `POST /api/cron/run` con `X-Cron-Key` para dispararlo desde fuera (cron, EventBridge,
  Hangfire de Accountisi); los recordatorios salen una vez al día.
- **Imagen de producción** (`server/Dockerfile.prod`, `docker-compose.prod.yml`, `deploy/Caddyfile`, `.env.prod.example`) y **respaldo/restauración**
  (`scripts/backup.sh`, `scripts/restore.sh`); la misma `APP_KEY` es obligatoria para poder leer lo cifrado.
- Probado en local: la pila completa (Caddy con certificado interno, migraciones, login, cookies seguras, cron externo) y un ciclo de desastre y
  restauración. No probado: la emisión real de Let's Encrypt (necesita un dominio público), el envío real con Brevo y Turnstile con llaves reales.

---

# Adenda v1.5 (2026-10-25) — servicios, tarifas guardadas, dos idiomas y diseño del mockup

- **Servicios dentro del proyecto** (como en Time Recording). Cada proyecto tiene siempre al menos un servicio activo; al crear un proyecto se crea "General". En la entrada la persona escoge proyecto y servicio; si el proyecto tiene un solo servicio activo, se escoge solo y el servicio es obligatorio. Los servicios no se borran, se desactivan.
- **El servicio define** la tarifa de venta (vacía = la del proyecto), si es facturable, cómo se entran las horas (`detail` = desde/hasta y reloj, `total` = total de horas del día, `both` = lo decide la preferencia de la persona) y el artículo de Accountisi (`accountisi_item_id`, que usa la factura de venta; si no tiene, el artículo por omisión de la integración).
- **Preferencia de entrada de la persona** (`users.capture_mode`, antes "modo de captura"): solo se usa cuando el servicio permite ambas formas. La fija la gerencia; la persona no la cambia. Modo efectivo: servicio `detail` → desde/hasta; `total` → total del día; `both` → la preferencia.
- **Tarifas guardadas en la entrada**: al crearla, cada entrada guarda `bill_rate` (venta) y `pay_rate` (consultor). Cambiar la tarifa o el modo de un servicio solo afecta entradas nuevas. Al aprobar una hoja se completan las que falten (datos viejos). Las facturas del consultor, las de venta, la hoja para el cliente y las exportaciones usan esas tarifas guardadas.
- **Migración**: cada proyecto existente recibe el servicio "General" en modo `both` y sus entradas se enlazan a él; las tarifas de las entradas existentes se llenan con las que había.
- **Dos idiomas, español e inglés**, en web, servidor y móvil. Cada persona escoge el suyo (`users.locale`; botón en el encabezado de la web y ajuste en el móvil); si no, vale el de la compañía (`companies.locale`, por omisión inglés, se cambia en Reglas). Los correos y documentos salen en el idioma de quien los recibe (los externos y la hoja para el cliente, en el de la compañía). Los encabezados de la plantilla Consultorio 1500 se quedan en español porque ese programa solo los reconoce así.
- **Web**: encabezado y contenido a todo el ancho, seis pestañas con pastillas dentro de la página, pantalla "Todas las semanas", aprobaciones en dos paneles, reglas agrupadas, estados en pastillas y el mismo tipo de letra y estilos del mockup (IBM Plex Sans y Mono). El marco de cada pantalla aparece al instante y los datos llegan después.
- **Móvil**: pantalla Día con proyecto y servicios debajo del día, "Entrar ahora / Salir ahora" con reloj, "Agregar entrada" (desde/hasta) o "Agregar horas" (total) según el servicio, y margen inferior para la barra de navegación del teléfono.

---

# Adenda v1.6 (2026-10-26) — cuentas de aprobador, contraseñas, dominios y ícono

- **Nadie maneja contraseñas ajenas.** Al crear a una persona no se escribe contraseña: recibe una invitación por correo (72 h, un solo uso) para crear la suya. Si el dueño la restablece, la anterior deja de servir, se cierran sus sesiones y sus equipos de confianza, y le llega un enlace (24 h) para crear una nueva. Todo queda anotado en `security_events` (quién, a quién, cuándo y desde dónde; nunca una contraseña). Cada persona cambia la suya en "My account" y debe escribir la actual. Sin recuperación por cuenta propia: si no la recuerda, llama al dueño. Si el dueño se queda sin acceso: `php artisan tiempo:resetear-clave correo`.
- **Aprobador con cuenta (opcional).** En Aprobadores, la casilla "Can sign in" crea una cuenta (rol `approver`) con el MISMO correo del aprobador; solo crea su contraseña. Al entrar por la web recibe un código de 6 dígitos por correo (10 minutos, 5 intentos). "Recordar este equipo" es configurable en Reglas (`remember_days`, 0 = apagado). Dentro solo ve Aprobaciones (Pending, Approved, Returned, All) y Mi cuenta. El código al aprobar se pide solo si el aprobador tiene marcado "Pedir siempre código" o la hoja pasa del límite de horas. Los enlaces de los correos siguen funcionando igual. Los aprobadores no entran por la app móvil.
- **Un dominio por compañía** (`companies.domain`). El login toma la marca de la compañía según el dominio, una persona solo entra por el dominio de su compañía y los enlaces de los correos usan ese dominio. `GET /api/domain-check?domain=` sirve para que Caddy emita el certificado de cada dominio nuevo (ejemplo `on_demand_tls` comentado en `deploy/Caddyfile`). Cada compañía nueva necesita su registro DNS y su dominio en Reglas; en Cloudflare Turnstile hay que añadirlo a la lista del widget.
- **Móvil con servidor fijo al compilar** (`EXPO_PUBLIC_SERVER_URL` o `extra.serverUrl`; ejemplo en `mobile/eas.json`): una compilación por compañía. El login no muestra el servidor (solo en desarrollo, en una sección plegada) y en producción solo acepta https.
- **Ojito** para ver la contraseña en todos los campos (web y móvil). En la web se oculta el botón nativo de Edge para que no salga doble. En Integración solo muestra lo que se escribe, nunca lo guardado.
- **Ícono**: el reloj de arena de `branding/` es el favicon y el ícono de instalación de la web y el ícono de la app (Android adaptable e iOS).

---

# Adenda v1.7 (2026-10-26) — edición, marca, acceso y buscador móvil

- **Editar Aprobadores y Clientes** en la web (como Proyectos y Personas). Un proyecto **facturable** exige cliente (el servidor responde 422 en `customer_id`); los no facturables pueden quedar sin cliente. La hora se asocia al proyecto y el cliente sale del proyecto, por eso el móvil no pide cliente.
- **Marca**: casilla "Mostrar el nombre junto al logo" (`companies.show_brand_name`, `brand.show_name`); apagada, el encabezado y las pantallas de entrada muestran solo el logo. Los colores se escriben en hexadecimal o en R, G, B, sincronizados con el selector, con el contraste calculado en pantalla. El servidor solo exige contraste (4.5) a un color que se cambia; uno ya guardado no bloquea el resto del formulario, y un color vacío significa "no cambiar".
- **Acceso** (tarjeta "Sign-in" de Reglas): `remember_days` = días sin usar antes de volver a pedir la contraseña, igual para todos (web, móvil y aprobadores; para aprobadores, también el tiempo que un equipo de confianza se salta el código). El vencimiento es deslizante por inactividad (`SessionExpiry`, token vencido → 401 `session_expired`). Con **0 = no recordar**: la web guarda la sesión solo mientras el navegador está abierto (sessionStorage; respaldo del servidor de 12 h sin uso) y el móvil pide la contraseña cada vez que se abre. Sin señal, el móvil entra en **modo limitado**: solo permite anotar horas; al volver la señal pide la contraseña y se sincroniza todo.
- **Móvil**: buscador de proyecto escribiendo (sin tildes ni mayúsculas, por código, nombre y cliente; recientes primero; funciona sin conexión).

---

# Adenda v1.8 (2026-10-26) — pantallas como el mockup, aprobación interna y detalle de horas

- **Pantallas pegadas al mockup**: hoja semanal (tabla editable por proyecto, servicio y tipo de hora; aviso ámbar de semanas pasadas solo desde que existe la cuenta; ID NUMBER; reloj como ícono con cuadro de proyecto y servicio; Save draft y Submit con casilla de certificación), Personas (invitar por correo con Worker / Approver / Back office, tabla con 2-STEP y STATUS, términos del consultor) y Aprobaciones (conteos, filtros y columnas). Las confirmaciones salen como avisos pequeños que desaparecen solos; los errores se quedan hasta cerrarlos.
- **Tipos de hora** (`time_entries.hour_type`: reg, ot, hol, sick, vac, nb) solo para empleados; hol, sick, vac y nb no son facturables. **Número de empleado** (`users.employee_number`, único por compañía).
- **Aprobación interna.** Un aprobador interno es un empleado más (supervisor). El proyecto tiene "requiere aprobación interna" y uno o más internos. Al enviar, la hoja va a etapa `internal` y se avisa a los internos; cuando aprueban pasa a etapa `external` y entonces se avisa al aprobador externo, que certifica. Reglas (todas en `InternalApprovalService`, con constantes para cambiarlas): basta uno de varios internos; cada proyecto con interno aprueba su parte; si un interno devuelve, vuelve la hoja completa y al reenviar empieza de nuevo; nadie aprueba sus propias horas; un cambio en la hoja invalida las aprobaciones internas; el dueño puede anular en cualquier etapa. Quien es interno en algún proyecto ve la pestaña Aprobaciones y sale como "Approver" (interno) en Personas.
- **El detalle de horas sigue cómo se entró** (hoja para el cliente, líneas de la factura de venta y detalle del PDF de la factura del consultor): hora por hora si se entró así, total del día si se entró el total. El ajuste por proyecto "Invoice detail" desapareció.

---

# Adenda v1.9 (2026-10-26) — ventanas emergentes, aprobación por servicio e incremento de hora

- **Ventanas emergentes** para añadir y editar en toda la web (personas, aprobadores, clientes, proyectos y sus servicios); solo la factura y los filtros se quedan en la página. Los avisos de la web desaparecen solos (éxito 2.5 s, advertencia 6 s, error 8 s).
- **Aprobación por servicio** (`services.approval_mode`: none, internal, external, both). Los internos siguen siendo una lista por proyecto; `projects.requires_internal_approval` se deduce de los servicios. Al enviar: con partes internas va primero a los internos; si el servicio no requiere externo la hoja queda aprobada con la aprobación interna; si todas las entradas son `none`, queda aprobada sola al enviar (evento `auto_approved`).
- **Incremento de hora aplicado al entrar** (regla `increment`): los minutos de desde/hasta y los totales de horas deben ser múltiplos del incremento; no se redondea en silencio, se avisa (422 en el servidor, aviso en rojo en la web, selector propio con los minutos del incremento en el móvil). Duración mínima = un incremento (`rules.minimum_minutes`). Check out con menos del mínimo pide permiso para registrarlo como entrada del mínimo.
- **Móvil, pantalla Día**: proyecto con flechas bajo la fecha y lupa para buscar; botones en tres filas (Check in now | Check out now, Add entry | Repeat, Copy previous day | Go to today); sin "Change project".

## Adenda v1.10 — Aprobadores por servicio (servidor)
- **Internos por servicio**: `service_internal_approvers` (servicio, usuario). Un servicio `internal`/`both` necesita al menos un interno activo (422 en `internal_approver_ids`); basta que apruebe uno (R1). Las partes internas de una hoja son una por **servicio** (`timesheet_internal_approvals.service_id`; las filas viejas, por proyecto, con `service_id` nulo, cuentan para todos los servicios de ese proyecto).
- **Externos ligados a clientes**: `approver_customer` (aprobador, cliente). Un servicio `external`/`both` escoge entre los aprobadores del cliente del proyecto (`service_external_approvers`); si se omite la lista, la hoja va a todos los activos del cliente. Sin cliente o cliente sin aprobadores: 422 en `external_approver_ids` al guardar (solo si la petición toca la aprobación). Un aprobador escogido que no es del cliente: 422. No se puede quitar un cliente a un aprobador escogido en un servicio de ese cliente (422 en `customer_ids`), ni cambiar el cliente de un proyecto con aprobadores escogidos de otro cliente (422 en `customer_id`).
- **Partes externas** (`ExternalApprovalService`): la etapa externa se reparte en una parte por servicio (`timesheet_external_approvals`). Cada parte la aprueba uno de los externos de ese servicio; cada aprobador recibe un correo solo con sus horas (token por hoja+aprobador), ve en la API y en la página solo sus líneas y su total, y al aprobar afecta solo sus partes. La hoja queda aprobada con todas las partes; cualquier devolución (interna o externa) la devuelve completa (R3); un cambio borra las aprobaciones externas y re-avisa a todos (R5). Las líneas de "qué cambió" se guardan etiquetadas por servicio (`[#id] ...`) para que cada aprobador vea solo las suyas.
- **Resolución en tiempo de ejecución** de una parte externa: (a) lista del servicio; (b) si está vacía, aprobadores activos del cliente; (c) RESPALDO para datos viejos: `projects.approver_id` o, si no, `users.approver_id`. Para internos: lista del servicio; si está vacía (viejos), la lista vieja `project_internal_approvers`. `users.approver_id`, `projects.approver_id` y `project_internal_approvers` se conservan solo para ese respaldo.
- API: ver `ServiceController::payload` (internal/external_approver_ids, *_approvers, external_effective, client), `GET /api/projects/{id}/approver-options`, `customer_ids`/`customers` y `?customer_id=` en aprobadores; la hoja trae `internal[]` y `external[]` (por servicio) y `waiting_for` con `kind`.

# Adenda v1.11 (2026-10-28) — tareas por proyecto y comisiones (tareas y fase 1 construidas; el resto de las comisiones es DISEÑO)

## Lo que ya está construido: tareas
- **Tareas por proyecto** (`tasks`, `time_entries.task_id`). Un proyecto puede no tener tareas. Si tiene al menos una **abierta** (`tasks.active`), la tarea es **obligatoria** en cada entrada de horas (web, móvil y reloj); una entrada vieja que ya estaba sin tarea se puede seguir editando sin ella. Sin tareas abiertas no se muestra ni se pide nada.
- **Completada** en vez de "inactiva": una tarea completada deja de salir al registrar horas y las horas ya registradas la conservan (`completed_on` guarda la fecha). Una tarea **sin horas** se puede borrar (se creó por error); con horas solo se completa.
- **Presupuesto de horas** opcional (`budget_hours`) y horas usadas (solo lo ve el dueño). Solo informa, no bloquea.
- El aprobador ve proyecto · servicio · tarea en la misma línea (pantalla del aprobador con cuenta, interno, revisión del dueño, página del correo y resumen del correo). Las exportaciones de tablas aún no llevan columna de tarea.
- Migraciones: `2026_11_03_000000_create_project_tasks`, `2026_11_04_000000_add_budget_to_tasks`, `2026_11_05_000000_add_completed_on_to_tasks`. Móvil: la tarea se escoge junto a las horas (selector en la pantalla de entrada y pastilla en la pantalla del día).

## Comisiones por productividad (diseño acordado, falta construir)
**Idea:** las horas se pagan **siempre a la tarifa normal** (p. ej. 15 la hora); nunca se "rompe" esa tarifa. Si una tarea se termina bien (a tiempo, ahorrando horas, y la persona hizo su parte), la persona gana una **comisión aparte**, que sale en una línea aparte de la factura.

**Dónde vive:** la comisión es del **trabajador** (empleado o consultor), no de la tarea. En su ficha hay una sección **Comisiones**. **Una por proyecto.** Solo el **dueño** la configura. Campos:
- Proyecto; **servicios** del proyecto que aplican; **tareas** del proyecto que aplican (solo salen las marcadas "aplica comisión").
- **% mínimo del total trabajado en la tarea** que esa persona debe haber hecho (p. ej. 80%). Es del total **real** trabajado, no un número fijo: con 16 h en total son 12.8 h; con 14 h, 11.2 h. Si trabajan dos personas, el dueño configura los porcentajes de cada una (p. ej. 40 y 40); el sistema solo sigue la regla de cada comisión.
- **Extra por hora** (p. ej. 5): se paga por las horas que la persona trabajó en esa tarea, además de su tarifa normal.
- **Bono por ahorro**, configurable: **% del ahorro** que se reconoce (p. ej. 100%) y **monto por hora ahorrada** (p. ej. 5; si no quiere dar tanto, 1).
- **Tolerancia en horas** sobre la meta de ahorro (p. ej. 0.5 h): pasarse por poco de la meta no penaliza.
- **Retención** (sí/no) y **IVU** (sí/no) para esa comisión. Se usan los porcentajes que el consultor ya tiene en sus términos.

**Datos nuevos en la tarea:** **fecha estimada de terminación** (editable; no hay "buffer de días": si hace falta más plazo, se edita la fecha), **meta de ahorro** (%, p. ej. 20) y **aplica comisión** (sí/no).

**Cálculo (al completar la tarea).** Ejemplo: estimado 20 h, meta de ahorro 20%.
- **Condiciones del equipo** (las mismas para todos): (1) total de horas de la tarea ≤ meta + tolerancia de la comisión, con meta = 20 × (1 − 20%) = **16 h**; (2) tarea completada a más tardar en la **fecha estimada**. Si se pasó del plazo, **no hay bono para nadie** (ni comisión ni ahorro).
- **Bono por ahorro (del equipo):** si se cumplen las dos condiciones del equipo, lo gana **toda persona que tenga comisión en esa tarea y haya trabajado al menos 1 hora en ella**, aunque no haya hecho su porcentaje. Cada persona tiene su propio monto por hora ahorrada. Horas ahorradas = estimado − total trabajado (20 − 16 = 4). Bono = horas ahorradas × **parte del ahorro** × monto por hora. La **parte del ahorro** se escoge en cada comisión: un **porcentaje fijo** (p. ej. 100%) o **el porcentaje que le correspondía hacer** (el mínimo configurado; con 40 y 40 el ahorro se reparte en vez de pagarse dos veces completo). *(Por definir: si se ofrece también "el porcentaje que realmente trabajó".)*
- **Comisión por hora (individual):** las condiciones del equipo **más** que sus horas sean ≥ su % mínimo del total trabajado. Comisión = sus horas en la tarea × extra por hora.
- Ejemplo: se completa a tiempo con 16 h en total; Jarvis hizo 13 h (81%) y Ana 3 h (19%), ambos con comisión en la tarea. Con parte del ahorro fija en 100% y monto 5, ambos ganan 4 × 100% × 5 = 20 de ahorro; Jarvis cumple su 80% y gana además 13 × 5 = 65; Ana no llegó a su mínimo y solo gana el ahorro.
- Solo cuentan horas de **hojas aprobadas** (confirmado): los números del cálculo no deben cambiar. El total de la tarea incluye las horas de todas las personas y servicios; las horas propias de la persona se cuentan solo en los servicios marcados en su comisión. Si quedan horas de la tarea sin aprobar, el sistema avisa y la tarea no se puede completar con comisión hasta que se aprueben.
- Si la tarea no tiene presupuesto, no hay comisión que calcular (por eso "aplica comisión" exige presupuesto).

**Completar y liberar.**
- Completan la tarea el **dueño** y los **aprobadores internos** del proyecto. La fecha por defecto es la de las últimas horas de la tarea; quien completa puede cambiarla. Se firma con contraseña.
- Al completar, el sistema **calcula**. Si hay comisión que pagar, **pregunta si se libera**; solo el **dueño** libera. Si el dueño quiere revisarla, la tarea **no se completa todavía**.
- Si completa un aprobador interno (no el dueño), la tarea se completa y la comisión queda **pendiente de liberar** para el dueño.
- El dueño puede **ajustar manualmente** una comisión antes de liberarla (se guarda el cálculo original, el ajuste y una nota con el motivo, y el ajuste se firma). No se ajusta una comisión ya facturada.
- La liberación existe porque la comisión **no siempre se paga de inmediato**. Estados: pendiente → liberada → facturada (o pagada, para empleados que no facturan).
- Si se reabre una tarea, se descarta su comisión (si ya se facturó, no se puede reabrir).

**Factura del consultor.** Sus horas, a su tarifa, como hoy. Las comisiones **liberadas** que aún no se han facturado entran como **una línea aparte por proyecto** (el total globalizado, con el detalle por tarea y por comisión disponible), con retención/IVU según lo marcado en cada comisión. Los empleados no facturan: la oficina necesita un reporte de comisiones por pagar.

**Lo que ve el trabajador (aprobado):** quiere transparencia, no solo "va bien / por debajo". Una **tarjeta por tarea** con la lista de condiciones y cómo va en cada una: meta de horas del equipo (lleva 9.5 h de 16), fecha (faltan 3 días), su parte (lleva 7.6 h = 80% del equipo; necesita 80%) y "si la tarea se completara hoy ganarías: comisión + ahorro". Una vez completada y liberada, el resultado final y su estado. No se muestran nombres ni desglose por persona. Consecuencia aceptada: el total del equipo y su porcentaje le permiten deducir lo que trabajaron los demás en conjunto (con dos personas, lo de la otra). Su comisión aparece en la factura como línea aparte.

**Firma digital con contraseña** (se construye al final, toca muchas pantallas): someter la hoja, aprobar, completar la tarea, liberar/ajustar una comisión y someter la factura. El campo de firma **cambia de nombre cada vez** para que el navegador no lo autocomplete (es una mitigación, un gestor de contraseñas puede ignorarla). Se guarda quién, cuándo, desde qué IP y una huella del contenido firmado, y los informes dicen "firmado/aprobado digitalmente por …". Los **aprobadores externos** (enlace del correo, sin cuenta) firman con el código de 6 dígitos que reciben por correo. En el **móvil**, someter una hoja pasa a exigir conexión.

**Decisiones de diseño tomadas (opiniones):**
- La **tolerancia en horas va en la comisión, no en la tarea**: la meta de ahorro es de la tarea (qué quiere ahorrar), pero cuánto se perdona es un acuerdo con cada persona; así dos personas en la misma tarea pueden tener tolerancias distintas. El buffer de días se descartó.
- **Editar a mano la comisión tiene sentido** (excepciones, errores del cálculo), siempre que quede el cálculo original, el ajuste y el motivo, firmado por el dueño.

**Por definir antes de la fase 2:** (a) la opción "el porcentaje que realmente trabajó" como tercera forma de calcular la parte del ahorro; (b) el reporte de comisiones por pagar para empleados (aprobado: tabla con persona, proyecto, tarea, monto y estado, exportable, y marcar como pagada).

**Fases:** 1) datos de la tarea y flujo de completar — **construida (abajo)**; 2) comisiones (configuración, cálculo, pantalla del trabajador, reporte); 3) línea de comisión en la factura; 4) firma digital con contraseña en las demás pantallas (someter hoja, aprobar, someter factura). Cada fase se puede usar sola.

## Fase 1 construida: datos de la tarea, completar con firma y pantalla Tareas
- **Datos de la tarea** (migración `2026_11_06_000000_task_goals_and_signatures`): `estimated_end` (fecha estimada, editable), `savings_goal_pct` (meta de ahorro) y `commissionable` (aplica comisión; exige presupuesto de horas). Solo los ven el dueño y los aprobadores internos.
- **Completar y reabrir se firman con la contraseña.** `POST /api/tasks/{id}/complete` (`completed_on` opcional, `signature`) y `POST /api/tasks/{id}/reopen`. Ya no se completa editando la tarea (`PUT` ignora `active`). `GET /api/tasks/{id}/completion` da la fecha por defecto (la de las últimas horas, o hoy si no hay) y las horas sin aprobar; `GET /api/tasks?status=open|completed|all` lista las tareas.
- **Quién completa:** el dueño y los aprobadores internos de algún servicio del proyecto (403 para los demás y para las cuentas de aprobador externo). **Reabrir: solo el dueño.** La fecha de completada no puede ser futura.
- **Firma** (`SignatureService`, tabla `signatures`): se verifica la contraseña (`signature`) y se guarda quién, qué (`task.complete`, `task.reopen`), cuándo, la IP y una huella sha256 del contenido; 5 contraseñas malas bloquean 15 minutos. En pantalla el campo de firma **cambia de nombre cada vez** y lleva `autocomplete="new-password"`. La tarea muestra "completada por …". La tabla `signatures` es la base de la fase 4.
- **Web:** el formulario de la tarea lleva presupuesto, fecha estimada, meta de ahorro y "aplica comisión"; completar y reabrir abren una ventana con la contraseña; hay una pestaña **Tareas** (dueño y aprobadores internos) con las tareas abiertas/completadas, horas usadas y sin aprobar.
- **Pruebas:** `tests/Feature/TaskTest.php` (servidor) y una prueba real en navegador (crear tarea con los campos nuevos, firma con contraseña mala y buena, completar, reabrir, pantalla Tareas y hoja semanal con tarea obligatoria).

## Gerente de proyecto: aprobador con vista de Avance (diseño acordado; se construye en una fase posterior)
**Para qué:** el aprobador externo con cuenta suele ser el project manager del cliente, y el dueño se reúne con él cada semana para el estado del proyecto (cuánto vamos, por dónde vamos, qué falta, peticiones). Hoy el aprobador solo ve las hojas con horas suyas que ya salieron de la etapa interna; no ve cómo va el proyecto.

**Cómo se marca:** una marca en el perfil del **aprobador** ("**Gerente de proyecto** / Project manager"), que solo pone el dueño. Un aprobador puede ser gerente de proyecto (no se crea otro tipo de usuario). Requiere que el aprobador tenga cuenta. Ve el **proyecto completo** (todos los servicios) de los clientes a los que está ligado; más adelante se puede afinar por proyecto. Los aprobadores sin la marca, y los que aprueban solo por el enlace del correo, siguen igual.

**Pantalla Avance (solo lectura), por proyecto:**
- Encabezado: horas aprobadas contra el presupuesto del proyecto (porcentaje y barra), horas que quedan, horas de esta semana contra la anterior y hojas que esperan **su** aprobación.
- **Tareas:** nombre, estado (abierta, completada con su fecha, **atrasada** si pasó la fecha estimada), fecha estimada, **presupuesto de horas (el cliente SÍ lo ve)**, horas usadas y avance; en rojo lo atrasado y lo que pasó del presupuesto.
- **Esta semana:** horas por semana de las últimas 8, tareas completadas y tareas nuevas desde la última reunión.
- **Por servicio** y **por persona** (el desglose por persona SÍ se muestra: el cliente ya ve nombres en las hojas que aprueba).
- **Exportar** a PDF o Excel con la marca de la compañía, para llevarlo a la reunión.
- Horas **aprobadas** en firme y, aparte y en un tono más claro, las **pendientes de su aprobación**. Nunca borradores ni hojas aún en la etapa interna.

**Lo que NUNCA ve:** tarifas, lo que se le paga a las personas, comisiones, la **meta de ahorro** y la casilla "aplica comisión" de la tarea (revelan el margen), ni tipos de hora internos (vacaciones, enfermedad, feriados).

**Horas facturables y no facturables (recomendación, por confirmar):** por defecto solo las **facturables** (las que el cliente paga). Opción por proyecto "mostrar el trabajo no facturado como cortesía": si el dueño la activa, esas horas salen **aparte** (columna "No facturadas / cortesía"), nunca sumadas al total facturable, para enseñar el trabajo extra que se hace. Las horas no facturables internas (reuniones internas, administración) nunca se muestran. Advertencia: el cálculo de comisiones usa **todas** las horas de la tarea y la vista del cliente solo lo que se le permite ver; los números pueden diferir por diseño.

**Fuera de esta fase:** las **peticiones y gestiones** de la reunión (una lista o notas compartidas por proyecto entre el dueño y el gerente de proyecto) serían otra función posterior.

**Al retomar las fases, preguntar al usuario** si arrancamos con esta (y confirmar la decisión sobre horas no facturables).
