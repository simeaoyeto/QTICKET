<?php
/**
 * KIAMI — Gestão de assuntos/categorias de tickets
 *
 * Permite ao Admin e à equipa técnica (Redes & Sistemas / Desenvolvimento)
 * adicionar, editar, activar/desactivar e remover opções da lista "Assunto"
 * nos formulários de abertura e edição de tickets.
 */
require_once 'conexao.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$nome_usuario = $_SESSION['nome'];
$perfil_usuario = $_SESSION['perfil'];
$contexto = obterContextoUsuario($pdo);
$mensagem = '';
$assuntoEditar = null;

if (!podeGerirAssuntosTicket($contexto)) {
    http_response_code(403);
    registarAuditoria($pdo, 'Acesso Negado', 'Tentativa de aceder à gestão de assuntos de ticket');
    die('Acesso negado. A gestão de assuntos está reservada às áreas de Redes & Sistemas e Desenvolvimento.');
}

// Activar / desactivar / remover (GET)
if (isset($_GET['acao'], $_GET['id'])) {
    $idAlvo = (int)$_GET['id'];
    $acao = $_GET['acao'];

    if ($acao === 'desativar') {
        $pdo->prepare('UPDATE ticket_assuntos SET ativo = 0 WHERE id = ?')->execute([$idAlvo]);
        registarAuditoria($pdo, 'Assunto Ticket', "Assunto #$idAlvo desactivado");
        $mensagem = "<div style='background: rgba(245,158,11,0.2); color: var(--amber); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Assunto desactivado. Deixa de aparecer na lista de abertura.</div>";
    } elseif ($acao === 'ativar') {
        $pdo->prepare('UPDATE ticket_assuntos SET ativo = 1 WHERE id = ?')->execute([$idAlvo]);
        registarAuditoria($pdo, 'Assunto Ticket', "Assunto #$idAlvo activado");
        $mensagem = "<div style='background: rgba(34,197,94,0.2); color: var(--green); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Assunto activado com sucesso!</div>";
    } elseif ($acao === 'remover') {
        $stmtTit = $pdo->prepare('SELECT titulo FROM ticket_assuntos WHERE id = ?');
        $stmtTit->execute([$idAlvo]);
        $tituloRem = $stmtTit->fetchColumn();
        if ($tituloRem) {
            $pdo->prepare('DELETE FROM ticket_assuntos WHERE id = ?')->execute([$idAlvo]);
            registarAuditoria($pdo, 'Assunto Ticket', "Assunto removido: $tituloRem");
            $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Assunto removido permanentemente.</div>";
        }
    }
}

// Carregar para edição
if (isset($_GET['acao']) && $_GET['acao'] === 'editar' && isset($_GET['id'])) {
    $stmtEd = $pdo->prepare('SELECT * FROM ticket_assuntos WHERE id = ?');
    $stmtEd->execute([(int)$_GET['id']]);
    $assuntoEditar = $stmtEd->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Adicionar novo assunto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_adicionar_assunto'])) {
    $titulo = trim($_POST['titulo'] ?? '');
    $ordem = (int)($_POST['ordem'] ?? 0);

    if ($titulo === '') {
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: o título do assunto é obrigatório.</div>";
    } else {
        if ($ordem <= 0) {
            $ordem = (int)$pdo->query('SELECT COALESCE(MAX(ordem), 0) + 10 FROM ticket_assuntos')->fetchColumn();
        }
        try {
            $pdo->prepare('INSERT INTO ticket_assuntos (titulo, ordem, ativo) VALUES (?, ?, 1)')->execute([$titulo, $ordem]);
            registarAuditoria($pdo, 'Assunto Ticket', "Novo assunto criado: $titulo");
            $mensagem = "<div style='background: rgba(34,197,94,0.2); color: var(--green); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Assunto adicionado com sucesso!</div>";
        } catch (PDOException $e) {
            $msg = ($e->getCode() === '23000') ? 'Já existe um assunto com este título.' : $e->getMessage();
            $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: " . htmlspecialchars($msg) . "</div>";
        }
    }
}

// Guardar edição
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_salvar_assunto'])) {
    $idEdit = (int)($_POST['id_assunto'] ?? 0);
    $titulo = trim($_POST['titulo'] ?? '');
    $ordem = (int)($_POST['ordem'] ?? 0);

    if ($idEdit <= 0 || $titulo === '') {
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: dados inválidos para edição.</div>";
    } else {
        if ($ordem <= 0) {
            $ordem = 10;
        }
        try {
            $pdo->prepare('UPDATE ticket_assuntos SET titulo = ?, ordem = ? WHERE id = ?')->execute([$titulo, $ordem, $idEdit]);
            registarAuditoria($pdo, 'Assunto Ticket', "Assunto #$idEdit actualizado: $titulo");
            $mensagem = "<div style='background: rgba(34,197,94,0.2); color: var(--green); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Assunto actualizado com sucesso!</div>";
            $assuntoEditar = null;
        } catch (PDOException $e) {
            $msg = ($e->getCode() === '23000') ? 'Já existe outro assunto com este título.' : $e->getMessage();
            $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: " . htmlspecialchars($msg) . "</div>";
        }
    }
}

