<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://n3bot.com.br');
header('Access-Control-Allow-Methods: GET, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$metodo = $_SERVER['REQUEST_METHOD'];

$arquivoUsuarios = __DIR__ . '/auth_web.json';
$diretorioUploads = __DIR__ . '/uploads';

function responder(int $status, array $dados): void
{
    http_response_code($status);
    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function lerJson(): array
{
    $conteudo = file_get_contents('php://input');

    if ($conteudo === false || trim($conteudo) === '') {
        return [];
    }

    $dados = json_decode($conteudo, true);

    if (!is_array($dados)) {
        responder(400, [
            'sucesso' => false,
            'mensagem' => 'JSON inválido.'
        ]);
    }

    return $dados;
}

function lerUsuarios(string $arquivo): array
{
    if (!is_file($arquivo)) {
        responder(500, [
            'sucesso' => false,
            'mensagem' => 'Arquivo de usuários não encontrado.'
        ]);
    }

    $conteudo = file_get_contents($arquivo);
    $dados = json_decode($conteudo ?: '', true);

    if (!is_array($dados)) {
        responder(500, [
            'sucesso' => false,
            'mensagem' => 'Arquivo de usuários inválido.'
        ]);
    }

    return $dados;
}

function salvarUsuarios(string $arquivo, array $usuarios): void
{
    $fp = fopen($arquivo, 'c+');

    if (!$fp) {
        responder(500, [
            'sucesso' => false,
            'mensagem' => 'Não foi possível abrir o arquivo.'
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

    $json = json_encode(
        $usuarios,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        flock($fp, LOCK_UN);
        fclose($fp);

        responder(500, [
            'sucesso' => false,
            'mensagem' => 'Erro ao converter os dados.'
        ]);
    }

    ftruncate($fp, 0);
    rewind($fp);

    $gravado = fwrite($fp, $json);
    fflush($fp);

    flock($fp, LOCK_UN);
    fclose($fp);

    if ($gravado === false || $gravado !== strlen($json)) {
        responder(500, [
            'sucesso' => false,
            'mensagem' => 'Falha ao gravar os usuários.'
        ]);
    }
}

/*
 * IMPORTANTE:
 * Este exemplo espera que o middleware de autenticação valide
 * o token e disponibilize o usuário autenticado em $_SERVER.
 *
 * Não confie em um ID recebido livremente no corpo da requisição.
 * Integre esta parte ao sistema de autenticação já existente.
 */
$usuarioId = $_SERVER['AUTH_USER_ID'] ?? null;

if (!$usuarioId) {
    responder(401, [
        'sucesso' => false,
        'mensagem' => 'Usuário não autenticado.'
    ]);
}

$usuarios = lerUsuarios($arquivoUsuarios);
$indice = null;

/*
 * Formato esperado:
 * [
 *   {"id": "123", "nome": "Usuário", "usuario": "login", "imagem": ""}
 * ]
 */
foreach ($usuarios as $i => $usuario) {
    if (
        isset($usuario['id']) &&
        (string)$usuario['id'] === (string)$usuarioId
    ) {
        $indice = $i;
        break;
    }
}

if ($indice === null) {
    responder(404, [
        'sucesso' => false,
        'mensagem' => 'Usuário não encontrado.'
    ]);
}

if ($metodo === 'GET') {
    responder(200, [
        'sucesso' => true,
        'data' => $usuarios[$indice]
    ]);
}

if ($metodo === 'PUT') {
    $dados = lerJson();

    // Só permite alterar estes campos pelo perfil.
    $camposPermitidos = ['nome', 'imagem'];

    foreach ($camposPermitidos as $campo) {
        if (array_key_exists($campo, $dados)) {
            if (!is_string($dados[$campo])) {
                responder(400, [
                    'sucesso' => false,
                    'mensagem' => "O campo {$campo} deve ser texto."
                ]);
            }

            $valor = trim($dados[$campo]);

            if ($campo === 'nome' && $valor === '') {
                responder(400, [
                    'sucesso' => false,
                    'mensagem' => 'O nome não pode ficar vazio.'
                ]);
            }

            if (mb_strlen($valor, 'UTF-8') > 255) {
                responder(400, [
                    'sucesso' => false,
                    'mensagem' => "O campo {$campo} é muito longo."
                ]);
            }

            $usuarios[$indice][$campo] = $valor;
        }
    }

    salvarUsuarios($arquivoUsuarios, $usuarios);

    responder(200, [
        'sucesso' => true,
        'mensagem' => 'Perfil atualizado.',
        'data' => $usuarios[$indice]
    ]);
}

if ($metodo === 'DELETE') {
    /*
     * Não apaga a conta inteira por padrão.
     * DELETE limpa apenas a imagem de perfil.
     */
    $usuarios[$indice]['imagem'] = '';

    salvarUsuarios($arquivoUsuarios, $usuarios);

    responder(200, [
        'sucesso' => true,
        'mensagem' => 'Foto de perfil removida.',
        'data' => $usuarios[$indice]
    ]);
}

header('Allow: GET, PUT, DELETE, OPTIONS');

responder(405, [
    'sucesso' => false,
    'mensagem' => 'Método não permitido.'
]);
