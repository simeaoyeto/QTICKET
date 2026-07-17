<?php
/**
 * KIAMI — Gestão de assuntos/categorias de tickets (hierarquia)
 *
 * Estrutura: Categoria (id_pai NULL) → Detalhe (id_pai = categoria).
 * Nos formulários o utilizador escolhe categoria e depois o detalhe.
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

// Garantir colunas SLA (caso migração ainda não tenha corrido)
try {
    $pdo->exec('ALTER TABLE ticket_assuntos ADD COLUMN IF NOT EXISTS id_pai INT NULL DEFAULT NULL AFTER titulo');
    $pdo->exec('ALTER TABLE ticket_assuntos ADD COLUMN IF NOT EXISTS sla_resposta_min INT NULL');
    $pdo->exec('ALTER TABLE ticket_assuntos ADD COLUMN IF NOT EXISTS sla_resolucao_h DECIMAL(6,2) NULL');
    $pdo->exec('ALTER TABLE ticket_assuntos ADD COLUMN IF NOT EXISTS urgencia_base VARCHAR(20) NULL');
    $pdo->exec('ALTER TABLE ticket_assuntos ADD COLUMN IF NOT EXISTS prioridade_base VARCHAR(20) NULL');
    $pdo->exec('ALTER TABLE ticket_assuntos ADD COLUMN IF NOT EXISTS classificacao_itil VARCHAR(60) NULL');
} catch (PDOException $e) {
    // ignore
}

/**
 * Lê metadados SLA do POST e calcula urgência automática se necessário.
 *
 * @return array{sla_resposta_min:?int,sla_resolucao_h:?float,urgencia_base:?string,prioridade_base:?string,classificacao_itil:?string}
 */
function lerMetaSlaAssuntoPost(): array
{
    $resp = isset($_POST['sla_resposta_min']) && $_POST['sla_resposta_min'] !== '' ? (int)$_POST['sla_resposta_min'] : null;
    $resol = isset($_POST['sla_resolucao_h']) && $_POST['sla_resolucao_h'] !== '' ? (float)$_POST['sla_resolucao_h'] : null;
    $urgencia = trim($_POST['urgencia_base'] ?? '');
    $prio = trim($_POST['prioridade_base'] ?? '');
    $itil = trim($_POST['classificacao_itil'] ?? '');

    if ($urgencia === '' || $urgencia === 'Automatica' || $urgencia === 'Automática') {
        if ($resol !== null) {
            $urgencia = catalogoUrgenciaPorSlaResolucao($resol);
        } elseif ($prio !== '') {
            $urgencia = catalogoNormalizarNivel($prio);
        } else {
            $urgencia = null;
        }
    } else {
        $urgencia = catalogoNormalizarNivel($urgencia);
    }

    if ($prio === '' && $urgencia) {
        $prio = $urgencia;
    } elseif ($prio !== '') {
        $prio = catalogoNormalizarNivel($prio);
    } else {
        $prio = null;
    }

    return [
        'sla_resposta_min' => $resp,
        'sla_resolucao_h' => $resol,
        'urgencia_base' => $urgencia,
        'prioridade_base' => $prio,
        'classificacao_itil' => $itil !== '' ? $itil : null,
    ];
}

// Activar / desactivar / remover (GET)
if (isset($_GET['acao'], $_GET['id'])) {
    $idAlvo = (int)$_GET['id'];
    $acao = $_GET['acao'];

    if ($acao === 'desativar') {
        $pdo->prepare('UPDATE ticket_assuntos SET ativo = 0 WHERE id = ? OR id_pai = ?')->execute([$idAlvo, $idAlvo]);
        registarAuditoria($pdo, 'Assunto Ticket', "Assunto #$idAlvo desactivado");
        $mensagem = "<div style='background: rgba(245,158,11,0.2); color: var(--amber); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Item desactivado (e detalhes associados, se for categoria).</div>";
    } elseif ($acao === 'ativar') {
        $pdo->prepare('UPDATE ticket_assuntos SET ativo = 1 WHERE id = ?')->execute([$idAlvo]);
        registarAuditoria($pdo, 'Assunto Ticket', "Assunto #$idAlvo activado");
        $mensagem = "<div style='background: rgba(34,197,94,0.2); color: var(--green); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Item activado com sucesso!</div>";
    } elseif ($acao === 'remover') {
        $stmtTit = $pdo->prepare('SELECT titulo, id_pai FROM ticket_assuntos WHERE id = ?');
        $stmtTit->execute([$idAlvo]);
        $rowRem = $stmtTit->fetch(PDO::FETCH_ASSOC);
        if ($rowRem) {
            $stN = $pdo->prepare('SELECT COUNT(*) FROM ticket_assuntos WHERE id_pai = ?');
            $stN->execute([$idAlvo]);
            $nFilhos = (int)$stN->fetchColumn();
            if ($nFilhos > 0) {
                $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Não pode remover uma categoria com detalhes. Remova ou reassocie os detalhes primeiro.</div>";
            } else {
                $pdo->prepare('DELETE FROM ticket_assuntos WHERE id = ?')->execute([$idAlvo]);
                registarAuditoria($pdo, 'Assunto Ticket', 'Assunto removido: ' . $rowRem['titulo']);
                $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Item removido permanentemente.</div>";
            }
        }
    }
}

