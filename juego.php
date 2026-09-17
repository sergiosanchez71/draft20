<?php
/**
 * Draft 20 — Shell de la partida.
 * Página de juego: noindex (solo accesible por código de sala).
 */
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';

$LANG = cargar_lang();
if ($LANG === []) {
    http_response_code(500);
    echo 'Error cargando i18n';
    exit;
}

$codigo = $_GET['codigo'] ?? null;
if (!is_string($codigo) || !preg_match('/^[A-Z0-9]{5}$/', $codigo)) {
    header('Location: /');
    exit;
}

// Catálogo compartido: lo usan el header (temática) y el modal de revancha.
$categorias = categorias();
csp_headers();
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <?= csp_meta() ?>
    <meta name="theme-color" content="#0f172a">
    <title>Draft 20 — Partida</title>
    <meta name="robots" content="noindex, follow">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="<?= e(asset('icons/icon-180.png')) ?>">
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <?= tailwind_tag() ?><link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
    <script nonce="<?= e(csp_nonce()) ?>">window.LANG = <?= json_encode($LANG, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
    <script nonce="<?= e(csp_nonce()) ?>">window.__CATEGORIAS = <?= json_encode($categorias, JSON_UNESCAPED_UNICODE) ?>;</script>
</head>
<body class="app-viewport bg-slate-900 text-slate-100 flex flex-col">
    <main id="app" class="flex-1 flex flex-col"></main>
    <script src="<?= e(asset_js('js/bot_policy.js')) ?>"></script>
    <script src="<?= e(asset_js('js/app.core.js')) ?>"></script>
    <script src="<?= e(asset_js('js/app.game.js')) ?>"></script>
    <script nonce="<?= e(csp_nonce()) ?>">
        (function () {
            const codigo = <?= json_encode($codigo, JSON_UNESCAPED_UNICODE) ?>;
            window.__juegoInit(codigo);
        })();
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('/sw.js?v=<?= is_file(__DIR__ . '/sw.js') ? filemtime(__DIR__ . '/sw.js') : '1' ?>').catch(function () {});
            });
        }
    </script>
</body>
</html>
