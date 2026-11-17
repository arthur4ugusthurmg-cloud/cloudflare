
<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function responder($status, $dados)
{
    http_response_code($status);
    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    responder(405, ['erro' => 'Método não permitido']);
}

// Recebe JSON ou formulário.
$raw = file_get_contents('php://input');
$entrada = json_decode($raw, true);

if (!is_array($entrada)) {
    $entrada = $_POST;
}

if (!is_array($entrada)) {
    responder(400, ['erro' => 'Corpo da requisição inválido']);
}

// Aceita os nomes de campos comuns.
$login = trim((string)(
    $entrada['usuario']
    ?? $entrada['login']
    ?? $entrada['email']
    ?? ''
));

$senha = (string)(
    $entrada['senha']
    ?? $entrada['password']
    ?? ''
);

if ($login === '' || $senha === '') {
    responder(400, [
        'erro' => 'Informe usuário e senha',
        'campos_recebidos' => array_keys($entrada)
    ]);
}

// Localiza o arquivo de usuários.
$candidatos = [
    __DIR__ . '/auth_web.json',
    __DIR__ . '/auth_web/auth_web.json'
];

$arquivo = null;

foreach ($candidatos as $candidato) {
    if (is_file($candidato) && is_readable($candidato)) {
        $arquivo = $candidato;
        break;
    }
}

if ($arquivo === null) {
    responder(500, [
        'erro' => 'Arquivo auth_web.json não encontrado',
        'diretorio' => basename(__DIR__)
    ]);
}

$conteudo = file_get_contents($arquivo);
$dados = json_decode($conteudo, true);

if (!is_array($dados) || !isset($dados['usuarios'])
    || !is_array($dados['usuarios'])) {
    responder(500, ['erro' => 'Estrutura do JSON inválida']);
}

foreach ($dados['usuarios'] as $usuario) {
    if (!is_array($usuario)) {
        continue;
    }

    $usuarioLogin = (string)($usuario['usuario'] ?? '');
    $usuarioSenha = (string)($usuario['senha'] ?? '');

    if (
        $usuarioLogin === $login &&
        $usuarioSenha === $senha
    ) {
        // Mantém o token existente, sem JWT.
        $token = (string)($usuario['token'] ?? '');

        if ($token === '') {
            responder(500, [
                'erro' => 'Usuário autenticado, mas sem token configurado'
            ]);
        }

        // Não envia senha, tokens de serviços ou segredos internos.
        responder(200, [
            'token' => $token,
            'usuarioId' => $usuario['id'] ?? null,
            'usuarioNome' => $usuario['nome']
                ?? $usuario['usuario'],
            'usuarioFoto' => $usuario['imagem'] ?? '',
            'usuarioPerfil' => $usuario['perfil'] ?? '',
            'usuarioGestorMaster' =>
                (bool)($usuario['usuarioGestorMaster'] ?? false),
            'clientes' => $usuario['clientes'] ?? [],
            'usuario' => [
                'id' => $usuario['id'] ?? null,
                'usuario' => $usuario['usuario'],
                'nome' => $usuario['nome']
                    ?? $usuario['usuario'],
                'imagem' => $usuario['imagem'] ?? '',
                'perfil' => $usuario['perfil'] ?? '',
                'empresa' => $usuario['empresa'] ?? null,
                'scope' => $usuario['scope'] ?? ''
            ]
        ]);
    }
}

responder(401, ['erro' => 'Usuário ou senha inválidos']);
