<?php
require_once 'conexao.php';

try {
    // Gerar o hash seguro oficial do PHP para a senha 'admin123'
    $nova_hash = password_hash('admin123', PASSWORD_BCRYPT);

    // Atualizar o utilizador admin na base de dados
    $stmt = $pdo->prepare("UPDATE utilizadores SET password_hash = ?, estado = 'Ativo' WHERE username = 'admin'");
    $stmt->execute([$nova_hash]);

    if ($stmt->rowCount() > 0) {
        echo "<h3 style='color: green;'>Sucesso! O utilizador 'admin' foi atualizado com a senha 'admin123'.</h3>";
    } else {
        echo "<h3 style='color: orange;'>O registo não foi alterado. Certifica-te de que o username é exatamente 'admin' na tabela.</h3>";
    }
    
    echo "<p>Podes apagar este ficheiro por segurança e voltar ao <a href='login.php'>login.php</a> para testar.</p>";

} catch (PDOException $e) {
    echo "<h3 style='color: red;'>Erro ao atualizar: " . $e->getMessage() . "</h3>";
}
?>