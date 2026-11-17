<?php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function resposta($status, $dados)
{
    http_response_code($status);
    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE |
        JSON_PRETTY_PRINT |
        JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

$metodo = $_SERVER['REQUEST_METHOD'];


if (!in_array($metodo, ['GET', 'PUT', 'POST'], true)) {
    header('Allow: GET, PUT, POST, OPTIONS');

    resposta(405, [
        'sucesso' => false,
        'mensagem' => 'Método não permitido.'
    ]);
}

$arquivo = dirname(__DIR__) . '/usuarios/auth_web.json';


/* Consultar um atendente por ID ou listar atendentes */
if ($metodo === 'GET') {

    if (!is_file($arquivo) || !is_readable($arquivo)) {
        resposta(500, [
            'sucesso' => false,
            'mensagem' => 'Arquivo de usuários indisponível.'
        ]);
    }

    $conteudo = file_get_contents($arquivo);
    $banco = json_decode($conteudo, true);

    if (!is_array($banco)) {
        resposta(500, [
            'sucesso' => false,
            'mensagem' => 'JSON de usuários inválido.'
        ]);
    }

    // Aceita tanto uma lista direta quanto {"usuarios": [...]}
    $usuarios = array_is_list($banco)
        ? $banco
        : ($banco['usuarios'] ?? []);

    if (!is_array($usuarios)) {
        resposta(500, [
            'sucesso' => false,
            'mensagem' => 'A lista de usuários é inválida.'
        ]);
    }

    $id = trim((string) ($_GET['id'] ?? ''));
    $departamentoId = trim(
        (string) ($_GET['departamentoId'] ?? '')
    );
    $status = strtoupper(
        trim((string) ($_GET['status'] ?? ''))
    );

    $atendentes = [];

    foreach ($usuarios as $item) {
        if (!is_array($item)) {
            continue;
        }

        $perfil = strtoupper((string) (
            $item['usuarioPerfil']
            ?? $item['perfil']
            ?? $item['scope']
            ?? ''
        ));

        // Retorna somente registros de atendentes
        if ($perfil !== 'ATENDENTE') {
            continue;
        }

        // Consulta individual por ID, quando informado
        if (
            $id !== '' &&
            (string) ($item['id'] ?? '') !== $id
        ) {
            continue;
        }

        // Filtra status somente quando o registro possui status.
        if (
            $status !== '' &&
            isset($item['status']) &&
            strtoupper((string) $item['status']) !== $status
        ) {
            continue;
        }

        // Inicializa campos usados pela tela de edição/listagem
        $item['departamentos'] = is_array($item['departamentos'] ?? null)
            ? $item['departamentos']
            : [];

        $item['permissionPages'] = is_array($item['permissionPages'] ?? null)
            ? $item['permissionPages']
            : [];

        $item['diasAtendimento'] = is_array($item['diasAtendimento'] ?? null)
            ? $item['diasAtendimento']
            : [];

        $atendentes[] = $item;
    }

    // Busca individual
    if ($id !== '') {
        if (empty($atendentes)) {
            resposta(404, [
                'sucesso' => false,
                'mensagem' => 'Atendente não encontrado.'
            ]);
        }

        $atendente = $atendentes[0];

        resposta(200, [
            'sucesso' => true,
            'id' => $atendente['id'] ?? null,
            'token' => $atendente['token'] ?? null,
            'atendente' => $atendente
        ]);
    }

    // Lista completa para o AngularJS
    resposta(200, $atendentes);
}



$corpo = file_get_contents('php://input');
$entrada = json_decode($corpo, true);

if (!is_array($entrada)) {
    resposta(400, [
        'sucesso' => false,
        'mensagem' => 'JSON inválido.'
    ]);
}

$nome = trim((string) ($entrada['nome'] ?? ''));
$usuario = trim((string) ($entrada['usuario'] ?? ''));
$email = trim((string) ($entrada['email'] ?? ''));

if ($nome === '' || $usuario === '') {
    resposta(400, [
        'sucesso' => false,
        'mensagem' => 'Os campos nome e usuario são obrigatórios.'
    ]);
}

$diretorio = dirname($arquivo);

if (!is_dir($diretorio) || !is_writable($diretorio)) {
    resposta(500, [
        'sucesso' => false,
        'mensagem' => 'Diretório de autenticação indisponível.'
    ]);
}

$fp = fopen($arquivo, 'c+');

if (!$fp) {
    resposta(500, [
        'sucesso' => false,
        'mensagem' => 'Não foi possível abrir auth_web.json.'
    ]);
}

if (!flock($fp, LOCK_EX)) {
    fclose($fp);

    resposta(500, [
        'sucesso' => false,
        'mensagem' => 'Não foi possível bloquear o arquivo.'
    ]);
}

$falhar = function ($mensagem) use ($fp) {
    flock($fp, LOCK_UN);
    fclose($fp);

    resposta(500, [
        'sucesso' => false,
        'mensagem' => $mensagem
    ]);
};

rewind($fp);
$conteudo = stream_get_contents($fp);
$banco = [];

if (trim($conteudo) !== '') {
    $banco = json_decode($conteudo, true);

    if (!is_array($banco)) {
        $falhar('auth_web.json contém JSON inválido.');
    }
}

$listaDireta = array_is_list($banco);

if ($listaDireta) {
    $usuarios = $banco;
} else {
    $usuarios = $banco['usuarios'] ?? [];

    if (!is_array($usuarios)) {
        $falhar('A propriedade usuarios não é uma lista válida.');
    }
}

$idRecebido = trim((string) ($entrada['id'] ?? ''));
$tokenRecebido = trim((string) ($entrada['token'] ?? ''));

$indiceEncontrado = null;

foreach ($usuarios as $indice => $item) {
    if (!is_array($item)) {
        continue;
    }

    $mesmoId = $idRecebido !== ''
        && isset($item['id'])
        && (string) $item['id'] === $idRecebido;

    $mesmoUsuario = isset($item['usuario'])
        && strcasecmp((string) $item['usuario'], $usuario) === 0;

    if ($mesmoId || $mesmoUsuario) {
        $indiceEncontrado = $indice;
        break;
    }
}

$existente = $indiceEncontrado !== null
    ? $usuarios[$indiceEncontrado]
    : [];

/*
 * Cria ID e token independentes para novos registros.
 * Em atualizações, preserva os valores existentes.
 */
$id = $existente['id'] ?? $idRecebido;

if ($id === '' || $id === null) {
    $id = bin2hex(random_bytes(16));
}

$token = $existente['token'] ?? $tokenRecebido;

if ($token === '' || $token === null) {
    $token = bin2hex(random_bytes(32));
}

/*
 * Não permite que campos arbitrários da entrada sobrescrevam
 * os identificadores existentes.
 */
$atendente = array_merge($existente, $entrada);

$atendente['id'] = (string) $id;
$atendente['token'] = (string) $token;
$atendente['nome'] = $nome;
$atendente['usuario'] = $usuario;
$atendente['perfil'] = 'ATENDENTE';
$atendente['usuarioPerfil'] = 'ATENDENTE';
$atendente['scope'] = 'ATENDENTE';

if ($email !== '') {
    $atendente['email'] = $email;
}

if ($indiceEncontrado !== null) {
    $usuarios[$indiceEncontrado] = $atendente;
    $acao = 'atualizado';
} else {
    $usuarios[] = $atendente;
    $acao = 'criado';
}

if ($listaDireta) {
    $bancoFinal = $usuarios;
} else {
    $bancoFinal = $banco;
    $bancoFinal['usuarios'] = $usuarios;
}

$json = json_encode(
    $bancoFinal,
    JSON_UNESCAPED_UNICODE |
    JSON_PRETTY_PRINT |
    JSON_INVALID_UTF8_SUBSTITUTE
);

if ($json === false) {
    $falhar('Erro ao gerar JSON.');
}

rewind($fp);
ftruncate($fp, 0);

$gravado = fwrite($fp, $json . PHP_EOL);

if ($gravado === false) {
    $falhar('Erro ao gravar auth_web.json.');
}

fflush($fp);

flock($fp, LOCK_UN);
fclose($fp);

resposta($acao === 'criado' ? 201 : 200, [
    'sucesso' => true,
    'mensagem' => 'Atendente ' . $acao . ' com sucesso.',
    'id' => (string) $id,
    'token' => (string) $token,
    'atendente' => $atendente
]);
