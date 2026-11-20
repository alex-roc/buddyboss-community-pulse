# Community Pulse para BuddyBoss

Plugin de WordPress para quienes gestionan una comunidad en **BuddyBoss Platform** o **BuddyPress**. Muestra la **participación pública de cada mes** sin exportar perfiles, contenido de las publicaciones ni datos de ninguna persona.

**Estado: 0.2.0, versión temprana.** Tiene pruebas de integración con BuddyBoss Platform 3.8.0 y BuddyPress 14.5.6 (ver [VALIDATION.md](VALIDATION.md)). Pruébalo primero en una copia de staging. Lo creó Alex Ojeda Copa en noviembre de 2026 y no está afiliado a BuddyBoss ni a BuddyPress.

La documentación técnica completa está en el [README en inglés](README.md).

## Qué muestra

En **Herramientas → Community Pulse**, quien administra el sitio elige uno de los últimos 12 meses calendario (en UTC) y ve:

| Indicador | Pregunta que responde |
|---|---|
| Participantes únicos | ¿Cuántas personas publicaron algo público este mes? |
| Actualizaciones públicas | ¿Cuánto se publicó? |
| Participantes en 2 días o más | ¿Cuántas personas volvieron a publicar otro día? |
| Grupos públicos con 5 participantes o más | ¿En qué grupos públicos hay conversación? |

Las mismas cifras se descargan en un **CSV agregado**, útil para informes al directorio, a donantes o al equipo.

Estas cifras miden **publicaciones públicas**. No miden lectores ni "usuarios activos", impacto social, causalidad ni calidad de la participación.

## Requisitos e instalación

1. WordPress 6.6 o superior, PHP 8.1 o superior, y BuddyBoss Platform o BuddyPress con los componentes **Actividad** y **Grupos** activos.
2. Descarga `buddyboss-community-pulse-<versión>.zip` desde [Releases](https://github.com/alex-roc/buddyboss-community-pulse/releases).
3. Entra a **Plugins → Añadir nuevo plugin → Subir plugin**, sube el ZIP y pulsa **Activar**.
4. Abre **Herramientas → Community Pulse**. También puedes usar el enlace **Ver informe** en la lista de plugins.

Para actualizar, sube el ZIP nuevo de la misma forma y confirma **Reemplazar el actual con el subido**. El plugin no guarda datos ni opciones, así que al borrarlo no deja nada.

Si el sitio está en cualquier variante de español (`es_ES`, `es_MX`, `es_AR`, …), la pantalla aparece en español.

## Cómo leer el informe

- **Mes en curso:** se marca como parcial porque las cifras siguen creciendo.
- **"Oculto":** el valor describiría a menos de 5 personas, así que no se muestra para no exponer a nadie. Si en el mes participaron menos de 5 personas en total, se oculta el resumen completo.
- **Grupos:** solo aparecen grupos públicos con 5 participantes o más. Una persona que publica en tres grupos cuenta en los tres, así que **no sumes las filas** para obtener el total de participantes.
- **Qué no se cuenta:** comentarios, grupos privados u ocultos, actividad oculta o marcada como spam, publicaciones programadas o no públicas, mensajes, lecturas e inicios de sesión.

### Columnas del CSV

| Columna | Significado |
|---|---|
| `month_utc` | Mes (`AAAA-MM`) |
| `partial_month` | `yes` si el mes no había terminado al exportar |
| `scope` | `overall` (total), `group` (grupo) o `note` (aviso) |
| `group_name` | Nombre del grupo público. Puede empezar con `'` si el nombre parecía una fórmula |
| `contributors` | Participantes distintos, o `suppressed` si se ocultó |
| `public_updates` | Actualizaciones públicas, o `suppressed` |
| `contributors_2plus_days` | Solo en el total: un número, `suppressed`, o `not_calculated` en los grupos |

## Privacidad

- Solo usuarios con `manage_options` (administradores) pueden ver y exportar. Se puede cambiar con el filtro `community_pulse_capability`; ver el [README en inglés](README.md#configuration).
- Todo se agrega dentro de la base de datos. Nombres, correos, perfiles, textos, mensajes e IP nunca se leen ni se exportan.
- No se guarda nada ni se envía nada a servicios externos.
- El umbral de 5 personas **reduce la exposición, pero no garantiza el anonimato**. Quien administra puede saber quién publica, y comparar meses puede revelar cambios. Comparte los CSV según la política de datos de tu organización.

## Problemas frecuentes

| Mensaje | Qué hacer |
|---|---|
| "Activa BuddyBoss Platform o BuddyPress con los componentes Actividad y Grupos." | Actívalos en **BuddyBoss → Ajustes → Componentes** o en **Ajustes → BuddyPress → Componentes** |
| "Resumen oculto: menos de 5 personas…" | Es lo esperado en comunidades pequeñas o en meses tranquilos |
| La tabla de grupos está vacía | Ningún grupo público llegó a 5 participantes ese mes |
| No aparece el menú | Tu usuario no tiene el permiso necesario |

## Licencia

GPL-2.0-or-later. © 2026 Alex Ojeda Copa. Sin garantía. Valídalo en staging antes de usarlo en producción.
