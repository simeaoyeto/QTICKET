<?php
require_once 'conexao.php';

try {
    // 1. Adicionar colunas novas caso não existam na tabela tickets
    $pdo->exec("ALTER TABLE tickets ADD COLUMN IF NOT EXISTS id_area_destino INT NULL AFTER id_criador");
    $pdo->exec("ALTER TABLE tickets ADD COLUMN IF NOT EXISTS id_operacao_origem INT NULL AFTER id_area_destino");

    // 2. Adicionar coluna id_operacao à tabela utilizadores se também faltar
    $pdo->exec("ALTER TABLE utilizadores ADD COLUMN IF NOT EXISTS id_operacao INT NULL AFTER id_area");

    // 3. Limpar possíveis lixos ou chaves antigas que bloqueiem a estrutura
    echo "<h3 style='color: green;'>Sucesso! As colunas da estrutura multi-páginas foram injetadas.</h3>";
    echo "<p>Podes apagar este ficheiro e recarregar o <a href='tickets_lista.php'>tickets_lista.php</a>.</p>";

} catch (PDOException $e) {
    // Se o MySQL der erro de "Duplicate column" por causa da versão, tentamos a alternativa clássica
    try {
        $pdo->exec("ALTER TABLE tickets ADD id_area_destino INT NULL, ADD id_operacao_origem INT NULL");
        $pdo->exec("ALTER TABLE utilizadores ADD id_operacao INT NULL");
        echo "<h3 style='color: green;'>Sucesso (Modo Alternativo)! Estrutura corrigida.</h3>";
    } catch (Exception $err) {
        echo "<h3 style='color: red;'>Erro ao atualizar estrutura: " . $err->getMessage() . "</h3>";
    }
}
?>