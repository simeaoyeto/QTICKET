<?php
/**
 * KIAMI — Gestão de áreas e emails (caixas postais)
 *
 * Quem gere: Admin + técnicos/responsáveis de Redes & Sistemas e Desenvolvimento.
 * Novas áreas aparecem automaticamente em «Área / Direção» na abertura de tickets.
 */
require_once 'conexao.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$nome_usuario = $_SESSION['nome'];
$perfil_usuario = $_SESSION['perfil'];
$contexto = obterContextoUsuario($pdo);

if (!podeGerirEmailsAreas($contexto)) {
    http_response_code(403);
    die('Acesso negado. A gestão de emails das áreas está reservada ao Admin e às equipas de Redes & Sistemas e Desenvolvimento.');
}

$mensagem = '';
$areaEditar = null;

/** Emails sugeridos para áreas ainda sem caixa postal */
function emailSugeridoArea(string $nome): ?string
{
    $mapa = [
        'Redes & Sistemas' => 'helpdesk@quality.co.ao',
        'Desenvolvimento' => 'desenvolvimento@quality.co.ao',
        'Direção' => 'direcao@quality.co.ao',
        'Direccao' => 'direcao@quality.co.ao',
        'Legal' => 'legal@quality.co.ao',
        'Logística' => 'logistica@quality.co.ao',
        'RH' => 'rh@quality.co.ao',
        'Recursos Humanos' => 'rh@quality.co.ao',
        'Formadores' => 'formacao@quality.co.ao',
        'Finanças' => 'financas@quality.co.ao',
        'Comercial' => 'comercial@quality.co.ao',
        'Auditoria' => 'auditoria@quality.co.ao',
    ];
    return $mapa[$nome] ?? null;
}

// Preencher emails em falta com sugestões (só se estiver vazio)
try {
    $vazias = $pdo->query("SELECT id, nome FROM areas WHERE email IS NULL OR email = ''")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($vazias)) {
        $updSug = $pdo->prepare("UPDATE areas SET email = ? WHERE id = ? AND (email IS NULL OR email = '')");
        foreach ($vazias as $va) {
            $sug = emailSugeridoArea($va['nome']);
            if ($sug) {
                $updSug->execute([$sug, (int)$va['id']]);
            }
        }
    }
} catch (PDOException $e) {
    // ignore
}

