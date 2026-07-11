<?php
require_once 'conexao.php';
echo "<h1>ESTOU NO FICHEIRO CORRETO</h1>";
exit;

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$nome_usuario = $_SESSION['nome'];
$perfil_usuario = $_SESSION['perfil'];

// Perfis autorizados
$perfisPermitidos = ['Admin', 'Responsavel', 'Tecnico'];

if (!in_array($perfil_usuario, $perfisPermitidos)) {
    die("Acesso negado. Não tem permissão para gerir utilizadores.");
}

// =========================================================
// PROCESSAR AÇÕES: DESATIVAR, ATIVAR E REMOVER (GET)
// =========================================================
if (isset($_GET['acao']) && isset($_GET['id'])) {
    $id_alvo = (int)$_GET['id'];
    $acao = $_GET['acao'];

    // Impedir que o admin se desative ou se remova a si próprio
    if ($id_alvo == $_SESSION['user_id'] || $id_alvo == 1) {
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: Não pode alterar ou remover a conta Master do Administrador.</div>";
    } else {
        if ($acao === 'desativar') {
            $stmt = $pdo->prepare("UPDATE utilizadores SET estado = 'Inativo' WHERE id = ?");
            $stmt->execute([$id_alvo]);
            $mensagem = "<div style='background: rgba(245,158,11,0.2); color: var(--amber); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Utilizador desativado com sucesso!</div>";
        } elseif ($acao === 'ativar') {
            $stmt = $pdo->prepare("UPDATE utilizadores SET estado = 'Ativo' WHERE id = ?");
            $stmt->execute([$id_alvo]);
            $mensagem = "<div style='background: rgba(34,197,94,0.2); color: var(--green); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Utilizador ativado com sucesso!</div>";
        } elseif ($acao === 'remover') {
            $stmt = $pdo->prepare("DELETE FROM utilizadores WHERE id = ?");
            $stmt->execute([$id_alvo]);
            $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Utilizador removido permanentemente do sistema!</div>";
        }
    }
}

