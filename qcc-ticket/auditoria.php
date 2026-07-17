<?php
/**
 * KIAMI — Registo de auditoria do sistema
 *
 * Consulta global de todas as acções registadas (logins, tickets, utilizadores, KB, etc.).
 * Acesso: Admin + Responsáveis das áreas Redes & Sistemas (Helpdesk) e Desenvolvimento.
 */
require_once 'conexao.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$nome_usuario = $_SESSION['nome'];
$perfil_usuario = $_SESSION['perfil'];
$contexto = obterContextoUsuario($pdo);

if (!podeAcederAuditoria($contexto)) {
    http_response_code(403);
    registarAuditoria($pdo, 'Acesso Negado', 'Tentativa de aceder à auditoria do sistema');
    die('Acesso negado. A auditoria está disponível para o Administrador e para os Responsáveis das áreas de Redes & Sistemas e Desenvolvimento.');
}

// Filtros opcionais
$filtro_acao = trim($_GET['acao'] ?? '');
$filtro_data_inicio = trim($_GET['data_inicio'] ?? '');
$filtro_data_fim = trim($_GET['data_fim'] ?? '');
$filtro_pesquisa = trim($_GET['pesquisa'] ?? '');

$where = [];
$params = [];

if ($filtro_acao !== '') {
    $where[] = 'a.acao = ?';
    $params[] = $filtro_acao;
}
if ($filtro_data_inicio !== '') {
    $where[] = 'DATE(a.data_registo) >= ?';
    $params[] = $filtro_data_inicio;
}
if ($filtro_data_fim !== '') {
    $where[] = 'DATE(a.data_registo) <= ?';
    $params[] = $filtro_data_fim;
}
if ($filtro_pesquisa !== '') {
    $where[] = '(a.detalhes LIKE ? OR u.nome LIKE ? OR u.username LIKE ?)';
    $termo = '%' . $filtro_pesquisa . '%';
    $params[] = $termo;
    $params[] = $termo;
    $params[] = $termo;
}

$where_sql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
    SELECT a.id, a.acao, a.detalhes, a.data_registo,
           u.nome AS nome_utilizador, u.username, u.perfil
    FROM auditoria a
    LEFT JOIN utilizadores u ON a.id_utilizador = u.id
    $where_sql
    ORDER BY a.id DESC
    LIMIT 2000
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$registos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Lista de tipos de acção para o filtro
$tipos_acao = $pdo->query("SELECT DISTINCT acao FROM auditoria ORDER BY acao ASC")->fetchAll(PDO::FETCH_COLUMN);

