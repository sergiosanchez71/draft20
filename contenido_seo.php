<?php
/**
 * Draft 20 — Contenido SEO: agregador.
 *
 * Une las partes (categorías, fichas y guías) y expone accessors con caché.
 * Todo el contenido es editorial y sin valores ⭐.
 */
declare(strict_types=1);

require_once __DIR__ . '/inc/i18n.php';

/** Contenido de una página suelta (faq, estrategia, hub) en el idioma actual. */
function contenido_pagina(string $clave): array
{
    $mapa = ['hub_subasta' => 'hub'];
    $k = $mapa[$clave] ?? $clave;
    if (idioma_actual() === 'en') {
        $en = contenido_en();
        if (is_array($en[$k] ?? null)) {
            return $en[$k];
        }
    }
    return require __DIR__ . '/contenido_' . $clave . '.php';
}

/** Contenido editorial inglés (lang/contenido_en.json), si existe. */
function contenido_en(): array
{
    static $data = null;
    if ($data === null) {
        $file = __DIR__ . '/lang/contenido_en.json';
        $raw = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $data = is_array($raw) ? $raw : [];
    }
    return $data;
}

function contenido_seo(): array
{
    static $data = null;
    if ($data === null) {
        $en = idioma_actual() === 'en' ? contenido_en() : [];
        if ($en !== []) {
            $tematicas = is_array($en['tematicas'] ?? null) ? $en['tematicas'] : [];
            $guias = is_array($en['guias'] ?? null) ? $en['guias'] : [];
            $categorias = is_array($en['categorias'] ?? null) ? $en['categorias'] : [];
        } else {
            $tematicas = (require __DIR__ . '/contenido_tematicas_1.php') + (require __DIR__ . '/contenido_tematicas_2.php');
            $guias = (require __DIR__ . '/contenido_guias_1.php')
                + (require __DIR__ . '/contenido_guias_2.php')
                + (require __DIR__ . '/contenido_guias_3.php')
                + (require __DIR__ . '/contenido_guias_4.php');
            $categorias = require __DIR__ . '/contenido_categorias.php';
        }
        $data = [
            'categorias' => $categorias,
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
                'juegos-por-videollamada',
                'juegos-online-gratis-sin-registro',
                'juegos-para-dos-personas',
                'juegos-rapidos-cinco-minutos',
            ],
        ];
    }
    return $data;
}

function seo_ui(string $clave): string
{
    $texto = t('ui.contenido.' . $clave);
    return $texto !== 'ui.contenido.' . $clave ? $texto : $clave;
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
    return fecha_localizada($iso);
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
