<?php
/**
 * Draft 20 — Ping IndexNow (Bing, Yandex y otros).
 *
 * Uso (CLI):  php tools/indexnow.php
 *
 * Lee las URLs del sitemap en producción y las envía a api.indexnow.org.
 * La clave vive en /draft20indexnow2026.txt (accesible en la raíz del sitio).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Solo CLI.';
    exit;
}

$host = 'draft20.es';
$key = 'draft20indexnow2026';
$sitemapUrl = 'https://' . $host . '/sitemap.xml';

$ctx = stream_context_create(['http' => [
    'timeout' => 25,
    'ignore_errors' => true,
    'user_agent' => 'Draft20-IndexNow/1.0',
]]);

$xml = @file_get_contents($sitemapUrl, false, $ctx);
if ($xml === false || $xml === '') {
    fwrite(STDERR, "ERROR: no se pudo leer {$sitemapUrl}\n");
    exit(1);
}

preg_match_all('#<loc>(.*?)</loc>#', $xml, $m);
$urls = array_values(array_unique(array_map('html_entity_decode', $m[1] ?? [])));
if ($urls === []) {
    fwrite(STDERR, "ERROR: el sitemap no tiene URLs\n");
    exit(1);
}

$payload = json_encode([
    'host' => $host,
    'key' => $key,
    'keyLocation' => 'https://' . $host . '/' . $key . '.txt',
    'urlList' => $urls,
], JSON_UNESCAPED_SLASHES);

$post = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/json; charset=utf-8\r\n",
    'content' => $payload,
    'timeout' => 30,
    'ignore_errors' => true,
    'user_agent' => 'Draft20-IndexNow/1.0',
]]);

$resp = @file_get_contents('https://api.indexnow.org/indexnow', false, $post);
$status = 0;
foreach ($http_response_header ?? [] as $h) {
    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $mm)) {
        $status = (int) $mm[1];
    }
}

echo 'URLs enviadas: ' . count($urls) . "\n";
echo 'HTTP IndexNow: ' . $status . "\n";
echo $status >= 200 && $status < 300
    ? "OK: Bing/Yandex recibirán el ping.\n"
    : "AVISO: respuesta inesperada (" . trim((string) $resp) . ")\n";
exit($status >= 200 && $status < 300 ? 0 : 1);
