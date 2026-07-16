<?php
/**
 * KIAMI — Recuperação de palavra-passe por email
 *
 * Fluxo: solicitar email → token na tabela password_reset → link SMTP
 * Em localhost com debug_local, mostra link se o envio falhar.
 */
require_once 'conexao.php';

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$mensagem = '';
$tipo = 'info';
$linkDebug = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_solicitar'])) {
    // Pedido de recuperação — gera token e envia email (mensagem genérica por segurança)
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $mensagem = 'Indique um endereço de email válido.';
        $tipo = 'erro';
    } else {
        $stmt = $pdo->prepare("SELECT id, nome, email FROM utilizadores WHERE email = ? AND estado = 'Ativo'");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $expira = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $pdo->prepare("DELETE FROM password_reset WHERE id_utilizador = ?")->execute([$user['id']]);
            $pdo->prepare("INSERT INTO password_reset (id_utilizador, token, expira_em) VALUES (?, ?, ?)")
                ->execute([$user['id'], $token, $expira]);

            $resultadoEmail = enviarEmailRecuperacaoSenha($user['email'], $user['nome'], $token);

            if ($resultadoEmail['sucesso']) {
                registarAuditoria($pdo, 'Recuperação Senha', "Email de recuperação enviado para {$user['email']}");
            } else {
                registarAuditoria($pdo, 'Erro Email', "Falha ao enviar recuperação para {$user['email']}: {$resultadoEmail['erro']}");
                $cfg = obterConfigEmail();
                if (!empty($cfg['debug_local']) && isAmbienteLocal()) {
                    $linkDebug = obterUrlBaseSistema() . '/recuperar_senha.php?token=' . urlencode($token);
                }
            }
        }

        // Mensagem genérica por segurança (não revelar se o email existe)
        $mensagem = 'Se o email existir no sistema, receberá instruções para redefinir a palavra-passe. Verifique também a pasta de spam.';
        $tipo = 'sucesso';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_redefinir'])) {
    // Redefinição via link com token (válido 1 hora, uso único)
    $token = $_POST['token'] ?? '';
    $novaSenha = $_POST['nova_senha'] ?? '';
    $confirma = $_POST['confirma_senha'] ?? '';

    if (strlen($novaSenha) < 6) {
        $mensagem = 'A nova palavra-passe deve ter pelo menos 6 caracteres.';
        $tipo = 'erro';
    } elseif ($novaSenha !== $confirma) {
        $mensagem = 'As palavras-passe não coincidem.';
        $tipo = 'erro';
    } elseif ($novaSenha === '123456') {
        $mensagem = 'Não pode usar a senha padrão 123456.';
        $tipo = 'erro';
    } else {
        $stmt = $pdo->prepare("SELECT pr.*, u.id AS uid FROM password_reset pr JOIN utilizadores u ON pr.id_utilizador = u.id WHERE pr.token = ? AND pr.usado = 0 AND pr.expira_em > NOW()");
        $stmt->execute([$token]);
        $reset = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($reset) {
            $hash = password_hash($novaSenha, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE utilizadores SET password_hash = ? WHERE id = ?")->execute([$hash, $reset['uid']]);
            $pdo->prepare("UPDATE password_reset SET usado = 1 WHERE id = ?")->execute([$reset['id']]);
            registarAuditoria($pdo, 'Alteração Senha', "Senha redefinida via recuperação (user #{$reset['uid']})");
            header("Location: login.php?msg=senha_alterada");
            exit;
        }

        $mensagem = 'Link inválido ou expirado. Solicite novamente.';
        $tipo = 'erro';
    }
}

$tokenAtivo = $_GET['token'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KIAMI - Recuperar Palavra-passe</title>
    <link rel="stylesheet" href="style.css">
    <script src="tema.js"></script></head>
<body class="auth-page">
    <div class="auth-card">
        <h2 style="text-align: center; margin-bottom: 8px;">Recuperar Palavra-passe</h2>
        <p style="text-align: center; color: #94a3b8; font-size: 14px; margin-bottom: 20px;">
            <?php echo $tokenAtivo ? 'Defina a sua nova palavra-passe.' : 'Indique o email da sua conta. Enviaremos um link de recuperação.'; ?>
        </p>

        <?php if ($mensagem): ?>
            <div style="background: rgba(<?php echo $tipo === 'erro' ? '239,68,68' : '34,197,94'; ?>,0.2); color: <?php echo $tipo === 'erro' ? '#f87171' : '#4ade80'; ?>; padding: 10px; border-radius: 4px; margin-bottom: 16px; font-size: 14px;">
                <?php echo htmlspecialchars($mensagem); ?>
            </div>
        <?php endif; ?>

        <?php if ($linkDebug): ?>
            <div style="background: rgba(245,158,11,0.15); padding: 12px; border-radius: 6px; margin-bottom: 16px; font-size: 12px; word-break: break-all; color: #fbbf24;">
                <strong>Modo debug (envio SMTP falhou):</strong><br>
                Configure <code>config/email.php</code> e tente novamente.<br>
                <a href="<?php echo htmlspecialchars($linkDebug); ?>" style="color: #60a5fa;"><?php echo htmlspecialchars($linkDebug); ?></a>
            </div>
        <?php endif; ?>

        <?php if ($tokenAtivo): ?>
            <form method="POST">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($tokenAtivo); ?>">
                <div style="margin-bottom: 14px;">
                    <label style="display: block; margin-bottom: 6px; color: #cbd5e1; font-size: 13px;">Nova palavra-passe</label>
                    <input type="password" name="nova_senha" required minlength="6" style="width: 100%; padding: 10px; background: #334155; border: 1px solid #475569; color: #fff; border-radius: 4px; box-sizing: border-box;">
                </div>
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 6px; color: #cbd5e1; font-size: 13px;">Confirmar palavra-passe</label>
                    <input type="password" name="confirma_senha" required minlength="6" style="width: 100%; padding: 10px; background: #334155; border: 1px solid #475569; color: #fff; border-radius: 4px; box-sizing: border-box;">
                </div>
                <button type="submit" name="btn_redefinir" style="width: 100%; padding: 12px; background: #3b82f6; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">Redefinir Palavra-passe</button>
            </form>
        <?php else: ?>
            <form method="POST">
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 6px; color: #cbd5e1; font-size: 13px;">Email</label>
                    <input type="email" name="email" required placeholder="seu.email@quality.co.ao" style="width: 100%; padding: 10px; background: #334155; border: 1px solid #475569; color: #fff; border-radius: 4px; box-sizing: border-box;">
                </div>
                <button type="submit" name="btn_solicitar" style="width: 100%; padding: 12px; background: #3b82f6; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">Enviar Link por Email</button>
            </form>
        <?php endif; ?>

        <div style="margin-top: 20px; text-align: center;">
            <a href="login.php" style="color: #60a5fa; font-size: 13px; text-decoration: none;">← Voltar ao Login</a>
        </div>
    </div>
</body>
</html>
