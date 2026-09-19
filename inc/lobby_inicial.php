<?php
/**
 * Draft 20 — Lobby inicial renderizado en servidor (index.php).
 *
 * Réplica exacta del DOM que construye renderInitialView() (js/app.core.js):
 * el navegador pinta el lobby en el primer frame y la hidratación de JS
 * reconstruye el mismo árbol, sin CLS. Si cambia la vista en JS, actualizar
 * aquí; tests/ssr_lobby.test.js verifica que ambos árboles siguen coincidiendo.
 */
declare(strict_types=1);

function lobby_inicial_html(?string $tematicaPre = null): string
{
    $L = cargar_lang()['ui']['lobby'] ?? [];
    $cat = static fn(string $k): string => (string) ($L[$k] ?? '');

    $opciones = static function (?string $sel): string {
        $sel = $sel ?? '__random__';
        $lang = cargar_lang();
        $html = '<option value="__random__"' . ($sel === '__random__' ? ' selected' : '') . '>'
            . e((string) ($lang['ui']['lobby']['tematica_aleatoria'] ?? '')) . '</option>';
        foreach (categorias() as $c) {
            $html .= '<optgroup label="' . e(($c['emoji'] ?? '🎲') . ' ' . nombre_categoria((string) $c['id'])) . '">';
            foreach ($c['tematicas'] as $tm) {
                $html .= '<option value="' . e((string) $tm['id']) . '"' . ($sel === $tm['id'] ? ' selected' : '') . '>'
                    . e(($tm['emoji'] ?? '🎲') . ' ' . nombre_tematica((string) $tm['id'])) . '</option>';
            }
            $html .= '</optgroup>';
        }
        return $html;
    };
    $select = static fn(string $id, ?string $sel): string =>
        '<div id="' . $id . '" class="mb-4"><select class="w-full bg-slate-700 text-slate-100 rounded-lg p-3 text-base" aria-label="'
        . e($cat('selector_tematica')) . '">' . $opciones($sel) . '</select></div>';
    $input = static fn(string $id, string $placeholder): string =>
        '<input id="' . $id . '" type="text" maxlength="20" value="" placeholder="' . e($placeholder)
        . '" class="w-full bg-slate-700 text-slate-100 rounded-lg p-3 mb-4 text-base placeholder:text-slate-300">';
    // Por defecto el modo ⭐ va ACTIVADO (igual que en renderInitialView).
    $toggle = static fn(string $wrapper): string =>
        '<div class="' . e($wrapper) . '">'
        . '<button type="button" aria-pressed="true" class="w-full py-3 px-4 rounded-lg border text-sm font-bold btn-tap bg-amber-400 text-slate-900 border-amber-400">'
        . e($cat('mostrar_valores')) . '</button>'
        . '<div class="text-[11px] text-slate-400 mt-2 text-center">' . e($cat('mostrar_valores_ayuda')) . '</div>'
        . '</div>';
    $dificultad = static function (string $wrapper) use ($cat): string {
        $html = '<div class="flex flex-wrap gap-2 justify-center ' . e($wrapper) . '">';
        foreach (['facil', 'normal', 'dificil', 'extremo'] as $nivel) {
            $activo = $nivel === 'normal';
            $css = 'px-3 py-1.5 rounded-full text-xs border btn-tap '
                . ($activo ? 'bg-amber-400 text-slate-900 border-amber-400 font-bold' : 'bg-slate-700 text-slate-200 border-slate-600');
            $html .= '<button type="button" class="' . $css . '">' . e($cat('bot_' . $nivel)) . '</button>';
        }
        return $html . '</div>';
    };

    return '<header class="py-1.5 px-3 flex items-center gap-2 min-h-[44px]">'
        . '<p class="flex-1 text-amber-300/80 text-xs font-mono truncate"></p>'
        . '<button id="btnInstalar" class="w-9 h-9 flex-shrink-0 rounded-full bg-emerald-500 text-slate-900 text-base font-bold btn-tap" aria-label="' . e($cat('pwa_instalar')) . '" title="' . e($cat('pwa_instalar')) . '">📲</button>'
        . '<button class="w-9 h-9 flex-shrink-0 rounded-full bg-slate-700 text-slate-200 text-base font-bold btn-tap" aria-label="' . e($cat('btn_progreso')) . '" title="' . e($cat('btn_progreso')) . '">🏅</button>'
        . '<button class="w-9 h-9 flex-shrink-0 rounded-full bg-slate-700 text-slate-200 text-base font-bold btn-tap" aria-label="' . e($cat('sonido')) . '" title="' . e($cat('sonido')) . '">🔇</button>'
        . '<button class="w-9 h-9 flex-shrink-0 rounded-full bg-slate-700 text-slate-200 text-sm font-bold btn-tap" aria-label="' . e($cat('btn_reglas')) . '" title="' . e($cat('btn_reglas')) . '">?</button>'
        . '</header>'
        . '<div id="lobbyError" class="hidden bg-rose-600 text-white p-3 rounded-lg mx-4 mb-4 text-sm text-center"></div>'
        . '<section class="bg-slate-800 p-6 rounded-lg m-4 fade-in">'
        . '<label class="block text-sm text-slate-400 mb-1">' . e($cat('input_nombre_jugador')) . '</label>'
        . $input('nameRapida', $cat('placeholder_nombre'))
        . $toggle('mb-4')
        . '<button id="btnRapida" class="w-full bg-emerald-500 text-slate-900 font-bold py-4 rounded-lg btn-tap text-lg">⚡ ' . e($cat('btn_rapida')) . '</button>'
        . '<p class="text-[11px] text-slate-400 mt-2 text-center">' . e($cat('rapida_ayuda')) . '</p>'
        . '<div id="colaInfo" class="text-[11px] text-slate-400 mt-1 text-center min-h-[15px]"></div>'
        . '</section>'
        . '<div class="text-center text-slate-400 text-xs my-2">— o —</div>'
        . '<section class="bg-slate-800 p-6 rounded-lg m-4 fade-in">'
        . '<label class="block text-sm text-slate-400 mb-2">' . e($cat('selector_tematica')) . '</label>'
        . $select('tematicaSelector', $tematicaPre)
        . '<label class="block text-sm text-slate-400 mb-1">' . e($cat('input_nombre_jugador')) . '</label>'
        . $input('nameCreate', $cat('placeholder_nombre'))
        . $toggle('mb-4')
        . '<button id="btnCreate" class="w-full bg-amber-400 text-slate-900 font-bold py-4 rounded-lg btn-tap text-lg">' . e($cat('btn_crear')) . '</button>'
        . '</section>'
        . '<div class="text-center text-slate-400 text-xs my-2">— o —</div>'
        . '<section class="bg-slate-800 p-6 rounded-lg m-4 fade-in">'
        . '<label class="block text-sm text-slate-400 mb-1">' . e($cat('label_unirse')) . '</label>'
        . '<input id="codeJoin" type="text" maxlength="5" minlength="5" placeholder="' . e($cat('placeholder_codigo')) . '" class="w-full bg-slate-700 text-slate-100 rounded-lg p-3 mb-4 text-base uppercase tracking-widest text-center text-2xl font-mono placeholder:text-slate-300">'
        . '<label class="block text-sm text-slate-400 mb-1">' . e($cat('input_nombre_jugador')) . '</label>'
        . $input('nameJoin', 'Jugador 2')
        . '<button id="btnJoin" class="w-full bg-emerald-500 text-slate-900 font-bold py-4 rounded-lg btn-tap text-lg">' . e($cat('btn_unirse')) . '</button>'
        . '</section>'
        . '<div class="text-center text-slate-400 text-xs my-2">— o —</div>'
        . '<section class="bg-slate-800 p-6 rounded-lg m-4 fade-in">'
        . '<label class="block text-sm text-slate-400 mb-2">' . e($cat('selector_tematica_bot')) . '</label>'
        . $select('tematicaBotSelector', null)
        . '<label class="block text-sm text-slate-400 mb-2 text-center">' . e($cat('bot_dificultad')) . '</label>'
        . $dificultad('mt-2')
        . $toggle('mt-4')
        . '<button id="btnPractice" class="w-full bg-slate-700 text-slate-200 py-3 rounded-lg btn-tap text-sm mt-4">' . e($cat('btn_practicar')) . '</button>'
        . '<button id="btnGuiada" class="w-full bg-amber-400/90 text-slate-900 font-bold py-3 rounded-lg btn-tap text-sm mt-2">🎓 ' . e($cat('btn_guiada')) . '</button>'
        . '<p class="text-[11px] text-slate-400 mt-1 text-center">' . e($cat('guiada_ayuda')) . '</p>'
        . '</section>';
}
