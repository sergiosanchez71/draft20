<?php
/**
 * Draft 20 — Contenido SEO: agregador.
 *
 * Une las partes (categorías, fichas y guías) y expone accessors con caché.
 * Todo el contenido es editorial y sin valores ⭐.
 */
declare(strict_types=1);

function contenido_seo(): array
{
    static $data = null;
    if ($data === null) {
        $tematicas = (require __DIR__ . '/contenido_tematicas_1.php') + (require __DIR__ . '/contenido_tematicas_2.php');
        $guias = (require __DIR__ . '/contenido_guias_1.php')
            + (require __DIR__ . '/contenido_guias_2.php')
            + (require __DIR__ . '/contenido_guias_3.php');
        $data = [
            'ui' => [
                'guias_titulo' => 'Guías de Draft 20',
                'guias_sub' => 'Reglas, estrategia y comparativas para sacarle todo el partido al draft de 20 monedas.',
                'migas_guias' => 'Guías',
                'guia_actualizado' => 'Actualizado',
                'tematica_faq_titulo' => 'Preguntas frecuentes',
                'categoria_tematicas_titulo' => 'Temáticas de esta categoría',
                'categoria_otras_titulo' => 'Otras categorías',
                'guia_enlaces_titulo' => 'Sigue leyendo',
                'ver_tematica' => 'Ver temática',
                'leer_guia' => 'Leer guía',
                'guias_ver_todas' => 'Ver todas las guías',
            ],
            'categorias' => require __DIR__ . '/contenido_categorias.php',
            'tematicas' => $tematicas,
            'guias' => $guias,
            // Orden de presentación de las guías (el resto se añade al final).
            'guias_orden' => [
                'draft-de-20-monedas',
                'como-ganar-draft-20',
                'mejores-tematicas',
                'juegos-por-whatsapp',
                'juegos-de-subasta-online',
                'draft-20-vs-you-have-20',
                'juegos-navidad-familia',
                'juegos-verano',
                'juegos-san-valentin',
            ],
        ];
    }
    return $data;
}

function seo_ui(string $clave): string
{
    return (string) (contenido_seo()['ui'][$clave] ?? $clave);
}

/** @return array{descripcion:string,preguntas:array<int,array{q:string,a:string}>}|null */
function tematica_contenido(string $id): ?array
{
    $t = contenido_seo()['tematicas'][$id] ?? null;
    return is_array($t) ? $t : null;
}

/** @return array{titulo:string,intro:array<int,string>}|null */
function categoria_contenido(string $id): ?array
{
    $c = contenido_seo()['categorias'][$id] ?? null;
    return is_array($c) ? $c : null;
}

/** @return array<string,mixed>|null */
function guia_contenido(string $slug): ?array
{
    $g = contenido_seo()['guias'][$slug] ?? null;
    return is_array($g) ? $g : null;
}

/** @return array<string,array<string,mixed>> */
function guias_ordenadas(): array
{
    $data = contenido_seo();
    $guias = $data['guias'];
    $out = [];
    foreach ($data['guias_orden'] as $slug) {
        if (isset($guias[$slug])) {
            $out[$slug] = $guias[$slug];
        }
    }
    foreach ($guias as $slug => $g) {
        if (!isset($out[$slug])) {
            $out[$slug] = $g;
        }
    }
    return $out;
}

function fecha_es(string $iso): string
{
    $ts = strtotime($iso);
    return $ts !== false ? date('d/m/Y', $ts) : $iso;
}

/** Recorta texto respetando palabras (para tarjetas y descripciones). */
function recortar(string $texto, int $max = 150): string
{
    $texto = trim($texto);
    if (mb_strlen($texto) <= $max) {
        return $texto;
    }
    $corte = mb_substr($texto, 0, $max);
    $ultimo = mb_strrpos($corte, ' ');
    if ($ultimo !== false && $ultimo > $max * 0.6) {
        $corte = mb_substr($corte, 0, $ultimo);
    }
    return rtrim($corte, ' ,;.') . '…';
}
