<?php
/**
 * KIAMI — Terminar sessão
 * Regista auditoria antes de destruir a sessão.
 */
require_once 'conexao.php';
if (isset($_SESSION['user_id'])) {
    registarAuditoria($pdo, 'Logout', 'Sessão terminada: ' . ($_SESSION['username'] ?? ''));

    // Marcar o utilizador como offline imediatamente ao terminar a sessão.
    // NÃO se apaga o ultimo_acesso: preserva-se para se mostrar "há quanto tempo"
    // esteve online (o texto do estado offline). Apenas se desliga a sessão ativa.
    $idNumerico = idUtilizadorNumerico();
    if ($idNumerico) {
        try {
            $stmt = $pdo->prepare("UPDATE utilizadores SET sessao_ativa = 0 WHERE id = ?");
            $stmt->execute([$idNumerico]);
        } catch (PDOException $e) {
            // Silencioso — não impedir o logout se a coluna ainda não existir
        }
    }
}

session_unset();
session_destroy();
header("Location: login.php");
exit;