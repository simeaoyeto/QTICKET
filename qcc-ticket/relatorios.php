<?php
/**
 * KIAMI — Relatórios estatísticos e exportações
 *
 * Acesso: Admin, staff Redes/Desenvolvimento ou Diretor Geral (só consulta).
 * Inclui filtros, DataTables, links para PDF/Excel e métricas por área/operação/técnico.
 */
require_once 'conexao.php';
require_once 'includes/relatorio_query.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$nome_usuario = $_SESSION['nome'];
$perfil_usuario = $_SESSION['perfil'];
$contexto = obterContextoUsuario($pdo);

if (!podeAcederAdministracao($contexto) && $perfil_usuario !== 'Diretor Geral') {
    http_response_code(403);
    registarAuditoria($pdo, 'Acesso Negado', 'Tentativa de aceder a relatórios');
    die("Acesso negado. Não tem permissões para visualizar relatórios estatísticos.");
}

$filtros = obterFiltrosRelatorioFromRequest();
[$sqlTickets, $paramsTickets] = construirQueryRelatorioTickets($filtros, $contexto);
$stmtTickets = $pdo->prepare($sqlTickets);
$stmtTickets->execute($paramsTickets);
$tickets_filtrados = $stmtTickets->fetchAll(PDO::FETCH_ASSOC);