// =========================================================
// PROCESSAR CRIAÇÃO DE NOVO UTILIZADOR (POST)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_criar_usuario'])) {
    $nome = trim($_POST['nome'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $perfil = trim($_POST['perfil'] ?? 'Tecnico');
    $destino = trim($_POST['destino'] ?? ''); // Recebe "OP_id" ou "AR_id"

    $id_area = null;
    $id_operacao = null;

    // Separar se o admin escolheu uma Operação ou uma Área Administrativa
    if (strpos($destino, 'OP_') === 0) {
        $id_operacao = (int)str_replace('OP_', '', $destino);
    } elseif (strpos($destino, 'AR_') === 0) {
        $id_area = (int)str_replace('AR_', '', $destino);
    }

    // Palavra-passe predefinida
    $senha_default_hash = password_hash('123456', PASSWORD_BCRYPT);

    if (!empty($nome) && !empty($username)) {
        // Verificar duplicados
        $check = $pdo->prepare("SELECT id FROM utilizadores WHERE username = ?");
        $check->execute([$username]);
        
        if ($check->fetch()) {
            $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: Esse nome de utilizador já está registado.</div>";
        } else {
            $stmt = $pdo->prepare("INSERT INTO utilizadores (nome, username, password_hash, perfil, estado, id_area, id_operacao) VALUES (?, ?, ?, ?, 'Ativo', ?, ?)");
            $stmt->execute([$nome, $username, $senha_default_hash, $perfil, $id_area, $id_operacao]);
            $mensagem = "<div style='background: rgba(34,197,94,0.2); color: var(--green); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Utilizador <b>$username</b> criado com sucesso! Senha padrão: 123456</div>";
        }
    }
}

// Procurar Áreas e Operações dinamicamente para os selects
$areas_db = $pdo->query("SELECT id, nome FROM areas ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
$operacoes_db = $pdo->query("SELECT id, nome FROM operacoes ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);

// Listar utilizadores trazendo os nomes das áreas e operações associadas
$query_users = "
    SELECT u.*, a.nome AS nome_area, o.nome AS nome_operacao 
    FROM utilizadores u
    LEFT JOIN areas a ON u.id_area = a.id
    LEFT JOIN operacoes o ON u.id_operacao = o.id
    ORDER BY u.id DESC
";
$lista_users = $pdo->query($query_users)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <title>QCCTICKET - Gestão de Utilizadores</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="app-layout">
        <div id="sidebar">
            <div class="sidebar-brand"><h3>QCCTICKET</h3><span>Quality Support</span></div>
            <div class="nav-section-title">Geral</div>
            <a href="index.php" class="nav-item">📊 <span>Dashboard</span></a>
            <a href="tickets_lista.php" class="nav-item">🎫 <span>Meus Tickets</span></a>
            <a href="kb_lista.php" class="nav-item">📚 <span>Base Conhecimento</span></a>
            <a href="empresa.php" class="nav-item">🏢 <span>A Empresa</span></a>
            <div class="nav-section-title">Administração</div>
            <a href="usuarios_lista.php" class="nav-item active">👥 <span>Gestão de Users</span></a>
            <a href="relatorios.php" class="nav-item">📈 <span>Relatórios</span></a>
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
                <h1>Controlo de Utilizadores</h1>
                <p>Registe novos membros, associe-os a departamentos ou operações e gira os seus acessos.</p>
            </div>

            <?php echo $mensagem; ?>

            <div class="card" style="margin-bottom: 30px;">
                <h3 style="margin-bottom: 15px; font-size: 16px; color: var(--accent);">👥 Criar Nova Conta</h3>
                <form action="usuarios_lista.php" method="POST" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)) auto; gap: 15px; align-items: end;">
                    <div>
                        <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Nome Completo</label>
                        <input type="text" name="nome" required placeholder="Ex: Rui Santos" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                    </div>
                    <div>
                        <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Utilizador (Login)</label>
                        <input type="text" name="username" required placeholder="Ex: rui.santos" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                    </div>
                    <div>
                        <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Perfil Hierárquico</label>
                        <select name="perfil" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                            <option value="Utilizador Comum">Utilizador Comum</option>
                            <option value="Tecnico">Técnico</option>
                            <option value="Responsavel">Responsável de Área</option>
                            <option value="Diretor Geral">Diretor Geral</option>
                            <option value="Admin">Administrador</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Área / Operação</label>
                        <select name="destino" required style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                            <option value="">-- Selecione uma opção --</option>
                            
                            <optgroup label="🏢 Áreas Administrativas">
                                <?php foreach($areas_db as $ar): ?>
                                    <option value="AR_<?php echo $ar['id']; ?>"><?php echo htmlspecialchars($ar['nome']); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            
                            <optgroup label="📱 Clientes Operacionais">
                                <?php foreach($operacoes_db as $op): ?>
                                    <option value="OP_<?php echo $op['id']; ?>"><?php echo htmlspecialchars($op['nome']); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                    <button type="submit" name="btn_criar_usuario" style="padding: 11px 20px; background: var(--accent); border:none; color:white; border-radius:var(--radius-sm); cursor:pointer; font-weight:600; height: 38px;">Registar</button>
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
                                        if(!empty($user['nome_area'])) {
                                            echo "🏢 " . htmlspecialchars($user['nome_area']);
                                        } elseif(!empty($user['nome_operacao'])) {
                                            echo "📱 " . htmlspecialchars($user['nome_operacao']);
                                        } else {
                                            echo "<span style='color:var(--text-muted);'>Nenhuma</span>";
                                        }
                                    ?>
                                </td>

                                <td style="padding: 15px;">
                                    <?php if ($user['estado'] === 'Ativo'): ?>
                                        <span style="color: var(--green); background: rgba(16,185,129,0.1); padding: 2px 8px; border-radius: 10px; font-size: 12px;">● Ativo</span>
                                    <?php else: ?>
                                        <span style="color: var(--red); background: rgba(239,68,68,0.1); padding: 2px 8px; border-radius: 10px; font-size: 12px;">● Inativo</span>
                                    <?php endif; ?>
                                </td>

                                <td style="padding: 15px; text-align: center;">
                                    <?php if($user['id'] != 1 && $user['id'] != $_SESSION['user_id']): ?>
                                        
                                        <?php if($user['estado'] === 'Ativo'): ?>
                                            <a href="usuarios_lista.php?acao=desativar&id=<?php echo $user['id']; ?>" style="color: var(--amber); text-decoration: none; margin-right: 12px; font-weight: 600; font-size: 13px;" onclick="return confirm('Deseja mesmo desativar este utilizador?');">Desativar</a>
                                        <?php else: ?>
                                            <a href="usuarios_lista.php?acao=ativar&id=<?php echo $user['id']; ?>" style="color: var(--green); text-decoration: none; margin-right: 12px; font-weight: 600; font-size: 13px;">Ativar</a>
                                        <?php endif; ?>

                                        <a href="usuarios_lista.php?acao=remover&id=<?php echo $user['id']; ?>" style="color: var(--red); text-decoration: none; font-weight: 600; font-size: 13px;" onclick="return confirm('ATENÇÃO: Esta ação vai apagar permanentemente o utilizador. Confirmar?');">Remover</a>
                                    
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
</body>
</html>