<?php
/**
 * KIAMI — Dashboard principal
 *
 * Exibe métricas de tickets, gráficos Chart.js (atualização AJAX a cada 60s)
 * e notificações. Painel técnico / Top Técnico: Redes, Desenvolvimento, Admin e Direção.
 */
require_once 'conexao.php';
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$nome_usuario = $_SESSION['nome'];
$perfil_usuario = $_SESSION['perfil'];
$contexto = obterContextoUsuario($pdo);

$painelTecnico = podeAcederAdministracao($contexto);
$verMetricasTecnicas = podeVerDashboardMetricasTecnicas($contexto);

[$where_clauses, $params] = obterFiltroTickets($contexto);
$where_sql = count($where_clauses) > 0 ? ' AND ' . implode(' AND ', $where_clauses) : '';

// Contadores por estado (respeitam filtro de visibilidade do perfil)
function contarEstado(PDO $pdo, string $estado, string $where_sql, array $params): int
{
    $sql = "SELECT COUNT(*) FROM tickets t WHERE t.estado = ?" . $where_sql;
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$estado], $params));
    return (int)$stmt->fetchColumn();
}

$total_ativos = contarEstado($pdo, 'Aberto', $where_sql, $params);
$total_progresso = contarEstado($pdo, 'Em Progresso', $where_sql, $params);
$total_resolvidos = contarEstado($pdo, 'Resolvido', $where_sql, $params);

// SLA em risco e vencido
$sql_sla = "SELECT t.* FROM tickets t WHERE t.estado <> 'Resolvido'" . $where_sql;
$stmt_sla = $pdo->prepare($sql_sla);
$stmt_sla->execute($params);
$tickets_ativos = $stmt_sla->fetchAll(PDO::FETCH_ASSOC);

$sla_risco = 0;
$sla_vencido = 0;
foreach ($tickets_ativos as $t) {
    $est = estadoSlaTicket($t);
    if ($est === 'risco') $sla_risco++;
    if ($est === 'vencido') $sla_vencido++;
}

// Notificações não lidas
$notificacoes = obterNotificacoesUtilizador($pdo, $contexto, 10, true);

?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KIAMI - Painel</title>
    <link rel="stylesheet" href="style.css">
    <script src="tema.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script></head>
<body class="app-body">
    <div class="app-layout">
        <div id="sidebar">
            <div class="sidebar-brand">
                <h3>KIAMI</h3>
                <span>Suporte Quality</span>
            </div>
            <div class="nav-section-title">Geral</div>
            <a href="index.php" class="nav-item active">📊 <span>Painel</span></a>
            <a href="tickets_lista.php" class="nav-item">🎫 <span>Tickets</span></a>
            <a href="kb_lista.php" class="nav-item">📚 <span>Base Conhecimento</span></a>
            <a href="formacao.php" class="nav-item">🎓 <span>Autoaprendizagem</span></a>
            <a href="empresa.php" class="nav-item">🏢 <span>A Empresa</span></a>
            <?php echo htmlNavNotificacoes((int)($notif_pendentes ?? 0)); ?>

            <?php if ($painelTecnico): ?>
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
                    <div style="font-size: 11px; color: var(--text-muted); margin-bottom: 2px;">Conectado como:</div>
                    <div style="font-size: 14px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                        <?php echo htmlspecialchars($nome_usuario); ?>
                    </div>
                    <div style="font-size: 11px; color: var(--accent); font-weight: 600; margin-top: 4px;">
                        ⚙️ <?php echo htmlspecialchars($perfil_usuario); ?>
                    </div>
                </div>
