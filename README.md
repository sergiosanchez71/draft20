# Draft 20

Juego de **subasta por turnos para 2 jugadores** en tiempo real, optimizado para móvil. Cada jugador empieza con **20 monedas** y 8 ítems se subastan en rondas alternas. Gana quien reúne la colección de mayor **valor intrínseco** (suma de `valor` de sus ítems).

> Stack: PHP 8 (sin frameworks) + JavaScript vanilla + Tailwind CDN. Persistencia en archivos JSON. Cero dependencias externas en backend.

---

## Cómo correrlo

### Requisitos

- PHP 8.0 o superior con extensión `flock` (incluida por defecto).
- Un navegador moderno (Chrome, Safari, Firefox).
- (Opcional) Servidor web: Apache, Nginx o el built-in `php -S` para desarrollo.

### Arranque rápido

```bash
# desde la raíz del proyecto
php -S 127.0.0.1:8000
```

Abrir `http://127.0.0.1:8000` en el navegador.

### Despliegue en producción

1. Subir todo el contenido a un servidor con PHP 8+.
2. Apuntar el document root a la raíz del proyecto (donde está `index.php`).
3. Asegurar que el directorio `api/salas/` tiene permisos de escritura para el usuario del servidor web.
4. El directorio `api/salas/` debe estar bloqueado vía `.htaccess` (ya incluido) si usas Apache.
5. **Caché del CDN**: los assets van versionados con `?v=filemtime(...)`, así que cada deploy genera URLs nuevas y no hace falta purgar caché. Si tras desplegar ves la versión antigua, haz un hard reload una vez (el `sw.js` y el `manifest` van con `no-cache` por `.htaccess`) y, si persiste, purga la caché del CDN una única vez.

---

## Cómo se juega

1. **Jugador 1** abre la app, elige temática y nombre → pulsa **Crear Sala** → recibe un código de 5 caracteres.
2. Comparte el código (botón WhatsApp o copiar enlace) con el **Jugador 2**.
3. **Jugador 2** abre el enlace o introduce el código → entra a la partida.
4. La partida empieza automáticamente cuando ambos están dentro.

### Reglas

- **8 ítems** únicos por partida, elegidos del pool de la temática con **reparto equilibrado**: sorteo ponderado por tiers de `valor` (premium ≥8, medios 4-7, malos ≤3) con castigo por repetición y límites duros (**malos 1-3**, premium 2-5, medios 0-4). Así nunca salen partidas cargadas de ítems malos ni todo gemas.
- **20 monedas** iniciales por jugador.
- En cada ronda se subasta **1 ítem**:
  - Los jugadores alternan pujas (+1 o +3 monedas).
  - El último en pujar marca el precio actual.
  - Cualquier jugador puede **bajar** (cerrar la subasta): el último pujador paga el precio y se queda el ítem.
  - Si bajas sin que nadie haya pujado, es un error.
- **Cap de 4 ítems por jugador**: si alguien llega a 4, los ítems restantes se asignan automáticamente al rival.
- **Deadlock sin dinero**: si en un ítem fresco te toca a ti y tienes 0 monedas, pulsas **PASAR TURNO** y el rival decide si se lo queda por 1 🪙 o te lo regala por 0 🪙.
- **Sin límite de tiempo por turno**: la partida espera a que cada jugador decida (el abandono por inactividad se gestiona aparte, ver abandono).
- **Abandono**: pulsar **Salir al lobby** notifica al rival y termina la partida. Si cierras la pestaña o pierdes conexión, el rival ve un aviso a los **6 s** ("rival desconectado") y la partida se da por **abandonada a los 45 s** sin actividad (margen para cortes móviles).
- **Victoria**: gana el jugador cuya **colección de ítems tenga mayor valor intrínseco** (suma de `valor`, escala 1-10). En caso de empate a ⭐, gana quien conserve **más monedas**; si también empatan, tablas. El precio pagado es informativo.
- **Revancha**: al terminar, cualquiera puede proponer revancha eligiendo **nueva temática** (o 🎲 aleatoria). El rival la acepta o rechaza desde la pantalla final.
- **Valores ocultos**: el ⭐ de cada ítem es secreto durante la partida (ni en la carta ni en el inventario). Solo se revela en la pantalla final.

---

## Arquitectura

