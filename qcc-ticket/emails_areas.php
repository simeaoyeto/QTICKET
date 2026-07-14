<?php
/**
 * KIAMI — Gestão dos emails (caixas postais) de cada área
 *
 * Quem gere: Admin + técnicos/responsáveis de Redes & Sistemas e Desenvolvimento.
 * Estes emails recebem aviso automático quando um ticket é aberto para a área.
 */
require_once 'conexao.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$nome_usuario = $_SESSION['nome'];
$perfil_usuario = $_SESSION['perfil'];
$contexto = obterContextoUsuario($pdo);

if (!podeGerirEmailsAreas($contexto)) {
    http_response_code(403);
    die('Acesso negado. A gestão de emails das áreas está reservada ao Admin e às equipas de Redes & Sistemas e Desenvolvimento.');
}

$mensagem = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_guardar_emails'])) {
    $emails = $_POST['email'] ?? [];
    if (is_array($emails)) {
        $stmt = $pdo->prepare("UPDATE areas SET email = ? WHERE id = ?");
        $ok = 0;
        foreach ($emails as $idArea => $email) {
            $idArea = (int)$idArea;
            $email = trim((string)$email);
            if ($idArea <= 0) {
                continue;
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $mensagem = "<div style='background:rgba(239,68,68,0.15);color:var(--red);padding:10px;border-radius:var(--radius-sm);margin-bottom:15px;'>Email inválido na área #$idArea.</div>";
                break;
            }
            $stmt->execute([$email !== '' ? $email : null, $idArea]);
            $ok++;
        }
        if ($mensagem === '') {
            registarAuditoria($pdo, 'Alteração', 'Emails das áreas actualizados');
            $mensagem = "<div style='background:rgba(34,197,94,0.15);color:var(--green);padding:10px;border-radius:var(--radius-sm);margin-bottom:15px;'>Emails das áreas guardados ({$ok}).</div>";
        }
    }
}

$areas = $pdo->query("SELECT id, nome, email FROM areas ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KIAMI - Emails das Áreas</title>
    <link rel="stylesheet" href="style.css">
    <script src="tema.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
<div class="app-layout">
    <div id="sidebar">
        <div class="sidebar-brand"><h3>KIAMI</h3><span>Suporte Quality</span></div>
        <div class="nav-section-title">Geral</div>
        <a href="index.php" class="nav-item">📊 <span>Painel</span></a>
        <a href="tickets_lista.php" class="nav-item">🎫 <span>Tickets</span></a>
        <a href="kb_lista.php" class="nav-item">📚 <span>Base Conhecimento</span></a>
        <a href="formacao.php" class="nav-item">🎓 <span>Autoaprendizagem</span></a>
        <a href="empresa.php" class="nav-item">🏢 <span>A Empresa</span></a>
        <?php echo htmlNavNotificacoes((int)($notif_pendentes ?? 0)); ?>

        <div class="nav-section-title">Administração</div>
        <a href="usuarios_lista.php" class="nav-item">👥 <span>Gestão de Utilizadores</span></a>
        <a href="equipa_online.php" class="nav-item">🟢 <span>Equipa Disponível</span></a>
        <a href="assuntos_lista.php" class="nav-item">📋 <span>Assuntos de Ticket</span></a>
        <a href="emails_areas.php" class="nav-item active">✉️ <span>Emails das Áreas</span></a>
        <a href="relatorios.php" class="nav-item">📈 <span>Relatórios</span></a>
        <?php if (podeAcederAuditoria($contexto)): ?>
        <a href="auditoria.php" class="nav-item">🔍 <span>Auditoria</span></a>
        <?php endif; ?>

        <div class="sidebar-footer">
            <div class="user-badge">
                <div style="font-size:14px;font-weight:600;"><?php echo htmlspecialchars($nome_usuario); ?></div>
                <div style="font-size:11px;color:var(--accent);font-weight:600;margin-top:4px;">⚙️ <?php echo htmlspecialchars($perfil_usuario); ?></div>
            </div>
            <a href="logout.php" class="btn-danger">🚪 Sair do Sistema</a>
        </div>
    </div>

    <div id="main-content">
        <div class="page-header">
            <h1>✉️ Emails das Áreas</h1>
            <p>Defina a caixa postal de cada área. Quando um ticket for aberto para essa área, o sistema envia um email automático com o código e o link para o painel.</p>
        </div>
        <?php echo $mensagem; ?>
        <div class="card">
            <form method="POST" style="display:flex;flex-direction:column;gap:14px;">
                <?php foreach ($areas as $ar): ?>
                    <div style="display:grid;grid-template-columns:220px 1fr;gap:12px;align-items:center;">
                        <label style="font-weight:600;color:#fff;"><?php echo htmlspecialchars($ar['nome']); ?></label>
                        <input type="email" name="email[<?php echo (int)$ar['id']; ?>]" value="<?php echo htmlspecialchars($ar['email'] ?? ''); ?>" placeholder="ex: helpdesk@quality.co.ao" style="width:100%;padding:10px;background:var(--bg-input);border:1px solid var(--border);color:#fff;border-radius:var(--radius-sm);">
                    </div>
                <?php endforeach; ?>
                <div>
                    <button type="submit" name="btn_guardar_emails" style="padding:12px 18px;background:var(--accent);border:none;color:#fff;border-radius:var(--radius-sm);cursor:pointer;font-weight:600;">Guardar Emails</button>
                </div>
            </form>
            <p style="margin-top:16px;font-size:12px;color:var(--text-muted);">Sugestão: Redes & Sistemas e Desenvolvimento podem usar <b>helpdesk@quality.co.ao</b> (já pré-preenchido se estiver vazio).</p>
        </div>
    </div>
</div>
<script src="notificacoes.js"></script>
</body>
</html>
