=== SwiftTrap for Mailtrap ===
Contributors: simmotorlp
Tags: mailtrap, transactional-email, email-api, wp-mail, email-log
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 3.0.1
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Envía correos de WordPress a través de la API de correo de Mailtrap (no SMTP). Flujos masivos y transaccionales, categorías, lista de supresión, registro de correo.

== Description ==

**SwiftTrap** es un reemplazo directo de `wp_mail()` que enruta el correo de WordPress a través de la **API de envío de correo de Mailtrap** en lugar de SMTP. Está diseñado específicamente para Mailtrap — no es un plugin SMTP genérico con un preset de Mailtrap — por lo que expone funciones nativas de Mailtrap que SMTP no puede ofrecer: enrutamiento de flujo masivo o transaccional, categorías de correo, variables personalizadas para seguimiento, listas de supresión y estado de verificación de dominio.

= ¿Por qué API HTTP en lugar de SMTP? =

* **Menor latencia** — una sola llamada HTTPS por mensaje, sin idas y vueltas de MAIL FROM / RCPT TO / DATA.
* **Mejor entregabilidad** — Mailtrap enruta los mensajes de la API a través de sus flujos dedicados transaccional y masivo; SMTP no permite elegir el flujo.
* **Categorías nativas** — cada correo se categoriza automáticamente (bienvenida, restablecimiento de contraseña, notificación, marketing, etc.) para que puedas filtrarlos y generar informes en Mailtrap.
* **Sin dolores de cabeza con el firewall** — ¿puerto 587/465 bloqueado? La API funciona sobre el HTTPS estándar del puerto 443.

= Por qué SwiftTrap y no WP Mail SMTP / Post SMTP =

* Los plugins SMTP genéricos usan las credenciales SMTP de Mailtrap y pierden todas las funciones exclusivas de Mailtrap.
* SwiftTrap llama a `send.api.mailtrap.io` para el correo transaccional y a `bulk.api.mailtrap.io` para el correo masivo — automáticamente, según la categoría o mediante un filtro.
* No requiere el SDK de PHP de Mailtrap. El plugin pesa **~30 KB en total** y usa únicamente la API HTTP de WordPress (`wp_remote_post`).
* La página de estadísticas muestra el estado de verificación de tu dominio de envío y la lista de supresión en vivo (rebotes, quejas, bajas).

= Funciones =

* Reemplazo directo de `wp_mail()` — funciona con WooCommerce, Contact Form 7, Gravity Forms y cualquier plugin que use el sistema de correo de WordPress.
* Categorización automática de correos y anulaciones de enrutamiento de flujo mediante una cuadrícula de ajustes.
* Seguimiento de entregas y webhooks — Seguimiento de eventos en tiempo real mediante la ruta REST personalizada `swifttrap/v1/webhook`.
* Gestión de supresiones — Panel CRUD para las listas de supresión de Mailtrap con comprobaciones de supresión del destinatario antes del envío.
* Alternativa de fiabilidad — Retorno controlado al `wp_mail()` nativo de WordPress si falla la llamada a la API de Mailtrap.
* Integración con Salud del sitio — Prueba de verificación que comprueba el estado del token de Mailtrap y la verificación del dominio de envío.
* Registro de correo en vivo — Explora y filtra los datos de entrega obtenidos directamente de la API de Mailtrap; busca por dirección del destinatario, estado o rango de fechas, con paginación automática.
* Comandos WP-CLI — Gestión desde la línea de comandos mediante `wp swifttrap` (test, stats, prune-logs, send-suppression-sync).
* Control de tamaño de adjuntos — Límites configurables para evitar que los archivos demasiado grandes sean rechazados en la puerta de enlace de la API.
* Botón de correo de prueba en la página de ajustes.
* Compatibilidad con plantillas de Mailtrap mediante `template_uuid`.
* Recurre al gestor de correo predeterminado de WordPress cuando está desactivado o el token está vacío.

= Extensible mediante filtros =

* `swifttrap_mailtrap_email_category` — anula la categoría de correo detectada automáticamente.
* `swifttrap_mailtrap_use_bulk_stream` — fuerza un mensaje al flujo masivo o transaccional.
* `swifttrap_mailtrap_template` — envía mediante una plantilla de Mailtrap usando `template_uuid`.
* `swifttrap_mailtrap_custom_variables` — adjunta metadatos de seguimiento a los correos salientes.

= Privacidad =

