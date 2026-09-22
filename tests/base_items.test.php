<?php
/**
 * Draft 20 — Test de items base obligatorios (CLI, sin servidor).
 *
 * Uso:  php tests/base_items.test.php
 *
 * Verifica que seleccionar_items_balanceados() incluye sí o sí los $baseIds
 * (pizza: queso + tomate), sin duplicados y completando hasta $n.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Solo CLI.';
    exit;
}

define('SALA_CORE_ONLY', true);
require_once __DIR__ . '/../api/crear_sala.php';

$ok = 0; $fail = 0;
function check(bool $cond, string $msg): void
{
    global $ok, $fail;
    if ($cond) { $ok++; echo "OK   $msg\n"; return; }
    $fail++;
    echo "FAIL $msg\n";
}

$pizza = json_decode((string) file_get_contents(__DIR__ . '/../tematicas/pizza.json'), true);
check(($pizza['base_items'] ?? null) === ['piz_queso', 'piz_tomate'], 'pizza declara base queso+tomate');

for ($i = 1; $i <= 30; $i++) {
    $sel = seleccionar_items_balanceados($pizza['items'], 8, $pizza['base_items'] ?? []);
    $ids = array_map(static fn(array $it): string => (string) $it['id'], $sel);
    check(
        count($ids) === 8
        && in_array('piz_queso', $ids, true)
        && in_array('piz_tomate', $ids, true)
        && count(array_unique($ids)) === 8,
        "sorteo $i incluye base, 8 únicos"
    );
}

$sin = seleccionar_items_balanceados($pizza['items'], 8);
check(count($sin) === 8, 'sin base_ids devuelve 8');

echo "\n=== BASE ITEMS: $ok OK / $fail FAIL ===\n";
exit($fail > 0 ? 1 : 0);
