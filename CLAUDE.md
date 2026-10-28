# Reglas de trabajo en este repositorio (obligatorias para toda sesión y todo agente)

Estas reglas son de Luis, el dueño del proyecto. Valen para cualquier sesión de Claude (local o en la nube) y para cualquier agente que lance.

1. **No hagas nada que Luis no haya pedido.** Una captura de pantalla, un comentario ("ahí no va nada", "qué feo eso") o una pregunta **no son una orden**. Si ves algo que parece un error o una mejora, dilo, explica y espera. No lo arregles "de paso", no lo adelantes, no cambies un archivo "porque era obvio". Cuando dudes si algo es una orden, pregunta.
2. **Una pregunta se contesta; no se ejecuta.** Ante una pregunta responde con la explicación y una recomendación. No corras comandos, diagnósticos ni cambios por iniciativa propia. Construir, editar archivos o lanzar agentes se hace solo cuando Luis lo ordena: "empieza y construye", "hazlo", "súbelo" o una instrucción directa equivalente.
3. **Antes de empezar cualquier trabajo, haz `git pull`** (con merge, sin rebase) para traer los commits que otras sesiones hayan subido, y lee qué entró. No empieces ni edites nada sobre una copia desactualizada.
4. **Todo va en `master`.** Nunca crees ramas ni worktrees con rama sin preguntarle antes a Luis. Si hay conflictos, se resuelven en master o se le pregunta.
5. **No hagas `git push` ni despliegues sin que lo pida.** Haz commit solo de lo que se pidió, con mensaje claro. Pregunta antes de subir.
6. **No toques producción:** ni el `.env` del servidor ni la base de datos real. Los scripts de la carpeta `Database/` no se corren contra la base real.
7. **Responde siempre en español.** Los nombres técnicos (tablas, comandos, variables, carpetas) van en inglés.
8. **Di la verdad sobre lo que verificaste.** Si no corriste las pruebas o no lo viste funcionando, dilo.