<?php if (idUtilizadorNumerico()): ?>
                <a href="alterar_senha.php" style="display:block; text-align:center; margin-bottom:8px; padding:9px; background:var(--bg-input); color:var(--text-primary); text-decoration:none; border-radius:var(--radius-sm); font-size:13px; border:1px solid var(--border);">🔑 Alterar Palavra-passe</a>
                <?php endif; ?>
                <a href="logout.php" class="btn-danger">🚪 Sair do Sistema</a>
            </div>
        </div>

        <div id="main-content">
            <div class="page-header">
                <h1>Painel Operacional</h1>
                <?php if (isset($_GET['msg']) && $_GET['msg'] === 'senha_atualizada'): ?>
                    <div class="auth-alert auth-alert-sucesso" style="margin-top:12px;">Palavra-passe atualizada com sucesso. Bem-vindo ao sistema!</div>
                <?php endif; ?>
                <p>
                    <?php if ($verMetricasTecnicas): ?>
                        Painel com métricas, SLA e Top Técnico da equipa.
                    <?php else: ?>
                        Resumo dos seus pedidos de suporte.
                    <?php endif; ?>
                </p>
            </div>

            <div class="stats-grid" id="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));">
                <div class="stat-card" style="border-top: 3px solid var(--accent);">
                    <span class="label">📂 Abertos</span>
                    <span class="value" id="stat-abertos"><?php echo $total_ativos; ?></span>
                </div>
                <div class="stat-card" style="border-top: 3px solid var(--amber);">
                    <span class="label">⏳ Em Progresso</span>
                    <span class="value" id="stat-progresso"><?php echo $total_progresso; ?></span>
                </div>
                <div class="stat-card" style="border-top: 3px solid var(--green);">
                    <span class="label">✅ Resolvidos</span>
                    <span class="value" id="stat-resolvidos"><?php echo $total_resolvidos; ?></span>
                </div>
                <?php if ($verMetricasTecnicas): ?>
                <div class="stat-card" style="border-top: 3px solid var(--amber);">
                    <span class="label">SLA em Risco</span>
                    <span class="value" id="stat-sla-risco" style="color: var(--amber);"><?php echo $sla_risco; ?></span>
                </div>
                <div class="stat-card" style="border-top: 3px solid var(--red);">
                    <span class="label">SLA Vencido</span>
                    <span class="value" id="stat-sla-vencido" style="color: var(--red);"><?php echo $sla_vencido; ?></span>
                </div>
                <div class="stat-card" style="border-top: 3px solid var(--accent);">
                    <span class="label">Tempo Médio (h)</span>
                    <span class="value" id="stat-tempo-medio">—</span>
                </div>
                <?php endif; ?>
            </div>

            <p id="ultima-atualizacao" style="font-size: 12px; color: var(--text-muted); margin-top: 10px;"></p>

            <?php if ($verMetricasTecnicas): ?>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin-top: 25px;">
                <div class="card"><h3 style="font-size: 14px; color: var(--text-secondary); margin-bottom: 15px;">Tickets por Estado</h3><canvas id="chartEstado" height="200"></canvas></div>
                <div class="card"><h3 style="font-size: 14px; color: var(--text-secondary); margin-bottom: 15px;">Tickets por Prioridade</h3><canvas id="chartPrioridade" height="200"></canvas></div>
                <div class="card"><h3 style="font-size: 14px; color: var(--text-secondary); margin-bottom: 15px;">Tickets por Área</h3><canvas id="chartArea" height="200"></canvas></div>
                <div class="card"><h3 style="font-size: 14px; color: var(--text-secondary); margin-bottom: 15px;">Tickets por Técnico</h3><canvas id="chartTecnico" height="200"></canvas></div>
            </div>
            <div class="card" style="margin-top: 25px;">
                <h3 style="font-size: 15px; color: var(--accent); margin-bottom: 15px;">🏆 Top Técnico</h3>
                <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 12px;">Ranking de produtividade (tickets resolvidos / atribuídos).</p>
                <div id="top-tecnico-lista" style="display:flex; flex-direction:column; gap:10px;">
                    <div style="color:var(--text-muted); font-size:13px;">A carregar…</div>
                </div>
            </div>
            <?php else: ?>
            <div class="card" style="margin-top: 25px;">
                <h3 style="font-size: 14px; color: var(--text-secondary); margin-bottom: 15px;">Os seus tickets por estado</h3>
                <canvas id="chartEstado" height="120"></canvas>
            </div>
            <?php endif; ?>

            <div id="notificacoes-box" style="margin-top: 25px; display: none;">
                <div class="card">
                    <h2 style="color: var(--accent); margin-bottom: 12px; font-size: 16px;">🔔 Notificações Recentes</h2>
                    <div id="notificacoes-lista"></div>
                </div>
            </div>

            <?php if ($verMetricasTecnicas && !empty($notificacoes)): ?>
            <div class="card" style="margin-top: 25px;" id="notificacoes-inicial">
                <h2 style="color: var(--accent); margin-bottom: 12px; font-size: 16px;">🔔 Notificações Recentes</h2>
                <?php foreach ($notificacoes as $n): ?>
                    <div style="padding: 10px 0; border-bottom: 1px solid var(--border); font-size: 13px; color: var(--text-secondary);">
                        <span style="color: var(--accent); font-weight: 600;"><?php echo htmlspecialchars($n['tipo']); ?></span>
                        — <?php echo htmlspecialchars($n['mensagem']); ?>
                        <span style="color: var(--text-muted); font-size: 11px; float: right;"><?php echo date('d/m H:i', strtotime($n['data_criacao'])); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="card" style="margin-top: 25px;">
                <h2 style="color: var(--accent); margin-bottom: 12px; font-size: 18px;">Visibilidade do seu perfil</h2>
                <p style="color: var(--text-secondary); line-height: 1.6; font-size: 14px;">
                    Como <b><?php echo htmlspecialchars($perfil_usuario); ?></b>, vê os tickets da sua alçada.
                    <?php if ($painelTecnico): ?>
                        Pertence a uma área técnica (Redes & Sistemas / Desenvolvimento), pelo que tem acesso ao painel completo, à gestão de utilizadores e aos relatórios.
                    <?php elseif ($verMetricasTecnicas): ?>
                        Tem acesso às métricas técnicas e ao Top Técnico no painel (Admin / Direção).
                    <?php else: ?>
                        Este painel mostra apenas os seus pedidos. A gestão de utilizadores é exclusiva das áreas de Redes & Sistemas e Desenvolvimento.
                    <?php endif; ?>
                </p>
                <p style="margin-top: 12px;">
                    <a href="tickets_lista.php" style="color: var(--accent); font-weight: 600;">Ver tickets →</a>
                    <?php if ($perfil_usuario === 'Admin'): ?>
                        &nbsp;·&nbsp; <a href="atualizar_banco.php" style="color: var(--amber);">Atualizar base de dados</a>
                        &nbsp;·&nbsp; <a href="testar_email.php" style="color: var(--amber);">Testar email SMTP</a>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>
