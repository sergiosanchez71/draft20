# Draft 20

Juego de **subasta por turnos para 2 jugadores** en tiempo real, optimizado para móvil. Cada jugador empieza con **20 monedas** y 8 ítems se subastan en rondas alternas. Gana quien reúne la colección de mayor **valor intrínseco** (suma de `valor` de sus ítems).

> Stack: PHP 8 (sin frameworks) + JavaScript vanilla + Tailwind CSS 3.4 compilado (build local). Persistencia en archivos JSON. Cero dependencias en runtime: el build (Tailwind + terser) solo se necesita en desarrollo.

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

### Build (CSS y JS)

Tailwind se compila a `css/tailwind.css` y los JS se minifican a `js/*.min.js` (las páginas usan `asset_js()`, que prefiere la versión minificada si existe):

```bash
npm install     # solo la primera vez
npm run build   # build:css (Tailwind) + build:js (terser)
```

> Tras cambiar clases de Tailwind en PHP/JS, vuelve a ejecutar `npm run build`. Los archivos generados (`css/tailwind.css`, `js/*.min.js`) se commitean: Hostinger no compila en el deploy.

### Tests

```bash
npm test          # catálogo (PHP) + política del bot (Node)
npm run smoke     # E2E: crear → unirse → pujar → bajar → finalizar
```

- `tools/test_catalogo.php`: 20 ítems por temática, tiers 6/7/7, ids únicos y con formato, `valor` 1-10, traducciones presentes y catálogo ↔ ficheros.
- `tests/bot_policy.test.js`: decisiones de la política del bot + fuzz de 2000 estados (solo acciones legales y nunca `null` cuando le toca).
- `tests/rate_limit.test.php`: ventana fija del limitador (permite hasta el máximo, corta, caduca, loopback exento y contadores por IP).
- `tests/a11y.test.js`: axe-core (WCAG A/AA) sobre home, cómo jugar, glosario, ficha, categoría, guía y privacidad; falla con violaciones serious/critical.
- `tools/smoke.php`: partida completa contra el servidor embebido de PHP, con cleanup de salas.

CI lista en **`tools/ci.yml`** (GitHub Actions: `php -l` de todo el repo, los tres tests, `npm run build` y comprobación de que los minificados/CSS commiteados no divergen del fuente). Para activarla, copia el fichero a `.github/workflows/ci.yml` y haz push con un token que tenga el scope `workflow`.

### Despliegue en producción

1. Subir todo el contenido a un servidor con PHP 8+ (en Hostinger: auto-deploy desde GitHub a `public_html`).
2. Apuntar el document root a la raíz del proyecto (donde está `index.php`).
3. Asegurar que el directorio `api/salas/` tiene permisos de escritura para el usuario del servidor web.
4. El directorio `api/salas/` debe estar bloqueado vía `.htaccess` (ya incluido) si usas Apache.
5. **Dominio**: `https://draft20.es` (Hostinger, SSL + CDN). El `.htaccess` fuerza HTTPS, redirige `www` → sin `www` y aplica las URLs limpias; configura la versión PHP 8.2 en el panel.
6. **Search Console**: verificar la propiedad (DNS TXT) y enviar `https://draft20.es/sitemap.xml`.
7. **Caché del CDN**: los assets van versionados con `?v=filemtime(...)`, así que cada deploy genera URLs nuevas y no hace falta purgar caché. Si tras desplegar ves la versión antigua, haz un hard reload una vez (el `sw.js` y el `manifest` van con `no-cache` por `.htaccess`) y, si persiste, purga la caché del CDN una única vez.
8. **Precalentado tras cada deploy**: `npm run warmup` (recorre HTML, fichas, assets versionados, sitemap, sw… para calentar origen y CDN y evitar la ráfaga de la primera oleada). Si algo va lento: `npm run latencia` (mediana/p95 por endpoint; picos al minuto del deploy y normales a los 10 min = arranque en frío).

---

## Cómo se juega