```
draft20/
├── index.php              # Lobby: crear / unirse a sala
├── juego.php              # UI principal del juego
├── tematicas_catalogo.php # Catálogo de temáticas por categoría (fuente única)
├── lang/es.json           # Strings UI + nombres de ítems y temáticas (i18n)
├── tematicas/             # 59 temáticas (≥20 ítems c/u; id + emoji + valor)
├── api/                   # Backend PHP (todos devuelven JSON)
│   ├── crear_sala.php     # POST: crea sala + GC oportunista
│   ├── unirse_sala.php    # POST: J2 entra a sala existente + GC oportunista
│   ├── accion.php         # POST: pujar / bajar / pasar_deadlock / asignar_rival / abandonar / emote
│   ├── revancha.php       # POST: proponer / rechazar revancha al terminar
│   ├── estado.php         # GET: snapshot + last_seen + aviso rival ausente + abandono
│   ├── salas_gc.php       # GC: borra salas con >1h sin actividad
│   ├── salas/             # JSON por sala en runtime (GC a 1h + TTL pasivo 24h)
│   └── salas/.htaccess    # Bloquea acceso directo
├── manifest.webmanifest   # PWA: instalable en móvil
├── sw.js                  # Service Worker (network-first con cache:'reload')
├── .htaccess              # no-cache para sw.js/manifest + MIME del manifest
├── icons/                 # Iconos PWA generados (192/512/180)
├── css/style.css          # Safe-area iOS, animaciones, reduced-motion, toast, viewport del juego
├── js/bot_policy.js       # Política del bot (función pura, testeable en Node)
└── js/app.js              # Toda la lógica del frontend (vanilla JS)
```

### Decisiones de diseño

- **JSON en disco con `flock`** en vez de base de datos: el MVP cabe perfectamente en un solo directorio. Si crece, se reemplaza la capa `SalaRepository` por SQLite/PostgreSQL sin tocar el resto.
- **Short polling cada 1 s** en vez de WebSockets: más simple, funciona en cualquier hosting compartido, latencia aceptable para un juego por turnos.
- **i18n centralizado** en `lang/es.json`. Los nombres de ítems se resuelven en frontend vía `window.LANG.items[id]`. Las salas solo almacenan IDs, no strings.
- **Items almacenados como `{id, emoji, valor, precio}`**: `valor` viene de la temática (estático), `precio` es lo que el ganador pagó en la subasta (dinámico, puede ser 0 en auto-asignaciones por cap).
- **Victoria por valor, no por dinero**: el ganador se determina por la suma de `valor` de su colección. El dinero restante y el `precio` pagado son solo informativos. Esto convierte la subasta en un mecanismo de selección: gana quien mejor identifica y puja por los ítems premium.
- **Auto-asignación por cap**: si un jugador llega a 4 ítems, los restantes van al rival a precio 0 (sin más subastas). Acepta pequeños sobre-caps en partidas muy desequilibradas — es un tradeoff de simplicidad para MVP.
- **Deadlock sin dinero**: si un jugador sin monedas recibe un ítem fresco, pulsa PASAR y el rival decide (quedárselo por 1 🪙 o regalarlo por 0 🪙). Evita bloqueos al final de la partida.
- **Abandono con gracia**: cada poll actualiza `last_seen[slot]`. A los 6 s sin señales el rival ve un aviso blando (campo `rival_ausente` en la respuesta, sin mutar la sala) y a los 45 s se marca `abandonada`. `visibilitychange` pausa el polling en background y lo reanuda al volver para evitar falsos positivos.
- **Sin límite de tiempo por turno**: se retiró el timeout real (generaba falsos positivos por desfase reloj cliente/servidor). La inactividad se cubre con el abandono de 45 s.
- **GC de salas**: al crear o unirse a una sala se ejecutan `limpiar_salas_antiguas()` (best-effort): borra JSON con `filemtime` > 1h con `flock` no bloqueante para no tocar partidas activas. El TTL de 24h queda como red de seguridad.
- **Revancha sin re-compartir código**: el proponente crea la sala nueva y registra `revancha {por, codigo_nuevo, tematica, ts}` en la vieja (caduca a los 10 min). El rival acepta (unirse) o rechaza desde la pantalla final. Contra el bot, la revancha arranca una partida nueva con el **mismo bot y dificultad** al instante.
- **Vibración háptica** en móvil al ganar ítems, recibir pujas del rival y avisos. **Emotes rápidos** (👍😂🔥😭🤝😱) guardados en la sala (máx 10) y mostrados con el nombre de quien los manda.
- **Bot de práctica con dificultades** (`js/bot_policy.js`, función pura): Fácil (puja poco y se retira pronto), Normal (valoración secreta por hash del id, presupuesto equilibrado y contra-pujas) y Difícil (conoce los valores reales y puja agresivo). Sin trampas en Fácil/Normal; delays humanos y retiradas no deterministas. El bot se marca en la sala (`bot_slot`) y **nunca cuenta como ausente**; su watchdog de 60 s solo corre en su turno (con acción de respaldo garantizada), así que puedes pensar sin prisa.
- **PWA instalable**: manifest + service worker (network-first con `cache: 'reload'`, API siempre red) + iconos generados por script.
- **Cache-busting de assets**: `index.php`/`juego.php` sirven `js/*.js` y `css/style.css` con `?v=filemtime(...)`. Cada deploy cambia la URL y el CDN de Hostinger no puede servir versiones viejas (no hace falta purgar caché).
- **Sin login ni cuentas**: cada sala es anónima, ligada al `localStorage` del navegador.

