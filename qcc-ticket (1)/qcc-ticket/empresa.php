<?php
require_once 'conexao.php';
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
$nome_usuario = $_SESSION['nome'];
$perfil_usuario = $_SESSION['perfil'];
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <title>QCCTICKET - A Empresa</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="app-layout">
        <div id="sidebar">
            <div class="sidebar-brand"><h3>QCCTICKET</h3><span>Quality Support</span></div>
            <div class="nav-section-title">Geral</div>
            <a href="index.php" class="nav-item">📊 <span>Dashboard</span></a>
            <a href="tickets_lista.php" class="nav-item">🎫 <span>Meus Tickets</span></a>
            <a href="kb_lista.php" class="nav-item">📚 <span>Base Conhecimento</span></a>
            <a href="empresa.php" class="nav-item active">🏢 <span>A Empresa</span></a>
            <?php if (in_array($perfil_usuario, ['Admin', 'Responsavel', 'Tecnico'])): ?>
                <div class="nav-section-title">Administração</div>
                <a href="usuarios_lista.php" class="nav-item">👥 <span>Gestão de Users</span></a>
                <a href="relatorios.php" class="nav-item">📈 <span>Relatórios</span></a>
            <?php endif; ?>
            <div class="sidebar-footer">
                <div class="user-badge">
                    <div style="font-size: 14px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo htmlspecialchars($nome_usuario); ?></div>
                    <div style="font-size: 11px; color: var(--accent); font-weight: 600; margin-top: 4px;">⚙️ <?php echo htmlspecialchars($perfil_usuario); ?></div>
                </div>
                <a href="logout.php" class="btn-danger">Sair do Sistema</a>
            </div>
        </div>

        <div id="main-content">
            <div class="page-header">
                <h1>Sobre a Quality Contact Center</h1>
                <p>Informações institucionais e canais de comunicação interna.</p>
            </div>
            <div class="card" style="line-height: 1.8;">
                <h3 style="color: var(--accent); margin-bottom: 10px;">Quem Somos</h3>
                <p style="color: var(--text-secondary); margin-bottom: 20px;">A Quality Contact Center é líder e referência na gestão de operações críticas de suporte ao cliente e infraestruturas em Angola.</p>
                <h3 style="color: var(--accent); margin-bottom: 10px;">Missão Operacional</h3>
                <p style="color: var(--text-secondary); margin-bottom: 20px;">Garantir soluções estáveis, rápidas e eficientes através da nossa plataforma técnica de incidentes internos.</p>
                <h3 style="color: var(--accent); margin-bottom: 10px;">Contactos Rápidos</h3>
                <p style="color: var(--text-secondary);">📧 E-mail: helpdesk@quality.co.ao<br>📞 Extensões da Sala Técnica: 4001 / 4002</p>
            </div>
        </div>
    </div>
</body>
</html>