<script>
// --- Gráficos e atualização automática via api/dashboard_data.php ---
const painelTecnico = <?php echo $verMetricasTecnicas ? 'true' : 'false'; ?>;
const charts = {};
const cores = ['#3b82f6','#f59e0b','#10b981','#ef4444','#8b5cf6','#06b6d4','#ec4899','#64748b'];

function criarChart(id, tipo, labels, dados) {
    const ctx = document.getElementById(id);
    if (!ctx) return;
    if (charts[id]) charts[id].destroy();
    charts[id] = new Chart(ctx, {
        type: tipo,
        data: {
            labels: labels,
            datasets: [{ data: dados, backgroundColor: cores.slice(0, labels.length), borderWidth: 0 }]
        },
        options: {
            responsive: true,
            plugins: { legend: { labels: { color: '#94a3b8' } } },
            scales: tipo === 'bar' ? { y: { beginAtZero: true, ticks: { color: '#94a3b8' } }, x: { ticks: { color: '#94a3b8' } } } : {}
        }
    });
}

function renderTopTecnico(lista) {
    const box = document.getElementById('top-tecnico-lista');
    if (!box) return;
    const rows = (lista || []).filter(x => x.label && x.label !== 'Não atribuído').slice(0, 8);
    if (!rows.length) {
        box.innerHTML = '<div style="color:var(--text-muted);font-size:13px;">Sem dados de técnicos atribuídos.</div>';
        return;
    }
    const max = Math.max(...rows.map(r => Number(r.concluidos || r.total || 0)), 1);
    box.innerHTML = rows.map((r, i) => {
        const concluidos = Number(r.concluidos != null ? r.concluidos : r.total || 0);
        const atribuidos = Number(r.atribuidos != null ? r.atribuidos : r.total || 0);
        const pct = Math.round((concluidos / max) * 100);
        const medal = i === 0 ? '🥇' : (i === 1 ? '🥈' : (i === 2 ? '🥉' : (i + 1) + '.'));
        return `<div>
            <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px;">
                <span style="color:#fff;font-weight:600;">${medal} ${r.label}</span>
                <span style="color:var(--text-secondary);">${concluidos} resolvidos · ${atribuidos} atribuídos</span>
            </div>
            <div style="height:8px;background:var(--bg-input);border-radius:6px;overflow:hidden;">
                <div style="height:100%;width:${pct}%;background:linear-gradient(90deg,var(--accent),#60a5fa);"></div>
            </div>
        </div>`;
    }).join('');
}