1. **Jugador 1** abre la app, elige temática y nombre → pulsa **Crear sala privada** → recibe un código de 5 caracteres.
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
- **Abandono**: pulsar **Salir al lobby** notifica al rival y termina la partida. Si cierras la pestaña o pierdes conexión, el rival ve un aviso a los **6 s** ("rival desconectado") y la partida se da por **abandonada a los 120 s** sin actividad (margen amplio para cortes móviles).
- **Victoria**: gana el jugador cuya **colección de ítems tenga mayor valor intrínseco** (suma de `valor`, escala 1-10). En caso de empate a ⭐, gana quien conserve **más monedas**; si también empatan, tablas. El precio pagado es informativo.
- **Revancha**: al terminar, cualquiera puede proponer revancha (por defecto **🎲 temática al azar**, o elegir una concreta). El rival la acepta o rechaza desde la pantalla final.
- **Valores ocultos**: el ⭐ de cada ítem es secreto durante la partida (ni en la carta ni en el inventario). Solo se revela en la pantalla final. Con el modo **⭐ Valores visibles** (opcional, lo fija quien crea la sala) el ⭐ del ítem en juego y el de los ítems del inventario se muestran a ambos jugadores.
- **Último ítem**: al terminar la partida se avisa con un toast (se va solo) del 8º ítem (el que ya no llega a pintarse en la carta): quién se lo llevó y por cuántas monedas.

---

## Arquitectura

```
draft20/
├── index.php              # Landing SEO + lobby: crear / unirse a sala
├── juego.php              # UI principal del juego (noindex)
├── tematica.php           # Ficha SEO de temática (/tematica/<id>, 72 URLs)
├── categoria.php          # Hub de categoría (/categoria/<id>, 10 URLs)
├── guias.php              # Índice de guías (/guias)
├── guia.php               # Guía individual (/guia/<slug>, 6 URLs)
├── contenido_categorias.php / contenido_tematicas_*.php / contenido_guias_*.php
│                          # Texto editorial único (sin valores ⭐) para hubs, fichas y guías
├── contenido_seo.php      # Agregador + accessors del contenido SEO
├── como_jugar.php         # Guía completa (/como-jugar)
├── acerca.php             # Acerca de (/acerca)
├── contacto.php           # Contacto (/contacto)
├── privacidad.php         # Privacidad (/privacidad)
├── 404.php                # Página no encontrada (ErrorDocument)
├── feed.php               # Feed RSS 2.0 de guías (/feed.xml) para acelerar la indexación
├── sitemap.php            # sitemap.xml dinámico (94 URLs: home + soporte + hubs + guías + 72 fichas)
├── robots.txt             # Allow / · Disallow /api/ y /juego.php
├── inc/layout.php         # Helpers SEO: metas, canonical, OG, JSON-LD, footer, assets
├── tematicas_catalogo.php # Catálogo de temáticas por categoría (fuente única)
├── lang/es.json           # Strings UI + temáticas + ítems + textos SEO (i18n)
├── tematicas/             # 55 temáticas (20 ítems c/u; id + emoji + valor)
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
├── sw.js                  # Service Worker v5 (navegación red-first, assets SWR con match exacto)
├── .htaccess              # HTTPS + www→sin www, 301 de /index.php, URLs limpias, deflate, caché, 404
├── llms.txt               # Resumen del sitio para agentes de IA (Markdown con H1)
├── ads.txt                # Declaración de publisher para AdSense
├── favicon.ico            # Icono (PNG embebido 16+32)
├── og-image.png           # Imagen para compartir (1200x630)
├── icons/                 # Iconos PWA generados (192/512/180)
├── css/tailwind.src.css   # Entrada de Tailwind (@tailwind base/components/utilities)
├── css/tailwind.css       # Tailwind compilado y minificado (generado, commiteado)
├── css/style.css          # Safe-area iOS, animaciones, reduced-motion, toast, viewport del juego
├── tailwind.config.js     # Content: ./*.php, inc/**, js/**
├── tools/indexnow.php     # Ping IndexNow (Bing/Yandex) tras cada deploy
├── draft20indexnow2026.txt # Clave IndexNow (raíz, accesible por HTTP)
├── package.json           # Scripts de build (tailwindcss + terser)
├── js/app.core.js         # Helpers + lobby (se carga también en la landing)
├── js/app.game.js         # Vista de juego (solo en juego.php)
├── js/bot_policy.js       # Política del bot (función pura, testeable en Node)
└── js/*.min.js            # Versiones minificadas (generadas, commiteadas)
```