Este plugin envía los datos del correo (destinatarios, asunto, cuerpo, adjuntos) a la API de Mailtrap en `send.api.mailtrap.io` y `bulk.api.mailtrap.io`. Las estadísticas de la cuenta y los registros de correo se obtienen de `mailtrap.io/api/accounts` y `mailtrap.io/api/email_logs`. Consulta la [Política de privacidad de Mailtrap](https://mailtrap.io/privacy-policy). No se envían datos a ningún otro sitio.

== Installation ==

1. Instálalo desde **Plugins → Añadir nuevo** y busca *SwiftTrap for Mailtrap*, o sube la carpeta `swifttrap-for-mailtrap` a `/wp-content/plugins/`.
2. Activa el plugin.
3. Ve a **Mailtrap → Ajustes**.
4. Pega tu **token de la API de envío** de Mailtrap (panel de Mailtrap → Sending Domains → API Tokens).
5. Configura tu correo y nombre de remitente verificados.
6. Haz clic en **Enviar correo de prueba** para verificar la entrega.

== Frequently Asked Questions ==

= ¿Por qué usar SwiftTrap en lugar de WP Mail SMTP o Post SMTP con credenciales de Mailtrap? =

WP Mail SMTP y Post SMTP enrutan a través de la puerta de enlace SMTP de Mailtrap y tratan a Mailtrap como un host SMTP más. SwiftTrap usa la API de envío HTTP de Mailtrap, que expone funciones que SMTP no puede ofrecer: enrutamiento de flujo masivo o transaccional, categorías, variables de seguimiento personalizadas, UUID de plantillas y visibilidad en vivo de la lista de supresión. Usa SwiftTrap si quieres un comportamiento nativo de Mailtrap; usa un plugin SMTP genérico si prefieres una configuración única válida para cualquier proveedor.

= ¿Es compatible con las plantillas de correo de Mailtrap? =

Sí — usa el filtro `swifttrap_mailtrap_template` para enviar mediante un `template_uuid`. Las variables de la plantilla se pueden pasar a través del payload estándar de variables de plantilla de Mailtrap.

= ¿Cómo funciona el enrutamiento al flujo masivo? =

Por defecto, las categorías de marketing/promocionales se enrutan a `bulk.api.mailtrap.io` y todo lo demás a `send.api.mailtrap.io`. Puedes anularlo por mensaje con el filtro `swifttrap_mailtrap_use_bulk_stream` — útil para newsletters por lotes desde un plugin personalizado.

= ¿Dónde consigo mi token de API? =

Inicia sesión en [mailtrap.io](https://mailtrap.io), abre tu dominio de envío, ve a **API Tokens** y crea un token con permisos de envío.

= ¿Qué ocurre si desactivo el plugin o elimino el token? =

WordPress recurre a su gestor `wp_mail()` predeterminado. Ningún correo se pierde silenciosamente.

= ¿El plugin requiere el SDK de PHP de Mailtrap? =

No. SwiftTrap llama directamente a la API REST de Mailtrap a través de la API HTTP de WordPress. El tamaño total del plugin es de unos 30 KB.

= ¿Qué datos se envían externamente? =

Los datos del correo (destinatarios, asunto, cuerpo, adjuntos) se envían a `send.api.mailtrap.io` y `bulk.api.mailtrap.io`. Las estadísticas de la cuenta se obtienen de `mailtrap.io/api/accounts`. Consulta la [Política de privacidad de Mailtrap](https://mailtrap.io/privacy-policy).

= ¿Hay un límite de tamaño para los adjuntos? =

Sí — 25 MB por correo (coincide con el límite de la API de Mailtrap).

== Screenshots ==

1. Página de ajustes — token de API, remitente verificado, enrutamiento de flujo.
2. Página de estadísticas — estado de verificación del dominio de envío y lista de supresión (rebotes, quejas, bajas).
3. Registro de correo — datos en vivo de la API de Mailtrap con filtros y paginación.
4. Widget del escritorio que muestra el estado de la integración, el remitente y enlaces rápidos a Estadísticas y Ajustes.
5. Confirmación de correo de prueba.

== Changelog ==

= 3.0.1 =
* Corregido: El receptor de webhooks ahora verifica el encabezado real `Mailtrap-Signature` HMAC-SHA256 de Mailtrap, en lugar de un encabezado que Mailtrap nunca envía. Todas las llamadas reales de webhook de seguimiento de entregas se rechazaban por completo desde que se lanzó la función en la 2.4.0.
* Corregido: El análisis del payload del webhook ahora desempaqueta correctamente el sobre `{"events": [...]}` de Mailtrap, de forma que los eventos verificados llegan a `do_action('swifttrap_mailtrap_webhook_event', ...)`.
* Corregido: La tarjeta de uso en la página de estadísticas ahora llama al endpoint actual `/api/billing/usage` de Mailtrap, en lugar de una ruta obsoleta con ámbito de cuenta que no devolvía datos.
* Corregido: Al desinstalar el plugin ahora se eliminan los transients en caché reales, en lugar de nombres de clave previos a la 2.3.0 que ya no coinciden.
* Mejorado: La búsqueda de destinatarios en Registros de correo y las llamadas a la API de cuenta ahora usan de forma consistente la sintaxis de filtro entre corchetes y autenticación con token Bearer.

= 3.0.0 =
* Cambio importante: Se eliminó todo el registro de correo basado en archivos locales. Ya no se escriben archivos de registro en disco — elimina el riesgo de OOM/disco lleno en sitios de alto volumen.
* Nuevo: El panel de Registros de correo en la página de estadísticas obtiene datos en vivo directamente de la API de Mailtrap (`GET /api/email_logs`).
* Nuevo: Los registros de correo admiten filtrado por dirección de correo del destinatario, estado de entrega y rango de fechas.
* Nuevo: Paginación del lado del cliente — almacena en búfer hasta 1000 entradas de Mailtrap por llamada a la API, y muestra 20 filas a la vez con navegación Anterior/Siguiente. Obtiene automáticamente el siguiente lote cuando se agota el búfer.
* Nuevo: El gestor de webhooks ahora dispara `do_action('swifttrap_mailtrap_webhook_event', $event)` para cada evento de entrega, permitiendo integraciones de terceros sin modificar el plugin.
* Eliminado: Exportación a CSV, borrado de archivo de registro, modal de detalle de registro, reenvío de registro, ajuste de registros por página y limpieza de registros basada en cron. Todo sustituido por la vista en vivo de la API.
* Corregido: La página de estadísticas ya no crea un atributo nonce redundante en el elemento contenedor.

= 2.4.2 =
* Corregido: El registro de correo perdía la mayoría de las entradas durante envíos de alto volumen o concurrentes. Cada escritura releía y reescribía todo el archivo de registro, por lo que los procesos paralelos se sobrescribían las líneas entre sí. Las escrituras ahora usan un anexado atómico con bloqueo exclusivo, de forma que el panel de estadísticas (envíos por día, categorías, totales) refleja el número real de correos enviados.
* Mejorado: El registro ya no ralentiza los envíos masivos — los anexados son O(1) en lugar de releer y reescribir todo el archivo en cada correo.

= 2.4.1 =
* Corregido: La lista de supresión ahora lee el campo `type` de Mailtrap, de forma que el panel muestra los recuentos reales de BOUNCE / COMPLAINT / UNSUBSCRIBE / MANUAL en lugar de marcar todos los registros como manuales.
* Nuevo: Las filas de supresión muestran la categoría de rebote del mensaje (cuando está disponible) para el detalle de rebotes duros.
* Corregido: Las fechas de supresión ahora se formatean en el servidor usando el formato de fecha del sitio, en lugar de la configuración regional del navegador.
* Nuevo: Enlace "Ver todo en Mailtrap" en la tarjeta de supresiones.
* Nuevo: Selector de elementos por página (10/25/50/100) en la pantalla de Registros de correo.
* Mejorado: Las acciones del encabezado de Registros de correo se alinearon a la derecha; se rediseñó el campo de filtro de fecha para coincidir con los demás campos.

= 2.4.0 =
* Nuevo: Endpoint REST de webhook (`swifttrap/v1/webhook`) para el seguimiento de los estados entregado, rebotado, abierto y clicado.
* Nuevo: CRUD de gestión de supresiones en las estadísticas de administración y comprobaciones de destinatarios antes del envío para omitir correos suprimidos.
* Nuevo: Mecanismo de reserva que devuelve `null` en `pre_wp_mail` cuando falla la API, de forma que el `wp_mail` nativo envía el correo en su lugar.
* Nuevo: Prueba de conexión y estado de verificación de dominio en Salud del sitio.
* Nuevo: Interfaz de registros de administración mejorada con búsqueda, filtrado, exportación a CSV, modales de vista previa del payload en iframe y acciones de reenvío.
* Nuevo: Cuadrícula de ajustes de categorías para las reglas de asignación de flujo por categoría y las anulaciones de remitente.
* Nuevo: Espacio de nombres WP-CLI `wp swifttrap` (test, stats, prune-logs, send-suppression-sync).
* Nuevo: Ajuste de control de tamaño de adjuntos.
* Refactorizado: Se extrajo el formateador de filas CSV a una función auxiliar para las pruebas unitarias. Totalmente cubierto y verificado por la suite de pruebas.

= 2.3.0 =
* PHP 8.0 es ahora el mínimo requerido; probado hasta WordPress 7.0.
* Fiabilidad: reintento automático con backoff en errores transitorios de la API de Mailtrap (429/5xx, respeta Retry-After).
* Retención determinista de registros mediante un evento cron diario (sustituye a la limpieza probabilística anterior).
* Las cachés de cuenta/estadísticas/dominio/supresión ahora se indexan por token de API, de forma que cambiar de token ya no sirve datos obsoletos.
* Manejo robusto de JSON para todas las respuestas de la API de Mailtrap; caché de ajustes segura para multisitio.
* Nuevo: Botón "Verificar token" en la pantalla de ajustes.
* Código modernizado a los idiomas de PHP 8; se añadió la primera suite de pruebas unitarias.

= 2.2.2 =
* Plugin URI: ahora apunta a la landing page dedicada en https://plugins.symonov.com/swifttrap-for-mailtrap/
* Sin cambios de código ni de comportamiento

= 2.2.1 =
* Readme: reescritura USP-first enfatizando la API de correo de Mailtrap (frente a SMTP) y el enrutamiento de flujo masivo/transaccional
* Tags: se reemplazaron `email`/`mail`/`smtp` por las específicas `mailtrap`, `transactional-email`, `email-api`, `wp-mail`, `email-log`
* FAQ: se añadió comparación con WP Mail SMTP / Post SMTP, compatibilidad con plantillas de Mailtrap y enrutamiento de flujo masivo
* Probado hasta WordPress 7.0

= 2.2.0 =
* Se reemplazaron todos los file_get_contents/file_put_contents por la API WP_Filesystem
* Se corrigió la sanitización de $_GET con wp_unslash() y anotaciones phpcs adecuadas
* Se mejoraron los encabezados PHPDoc en todos los archivos
* Mejor cumplimiento de los WordPress Coding Standards

= 2.1.0 =
* Se añadió el estado de verificación del dominio de envío en la página de estadísticas
* Se añadió la lista de supresión (rebotes, quejas, bajas) en la página de estadísticas
* Se añadió el filtro `swifttrap_mailtrap_template` para la compatibilidad con plantillas de Mailtrap
* Se añadió el filtro `swifttrap_mailtrap_custom_variables` para metadatos de seguimiento de correo
* Se extrajo la función reutilizable `swifttrap_mailtrap_get_account_id()` con caché de transients

= 2.0.0 =
* Se eliminó la dependencia del SDK de Mailtrap — usa directamente la API HTTP de WordPress
* Cero dependencias externas, ~30 KB de tamaño total del plugin
* Mejor cumplimiento de las normas de WP.org

= 1.3.0 =
* Seguridad: se protegió el directorio de registros del acceso web directo
* Se añadió validación del tamaño de los adjuntos (límite de 25 MB)
* Se añadió validación de destinatario vacío
* Se corrigió el manejo de zona horaria en la visualización de registros
* Se optimizó el cálculo de la categoría de correo
* Se mejoró el bloqueo del archivo de registro

== Upgrade Notice ==

= 3.0.1 =
Corrección importante: los eventos de seguimiento de entregas por webhook de Mailtrap se estaban rechazando por una discrepancia en la verificación de firma y nunca se han procesado desde la 2.4.0. Actualiza si usas la integración de webhooks.

= 2.4.0 =
Actualiza el plugin de WordPress a la 2.4.0, introduciendo webhooks de seguimiento de entregas, gestión de supresiones, reserva nativa controlada, una interfaz de registros mejorada con exportación a CSV, comandos WP-CLI y una comprobación de Salud del sitio de WordPress.

= 2.3.0 =
Versión menor de fiabilidad: reintentos automáticos de envío ante errores transitorios de la API, limpieza de registros basada en cron y actualizaciones modernas para PHP 8.

= 2.2.2 =
Plugin URI ahora apunta a la landing page dedicada en plugins.symonov.com. Sin cambios de código.

= 2.2.1 =
Versión solo de documentación. Readme actualizado y compatibilidad confirmada con WordPress 7.0.

= 2.2.0 =
Revisión de WordPress Coding Standards — API WP_Filesystem, sanitización de entrada reforzada y PHPDoc mejorado. No se requieren cambios de configuración.