// Carregar para edição
if (isset($_GET['acao']) && $_GET['acao'] === 'editar' && isset($_GET['id'])) {
    $stmtEd = $pdo->prepare('SELECT * FROM ticket_assuntos WHERE id = ?');
    $stmtEd->execute([(int)$_GET['id']]);
    $assuntoEditar = $stmtEd->fetch(PDO::FETCH_ASSOC) ?: null;
}

$idPaiPost = isset($_POST['id_pai']) && $_POST['id_pai'] !== '' ? (int)$_POST['id_pai'] : null;
if ($idPaiPost !== null && $idPaiPost <= 0) {
    $idPaiPost = null;
}

// Adicionar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_adicionar_assunto'])) {
    $titulo = trim($_POST['titulo'] ?? '');
    $ordem = (int)($_POST['ordem'] ?? 0);
    $meta = lerMetaSlaAssuntoPost();

    if ($titulo === '') {
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: o título é obrigatório.</div>";
    } else {
        if ($ordem <= 0) {
            $ordem = (int)$pdo->query('SELECT COALESCE(MAX(ordem), 0) + 10 FROM ticket_assuntos')->fetchColumn();
        }
        try {
            $pdo->prepare('INSERT INTO ticket_assuntos (titulo, id_pai, ordem, ativo, classificacao_itil, sla_resposta_min, sla_resolucao_h, prioridade_base, urgencia_base) VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?)')
                ->execute([
                    $titulo, $idPaiPost, $ordem,
                    $meta['classificacao_itil'], $meta['sla_resposta_min'], $meta['sla_resolucao_h'],
                    $meta['prioridade_base'], $meta['urgencia_base'],
                ]);
            $tipo = $idPaiPost ? 'detalhe' : 'categoria';
            registarAuditoria($pdo, 'Assunto Ticket', "Novo $tipo criado: $titulo");
            $mensagem = "<div style='background: rgba(34,197,94,0.2); color: var(--green); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>" . ($idPaiPost ? 'Detalhe' : 'Categoria') . " adicionado com sucesso!</div>";
        } catch (PDOException $e) {
            $msg = ($e->getCode() === '23000') ? 'Já existe um item com este título.' : $e->getMessage();
            $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: " . htmlspecialchars($msg) . "</div>";
        }
    }
}