$listaAssuntos = $pdo->query('SELECT * FROM ticket_assuntos ORDER BY ordem ASC, titulo ASC')->fetchAll(PDO::FETCH_ASSOC);
$emEdicao = $assuntoEditar !== null;
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KIAMI - Assuntos de Ticket</title>
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
            <a href="usuarios_lista.php" class="nav-item">👥 <span>Gestão de Utilizadores</span></a>
            <a href="equipa_online.php" class="nav-item">🟢 <span>Equipa Disponível</span></a>
            <a href="assuntos_lista.php" class="nav-item active">📋 <span>Assuntos de Ticket</span></a>
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
                <h1>Assuntos de Ticket</h1>
                <p>Gira as opções da lista <strong>Assunto</strong> nos formulários de abertura e edição de tickets. A opção «Outros (especificar)» permanece sempre disponível para casos não listados.</p>
            </div>

            <?php echo $mensagem; ?>

            <div class="card" style="margin-bottom: 30px;" id="form-assunto">
                <h3 style="margin-bottom: 15px; font-size: 16px; color: var(--accent);">
                    <?php echo $emEdicao ? '✏️ Editar Assunto' : '➕ Adicionar Assunto'; ?>
                </h3>
                <form action="assuntos_lista.php" method="POST" style="display: grid; grid-template-columns: 2fr 120px auto; gap: 15px; align-items: end;">
                    <?php if ($emEdicao): ?>
                        <input type="hidden" name="id_assunto" value="<?php echo (int)$assuntoEditar['id']; ?>">
                    <?php endif; ?>
                    <div>
                        <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Título do assunto</label>
                        <input type="text" name="titulo" required maxlength="150" value="<?php echo $emEdicao ? htmlspecialchars($assuntoEditar['titulo']) : ''; ?>" placeholder="Ex: VPN / Acesso remoto" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:var(--text-primary); border-radius:var(--radius-sm);">
                    </div>
                    <div>
                        <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Ordem</label>
                        <input type="number" name="ordem" min="1" value="<?php echo $emEdicao ? (int)$assuntoEditar['ordem'] : ''; ?>" placeholder="Auto" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:var(--text-primary); border-radius:var(--radius-sm);">
                    </div>
                    <div style="display:flex; flex-direction:column; gap:8px;">
                        <button type="submit" name="<?php echo $emEdicao ? 'btn_salvar_assunto' : 'btn_adicionar_assunto'; ?>" style="padding: 10px 18px; background: var(--accent); border:none; color:#fff; border-radius:var(--radius-sm); cursor:pointer; font-weight:600;">
                            <?php echo $emEdicao ? 'Guardar' : 'Adicionar'; ?>
                        </button>
                        <?php if ($emEdicao): ?>
                        <a href="assuntos_lista.php" style="text-align:center; font-size:12px; color:var(--text-muted);">Cancelar</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="card">
                <h3 style="margin-bottom: 15px; font-size: 16px;">📋 Lista de assuntos (<?php echo count($listaAssuntos); ?>)</h3>
                <div style="overflow-x: auto;">
                    <table style="width:100%; border-collapse: collapse; font-size: 14px;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--border); text-align: left;">
                                <th style="padding: 10px; color: var(--text-secondary);">Ordem</th>
                                <th style="padding: 10px; color: var(--text-secondary);">Assunto</th>
                                <th style="padding: 10px; color: var(--text-secondary);">Estado</th>
                                <th style="padding: 10px; color: var(--text-secondary);">Acções</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($listaAssuntos)): ?>
                            <tr>
                                <td colspan="4" style="padding: 20px; color: var(--text-muted); text-align: center;">
                                    Nenhum assunto registado. Execute <a href="atualizar_banco.php" style="color:var(--accent);">atualizar_banco.php</a> ou adicione o primeiro acima.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($listaAssuntos as $ass): ?>
                            <tr style="border-bottom: 1px solid var(--border);">
                                <td style="padding: 10px; color: var(--text-muted);"><?php echo (int)$ass['ordem']; ?></td>
                                <td style="padding: 10px;"><?php echo htmlspecialchars($ass['titulo']); ?></td>
                                <td style="padding: 10px;">
                                    <?php if ((int)$ass['ativo'] === 1): ?>
                                        <span style="color: var(--green); font-weight: 600;">Activo</span>
                                    <?php else: ?>
                                        <span style="color: var(--amber); font-weight: 600;">Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 10px; white-space: nowrap;">
                                    <a href="assuntos_lista.php?acao=editar&id=<?php echo (int)$ass['id']; ?>#form-assunto" style="color: var(--accent); text-decoration: none; margin-right: 12px; font-weight: 600; font-size: 13px;">✏️ Editar</a>
                                    <?php if ((int)$ass['ativo'] === 1): ?>
                                        <a href="assuntos_lista.php?acao=desativar&id=<?php echo (int)$ass['id']; ?>" style="color: var(--amber); text-decoration: none; margin-right: 12px; font-weight: 600; font-size: 13px;" onclick="return confirm('Desactivar este assunto? Deixa de aparecer na lista de abertura.');">Desactivar</a>
                                    <?php else: ?>
                                        <a href="assuntos_lista.php?acao=ativar&id=<?php echo (int)$ass['id']; ?>" style="color: var(--green); text-decoration: none; margin-right: 12px; font-weight: 600; font-size: 13px;">Activar</a>
                                    <?php endif; ?>
                                    <a href="assuntos_lista.php?acao=remover&id=<?php echo (int)$ass['id']; ?>" style="color: var(--red); text-decoration: none; font-weight: 600; font-size: 13px;" onclick="return confirm('Remover permanentemente este assunto?');">Remover</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <script src="notificacoes.js"></script>
</body>
</html>