### Decisiones de diseño

- **JSON en disco con `flock`** en vez de base de datos: el MVP cabe perfectamente en un solo directorio. Si crece, se reemplaza la capa `SalaRepository` por SQLite/PostgreSQL sin tocar el resto.
- **Short polling cada 1 s** en vez de WebSockets: más simple, funciona en cualquier hosting compartido, latencia aceptable para un juego por turnos.
- **i18n centralizado** en `lang/es.json`. Los nombres de ítems se resuelven en frontend vía `window.LANG.items[id]`. Las salas solo almacenan IDs, no strings.
- **Items almacenados como `{id, emoji, valor, precio}`**: `valor` viene de la temática (estático), `precio` es lo que el ganador pagó en la subasta (dinámico, puede ser 0 en auto-asignaciones por cap).
- **Victoria por valor, no por dinero**: el ganador se determina por la suma de `valor` de su colección. El dinero restante y el `precio` pagado son solo informativos. Esto convierte la subasta en un mecanismo de selección: gana quien mejor identifica y puja por los ítems premium.
- **Auto-asignación por cap**: si un jugador llega a 4 ítems, los restantes van al rival a precio 0 (sin más subastas). Acepta pequeños sobre-caps en partidas muy desequilibradas — es un tradeoff de simplicidad para MVP.
- **Deadlock sin dinero**: si un jugador sin monedas recibe un ítem fresco, pulsa PASAR y el rival decide (quedárselo por 1 🪙 o regalarlo por 0 🪙). Evita bloqueos al final de la partida.
- **Abandono con gracia**: cada poll actualiza `last_seen[slot]`, persistido como mucho cada 5 s para no escribir en disco en cada petición. A los 6 s sin señales el rival ve un aviso blando (campo `rival_ausente` en la respuesta, sin mutar la sala) y a los 120 s se marca `abandonada`. En segundo plano el polling no se corta: baja a 10 s en partida (5 s en el lobby) y se para en `pagehide`, reanudándose con un poll inmediato al volver. Así un móvil bloqueado no provoca ni avisos ni abandonos falsos.
- **Sin límite de tiempo por turno**: se retiró el timeout real (generaba falsos positivos por desfase reloj cliente/servidor). La inactividad se cubre con el abandono de 120 s.
- **GC de salas**: al crear o unirse a una sala se ejecutan `limpiar_salas_antiguas()` (best-effort): borra JSON con `filemtime` > 1h con `flock` no bloqueante para no tocar partidas activas. El TTL de 24h queda como red de seguridad.
- **Revancha sin re-compartir código**: el proponente crea la sala nueva y registra `revancha {por, codigo_nuevo, tematica, ts}` en la vieja (caduca a los 10 min). El rival acepta (unirse) o rechaza desde la pantalla final. Contra el bot, la revancha arranca una partida nueva con el **mismo bot y dificultad** al instante.
- **Vibración háptica** en móvil al ganar ítems, recibir pujas del rival y avisos. **Emotes rápidos** (👍😂🔥😭🤝😱) guardados en la sala (máx 10) y mostrados con el nombre de quien los manda.
- **Bot de práctica con dificultades** (`js/bot_policy.js`, función pura): Fácil (intuye los valores con mucho ruido, puja poco y se retira pronto), Normal (intuición ligera y presupuesto equilibrado; partida pareja), Difícil (valores reales y reparto racional moderado del presupuesto) y Extremo (valores reales + reparto agresivo y selectivo por los mejores ítems). Niveles calibrados contra un jugador medio simulado en los dos modos: objetivo de victorias 30/50/70/90%. Con «⭐ Valores visibles» todos los niveles ven los valores reales (el humano también), salvo el handicap de Fácil, que mantiene su intuición. Delays humanos y retiradas no deterministas. El bot se marca en la sala (`bot_slot`) y **nunca cuenta como ausente**; su watchdog de 60 s solo corre en su turno (con acción de respaldo garantizada), así que puedes pensar sin prisa.
- **PWA instalable**: manifest + service worker v5 (navegación red-first con fallback a la home cacheada, assets stale-while-revalidate con **match exacto** para que un `?v=` nuevo nunca reutilice caché antigua, API siempre red) + iconos generados por script. El lobby ofrece **"Añadir a pantalla de inicio"**: usa el prompt nativo cuando el navegador lo permite (`beforeinstallprompt`) y, si no, muestra los pasos manuales de Android, iOS y escritorio.
- **Progreso local (sin cuentas)**: récord y racha, 6 logros, serie al mejor de 3 por rival (vigencia 30 min), historial de las últimas 10 partidas y modal **Mi progreso** en el lobby. **Sonido opcional** generado con WebAudio (off por defecto) y respeto a `prefers-reduced-motion`. **Práctica guiada** contra el bot fácil con ⭐ visibles y pasos explicados.
- **Cache-busting de assets**: `inc/layout.php::asset()` sirve `js/*`, `css/*` e imágenes con `?v=filemtime(...)`. Cada deploy cambia la URL y el CDN de Hostinger no puede servir versiones viejas (no hace falta purgar caché).
- **SEO**: dominio `https://draft20.es` (canonical sin www; 301 de `www`, HTTPS forzado y **301 de `/index.php` → `/`** en `.htaccess`), URLs limpias (`/tematica/<id>`, `/categoria/<id>`, `/guias`, `/guia/<slug>`, `/glosario`, `/juegos-de-subasta`, `/como-jugar`, `/feed.xml`, `/sitemap.xml`), landing server-side con H1/intro/categorías/FAQ y **temática del día** (rotación por fecha, enlace interno a la ficha), 72 fichas de temática enriquecidas (texto editorial único + FAQ propia, ítems sin valores ⭐) con `BreadcrumbList`+`ItemList`, 10 hubs de categoría, **hub `/juegos-de-subasta`** (`CollectionPage`+`FAQPage`), **11 guías** (incluidas 3 estacionales: Navidad, verano y San Valentín) con enlaces internos a temáticas (validados en `tools/test_catalogo.php`), **glosario** con `DefinedTermSet`, feed RSS de guías, JSON-LD `Organization`+`WebSite` en todas las páginas, `WebApplication`+`FAQPage` en la home, `Article` (con `image` y `publisher`) en guías y `/como-jugar` y `HowTo` en `/como-jugar`, **OG por temática, categoría, guía y sección** (`/og/tematica/<id>.png`, `/og/categoria/<id>.png`, `/og/guia/<slug>.png`, `/og/seccion/<id>.png`), `juego.php` y `?sala=` con `noindex`, `robots.txt` + sitemap dinámico (**102 URLs** con `lastmod` real por archivo) + `llms.txt` e IndexNow (`tools/indexnow.php`) para Bing/Yandex.
  Las imágenes OG se regeneran con `php tools/og_data.php && powershell -File tools/gen_og.ps1` (opcional `-Tipo tematica|categoria|guia|seccion` y `-Only <id>`).
