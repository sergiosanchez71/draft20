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
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no">
    <meta name="theme-color" content="#0f172a">
    <title>Draft 20 — Juego</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>window.LANG = <?= json_encode($LANG, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen flex flex-col">
    <main id="app" class="flex-1 flex flex-col"></main>
    <script src="js/app.js"></script>
    <script>
        (function () {
            const codigo = <?= json_encode($codigo, JSON_UNESCAPED_UNICODE) ?>;
            window.__juegoInit(codigo);
        })();
    </script>
</body>
</html>