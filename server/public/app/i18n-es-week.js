/* Spanish for the week screens. Merges into the main dictionary (loaded after i18n-es.js). */
(function () {
  const d = window.I18N_ES || (window.I18N_ES = { exact: {}, patterns: [] });
  Object.assign(d.exact, {
    'Weekly time record': 'Registro semanal de horas',
    'Previous week (older)': 'Semana anterior (más antigua)', 'Next week (newer)': 'Semana siguiente (más nueva)', 'Current week': 'Semana actual',
    'Code': 'Código', 'Service': 'Servicio', 'Project': 'Proyecto', 'Total': 'Total', 'Daily total': 'Total diario',
    '+ Add project line': '+ Agregar línea de proyecto', 'Copy lines from previous week': 'Copiar líneas de la semana anterior',
    'Remove line': 'Quitar línea', 'Details': 'Detalle', 'Late': 'Tarde',
    'Employee total': 'Total del empleado', 'Billable hours': 'Horas facturables', 'Non-billable hours': 'Horas no facturables', 'Total hours': 'Total de horas',
    'Your invoice is generated from approved weeks. You never retype hours.': 'Tu factura se genera con las semanas aprobadas. Nunca vuelves a escribir las horas.',
    'Save draft': 'Guardar borrador', 'Submit for approval': 'Enviar a aprobación', 'Corrected: submit again': 'Corregida: enviar de nuevo',
    'I certify these hours are accurate. Submitting counts as my electronic signature.': 'Certifico que estas horas son correctas. Al enviar, esto cuenta como mi firma electrónica.',
    'Approved and locked.': 'Aprobada y bloqueada.',
    'Waiting for a decision. If you change it, the approver gets a notice that it changed.': 'Esperando una decisión. Si la cambias, el aprobador recibe un aviso de que cambió.',
    'Returned for correction:': 'Devuelta para corregir:',
    'Name': 'Nombre', 'Worker type': 'Tipo de trabajador', 'Approver': 'Aprobador', 'Employee': 'Empleado', 'Consultant': 'Consultor',
    'Week ending (Sunday)': 'Semana que termina (domingo)',
    'You have 1 past week with no timesheet. Start with the oldest:': 'Tienes 1 semana pasada sin hoja de horas. Empieza por la más antigua:',
    // clock
    'Clock': 'Reloj', 'Start': 'Iniciar', 'Stop': 'Parar', 'since': 'desde', 'Running on': 'Corriendo en', 'Close': 'Cerrar', 'Cancel': 'Cancelar',
    'There are no projects where the clock can be used.': 'No hay proyectos donde se pueda usar el reloj.',
    'Clock started.': 'Reloj iniciado.', 'Clock stopped.': 'Reloj detenido.',
    // detail box
    'From': 'Desde', 'To': 'Hasta', 'Note': 'Nota', 'Hours for the day': 'Horas del día', 'Full day': 'Día completo', 'Edit': 'Editar',
    'Add entry': 'Agregar entrada', 'Save entry': 'Guardar entrada', 'Delete this entry': 'Borrar esta entrada', 'Delete this entry?': '¿Borrar esta entrada?',
    'Entry saved.': 'Entrada guardada.', 'Entry deleted.': 'Entrada borrada.',
    // messages
    'Draft saved.': 'Borrador guardado.', 'Nothing to save.': 'No hay nada que guardar.',
    'Timesheet submitted for approval.': 'Hoja enviada a aprobación.',
    '1 line copied.': '1 línea copiada.', 'There are no new lines to copy from the previous week.': 'No hay líneas nuevas que copiar de la semana anterior.',
    'There are no projects to add.': 'No hay proyectos para agregar.',
    'Remove this line and delete its hours for this week?': '¿Quitar esta línea y borrar sus horas de esta semana?',
    'Hours must be a number, zero or more.': 'Las horas deben ser un número, cero o más.',
    'You cannot enter hours on future dates.': 'No se pueden registrar horas en fechas futuras.',
  });
  d.patterns.push(
    [/^Deadline: (.+) (\d{1,2}:\d{2}) after the week ends$/, 'Límite: $1 $2 después de terminar la semana'],
    [/^Deadline: (.+) after the week ends$/, 'Límite: $1 después de terminar la semana'],
    [/^You have (\d+) past weeks with no timesheet\. Start with the oldest:$/, 'Tienes $1 semanas pasadas sin hoja de horas. Empieza por la más antigua:'],
    [/^Fill in week ending (.+)$/, 'Llenar la semana que termina el $1'],
    [/^(.+) ID number$/, 'Número de ID de $1'],
    [/^(\d+) lines copied\.$/, '$1 líneas copiadas.'],
    [/^The most hours you can enter for one day is (\d+(?:\.\d+)?)\.$/, 'El máximo de horas que se pueden escribir en un día es $1.'],
    [/^Entries for (.+)$/, 'Entradas de $1'],
    [/^(.+) hours, line (\d+)$/, 'Horas del $1, línea $2'],
  );
})();
/* Internal approval (web): Spanish. */
(function () {
  const d = window.I18N_ES;
  Object.assign(d.exact, {
    'Requires internal approval': 'Requiere aprobación interna', 'Internal approvers': 'Aprobadores internos', 'Search people': 'Buscar personas',
    'The hours go to these people first. When one of them approves, they go to the external approver, who certifies at the end.': 'Las horas pasan primero por estas personas. Cuando una de ellas aprueba, pasan al aprobador externo, que certifica al final.',
    'Internal approval': 'Aprobación interna', 'Pick at least one internal approver.': 'Elige al menos un aprobador interno.',
    'Submitted · waiting for client approval': 'Enviada · esperando la aprobación del cliente', 'Submitted · waiting for internal approval': 'Enviada · esperando la aprobación interna',
    'Pending · internal': 'Pendiente · interna', 'Pending · client': 'Pendiente · cliente',
    'Waiting for': 'Esperando a', 'Waiting for client approval:': 'Esperando la aprobación del cliente:', 'Returned by': 'Devuelta por',
    'From – To': 'Desde – Hasta',
    'These are hours waiting for your approval. After you approve, they go to the external approver.': 'Estas son horas que esperan tu aprobación. Después de que apruebes, pasan al aprobador externo.',
  });
  d.patterns.push(
    [/^Submitted · waiting for (.+)$/, 'Enviada · esperando a $1'],
    [/^(.+) — approved by (.+)$/, '$1 — aprobada por $2'],
    [/^(.+) — waiting for (.+)$/, '$1 — esperando a $2'],
    [/^(.+) — skipped$/, '$1 — omitida']
  );
})();
/* Clock: minimum duration (Spanish). */
(function () {
  const d = window.I18N_ES;
  Object.assign(d.exact, { 'Keep clock running': 'Dejar el reloj corriendo' });
  d.patterns.push(
    [/^Only (\d+) min passed\. The minimum is (\d+) min\. Record it as (\d+) min\?$/, 'Solo han pasado $1 min. El mínimo es $2 min. ¿Registrarlo como $3 min?'],
    [/^Record (\d+) min$/, 'Registrar $1 min'],
  );
})();
