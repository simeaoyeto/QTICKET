<?php
require_once 'conexao.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$nome_usuario = $_SESSION['nome'];
$perfil_usuario = $_SESSION['perfil'];

// Processar a inserção de um novo artigo (Funcionalidade 1)
$mensagem = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_salvar_kb'])) {
    // Apenas perfis técnicos/administrativos podem adicionar
    if (in_array($perfil_usuario, ['Admin', 'Responsavel', 'Tecnico'])) {
        $titulo = trim($_POST['titulo'] ?? '');
        $conteudo = trim($_POST['conteudo'] ?? '');
        $categoria = trim($_POST['categoria'] ?? 'Geral');

        if (!empty($titulo) && !empty($conteudo)) {
            $stmt = $pdo->prepare("INSERT INTO base_conhecimento (titulo, conteudo, categoria, tipo_conteudo) VALUES (?, ?, ?, 'tecnico')");
            $stmt->execute([$titulo, $conteudo, $categoria]);
            $mensagem = "<div style='background: rgba(34,197,94,0.2); color: var(--green); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Artigo adicionado com sucesso!</div>";
        } else {
            $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Por favor, preencha todos os campos.</div>";
        }
    }
}

// Procurar artigos existentes na base de dados
$stmt = $pdo->query("SELECT * FROM base_conhecimento ORDER BY id DESC");
$artigos = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <title>QCCTICKET - Base de Conhecimento</title>
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
            <a href="kb_lista.php" class="nav-item active">📚 <span>Base Conhecimento</span></a>
            <a href="empresa.php" class="nav-item">🏢 <span>A Empresa</span></a>
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
                <h1>Base de Conhecimento</h1>
                <p>Consulte resoluções rápidas e documentações técnicas da plataforma.</p>
            </div>

            <?php echo $mensagem; ?>

            <?php if (in_array($perfil_usuario, ['Admin', 'Responsavel', 'Tecnico'])): ?>
                <div class="card" style="margin-bottom: 30px;">
                    <h3 style="margin-bottom: 15px; font-size: 16px; color: var(--accent);">➕ Publicar Nova Informação</h3>
                    <form action="kb_lista.php" method="POST">
                        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 15px; margin-bottom: 15px;">
                            <input type="text" name="titulo" required placeholder="Título do Artigo (Ex: Como configurar VPN)" style="padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                            <select name="categoria" style="padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                                <option value="Redes">Redes & Sistemas</option>
                                <option value="Software">Desenvolvimento</option>
                                <option value="Geral">Procedimento Geral</option>
                            </select>
                        </div>
                        <div style="margin-bottom: 15px;">
                            <textarea name="conteudo" required placeholder="Escreva aqui o passo a passo da solução..." rows="4" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm); resize: vertical;"></textarea>
                        </div>
                        <button type="submit" name="btn_salvar_kb" style="padding: 10px 20px; background: var(--accent); border:none; color:white; border-radius:var(--radius-sm); cursor:pointer; font-weight:600;">Publicar Informação</button>
                    </form>
                </div>
            <?php endif; ?>

            <div style="display: flex; flex-direction: column; gap: 15px;">
                <?php if (count($artigos) === 0): ?>
                    <p style="color: var(--text-secondary);">Nenhum artigo publicado na base de conhecimento até ao momento.</p>
                <?php else: ?>
                    <?php foreach ($artigos as $artigo): ?>
                        <div class="card" style="background: var(--bg-sidebar);">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                <h3 style="font-size: 18px; color: #fff;"><?php echo htmlspecialchars($artigo['titulo']); ?></h3>
                                <span style="font-size: 11px; background: var(--bg-input); padding: 4px 8px; border-radius: 12px; color: var(--accent); border: 1px solid var(--border);">
                                    📁 <?php echo htmlspecialchars($artigo['categoria']); ?>
                                </span>
                            </div>
                            <p style="color: var(--text-secondary); font-size: 14px; line-height: 1.6; white-space: pre-line;"><?php echo htmlspecialchars($artigo['conteudo']); ?></p>
                            <div style="font-size: 11px; color: var(--text-muted); margin-top: 12px; text-align: right;">
                                Publicado em: <?php echo date('d/m/Y H:i', strtotime($artigo['data_criacao'])); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>