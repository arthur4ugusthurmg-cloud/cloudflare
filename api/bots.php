
<?php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://n3bot.com.br');
header('Access-Control-Allow-Methods: GET, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$arquivo = __DIR__ . '/bots.json';
$metodo = $_SERVER['REQUEST_METHOD'];

function resposta($status, $dados) {
    http_response_code($status);
    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );
    exit;
}

function lerBots($arquivo) {
    if (!file_exists($arquivo)) {
        resposta(404, ['erro' => 'bots.json não encontrado']);
    }

    $conteudo = file_get_contents($arquivo);
    $dados = json_decode($conteudo, true);

    if (!is_array($dados) || json_last_error() !== JSON_ERROR_NONE) {
        resposta(500, ['erro' => 'JSON inválido']);
    }

    return $dados;
}

function salvarBots($arquivo, $bots) {
    $json = json_encode(
        array_values($bots),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
    );

    if ($json === false) {
        resposta(500, ['erro' => 'Erro ao converter JSON']);
    }

    if (file_put_contents($arquivo, $json, LOCK_EX) === false) {
        resposta(500, ['erro' => 'Erro ao gravar bots.json']);
    }
}

/* =========================
   GET - CONSULTAR BOTS
========================= */
if ($metodo === 'GET') {
    $bots = lerBots($arquivo);
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    if ($id > 0) {
        foreach ($bots as $bot) {
            if ((int) ($bot['id'] ?? 0) === $id) {
                resposta(200, $bot);
            }
        }

        resposta(404, ['erro' => 'Bot não encontrado']);
    }

    resposta(200, $bots);
}

/* =========================
   PUT - CRIAR OU ATUALIZAR
========================= */
if ($metodo === 'PUT') {
    $entrada = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($entrada) ||
        json_last_error() !== JSON_ERROR_NONE) {
        resposta(400, ['erro' => 'JSON enviado inválido']);
    }

    if (empty(trim($entrada['nome'] ?? ''))) {
        resposta(400, ['erro' => 'O nome é obrigatório']);
    }

    $bots = lerBots($arquivo);
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    // Atualizar um bot existente
    if ($id > 0) {
        foreach ($bots as $indice => $bot) {
            if ((int) ($bot['id'] ?? 0) === $id) {
                $bots[$indice] = array_merge($bot, $entrada);
                $bots[$indice]['id'] = $id;

                salvarBots($arquivo, $bots);

                resposta(200, [
                    'sucesso' => true,
                    'mensagem' => 'Bot atualizado',
                    'id' => $id
                ]);
            }
        }

        resposta(404, ['erro' => 'Bot não encontrado']);
    }

    // Criar um bot novo
    $maiorId = 0;

    foreach ($bots as $bot) {
        $maiorId = max(
            $maiorId,
            (int) ($bot['id'] ?? 0)
        );
    }

    $novo = array_merge([
        'status' => 'ACTIVE',
        'ativo' => true,
        'telefones' => [],
        'botSites' => [],
        'botsFacebook' => [],
        'botsInstagram' => [],
        'botTelegram' => [],
        'botsEmail' => [],
        'botsGoogleBusinessMessages' => []
    ], $entrada);

    $novo['id'] = $maiorId + 1;
    $bots[] = $novo;

    salvarBots($arquivo, $bots);

    resposta(201, [
        'sucesso' => true,
        'mensagem' => 'Bot criado',
        'id' => $novo['id']
    ]);
}

/* =========================
   DELETE - EXCLUIR BOT
========================= */
if ($metodo === 'DELETE') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    if ($id < 1) {
        resposta(400, ['erro' => 'ID inválido']);
    }

    $bots = lerBots($arquivo);

    foreach ($bots as $indice => $bot) {
        if ((int) ($bot['id'] ?? 0) === $id) {
            array_splice($bots, $indice, 1);

            salvarBots($arquivo, $bots);

            resposta(200, [
                'sucesso' => true,
                'mensagem' => 'Bot excluído',
                'id' => $id
            ]);
        }
    }

    resposta(404, ['erro' => 'Bot não encontrado']);
}

/* =========================
   MÉTODO NÃO PERMITIDO
========================= */
header('Allow: GET, PUT, DELETE, OPTIONS');

resposta(405, [
    'erro' => 'Método não permitido',
    'metodo' => $metodo
]);
