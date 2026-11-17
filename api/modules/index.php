<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://app.n3bot.com.br');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Empresa-Id');
header('Vary: Origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Método não permitido. Utilize GET.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$arquivo = __DIR__ . '/modules.json';

if (!file_exists($arquivo)) {
    http_response_code(404);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Arquivo modules.json não encontrado.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$conteudo = file_get_contents($arquivo);
$dados = json_decode($conteudo, true);

if (!is_array($dados) || !isset($dados['modulos']) || !is_array($dados['modulos'])) {
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'JSON de módulos inválido.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$somenteAtivos = isset($_GET['active']) &&
    strtolower((string) $_GET['active']) === 'true';

$modulos = $dados['modulos'];

if ($somenteAtivos) {
    $modulos = array_values(array_filter(
        $modulos,
        fn($modulo) => isset($modulo['ativo']) && $modulo['ativo'] === true
    ));
}

usort($modulos, fn($a, $b) =>
    ($a['ordem'] ?? 999) <=> ($b['ordem'] ?? 999)
);

echo json_encode([
    'sucesso' => true,
    'total' => count($modulos),
    'modulos' => $modulos
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
