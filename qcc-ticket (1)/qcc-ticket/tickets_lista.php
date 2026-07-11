<?php
require_once 'conexao.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$nome_usuario = $_SESSION['nome'];
$perfil_usuario = $_SESSION['perfil'];

// Buscar dados completos do utilizador logado
$stmt_me = $pdo->prepare("SELECT id_area, id_operacao FROM utilizadores WHERE id = ?");
$stmt_me->execute([$user_id]);
$me = $stmt_me->fetch(PDO::FETCH_ASSOC);

$meu_id_area = $me['id_area'] ?? null;
$meu_id_operacao = $me['id_operacao'] ?? null;

$mensagem = "";

// =========================================================
// PROCESSAR AÇÕES RÁPIDAS DA TABELA (GET)
// =========================================================
if (isset($_GET['acao']) && isset($_GET['id'])) {
    $id_ticket_acao = (int)$_GET['id'];
    $acao = $_GET['acao'];

    if ($acao === 'fechar' && in_array($perfil_usuario, ['Admin', 'Responsavel'])) {
        $stmt = $pdo->prepare("UPDATE tickets SET estado = 'Fechado' WHERE id = ?");
        $stmt->execute([$id_ticket_acao]);
        $mensagem = "<div style='background: rgba(245,158,11,0.2); color: var(--amber); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Ticket #$id_ticket_acao foi fechado com sucesso!</div>";
    } elseif ($acao === 'remover' && $perfil_usuario === 'Admin') {
        // Remover também os comentários associados para evitar erros de integridade (Foreign Key)
        $stmt_del_com = $pdo->prepare("DELETE FROM comentarios WHERE id_ticket = ?");
        $stmt_del_com->execute([$id_ticket_acao]);

        // Apaga o ticket
        $stmt = $pdo->prepare("DELETE FROM tickets WHERE id = ?");
        $stmt->execute([$id_ticket_acao]);

        // RECONTAGEM AUTOMÁTICA DO AUTO_INCREMENT:
        // Procura o maior ID atual na tabela
        $max_id = $pdo->query("SELECT MAX(id) FROM tickets")->fetchColumn();
        // Se a tabela ficou vazia, o próximo será 1. Se não, será o Maior ID + 1
        $proximo_id = $max_id ? $max_id + 1 : 1;
        // Altera o contador interno do MySQL
        $pdo->exec("ALTER TABLE tickets AUTO_INCREMENT = $proximo_id");

        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Ticket #$id_ticket_acao eliminado permanentemente! Contador reajustado para $proximo_id.</div>";
    }
}

