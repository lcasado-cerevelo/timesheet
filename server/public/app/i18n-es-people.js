/* Spanish for the people screens (People and Approvals). Merges into the main dictionary (loaded after i18n-es.js). */
(function () {
  const d = window.I18N_ES || (window.I18N_ES = { exact: {}, patterns: [] });
  Object.assign(d.exact, {
    // People
    'People & rates': 'Personas y tarifas',
    "Invite users, set roles, and configure each contractor's billing terms.": 'Invita usuarios, asigna roles y configura los términos de cobro de cada contratista.',
    'Invite by email': 'Invitar por correo', 'Full name': 'Nombre completo', 'name@company.com': 'nombre@empresa.com',
    'Worker': 'Trabajador', 'Back office': 'Oficina', 'Send invitation': 'Enviar invitación',
    'All roles': 'Todos los roles', 'Users': 'Usuarios', 'Email': 'Correo', 'ID number': 'Número de ID', '2-step': '2 pasos',
    'Enabled': 'Activado', 'Not set up': 'Sin configurar', 'Disabled': 'Desactivado', 'Close': 'Cerrar',
    'No user matches the filter.': 'Ningún usuario coincide con el filtro.', 'No contractor matches the filter.': 'Ningún contratista coincide con el filtro.',
    'Contractor billing terms': 'Términos de cobro del contratista',
    'Applies to contractors only. Employees have no billing terms: the system records hours, nothing else. Changes affect new invoices, never invoices already submitted.':
      'Aplica solo a contratistas. Los empleados no tienen términos de cobro: el sistema solo registra horas. Los cambios afectan facturas nuevas, nunca las ya enviadas.',
    'Contractor': 'Contratista', 'Rate ($/h)': 'Tarifa ($/h)', 'Retention (%)': 'Retención (%)', 'IVU withholding agent': 'Agente retenedor de IVU', 'IVU (%)': 'IVU (%)',
    'Professional services retention percent': 'Porcentaje de retención de servicios profesionales', 'IVU percent': 'Porcentaje de IVU',
    'Yes, adds IVU': 'Sí, suma IVU', 'No': 'No', 'Billing terms saved.': 'Términos de cobro guardados.',
    'ID number': 'Número de ID', 'Approver': 'Aprobador',
    // Approvals
    'Approvals': 'Aprobaciones',
    'Approving replaces the manager signature. Weeks filled in after the deadline are flagged as late.': 'Aprobar reemplaza la firma del gerente. Las semanas llenadas después de la fecha límite se marcan como tarde.',
    'Name': 'Nombre', 'Type': 'Tipo', 'Contractors': 'Contratistas', 'Employees': 'Empleados', 'Contractor': 'Contratista',
    'Week ending from': 'Semana que termina desde', 'to': 'hasta', 'Quick period:': 'Período rápido:',
    'Last 4 weeks': 'Últimas 4 semanas', 'Last 3 months': 'Últimos 3 meses', 'All time': 'Todo el tiempo',
    'Pending always shows everything waiting for you, whatever the dates. The period applies to Approved, Returned and All.': 'Pendientes siempre muestra todo lo que te espera, sin importar las fechas. El período aplica a Aprobadas, Devueltas y Todas.',
    'Person': 'Persona', 'Week ending': 'Semana que termina', 'Project': 'Proyecto', 'Hours': 'Horas', 'Line': 'Línea', 'Total': 'Total',
    'No timesheet selected.': 'Ninguna hoja seleccionada.', 'Nothing is waiting for approval.': 'No hay nada esperando aprobación.', 'No timesheet matches the filter.': 'Ninguna hoja coincide con el filtro.',
  });
  d.patterns.push(
    [/^Showing (\d+) of (\d+) users\. The search also filters the contractor billing terms below\.$/, 'Mostrando $1 de $2 usuarios. La búsqueda también filtra los términos de cobro de contratistas más abajo.'],
    [/^Pending \((\d+)\)$/, 'Pendientes ($1)'], [/^Approved \((\d+)\)$/, 'Aprobadas ($1)'], [/^Returned \((\d+)\)$/, 'Devueltas ($1)'], [/^All \((\d+)\)$/, 'Todas ($1)'],
    [/^Employee · week ending (.+)$/, 'Empleado · semana que termina el $1'], [/^Contractor · week ending (.+)$/, 'Contratista · semana que termina el $1'],
    [/^Decided on (.+)\.$/, 'Decidida el $1.']
  );
})();
/* Approver kind in People (Spanish). */
(function () {
  Object.assign(window.I18N_ES.exact, { 'Internal': 'Interno', 'External': 'Externo' });
})();
/* Pop-up windows (modals) for add / edit, services and approval per service (Spanish). */
(function () {
  const d = window.I18N_ES;
  Object.assign(d.exact, {
    'Invite': 'Invitar', 'Invite person': 'Invitar persona', 'Edit person': 'Editar persona', 'Add approver': 'Agregar aprobador', 'Edit approver': 'Editar aprobador',
    'Add client': 'Agregar cliente', 'Edit client': 'Editar cliente', 'Edit project': 'Editar proyecto', 'Create project': 'Crear proyecto',
    'Add service': 'Agregar servicio', 'Edit service': 'Editar servicio', 'Services': 'Servicios', 'Approval': 'Aprobación', 'None': 'Ninguna',
    'Internal only': 'Solo interna', 'External only': 'Solo externa', 'Internal and external': 'Interna y externa',
    'What this service needs before its hours count as approved.': 'Lo que necesita este servicio para que sus horas cuenten como aprobadas.',
    'Internal approvers': 'Aprobadores internos', 'Needed when a service requires internal approval.': 'Se necesitan cuando un servicio pide aprobación interna.',
    'Choose the internal approvers in the project and save the project first.': 'Elige los aprobadores internos en el proyecto y guarda primero el proyecto.',
    'Discard your changes?': '¿Descartar los cambios?', 'Could not save. Try again.': 'No se pudo guardar. Inténtalo de nuevo.',
    'Assigned projects': 'Proyectos asignados', 'Assigned people': 'Personas asignadas', 'External approver': 'Aprobador externo', 'Consultant billing terms': 'Términos de cobro del consultor',
    'There are no projects yet.': 'Todavía no hay proyectos.', 'There are no people yet.': 'Todavía no hay personas.',
    'That person no longer exists.': 'Esa persona ya no existe.', 'That approver no longer exists.': 'Ese aprobador ya no existe.', 'That client no longer exists.': 'Ese cliente ya no existe.', 'That project no longer exists.': 'Ese proyecto ya no existe.',
    'You never type a password. The person receives an invitation by email to create their own.': 'Nunca escribes una contraseña. La persona recibe una invitación por correo para crear la suya.',
    'A new project starts with one service called General. Save the project, then open it with Edit to rename that service or add more services (each one with its own rate, entry mode and approval).':
      'Un proyecto nuevo empieza con un servicio llamado General. Guarda el proyecto y ábrelo con Editar para cambiarle el nombre a ese servicio o agregar más (cada uno con su tarifa, forma de entrada y aprobación).',
    'The person picks the project and then the service. A service decides the rate, whether it is billed, how hours are entered and which approval it needs. A project with one service picks it automatically. Changes only affect new entries.':
      'La persona elige el proyecto y luego el servicio. El servicio decide la tarifa, si se factura, cómo se entran las horas y qué aprobación necesita. Un proyecto con un solo servicio lo elige solo. Los cambios solo afectan las entradas nuevas.',
    'Service deactivated.': 'Servicio desactivado.', 'Service activated.': 'Servicio activado.', 'Type the 6-digit code.': 'Escribe el código de 6 dígitos.',
    'Fix the hours marked in red before saving. Hours must be multiples of': 'Corrige las horas marcadas en rojo antes de guardar. Las horas deben ser múltiplos de',
    'Person saved.': 'Persona guardada.', 'project rate': 'tarifa del proyecto', 'Rate (empty = project rate)': 'Tarifa (vacía = tarifa del proyecto)',
  });
  d.patterns.push(
    [/^Hours must be multiples of (.+)$/, 'Las horas deben ser múltiplos de $1'],
    [/^Minutes must be multiples of (.+)$/, 'Los minutos deben ser múltiplos de $1'],
  );
})();
/* Searchable multi-select, approvers per service, and short labels with the explanation below the field (Spanish). */
(function () {
  const d = window.I18N_ES;
  Object.assign(d.exact, {
    'Remove': 'Quitar', 'No matches': 'Sin coincidencias', 'Nothing to choose from yet.': 'Todavía no hay nada que escoger.',
    'Search by name or email': 'Buscar por nombre o correo', 'Search by code or name': 'Buscar por código o nombre', 'Search clients': 'Buscar clientes', 'Search by name': 'Buscar por nombre',
    'There are no clients yet.': 'Todavía no hay clientes.', 'This client has no approvers yet.': 'Este cliente todavía no tiene aprobadores.',
    'Internal approvers *': 'Aprobadores internos *', 'External approvers': 'Aprobadores externos',
    'At least one. When one of them approves, the hours move on.': 'Al menos uno. Cuando uno de ellos aprueba, las horas siguen su camino.',
    'Choose at least one internal approver.': 'Escoge al menos un aprobador interno.',
    'This project has no client. Pick one in the project first.': 'Este proyecto no tiene cliente. Escoge uno en el proyecto primero.',
    'Only the approvers chosen above are asked to approve.': 'Solo se pide aprobar a los aprobadores escogidos arriba.',
    'This approver can be chosen for services of these clients.': 'Este aprobador se puede escoger en servicios de estos clientes.',
    'Without a client, the services of this project cannot have external approval.': 'Sin cliente, los servicios de este proyecto no pueden tener aprobación externa.',
    'A billable project needs a client. Non-billable (internal) projects can have none.': 'Un proyecto facturable necesita un cliente. Los proyectos no facturables (internos) pueden no tener.',
    'Entry preference': 'Preferencia de entrada', 'Used when a service allows both.': 'Se usa cuando un servicio permite ambas.',
    'Hours per week': 'Horas por semana', 'Employees only.': 'Solo empleados.', 'Withholding (%)': 'Retención (%)', 'Professional services withholding.': 'Retención de servicios profesionales.',
    'Default billing rate': 'Tarifa de cobro por omisión', 'Used by services without their own rate.': 'La usan los servicios sin tarifa propia.', 'Empty = project rate.': 'Vacía = tarifa del proyecto.',
    'Accent color': 'Color de acento', 'Used for buttons.': 'Se usa en los botones.', 'Logo': 'Logo', 'png, jpg or webp; max 1 MB.': 'png, jpg o webp; máximo 1 MB.',
    'API URL': 'URL de la API', 'Localhost today, the server tomorrow.': 'Localhost hoy, el servidor mañana.', 'Username': 'Usuario',
    'Company id': 'Id de la compañía', 'The company in Accountisi.': 'La compañía en Accountisi.',
    'Sales service item': 'Artículo de servicio (ventas)', 'Used on sales invoices.': 'Se usa en las facturas de venta.',
    'Income account': 'Cuenta de ingresos', 'Used if there is no item.': 'Se usa si no hay artículo.',
    'Bills service item': 'Artículo de servicio (compras)', 'Used on bills.': 'Se usa en las facturas de compra.', 'Expense account': 'Cuenta de gastos',
    'Open invoice id': 'Id de factura abierta', 'Only when adding to an open one.': 'Solo al agregar a una abierta.', 'Columns': 'Columnas',
    'One per line: Heading | source. Sources: date, week_ending, worker, project.code, project.name, project.patient, project.plan, project.contract, project.meta.KEY, customer, hours, amount, rate, start, end, notes, billable':
      'Una por línea: Encabezado | fuente. Fuentes: date, week_ending, worker, project.code, project.name, project.patient, project.plan, project.contract, project.meta.KEY, customer, hours, amount, rate, start, end, notes, billable',
    'External · all': 'Externo · todos', 'skipped': 'omitida',
  });
  d.patterns.unshift(   // first: the generic "(.+) to (.+)" rule of the main dictionary would swallow "goes to all"
    [/^(\d+) selected$/, '$1 seleccionados'],
    [/^Client (.+) has no approvers\. Add one in Approvers first\.$/, 'El cliente $1 no tiene aprobadores. Agrega uno en Aprobadores primero.'],
    [/^Empty = it goes to all approvers of (.+?): (.+)$/, 'Vacío = va a todos los aprobadores de $1: $2']
  );
})();
