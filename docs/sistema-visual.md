# Sistema visual: RN Asistencia — control claro

Interfaz corporativa mobile-first construida con Blade y CSS propio, sin incorporar bibliotecas ni dependencias externas. Los valores de color, espacio, tipografía, radios, foco y controles se centralizan en `public/css/app.css`.

## Criterios

- Rojo `#D50032` como acento de marca; superficies blancas y grises y texto `#606060`. Los tonos suaves de estado y los derivados de interacción son tokens de interfaz, no nuevos colores institucionales.
- Tipografía de sistema porque no hay una fuente Montserrat licenciada disponible localmente; no se carga ninguna fuente desde CDN.
- Controles táctiles de al menos 48 px, foco visible, etiquetas explícitas, contraste reforzado, navegación con teclado, respeto por `prefers-reduced-motion` y navegación móvil con área segura.
- En escritorio el panel administrativo usa navegación lateral; en móvil muestra los módulos existentes en una cuadrícula y presenta las tablas como filas etiquetadas, evitando depender del desplazamiento horizontal.
- La pantalla del trabajador presenta la conectividad del navegador, la cantidad de eventos pendientes localmente, la última marca recibida y las incidencias/solicitudes entregadas por el servidor.

## Recursos y funciones no disponibles

- No se encontraron los logotipos oficiales `public/brand/blanco-hq.png`, `rojo-hq.png` o `gris-hq.png`, ni una biblioteca de iconos. No se inventa un reemplazo del logo; la cabecera usa texto funcional de producto, no un logotipo.
- No hay una fuente local licenciada de Montserrat. Se usa la familia de sistema.
- El MVP no calcula un resumen diario de horas en la pantalla del trabajador ni determina automáticamente la próxima marca esperada. La interfaz declara estas limitaciones y deja la selección de tipo al trabajador, sin inferir reglas de jornada.
- “En línea” refleja el estado de conectividad que informa el navegador; la confirmación de cada evento depende de la respuesta del servidor. Solo esa respuesta retira el evento de la cola local.

## Compatibilidad de recursos offline

La versión del caché estático del service worker cambió a `asistencia-shell-v2` para que los dispositivos adopten el CSS rediseñado. La actualización elimina únicamente el caché anterior de recursos estáticos; no accede ni elimina IndexedDB ni las marcas pendientes.
