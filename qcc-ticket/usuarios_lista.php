<?php
/**
 * KIAMI — Gestão de utilizadores
 *
 * Criar, ativar, desativar e remover utilizadores.
 * Restrito a Admin e staff das áreas técnicas (Redes & Sistemas / Desenvolvimento).
 * Novos utilizadores recebem senha padrão 123456 (obriga troca no 1.º login).
 */
require_once 'conexao.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$nome_usuario = $_SESSION['nome'];
$perfil_usuario = $_SESSION['perfil'];
$contexto = obterContextoUsuario($pdo);
$user_id_num = $contexto['user_id_numerico'];
$mensagem = '';

// Utilizador em edição (preenche o formulário)
$userEditar = null;

// Gestão de utilizadores: apenas Admin e staff das áreas Redes & Sistemas / Desenvolvimento
if (!podeGerirUtilizadores($contexto)) {
    http_response_code(403);
    registarAuditoria($pdo, 'Acesso Negado', "Tentativa de aceder à gestão de utilizadores");
    die("Acesso negado. A gestão de utilizadores está reservada às áreas de Redes & Sistemas e Desenvolvimento.");
}

// =========================================================
// PROCESSAR AÇÕES: DESATIVAR, ATIVAR E REMOVER (GET + CSRF)
// =========================================================
$acoesMutacao = ['desativar', 'ativar', 'aceitar', 'recusar', 'remover'];
if (isset($_GET['acao'], $_GET['id']) && in_array($_GET['acao'], $acoesMutacao, true)) {
    $id_alvo = (int)$_GET['id'];
    $acao = $_GET['acao'];

    if (!validarTokenCsrf($_GET['csrf'] ?? null)) {
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Pedido inválido ou expirado. Recarregue a página e tente novamente.</div>";
    } elseif ($id_alvo == $_SESSION['user_id'] || $id_alvo == 1) {
        // Impedir que o admin se desative ou se remova a si próprio
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: Não pode alterar ou remover a conta Master do Administrador.</div>";
    } else {
        if ($acao === 'desativar') {
            $stmt = $pdo->prepare("UPDATE utilizadores SET estado = 'Inativo' WHERE id = ?");
            $stmt->execute([$id_alvo]);
            $mensagem = "<div style='background: rgba(245,158,11,0.2); color: var(--amber); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Utilizador desativado com sucesso!</div>";
        } elseif ($acao === 'ativar' || $acao === 'aceitar') {
            $stmt = $pdo->prepare("UPDATE utilizadores SET estado = 'Ativo' WHERE id = ?");
            $stmt->execute([$id_alvo]);
            criarNotificacao($pdo, $id_alvo, null, 'Conta aprovada', 'A sua conta foi aceite. Já pode iniciar sessão no KIAMI.', null);
            registarAuditoria($pdo, 'Alteração', "Conta #$id_alvo aceite/ativada");
            $mensagem = "<div style='background: rgba(34,197,94,0.2); color: var(--green); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Conta aceite/ativada com sucesso!</div>";
        } elseif ($acao === 'recusar') {
            $stmt = $pdo->prepare("UPDATE utilizadores SET estado = 'Inativo' WHERE id = ?");
            $stmt->execute([$id_alvo]);
            criarNotificacao($pdo, $id_alvo, null, 'Conta recusada', 'O pedido de conta foi recusado por Redes & Sistemas.', null);
            registarAuditoria($pdo, 'Alteração', "Conta #$id_alvo recusada");
            $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Pedido de conta recusado.</div>";
        } elseif ($acao === 'remover') {
            $resultado = eliminarUtilizadorSeguro($pdo, $id_alvo);
            if ($resultado['sucesso']) {
                registarAuditoria($pdo, 'Exclusão', "Utilizador #$id_alvo eliminado");
                $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Utilizador removido permanentemente do sistema!</div>";
            } else {
                $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro ao remover: " . htmlspecialchars($resultado['erro']) . "</div>";
            }
        }
    }
}

$tokenCsrfUsers = gerarTokenCsrf();