---

## API REST

Todos los endpoints devuelven JSON. Errores usan códigos HTTP estándar.

### `POST /api/crear_sala.php`

Crea una sala. Jugador 1 la usa.

```json
// Request
{ "tematica": "hamburguesa", "nombre": "Ana" }

// 200 OK
{
  "ok": true,
  "codigo": "A8F3X",
  "jugador_id": "j1_xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
  "sala": { ... }
}
```

### `POST /api/unirse_sala.php`

Jugador 2 entra a una sala existente.

```json
// Request
{ "codigo": "A8F3X", "nombre": "Bea" }

// 200 OK
{ "ok": true, "jugador_id": "j2_...", "sala": { ... } }

// Errores: 404 (no existe), 409 (ya empezó), 400 (código inválido)
```

### `GET /api/estado.php?codigo=XXXXX[&jugador_id=j1_<uuid>]`

Snapshot de la sala. Usado por el polling cada 1 s.

Si se pasa `jugador_id`, el servidor:
1. Actualiza `sala.last_seen[miSlot] = time()`.
2. Devuelve `rival_ausente` (segundos sin señales del rival; `null` si nunca ha polleado).
3. Si el rival lleva > 45 s sin actividad y la sala está `jugando`, la marca como `abandonada` con `abandono_por = otroSlot`.

```json
// 200 OK
{
  "ok": true,
  "rival_ausente": 8,             // segundos, o null
  "sala": {
    "codigo": "A8F3X",
    "estado": "jugando",          // esperando | jugando | finalizada | abandonada
    "tematica": "hamburguesa",
    "items_mezclados": [...],
    "indice_item": 2,
    "item_actual": {
      "id": "ing_xxx", "emoji": "🍞", "precio_actual": 5, "turno_de": 0, "ultimo_pujo": 0,
      "pujas": [{ "por": 0, "incremento": 3, "precio": 5, "ts": 1693574432 }]
    },
    "jugadores": [
      { "id": "j1_...", "nombre": "Ana", "dinero": 15, "items_ganados": [...] },
      { "id": "j2_...", "nombre": "Bea", "dinero": 17, "items_ganados": [] }
    ],
    "ronda": 3,
    "asignacion_forzada_a": null,
    "decision_pendiente": null,         // {para, sobre, motivo} si deadlock sin dinero
    "last_seen": [1693574432, 1693574400],
    "abandono_por": null,               // 0 | 1 cuando estado === 'abandonada'
    "revancha": null,                   // {por, codigo_nuevo, tematica, ts} al terminar
    "emotes": [{ "por": 1, "code": "😂", "ts": 1693574432000 }]
  }
}
```

### `POST /api/accion.php`

Realiza una jugada.