// =========================================================
// PROCESSAR ABERTURA DE NOVO TICKET (POST)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_criar_ticket'])) {
    $titulo = trim($_POST['titulo'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $prioridade = $_POST['prioridade'] ?? 'Media';
    $id_area_destino = (int)($_POST['id_area_destino'] ?? 0);

    if (!empty($titulo) && !empty($descricao) && $id_area_destino > 0) {
        $stmt_cad = $pdo->prepare("
            INSERT INTO tickets (titulo, descricao, prioridade, estado, id_criador, id_area_destino, id_operacao_origem, data_criacao) 
            VALUES (?, ?, ?, 'Aberto', ?, ?, ?, NOW())
        ");
        $stmt_cad->execute([$titulo, $descricao, $prioridade, $user_id, $id_area_destino, $meu_id_operacao]);
        
        $mensagem = "<div style='background: rgba(34,197,94,0.2); color: var(--green); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Ticket submetido com sucesso! A equipa técnica foi notificada.</div>";
    } else {
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: Preencha todos os campos obrigatórios.</div>";
    }
}

// =========================================================
// CONSTRUÇÃO DO FILTRO DINÂMICO DE VISUALIZAÇÃO
// =========================================================
$filtro_estado = $_GET['estado'] ?? 'Todos';
$where_clauses = [];
$params = [];

if ($perfil_usuario === 'Admin' || $perfil_usuario === 'Diretor Geral') {
    // Acesso total global
} elseif ($perfil_usuario === 'Responsavel' || $perfil_usuario === 'Tecnico') {
    if ($meu_id_area) {
        $where_clauses[] = "(t.id_area_destino = ? OR t.id_criador = ?)";
        $params[] = $meu_id_area;
        $params[] = $user_id;
    } else {
        $where_clauses[] = "t.id_criador = ?";
        $params[] = $user_id;
    }
} else {
    if ($meu_id_operacao) {
        $where_clauses[] = "(t.id_operacao_origem = ? OR t.id_criador = ?)";
        $params[] = $meu_id_operacao;
        $params[] = $user_id;
    } else {
        $where_clauses[] = "t.id_criador = ?";
        $params[] = $user_id;
    }
}

if ($filtro_estado !== 'Todos') {
    $where_clauses[] = "t.estado = ?";
    $params[] = $filtro_estado;
}

$where_sql = "";
if (count($where_clauses) > 0) {
    $where_sql = "WHERE " . implode(" AND ", $where_clauses);
}

$query_tickets = "
    SELECT t.*, 
           u.nome AS nome_criador, 
           a.nome AS nome_area, 
           o.nome AS nome_operacao
    FROM tickets t
    LEFT JOIN utilizadores u ON t.id_criador = u.id
    LEFT JOIN areas a ON t.id_area_destino = a.id
    LEFT JOIN operacoes o ON t.id_operacao_origem = o.id
    $where_sql
    ORDER BY 
        CASE t.prioridade WHEN 'Alta' THEN 1 WHEN 'Media' THEN 2 WHEN 'Baixa' THEN 3 END, 
        t.id DESC
";

$stmt_tickets = $pdo->prepare($query_tickets);
$stmt_tickets->execute($params);
$lista_tickets = $stmt_tickets->fetchAll(PDO::FETCH_ASSOC);

$areas_destino = $pdo->query("SELECT id, nome FROM areas ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <title>QCCTICKET - Meus Tickets</title>
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
                <h1>Painel de Incidentes e Solicitações</h1>
                <p>Acompanhe em tempo real o progresso dos seus pedidos de suporte.</p>
            </div>

            <?php echo $mensagem; ?>

            <div style="display: flex; gap: 10px; margin-bottom: 25px;">
                <a href="tickets_lista.php?estado=Todos" style="padding: 8px 16px; background: <?php echo $filtro_estado === 'Todos' ? 'var(--accent)' : 'var(--bg-sidebar)'; ?>; color:#fff; border-radius: var(--radius-sm); text-decoration:none; font-size:13px; font-weight:500;">Todos</a>
                <a href="tickets_lista.php?estado=Aberto" style="padding: 8px 16px; background: <?php echo $filtro_estado === 'Aberto' ? 'var(--accent)' : 'var(--bg-sidebar)'; ?>; color:#fff; border-radius: var(--radius-sm); text-decoration:none; font-size:13px; font-weight:500;">📂 Abertos</a>
                <a href="tickets_lista.php?estado=Em Progresso" style="padding: 8px 16px; background: <?php echo $filtro_estado === 'Em Progresso' ? 'var(--accent)' : 'var(--bg-sidebar)'; ?>; color:#fff; border-radius: var(--radius-sm); text-decoration:none; font-size:13px; font-weight:500;">⚡ Em Progresso</a>
                <a href="tickets_lista.php?estado=Resolvido" style="padding: 8px 16px; background: <?php echo $filtro_estado === 'Resolvido' ? 'var(--accent)' : 'var(--bg-sidebar)'; ?>; color:#fff; border-radius: var(--radius-sm); text-decoration:none; font-size:13px; font-weight:500;">✅ Resolvidos</a>
                <a href="tickets_lista.php?estado=Fechado" style="padding: 8px 16px; background: <?php echo $filtro_estado === 'Fechado' ? 'var(--accent)' : 'var(--bg-sidebar)'; ?>; color:#fff; border-radius: var(--radius-sm); text-decoration:none; font-size:13px; font-weight:500;">🔒 Fechados</a>
            </div>

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 25px; align-items: start;">
                
                <div class="card" style="padding:0; overflow: hidden;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
                        <thead>
                            <tr style="background: var(--bg-sidebar); border-bottom: 1px solid var(--border);">
                                <th style="padding: 15px;">ID / Assunto</th>
                                <th style="padding: 15px;">Origem</th>
                                <th style="padding: 15px;">Destino Técnico</th>
                                <th style="padding: 15px;">Prioridade</th>
                                <th style="padding: 15px;">Estado</th>
                                <th style="padding: 15px; text-align: center;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($lista_tickets)): ?>
                                <tr>
                                    <td colspan="6" style="padding: 30px; text-align: center; color: var(--text-muted);">Nenhum ticket encontrado.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($lista_tickets as $ticket): ?>
                                <tr style="border-bottom: 1px solid var(--border); background: rgba(255,255,255,0.01);">
                                    <td style="padding: 15px;">
                                        <div style="font-weight:600; color:#fff; font-size:14px; margin-bottom:4px;">#<?php echo $ticket['id']; ?> - <?php echo htmlspecialchars($ticket['titulo']); ?></div>
                                        <div style="font-size:12px; color:var(--text-muted);">Por: <?php echo htmlspecialchars($ticket['nome_criador']); ?></div>
                                    </td>
                                    <td style="padding: 15px; color:var(--text-secondary);">
                                        <?php echo $ticket['nome_operacao'] ? "📱 " . htmlspecialchars($ticket['nome_operacao']) : "🏢 Interno"; ?>
                                    </td>
                                    <td style="padding: 15px;">
                                        <span style="color:var(--accent); font-weight:500;">🛠️ <?php echo htmlspecialchars($ticket['nome_area']); ?></span>
                                    </td>
                                    <td style="padding: 15px;">
                                        <?php 
                                            $p_color = 'var(--text-secondary)';
                                            if($ticket['prioridade'] === 'Alta') $p_color = 'var(--red)';
                                            if($ticket['prioridade'] === 'Media') $p_color = 'var(--amber)';
                                        ?>
                                        <span style="color: <?php echo $p_color; ?>; font-weight: 600;">⚠️ <?php echo $ticket['prioridade']; ?></span>
                                    </td>
                                    <td style="padding: 15px;">
                                        <?php 
                                            $status_style = "color: var(--green); background: rgba(16,185,129,0.1);";
                                            if($ticket['estado'] === 'Aberto') $status_style = "color: #3b82f6; background: rgba(59,130,246,0.1);";
                                            if($ticket['estado'] === 'Em Progresso') $status_style = "color: var(--amber); background: rgba(245,158,11,0.1);";
                                            if($ticket['estado'] === 'Fechado') $status_style = "color: var(--text-muted); background: rgba(255,255,255,0.05);";
                                        ?>
                                        <span style="<?php echo $status_style; ?> padding: 4px 8px; border-radius: 10px; font-size: 11px; font-weight:600; text-transform: uppercase;"><?php echo $ticket['estado']; ?></span>
                                    </td>
                                    
                                    <td style="padding: 15px; text-align: center; white-space: nowrap;">
                                        <a href="ticket_detalhes.php?id=<?php echo $ticket['id']; ?>" style="color: #fff; text-decoration: none; font-weight: 600; font-size: 12px; background: var(--bg-input); padding: 4px 8px; border-radius: var(--radius-sm); border: 1px solid var(--border); margin-right: 5px;">Tratar</a>
                                        
                                        <?php if (in_array($perfil_usuario, ['Admin', 'Responsavel']) && $ticket['estado'] !== 'Fechado'): ?>
                                            <a href="tickets_lista.php?acao=fechar&id=<?php echo $ticket['id']; ?>" style="color: var(--amber); text-decoration: none; font-weight: 600; font-size: 12px; margin-right: 5px;" onclick="return confirm('Deseja encerrar este ticket imediatamente?');">Fechar</a>
                                        <?php endif; ?>

                                        <?php if ($perfil_usuario === 'Admin'): ?>
                                            <a href="tickets_lista.php?acao=remover&id=<?php echo $ticket['id']; ?>" style="color: var(--red); text-decoration: none; font-weight: 600; font-size: 12px;" onclick="return confirm('ATENÇÃO: Isto apagará o ticket e todo o seu histórico de mensagens permanentemente. Continuar?');">Remover</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card">
                    <h3 style="margin-bottom: 15px; font-size: 16px; color: var(--accent);">🎫 Abrir Novo Chamado</h3>
                    <form action="tickets_lista.php" method="POST" style="display: flex; flex-direction: column; gap: 15px;">
                        <div>
                            <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Assunto Breve</label>
                            <input type="text" name="titulo" required placeholder="Ex: Falha de rede na VPN" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Área Técnica de Destino</label>
                            <select name="id_area_destino" required style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                                <option value="">-- Selecionar Equipa --</option>
                                <?php foreach($areas_destino as $ad): ?>
                                    <option value="<?php echo $ad['id']; ?>">🛠️ <?php echo htmlspecialchars($ad['nome']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Nível de Urgência</label>
                            <select name="prioridade" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                                <option value="Baixa">🟢 Baixa</option>
                                <option value="Media" selected>🟡 Média</option>
                                <option value="Alta">🔴 Alta</option>
                            </select>
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Descrição Detalhada</label>
                            <textarea name="descricao" required rows="5" placeholder="Descreva o problema..." style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm); resize: vertical; font-family:inherit; font-size:13px;"></textarea>
                        </div>
                        <button type="submit" name="btn_criar_ticket" style="padding: 12px; background: var(--accent); border:none; color:white; border-radius:var(--radius-sm); cursor:pointer; font-weight:600; text-align:center;">Submeter Ticket</button>
                    </form>
                </div>

            </div>
        </div>
    </div>
</body>
</html>