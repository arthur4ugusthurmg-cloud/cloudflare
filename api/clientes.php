<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const ARQUIVO = __DIR__ . '/dados/telefones.json';

function resposta(int $status, array $dados): void
{
    http_response_code($status);

    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function lerEntrada(): array
{
    $dados = json_decode(file_get_contents('php://input'), true);

    if (!is_array($dados)) {
        resposta(400, [
            'sucesso' => false,
            'erro' => 'JSON inválido'
        ]);
    }

    return $dados;
}

function abrirDados()
{
    $fp = fopen(ARQUIVO, 'c+');

    if (!$fp || !flock($fp, LOCK_EX)) {
        resposta(500, [
            'sucesso' => false,
            'erro' => 'Não foi possível acessar os dados'
        ]);
    }

    rewind($fp);
    $conteudo = stream_get_contents($fp);
    $dados = json_decode($conteudo ?: '', true);

    if (!is_array($dados) ||
        !isset($dados['empresas']) ||
        !is_array($dados['empresas'])) {
        flock($fp, LOCK_UN);
        fclose($fp);

        resposta(500, [
            'sucesso' => false,
            'erro' => 'Arquivo JSON inválido'
        ]);
    }

    return [$fp, $dados];
}

function salvarDados($fp, array $dados): void
{
    $json = json_encode(
        $dados,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        flock($fp, LOCK_UN);
        fclose($fp);

        resposta(500, [
            'sucesso' => false,
            'erro' => 'Falha ao gerar JSON'
        ]);
    }

    rewind($fp);

    if (!ftruncate($fp, 0) ||
        fwrite($fp, $json) === false ||
        !fflush($fp)) {
        flock($fp, LOCK_UN);
        fclose($fp);

        resposta(500, [
            'sucesso' => false,
            'erro' => 'Falha ao salvar JSON'
        ]);
    }

    flock($fp, LOCK_UN);
    fclose($fp);
}

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$empresaId = $_COOKIE['empresaId'] ?? '';

if (!is_string($empresaId) ||
    !preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $empresaId)) {
    resposta(401, [
        'sucesso' => false,
        'erro' => 'Cookie empresaId ausente ou inválido'
    ]);
}

[$fp, $dados] = abrirDados();

if (!isset($dados['empresas'][$empresaId]) ||
    !is_array($dados['empresas'][$empresaId])) {
    flock($fp, LOCK_UN);
    fclose($fp);

    resposta(403, [
        'sucesso' => false,
        'erro' => 'Empresa não cadastrada'
    ]);
}

$telefones = &$dados['empresas'][$empresaId];

if ($metodo === 'GET') {
    $id = $_GET['id'] ?? null;

    if ($id !== null) {
        foreach ($telefones as $telefone) {
            if (($telefone['id'] ?? '') === $id) {
                flock($fp, LOCK_UN);
                fclose($fp);

                resposta(200, [
                    'sucesso' => true,
                    'empresaId' => $empresaId,
                    'telefone' => $telefone
                ]);
            }
        }

        flock($fp, LOCK_UN);
        fclose($fp);

        resposta(404, [
            'sucesso' => false,
            'erro' => 'Telefone não encontrado'
        ]);
    }

    flock($fp, LOCK_UN);
    fclose($fp);

    resposta(200, [
        'sucesso' => true,
        'empresaId' => $empresaId,
        'telefones' => $telefones
    ]);
}

if ($metodo === 'PUT') {
    $entrada = lerEntrada();
    $id = $entrada['id'] ?? '';

    if (!is_string($id) ||
        !preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $id)) {
        flock($fp, LOCK_UN);
        fclose($fp);

        resposta(400, [
            'sucesso' => false,
            'erro' => 'Informe um id válido'
        ]);
    }

    unset($entrada['empresaId']);

    $entrada['id'] = $id;
    $encontrado = false;

    foreach ($telefones as $indice => $telefone) {
        if (($telefone['id'] ?? '') === $id) {
            $telefones[$indice] = array_merge(
                $telefone,
                $entrada
            );

            $encontrado = true;
            break;
        }
    }

    if (!$encontrado) {
        $telefones[] = $entrada;
    }

    salvarDados($fp, $dados);

    resposta(200, [
        'sucesso' => true,
        'empresaId' => $empresaId,
        'acao' => $encontrado ? 'atualizado' : 'criado',
        'telefone' => $entrada
    ]);
}

if ($metodo === 'DELETE') {
    $id = $_GET['id'] ?? '';

    if (!is_string($id) || $id === '') {
        flock($fp, LOCK_UN);
        fclose($fp);

        resposta(400, [
            'sucesso' => false,
            'erro' => 'Informe o id do telefone'
        ]);
    }

    $quantidadeAnterior = count($telefones);

    $telefones = array_values(array_filter(
        $telefones,
        fn($telefone) => ($telefone['id'] ?? '') !== $id
    ));

    if (count($telefones) === $quantidadeAnterior) {
        flock($fp, LOCK_UN);
        fclose($fp);

        resposta(404, [
            'sucesso' => false,
            'erro' => 'Telefone não encontrado nesta empresa'
        ]);
    }

    salvarDados($fp, $dados);

    resposta(200, [
        'sucesso' => true,
        'mensagem' => 'Telefone excluído'
    ]);
}

header('Allow: GET, PUT, DELETE');

flock($fp, LOCK_UN);
fclose($fp);

resposta(405, [
    'sucesso' => false,
    'erro' => 'Método não permitido'
]);
