<?php
require_once 'conexao.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$nome_usuario = $_SESSION['nome'];
$perfil_usuario = $_SESSION['perfil'];

$ticket_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($ticket_id <= 0) {
    header("Location: tickets_lista.php");
    exit;
}

$mensagem = "";

// =========================================================
// PROCESSAR SUBMISSÃO DE COMENTÁRIO/MENSAGEM (POST)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_comentar'])) {
    $comentario = trim($_POST['comentario'] ?? '');
    
    if (!empty($comentario)) {
        $stmt_com = $pdo->prepare("INSERT INTO comentarios (id_ticket, id_utilizador, texto, data_criacao) VALUES (?, ?, ?, NOW())");
        $stmt_com->execute([$ticket_id, $user_id, $comentario]);
        $mensagem = "<div style='background: rgba(34,197,94,0.15); color: var(--green); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>Comentário adicionado!</div>";
    }
}

// =========================================================
// PROCESSAR ALTERAÇÃO DE ESTADO / ATRIBUIÇÃO (POST)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_alterar_estado'])) {
    $novo_estado = $_POST['novo_estado'] ?? '';
    
    // Regra: Utilizador comum/Cliente só pode fechar/confirmar se estiver Resolvido
    if (in_array($perfil_usuario, ['Utilizador Comum', 'Cliente Operacional']) && $novo_estado !== 'Fechado') {
        $mensagem = "<div style='background: rgba(239,68,68,0.15); color: var(--red); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>Não tem permissão para alterar para este estado.</div>";
    } else {
        $stmt_est = $pdo->prepare("UPDATE tickets SET estado = ? WHERE id = ?");
        $stmt_est->execute([$novo_estado, $ticket_id]);
        $mensagem = "<div style='background: rgba(34,197,94,0.15); color: var(--green); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>Estado do ticket atualizado para <b>$novo_estado</b>!</div>";
    }
}

// Atribuição de Tarefa (Apenas Responsável ou Admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_atribuir']) && in_array($perfil_usuario, ['Admin', 'Responsavel'])) {
    $id_tecnico = (int)($_POST['id_tecnico'] ?? 0);
    if ($id_tecnico > 0) {
        $stmt_atr = $pdo->prepare("UPDATE tickets SET id_tecnico_atribuido = ?, estado = 'Em Progresso' WHERE id = ?");
        $stmt_atr->execute([$id_tecnico, $ticket_id]);
        $mensagem = "<div style='background: rgba(3d,111,255,0.15); color: var(--accent); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>Tarefa atribuída e movida para Em Progresso!</div>";
    }
}

// =========================================================
// BUSCAR INFORMAÇÕES DO TICKET ATUAL
// =========================================================
$query_ticket = "
    SELECT t.*, 
           u.nome AS nome_criador, 
           a.nome AS nome_area, 
           o.nome AS nome_operacao,
           tec.nome AS nome_tecnico
    FROM tickets t
    LEFT JOIN utilizadores u ON t.id_criador = u.id
    LEFT JOIN areas a ON t.id_area_destino = a.id
    LEFT JOIN operacoes o ON t.id_operacao_origem = o.id
    LEFT JOIN utilizadores tec ON t.id_tecnico_atribuido = tec.id
    WHERE t.id = ?
";
$stmt = $pdo->prepare($query_ticket);
$stmt->execute([$ticket_id]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
    die("Ticket não encontrado ou sem permissão de acesso.");
}

