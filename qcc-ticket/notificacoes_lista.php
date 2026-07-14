<?php
/**
 * KIAMI — Painel de Notificações
 *
 * Lista notificações dirigidas ao utilizador ou à sua área.
 * Badge na barra lateral mostra o número de pendentes.
 */
require_once 'conexao.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$nome_usuario = $_SESSION['nome'];
$perfil_usuario = $_SESSION['perfil'];
$contexto = obterContextoUsuario($pdo);
$mensagem = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_marcar_lidas'])) {
    marcarNotificacoesLidas($pdo, $contexto);
    $mensagem = "<div style='background:rgba(34,197,94,0.15);color:var(--green);padding:10px;border-radius:var(--radius-sm);margin-bottom:15px;'>Notificações marcadas como lidas.</div>";
}

if (isset($_GET['ler'])) {
    marcarNotificacoesLidas($pdo, $contexto, (int)$_GET['ler']);
    if (!empty($_GET['ticket'])) {
        header('Location: ticket_detalhes.php?id=' . (int)$_GET['ticket']);
        exit;
    }
    header('Location: notificacoes_lista.php');
    exit;
}

$lista = obterNotificacoesUtilizador($pdo, $contexto, 80, false);
$pendentes = contarNotificacoesPendentes($pdo, $contexto);
$notif_pendentes = $pendentes;
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KIAMI - Notificações</title>
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
        <?php echo htmlNavNotificacoes((int)$notif_pendentes); ?>

        <?php if (podeAcederAdministracao($contexto)): ?>
            <div class="nav-section-title">Administração</div>
            <a href="usuarios_lista.php" class="nav-item">👥 <span>Gestão de Utilizadores</span></a>
            <a href="equipa_online.php" class="nav-item">🟢 <span>Equipa Disponível</span></a>
            <a href="assuntos_lista.php" class="nav-item">📋 <span>Assuntos de Ticket</span></a>
            <a href="emails_areas.php" class="nav-item">✉️ <span>Emails das Áreas</span></a>
            <a href="relatorios.php" class="nav-item">📈 <span>Relatórios</span></a>
            <?php if (podeAcederAuditoria($contexto)): ?>
            <a href="auditoria.php" class="nav-item">🔍 <span>Auditoria</span></a>
            <?php endif; ?>
        <?php elseif ($perfil_usuario === 'Diretor Geral'): ?>
            <div class="nav-section-title">Consulta</div>
            <a href="relatorios.php" class="nav-item">📈 <span>Relatórios</span></a>
        <?php endif; ?>

        <div class="sidebar-footer">
            <div class="user-badge">
                <div style="font-size:14px;font-weight:600;"><?php echo htmlspecialchars($nome_usuario); ?></div>
                <div style="font-size:11px;color:var(--accent);font-weight:600;margin-top:4px;">⚙️ <?php echo htmlspecialchars($perfil_usuario); ?></div>
            </div>
            <?php if (idUtilizadorNumerico()): ?>
            <a href="alterar_senha.php" style="display:block;text-align:center;margin-bottom:8px;padding:9px;background:var(--bg-input);color:var(--text-primary);text-decoration:none;border-radius:var(--radius-sm);font-size:13px;border:1px solid var(--border);">🔑 Alterar Palavra-passe</a>
            <?php endif; ?>
            <a href="logout.php" class="btn-danger">🚪 Sair do Sistema</a>
        </div>
    </div>

    <div id="main-content">
        <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;">
            <div>
                <h1>🔔 Notificações</h1>
                <p>Alertas dirigidos a si ou à sua área. <?php echo $pendentes > 0 ? "<b style='color:var(--red);'>{$pendentes} pendente(s)</b>." : 'Sem pendentes.'; ?></p>
            </div>
            <?php if ($pendentes > 0): ?>
            <form method="POST">
                <button type="submit" name="btn_marcar_lidas" style="padding:10px 16px;background:var(--accent);border:none;color:#fff;border-radius:var(--radius-sm);cursor:pointer;font-weight:600;">Marcar todas como lidas</button>
            </form>
            <?php endif; ?>
        </div>

        <?php echo $mensagem; ?>

        <div class="card">
            <?php if (empty($lista)): ?>
                <p style="color:var(--text-muted);font-style:italic;">Ainda não há notificações para si.</p>
            <?php else: ?>
                <div style="display:flex;flex-direction:column;gap:10px;">
                    <?php foreach ($lista as $n):
                        $lida = (int)($n['lida'] ?? 0) === 1;
                        $ticketId = (int)($n['id_ticket'] ?? 0);
                        $href = $ticketId > 0
                            ? 'notificacoes_lista.php?ler=' . (int)$n['id'] . '&ticket=' . $ticketId
                            : 'notificacoes_lista.php?ler=' . (int)$n['id'];
                    ?>
                        <a href="<?php echo htmlspecialchars($href); ?>" style="display:block;text-decoration:none;padding:14px 16px;border-radius:var(--radius-sm);border:1px solid <?php echo $lida ? 'var(--border)' : 'rgba(239,68,68,0.45)'; ?>;background:<?php echo $lida ? 'var(--bg-input)' : 'rgba(239,68,68,0.08)'; ?>;">
                            <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;">
                                <div>
                                    <div style="font-weight:700;color:#fff;margin-bottom:4px;">
                                        <?php echo $lida ? '' : '🚨 '; ?><?php echo htmlspecialchars($n['tipo'] ?? 'Alerta'); ?>
                                    </div>
                                    <div style="font-size:13px;color:var(--text-secondary);line-height:1.45;"><?php echo htmlspecialchars($n['mensagem'] ?? ''); ?></div>
                                </div>
                                <div style="font-size:11px;color:var(--text-muted);white-space:nowrap;">
                                    <?php echo !empty($n['data_criacao']) ? date('d/m/Y H:i', strtotime($n['data_criacao'])) : ''; ?>
                                </div>
                            </div>
                            <?php if ($ticketId > 0): ?>
                                <div style="margin-top:8px;font-size:12px;color:var(--accent);font-weight:600;">Abrir ticket →</div>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="notificacoes.js"></script>
</body>
</html>
