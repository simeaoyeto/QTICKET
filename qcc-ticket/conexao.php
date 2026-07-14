<?php
/**
 * KIAMI — Ponto de entrada comum (bootstrap)
 *
 * Incluído por todas as páginas PHP. Responsabilidades:
 * 1. Iniciar sessão PHP
 * 2. Ligar à base de dados MySQL/MariaDB (config/database.php)
 * 3. Carregar funções auxiliares e envio de email
 * 4. Forçar troca de senha padrão (123456) antes de usar o sistema
 * 5. Expirar sessão após 15 minutos de inatividade
 */

// --- Fuso horário (Angola, UTC+1) ---
// Alinha o PHP com o horário local. A ligação MySQL é fixada ao mesmo fuso
// mais abaixo, para que NOW() e o time() do PHP fiquem coerentes (evita o
// problema de aparecer "há 1 h" logo após o login por diferença de fuso).
date_default_timezone_set('Africa/Luanda');

// --- Sessão ---
if (session_status() === PHP_SESSION_NONE) {

    session_start();

}

// --- Base de dados ---
$dbConfigPath = __DIR__ . '/config/database.php';
if (file_exists($dbConfigPath)) {

    $db = require $dbConfigPath;

    $host = $db['host'] ?? 'localhost';

    $banco = $db['banco'] ?? 'qccticket';

    $usuario = $db['usuario'] ?? 'root';

    $senha = $db['senha'] ?? '';

    $charset = $db['charset'] ?? 'utf8mb4';

} else {

    $host = 'localhost';

    $banco = 'qccticket';

    $usuario = 'root';

    $senha = '';

    $charset = 'utf8mb4';

}



try {

    $pdo = new PDO("mysql:host=$host;dbname=$banco;charset=$charset", $usuario, $senha);

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fixa o fuso da sessão MySQL a UTC+1 (Angola), para NOW() coincidir com o PHP
    $pdo->exec("SET time_zone = '+01:00'");

} catch (PDOException $e) {

    die('Erro ao ligar à base de dados: ' . $e->getMessage());

}



// --- Bibliotecas partilhadas ---
require_once __DIR__ . '/includes/funcoes.php';

require_once __DIR__ . '/includes/enviar_email.php';

// --- Controlo de acesso global ---
$scriptAtual = basename($_SERVER['PHP_SELF'] ?? '');
$paginasSemSessao = ['login.php', 'recuperar_senha.php', 'abrir_ticket.php'];

$paginasDuranteTrocaSenha = ['alterar_senha.php', 'logout.php'];



// Forçar alteração de senha inicial (123456) antes de aceder ao sistema

if (isset($_SESSION['user_id']) && !in_array($scriptAtual, $paginasSemSessao, true)) {

    if (!empty($_SESSION['forcar_troca_senha']) && !in_array($scriptAtual, $paginasDuranteTrocaSenha, true)) {

        header('Location: alterar_senha.php');

        exit;

    }

}



// CONTROLO DE TIMEOUT GLOBAL (15 Minutos)

if (isset($_SESSION['user_id'])) {

    $tempo_atual = time();

    $tempo_inatividade = $tempo_atual - ($_SESSION['ultimo_acesso'] ?? $tempo_atual);



    if ($tempo_inatividade > 900) {

        session_unset();

        session_destroy();

        header('Location: login.php?erro=timeout');

        exit;

    }

    $_SESSION['ultimo_acesso'] = $tempo_atual;

    // Marca presença do utilizador (para saber que técnicos estão online/disponíveis)
    marcarAtividadeUtilizador($pdo);

    // Contagem de notificações para badge na barra lateral (todas as páginas)
    $contextoNotifSidebar = obterContextoUsuario($pdo);
    $notif_pendentes = contarNotificacoesPendentes($pdo, $contextoNotifSidebar);

} else {
    $notif_pendentes = 0;
}

?>

