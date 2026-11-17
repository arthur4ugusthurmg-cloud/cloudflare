<?php

declare(strict_types=1);

/*
 * N3Bot - Chat interno
 * GET /api/chat-interno/all?offset=0&limit=20&nome=
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Vary: Origin');

$origensPermitidas = [
    'https://n3bot.com.br',
    'https://www.n3bot.com.br',
    'https://app.n3bot.com.br',
];

$origem = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($origem !== '') {
    if (!in_array($origem, $origensPermitidas, true)) {
        responder(403, [
            'sucesso' => false,
            'mensagem' => 'Origem não permitida.',
        ]);
    }

    header("Access-Control-Allow-Origin: {$origem}");
    header('Access-Control-Allow-Credentials: true');
}

header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

function responder(int $status, array $dados): void
{
    http_response_code($status);

    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

$metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($metodo === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($metodo !== 'GET') {
    header('Allow: GET, OPTIONS');

    responder(405, [
        'sucesso' => false,
        'mensagem' => 'Método não permitido.',
    ]);
}

// Parâmetros
$offset = filter_var(
    $_GET['offset'] ?? 0,
    FILTER_VALIDATE_INT
);

$limit = filter_var(
    $_GET['limit'] ?? 20,
    FILTER_VALIDATE_INT
);

$nome = trim((string) ($_GET['nome'] ?? ''));

if ($offset === false || $offset < 0) {
    responder(400, [
        'sucesso' => false,
        'mensagem' => 'Offset inválido.',
    ]);
}

if ($limit === false || $limit < 1 || $limit > 100) {
    responder(400, [
        'sucesso' => false,
        'mensagem' => 'Limit deve estar entre 1 e 100.',
    ]);
}

// AJUSTE estes caminhos para os JSONs reais do chat interno.
$arquivos = [
    __DIR__ . '/all.json'
];

$arquivo = null;

foreach ($arquivos as $caminho) {
    if (is_file($caminho) && is_readable($caminho)) {
        $arquivo = $caminho;
        break;
    }
}

if ($arquivo === null) {
    responder(500, [
        'sucesso' => false,
        'mensagem' => 'Arquivo de dados do chat interno não encontrado. Configure o caminho JSON.',
    ]);
}

$conteudo = file_get_contents($arquivo);

if ($conteudo === false) {
    responder(500, [
        'sucesso' => false,
        'mensagem' => 'Não foi possível ler os dados.',
    ]);
}

try {
    $dados = json_decode($conteudo, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    responder(500, [
        'sucesso' => false,
        'mensagem' => 'O JSON do chat interno é inválido.',
    ]);
}

// Aceita uma lista direta ou uma lista dentro de "usuarios" ou "dados".
if (isset($dados['usuarios']) && is_array($dados['usuarios'])) {
    $registros = $dados['usuarios'];
} elseif (isset($dados['dados']) && is_array($dados['dados'])) {
    $registros = $dados['dados'];
} elseif (is_array($dados) && array_is_list($dados)) {
    $registros = $dados;
} else {
    responder(500, [
        'sucesso' => false,
        'mensagem' => 'Estrutura JSON incompatível com o endpoint.',
    ]);
}

// Filtra pelo nome, login ou usuário.
$termo = function_exists('mb_strtolower')
    ? mb_strtolower($nome, 'UTF-8')
    : strtolower($nome);

$filtrados = [];

foreach ($registros as $registro) {
    if (!is_array($registro)) {
        continue;
    }

    $nomeRegistro = (string) (
        $registro['nome']
        ?? $registro['usuarioNome']
        ?? $registro['usuario']
        ?? $registro['login']
        ?? ''
    );

    $login = (string) (
        $registro['login']
        ?? $registro['usuario']
        ?? ''
    );

    $nomeBusca = function_exists('mb_strtolower')
        ? mb_strtolower($nomeRegistro, 'UTF-8')
        : strtolower($nomeRegistro);

    $loginBusca = function_exists('mb_strtolower')
        ? mb_strtolower($login, 'UTF-8')
        : strtolower($login);

    if (
        $termo !== ''
        && strpos($nomeBusca, $termo) === false
        && strpos($loginBusca, $termo) === false
    ) {
        continue;
    }

    // Nunca retornar tokens ou credenciais ao frontend.
    unset(
        $registro['token'],
        $registro['senha'],
        $registro['password'],
        $registro['authorization']
    );

    $filtrados[] = $registro;
}

$total = count($filtrados);

$resultado = array_slice(
    $filtrados,
    (int) $offset,
    (int) $limit
);

responder(200, [
    'sucesso' => true,
    'dados' => array_values($resultado),
    'total' => $total,
    'offset' => (int) $offset,
    'limit' => (int) $limit,
]);