// Guardar edição
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_salvar_assunto'])) {
    $idEdit = (int)($_POST['id_assunto'] ?? 0);
    $titulo = trim($_POST['titulo'] ?? '');
    $ordem = (int)($_POST['ordem'] ?? 0);
    $meta = lerMetaSlaAssuntoPost();

    if ($idEdit <= 0 || $titulo === '') {
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: dados inválidos para edição.</div>";
    } elseif ($idPaiPost !== null && $idPaiPost === $idEdit) {
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: um item não pode ser pai de si próprio.</div>";
    } else {
        if ($ordem <= 0) {
            $ordem = 10;
        }
        try {
            $pdo->prepare('UPDATE ticket_assuntos SET titulo = ?, id_pai = ?, ordem = ?, classificacao_itil = ?, sla_resposta_min = ?, sla_resolucao_h = ?, prioridade_base = ?, urgencia_base = ? WHERE id = ?')
                ->execute([
                    $titulo, $idPaiPost, $ordem,
                    $meta['classificacao_itil'], $meta['sla_resposta_min'], $meta['sla_resolucao_h'],
                    $meta['prioridade_base'], $meta['urgencia_base'], $idEdit,
                ]);
            registarAuditoria($pdo, 'Assunto Ticket', "Assunto #$idEdit actualizado: $titulo (SLA resp {$meta['sla_resposta_min']}min / resol {$meta['sla_resolucao_h']}h / urgência {$meta['urgencia_base']})");
            $mensagem = "<div style='background: rgba(34,197,94,0.2); color: var(--green); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Item actualizado com sucesso! Urgência: <b>" . htmlspecialchars($meta['urgencia_base'] ?? '—') . "</b></div>";
            $assuntoEditar = null;
        } catch (PDOException $e) {
            $msg = ($e->getCode() === '23000') ? 'Já existe outro item com este título.' : $e->getMessage();
            $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: " . htmlspecialchars($msg) . "</div>";
        }
    }
}