// Dropdowns: Admin/Diretor vêem tudo; técnicos/responsáveis só a sua área e operação
$visaoGlobal = in_array($perfil_usuario, ['Admin', 'Diretor Geral'], true);
if ($visaoGlobal) {
    $areas = $pdo->query("SELECT id, nome FROM areas ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
    $operacoes = $pdo->query("SELECT id, nome FROM operacoes ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
    $tecnicos = $pdo->query("SELECT id, nome FROM utilizadores WHERE perfil = 'Tecnico' AND estado = 'Ativo' ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $idsAreasRel = obterIdsAreasContexto($contexto);
    $areas = [];
    if (!empty($idsAreasRel)) {
        $ph = implode(',', array_fill(0, count($idsAreasRel), '?'));
        $stA = $pdo->prepare("SELECT id, nome FROM areas WHERE id IN ($ph) ORDER BY nome");
        $stA->execute($idsAreasRel);
        $areas = $stA->fetchAll(PDO::FETCH_ASSOC);
    }
    $operacoes = [];
    if (!empty($contexto['id_operacao'])) {
        $stO = $pdo->prepare("SELECT id, nome FROM operacoes WHERE id = ?");
        $stO->execute([(int)$contexto['id_operacao']]);
        $operacoes = $stO->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $operacoes = $pdo->query("SELECT id, nome FROM operacoes ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
    }
    // Técnicos das áreas do utilizador (coluna ou multiárea)
    if (!empty($idsAreasRel)) {
        $ph = implode(',', array_fill(0, count($idsAreasRel), '?'));
        $stT = $pdo->prepare("
            SELECT DISTINCT u.id, u.nome
            FROM utilizadores u
            LEFT JOIN utilizador_areas ua ON ua.id_utilizador = u.id
            WHERE u.perfil = 'Tecnico' AND u.estado = 'Ativo'
              AND (u.id_area IN ($ph) OR ua.id_area IN ($ph))
            ORDER BY u.nome
        ");
        $stT->execute(array_merge($idsAreasRel, $idsAreasRel));
        $tecnicos = $stT->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $tecnicos = [];
    }
}

$queryStringFiltros = http_build_query(array_filter($filtros));

// Escopo de visibilidade para as métricas (área + operação do utilizador)
[$clausulasVis, $paramsVis] = $visaoGlobal ? [[], []] : obterFiltroTickets($contexto);
$filtroVisSql = !empty($clausulasVis) ? (' AND ' . implode(' AND ', $clausulasVis)) : '';

// =========================================================
// MÉTRICA 1: ESTATÍSTICAS POR ÁREA TÉCNICA (no escopo do utilizador)
// =========================================================
$query_areas = "
    SELECT a.nome AS area,
           COUNT(t.id) AS total,
           SUM(CASE WHEN t.estado = 'Aberto' THEN 1 ELSE 0 END) AS abertos,
           SUM(CASE WHEN t.estado = 'Em Progresso' THEN 1 ELSE 0 END) AS progresso,
           SUM(CASE WHEN t.estado = 'Resolvido' THEN 1 ELSE 0 END) AS resolvidos
    FROM areas a
    LEFT JOIN tickets t ON t.id_area_destino = a.id
    WHERE 1=1
";
$paramsAreas = [];
if (!$visaoGlobal) {
    $idsAreasRel = obterIdsAreasContexto($contexto);
    if (!empty($idsAreasRel)) {
        $ph = implode(',', array_fill(0, count($idsAreasRel), '?'));
        $query_areas .= " AND a.id IN ($ph)";
        foreach ($idsAreasRel as $ia) {
            $paramsAreas[] = $ia;
        }
    }
}
// Conta só tickets visíveis ao utilizador (além da área da linha)
if ($filtroVisSql !== '') {
    $query_areas = "
        SELECT a.nome AS area,
               COUNT(t.id) AS total,
               SUM(CASE WHEN t.estado = 'Aberto' THEN 1 ELSE 0 END) AS abertos,
               SUM(CASE WHEN t.estado = 'Em Progresso' THEN 1 ELSE 0 END) AS progresso,
               SUM(CASE WHEN t.estado = 'Resolvido' THEN 1 ELSE 0 END) AS resolvidos
        FROM areas a
        LEFT JOIN tickets t ON t.id_area_destino = a.id {$filtroVisSql}
        WHERE 1=1
    ";
    $paramsAreas = $paramsVis;
    if (!$visaoGlobal) {
        $idsAreasFiltro = obterIdsAreasContexto($contexto);
        if (!empty($idsAreasFiltro)) {
            $ph = implode(',', array_fill(0, count($idsAreasFiltro), '?'));
            $query_areas .= " AND a.id IN ($ph)";
            foreach ($idsAreasFiltro as $ia) {
                $paramsAreas[] = $ia;
            }
        }
    }
}
$query_areas .= " GROUP BY a.id, a.nome ORDER BY total DESC";
$stmtRelAreas = $pdo->prepare($query_areas);
$stmtRelAreas->execute($paramsAreas);
$relatorio_areas = $stmtRelAreas->fetchAll(PDO::FETCH_ASSOC);

// =========================================================
// MÉTRICA 2: ESTATÍSTICAS POR CLIENTE OPERACIONAL
// =========================================================
$query_operacoes = "
    SELECT o.nome AS operacao,
           COUNT(t.id) AS total,
           SUM(CASE WHEN t.estado = 'Resolvido' THEN 1 ELSE 0 END) AS resolvidos
    FROM operacoes o
    LEFT JOIN tickets t ON t.id_operacao_origem = o.id" . ($filtroVisSql !== '' ? $filtroVisSql : '') . "
    WHERE 1=1
";
$paramsOps = $paramsVis;
if (!$visaoGlobal && !empty($contexto['id_operacao'])) {
    $query_operacoes .= " AND o.id = ?";
    $paramsOps[] = (int)$contexto['id_operacao'];
}
$query_operacoes .= " GROUP BY o.id, o.nome ORDER BY total DESC";
$stmtRelOps = $pdo->prepare($query_operacoes);
$stmtRelOps->execute($paramsOps);
$relatorio_operacoes = $stmtRelOps->fetchAll(PDO::FETCH_ASSOC);

// =========================================================
// MÉTRICA 3: PERFORMANCE INDIVIDUAL DOS TÉCNICOS
// =========================================================
$query_tecnicos = "
    SELECT u.nome AS tecnico,
           COUNT(t.id) AS atribuidos,
           SUM(CASE WHEN t.estado = 'Resolvido' THEN 1 ELSE 0 END) AS concluidos
    FROM utilizadores u
    LEFT JOIN utilizador_areas ua ON ua.id_utilizador = u.id
    LEFT JOIN tickets t ON t.id_tecnico_atribuido = u.id" . ($filtroVisSql !== '' ? $filtroVisSql : '') . "
    WHERE u.perfil = 'Tecnico' AND u.estado = 'Ativo'
";
$paramsTec = $paramsVis;
if (!$visaoGlobal) {
    $idsAreasTec = obterIdsAreasContexto($contexto);
    if (!empty($idsAreasTec)) {
        $ph = implode(',', array_fill(0, count($idsAreasTec), '?'));
        $query_tecnicos .= " AND (u.id_area IN ($ph) OR ua.id_area IN ($ph))";
        foreach ($idsAreasTec as $ia) {
            $paramsTec[] = $ia;
        }
        foreach ($idsAreasTec as $ia) {
            $paramsTec[] = $ia;
        }
    }
}
$query_tecnicos .= " GROUP BY u.id, u.nome ORDER BY concluidos DESC, atribuidos DESC";
$stmtRelTec = $pdo->prepare($query_tecnicos);
$stmtRelTec->execute($paramsTec);
$relatorio_tecnicos = $stmtRelTec->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KIAMI - Relatórios e Métricas</title>
    <link rel="stylesheet" href="style.css">
    <script src="tema.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script></head>
<body>
    <div class="app-layout">
        <!-- SIDEBAR -->
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
            <?php if (podeGerirUtilizadores($contexto)): ?>
                <a href="perfis_lista.php" class="nav-item">🪪 <span>Gestão de Perfis</span></a>
                <a href="usuarios_lista.php" class="nav-item nav-sub">👥 <span>Utilizadores</span></a>
                <a href="equipa_online.php" class="nav-item">🟢 <span>Equipa Disponível</span></a>
                <a href="assuntos_lista.php" class="nav-item">📋 <span>Assuntos de Ticket</span></a>
                <a href="emails_areas.php" class="nav-item">✉️ <span>Emails das Áreas</span></a>
            <?php endif; ?>
            <a href="relatorios.php" class="nav-item active">📈 <span>Relatórios</span></a>
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

        <!-- CONTEÚDO PRINCIPAL -->
        <div id="main-content">
            <div class="page-header">
                <h1>Relatórios e Métricas</h1>
                <p>Analise tickets com filtros avançados e exporte para PDF ou Excel.</p>
            </div>

            <div class="card" style="margin-bottom: 25px;">
                <h3 style="color: var(--accent); margin-bottom: 15px; font-size: 15px;">🔍 Filtros</h3>
                <form method="GET" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; align-items: end;">
                    <div>
                        <label style="font-size: 11px; color: var(--text-muted);">Data início</label>
                        <input type="date" name="data_inicio" value="<?php echo htmlspecialchars($filtros['data_inicio']); ?>" style="width:100%;padding:8px;background:var(--bg-input);border:1px solid var(--border);color:#fff;border-radius:var(--radius-sm);">
                    </div>
                    <div>
                        <label style="font-size: 11px; color: var(--text-muted);">Data fim</label>
                        <input type="date" name="data_fim" value="<?php echo htmlspecialchars($filtros['data_fim']); ?>" style="width:100%;padding:8px;background:var(--bg-input);border:1px solid var(--border);color:#fff;border-radius:var(--radius-sm);">
                    </div>
                    <div>
                        <label style="font-size: 11px; color: var(--text-muted);">Técnico</label>
                        <select name="id_tecnico" style="width:100%;padding:8px;background:var(--bg-input);border:1px solid var(--border);color:#fff;border-radius:var(--radius-sm);">
                            <option value="">Todos</option>
                            <?php foreach ($tecnicos as $t): ?>
                                <option value="<?php echo $t['id']; ?>" <?php echo $filtros['id_tecnico'] == $t['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($t['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="font-size: 11px; color: var(--text-muted);">Área</label>
                        <select name="id_area" style="width:100%;padding:8px;background:var(--bg-input);border:1px solid var(--border);color:#fff;border-radius:var(--radius-sm);">
                            <option value="">Todas</option>
                            <?php foreach ($areas as $a): ?>
                                <option value="<?php echo $a['id']; ?>" <?php echo $filtros['id_area'] == $a['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($a['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="font-size: 11px; color: var(--text-muted);">Operação</label>
                        <select name="id_operacao" style="width:100%;padding:8px;background:var(--bg-input);border:1px solid var(--border);color:#fff;border-radius:var(--radius-sm);">
                            <option value="">Todas</option>
                            <?php foreach ($operacoes as $o): ?>
                                <option value="<?php echo $o['id']; ?>" <?php echo $filtros['id_operacao'] == $o['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($o['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="font-size: 11px; color: var(--text-muted);">Prioridade</label>
                        <select name="prioridade" style="width:100%;padding:8px;background:var(--bg-input);border:1px solid var(--border);color:#fff;border-radius:var(--radius-sm);">
                            <option value="">Todas</option>
                            <?php foreach (['Alta','Média','Baixa'] as $p): ?>
                                <option value="<?php echo $p; ?>" <?php echo $filtros['prioridade'] === $p ? 'selected' : ''; ?>><?php echo $p; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="font-size: 11px; color: var(--text-muted);">Estado</label>
                        <select name="estado" style="width:100%;padding:8px;background:var(--bg-input);border:1px solid var(--border);color:#fff;border-radius:var(--radius-sm);">
                            <option value="">Todos</option>
                            <?php foreach (['Aberto','Em Progresso','Reencaminhado','Resolvido'] as $e): ?>
                                <option value="<?php echo $e; ?>" <?php echo $filtros['estado'] === $e ? 'selected' : ''; ?>><?php echo $e; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="font-size: 11px; color: var(--text-muted);">SLA</label>
                        <select name="sla" style="width:100%;padding:8px;background:var(--bg-input);border:1px solid var(--border);color:#fff;border-radius:var(--radius-sm);">
                            <option value="">Todos</option>
                            <option value="cumprido" <?php echo $filtros['sla'] === 'cumprido' ? 'selected' : ''; ?>>Cumprido</option>
                            <option value="vencido" <?php echo $filtros['sla'] === 'vencido' ? 'selected' : ''; ?>>Vencido</option>
                        </select>
                    </div>
                    <button type="submit" style="padding:9px 16px;background:var(--accent);border:none;color:#fff;border-radius:var(--radius-sm);font-weight:600;cursor:pointer;">Filtrar</button>
                </form>
                <div style="margin-top: 15px; display: flex; gap: 10px;">
                    <a href="exports/pdf/export_tickets.php?<?php echo $queryStringFiltros; ?>" style="padding:8px 16px;background:var(--red);color:#fff;text-decoration:none;border-radius:var(--radius-sm);font-size:13px;font-weight:600;">📄 Exportar PDF</a>
                    <a href="exports/excel/export_tickets.php?<?php echo $queryStringFiltros; ?>" style="padding:8px 16px;background:var(--green);color:#fff;text-decoration:none;border-radius:var(--radius-sm);font-size:13px;font-weight:600;">📊 Exportar Excel</a>
                </div>
            </div>

            <div class="card" style="padding:0; overflow:hidden; margin-bottom:25px;">
                <div style="padding:15px 20px;border-bottom:1px solid var(--border);">
                    <h3 style="font-size:15px;color:var(--accent);">📋 Tickets Filtrados (<?php echo count($tickets_filtrados); ?>)</h3>
                </div>
                <table id="tabela-relatorio" style="width:100%;font-size:13px;">
                    <thead>
                        <tr style="background:var(--bg-sidebar);">
                            <th>Código</th><th>Assunto</th><th>Solicitante</th><th>Estado</th><th>Prioridade</th>
                            <th>Área</th><th>Técnico</th><th>Abertura</th><th>Resolução</th><th>Tempo de Resolução</th><th>SLA</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tickets_filtrados as $t):
                            $sla = estadoSlaTicket($t);
                            $slaTexto = match ($sla) {
                                'cumprido' => 'Cumprido', 'vencido' => 'Vencido', 'risco' => 'Em risco',
                                'ok' => 'No prazo', 'resolvido' => 'Resolvido', default => '—',
                            };
                            $slaCor = match ($sla) {
                                'vencido' => 'var(--red)', 'risco' => 'var(--amber)', 'cumprido' => 'var(--green)',
                                default => 'var(--text-secondary)',
                            };
                            // Tempo real só para tickets já resolvidos; caso contrário "Em curso"
                            $tempoResolucao = $t['estado'] === 'Resolvido' && isset($t['minutos_resolucao'])
                                ? formatarDuracaoMinutos((int)$t['minutos_resolucao'])
                                : 'Em curso';
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($t['codigo'] ?? $t['id']); ?></td>
                            <td><?php echo htmlspecialchars($t['titulo']); ?></td>
                            <td><?php echo htmlspecialchars($t['solicitante'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($t['estado']); ?></td>
                            <td><?php echo htmlspecialchars($t['prioridade']); ?></td>
                            <td><?php echo htmlspecialchars($t['area'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($t['tecnico'] ?? '-'); ?></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($t['data_criacao'])); ?></td>
                            <td><?php echo $t['data_resolucao'] ? date('d/m/Y H:i', strtotime($t['data_resolucao'])) : '-'; ?></td>
                            <td><?php echo htmlspecialchars($tempoResolucao); ?></td>
                            <td style="color: <?php echo $slaCor; ?>; font-weight:600;"><?php echo $slaTexto; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="page-header" style="margin-top:10px;">
                <h2 style="font-size:18px;">Resumo Estatístico</h2>
            </div>

            <?php
                // KPIs de SLA / tempo de resolução sobre os tickets filtrados
                $resolvidosComTempo = array_filter(
                    $tickets_filtrados,
                    static fn(array $t): bool => $t['estado'] === 'Resolvido' && isset($t['minutos_resolucao'])
                );
                $qtdResolvidos = count($resolvidosComTempo);
                $mediaMin = $qtdResolvidos > 0
                    ? (int) round(array_sum(array_map(static fn(array $t): int => (int)$t['minutos_resolucao'], $resolvidosComTempo)) / $qtdResolvidos)
                    : null;
                $slaCumpridos = count(array_filter($tickets_filtrados, static fn(array $t): bool => estadoSlaTicket($t) === 'cumprido'));
                $slaVencidos = count(array_filter($tickets_filtrados, static fn(array $t): bool => estadoSlaTicket($t) === 'vencido'));
                $totalFiltrado = count($tickets_filtrados);
                $taxaSla = ($slaCumpridos + $slaVencidos) > 0 ? round(($slaCumpridos / ($slaCumpridos + $slaVencidos)) * 100) : 0;
            ?>
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 25px;">
                <div class="stat-card" style="border-top: 3px solid var(--accent);">
                    <span class="label">⏱️ Tempo Médio de Resolução</span>
                    <span class="value"><?php echo $mediaMin !== null ? htmlspecialchars(formatarDuracaoMinutos($mediaMin)) : '—'; ?></span>
                </div>
                <div class="stat-card" style="border-top: 3px solid var(--green);">
                    <span class="label">✅ SLA Cumprido</span>
                    <span class="value" style="color: var(--green);"><?php echo $slaCumpridos; ?></span>
                </div>
                <div class="stat-card" style="border-top: 3px solid var(--red);">
                    <span class="label">⛔ SLA Vencido</span>
                    <span class="value" style="color: var(--red);"><?php echo $slaVencidos; ?></span>
                </div>
                <div class="stat-card" style="border-top: 3px solid var(--amber);">
                    <span class="label">📈 Taxa de Cumprimento SLA</span>
                    <span class="value"><?php echo $taxaSla; ?>%</span>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin-bottom: 25px;">
                
                <!-- TABELA 1: DISTRIBUIÇÃO POR ÁREA -->
                <div class="card" style="padding: 0; overflow: hidden;">
                    <div style="padding: 15px 20px; background: rgba(255,255,255,0.02); border-bottom: 1px solid var(--border);">
                        <h3 style="font-size: 15px; color: var(--accent);">🏢 Desempenho por Área Técnica</h3>
                    </div>
                    <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
                        <thead>
                            <tr style="background: var(--bg-sidebar); border-bottom: 1px solid var(--border); color: var(--text-secondary);">
                                <th style="padding: 12px 20px;">Departamento</th>
                                <th style="padding: 12px; text-align: center;">Total</th>
                                <th style="padding: 12px; text-align: center; color: #3b82f6;">Abertos</th>
                                <th style="padding: 12px; text-align: center; color: var(--amber);">Progresso</th>
                                <th style="padding: 12px; text-align: center; color: var(--green);">Resolvidos</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($relatorio_areas as $ra): ?>
                                <tr style="border-bottom: 1px solid var(--border);">
                                    <td style="padding: 12px 20px; font-weight: 500; color: #fff;"><?php echo htmlspecialchars($ra['area']); ?></td>
                                    <td style="padding: 12px; text-align: center; font-weight: bold;"><?php echo $ra['total']; ?></td>
                                    <td style="padding: 12px; text-align: center; color: var(--text-secondary);"><?php echo $ra['abertos']; ?></td>
                                    <td style="padding: 12px; text-align: center; color: var(--text-secondary);"><?php echo $ra['progresso']; ?></td>
                                    <td style="padding: 12px; text-align: center; color: var(--green); font-weight: 500;"><?php echo $ra['resolvidos']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- TABELA 2: VOLUMETRIA POR OPERAÇÃO CLIENTE -->
                <div class="card" style="padding: 0; overflow: hidden;">
                    <div style="padding: 15px 20px; background: rgba(255,255,255,0.02); border-bottom: 1px solid var(--border);">
                        <h3 style="font-size: 15px; color: var(--amber);">📱 Volumetria por Operação</h3>
                    </div>
                    <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
                        <thead>
                            <tr style="background: var(--bg-sidebar); border-bottom: 1px solid var(--border); color: var(--text-secondary);">
                                <th style="padding: 12px 20px;">Operação / Parceiro</th>
                                <th style="padding: 12px; text-align: center;">Tickets Gerados</th>
                                <th style="padding: 12px; text-align: center; color: var(--green);">Casos Concluídos</th>
                                <th style="padding: 12px; text-align: center;">Taxa Resolução</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($relatorio_operacoes as $ro): ?>
                                <?php 
                                    $taxa = $ro['total'] > 0 ? round(($ro['resolvidos'] / $ro['total']) * 100) : 0;
                                ?>
                                <tr style="border-bottom: 1px solid var(--border);">
                                    <td style="padding: 12px 20px; font-weight: 500; color: #fff;"><?php echo htmlspecialchars($ro['operacao']); ?></td>
                                    <td style="padding: 12px; text-align: center; font-weight: 600;"><?php echo $ro['total']; ?></td>
                                    <td style="padding: 12px; text-align: center; color: var(--green);"><?php echo $ro['resolvidos']; ?></td>
                                    <td style="padding: 12px; text-align: center;">
                                        <span style="background: rgba(16,185,129,0.1); color: var(--green); padding: 2px 6px; border-radius: 4px; font-weight: 600; font-size: 11px;"><?php echo $taxa; ?>%</span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            </div>

            <!-- TABELA 3: EFICIÊNCIA DOS TÉCNICOS -->
            <div class="card" style="padding: 0; overflow: hidden;">
                <div style="padding: 15px 20px; background: rgba(255,255,255,0.02); border-bottom: 1px solid var(--border);">
                    <h3 style="font-size: 15px; color: var(--green);">🎯 Ranking de Produtividade dos Técnicos</h3>
                </div>
                <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
                    <thead>
                        <tr style="background: var(--bg-sidebar); border-bottom: 1px solid var(--border); color: var(--text-secondary);">
                            <th style="padding: 15px 20px;">Nome do Especialista Técnico</th>
                            <th style="padding: 15px; text-align: center;">Total de Casos Atribuídos</th>
                            <th style="padding: 15px; text-align: center; color: var(--green);">Casos Resolvidos com Sucesso</th>
                            <th style="padding: 15px; text-align: center;">Performance Relativa</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($relatorio_tecnicos)): ?>
                            <tr>
                                <td colspan="4" style="padding: 20px; text-align: center; color: var(--text-muted);">Nenhum técnico registado ou com tarefas associadas no momento.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($relatorio_tecnicos as $rt): ?>
                            <?php 
                                $barra = $rt['atribuidos'] > 0 ? round(($rt['concluidos'] / $rt['atribuidos']) * 100) : 0;
                            ?>
                            <tr style="border-bottom: 1px solid var(--border);">
                                <td style="padding: 15px 20px; font-weight: 500; color: #fff;"><?php echo htmlspecialchars($rt['tecnico']); ?></td>
                                <td style="padding: 15px; text-align: center; font-weight: 600; color: var(--text-primary);"><?php echo $rt['atribuidos']; ?></td>
                                <td style="padding: 15px; text-align: center; color: var(--green); font-weight: 600;"><?php echo $rt['concluidos']; ?></td>
                                <td style="padding: 15px; width: 30%;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <div style="flex-grow: 1; height: 6px; background: var(--bg-input); border-radius: 3px; overflow: hidden;">
                                            <div style="width: <?php echo $barra; ?>%; height: 100%; background: var(--green); border-radius: 3px;"></div>
                                        </div>
                                        <span style="font-size: 11px; font-weight: 600; color: var(--text-secondary); width: 35px; text-align: right;"><?php echo $barra; ?>%</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
<script>
$(document).ready(function() {
    $('#tabela-relatorio').DataTable({
        pageLength: 25,
        order: [[7, 'desc']],
        scrollY: '55vh',
        scrollCollapse: true,
        language: { url: '//cdn.datatables.net/plug-ins/1.13.8/i18n/pt-PT.json' }
    });
});
</script>
    <script src="notificacoes.js"></script>
</body>
</html>