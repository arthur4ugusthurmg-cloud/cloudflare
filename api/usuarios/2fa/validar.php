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
// LÊ JSON
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
// DADOS RECEBIDOS
// ==========================================================

$twoFAToken = isset($data["twoFAToken"])
    ? trim($data["twoFAToken"])
    : "";

$codigo = isset($data["codigo"])
    ? trim($data["codigo"])
    : "";

$login = isset($data["login"])
    ? trim($data["login"])
    : "";

// ==========================================================
// VALIDA CAMPOS
// ==========================================================

if (!$twoFAToken || !$codigo || !$login) {

    http_response_code(400);

    echo json_encode([
        "sucesso" => false,
        "erro" => "Informe o código de verificação."
    ]);

    exit;
}

// ==========================================================
// ARQUIVO DE DESAFIOS 2FA
// ==========================================================

$arquivo = __DIR__ . "/../../../dados/2fa.json";

if (!file_exists($arquivo)) {

    http_response_code(500);

    echo json_encode([
        "sucesso" => false,
        "erro" => "Sistema de autenticação 2FA não configurado."
    ]);

    exit;
}

// ==========================================================
// CARREGA DESAFIOS
// ==========================================================

$conteudo = file_get_contents($arquivo);

$desafios = json_decode($conteudo, true);

if (!is_array($desafios)) {
    $desafios = [];
}

// ==========================================================
// PROCURA TOKEN
// ==========================================================

$desafioEncontrado = null;
$indiceEncontrado = null;

foreach ($desafios as $indice => $desafio) {

    if (
        isset($desafio["token"]) &&
        hash_equals(
            (string) $desafio["token"],
            (string) $twoFAToken
        )
    ) {

        $desafioEncontrado = $desafio;
        $indiceEncontrado = $indice;

        break;
    }
}

// ==========================================================
// TOKEN NÃO ENCONTRADO
// ==========================================================

if (!$desafioEncontrado) {

    http_response_code(401);

    echo json_encode([
        "sucesso" => false,
        "erro" => "Código de verificação expirado ou inválido."
    ]);

    exit;
}

// ==========================================================
// CONFERE LOGIN
// ==========================================================

if (
    isset($desafioEncontrado["login"]) &&
    strtolower($desafioEncontrado["login"]) !== strtolower($login)
) {

    http_response_code(401);

    echo json_encode([
        "sucesso" => false,
        "erro" => "Sessão de verificação inválida."
    ]);

    exit;
}

// ==========================================================
// EXPIRAÇÃO
// ==========================================================

$criadoEm = isset($desafioEncontrado["criadoEm"])
    ? intval($desafioEncontrado["criadoEm"])
    : 0;

$agora = time();

// 10 minutos
if (
    !$criadoEm ||
    ($agora - $criadoEm) > 600
) {

    unset($desafios[$indiceEncontrado]);

    file_put_contents(
        $arquivo,
        json_encode(
            array_values($desafios),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        ),
        LOCK_EX
    );

    http_response_code(401);

    echo json_encode([
        "sucesso" => false,
        "erro" => "O código de verificação expirou."
    ]);

    exit;
}

// ==========================================================
// TENTA COMPARAR CÓDIGO
// ==========================================================

$codigoCorreto = isset($desafioEncontrado["codigo"])
    ? (string) $desafioEncontrado["codigo"]
    : "";

if (
    !$codigoCorreto ||
    !hash_equals(
        $codigoCorreto,
        (string) $codigo
    )
) {

    // Conta tentativa
    $tentativas = isset($desafios[$indiceEncontrado]["tentativas"])
        ? intval($desafios[$indiceEncontrado]["tentativas"])
        : 0;

    $tentativas++;

    $desafios[$indiceEncontrado]["tentativas"] = $tentativas;

    // Máximo 5 tentativas
    if ($tentativas >= 5) {

        unset($desafios[$indiceEncontrado]);

        file_put_contents(
            $arquivo,
            json_encode(
                array_values($desafios),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            ),
            LOCK_EX
        );

        http_response_code(401);

        echo json_encode([
            "sucesso" => false,
            "erro" => "Número máximo de tentativas atingido."
        ]);

        exit;
    }

    file_put_contents(
        $arquivo,
        json_encode(
            array_values($desafios),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        ),
        LOCK_EX
    );

    http_response_code(401);

    echo json_encode([
        "sucesso" => false,
        "erro" => "Código de verificação incorreto.",
        "tentativasRestantes" => 5 - $tentativas
    ]);

    exit;
}

// ==========================================================
// CÓDIGO CORRETO
// ==========================================================

// O token não pode ser reutilizado
unset($desafios[$indiceEncontrado]);

file_put_contents(
    $arquivo,
    json_encode(
        array_values($desafios),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
    ),
    LOCK_EX
);

// ==========================================================
// BUSCA USUÁRIO
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
// PROCURA USUÁRIO
// ==========================================================

$usuarioEncontrado = null;

foreach ($usuarios as $usuario) {

    if (
        isset($usuario["usuario"]) &&
        strtolower($usuario["usuario"]) === strtolower($login)
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
// REMOVE SENHA
// ==========================================================

unset($usuarioEncontrado["senha"]);

// ==========================================================
// LOGIN AUTORIZADO
// ==========================================================

echo json_encode([
    "sucesso" => true,
    "twoFA" => true,
    "usuario" => $usuarioEncontrado
], JSON_UNESCAPED_UNICODE);

exit;