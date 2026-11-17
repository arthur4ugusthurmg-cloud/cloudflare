<?php

header('Content-Type: application/json; charset=utf-8');

$origensPermitidas = [
    'https://n3bot.com.br',
    'https://www.n3bot.com.br',
    'https://app.n3bot.com.br'
];

$origem = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origem, $origensPermitidas, true)) {
    header("Access-Control-Allow-Origin: $origem");
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function responder($status, $dados)
{
    http_response_code($status);
    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

$metodo = $_SERVER['REQUEST_METHOD'];
$arquivo = __DIR__ . '/departamentos.json';

/*
 * Captura o ID da URL.
 * Exemplos:
 * /api/departamentos/13
 * /api/departamentos/250
 */
$caminho = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

$idUrl = null;

if (preg_match('~/departamentos/([0-9]+)/?$~', $caminho, $matches)) {
    $idUrl = (string) $matches[1];
}

if (!file_exists($arquivo)) {
    if (file_put_contents(
        $arquivo,
        '[]',
        LOCK_EX
    ) === false) {
        responder(500, [
            'sucesso' => false,
            'mensagem' => 'Não foi possível criar departamentos.json.'
        ]);
    }
}

if ($metodo === 'GET') {
    $departamentos = json_decode(
        file_get_contents($arquivo),
        true
    );

    if (!is_array($departamentos)) {
        responder(500, [
            'sucesso' => false,
            'mensagem' => 'JSON de departamentos inválido.'
        ]);
    }

    if ($idUrl !== null) {
        foreach ($departamentos as $departamento) {
            $id = $departamento['id']
                ?? $departamento['departamentoId']
                ?? null;

            if ($id !== null && (string) $id === $idUrl) {
                responder(200, [
                    'sucesso' => true,
                    'dados' => $departamento
                ]);
            }
        }

        responder(404, [
            'sucesso' => false,
            'mensagem' => 'Departamento não encontrado.'
        ]);
    }

    responder(200, [
        'sucesso' => true,
        'dados' => $departamentos
    ]);
}

if ($metodo !== 'PUT') {
    header('Allow: GET, PUT, OPTIONS');

    responder(405, [
        'sucesso' => false,
        'mensagem' => 'Método não permitido.'
    ]);
}

if ($idUrl === null) {
    responder(400, [
        'sucesso' => false,
        'mensagem' => 'Informe o ID na URL: /api/departamentos/{id}.'
    ]);
}

$data = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    responder(400, [
        'sucesso' => false,
        'mensagem' => 'JSON inválido ou dados não enviados.'
    ]);
}

/* Um único objeto de departamento é esperado no PUT. */
if (array_is_list($data)) {
    responder(400, [
        'sucesso' => false,
        'mensagem' => 'Envie um único objeto de departamento, não uma lista.'
    ]);
}

$fp = fopen($arquivo, 'c+');

if (!$fp) {
    responder(500, [
        'sucesso' => false,
        'mensagem' => 'Não foi possível abrir departamentos.json.'
    ]);
}

if (!flock($fp, LOCK_EX)) {
    fclose($fp);

    responder(500, [
        'sucesso' => false,
        'mensagem' => 'Não foi possível bloquear o arquivo.'
    ]);
}

rewind($fp);
$conteudo = stream_get_contents($fp);
$departamentos = json_decode($conteudo ?: '[]', true);

if (!is_array($departamentos)) {
    flock($fp, LOCK_UN);
    fclose($fp);

    responder(500, [
        'sucesso' => false,
        'mensagem' => 'O arquivo departamentos.json contém JSON inválido.'
    ]);
}

/*
 * O ID da URL é a referência principal.
 * Se o corpo também enviar um ID diferente, a URL prevalece.
 */
$encontrado = false;

foreach ($departamentos as $indice => $departamento) {
    if (!is_array($departamento)) {
        continue;
    }

    $idExistente = $departamento['id']
        ?? $departamento['departamentoId']
        ?? null;

    if ($idExistente !== null && (string) $idExistente === $idUrl) {
        $data['id'] = $idUrl;

        $departamentos[$indice] = array_merge(
            $departamento,
            $data
        );

        $encontrado = true;
        break;
    }
}

/* Não cria registros novos silenciosamente em uma atualização. */
if (!$encontrado) {
    flock($fp, LOCK_UN);
    fclose($fp);

    responder(404, [
        'sucesso' => false,
        'mensagem' => 'Departamento não encontrado para atualização.',
        'id' => $idUrl
    ]);
}

$json = json_encode(
    array_values($departamentos),
    JSON_PRETTY_PRINT |
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
);

if ($json === false) {
    flock($fp, LOCK_UN);
    fclose($fp);

    responder(500, [
        'sucesso' => false,
        'mensagem' => 'Erro ao converter departamentos para JSON.'
    ]);
}

rewind($fp);
ftruncate($fp, 0);

$gravado = fwrite($fp, $json);

fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

if ($gravado === false || $gravado !== strlen($json)) {
    responder(500, [
        'sucesso' => false,
        'mensagem' => 'Falha ao salvar os departamentos.'
    ]);
}

responder(200, [
    'sucesso' => true,
    'mensagem' => 'Departamento atualizado com sucesso.',
    'id' => $idUrl,
    'dados' => $departamentos
]);
