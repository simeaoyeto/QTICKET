<?php
require_once 'conexao.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$nome_usuario = $_SESSION['nome'];
$perfil_usuario = $_SESSION['perfil'];

$mensagem = "";

// =========================================================
// PROCESSAR REMOÇÃO DE ARTIGO (DELETE)
// =========================================================
if (isset($_GET['acao']) && $_GET['acao'] === 'remover' && isset($_GET['id'])) {
    $id_artigo = (int)$_GET['id'];

    // Buscar o criador do artigo para validar a permissão
    $stmt_check = $pdo->prepare("SELECT id_autor FROM kb_artigos WHERE id = ?");
    $stmt_check->execute([$id_artigo]);
    $artigo = $stmt_check->fetch(PDO::FETCH_ASSOC);

    if ($artigo) {
        // REGRA DE OURO: Admin apaga tudo; Outros perfis apenas o que criaram
        if ($perfil_usuario === 'Admin' || $artigo['id_autor'] == $user_id) {
            $stmt_del = $pdo->prepare("DELETE FROM kb_artigos WHERE id = ?");
            $stmt_del->execute([$id_artigo]);
            $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Artigo removido com sucesso!</div>";
        } else {
            $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: Não tem permissão para apagar este artigo.</div>";
        }
    }
}

// =========================================================
// PROCESSAR NOVA PUBLICAÇÃO (POST)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_publicar'])) {
    $titulo = trim($_POST['titulo'] ?? '');
    $conteudo = trim($_POST['conteudo'] ?? '');
    $categoria = $_POST['categoria'] ?? 'Geral';

    if (!empty($titulo) && !empty($conteudo)) {
        $stmt_ins = $pdo->prepare("INSERT INTO kb_artigos (titulo, conteudo, categoria, id_autor, data_criacao) VALUES (?, ?, ?, ?, NOW())");
        $stmt_ins->execute([$titulo, $conteudo, $categoria, $user_id]);
        $mensagem = "<div style='background: rgba(34,197,94,0.2); color: var(--green); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Novo conhecimento publicado!</div>";
    } else {
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: Preencha todos os campos.</div>";
    }
}

// =========================================================
// PROCURAR TODOS OS ARTIGOS PUBLICADOS
// =========================================================
$query_kb = "
    SELECT k.*, u.nome AS nome_autor, u.perfil AS perfil_autor 
    FROM kb_artigos k
    JOIN utilizadores u ON k.id_autor = u.id
    ORDER BY k.id DESC
";
$artigos = $pdo->query($query_kb)->fetchAll(PDO::FETCH_ASSOC);
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
            
            <?php if ($perfil_usuario === 'Admin'): ?>
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
                <h1>📚 Base de Conhecimento (Knowledge Base)</h1>
                <p>Consulte tutoriais, resoluções de erros frequentes e procedimentos internos da Quality Contact Center.</p>
            </div>

            <?php echo $mensagem; ?>

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 25px; align-items: start;">
                
                <div style="display: flex; flex-direction: column; gap: 20px;">
                    <?php if (empty($artigos)): ?>
                        <div class="card" style="text-align: center; color: var(--text-muted); padding: 40px;">
                            Nenhuma documentação publicada ainda. Seja o primeiro a partilhar conhecimento!
                        </div>
                    <?php endif; ?>

                    <?php foreach ($artigos as $art): ?>
                        <div class="card" style="position: relative;">
                            <span style="font-size: 10px; background: var(--bg-input); color: var(--accent); padding: 3px 8px; border-radius: 4px; font-weight: 600; text-transform: uppercase;">
                                📁 <?php echo htmlspecialchars($art['categoria']); ?>
                            </span>

                            <h2 style="font-size: 18px; color: #fff; margin: 10px 0 6px 0;"><?php echo htmlspecialchars($art['titulo']); ?></h2>
                            
                            <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 15px;">
                                Por: <b><?php echo htmlspecialchars($art['nome_autor']); ?></b> (<?php echo $art['perfil_autor']; ?>) | 
                                data: <?php echo date('d/m/Y', strtotime($art['data_criacao'])); ?>
                            </div>

                            <p style="color: var(--text-secondary); font-size: 14px; line-height: 1.6; white-space: pre-line;"><?php echo htmlspecialchars($art['conteudo']); ?></p>

                            <?php if ($perfil_usuario === 'Admin' || $art['id_autor'] == $user_id): ?>
                                <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid var(--border); text-align: right;">
                                    <a href="kb_lista.php?acao=remover&id=<?php echo $art['id']; ?>" 
                                       style="color: var(--red); font-size: 12px; text-decoration: none; font-weight: 600;" 
                                       onclick="return confirm('Deseja mesmo apagar este artigo da Base de Conhecimento?');">
                                        🗑️ Apagar Publicação
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="card">
                    <h3 style="color: var(--accent); margin-bottom: 15px; font-size: 16px;">📝 Partilhar Conhecimento</h3>
                    <form action="kb_lista.php" method="POST" style="display: flex; flex-direction: column; gap: 15px;">
                        <div>
                            <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Título do Artigo</label>
                            <input type="text" name="titulo" required placeholder="Ex: Como configurar a VPN no Windows" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Categoria</label>
                            <select name="categoria" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                                <option value="Redes e Sistemas">Redes e Sistemas</option>
                                <option value="Desenvolvimento">Desenvolvimento</option>
                                <option value="VOIP e Telecomunicações">VOIP e Telecomunicações</option>
                                <option value="Geral / Tutoriais" selected>Geral / Tutoriais</option>
                            </select>
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Conteúdo / Passo a Passo</label>
                            <textarea name="conteudo" required rows="8" placeholder="Escreva a solução detalhada aqui..." style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm); resize: vertical; font-family:inherit; font-size:13px;"></textarea>
                        </div>
                        <button type="submit" name="btn_publicar" style="padding: 12px; background: var(--accent); border:none; color:white; border-radius:var(--radius-sm); cursor:pointer; font-weight:600; text-align:center;">Publicar na Base</button>
                    </form>
                </div>

            </div>
        </div>
    </div>
</body>
</html>