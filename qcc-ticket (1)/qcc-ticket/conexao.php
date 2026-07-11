<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Configurações do XAMPP
$host = "localhost";
$banco = "qccticket";
$usuario = "root";
$senha = "";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$banco;charset=utf8mb4", $usuario, $senha);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erro ao ligar à base de dados: " . $e->getMessage());
}

// CONTROLO DE TIMEOUT GLOBAL (15 Minutos)
if (isset($_SESSION['user_id'])) {
    $tempo_atual = time();
    $tempo_inatividade = $tempo_atual - $_SESSION['ultimo_acesso'];
    
    if ($tempo_inatividade > 900) { // 900 segundos = 15 minutos
        session_unset();
        session_destroy();
        header("Location: login.php?erro=timeout");
        exit;
    }
    $_SESSION['ultimo_acesso'] = $tempo_atual;
}
?>