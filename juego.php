<?php
declare(strict_types=1);

$langFile = __DIR__ . '/lang/es.json';
$LANG = json_decode((string) file_get_contents($langFile), true);
if (!is_array($LANG)) {
    http_response_code(500);
    echo 'Error cargando i18n';
    exit;
}

$codigo = $_GET['codigo'] ?? null;
if (!is_string($codigo) || !preg_match('/^[A-Z0-9]{5}$/', $codigo)) {
    header('Location: index.php');
    exit;
}

// Catálogo compartido: lo usan el header (temática) y el modal de revancha.
$categorias = require __DIR__ . '/tematicas_catalogo.php';
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no">
    <meta name="theme-color" content="#0f172a">
    <title>Draft 20 — Juego</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="apple-touch-icon" href="icons/icon-180.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>window.LANG = <?= json_encode($LANG, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
    <script>window.__CATEGORIAS = <?= json_encode($categorias, JSON_UNESCAPED_UNICODE) ?>;</script>
    <link rel="stylesheet" href="css/style.css?v=<?= filemtime(__DIR__ . '/css/style.css') ?>">
</head>
<body class="app-viewport bg-slate-900 text-slate-100 flex flex-col">
    <main id="app" class="flex-1 flex flex-col"></main>
    <script src="js/bot_policy.js?v=<?= filemtime(__DIR__ . '/js/bot_policy.js') ?>"></script>
    <script src="js/app.js?v=<?= filemtime(__DIR__ . '/js/app.js') ?>"></script>
    <script>
        (function () {
            const codigo = <?= json_encode($codigo, JSON_UNESCAPED_UNICODE) ?>;
            window.__juegoInit(codigo);
        })();
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('sw.js?v=<?= filemtime(__DIR__ . '/sw.js') ?>').catch(function () {});
            });
        }
    </script>
</body>
</html>