- **Métricas propias sin cookies**: `api/evento.php` guarda contadores diarios agregados (páginas vistas y partidas por tipo) en `api/datos/stats/`; el beacon va en las páginas SEO (con nonce CSP) y los eventos de juego (`game:creada|rapida|bot|fin`, `pwa:install`) se envían desde el cliente. Sin cookies, sin identificadores y sin guardar IP. Informe en CLI: `php tools/stats.php [días]` (eventos + partidas de `api/salas`).
- **Publicidad (AdSense)**: loader de Google AdSense en todas las páginas (en `inc/layout.php` para las de contenido y en `juego.php` para la partida) con cuatro **bloques manuales de tamaño fijo** (cero CLS): banner 320x50 en la partida (`ADSENSE_SLOT_JUEGO`, oculto en final/abandono), rectángulo 300x250 en la pantalla final (`ADSENSE_SLOT_FINAL`, con contenedores persistentes para no repetir peticiones en los repintados por revancha), horizontal 320x100 en el lobby (`ADSENSE_SLOT_LOBBY`, estático fuera de `#app`) y 300x250 in-article reutilizado en temáticas, guías, hub y cómo jugar (`ads_slot()`, una vez por página, sin Auto ads). La CSP (`csp_policy()`) permite los dominios de AdSense y de la CMP de Google (`fundingchoicesmessages.google.com`); `ads.txt` declara el publisher y la privacidad (`lang/es.json` → `priv_*`) informa de las cookies de terceros y de cómo retirar el consentimiento (CMP en AdSense → Privacidad y mensajería).
- **Materiales de difusión**: `docs/difusion.md` (3 guiones de TikTok/Reels, posts para Reddit ES/EN, textos y sitios para directorios de juegos, mensaje para micro-creadores y checklist).
- **Rendimiento**: Tailwind compilado (14 KB) e **inline en las páginas SEO** (cero CSS render-blocking), JS dividido (`app.core.min.js` 14 KB en la landing; `app.game.min.js` 27 KB solo en `juego.php`), `window.LANG` recortado en la landing (solo `ui` + temáticas), service worker v5 sin `cache:'reload'`, redirecciones a 1 salto y render del juego por **firma de estado** (el poll de 1 s no reconstruye el DOM si nada cambió). La landing puede cachearse 10 min en el CDN (`s-maxage=600`, sin query). El **lobby inicial va renderizado en servidor** (`inc/lobby_inicial.php`) y JS lo hidrata sin salto (CLS 0; paridad cubierta por `tests/ssr_lobby.test.js`).
- **Endurecimiento básico (P0)**: las respuestas de la API ocultan `jugadores[].id` (token de auth) y `last_seen`, y usan `mi_slot`/`total_items`; `asignar_rival` solo acepta las dos decisiones legales (quedárselo por 1 🪙 o regalarlo a quien cedió); `/tematicas/*.json` no se sirve por HTTP (los valores llegan por API solo con el modo visible o en salas de bot); el polling se detiene al terminar la partida y ante 404/403 vuelve al lobby; `last_seen` se inicializa al crear/unir; la revancha valida la sala nueva con `jugador_id_nuevo` (el id que devuelve `crear_sala`) y exige que siga libre; solo el creador puede sentar un bot (`creador_id`); y `abandonar`/`emote` se procesan antes del handler de cap.
- **Anti-abuso (P2)**: rate limit por IP+endpoint (`inc/rate_limit.php`, ventana fija, exento en loopback): `crear_sala` 20/h, `unirse` 60/h, `accion` 240/min (emote 60/min), `revancha` 60/h, `registrar_partida` 60/h; cupo global de 300 salas activas (503); guarda de `Origin`/`Sec-Fetch-Site` para peticiones cross-site; cabeceras `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy` y CSP en modo informe. Las escrituras siguen siendo `flock` + `ftruncate` (serializa bien; el rename atómico añadiría riesgo sin beneficio claro).
- **Partida rápida (matchmaking)**: `api/partida_rapida.php` empareja en el lobby con el primero que también pulse "Partida rápida" **y tenga el mismo modo de "⭐ Valores visibles"** (emparejamiento estricto: si espera alguien con otro modo, no se empareja y se crea sala propia; puede haber una entrada esperando por cada modo). Cola en `api/datos/cola_rapida.json` con purga de entradas muertas (sala inexistente/empezada u oyente sin poll > 60 s); el lobby muestra un contador ("2 esperando · 1 con ⭐") y la vista de búsqueda lista quién espera (nombre + ⭐) con refresco cada 5 s (acción `cola`, saneada: sin código ni `jugador_id`). Si nadie entra, se ofrece practicar contra el bot (con dificultad) sin dejar de buscar: la sala y el enlace siguen vivos y, si alguien se une mientras practicas, la partida del bot avisa con cuenta atrás ("{nombre} se ha unido · entras en 5 s", con botón **Entrar ya**) y te lleva a la partida humana abandonando la de práctica.
- **Conversión (P4-E)**: la home detecta una partida en curso en `localStorage` y ofrece "Continuar"; las fichas de temática crean la sala en **1 clic** (inline JS con fallback al lobby); **QR de la sala** en el lobby y en la espera de revancha (librería `qr-creator` MIT vendorizada, se carga bajo demanda); prefetch del bundle del juego desde la landing; y **aviso legal** (`/aviso-legal`) para la LSSI.
- **Iconos**: los ítems se pintan con **Fluent Emoji** de Microsoft en SVG (estilo Color, licencia **MIT**, ver `img/emoji/LICENSE`), con las 24 banderas de país generadas localmente en estilo simplificado y **fallback automático al emoji de texto** si falta un asset. Se regeneran con `npm run gen:assets` (descarga de Microsoft + banderas + optimización SVGO). El test de catálogo verifica que todos los emojis usados tienen icono.
- **Favicon**: `icons/favicon.ico` (16/32/48, con copia en la raíz para el descubrimiento por defecto) y `icons/icon-48.png` / `icon-96.png` se generan desde `icon-512.png` con `npm run gen:icons`. Se declaran con **rutas absolutas** (`asset()` devuelve `/…`) para que también funcionen en las URLs bonitas (`/tematica/<id>`, `/guia/<slug>`), con `?v=` para saltar la caché del CDN, y en tamaños **múltiplos de 48** que son los que Google usa para el favicon en los resultados de búsqueda.
- **Sin login ni cuentas**: cada sala es anónima, ligada al `localStorage` del navegador.