// =========================================================
// CARREGAR UTILIZADOR PARA EDIÇÃO (GET)
// =========================================================
if (isset($_GET['acao']) && $_GET['acao'] === 'editar' && isset($_GET['id'])) {
    $id_ed = (int)$_GET['id'];
    if ($id_ed != 1 && $id_ed != $_SESSION['user_id']) {
        $stmt_ed = $pdo->prepare("SELECT * FROM utilizadores WHERE id = ?");
        $stmt_ed->execute([$id_ed]);
        $userEditar = $stmt_ed->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

// =========================================================
// PROCESSAR EDIÇÃO DE UTILIZADOR (POST)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_salvar_usuario'])) {
    $id_edit = (int)($_POST['id_usuario'] ?? 0);
    $nome = trim($_POST['nome'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $perfil = mapearPerfilParaBd(trim($_POST['perfil'] ?? 'Tecnico'));
    $estadoRaw = $_POST['estado'] ?? 'Ativo';
    $estado = in_array($estadoRaw, ['Ativo', 'Inativo', 'Pendente'], true) ? $estadoRaw : 'Ativo';
    $destino = trim($_POST['destino'] ?? '');
    $areasPost = array_map('intval', $_POST['areas'] ?? []);

    $id_area = null;
    $id_operacao = null;
    $usaOperacao = ($perfil === 'Operador');
    $emailObrigatorio = !$usaOperacao;

    if ($usaOperacao) {
        if (strpos($destino, 'OP_') === 0) {
            $id_operacao = (int)str_replace('OP_', '', $destino);
        }
        $areasPost = [];
    } else {
        if (!empty($areasPost)) {
            $id_area = $areasPost[0];
        } elseif (strpos($destino, 'AR_') === 0) {
            $id_area = (int)str_replace('AR_', '', $destino);
            $areasPost = [$id_area];
        }
    }

    $erroValidacao = false;
    [$emailOk, $emailRes] = validarEmailConta($email, $emailObrigatorio);
    if (!$emailOk) {
        $erroValidacao = true;
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: " . htmlspecialchars($emailRes) . "</div>";
    } elseif ($perfil === 'Operador' && $id_operacao <= 0) {
        $erroValidacao = true;
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: O perfil Operador exige uma operação associada.</div>";
    } elseif (!$usaOperacao && in_array($perfil, ['Tecnico', 'Responsavel', 'Comum'], true) && empty($areasPost)) {
        $erroValidacao = true;
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: Selecione pelo menos uma área.</div>";
    }

    if ($erroValidacao) {
        // Mantém a mensagem já definida
    } elseif ($id_edit <= 0 || $id_edit == 1 || $id_edit == $_SESSION['user_id']) {
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: Não pode editar esta conta protegida.</div>";
    } elseif (empty($nome) || empty($username)) {
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: Nome e utilizador são obrigatórios.</div>";
    } else {
        $check = $pdo->prepare("SELECT id FROM utilizadores WHERE username = ? AND id != ?");
        $check->execute([$username, $id_edit]);
        if ($check->fetch()) {
            $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: Esse nome de utilizador já está registado.</div>";
        } else {
            $sqlUpd = "UPDATE utilizadores SET nome = ?, username = ?, email = ?, perfil = ?, estado = ?, id_area = ?, id_operacao = ?";
            $paramsUpd = [$nome, $username, $emailRes, $perfil, $estado, $id_area, $id_operacao];

            $novaSenhaAdmin = trim($_POST['nova_senha_admin'] ?? '');
            $msgExtra = '';

            if ($novaSenhaAdmin !== '') {
                if (strlen($novaSenhaAdmin) < 6) {
                    $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: A nova palavra-passe deve ter pelo menos 6 caracteres.</div>";
                    $sqlUpd = null;
                } else {
                    $sqlUpd .= ", password_hash = ?";
                    $paramsUpd[] = password_hash($novaSenhaAdmin, PASSWORD_BCRYPT);
                    $msgExtra = ' Palavra-passe definida manualmente.';
                }
            } elseif (!empty($_POST['repor_senha'])) {
                $sqlUpd .= ", password_hash = ?";
                $paramsUpd[] = password_hash('123456', PASSWORD_BCRYPT);
                $msgExtra = ' Senha reposta para 123456.';
            }

            if ($sqlUpd !== null) {
                $sqlUpd .= " WHERE id = ?";
                $paramsUpd[] = $id_edit;

                $pdo->prepare($sqlUpd)->execute($paramsUpd);
                sincronizarAreasUtilizador($pdo, $id_edit, $usaOperacao ? [] : $areasPost);
                registarAuditoria($pdo, 'Alteração', "Utilizador #$id_edit editado: $username ($perfil)");
                $mensagem = "<div style='background: rgba(34,197,94,0.2); color: var(--green); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Utilizador <b>$username</b> atualizado com sucesso!$msgExtra</div>";
                $userEditar = null;
            }
        }
    }
}

// =========================================================
// PROCESSAR CRIAÇÃO DE NOVO UTILIZADOR (POST)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_criar_usuario'])) {
    $nome = trim($_POST['nome'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $perfil = mapearPerfilParaBd(trim($_POST['perfil'] ?? 'Tecnico'));
    $destino = trim($_POST['destino'] ?? '');
    $areasPost = array_map('intval', $_POST['areas'] ?? []);

    $id_area = null;
    $id_operacao = null;
    $usaOperacao = ($perfil === 'Operador');
    $emailObrigatorio = !$usaOperacao;

    if ($usaOperacao) {
        if (strpos($destino, 'OP_') === 0) {
            $id_operacao = (int)str_replace('OP_', '', $destino);
        }
        $areasPost = [];
    } else {
        if (!empty($areasPost)) {
            $id_area = $areasPost[0];
        } elseif (strpos($destino, 'AR_') === 0) {
            $id_area = (int)str_replace('AR_', '', $destino);
            $areasPost = [$id_area];
        }
    }

    $senha_default_hash = password_hash('123456', PASSWORD_BCRYPT);

    [$emailOk, $emailRes] = validarEmailConta($email, $emailObrigatorio);
    if (!$emailOk) {
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: " . htmlspecialchars($emailRes) . "</div>";
    } elseif ($perfil === 'Operador' && $id_operacao <= 0) {
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: O perfil Operador exige escolher uma operação.</div>";
    } elseif (!$usaOperacao && in_array($perfil, ['Tecnico', 'Responsavel', 'Comum'], true) && empty($areasPost)) {
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: Selecione pelo menos uma área.</div>";
    } elseif (!empty($nome) && !empty($username)) {
        $check = $pdo->prepare("SELECT id FROM utilizadores WHERE username = ?");
        $check->execute([$username]);
        
        if ($check->fetch()) {
            $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: Esse nome de utilizador já está registado.</div>";
        } else {
            $estadoNovo = 'Ativo';
            $stmt = $pdo->prepare("INSERT INTO utilizadores (nome, username, email, password_hash, perfil, estado, id_area, id_operacao) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$nome, $username, $emailRes, $senha_default_hash, $perfil, $estadoNovo, $id_area, $id_operacao]);
            $novoId = (int)$pdo->lastInsertId();
            sincronizarAreasUtilizador($pdo, $novoId, $usaOperacao ? [] : $areasPost);
            registarAuditoria($pdo, 'Criação', "Utilizador criado: $username ($perfil / $estadoNovo)");
            $mensagem = "<div style='background: rgba(34,197,94,0.2); color: var(--green); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Utilizador <b>$username</b> criado com sucesso! Senha padrão: 123456</div>";
        }
    }
}

// Procurar Áreas e Operações dinamicamente para os selects
$areas_db = $pdo->query("SELECT id, nome FROM areas ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
$operacoes_db = $pdo->query("SELECT id, nome FROM operacoes ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);

// Listar utilizadores — áreas multi via subquery
$query_users = "
    SELECT u.*, 
           a.nome AS nome_area,
           o.nome AS nome_operacao,
           (SELECT GROUP_CONCAT(ar.nome ORDER BY ar.nome SEPARATOR ', ')
            FROM utilizador_areas ua
            INNER JOIN areas ar ON ar.id = ua.id_area
            WHERE ua.id_utilizador = u.id) AS nomes_areas
    FROM utilizadores u
    LEFT JOIN areas a ON u.id_area = a.id
    LEFT JOIN operacoes o ON u.id_operacao = o.id
    ORDER BY FIELD(u.estado, 'Pendente', 'Ativo', 'Inativo'), u.id DESC
";
$lista_users = $pdo->query($query_users)->fetchAll(PDO::FETCH_ASSOC);
$nPendentes = 0;
foreach ($lista_users as $lu) {
    if (($lu['estado'] ?? '') === 'Pendente') {
        $nPendentes++;
    }
}

$areasEditar = [];
if ($userEditar) {
    $areasEditar = obterIdsAreasUtilizador($pdo, (int)$userEditar['id']);
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <title>KIAMI - Gestão de Utilizadores</title>
    <link rel="stylesheet" href="style.css">
    <script src="tema.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"></head>
<body>
    <div class="app-layout">
        <div id="sidebar">
            <div class="sidebar-brand"><h3>KIAMI</h3><span>Suporte Quality</span></div>
            <div class="nav-section-title">Geral</div>
            <a href="index.php" class="nav-item">📊 <span>Painel</span></a>
            <a href="tickets_lista.php" class="nav-item">🎫 <span>Tickets</span></a>
            <a href="kb_lista.php" class="nav-item">📚 <span>Base Conhecimento</span></a>
            <a href="empresa.php" class="nav-item">🏢 <span>A Empresa</span></a>
            <?php echo htmlNavNotificacoes((int)($notif_pendentes ?? 0)); ?>
            <div class="nav-section-title">Administração</div>
            <a href="usuarios_lista.php" class="nav-item">👥 <span>Gestão de Utilizadores</span></a>
            <a href="equipa_online.php" class="nav-item">🟢 <span>Equipa Disponível</span></a>
            <a href="assuntos_lista.php" class="nav-item">📋 <span>Assuntos de Ticket</span></a>
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
                <h1>Controlo de Utilizadores</h1>
                <p>Registe novos membros, associe-os a departamentos ou operações e gira os seus acessos.<?php if ($nPendentes > 0): ?> <b style="color:var(--amber);"><?php echo $nPendentes; ?> conta(s) pendente(s) de aprovação.</b><?php endif; ?></p>
            </div>

            <?php echo $mensagem; ?>

            <div class="card" style="margin-bottom: 30px;" id="form-user">
                <?php
                    $emEdicaoUser = $userEditar !== null;
                    $destinoAtual = '';
                    if ($emEdicaoUser) {
                        if (!empty($userEditar['id_operacao'])) {
                            $destinoAtual = 'OP_' . $userEditar['id_operacao'];
                        } elseif (!empty($userEditar['id_area'])) {
                            $destinoAtual = 'AR_' . $userEditar['id_area'];
                        }
                    }
                    $perfisForm = [
                        'Comum' => 'Utilizador Comum',
                        'Operador' => 'Operador',
                        'Tecnico' => 'Técnico',
                        'Responsavel' => 'Responsável de Área',
                        'Diretor Geral' => 'Diretor Geral',
                        'Admin' => 'Administrador',
                    ];
                ?>
                <h3 style="margin-bottom: 15px; font-size: 16px; color: var(--accent);">
                    <?php echo $emEdicaoUser ? '✏️ Editar Utilizador' : '👥 Criar Nova Conta'; ?>
                </h3>
                <form action="usuarios_lista.php" method="POST" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)) auto; gap: 15px; align-items: end;">
                    <?php if ($emEdicaoUser): ?>
                        <input type="hidden" name="id_usuario" value="<?php echo (int)$userEditar['id']; ?>">
                    <?php endif; ?>
                    <div>
                        <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Nome Completo</label>
                        <input type="text" name="nome" required value="<?php echo $emEdicaoUser ? htmlspecialchars($userEditar['nome']) : ''; ?>" placeholder="Ex: Rui Santos" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                    </div>
                    <div>
                        <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Utilizador (Login)</label>
                        <input type="text" name="username" required value="<?php echo $emEdicaoUser ? htmlspecialchars($userEditar['username']) : ''; ?>" placeholder="Ex: rui.santos" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                    </div>
                    <div>
                        <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Email</label>
                        <input type="email" name="email" value="<?php echo $emEdicaoUser ? htmlspecialchars($userEditar['email'] ?? '') : ''; ?>" placeholder="nome.sobrenome@quality.co.ao" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                        <small style="color:var(--text-muted); font-size:11px;">Obrigatório @quality.co.ao (excepto Operador).</small>
                    </div>
                    <div>
                        <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Perfil Hierárquico</label>
                        <select name="perfil" id="sel-perfil-admin" onchange="atualizarDestinoAdmin()" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                            <?php foreach ($perfisForm as $val => $label): ?>
                                <option value="<?php echo htmlspecialchars($val); ?>" <?php echo ($emEdicaoUser && $userEditar['perfil'] === $val) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="bloco-destino-op" style="display:none;">
                        <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Operação</label>
                        <select name="destino" id="sel-destino-op" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                            <option value="">-- Escolher operação --</option>
                            <?php foreach($operacoes_db as $op): ?>
                                <option value="OP_<?php echo $op['id']; ?>" <?php echo $destinoAtual === 'OP_' . $op['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($op['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="bloco-destino-areas" style="grid-column: 1 / -1; display:none;">
                        <label style="display:block; margin-bottom:8px; font-size:12px; color:var(--text-secondary);">Áreas de responsabilidade (pode seleccionar várias)</label>
                        <div style="display:flex; flex-wrap:wrap; gap:10px 18px; padding:12px; background:var(--bg-input); border:1px solid var(--border); border-radius:var(--radius-sm);">
                            <?php foreach ($areas_db as $ar): ?>
                                <label style="font-size:13px; color:var(--text-primary); cursor:pointer; display:flex; align-items:center; gap:6px;">
                                    <input type="checkbox" name="areas[]" value="<?php echo (int)$ar['id']; ?>"
                                        <?php echo in_array((int)$ar['id'], $areasEditar, true) ? 'checked' : ''; ?>>
                                    <?php echo htmlspecialchars($ar['nome']); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <small style="color:var(--text-muted); font-size:11px;">Ex.: um Responsável pode ter Redes &amp; Sistemas e Desenvolvimento ao mesmo tempo.</small>
                    </div>
                    <?php if ($emEdicaoUser): ?>
                    <div>
                        <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Estado</label>
                        <select name="estado" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                            <option value="Ativo" <?php echo $userEditar['estado'] === 'Ativo' ? 'selected' : ''; ?>>Ativo</option>
                            <option value="Pendente" <?php echo $userEditar['estado'] === 'Pendente' ? 'selected' : ''; ?>>Pendente</option>
                            <option value="Inativo" <?php echo $userEditar['estado'] === 'Inativo' ? 'selected' : ''; ?>>Inativo / Recusado</option>
                        </select>
                    </div>
                    <div style="grid-column: 1 / -1;">
                        <label style="display:block; font-size:12px; color:var(--text-secondary); margin-bottom:6px;">Definir nova palavra-passe (opcional)</label>
                        <input type="text" name="nova_senha_admin" placeholder="Deixe em branco para manter a atual" minlength="6" autocomplete="new-password" style="width:100%; padding:10px; background:var(--bg-input); border:1px solid var(--border); color:var(--text-primary); border-radius:var(--radius-sm); box-sizing:border-box;">
                        <small style="color:var(--text-muted); font-size:11px;">Escreva aqui para definir uma senha específica (mín. 6 caracteres).</small>
                    </div>
                    <div style="grid-column: 1 / -1;">
                        <label style="font-size:12px; color:var(--text-secondary); cursor:pointer;">
                            <input type="checkbox" name="repor_senha" value="1" style="margin-right:6px;">
                            Ou repor palavra-passe para <b>123456</b> (obriga troca no próximo login)
                        </label>
                    </div>
                    <?php endif; ?>
                    <div style="display:flex; flex-direction:column; gap:8px;">
                        <?php if ($emEdicaoUser): ?>
                            <button type="submit" name="btn_salvar_usuario" style="padding: 11px 20px; background: var(--amber); border:none; color:#111; border-radius:var(--radius-sm); cursor:pointer; font-weight:600; height: 38px;">Guardar</button>
                            <a href="usuarios_lista.php" style="text-align:center; font-size:12px; color:var(--text-muted);">Cancelar</a>
                        <?php else: ?>
                            <button type="submit" name="btn_criar_usuario" style="padding: 11px 20px; background: var(--accent); border:none; color:white; border-radius:var(--radius-sm); cursor:pointer; font-weight:600; height: 38px;">Registar</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="card" style="padding:0; overflow: hidden;">
                <table style="width: 100%; border-collapse: collapse; font-size: 14px; text-align: left;">
                    <thead>
                        <tr style="background: var(--bg-sidebar); border-bottom: 1px solid var(--border);">
                            <th style="padding: 15px;">Nome</th>
                            <th style="padding: 15px;">Utilizador</th>
                            <th style="padding: 15px;">Perfil</th>
                            <th style="padding: 15px;">Área / Operação</th>
                            <th style="padding: 15px;">Estado</th>
                            <th style="padding: 15px; text-align: center;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lista_users as $user): ?>
                            <tr style="border-bottom: 1px solid var(--border); background: rgba(255,255,255,0.01);">
                                <td style="padding: 15px; font-weight: 500; color:#fff;"><?php echo htmlspecialchars($user['nome']); ?></td>
                                <td style="padding: 15px; color:var(--text-secondary);"><?php echo htmlspecialchars($user['username']); ?></td>
                                <td style="padding: 15px;"><span style="color:var(--text-primary); font-size:13px;"><?php echo htmlspecialchars($user['perfil']); ?></span></td>
                                
                                <td style="padding: 15px; color: var(--text-secondary);">
                                    <?php
                                        if (!empty($user['nome_operacao'])) {
                                            echo '📱 ' . htmlspecialchars($user['nome_operacao']);
                                        } elseif (!empty($user['nomes_areas'])) {
                                            echo htmlspecialchars($user['nomes_areas']);
                                        } elseif (!empty($user['nome_area'])) {
                                            echo htmlspecialchars($user['nome_area']);
                                        } else {
                                            echo "<span style='color:var(--text-muted);'>Nenhuma</span>";
                                        }
                                    ?>
                                </td>

                                <td style="padding: 15px;">
                                    <?php if ($user['estado'] === 'Ativo'): ?>
                                        <span style="color: var(--green); background: rgba(16,185,129,0.1); padding: 2px 8px; border-radius: 10px; font-size: 12px;">● Ativo</span>
                                    <?php elseif ($user['estado'] === 'Pendente'): ?>
                                        <span style="color: var(--amber); background: rgba(245,158,11,0.15); padding: 2px 8px; border-radius: 10px; font-size: 12px;">● Pendente</span>
                                    <?php else: ?>
                                        <span style="color: var(--red); background: rgba(239,68,68,0.1); padding: 2px 8px; border-radius: 10px; font-size: 12px;">● Inativo</span>
                                    <?php endif; ?>
                                </td>

                                <td style="padding: 15px; text-align: center;">
                                    <?php if($user['id'] != 1 && $user['id'] != $_SESSION['user_id']): ?>
                                        <a href="usuarios_lista.php?acao=editar&id=<?php echo $user['id']; ?>#form-user" style="color: var(--accent); text-decoration: none; margin-right: 12px; font-weight: 600; font-size: 13px;">✏️ Editar</a>
                                        <?php if ($user['estado'] === 'Pendente'): ?>
                                            <a href="usuarios_lista.php?acao=aceitar&id=<?php echo $user['id']; ?>&csrf=<?php echo urlencode($tokenCsrfUsers); ?>" style="color: var(--green); text-decoration: none; margin-right: 12px; font-weight: 600; font-size: 13px;" onclick="return confirm('Aceitar e activar esta conta?');">✅ Aceitar</a>
                                            <a href="usuarios_lista.php?acao=recusar&id=<?php echo $user['id']; ?>&csrf=<?php echo urlencode($tokenCsrfUsers); ?>" style="color: var(--red); text-decoration: none; margin-right: 12px; font-weight: 600; font-size: 13px;" onclick="return confirm('Recusar este pedido de conta?');">✕ Recusar</a>
                                        <?php elseif($user['estado'] === 'Ativo'): ?>
                                            <a href="usuarios_lista.php?acao=desativar&id=<?php echo $user['id']; ?>&csrf=<?php echo urlencode($tokenCsrfUsers); ?>" style="color: var(--amber); text-decoration: none; margin-right: 12px; font-weight: 600; font-size: 13px;" onclick="return confirm('Deseja mesmo desativar este utilizador?');">Desativar</a>
                                        <?php else: ?>
                                            <a href="usuarios_lista.php?acao=ativar&id=<?php echo $user['id']; ?>&csrf=<?php echo urlencode($tokenCsrfUsers); ?>" style="color: var(--green); text-decoration: none; margin-right: 12px; font-weight: 600; font-size: 13px;">Ativar</a>
                                        <?php endif; ?>
                                        <a href="usuarios_lista.php?acao=remover&id=<?php echo $user['id']; ?>&csrf=<?php echo urlencode($tokenCsrfUsers); ?>" style="color: var(--red); text-decoration: none; font-weight: 600; font-size: 13px;" onclick="return confirm('ATENÇÃO: Esta ação vai apagar permanentemente o utilizador. Os artigos KB e tickets criados por ele serão reatribuídos ao sistema. Confirmar?');">Remover</a>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 12px; font-style: italic;">Protegido</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <script src="notificacoes.js"></script>
<script>
function atualizarDestinoAdmin() {
    var perfil = document.getElementById('sel-perfil-admin').value;
    var usaOp = (perfil === 'Operador');
    var blocoOp = document.getElementById('bloco-destino-op');
    var blocoAr = document.getElementById('bloco-destino-areas');
    if (!blocoOp || !blocoAr) return;
    blocoOp.style.display = usaOp ? 'block' : 'none';
    blocoAr.style.display = usaOp ? 'none' : 'block';
}
atualizarDestinoAdmin();
</script>
</body>
</html>