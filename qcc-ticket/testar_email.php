<?php
/**
 * KIAMI — Teste de configuração SMTP (apenas Admin)
 *
 * Envia email de teste para validar config/email.php antes de produção.
 */
require_once 'conexao.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$contexto = obterContextoUsuario($pdo);
if ($contexto['perfil'] !== 'Admin') {
    die('Acesso negado. Apenas o Administrador pode testar o envio de email.');
}

$mensagem = '';
$tipo = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_testar'])) {
    $emailTeste = trim($_POST['email_teste'] ?? '');
    if (!filter_var($emailTeste, FILTER_VALIDATE_EMAIL)) {
        $mensagem = 'Indique um email válido para o teste.';
        $tipo = 'erro';
    } else {
        $token = 'teste_' . bin2hex(random_bytes(8));
        $resultado = enviarEmailRecuperacaoSenha($emailTeste, 'Administrador (Teste)', $token);
        if ($resultado['sucesso']) {
            $mensagem = "Email de teste enviado com sucesso para {$emailTeste}. Verifique a caixa de entrada e spam.";
            $tipo = 'sucesso';
            registarAuditoria($pdo, 'Teste Email', "Email de teste enviado para {$emailTeste}");
        } else {
            $mensagem = 'Falha no envio: ' . $resultado['erro'];
            $tipo = 'erro';
        }
    }
}

$cfg = obterConfigEmail();
$nome_usuario = $_SESSION['nome'];
$perfil_usuario = $_SESSION['perfil'];
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KIAMI - Testar Email SMTP</title>
    <link rel="stylesheet" href="style.css">
    <script src="tema.js"></script></head>
<body>
<div class="app-layout">
    <div id="sidebar">
        <div class="sidebar-brand"><h3>KIAMI</h3><span>Suporte Quality</span></div>
        <a href="index.php" class="nav-item">📊 <span>Painel</span></a>
        <a href="perfis_lista.php" class="nav-item">🪪 <span>Gestão de Perfis</span></a>
        <a href="usuarios_lista.php" class="nav-item nav-sub">👥 <span>Utilizadores</span></a>
        <a href="equipa_online.php" class="nav-item">🟢 <span>Equipa Disponível</span></a>
        <a href="assuntos_lista.php" class="nav-item">📋 <span>Assuntos de Ticket</span></a>
        <a href="testar_email.php" class="nav-item active">📧 <span>Testar Email</span></a>
        <div class="sidebar-footer">
            <a href="logout.php" class="btn-danger">🚪 Sair</a>
        </div>
    </div>
    <div id="main-content">
        <div class="page-header">
            <h1>Testar Configuração SMTP</h1>
            <p>Valide o envio de emails antes de usar a recuperação de senha em produção.</p>
        </div>

        <?php if ($mensagem): ?>
            <div class="card" style="margin-bottom:20px;border-left:3px solid <?php echo $tipo === 'sucesso' ? 'var(--green)' : 'var(--red)'; ?>;">
                <?php echo htmlspecialchars($mensagem); ?>
            </div>
        <?php endif; ?>

        <div class="card" style="margin-bottom:25px;">
            <h3 style="color:var(--accent);margin-bottom:15px;">Configuração atual</h3>
            <table style="font-size:14px;color:var(--text-secondary);line-height:2;">
                <tr><td>Ativo</td><td><b><?php echo !empty($cfg['ativo']) ? 'Sim' : 'Não'; ?></b></td></tr>
                <tr><td>Servidor SMTP</td><td><b><?php echo htmlspecialchars($cfg['host'] ?? '-'); ?>:<?php echo (int)($cfg['port'] ?? 0); ?></b></td></tr>
                <tr><td>Encriptação</td><td><b><?php echo htmlspecialchars($cfg['encryption'] ?? '-'); ?></b></td></tr>
                <tr><td>Utilizador</td><td><b><?php echo htmlspecialchars($cfg['username'] ?? '-'); ?></b></td></tr>
                <tr><td>Remetente</td><td><b><?php echo htmlspecialchars($cfg['from_email'] ?? '-'); ?></b></td></tr>
                <tr><td>URL base</td><td><b><?php echo htmlspecialchars($cfg['url_base'] ?: obterUrlBaseSistema()); ?></b></td></tr>
            </table>
            <p style="margin-top:15px;font-size:13px;color:var(--text-muted);">
                Edite o ficheiro <code>config/email.php</code> com as credenciais reais do servidor de email da empresa.
                Use <code>config/email.example.php</code> como referência.
            </p>
        </div>

        <div class="card">
            <h3 style="color:var(--accent);margin-bottom:15px;">Enviar email de teste</h3>
            <form method="POST" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;">
                <div style="flex:1;min-width:220px;">
                    <label style="font-size:12px;color:var(--text-muted);">Email de destino</label>
                    <input type="email" name="email_teste" required placeholder="admin@quality.co.ao" style="width:100%;padding:10px;background:var(--bg-input);border:1px solid var(--border);color:#fff;border-radius:var(--radius-sm);">
                </div>
                <button type="submit" name="btn_testar" style="padding:10px 20px;background:var(--accent);border:none;color:#fff;border-radius:var(--radius-sm);font-weight:600;cursor:pointer;">Enviar Teste</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