---

## API REST

Todos los endpoints devuelven JSON. Errores usan códigos HTTP estándar.

### `POST /api/crear_sala.php`

Crea una sala. Jugador 1 la usa.

```json
// Request
{ "tematica": "hamburguesa", "nombre": "Ana", "mostrar_valores": false }

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
1. Actualiza `sala.last_seen[miSlot]` (persistido como mucho cada 5 s).
2. Devuelve `rival_ausente` (segundos sin señales del rival; `null` si nunca ha polleado).
3. Si el rival lleva > 120 s sin actividad y la sala está `jugando`, la marca como `abandonada` con `abandono_por = otroSlot`.

**La respuesta nunca expone datos internos**: ni `jugadores[].id` (es el token de autenticación), ni `last_seen`. En su lugar devuelve `mi_slot` (tu índice) y `total_items`. En partidas entre humanos tampoco se expone `items_mezclados`; `valores` (mapa id→⭐) solo viaja con el modo "Valores visibles" o en salas contra bot.

```json
// 200 OK
{
  "ok": true,
  "rival_ausente": 8,             // segundos, o null
  "sala": {
    "codigo": "A8F3X",
    "estado": "jugando",          // esperando | jugando | finalizada | abandonada
    "tematica": "hamburguesa",
    "total_items": 8,
    "mi_slot": 0,
    "indice_item": 2,
    "item_actual": {
      "id": "ing_xxx", "emoji": "🍞", "precio_actual": 5, "turno_de": 0, "ultimo_pujo": 0,
      "pujas": [{ "por": 0, "incremento": 3, "precio": 5, "ts": 1693574432 }]
    },
    "jugadores": [
      { "nombre": "Ana", "dinero": 15, "items_ganados": [...] },
      { "nombre": "Bea", "dinero": 17, "items_ganados": [] }
    ],
    "ronda": 3,
    "asignacion_forzada_a": null,
    "decision_pendiente": null,         // {para, sobre, motivo} si deadlock sin dinero
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
// stale > 120 s, la sala se marca como 'abandonada' y se devuelve con la
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
  "mostrar_valores": false,
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

### Temáticas incluidas (55, agrupadas en 9 categorías + ⭐ Destacados)

El estilo sigue el trend viral del *$20 draft* (["You Have $20!"](https://youhave20.com)): cada ítem es un **nombre propio reconocible** (personaje, obra, modelo, marca) y los “malos” son también cosas concretas (objetos cutres, títulos malos legendarios, personajes ridículos) — nunca acciones ni estados. Las últimas incorporaciones salen de investigar los virales del TikTok español: Mundial 2026, Eurovisión/Benidorm Fest, Mercadona, nostalgia Tuenti/MSN, Navidad, programas de TV y F1.

El lobby y el modal de revancha las presentan con un **desplegable nativo agrupado por categoría** (todas las temáticas visibles, sin menús que se corten). La opción por defecto es **✨ Todas (aleatoria)**: al crear/revancha se sortea una temática del catálogo; el usuario puede fijar una concreta. La estructura vive en `tematicas_catalogo.php` (fuente única vía `window.__CATEGORIAS`).

- 🍔 **Comida y bebida** — hamburguesa, tapas, pizza, barbacoa, sushi, postres, cerveza.
- 🎬 **Cine, series y música** — peliculas, series, anime, comics, libros, musica, karaoke, teatro, villanos.
- 📱 **Viral (personajes)** — simpsons, disney, marvel_dc, dragon_ball, one_piece, harry_potter, star_wars, pokemon, pop_stars, streamers, wwe.
- ⚽ **Deporte y motor** — futbol, nba, tenis, boxeo, esports, coches, olimpiadas, f1.
- 🎮 **Ocio y juegos** — videojuegos, juegos_mesa, consolas, moviles, juguetes.
- 🐉 **Fantasía y aventura** — zombies, piratas, vikingos, romanos, egipto, samurais, vaqueros.
- 🚀 **Ciencia y tecnología** — espacio, robots.
- 🌴 **Naturaleza y animales** — animales, dinosaurios, fondo_marino, granja.
- 🕵️ **Crimen y misterio** — atraco, detective.

Estándar de calidad del catálogo (validado por `test_catalogo.php`): 20 ítems por temática (6 premium / 7 medios / 7 malos), IDs únicos con formato slug, `valor` 1-10, sin prefijos compartidos entre temáticas, y traducción/label para cada ID en `lang/es.json`.

> Nota legal: los nombres de personajes, obras y marcas se usan con fines de entretenimiento y referencia; el proyecto no está afiliado ni respaldado por sus titulares.

---

## Limitaciones y siguientes pasos

- **No hay persistencia entre dispositivos**: si cambias de navegador o borras los datos del sitio, pierdes la sesión (el `jugador_id` vive en `localStorage`). El banner "Partida en curso" recupera salas activas del mismo dispositivo.
- **El que sale con "Salir al lobby" no puede volver**: se notifica al rival y la partida termina. Para el caso accidental (botón atrás, recarga) la sala sigue accesible hasta que se limpia por GC.
- **Aviso a los 6 s / abandono a los 120 s**: el aviso blando puede aparecer antes en redes con jitter alto; el margen de 120 s y el poll reducido en segundo plano evitan abandonos falsos.
- **Endurecimiento activo**: rate limit por IP+bucket, comprobación de origen, ids de jugador ocultos en las respuestas, cap de salas y auto-GC. No hay cuentas ni anticheat serio: un cliente podría automatizar acciones dentro de su turno.
- **Mobile-first**: en desktop funciona pero el layout está pensado para portrait.