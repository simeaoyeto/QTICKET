<?php
require_once 'conexao.php';

// Segurança: Expulsa o utilizador se tentar aceder à página diretamente sem ter feito login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$nome_usuario = $_SESSION['nome'];
$perfil_usuario = $_SESSION['perfil'];

$perfis_kb = [
    'Admin',
    'Responsavel',
    'Tecnico',
    'HelpDesk',
    'Desenvolvimento'
];

// Buscar dados de Área e Operação do utilizador atual
$stmt_me = $pdo->prepare("SELECT id_area, id_operacao FROM utilizadores WHERE id = ?");
$stmt_me->execute([$user_id]);
$me = $stmt_me->fetch(PDO::FETCH_ASSOC);

$meu_id_area = $me['id_area'] ?? null;
$meu_id_operacao = $me['id_operacao'] ?? null;

// =========================================================
// CONSTRUÇÃO DOS FILTROS DE CONTAGEM SEGUNDO O PERFIL
// =========================================================
$where_clauses = [];
$params = [];

if ($perfil_usuario === 'Admin' || $perfil_usuario === 'Diretor Geral') {
    // Sem restrições: Vê dados globais
} elseif ($perfil_usuario === 'Responsavel' || $perfil_usuario === 'Tecnico') {
    // Técnicos e Responsáveis olham para a sua Área Técnica ou o que criaram
    if ($meu_id_area) {
        $where_clauses[] = "(id_area_destino = ? OR id_criador = ?)";
        $params[] = $meu_id_area;
        $params[] = $user_id;
    } else {
        $where_clauses[] = "id_criador = ?";
        $params[] = $user_id;
    }
} else {
    // Utilizadores Comuns e Clientes Operacionais
    if ($meu_id_operacao) {
        $where_clauses[] = "(id_operacao_origem = ? OR id_criador = ?)";
        $params[] = $meu_id_operacao;
        $params[] = $user_id;
    } else {
        $where_clauses[] = "id_criador = ?";
        $params[] = $user_id;
    }
}

// Montar a base da query WHERE
$where_sql = "";
if (count($where_clauses) > 0) {
    $where_sql = " AND " . implode(" AND ", $where_clauses);
}

// 1. Contar Tickets Ativos (Abertos)
$sql_ativos = "SELECT COUNT(*) FROM tickets WHERE estado = 'Aberto'" . $where_sql;
$stmt = $pdo->prepare($sql_ativos);
$stmt->execute($params);
$total_ativos = $stmt->fetchColumn();

// 2. Contar Tickets Em Progresso
$sql_progresso = "SELECT COUNT(*) FROM tickets WHERE estado = 'Em Progresso'" . $where_sql;
$stmt = $pdo->prepare($sql_progresso);
$stmt->execute($params);
$total_progresso = $stmt->fetchColumn();

// 3. Contar Tickets Resolvidos
$sql_resolvidos = "SELECT COUNT(*) FROM tickets WHERE estado = 'Resolvido'" . $where_sql;
$stmt = $pdo->prepare($sql_resolvidos);
$stmt->execute($params);
$total_resolvidos = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QCCTICKET - Dashboard</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>

    <div class="app-layout">
        
        <div id="sidebar">
            <div class="sidebar-brand">
                <h3>QCCTICKET</h3>
                <span>Quality Support</span>
            </div>

            <div class="nav-section-title">Geral</div>
            <a href="index.php" class="nav-item active">📊 <span>Dashboard</span></a>
            <a href="tickets_lista.php" class="nav-item">🎫 <span>Meus Tickets</span></a>
            <a href="kb_lista.php" class="nav-item">📚 <span>Base Conhecimento</span></a>
            <a href="empresa.php" class="nav-item">🏢 <span>A Empresa</span></a>
            
            <?php if (in_array($perfil_usuario, ['Admin', 'Responsavel', 'Tecnico', 'HelpDesk', 'Desenvolvimento'])): ?>
                <div class="nav-section-title">Administração</div>

                <a href="usuarios_lista.php" class="nav-item">👥 <span>Gestão de Users</span></a>
                <a href="relatorios.php" class="nav-item">📈 <span>Relatórios</span></a>
            <?php endif; ?>

            <div class="sidebar-footer">
                <div class="user-badge">
                    <div style="font-size: 11px; color: var(--text-muted); margin-bottom: 2px;">Conectado como:</div>
                    <div style="font-size: 14px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                        <?php echo htmlspecialchars($nome_usuario); ?>
                    </div>
                    <div style="font-size: 11px; color: var(--accent); font-weight: 600; margin-top: 4px;">
                        ⚙️ <?php echo htmlspecialchars($perfil_usuario); ?>
                    </div>
                </div>
                <a href="logout.php" class="btn-danger">Sair do Sistema</a>
            </div>
        </div>

        <div id="main-content">
            <div class="page-header">
                <h1>Painel Operacional</h1>
                <p>Resumo de métricas em tempo real personalizadas para o seu nível de acesso.</p>
            </div>

            <div class="stats-grid">
                <div class="stat-card" style="border-top: 3px solid var(--accent);">
                    <span class="label">Tickets em Aberto</span>
                    <span class="value"><?php echo $total_ativos; ?></span>
                </div>
                <div class="stat-card" style="border-top: 3px solid var(--amber);">
                    <span class="label">Em Atendimento</span>
                    <span class="value"><?php echo $total_progresso; ?></span>
                </div>
                <div class="stat-card" style="border-top: 3px solid var(--green);">
                    <span class="label">Casos Resolvidos</span>
                    <span class="value"><?php echo $total_resolvidos; ?></span>
                </div>
            </div>

            <div class="card">
                <h2 style="color: var(--accent); margin-bottom: 12px; font-size: 18px;">🎯 Visibilidade Restrita Ativa</h2>
                <p style="color: var(--text-secondary); line-height: 1.6; font-size: 14px;">
                    Com base no seu perfil de <b><?php echo htmlspecialchars($perfil_usuario); ?></b>, os contadores acima refletem apenas os incidentes que estão sob a sua alçada direta, garantindo o cumprimento estrito dos controlos de segurança estabelecidos no projeto do sistema.
                </p>
            </div>
        </div>

    </div>

</body>
</html>