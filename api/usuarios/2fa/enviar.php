<?php

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Accept");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// ==========================================================
// LER JSON RECEBIDO
// ==========================================================

$input = file_get_contents("php://input");

$data = json_decode($input, true);

if (!is_array($data)) {

    http_response_code(400);

    echo json_encode([
        "sucesso" => false,
        "erro" => "Dados inválidos."
    ]);

    exit;
}

// ==========================================================
// DADOS
// ==========================================================

$login = isset($data["login"])
    ? trim($data["login"])
    : "";

$emailInformado = isset($data["email"])
    ? trim($data["email"])
    : "";

$twoFAToken = isset($data["twoFAToken"])
    ? trim($data["twoFAToken"])
    : "";

// ==========================================================
// VALIDAR CAMPOS
// ==========================================================

if (!$login || !$emailInformado || !$twoFAToken) {

    http_response_code(400);

    echo json_encode([
        "sucesso" => false,
        "erro" => "Informe o usuário e o e-mail cadastrado."
    ]);

    exit;
}

// ==========================================================
// ARQUIVO DE USUÁRIOS
// ==========================================================

$arquivoUsuarios = __DIR__ . "/../auth_web.json";

if (!file_exists($arquivoUsuarios)) {

    http_response_code(500);

    echo json_encode([
        "sucesso" => false,
        "erro" => "Arquivo de usuários não encontrado."
    ]);

    exit;
}

// ==========================================================
// CARREGAR USUÁRIOS
// ==========================================================

$usuariosData = json_decode(
    file_get_contents($arquivoUsuarios),
    true
);

$usuarios = [];

if (
    is_array($usuariosData) &&
    isset($usuariosData["usuarios"]) &&
    is_array($usuariosData["usuarios"])
) {

    $usuarios = $usuariosData["usuarios"];

} elseif (is_array($usuariosData)) {

    $usuarios = $usuariosData;
}

// ==========================================================
// PROCURAR USUÁRIO
// ==========================================================

$usuarioEncontrado = null;

foreach ($usuarios as $usuario) {

    if (
        isset($usuario["usuario"]) &&
        strtolower(trim($usuario["usuario"])) ===
        strtolower($login)
    ) {

        $usuarioEncontrado = $usuario;

        break;
    }
}

// ==========================================================
// USUÁRIO NÃO ENCONTRADO
// ==========================================================

if (!$usuarioEncontrado) {

    http_response_code(401);

    echo json_encode([
        "sucesso" => false,
        "erro" => "Usuário não encontrado."
    ]);

    exit;
}

// ==========================================================
// VERIFICAR 2FA
// ==========================================================

$twoFA = false;

if (
    isset($usuarioEncontrado["twoFA"]) &&
    (
        $usuarioEncontrado["twoFA"] === true ||
        $usuarioEncontrado["twoFA"] === 1 ||
        $usuarioEncontrado["twoFA"] === "1" ||
        $usuarioEncontrado["twoFA"] === "true"
    )
) {

    $twoFA = true;
}

if (!$twoFA) {

    http_response_code(400);

    echo json_encode([
        "sucesso" => false,
        "erro" => "A autenticação em duas etapas não está ativada."
    ]);

    exit;
}

// ==========================================================
// E-MAIL CADASTRADO
// ==========================================================

$emailCadastrado = isset($usuarioEncontrado["email"])
    ? trim($usuarioEncontrado["email"])
    : "";

if (!$emailCadastrado) {

    http_response_code(400);

    echo json_encode([
        "sucesso" => false,
        "erro" => "Este usuário não possui e-mail cadastrado."
    ]);

    exit;
}

// ==========================================================
// COMPARAR E-MAIL
// ==========================================================

if (
    strtolower($emailInformado) !==
    strtolower($emailCadastrado)
) {

    http_response_code(401);

    echo json_encode([
        "sucesso" => false,
        "erro" => "O e-mail informado não corresponde ao e-mail cadastrado."
    ]);

    exit;
}

// ==========================================================
// GERAR CÓDIGO
// ==========================================================

$codigo = str_pad(
    (string) random_int(0, 999999),
    6,
    "0",
    STR_PAD_LEFT
);

// ==========================================================
// GERAR TOKEN REAL
// ==========================================================

$token = bin2hex(random_bytes(32));

// ==========================================================
// ARQUIVO 2FA
// ==========================================================

$arquivo2FA = __DIR__ . "/../../../dados/2fa.json";

$diretorio2FA = dirname($arquivo2FA);

if (!is_dir($diretorio2FA)) {

    mkdir(
        $diretorio2FA,
        0755,
        true
    );
}

// ==========================================================
// CARREGAR DESAFIOS EXISTENTES
// ==========================================================

$desafios = [];

if (file_exists($arquivo2FA)) {

    $desafiosExistentes = json_decode(
        file_get_contents($arquivo2FA),
        true
    );

    if (is_array($desafiosExistentes)) {

        $desafios = $desafiosExistentes;
    }
}

// ==========================================================
// REMOVER DESAFIOS ANTIGOS DO MESMO USUÁRIO
// ==========================================================

foreach ($desafios as $indice => $desafio) {

    if (
        isset($desafio["login"]) &&
        strtolower($desafio["login"]) ===
        strtolower($login)
    ) {

        unset($desafios[$indice]);
    }
}

$desafios = array_values($desafios);

// ==========================================================
// CRIAR NOVO DESAFIO
// ==========================================================

$desafios[] = [

    "token" => $token,

    "login" => $usuarioEncontrado["usuario"],

    "email" => $emailCadastrado,

    "codigo" => $codigo,

    "criadoEm" => time(),

    "tentativas" => 0

];

// ==========================================================
// SALVAR
// ==========================================================

$resultado = file_put_contents(
    $arquivo2FA,
    json_encode(
        $desafios,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE
    ),
    LOCK_EX
);

if ($resultado === false) {

    http_response_code(500);

    echo json_encode([
        "sucesso" => false,
        "erro" => "Não foi possível salvar o código de verificação."
    ]);

    exit;
}

// ==========================================================
// AQUI ENTRA O ENVIO DO E-MAIL
// ==========================================================
//
// O código está na variável:
//
// $codigo
//
// O e-mail está na variável:
//
// $emailCadastrado
//
// ==========================================================


// ==========================================================
// RESPOSTA
// ==========================================================

echo json_encode([
    "sucesso" => true,
    "mensagem" => "Código de verificação enviado.",
    "twoFAToken" => $token
], JSON_UNESCAPED_UNICODE);

exit;