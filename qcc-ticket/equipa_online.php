<?php
/**
 * KIAMI — Equipa Disponível (técnicos e responsáveis online)
 *
 * Mostra quem está disponível para dar tratamento aos tickets.
 * Acesso: Admin e staff das áreas técnicas (Redes & Sistemas / Desenvolvimento).
 */
require_once 'conexao.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$nome_usuario = $_SESSION['nome'];
$perfil_usuario = $_SESSION['perfil'];
$contexto = obterContextoUsuario($pdo);

// Acesso: Admin + equipas Redes & Sistemas e Desenvolvimento
if (!podeVerEquipaDisponivel($contexto)) {
    http_response_code(403);
    registarAuditoria($pdo, 'Acesso Negado', 'Tentativa de aceder à equipa disponível');
    die('Acesso negado. Esta área está reservada ao Admin e às equipas de Redes & Sistemas e Desenvolvimento.');
}

$equipaOnline = obterEquipaTecnica($pdo);
$equipaPorArea = agruparEquipaPorArea($pdo, $equipaOnline);
$totalEquipaOnline = count(array_filter($equipaOnline, static fn(array $t): bool => tecnicoEstaOnline($t['ultimo_acesso'] ?? null, $t['sessao_ativa'] ?? 1)));
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KIAMI - Equipa Disponível</title>
    <link rel="stylesheet" href="style.css">
    <script src="tema.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="app-body">
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
            <a href="perfis_lista.php" class="nav-item">🪪 <span>Gestão de Perfis</span></a>
            <a href="usuarios_lista.php" class="nav-item nav-sub">👥 <span>Utilizadores</span></a>
            <a href="equipa_online.php" class="nav-item active">🟢 <span>Equipa Disponível</span></a>
            <a href="assuntos_lista.php" class="nav-item">📋 <span>Assuntos de Ticket</span></a>
                <a href="emails_areas.php" class="nav-item">✉️ <span>Emails das Áreas</span></a>
            <a href="relatorios.php" class="nav-item">📈 <span>Relatórios</span></a>
            <?php if (podeAcederAuditoria($contexto)): ?>
            <a href="auditoria.php" class="nav-item">🔍 <span>Auditoria</span></a>
            <?php endif; ?>
            <div class="sidebar-footer">
                <div class="user-badge">
                    <div style="font-size: 14px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo htmlspecialchars($nome_usuario); ?></div>
                    <div style="font-size: 11px; color: var(--accent); font-weight: 600; margin-top: 4px;">⚙️ <?php echo htmlspecialchars($perfil_usuario); ?></div>
                </div>
                <?php if (idUtilizadorNumerico()): ?>
                <a href="alterar_senha.php" style="display:block; text-align:center; margin-bottom:8px; padding:9px; background:var(--bg-input); color:var(--text-primary); text-decoration:none; border-radius:var(--radius-sm); font-size:13px; border:1px solid var(--border);">🔑 Alterar Palavra-passe</a>
                <?php endif; ?>
                <a href="logout.php" class="btn-danger">🚪 Sair do Sistema</a>
            </div>
        </div>

        <div id="main-content">
            <div class="page-header">
                <h1>Equipa Disponível</h1>
                <p>Veja quem está online para tratar tickets: <strong>Redes &amp; Sistemas</strong>, <strong>Desenvolvimento</strong> e <strong>Administração</strong> (com tempo de sessão / último acesso).</p>
            </div>

            <div class="card">
                <h2 style="color: var(--green); margin-bottom: 4px; font-size: 16px;">🟢 Disponíveis agora</h2>
                <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 20px;">
                    <?php echo $totalEquipaOnline; ?> de <?php echo count($equipaOnline); ?> membro(s) online
                    <span style="color: var(--text-muted);">· considera-se online quem teve atividade nos últimos 2 minutos.</span>
                </p>

                <?php foreach ($equipaPorArea as $idArea => $grupo):
                    $onlineArea = count(array_filter($grupo['membros'], static fn(array $m): bool => tecnicoEstaOnline($m['ultimo_acesso'] ?? null, $m['sessao_ativa'] ?? 1)));
                    $iconeGrupo = ((int)$idArea === 0) ? '🛡️' : '🛠️';
                ?>
                    <div style="margin-bottom: 24px;">
                        <h3 style="font-size: 14px; color: var(--accent); margin-bottom: 10px; padding-bottom: 6px; border-bottom: 1px solid var(--border);">
                            <?php echo $iconeGrupo; ?> <?php echo htmlspecialchars($grupo['nome']); ?>
                            <span style="font-size: 11px; font-weight: 500; color: var(--text-muted); margin-left: 8px;">
                                <?php echo $onlineArea; ?>/<?php echo count($grupo['membros']); ?> online
                            </span>
                        </h3>
                        <?php if (empty($grupo['membros'])): ?>
                            <p style="font-size: 13px; color: var(--text-muted); font-style: italic; padding-left: 4px;">Sem membros registados nesta equipa.</p>
                        <?php else: ?>
                            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 12px;">
                                <?php foreach ($grupo['membros'] as $membro):
                                    $online = tecnicoEstaOnline($membro['ultimo_acesso'] ?? null, $membro['sessao_ativa'] ?? 1);
                                    $ultimoTxt = !empty($membro['ultimo_acesso'])
                                        ? date('d/m/Y H:i', strtotime((string)$membro['ultimo_acesso']))
                                        : 'sem registo';
                                ?>
                                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 12px 14px; background: var(--bg-input); border-radius: var(--radius-sm); border: 1px solid var(--border);">
                                        <div style="display: flex; align-items: center; gap: 10px; min-width: 0;">
                                            <span style="display:inline-block; width:11px; height:11px; border-radius:50%; flex-shrink:0; background: <?php echo $online ? 'var(--green)' : 'var(--text-muted)'; ?>;"></span>
                                            <div style="min-width:0;">
                                                <div style="font-size: 14px; font-weight: 600; color: var(--text-primary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo htmlspecialchars($membro['nome']); ?></div>
                                                <div style="font-size: 11px; color: var(--text-muted);"><?php echo htmlspecialchars($membro['perfil']); ?> · último acesso <?php echo htmlspecialchars($ultimoTxt); ?></div>
                                            </div>
                                        </div>
                                        <span style="font-size: 11px; font-weight: 600; color: <?php echo $online ? 'var(--green)' : 'var(--text-muted)'; ?>; white-space:nowrap;">
                                            <?php echo $online ? 'disponível' : ultimoAcessoTexto($membro['ultimo_acesso'] ?? null); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <script src="notificacoes.js"></script>
</body>
</html>