function atualizarDashboard() {
    fetch('api/dashboard_data.php')
        .then(r => r.json())
        .then(d => {
            if (d.erro) return;
            const s = d.stats;
            document.getElementById('stat-abertos').textContent = s.abertos;
            document.getElementById('stat-progresso').textContent = s.progresso;
            document.getElementById('stat-resolvidos').textContent = s.resolvidos;
            if (painelTecnico) {
                const risco = document.getElementById('stat-sla-risco');
                const venc = document.getElementById('stat-sla-vencido');
                if (risco) risco.textContent = s.sla_risco;
                if (venc) venc.textContent = s.sla_vencido;
                const el = document.getElementById('stat-tempo-medio');
                if (el) el.textContent = s.tempo_medio_horas;
                criarChart('chartArea', 'bar', d.por_area.map(x => x.label), d.por_area.map(x => x.total));
                criarChart('chartPrioridade', 'doughnut', d.por_prioridade.map(x => x.label), d.por_prioridade.map(x => x.total));
                criarChart('chartTecnico', 'bar', d.por_tecnico.map(x => x.label), d.por_tecnico.map(x => x.total));
                renderTopTecnico(d.top_tecnico || d.por_tecnico || []);
            }
            criarChart('chartEstado', painelTecnico ? 'doughnut' : 'bar', d.por_estado.map(x => x.label), d.por_estado.map(x => x.total));
            document.getElementById('ultima-atualizacao').textContent = 'Atualizado: ' + d.atualizado;

            if (d.notificacoes && d.notificacoes.length > 0) {
                const box = document.getElementById('notificacoes-box');
                const lista = document.getElementById('notificacoes-lista');
                const ini = document.getElementById('notificacoes-inicial');
                if (ini) ini.style.display = 'none';
                box.style.display = 'block';
                lista.innerHTML = d.notificacoes.map(n =>
                    `<div style="padding:10px 0;border-bottom:1px solid var(--border);font-size:13px;color:var(--text-secondary)">
                        <span style="color:var(--accent);font-weight:600">${n.tipo}</span> — ${n.mensagem}
                        <span style="color:var(--text-muted);font-size:11px;float:right">${n.data_criacao}</span>
                    </div>`
                ).join('');
            }
        })
        .catch(() => {});
}

atualizarDashboard();
setInterval(atualizarDashboard, 60000);
</script>    <script src="notificacoes.js"></script>
</body>
</html>
