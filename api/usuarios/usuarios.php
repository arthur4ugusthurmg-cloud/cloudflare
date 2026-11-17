
<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function responder($status, $dados) {
    http_response_code($status);
    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

$metodo = $_SERVER['REQUEST_METHOD'];

if ($metodo === 'OPTIONS') {
    header('Allow: GET, PUT, OPTIONS');
    exit;
}

if (!in_array($metodo, ['GET', 'PUT'], true)) {
    header('Allow: GET, PUT, OPTIONS');
    responder(405, [
        'sucesso' => false,
        'mensagem' => 'Método não permitido.'
    ]);
}

$arquivo = __DIR__ . '/auth_web.json';

if (!is_file($arquivo) || !is_readable($arquivo)) {
    responder(500, [
        'sucesso' => false,
        'mensagem' => 'Arquivo de usuários não encontrado ou sem permissão de leitura.'
    ]);
}

$fp = fopen($arquivo, 'c+');

if (!$fp) {
    responder(500, [
        'sucesso' => false,
        'mensagem' => 'Não foi possível abrir o arquivo de usuários.'
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
$usuarios = json_decode($conteudo, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    flock($fp, LOCK_UN);
    fclose($fp);
    responder(500, [
        'sucesso' => false,
        'mensagem' => 'O JSON de usuários está inválido.'
    ]);
}

/*
 * Aceita uma lista de usuários ou um objeto com a chave "usuarios".
 */
$listaDireta = is_array($usuarios)
    && array_is_list($usuarios);

if ($listaDireta) {
    $lista = $usuarios;
} elseif (isset($usuarios['usuarios']) && is_array($usuarios['usuarios'])) {
    $lista = $usuarios['usuarios'];
} else {
    flock($fp, LOCK_UN);
    fclose($fp);
    responder(500, [
        'sucesso' => false,
        'mensagem' => 'Estrutura do JSON não reconhecida.'
    ]);
}

/*
 * O ID pode vir da rota /usuarios/{id}/perfil
 * ou do parâmetro ?id=123.
 */
$id = isset($_GET['id']) ? (string) $_GET['id'] : '';

/*
 * IMPORTANTE:
 * Este exemplo ainda não identifica o usuário pela sessão ou JWT.
 * Não disponibilize a rota publicamente antes de implementar
 * autenticação e autorização.
 */
if ($id === '') {
    flock($fp, LOCK_UN);
    fclose($fp);
    responder(400, [
        'sucesso' => false,
        'mensagem' => 'ID do usuário não informado.'
    ]);
}

$indice = null;

foreach ($lista as $i => $usuario) {
    if (
        is_array($usuario)
        && isset($usuario['id'])
        && (string) $usuario['id'] === $id
    ) {
        $indice = $i;
        break;
    }
}

if ($indice === null) {
    flock($fp, LOCK_UN);
    fclose($fp);
    responder(404, [
        'sucesso' => false,
        'mensagem' => 'Usuário não encontrado.'
    ]);
}

if ($metodo === 'GET') {
    $perfil = $lista[$indice];

    // Não enviar credenciais nem segredos ao navegador.
    foreach ([
        'senha',
        'password',
        'token',
        'access_token',
        'refresh_token'
    ] as $campo) {
        unset($perfil[$campo]);
    }

    flock($fp, LOCK_UN);
    fclose($fp);

    responder(200, $perfil);
}

$entrada = json_decode(file_get_contents('php://input'), true);

if (!is_array($entrada)) {
    flock($fp, LOCK_UN);
    fclose($fp);
    responder(400, [
        'sucesso' => false,
        'mensagem' => 'JSON de entrada inválido.'
    ]);
}

/*
 * Não permitir que o cliente altere o ID ou campos sensíveis.
 * Ajuste a lista de campos editáveis ao seu modelo de perfil.
 */
$bloqueados = [
    'id',
    'senha',
    'password',
    'token',
    'access_token',
    'refresh_token',
    'isAdministrador',
    'isRevendedor',
    'permissoes',
    'role'
];

foreach ($entrada as $campo => $valor) {
    if (!in_array($campo, $bloqueados, true)) {
        $lista[$indice][$campo] = $valor;
    }
}

$novoConteudo = $listaDireta
    ? $lista
    : array_merge($usuarios, ['usuarios' => $lista]);

$json = json_encode(
    $novoConteudo,
    JSON_PRETTY_PRINT
    | JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
);

if ($json === false) {
    flock($fp, LOCK_UN);
    fclose($fp);
    responder(500, [
        'sucesso' => false,
        'mensagem' => 'Não foi possível gerar o JSON.'
    ]);
}

rewind($fp);
if (!ftruncate($fp, 0) || fwrite($fp, $json) === false) {
    flock($fp, LOCK_UN);
    fclose($fp);
    responder(500, [
        'sucesso' => false,
        'mensagem' => 'Falha ao salvar os usuários.'
    ]);
}

fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

responder(200, [
    'sucesso' => true,
    'mensagem' => 'Perfil atualizado com sucesso.'
]);