```json
// Request (pujar +1)
{ "codigo": "A8F3X", "jugador_id": "j1_...", "accion": "pujar" }

// Request (pujar +3)
{ "codigo": "A8F3X", "jugador_id": "j1_...", "accion": "pujar", "incremento": 3 }

// Request (bajar)
{ "codigo": "A8F3X", "jugador_id": "j2_...", "accion": "bajar" }

// Request (emote — válido en cualquier momento de la partida)
{ "codigo": "A8F3X", "jugador_id": "j1_...", "accion": "emote", "emote": "😂" }

// Request (abandonar — sin validar turno)
{ "codigo": "A8F3X", "jugador_id": "j1_...", "accion": "abandonar" }

// 200 OK
{ "ok": true, "sala": { ... } }

// Errores frecuentes:
// 400 - falta campo, código/jugador inválido, acción inválida, incremento inválido,
//       emote inválido, bajar sin puja previa, dinero insuficiente
// 403 - jugador_id no pertenece a esta sala
// 404 - sala no encontrada
// 409 - partida no en curso, no es tu turno, ya empezada, sala finalizada
//
// Auto-abandono: si al recibir la petición el OTRO jugador tiene last_seen
// stale > 45 s, la sala se marca como 'abandonada' y se devuelve con la
// acción del solicitante ya tramitada (sin mutar la sala).
```

### `POST /api/revancha.php`

Propuesta de revancha al terminar la partida (solo estado `finalizada`).

```json
// Request (proponer; el proponente debe pertenecer también a la sala nueva)
{ "codigo": "A8F3X", "jugador_id": "j1_...", "accion": "proponer",
  "codigo_nuevo": "B9G4Y", "tematica": "pizza" }

// Request (rechazar; solo el NO proponente)
{ "codigo": "A8F3X", "jugador_id": "j2_...", "accion": "rechazar" }

// 200 OK
{ "ok": true, "sala": { ... } }

// 403 - jugador_id no pertenece a la sala vieja o no es miembro de la nueva
// 409 - partida no finalizada, propuesta ya activa, nada que rechazar
```

### Códigos HTTP

| Código | Significado |
|--------|-------------|
| 200 | OK |
| 400 | Validación de input fallida |
| 403 | Identidad no autorizada para esta sala |
| 404 | Recurso no encontrado |
| 405 | Método HTTP incorrecto |
| 409 | Conflicto de estado (turno, sala empezada, etc.) |
| 500 | Error interno |

---

## Modelo de datos de la sala

Cada sala es un único archivo JSON en `api/salas/<CODIGO>.json`. TTL: 24 h (limpieza al consultar).

```json
{
  "codigo": "A8F3X",
  "estado": "jugando",
  "tematica": "hamburguesa",
  "items_mezclados": ["ing_pan_brioche", "ing_carne_wagyu", ...],
  "indice_item": 2,
  "item_actual": {
    "id": "ing_carne_wagyu",
    "emoji": "🥩",
    "precio_actual": 5,
    "turno_de": 0,
    "ultimo_pujo": 0,
    "auto_asignado": false,
    "pujas": [{ "por": 0, "incremento": 3, "precio": 5, "ts": 1693574432 }]
  },
  "jugadores": [
    {
      "id": "j1_<uuid>",
      "nombre": "Ana",
      "dinero": 15,
      "items_ganados": [
        { "id": "ing_pan_brioche", "emoji": "🍞", "valor": 8, "precio": 3 }
      ]
    },
    { "id": "j2_<uuid>", "nombre": "Bea", "dinero": 17, "items_ganados": [] }
  ],
  "turno_inicial_ronda": 1,
  "ronda": 3,
  "asignacion_forzada_a": null,
  "decision_pendiente": null,
  "last_seen": [1693574432, 1693574400],
  "abandono_por": null,
  "revancha": null,
  "emotes": [],
  "bot_slot": null,
  "creado_en": 1693574400,
  "actualizado_en": 1693574432
}
```

### Concurrencia

Todas las mutaciones usan `flock(LOCK_EX)` + re-lectura tras adquirir el lock para evitar condiciones de carrera entre validación y escritura.

---

## Internacionalización

Toda la UI está en `lang/es.json`. Estructura:

```json
{
  "ui": {
    "app": { "titulo": "...", "subtitulo_lobby": "..." },
    "lobby": { "btn_crear": "...", ... },
    "juego": { "tu_turno": "...", "btn_pujar": "...", ... },
    "estados_sala": { "esperando": "...", "jugando": "...", "finalizada": "..." }
  },
  "tematicas": {
    "hamburguesa": "Hamburguesa",
    "zombies": "Apocalipsis Zombie"
  },
  "items": {
    "ing_pan_brioche": "Pan Brioche Tostado",
    ...
  }
}
```

