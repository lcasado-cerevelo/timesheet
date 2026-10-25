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