$painelTecnico = podeAcederAdministracao($contexto);
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KIAMI - Auditoria do Sistema</title>
    <link rel="stylesheet" href="style.css">
    <script src="tema.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script></head>
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
            <?php echo htmlNavNotificacoes((int)($notif_pendentes ?? 0)); ?>

            <?php if ($painelTecnico): ?>
            <div class="nav-section-title">Administração</div>
            <?php if (podeGerirUtilizadores($contexto)): ?>
            <a href="perfis_lista.php" class="nav-item">🪪 <span>Gestão de Perfis</span></a>
            <a href="usuarios_lista.php" class="nav-item nav-sub">👥 <span>Utilizadores</span></a>
            <a href="equipa_online.php" class="nav-item">🟢 <span>Equipa Disponível</span></a>
            <a href="assuntos_lista.php" class="nav-item">📋 <span>Assuntos de Ticket</span></a>
                <a href="emails_areas.php" class="nav-item">✉️ <span>Emails das Áreas</span></a>
            <?php endif; ?>
            <a href="relatorios.php" class="nav-item">📈 <span>Relatórios</span></a>
            <?php if (podeAcederAuditoria($contexto)): ?>
            <a href="auditoria.php" class="nav-item active">🔍 <span>Auditoria</span></a>
            <?php endif; ?>
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
                <h1>Auditoria do Sistema</h1>
                <p>Registo completo de todas as acções realizadas no KIAMI — logins, tickets, utilizadores, base de conhecimento e alterações de configuração.</p>
            </div>

            <div class="card" style="margin-bottom: 25px;">
                <h3 style="color: var(--accent); margin-bottom: 15px; font-size: 15px;">🔍 Filtros</h3>
                <form method="GET" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; align-items: end;">
                    <div>
                        <label style="font-size: 11px; color: var(--text-muted);">Tipo de acção</label>
                        <select name="acao" style="width:100%;padding:8px;background:var(--bg-input);border:1px solid var(--border);color:#fff;border-radius:var(--radius-sm);">
                            <option value="">Todas</option>
                            <?php foreach ($tipos_acao as $tipo): ?>
                            <option value="<?php echo htmlspecialchars($tipo); ?>" <?php echo $filtro_acao === $tipo ? 'selected' : ''; ?>><?php echo htmlspecialchars($tipo); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="font-size: 11px; color: var(--text-muted);">Data início</label>
                        <input type="date" name="data_inicio" value="<?php echo htmlspecialchars($filtro_data_inicio); ?>" style="width:100%;padding:8px;background:var(--bg-input);border:1px solid var(--border);color:#fff;border-radius:var(--radius-sm);">
                    </div>
                    <div>
                        <label style="font-size: 11px; color: var(--text-muted);">Data fim</label>
                        <input type="date" name="data_fim" value="<?php echo htmlspecialchars($filtro_data_fim); ?>" style="width:100%;padding:8px;background:var(--bg-input);border:1px solid var(--border);color:#fff;border-radius:var(--radius-sm);">
                    </div>
                    <div>
                        <label style="font-size: 11px; color: var(--text-muted);">Pesquisar</label>
                        <input type="text" name="pesquisa" value="<?php echo htmlspecialchars($filtro_pesquisa); ?>" placeholder="Utilizador ou detalhes..." style="width:100%;padding:8px;background:var(--bg-input);border:1px solid var(--border);color:#fff;border-radius:var(--radius-sm);">
                    </div>
                    <button type="submit" style="padding:9px 16px;background:var(--accent);border:none;color:#fff;border-radius:var(--radius-sm);font-weight:600;cursor:pointer;">Filtrar</button>
                    <a href="auditoria.php" style="padding:9px 16px;background:var(--bg-sidebar);border:1px solid var(--border);color:var(--text-secondary);text-decoration:none;border-radius:var(--radius-sm);font-weight:600;text-align:center;">Limpar</a>
                </form>
            </div>

            <div class="card" style="padding:0; overflow:hidden;">
                <div style="padding:15px 20px;border-bottom:1px solid var(--border);">
                    <h3 style="font-size:15px;color:var(--accent);">📋 Registo de Auditoria (<?php echo count($registos); ?> registos)</h3>
                </div>
                <table id="tabela-auditoria" style="width:100%;font-size:13px;">
                    <thead>
                        <tr style="background:var(--bg-sidebar);">
                            <th style="padding:12px 15px;">Data/Hora</th>
                            <th style="padding:12px 15px;">Utilizador</th>
                            <th style="padding:12px 15px;">Perfil</th>
                            <th style="padding:12px 15px;">Acção</th>
                            <th style="padding:12px 15px;">Detalhes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($registos as $r): ?>
                        <tr style="border-bottom:1px solid var(--border);">
                            <td style="padding:12px 15px; white-space:nowrap; color:var(--text-muted);"><?php echo date('d/m/Y H:i:s', strtotime($r['data_registo'])); ?></td>
                            <td style="padding:12px 15px; color:#fff; font-weight:500;">
                                <?php echo $r['nome_utilizador'] ? htmlspecialchars($r['nome_utilizador']) : '<span style="color:var(--text-muted);">Sistema</span>'; ?>
                                <?php if ($r['username']): ?>
                                <div style="font-size:11px;color:var(--text-muted);"><?php echo htmlspecialchars($r['username']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="padding:12px 15px; color:var(--text-secondary);"><?php echo htmlspecialchars($r['perfil'] ?? '—'); ?></td>
                            <td style="padding:12px 15px;">
                                <?php
                                $corAcao = match ($r['acao']) {
                                    'Login' => 'var(--green)',
                                    'Logout' => 'var(--text-muted)',
                                    'Criação' => 'var(--accent)',
                                    'Alteração', 'Alteração Senha' => 'var(--amber)',
                                    'Exclusão', 'Acesso Negado' => 'var(--red)',
                                    default => 'var(--text-secondary)',
                                };
                                ?>
                                <span style="color:<?php echo $corAcao; ?>; font-weight:600; font-size:12px;"><?php echo htmlspecialchars($r['acao']); ?></span>
                            </td>
                            <td style="padding:12px 15px; color:var(--text-secondary); max-width:400px;"><?php echo htmlspecialchars($r['detalhes']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<script>
$(document).ready(function() {
    $('#tabela-auditoria').DataTable({
        pageLength: 25,
        order: [],
        scrollY: '55vh',
        scrollCollapse: true,
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.8/i18n/pt-PT.json',
            emptyTable: 'Nenhum registo de auditoria encontrado.'
        }
    });
});
</script>
    <script src="notificacoes.js"></script>
</body>
</html>