Para añadir un idioma nuevo: duplicar `lang/es.json` → `lang/<iso>.json` y servir según `Accept-Language` (no implementado aún en el MVP, listo para hook).

---

## Temáticas

Cada temática es un JSON en `tematicas/`:

```json
{
  "id": "hamburguesa",
  "items": [
    { "id": "ing_pan_brioche", "emoji": "🍞" },
    { "id": "ing_carne_wagyu",  "emoji": "🥩" }
  ]
}
```

Reglas:
- `id`: snake_case, solo `[a-z0-9_]`, único.
- `items[].id`: snake_case con prefijo temático (`ing_`, `arm_`, `act_`, `des_`...).
- `items[].emoji`: 1 glifo o shortcode.
- `items[].valor`: entero 1-10 (calidad intrínseca del ítem, escala del "burger battle"). Wagyu y trufa valen 10; ketchup y mostaza valen 1-2. Es el único campo que determina el ganador.
- Mínimo **8 ítems** (se sirven 8 por partida con reparto equilibrado por tiers: ver `seleccionar_items_balanceados()` en `api/crear_sala.php`).
- Cada `id` debe existir en `lang/<iso>.json::items` para resolverse en UI.

### Temáticas incluidas (59, agrupadas en 9 categorías)

El lobby y el modal de revancha las presentan con un **desplegable nativo agrupado por categoría** (todas las temáticas visibles, sin menús que se corten). La opción por defecto es **✨ Todas (aleatoria)**: al crear/revancha se sortea una temática del catálogo; el usuario puede fijar una concreta. La estructura vive en `tematicas_catalogo.php` (fuente única vía `window.__CATEGORIAS`).

- 🍔 **Comida y bebida** — hamburguesa, tapas, pizza, barbacoa, sushi, postres, desayuno, cerveza.
- 🎬 **Cultura pop** — peliculas, videojuegos, musica, series, anime, comics, juegos_mesa, libros, teatro, karaoke.
- ⚽ **Deporte y motor** — futbol, coches, baloncesto, tenis, olimpiadas, gimnasio, esports, boxeo.
- 🐉 **Fantasía y superpoderes** — zombies, poderes, magos, villanos, dragones, fantasmas.
- 🏴‍☠️ **Historia y aventura** — piratas, medieval, gladiador, vikingos, egipto, romanos, samurais, vaqueros.
- 🚀 **Ciencia y tecnología** — espacio, robots, inventos, criptos.
- 🌴 **Naturaleza y viajes** — vacaciones, animales, granja, dinosaurios, fondo_marino, selva, montana, isla_desierta.
- 🏠 **Vida y sociedad** — pareja, oficina, influencer, boda.
- 🕵️ **Crimen y misterio** — atraco, detective, ciberseguridad.

Estándar de calidad del catálogo (validado por `test_catalogo.php`): ≥20 ítems por temática, IDs únicos con formato slug, `valor` 1-10, sin prefijos compartidos entre temáticas, y traducción/label para cada ID en `lang/es.json`.

---

## Limitaciones y siguientes pasos

- **No hay persistencia entre dispositivos**: si recargas el navegador, pierdes la sesión (el `jugador_id` está en `localStorage`). Solución MVP: pasar `jugador_id` por URL.
- **No hay reconexión del que se fue**: el jugador que abandona NO puede volver a la misma sala (el `jugador_id` se borra al pulsar "Salir"). El rival SÍ recibe el mensaje de abandono y vuelve al lobby.
- **Timeout 6s**: si un jugador cierra pestaña o pierde conexión durante >6 s, el rival es notificado. En redes muy lentas con jitter alto, pueden darse falsos positivos — aceptable para MVP.
- **Partidas simultáneas**: no hay límite de salas concurrentes; el cuello de botella es el I/O de disco bajo carga alta.
- **Anti-trampas mínimo**: la validación de turno y de pertenencia está en el servidor, pero no hay rate limiting (un jugador podría spammear `pujar`). Añadir tokens + rate limit en backend si se expone a internet.
- **Mobile-first**: en desktop funciona pero el layout está pensado para portrait.