// Buscar comentários do Ticket
$stmt_comentarios = $pdo->prepare("
    SELECT c.*, u.nome AS nome_autor, u.perfil AS perfil_autor 
    FROM comentarios c 
    JOIN utilizadores u ON c.id_utilizador = u.id 
    WHERE c.id_ticket = ? 
    ORDER BY c.id ASC
");
$stmt_comentarios->execute([$ticket_id]);
$lista_comentarios = $stmt_comentarios->fetchAll(PDO::FETCH_ASSOC);

// Buscar técnicos da mesma área para o dropdown de atribuição (Responsáveis)
$lista_tecnicos = [];
if (in_array($perfil_usuario, ['Admin', 'Responsavel'])) {
    $stmt_tec = $pdo->prepare("SELECT id, nome FROM utilizadores WHERE perfil = 'Tecnico' AND estado = 'Ativo'");
    $stmt_tec->execute();
    $lista_tecnicos = $stmt_tec->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <title>QCCTICKET - Detalhes do Ticket #<?php echo $ticket['id']; ?></title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="app-layout">
        <div id="sidebar">
            <div class="sidebar-brand"><h3>QCCTICKET</h3><span>Quality Support</span></div>
            <div class="nav-section-title">Geral</div>
            <a href="index.php" class="nav-item">📊 <span>Dashboard</span></a>
            <a href="tickets_lista.php" class="nav-item active">🎫 <span>Meus Tickets</span></a>
            <a href="kb_lista.php" class="nav-item">📚 <span>Base Conhecimento</span></a>
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
            <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                <div>
                    <a href="tickets_lista.php" style="color: var(--accent); text-decoration: none; font-size: 13px; font-weight: 600;">← Voltar para a lista</a>
                    <h1 style="margin-top: 5px;">Ticket #<?php echo $ticket['id']; ?></h1>
                </div>
                <div>
                    <span style="padding: 6px 12px; background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13px;">
                        Estado Atual: <b style="color: var(--accent);"><?php echo $ticket['estado']; ?></b>
                    </span>
                </div>
            </div>

            <?php echo $mensagem; ?>

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 25px; align-items: start;">
                
                <div>
                    <div class="card" style="margin-bottom: 25px;">
                        <h2 style="font-size: 20px; color: #fff; margin-bottom: 10px;"><?php echo htmlspecialchars($ticket['titulo']); ?></h2>
                        <div style="font-size: 13px; color: var(--text-secondary); margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid var(--border);">
                            Aberto por: <b><?php echo htmlspecialchars($ticket['nome_criador']); ?></b> | 
                            Origem: <b><?php echo $ticket['nome_operacao'] ? htmlspecialchars($ticket['nome_operacao']) : 'Interno'; ?></b> |
                            Data: <?php echo date('d/m/Y H:i', strtotime($ticket['data_criacao'])); ?>
                        </div>
                        <p style="color: var(--text-primary); font-size: 14px; line-height: 1.6; white-space: pre-line;"><?php echo htmlspecialchars($ticket['descricao']); ?></p>
                    </div>

                    <h3 style="font-size: 16px; margin-bottom: 15px; color: var(--text-secondary);">💬 Histórico e Interações</h3>
                    <div style="display: flex; flex-direction: column; gap: 15px; margin-bottom: 25px;">
                        <?php if (empty($lista_comentarios)): ?>
                            <p style="color: var(--text-muted); font-size: 14px; font-style: italic;">Nenhum comentário técnico adicionado ainda.</p>
                        <?php endif; ?>
                        
                        <?php foreach ($lista_comentarios as $com): ?>
                            <div class="card" style="background: <?php echo $com['id_utilizador'] == $user_id ? 'rgba(61,111,255,0.05)' : 'var(--bg-sidebar)'; ?>; border-left: 3px solid <?php echo $com['id_utilizador'] == $user_id ? 'var(--accent)' : 'var(--border)'; ?>; padding: 15px;">
                                <div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 6px; color: var(--text-secondary);">
                                    <span><b><?php echo htmlspecialchars($com['nome_autor']); ?></b> (<?php echo $com['perfil_autor']; ?>)</span>
                                    <span><?php echo date('d/m/Y H:i', strtotime($com['data_criacao'])); ?></span>
                                </div>
                                <p style="font-size: 14px; color: var(--text-primary); line-height: 1.5; white-space: pre-line;"><?php echo htmlspecialchars($com['texto']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="card">
                        <form action="ticket_detalhes.php?id=<?php echo $ticket_id; ?>" method="POST">
                            <textarea name="comentario" required placeholder="Escreva uma mensagem ou atualização técnica..." rows="3" style="width:100%; padding: 12px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm); resize: vertical; margin-bottom: 10px; font-family: inherit;"></textarea>
                            <button type="submit" name="btn_comentar" style="padding: 10px 20px; background: var(--accent); border:none; color:white; border-radius:var(--radius-sm); cursor:pointer; font-weight:600;">Enviar Mensagem</button>
                        </form>
                    </div>
                </div>

                <div style="display: flex; flex-direction: column; gap: 25px;">
                    
                    <div class="card" style="background: var(--bg-sidebar);">
                        <h3 style="font-size: 14px; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 15px; letter-spacing: 0.5px;">Atribuição</h3>
                        <div style="font-size: 14px; margin-bottom: 10px;">
                            <span style="color: var(--text-muted);">Destino:</span> <b style="color: #fff;"><?php echo htmlspecialchars($ticket['nome_area']); ?></b>
                        </div>
                        <div style="font-size: 14px;">
                            <span style="color: var(--text-muted);">Técnico:</span> 
                            <b style="color: var(--accent);"><?php echo $ticket['nome_tecnico'] ? htmlspecialchars($ticket['nome_tecnico']) : 'Não Atribuído'; ?></b>
                        </div>
                    </div>

                    <?php if (in_array($perfil_usuario, ['Admin', 'Responsavel'])): ?>
                        <div class="card">
                            <h3 style="font-size: 15px; color: var(--accent); margin-bottom: 12px;">🎯 Atribuir a Técnico</h3>
                            <form action="ticket_detalhes.php?id=<?php echo $ticket_id; ?>" method="POST" style="display: flex; flex-direction: column; gap: 10px;">
                                <select name="id_tecnico" required style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                                    <option value="">-- Escolha o Técnico --</option>
                                    <?php foreach ($lista_tecnicos as $tec): ?>
                                        <option value="<?php echo $tec['id']; ?>" <?php echo ($ticket['id_tecnico_atribuido'] == $tec['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($tec['nome']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" name="btn_atribuir" style="padding: 9px; background: var(--accent); border:none; color:white; border-radius:var(--radius-sm); cursor:pointer; font-weight:600; font-size:13px;">Confirmar Atribuição</button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <?php if (in_array($perfil_usuario, ['Admin', 'Responsavel', 'Tecnico'])): ?>
                        <div class="card">
                            <h3 style="font-size: 15px; color: #fff; margin-bottom: 12px;">⚙️ Alterar Estado</h3>
                            <form action="ticket_detalhes.php?id=<?php echo $ticket_id; ?>" method="POST" style="display: flex; flex-direction: column; gap: 10px;">
                                <select name="novo_estado" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                                    <option value="Aberto" <?php echo $ticket['estado'] === 'Aberto' ? 'selected' : ''; ?>>📂 Aberto</option>
                                    <option value="Em Progresso" <?php echo $ticket['estado'] === 'Em Progresso' ? 'selected' : ''; ?>>⚡ Em Progresso</option>
                                    <option value="Resolvido" <?php echo $ticket['estado'] === 'Resolvido' ? 'selected' : ''; ?>>✅ Resolvido</option>
                                </select>
                                <button type="submit" name="btn_alterar_estado" style="padding: 9px; background: var(--bg-input); border:1px solid var(--border); color:white; border-radius:var(--radius-sm); cursor:pointer; font-weight:600; font-size:13px;">Atualizar Fluxo</button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <?php if ($ticket['estado'] === 'Resolvido'): ?>
                        <div class="card" style="border: 1px solid rgba(16,185,129,0.3); background: rgba(16,185,129,0.03);">
                            <h3 style="font-size: 15px; color: var(--green); margin-bottom: 8px;">✔️ Solução Encontrada</h3>
                            <p style="font-size: 12px; color: var(--text-secondary); margin-bottom: 12px;">Se o problema foi resolvido de forma satisfatória, confirme para encerrar o ticket.</p>
                            <form action="ticket_detalhes.php?id=<?php echo $ticket_id; ?>" method="POST">
                                <input type="hidden" name="novo_estado" value="Fechado">
                                <button type="submit" name="btn_alterar_estado" style="width: 100%; padding: 10px; background: var(--green); border:none; color:white; border-radius:var(--radius-sm); cursor:pointer; font-weight:600; font-size:13px;">Confirmar Solução</button>
                            </form>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>
</body>
</html>