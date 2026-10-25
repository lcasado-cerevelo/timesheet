# Timesheet — análisis preliminar (2026-10-24)

Objetivo: una aplicación de registro de tiempo que Luis use para sí mismo (él más consultores, sin empleados), pero que cualquiera pueda usar; integrada **directamente con Accountisi** (contabilidad) y **por archivo con Consultorio 1500** (app móvil); con una línea de aprobación similar a la de Dash, más simple.

## 1. Lo que hay en la máquina

| Pieza | Dónde | Qué es |
|---|---|---|
| Diseño actual | `timesheet/EBVS Timesheets Mockup – App mockup · all roles.html` | Mockup interactivo "EBVS Time" (cliente EB Validation Services, Caguas PR). 4 roles, flujo semanal, facturas de contratistas con retención e IVU. |
| Accountisi ("Account Easy") | `C:\Program Files\Accountisi\` — API en `http://localhost:5099` (servicio Windows `AccountisiApi`, corriendo), escritorio WPF `Escritorio\Accountisi.exe`, base SQL Server local (producción: `Cerevelo`) | Contabilidad propia, .NET 10. Tiene **módulo de tiempo** `db\29-time-tracking` (esquema `tm`) y rutas `/api/time`, `/api/projects`. |
| Consultorio 1500 | `C:\Users\Luis\AppData\Local\Consultorio1500\consultorio.db` (SQLite, EF Core) | Facturación médica: pacientes, planes, servicios, reclamaciones, pagos, reconciliaciones por oficina, períodos cerrados. No tiene tiempo ni horas. El zip "consultorio-exportado…" del escritorio está vacío (22 bytes). |
| Dash SaaS | `source/dash/db/dash_db.sql` (MySQL, Laravel/WorkDo) + `source/dash/src` | Lo que usas hoy: tabla `timesheets` por día con flags `validated/billed/invoiced/paid`, `timesheet_approvals` (approved/unapproved), buzones IMAP que crean timesheets desde correos, facturas `invoices`/`invoice_products` con líneas tipo "service". Roles: company, Consultant, Client, Staff… |

## 2. El diseño EBVS vs. lo que tú necesitas

El mockup está pensado para una empresa con empleados W-2 y contratistas. Lo reutilizable:

- Ciclo **semana → someter → aprobar/devolver → facturar → pagar** (estados `draft, submitted, approved, returned, paid`).
- Líneas por proyecto por semana, horas facturables y no facturables, "copiar semana anterior".
- Factura del contratista generada de semanas aprobadas, con **retención de servicios profesionales e IVU** (agente de retención, SURI) — esto es exactamente lo que tus consultores y tú necesitan en PR.
- Reglas configurables: semanas hacia atrás, fecha límite semanal, incremento de hora, máximo por día, enrutamiento de aprobador (por persona / por proyecto), aprobador suplente, días para reabrir, segunda aprobación por monto, prefijo de factura, términos de pago, MFA.

Lo que sobra o cambia para tu caso:

- El rol **Employee (W-2)** y la pestaña "Pay run / Employee hours" (no tienes empleados). Lo que sí hace falta es un rol **Consultor** (= contractor) y tú como **dueño/aprobador/back office** en una sola persona.
- La factura no la hace la app "para EBVS": la hace **el consultor hacia tu empresa** (cuenta por pagar) y tú **hacia tu cliente final** (cuenta por cobrar). Son dos facturas distintas de las mismas horas; Dash hoy solo cubre la segunda.
- Para que "cualquiera lo use": empresa (tenant) + usuarios + clientes + proyectos, sin suponer EBVS ni Caguas.

## 3. Cómo lo hace Dash (línea de aprobación de referencia)

1. El consultor registra entradas por día (`timesheets`: fecha, de/hasta, horas, descripción, proyecto/tarea/cliente opcionales). También entran por correo (IMAP → `timesheet_inbound_*`).
2. Alguien con permiso marca `validated=1` y queda rastro en `timesheet_approvals` (approved / unapproved, quién y cuándo). Se puede des-aprobar.
3. Al facturar, se marcan `invoiced=1` y las horas van como una línea "service" en `invoice_products` (cantidad = horas, precio = tarifa). Luego `paid=1`.

Es simple pero flojo: no hay "sometido" (el aprobador no sabe qué está listo), no hay devolución con comentario, y la factura no apunta a qué horas la componen (solo un flag). Accountisi resuelve justo eso.

## 4. Accountisi ya tiene el modelo que necesitas

`tm.Project` (cliente, servicio por omisión, facturable o no, presupuesto de horas, estado) y `tm.TimeEntry` (usuario, fecha, horas, descripción, facturable, **estado 0 borrador / 1 sometida / 2 aprobada / 3 facturada / 4 rechazada**, `ApprovedBy/At`, `RejectReason`, `BilledRate`, `InvoiceLineId`). Procedimientos: `uspLogTime`, `uspSubmitTime`, `uspApproveTime`, `uspRejectTime`, `uspInvoiceTime`; vistas `vwUnbilled` y `vwProjectSummary`. Permisos `TIME.LOG / TIME.MANAGE / TIME.APPROVE / TIME.INVOICE`.

Sus reglas: la tarifa es la del día trabajado (vía listas de precios por cliente), se aprueba antes de facturar, una hora facturada no se toca (trigger), lo no facturado se ve, lo no facturable también se captura. Eso es "la misma línea de aprobación que Dash, hecha bien".

Lo que Accountisi **no** tiene y el timesheet aporta:

- La **semana** como unidad de trabajo (someter/aprobar por semana, no entrada por entrada), fecha límite, "late", copiar semana anterior, recordatorios.
- La **factura del consultor hacia ti** con retención/IVU (en Accountisi eso es una cuenta por pagar `bills` + `tax.WithholdingType`, no una factura de venta).
- Acceso para consultores externos sin darles usuario de la contabilidad.

## 5. Integración propuesta

### 5.1 Accountisi — directa (API local)

La API ya corre en `localhost:5099` y exige autenticación en todo (`401` incluso en `/api/health`); hay `api/auth/login`, `api/auth/mfa/verify`, `/api/sessions`, `/api/me`, `/api/projects`, `/api/time`, `/api/invoices`, `/api/bills`. No vi API keys: el acceso es por sesión de usuario (`sec.Session`). Por tanto:

- El timesheet se conecta con **un usuario de servicio** de Accountisi (permisos TIME.* y AR/AP) y guarda la sesión; o, más limpio, se le añade a Accountisi una API key por empresa.
- Catálogos maestros viven en Accountisi: **clientes, proyectos, artículos de servicio y tarifas (listas de precios)**. El timesheet los lee; no los duplica.
- Al **aprobar una semana** en el timesheet, se empujan las entradas a `tm.TimeEntry` ya aprobadas (o se someten y aprueban por API). Las horas quedan en `vwUnbilled`.
- **Facturar al cliente** = `uspInvoiceTime` desde Accountisi (o desde el timesheet llamando `/api/time`). La factura sale con la tarifa de la fecha y queda inmutable.
- **Pagar al consultor** = crear en Accountisi la cuenta por pagar (`/api/bills`) con la retención de servicios profesionales y, si aplica, IVU, a partir de la misma semana aprobada. Así la retención sale en el informativo 480 desde la contabilidad y no desde el timesheet.
- Pendiente de confirmar mirando el código fuente de la API: la forma exacta de `/api/time` (POST por entrada o por lote) y si existe hoy un endpoint para `uspInvoiceTime`.

### 5.2 Consultorio 1500 — por archivo

Consultorio 1500 no maneja horas: su unidad es el **servicio** (visita) con cargo, copago, plan y reconciliación por oficina. La integración lógica no es "pasarle horas" sino dos cosas, ambas por archivo porque es app móvil:

- **Exportar desde el timesheet** un archivo (JSON o CSV con cabecera de versión) con las semanas aprobadas de un período, para que Consultorio lo importe como gasto de oficina / honorarios de proveedor dentro de la `ReconciliacionOficina` del período. Hoy su `GastoCalculado` sale de `Oficinas` (monto fijo, porcentaje, visitas incluidas); la hora facturada sería una fuente más.
- **Importar en el timesheet** las visitas de Consultorio (servicios por fecha y oficina) como entradas de tiempo ya hechas, si tú registras tu propio tiempo de consultorio. Formato: el mismo archivo en sentido contrario.
- Hay que definir el formato porque el zip de exportación que existe en el escritorio está vacío; conviene un solo esquema (`timesheet-export-v1.json`) con empresa, período, persona, proyecto/oficina, fecha, horas, importe, estado y un hash para no importar dos veces.

### 5.3 Dash

Dash es la referencia de flujo y, mientras exista, origen de datos: sus `timesheets` + `timesheet_approvals` de 2025-2026 se pueden migrar al nuevo sistema (usuarios Jarvis Rodriguez, Andrea Rivera, clientes Advance Logistics, InnovaSyst…). El buzón IMAP de Dash (horas por correo) es una idea que vale la pena conservar para consultores que no quieran abrir la app.

## 6. Modelo mínimo del nuevo sistema

- **Empresa** (tenant) → **usuarios** con rol *dueño/aprobador*, *consultor*, *cliente (solo lectura)*.
- **Cliente** y **Proyecto** (espejo de Accountisi: guardar `AccountisiCustomerId/ProjectId`).
- **Semana** (persona × semana que termina domingo): estado `borrador → sometida → aprobada | devuelta → facturada → pagada`, comentario de devolución, marca de tarde, quién aprobó y cuándo.
- **Línea** (semana × proyecto × código REG/no facturable): 7 días de horas, descripción.
- **Términos del consultor**: tarifa, % retención, agente IVU, % IVU, términos de pago.
- **Documento generado**: factura del consultor (PDF) y referencias a Accountisi (`BillId`, `InvoiceId`, `TimeEntryId[]`).
- **Reglas** por empresa: las del mockup (semanas atrás, límite, incremento, máximo/día, enrutamiento, reabrir, segunda aprobación, prefijo, MFA).

## 7. Decisiones que te tocan a ti

1. ¿La fuente de verdad de proyectos y tarifas es Accountisi (recomendado) o el timesheet?
2. ¿La factura del consultor hacia tu empresa la genera el timesheet (PDF) y Accountisi recibe el `bill`, o el consultor factura por su cuenta y solo se registra el pago?
3. ¿Mantener la entrada de horas por correo (como Dash) en la primera versión o dejarla para después?
4. ¿Qué viaja hacia Consultorio 1500: honorarios por período para la reconciliación, o nada por ahora y solo importar visitas?
5. Autenticación hacia Accountisi: usuario de servicio con sesión, o añadir API keys a Accountisi.
6. Plataforma: web (como el mockup) con API .NET al lado de Accountisi, misma SQL Server local; los consultores entran por navegador o móvil.