// Carregar para edição
if (isset($_GET['acao'], $_GET['id']) && $_GET['acao'] === 'editar') {
    $stmtEd = $pdo->prepare('SELECT id, nome, email FROM areas WHERE id = ?');
    $stmtEd->execute([(int)$_GET['id']]);
    $areaEditar = $stmtEd->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Remover área (só se não tiver tickets / utilizadores ligados)
if (isset($_GET['acao'], $_GET['id']) && $_GET['acao'] === 'remover') {
    $idRem = (int)$_GET['id'];
    if ($idRem > 0) {
        $stT = $pdo->prepare('SELECT COUNT(*) FROM tickets WHERE id_area_destino = ?');
        $stT->execute([$idRem]);
        $nTickets = (int)$stT->fetchColumn();
        $stU = $pdo->prepare('SELECT COUNT(*) FROM utilizadores WHERE id_area = ?');
        $stU->execute([$idRem]);
        $nUsers = (int)$stU->fetchColumn();
        $nUa = 0;
        try {
            $stUa = $pdo->prepare('SELECT COUNT(*) FROM utilizador_areas WHERE id_area = ?');
            $stUa->execute([$idRem]);
            $nUa = (int)$stUa->fetchColumn();
        } catch (PDOException $e) {
            $nUa = 0;
        }

        if ($nTickets > 0 || $nUsers > 0 || $nUa > 0) {
            $mensagem = "<div style='background:rgba(239,68,68,0.15);color:var(--red);padding:10px;border-radius:var(--radius-sm);margin-bottom:15px;'>Não é possível remover: existem tickets ou utilizadores associados a esta área. Pode editar o nome ou o email.</div>";
        } else {
            $stNome = $pdo->prepare('SELECT nome FROM areas WHERE id = ?');
            $stNome->execute([$idRem]);
            $nomeRem = (string)($stNome->fetchColumn() ?: '');
            // Impede que «Supervisão X» volte a ser criada ao actualizar a página
            desactivarAreaSupervisaoOperacao($pdo, $idRem);
            $pdo->prepare('DELETE FROM areas WHERE id = ?')->execute([$idRem]);
            registarAuditoria($pdo, 'Exclusão', "Área removida: $nomeRem (#$idRem)");
            $mensagem = "<div style='background:rgba(34,197,94,0.15);color:var(--green);padding:10px;border-radius:var(--radius-sm);margin-bottom:15px;'>Área «" . htmlspecialchars($nomeRem) . "» removida.</div>";
        }
    }
}

// Adicionar nova área
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_adicionar_area'])) {
    $nomeNovo = trim($_POST['nome_area'] ?? '');
    $emailNovo = trim($_POST['email_nova_area'] ?? '');

    if ($nomeNovo === '') {
        $mensagem = "<div style='background:rgba(239,68,68,0.15);color:var(--red);padding:10px;border-radius:var(--radius-sm);margin-bottom:15px;'>Indique o nome da área.</div>";
    } elseif ($emailNovo !== '' && !filter_var($emailNovo, FILTER_VALIDATE_EMAIL)) {
        $mensagem = "<div style='background:rgba(239,68,68,0.15);color:var(--red);padding:10px;border-radius:var(--radius-sm);margin-bottom:15px;'>Email inválido.</div>";
    } else {
        try {
            $stEx = $pdo->prepare('SELECT id FROM areas WHERE nome = ? LIMIT 1');
            $stEx->execute([$nomeNovo]);
            if ($stEx->fetchColumn()) {
                $mensagem = "<div style='background:rgba(239,68,68,0.15);color:var(--red);padding:10px;border-radius:var(--radius-sm);margin-bottom:15px;'>Já existe uma área com esse nome.</div>";
            } else {
                if ($emailNovo === '') {
                    $emailNovo = emailSugeridoArea($nomeNovo) ?? '';
                }
                $pdo->prepare('INSERT INTO areas (nome, email) VALUES (?, ?)')
                    ->execute([$nomeNovo, $emailNovo !== '' ? $emailNovo : null]);
                $idNova = (int)$pdo->lastInsertId();
                if ($idNova > 0) {
                    reactivarAreaSupervisaoSeAplicavel($pdo, $idNova, $nomeNovo);
                }
                registarAuditoria($pdo, 'Criação', "Nova área criada: $nomeNovo");
                $mensagem = "<div style='background:rgba(34,197,94,0.15);color:var(--green);padding:10px;border-radius:var(--radius-sm);margin-bottom:15px;'>Área <b>" . htmlspecialchars($nomeNovo) . "</b> criada. Já aparece automaticamente em «Área / Direção» na abertura de tickets.</div>";
            }
        } catch (PDOException $e) {
            $mensagem = "<div style='background:rgba(239,68,68,0.15);color:var(--red);padding:10px;border-radius:var(--radius-sm);margin-bottom:15px;'>Erro: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

// Guardar edição de uma área (nome + email)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_salvar_area'])) {
    $idEdit = (int)($_POST['id_area'] ?? 0);
    $nomeEdit = trim($_POST['nome_area'] ?? '');
    $emailEdit = trim($_POST['email_area'] ?? '');

    if ($idEdit <= 0 || $nomeEdit === '') {
        $mensagem = "<div style='background:rgba(239,68,68,0.15);color:var(--red);padding:10px;border-radius:var(--radius-sm);margin-bottom:15px;'>Dados inválidos.</div>";
    } elseif ($emailEdit !== '' && !filter_var($emailEdit, FILTER_VALIDATE_EMAIL)) {
        $mensagem = "<div style='background:rgba(239,68,68,0.15);color:var(--red);padding:10px;border-radius:var(--radius-sm);margin-bottom:15px;'>Email inválido.</div>";
    } else {
        try {
            $stDup = $pdo->prepare('SELECT id FROM areas WHERE nome = ? AND id <> ? LIMIT 1');
            $stDup->execute([$nomeEdit, $idEdit]);
            if ($stDup->fetchColumn()) {
                $mensagem = "<div style='background:rgba(239,68,68,0.15);color:var(--red);padding:10px;border-radius:var(--radius-sm);margin-bottom:15px;'>Já existe outra área com esse nome.</div>";
            } else {
                $pdo->prepare('UPDATE areas SET nome = ?, email = ? WHERE id = ?')
                    ->execute([$nomeEdit, $emailEdit !== '' ? $emailEdit : null, $idEdit]);
                reactivarAreaSupervisaoSeAplicavel($pdo, $idEdit, $nomeEdit);
                registarAuditoria($pdo, 'Alteração', "Área #$idEdit actualizada: $nomeEdit");
                $mensagem = "<div style='background:rgba(34,197,94,0.15);color:var(--green);padding:10px;border-radius:var(--radius-sm);margin-bottom:15px;'>Área actualizada com sucesso.</div>";
                $areaEditar = null;
            }
        } catch (PDOException $e) {
            $mensagem = "<div style='background:rgba(239,68,68,0.15);color:var(--red);padding:10px;border-radius:var(--radius-sm);margin-bottom:15px;'>Erro: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

// Guardar todos os emails (lista)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_guardar_emails'])) {
    $emails = $_POST['email'] ?? [];
    if (is_array($emails)) {
        $stmt = $pdo->prepare("UPDATE areas SET email = ? WHERE id = ?");
        $ok = 0;
        foreach ($emails as $idArea => $email) {
            $idArea = (int)$idArea;
            $email = trim((string)$email);
            if ($idArea <= 0) {
                continue;
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $mensagem = "<div style='background:rgba(239,68,68,0.15);color:var(--red);padding:10px;border-radius:var(--radius-sm);margin-bottom:15px;'>Email inválido na área #$idArea.</div>";
                break;
            }
            $stmt->execute([$email !== '' ? $email : null, $idArea]);
            $ok++;
        }
        if ($mensagem === '') {
            registarAuditoria($pdo, 'Alteração', 'Emails das áreas actualizados');
            $mensagem = "<div style='background:rgba(34,197,94,0.15);color:var(--green);padding:10px;border-radius:var(--radius-sm);margin-bottom:15px;'>Emails das áreas guardados ({$ok}).</div>";
        }
    }
}

$areas = $pdo->query("SELECT id, nome, email FROM areas ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
$emEdicao = $areaEditar !== null;
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KIAMI - Emails das Áreas</title>
    <link rel="stylesheet" href="style.css">
    <script src="tema.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
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

        <div class="nav-section-title">Administração</div>
        <a href="perfis_lista.php" class="nav-item">🪪 <span>Gestão de Perfis</span></a>
        <a href="usuarios_lista.php" class="nav-item nav-sub">👥 <span>Utilizadores</span></a>
        <a href="equipa_online.php" class="nav-item">🟢 <span>Equipa Disponível</span></a>
        <a href="assuntos_lista.php" class="nav-item">📋 <span>Assuntos de Ticket</span></a>
        <a href="emails_areas.php" class="nav-item active">✉️ <span>Emails das Áreas</span></a>
        <a href="relatorios.php" class="nav-item">📈 <span>Relatórios</span></a>
        <?php if (podeAcederAuditoria($contexto)): ?>
        <a href="auditoria.php" class="nav-item">🔍 <span>Auditoria</span></a>
        <?php endif; ?>

        <div class="sidebar-footer">
            <div class="user-badge">
                <div style="font-size:14px;font-weight:600;"><?php echo htmlspecialchars($nome_usuario); ?></div>
                <div style="font-size:11px;color:var(--accent);font-weight:600;margin-top:4px;">⚙️ <?php echo htmlspecialchars($perfil_usuario); ?></div>
            </div>
            <a href="logout.php" class="btn-danger">🚪 Sair do Sistema</a>
        </div>
    </div>

    <div id="main-content">
        <div class="page-header">
            <h1>Emails das Áreas</h1>
            <p>Defina a caixa postal de cada área e gerira as áreas da empresa. Novas áreas aparecem automaticamente em <b>Área / Direção</b> na abertura de tickets.</p>
        </div>
        <?php echo $mensagem; ?>

        <div class="card" style="margin-bottom:24px;" id="form-nova-area">
            <h3 style="margin-bottom:12px;font-size:16px;color:var(--accent);">
                <?php echo $emEdicao ? 'Editar área' : 'Adicionar nova área'; ?>
            </h3>
            <?php if ($emEdicao): ?>
            <form method="POST" style="display:grid;grid-template-columns:1.4fr 1.6fr auto auto;gap:12px;align-items:end;">
                <input type="hidden" name="id_area" value="<?php echo (int)$areaEditar['id']; ?>">
                <div>
                    <label style="display:block;margin-bottom:5px;font-size:12px;color:var(--text-secondary);">Nome da área</label>
                    <input type="text" name="nome_area" required maxlength="100" value="<?php echo htmlspecialchars($areaEditar['nome']); ?>" style="width:100%;padding:10px;background:var(--bg-input);border:1px solid var(--border);color:#fff;border-radius:var(--radius-sm);">
                </div>
                <div>
                    <label style="display:block;margin-bottom:5px;font-size:12px;color:var(--text-secondary);">Email</label>
                    <input type="email" name="email_area" value="<?php echo htmlspecialchars($areaEditar['email'] ?? ''); ?>" placeholder="ex: nova.area@quality.co.ao" style="width:100%;padding:10px;background:var(--bg-input);border:1px solid var(--border);color:#fff;border-radius:var(--radius-sm);">
                </div>
                <button type="submit" name="btn_salvar_area" style="padding:10px 16px;background:var(--accent);border:none;color:#fff;border-radius:var(--radius-sm);cursor:pointer;font-weight:600;">Guardar</button>
                <a href="emails_areas.php" style="padding:10px 14px;color:var(--text-muted);font-size:13px;align-self:center;">Cancelar</a>
            </form>
            <?php else: ?>
            <form method="POST" style="display:grid;grid-template-columns:1.4fr 1.6fr auto;gap:12px;align-items:end;">
                <div>
                    <label style="display:block;margin-bottom:5px;font-size:12px;color:var(--text-secondary);">Nome da área</label>
                    <input type="text" name="nome_area" required maxlength="100" placeholder="Ex: Qualidade" style="width:100%;padding:10px;background:var(--bg-input);border:1px solid var(--border);color:#fff;border-radius:var(--radius-sm);">
                </div>
                <div>
                    <label style="display:block;margin-bottom:5px;font-size:12px;color:var(--text-secondary);">Email (opcional)</label>
                    <input type="email" name="email_nova_area" placeholder="ex: qualidade@quality.co.ao" style="width:100%;padding:10px;background:var(--bg-input);border:1px solid var(--border);color:#fff;border-radius:var(--radius-sm);">
                </div>
                <button type="submit" name="btn_adicionar_area" style="padding:10px 18px;background:var(--green);border:none;color:#fff;border-radius:var(--radius-sm);cursor:pointer;font-weight:600;">+ Adicionar área</button>
            </form>
            <p style="margin-top:10px;font-size:12px;color:var(--text-muted);">Após adicionar, a área fica disponível de imediato nos formulários de abertura e reencaminhamento de tickets.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3 style="margin-bottom:14px;font-size:16px;">Caixas postais (<?php echo count($areas); ?> áreas)</h3>
            <form method="POST" style="display:flex;flex-direction:column;gap:14px;">
                <?php foreach ($areas as $ar): ?>
                    <div style="display:grid;grid-template-columns:200px 1fr auto;gap:12px;align-items:center;">
                        <label style="font-weight:600;color:#fff;"><?php echo htmlspecialchars($ar['nome']); ?></label>
                        <input type="email" name="email[<?php echo (int)$ar['id']; ?>]" value="<?php echo htmlspecialchars($ar['email'] ?? ''); ?>" placeholder="ex: helpdesk@quality.co.ao" style="width:100%;padding:10px;background:var(--bg-input);border:1px solid var(--border);color:#fff;border-radius:var(--radius-sm);">
                        <div style="display:flex;gap:10px;white-space:nowrap;">
                            <a href="emails_areas.php?acao=editar&id=<?php echo (int)$ar['id']; ?>#form-nova-area" style="color:var(--accent);font-size:13px;font-weight:600;text-decoration:none;">Editar</a>
                            <a href="emails_areas.php?acao=remover&id=<?php echo (int)$ar['id']; ?>" style="color:var(--red);font-size:13px;font-weight:600;text-decoration:none;" onclick="return confirm('Remover esta área? Só é possível se não tiver tickets nem utilizadores.');">Remover</a>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div>
                    <button type="submit" name="btn_guardar_emails" style="padding:12px 18px;background:var(--accent);border:none;color:#fff;border-radius:var(--radius-sm);cursor:pointer;font-weight:600;">Guardar Emails</button>
                </div>
            </form>
            <p style="margin-top:16px;font-size:12px;color:var(--text-muted);">Sugestão: Redes & Sistemas pode usar <b>helpdesk@quality.co.ao</b>; Desenvolvimento <b>desenvolvimento@quality.co.ao</b>.</p>
        </div>
    </div>
</div>
<script src="notificacoes.js"></script>
</body>
</html>