$listaAssuntos = $pdo->query('
    SELECT a.*, p.titulo AS titulo_pai
    FROM ticket_assuntos a
    LEFT JOIN ticket_assuntos p ON p.id = a.id_pai
    ORDER BY COALESCE(p.ordem, a.ordem) ASC, a.id_pai IS NOT NULL ASC, a.ordem ASC, a.titulo ASC
')->fetchAll(PDO::FETCH_ASSOC);

$categoriasSelect = $pdo->query("
    SELECT id, titulo FROM ticket_assuntos
    WHERE (id_pai IS NULL OR id_pai = 0) AND ativo = 1
    ORDER BY ordem ASC, titulo ASC
")->fetchAll(PDO::FETCH_ASSOC);

$emEdicao = $assuntoEditar !== null;
$idPaiEdicao = $emEdicao ? (int)($assuntoEditar['id_pai'] ?? 0) : 0;
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
            <a href="perfis_lista.php" class="nav-item">🪪 <span>Gestão de Perfis</span></a>
            <a href="usuarios_lista.php" class="nav-item nav-sub">👥 <span>Utilizadores</span></a>
            <a href="equipa_online.php" class="nav-item">🟢 <span>Equipa Disponível</span></a>
            <a href="assuntos_lista.php" class="nav-item active">📋 <span>Assuntos de Ticket</span></a>
            <a href="emails_areas.php" class="nav-item">✉️ <span>Emails das Áreas</span></a>
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
                <p>Catálogo ITIL: categoria → detalhe. Defina <strong>SLA de resposta/resolução</strong>; a <strong>urgência</strong> pode ser automática a partir do SLA.</p>
            </div>

            <?php echo $mensagem; ?>

            <div class="card" style="margin-bottom: 30px;" id="form-assunto">
                <h3 style="margin-bottom: 15px; font-size: 16px; color: var(--accent);">
                    <?php echo $emEdicao ? 'Editar item' : 'Adicionar categoria ou detalhe'; ?>
                </h3>
                <form action="assuntos_lista.php" method="POST" style="display: flex; flex-direction: column; gap: 14px;">
                    <?php if ($emEdicao): ?>
                        <input type="hidden" name="id_assunto" value="<?php echo (int)$assuntoEditar['id']; ?>">
                    <?php endif; ?>
                    <div style="display: grid; grid-template-columns: 2fr 1.4fr 100px; gap: 15px;">
                        <div>
                            <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Título</label>
                            <input type="text" name="titulo" required maxlength="255" value="<?php echo $emEdicao ? htmlspecialchars($assuntoEditar['titulo']) : ''; ?>" placeholder="Ex: Sem acesso à Internet" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:var(--text-primary); border-radius:var(--radius-sm);">
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Tipo / categoria pai</label>
                            <select name="id_pai" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:var(--text-primary); border-radius:var(--radius-sm);">
                                <option value="">— Categoria principal —</option>
                                <?php foreach ($categoriasSelect as $cat): ?>
                                    <?php if ($emEdicao && (int)$cat['id'] === (int)$assuntoEditar['id']) continue; ?>
                                    <option value="<?php echo (int)$cat['id']; ?>" <?php echo $idPaiEdicao === (int)$cat['id'] ? 'selected' : ''; ?>>
                                        Detalhe de: <?php echo htmlspecialchars($cat['titulo']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Ordem</label>
                            <input type="number" name="ordem" min="1" value="<?php echo $emEdicao ? (int)$assuntoEditar['ordem'] : ''; ?>" placeholder="Auto" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:var(--text-primary); border-radius:var(--radius-sm);">
                        </div>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr 1.2fr; gap: 12px;">
                        <div>
                            <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">SLA resposta (min)</label>
                            <input type="number" name="sla_resposta_min" min="1" value="<?php echo $emEdicao && isset($assuntoEditar['sla_resposta_min']) ? (int)$assuntoEditar['sla_resposta_min'] : ''; ?>" placeholder="ex: 30" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:var(--text-primary); border-radius:var(--radius-sm);">
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">SLA resolução (h)</label>
                            <input type="number" name="sla_resolucao_h" min="0.25" step="0.25" value="<?php echo $emEdicao && isset($assuntoEditar['sla_resolucao_h']) ? htmlspecialchars((string)$assuntoEditar['sla_resolucao_h']) : ''; ?>" placeholder="ex: 8" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:var(--text-primary); border-radius:var(--radius-sm);">
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Urgência</label>
                            <?php $urgEd = $emEdicao ? ($assuntoEditar['urgencia_base'] ?? '') : ''; ?>
                            <select name="urgencia_base" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:var(--text-primary); border-radius:var(--radius-sm);">
                                <option value="Automática" <?php echo ($urgEd === '' || $urgEd === 'Automática') ? 'selected' : ''; ?>>Automática (pelo SLA)</option>
                                <option value="Baixa" <?php echo $urgEd === 'Baixa' ? 'selected' : ''; ?>>Baixa</option>
                                <option value="Média" <?php echo $urgEd === 'Média' ? 'selected' : ''; ?>>Média</option>
                                <option value="Alta" <?php echo $urgEd === 'Alta' ? 'selected' : ''; ?>>Alta</option>
                                <option value="Crítica" <?php echo $urgEd === 'Crítica' ? 'selected' : ''; ?>>Crítica</option>
                            </select>
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Prioridade base</label>
                            <?php $prioEd = $emEdicao ? ($assuntoEditar['prioridade_base'] ?? '') : ''; ?>
                            <select name="prioridade_base" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:var(--text-primary); border-radius:var(--radius-sm);">
                                <option value="">—</option>
                                <option value="Baixa" <?php echo $prioEd === 'Baixa' ? 'selected' : ''; ?>>Baixa</option>
                                <option value="Média" <?php echo $prioEd === 'Média' ? 'selected' : ''; ?>>Média</option>
                                <option value="Alta" <?php echo $prioEd === 'Alta' ? 'selected' : ''; ?>>Alta</option>
                                <option value="Crítica" <?php echo $prioEd === 'Crítica' ? 'selected' : ''; ?>>Crítica</option>
                            </select>
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Classificação ITIL</label>
                            <input type="text" name="classificacao_itil" value="<?php echo $emEdicao ? htmlspecialchars($assuntoEditar['classificacao_itil'] ?? '') : ''; ?>" placeholder="Incidente / Acesso..." style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:var(--text-primary); border-radius:var(--radius-sm);">
                        </div>
                    </div>
                    <div style="display:flex; gap:10px; align-items:center;">
                        <button type="submit" name="<?php echo $emEdicao ? 'btn_salvar_assunto' : 'btn_adicionar_assunto'; ?>" style="padding: 10px 18px; background: var(--accent); border:none; color:#fff; border-radius:var(--radius-sm); cursor:pointer; font-weight:600;">
                            <?php echo $emEdicao ? 'Guardar' : 'Adicionar'; ?>
                        </button>
                        <?php if ($emEdicao): ?>
                        <a href="assuntos_lista.php" style="font-size:12px; color:var(--text-muted);">Cancelar</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="card">
                <h3 style="margin-bottom: 15px; font-size: 16px;">Estrutura (<?php echo count($listaAssuntos); ?> itens)</h3>
                <div style="overflow-x: auto;">
                    <table style="width:100%; border-collapse: collapse; font-size: 14px;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--border); text-align: left;">
                                <th style="padding: 10px; color: var(--text-secondary);">Ordem</th>
                                <th style="padding: 10px; color: var(--text-secondary);">Item</th>
                                <th style="padding: 10px; color: var(--text-secondary);">Nível</th>
                                <th style="padding: 10px; color: var(--text-secondary);">SLA Resp.</th>
                                <th style="padding: 10px; color: var(--text-secondary);">SLA Resol.</th>
                                <th style="padding: 10px; color: var(--text-secondary);">Urgência</th>
                                <th style="padding: 10px; color: var(--text-secondary);">Estado</th>
                                <th style="padding: 10px; color: var(--text-secondary);">Acções</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($listaAssuntos)): ?>
                            <tr>
                                <td colspan="8" style="padding: 20px; color: var(--text-muted); text-align: center;">
                                    Nenhum assunto. Execute <a href="atualizar_banco.php" style="color:var(--accent);">atualizar_banco.php</a> para carregar o catálogo ITIL.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($listaAssuntos as $ass):
                                $ehDetalhe = !empty($ass['id_pai']);
                            ?>
                            <tr style="border-bottom: 1px solid var(--border); <?php echo $ehDetalhe ? '' : 'background: rgba(61,111,255,0.04);'; ?>">
                                <td style="padding: 10px; color: var(--text-muted);"><?php echo (int)$ass['ordem']; ?></td>
                                <td style="padding: 10px; <?php echo $ehDetalhe ? 'padding-left:28px;' : 'font-weight:600;'; ?>">
                                    <?php if ($ehDetalhe): ?>
                                        <span style="color:var(--text-muted);">↳</span>
                                        <?php echo htmlspecialchars($ass['titulo']); ?>
                                        <div style="font-size:11px; color:var(--text-muted); margin-top:2px;"><?php echo htmlspecialchars($ass['titulo_pai'] ?? ''); ?></div>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($ass['titulo']); ?>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 10px; font-size:12px; color:var(--text-secondary);">
                                    <?php echo $ehDetalhe ? 'Detalhe' : 'Categoria'; ?>
                                </td>
                                <td style="padding: 10px; font-size:12px; color:var(--text-secondary);">
                                    <?php echo isset($ass['sla_resposta_min']) && $ass['sla_resposta_min'] !== null ? ((int)$ass['sla_resposta_min'] . ' min') : '—'; ?>
                                </td>
                                <td style="padding: 10px; font-size:12px; color:var(--text-secondary);">
                                    <?php echo isset($ass['sla_resolucao_h']) && $ass['sla_resolucao_h'] !== null ? (rtrim(rtrim(number_format((float)$ass['sla_resolucao_h'], 2, '.', ''), '0'), '.') . ' h') : '—'; ?>
                                </td>
                                <td style="padding: 10px; font-size:12px; font-weight:600; color:var(--accent);">
                                    <?php echo htmlspecialchars($ass['urgencia_base'] ?? '—'); ?>
                                </td>
                                <td style="padding: 10px;">
                                    <?php if ((int)$ass['ativo'] === 1): ?>
                                        <span style="color: var(--green); font-weight: 600;">Activo</span>
                                    <?php else: ?>
                                        <span style="color: var(--amber); font-weight: 600;">Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 10px; white-space: nowrap;">
                                    <a href="assuntos_lista.php?acao=editar&id=<?php echo (int)$ass['id']; ?>#form-assunto" style="color: var(--accent); text-decoration: none; margin-right: 12px; font-weight: 600; font-size: 13px;">Editar</a>
                                    <?php if ((int)$ass['ativo'] === 1): ?>
                                        <a href="assuntos_lista.php?acao=desativar&id=<?php echo (int)$ass['id']; ?>" style="color: var(--amber); text-decoration: none; margin-right: 12px; font-weight: 600; font-size: 13px;" onclick="return confirm('Desactivar este item?');">Desactivar</a>
                                    <?php else: ?>
                                        <a href="assuntos_lista.php?acao=ativar&id=<?php echo (int)$ass['id']; ?>" style="color: var(--green); text-decoration: none; margin-right: 12px; font-weight: 600; font-size: 13px;">Activar</a>
                                    <?php endif; ?>
                                    <a href="assuntos_lista.php?acao=remover&id=<?php echo (int)$ass['id']; ?>" style="color: var(--red); text-decoration: none; font-weight: 600; font-size: 13px;" onclick="return confirm('Remover permanentemente?');">Remover</a>
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
