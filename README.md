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
- **Cuenta atrás 60s**: visible solo durante tu turno para animar a decidir. No fuerza acciones; el abandono real es por polling.
- **Abandono**: si pulsas **Salir al lobby** durante una partida, se notifica al rival y la partida termina. Si cierras la pestaña o pierdes la conexión durante >6 s, el rival también recibe el aviso automáticamente.
- **Victoria**: gana el jugador cuya **colección de ítems tenga mayor valor intrínseco** (suma de `valor` de cada ítem, definido en la temática, escala 1-10). El dinero restante y el precio pagado son **informativos**, no determinan el ganador. Esto significa que la estrategia es pujar por ítems de calidad, no acumular dinero.

---

## Arquitectura

```
draft20/
├── index.php              # Lobby: crear / unirse a sala
├── juego.php              # UI principal del juego
├── lang/es.json           # Strings UI + nombres de ítems y temáticas (i18n)
├── tematicas/             # Definición de las 4 temáticas (ítems por juego)
│   ├── hamburguesa.json
│   ├── zombies.json
│   ├── peliculas.json
│   ├── vacaciones.json
│   └── futbol.json
├── api/                   # Backend PHP (todos devuelven JSON)
│   ├── crear_sala.php     # POST: crea sala, devuelve codigo + jugador_id
│   ├── unirse_sala.php    # POST: J2 entra a sala existente
│   ├── accion.php         # POST: pujar / bajar / pasar_deadlock / asignar_rival / abandonar
│   ├── estado.php         # GET: snapshot actual de la sala (polling + last_seen + timeout)
│   ├── salas/             # JSON por sala en runtime (auto-limpieza por TTL)
│   └── salas/.htaccess    # Bloquea acceso directo
├── css/style.css          # Safe-area iOS, animaciones, scrollbar oculto
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
- **Abandono y reconexión mínima**: cada poll actualiza `last_seen[slot]`. Si un jugador deja de aparecer >6 s, el rival recibe la sala en estado `abandonada` con mensaje dedicado. Pulsar **Salir** voluntariamente dispara la misma señal vía acción `abandonar`.
- **Cuenta atrás 60s**: se muestra solo durante tu turno en la tarjeta del ítem. Es puramente visual — el abandono real sigue siendo el polling timeout. Esto da presión psicológica sin forzar acciones.
- **Vibración háptica** en móvil cuando ganas un ítem o cuando el rival abandona (sin audio para mantenerlo silencioso).
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
2. Comprueba `last_seen[otroSlot]`: si está stale > 6 s y la sala está `jugando`, la marca como `abandonada` con `abandono_por = otroSlot`.

```json
// 200 OK
{
  "ok": true,
  "sala": {
    "codigo": "A8F3X",
    "estado": "jugando",          // esperando | jugando | finalizada | abandonada
    "tematica": "hamburguesa",
    "items_mezclados": [...],
    "indice_item": 2,
    "item_actual": { "id": "ing_xxx", "emoji": "🍞", "precio_actual": 5, "turno_de": 0, "ultimo_pujo": 0 },
    "jugadores": [
      { "id": "j1_...", "nombre": "Ana", "dinero": 15, "items_ganados": [...] },
      { "id": "j2_...", "nombre": "Bea", "dinero": 17, "items_ganados": [] }
    ],
    "ronda": 3,
    "asignacion_forzada_a": null,
    "decision_pendiente": null,         // {para, sobre, motivo} si deadlock sin dinero
    "last_seen": [1693574432, 1693574400],
    "abandono_por": null                // 0 | 1 cuando estado === 'abandonada'
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

// Request (abandonar — sin validar turno)
{ "codigo": "A8F3X", "jugador_id": "j1_...", "accion": "abandonar" }

// 200 OK
{ "ok": true, "sala": { ... } }

// Errores frecuentes:
// 400 - falta campo, código/jugador inválido, acción inválida, incremento inválido,
//       bajar sin puja previa, dinero insuficiente
// 403 - jugador_id no pertenece a esta sala
// 404 - sala no encontrada
// 409 - partida no en curso, no es tu turno, ya empezada, sala finalizada
//
// Auto-abandono: si al recibir la petición el OTRO jugador tiene last_seen
// stale > 6 s, la sala se marca como 'abandonada' y se devuelve con la
// acción del solicitante ya tramitada en segundo plano (sin mutar).
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
    "auto_asignado": false
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

### Temáticas incluidas

- `hamburguesa` — Ingredientes para la hamburguesa perfecta.
- `zombies` — Apocalipsis: armas, refugios y compañeros.
- `peliculas` — Director de cine: actores, géneros y presupuestos.
- `vacaciones` — Plan de vacaciones: destinos, alojamientos y transporte.
- `futbol` — Leyendas mundiales (Messi Prime, CR7, Ronaldinho, Zidane, Ronaldo Nazário, Iniesta, Maldini, Haaland, Mbappé, Casillas) vs. jugadores "meme" (Maguire, Antony, Karius, Lord Bendtner, Hazard RM, Dembélé lesionado, Gravesen, Mariano, Chygrynskiy, Ali Dia).

---

## Limitaciones y siguientes pasos

- **No hay persistencia entre dispositivos**: si recargas el navegador, pierdes la sesión (el `jugador_id` está en `localStorage`). Solución MVP: pasar `jugador_id` por URL.
- **No hay reconexión del que se fue**: el jugador que abandona NO puede volver a la misma sala (el `jugador_id` se borra al pulsar "Salir"). El rival SÍ recibe el mensaje de abandono y vuelve al lobby.
- **Timeout 6s**: si un jugador cierra pestaña o pierde conexión durante >6 s, el rival es notificado. En redes muy lentas con jitter alto, pueden darse falsos positivos — aceptable para MVP.
- **Partidas simultáneas**: no hay límite de salas concurrentes; el cuello de botella es el I/O de disco bajo carga alta.
- **Anti-trampas mínimo**: la validación de turno y de pertenencia está en el servidor, pero no hay rate limiting (un jugador podría spammear `pujar`). Añadir tokens + rate limit en backend si se expone a internet.
- **Mobile-first**: en desktop funciona pero el layout está pensado para portrait.