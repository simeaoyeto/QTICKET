<?php
/**
 * KIAMI — Registo público de conta
 *
 * Qualquer pessoa pode pedir acesso e escolher o perfil.
 * - Operador → escolhe a operação (email opcional)
 * - Restantes → escolhe a área (email obrigatório @quality.co.ao)
 * A conta fica Pendente até Redes & Sistemas (ou Admin) aceitar ou recusar.
 */
require_once 'conexao.php';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$erro = '';
$sucesso = '';

$perfisPublicos = [
    'Operador' => 'Operador (operação)',
    'Comum' => 'Utilizador Comum (área interna)',
    'Tecnico' => 'Técnico',
    'Responsavel' => 'Responsável de Área',
];

try {
    $operacoes = $pdo->query('SELECT id, nome FROM operacoes ORDER BY nome ASC')->fetchAll(PDO::FETCH_ASSOC);
    $areas = $pdo->query('SELECT id, nome FROM areas ORDER BY nome ASC')->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $operacoes = [];
    $areas = [];
}

$perfilSel = trim($_POST['perfil'] ?? '');
$idOperacaoSel = (int)($_POST['id_operacao'] ?? 0);
$idAreaSel = (int)($_POST['id_area'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim($_POST['nome'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $password2 = trim($_POST['password2'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $perfil = mapearPerfilParaBd($perfilSel);

    $usaOperacao = ($perfil === 'Operador');
    $emailObrigatorio = !$usaOperacao;

    if ($nome === '' || $username === '' || $password === '' || $perfil === '') {
        $erro = 'Preencha o nome, utilizador, palavra-passe e escolha o perfil.';
    } elseif (!isset($perfisPublicos[$perfil])) {
        $erro = 'Perfil inválido.';
    } elseif (strlen($username) < 3) {
        $erro = 'O utilizador deve ter pelo menos 3 caracteres.';
    } elseif (!preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
        $erro = 'O utilizador só pode conter letras, números, ponto, hífen ou underscore.';
    } elseif (strlen($password) < 6) {
        $erro = 'A palavra-passe deve ter pelo menos 6 caracteres.';
    } elseif ($password !== $password2) {
        $erro = 'As palavras-passe não coincidem.';
    } else {
        [$emailOk, $emailMsg] = validarEmailConta($email, $emailObrigatorio);
        if (!$emailOk) {
            $erro = $emailMsg;
        } elseif ($usaOperacao && $idOperacaoSel <= 0) {
            $erro = 'Escolha a operação associada à sua conta.';
        } elseif (!$usaOperacao && $idAreaSel <= 0) {
            $erro = 'Escolha a área a que pertence.';
        } else {
            $destinoOk = false;
            if ($usaOperacao) {
                foreach ($operacoes as $op) {
                    if ((int)$op['id'] === $idOperacaoSel) {
                        $destinoOk = true;
                        break;
                    }
                }
                if (!$destinoOk) {
                    $erro = 'Operação inválida.';
                }
            } else {
                foreach ($areas as $ar) {
                    if ((int)$ar['id'] === $idAreaSel) {
                        $destinoOk = true;
                        break;
                    }
                }
                if (!$destinoOk) {
                    $erro = 'Área inválida.';
                }
            }

            if ($erro === '' && $destinoOk) {
                try {
                    $check = $pdo->prepare('SELECT id FROM utilizadores WHERE username = ?');
                    $check->execute([$username]);
                    if ($check->fetch()) {
                        $erro = 'Esse nome de utilizador já está registado. Escolha outro.';
                    } else {
                        $hash = password_hash($password, PASSWORD_BCRYPT);
                        $idArea = $usaOperacao ? null : $idAreaSel;
                        $idOperacao = $usaOperacao ? $idOperacaoSel : null;
                        $emailFinal = $emailOk ? $emailMsg : null; // quando ok, índice 1 é o email

                        $stmt = $pdo->prepare("
                            INSERT INTO utilizadores (nome, username, email, password_hash, perfil, estado, id_area, id_operacao)
                            VALUES (?, ?, ?, ?, ?, 'Pendente', ?, ?)
                        ");
                        $stmt->execute([
                            $nome,
                            $username,
                            $emailFinal,
                            $hash,
                            $perfil,
                            $idArea,
                            $idOperacao,
                        ]);
                        $novoId = (int)$pdo->lastInsertId();

                        if ($idArea) {
                            sincronizarAreasUtilizador($pdo, $novoId, [$idArea]);
                        }

                        criarNotificacao(
                            $pdo,
                            null,
                            1,
                            'Nova conta pendente',
                            "Novo pedido de conta ({$perfil}): {$nome} (@{$username}). Aceite ou recuse em Gestão de Utilizadores.",
                            null
                        );

                        registarAuditoria($pdo, 'Criação', "Pedido de conta #$novoId: $username ($perfil / Pendente)");
                        $sucesso = 'Pedido enviado. A sua conta aguarda aprovação de Redes & Sistemas. Só poderá entrar depois de ser aceite.';
                    }
                } catch (PDOException $e) {
                    $erro = 'Não foi possível criar a conta. Se o erro persistir, contacte o suporte.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KIAMI - Criar Conta</title>
    <link rel="stylesheet" href="style.css">
    <script src="tema.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="auth-page">
    <div class="auth-card" style="max-width: 460px;">
        <div style="text-align: center; margin-bottom: 24px;">
            <h2>KIAMI</h2>
            <p class="auth-subtitle">Criar conta</p>
            <p class="auth-subtitle" style="margin-top: 8px; font-size: 13px;">
                Após o pedido, Redes &amp; Sistemas irá aceitar ou recusar a conta.
            </p>
        </div>

        <?php if ($erro !== ''): ?>
            <div class="auth-alert auth-alert-erro"><?php echo htmlspecialchars($erro); ?></div>
        <?php endif; ?>

        <?php if ($sucesso !== ''): ?>
            <div class="auth-alert auth-alert-sucesso"><?php echo htmlspecialchars($sucesso); ?></div>
            <div class="auth-links" style="margin-top: 16px;">
                <a href="login.php">Ir para o login</a>
            </div>
        <?php else: ?>
            <form action="registo.php" method="POST" id="form-registo">
                <div style="margin-bottom: 14px;">
                    <label style="display:block; margin-bottom:6px; color:#cbd5e1; font-size:13px;">Nome completo</label>
                    <input type="text" name="nome" required value="<?php echo htmlspecialchars($_POST['nome'] ?? ''); ?>" placeholder="O seu nome" class="auth-input">
                </div>
                <div style="margin-bottom: 14px;">
                    <label style="display:block; margin-bottom:6px; color:#cbd5e1; font-size:13px;">Utilizador (login)</label>
                    <input type="text" name="username" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" placeholder="ex: joao.silva" class="auth-input" autocomplete="username">
                </div>
                <div style="margin-bottom: 14px;">
                    <label style="display:block; margin-bottom:6px; color:#cbd5e1; font-size:13px;">Perfil</label>
                    <select name="perfil" id="sel-perfil" required class="auth-input" style="width:100%;" onchange="atualizarDestinoRegisto()">
                        <option value="">-- Escolha o perfil --</option>
                        <?php foreach ($perfisPublicos as $val => $label): ?>
                            <option value="<?php echo htmlspecialchars($val); ?>" <?php echo ($perfilSel === $val) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="bloco-operacao" style="margin-bottom: 14px; display:none;">
                    <label style="display:block; margin-bottom:6px; color:#cbd5e1; font-size:13px;">Operação</label>
                    <select name="id_operacao" id="sel-operacao" class="auth-input" style="width:100%;">
                        <option value="">-- Escolha a operação --</option>
                        <?php foreach ($operacoes as $op): ?>
                            <option value="<?php echo (int)$op['id']; ?>" <?php echo ($idOperacaoSel === (int)$op['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($op['nome']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="display:block; margin-top:6px; color:#94a3b8; font-size:11px;">A operação fica fixa no perfil após a aprovação.</small>
                </div>

                <div id="bloco-area" style="margin-bottom: 14px; display:none;">
                    <label style="display:block; margin-bottom:6px; color:#cbd5e1; font-size:13px;">Área</label>
                    <select name="id_area" id="sel-area" class="auth-input" style="width:100%;">
                        <option value="">-- Escolha a sua área --</option>
                        <?php foreach ($areas as $ar): ?>
                            <option value="<?php echo (int)$ar['id']; ?>" <?php echo ($idAreaSel === (int)$ar['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($ar['nome']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="margin-bottom: 14px;">
                    <label style="display:block; margin-bottom:6px; color:#cbd5e1; font-size:13px;" id="label-email">Email</label>
                    <input type="email" name="email" id="input-email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" placeholder="nome.sobrenome@quality.co.ao" class="auth-input">
                    <small id="hint-email" style="display:block; margin-top:6px; color:#94a3b8; font-size:11px;"></small>
                </div>
                <div style="margin-bottom: 14px;">
                    <label style="display:block; margin-bottom:6px; color:#cbd5e1; font-size:13px;">Palavra-passe</label>
                    <input type="password" name="password" required minlength="6" placeholder="Mínimo 6 caracteres" class="auth-input" autocomplete="new-password">
                </div>
                <div style="margin-bottom: 20px;">
                    <label style="display:block; margin-bottom:6px; color:#cbd5e1; font-size:13px;">Confirmar palavra-passe</label>
                    <input type="password" name="password2" required minlength="6" placeholder="Repita a palavra-passe" class="auth-input" autocomplete="new-password">
                </div>
                <button type="submit" class="auth-btn">Pedir conta</button>
            </form>

            <div class="auth-links" style="margin-top: 18px;">
                <a href="login.php">Já tenho conta — Entrar</a>
            </div>
        <?php endif; ?>
    </div>
<script>
function atualizarDestinoRegisto() {
    var perfil = document.getElementById('sel-perfil').value;
    var usaOp = (perfil === 'Operador');
    var blocoOp = document.getElementById('bloco-operacao');
    var blocoAr = document.getElementById('bloco-area');
    var selOp = document.getElementById('sel-operacao');
    var selAr = document.getElementById('sel-area');
    var inputEmail = document.getElementById('input-email');
    var hint = document.getElementById('hint-email');
    var label = document.getElementById('label-email');
    if (!perfil) {
        blocoOp.style.display = 'none';
        blocoAr.style.display = 'none';
        selOp.required = false;
        selAr.required = false;
        inputEmail.required = false;
        hint.textContent = '';
        return;
    }
    blocoOp.style.display = usaOp ? 'block' : 'none';
    blocoAr.style.display = usaOp ? 'none' : 'block';
    selOp.required = usaOp;
    selAr.required = !usaOp;
    if (usaOp) {
        selAr.value = '';
        inputEmail.required = false;
        label.textContent = 'Email (opcional)';
        hint.textContent = 'Para Operador o email não é obrigatório.';
        inputEmail.placeholder = 'Opcional';
    } else {
        selOp.value = '';
        inputEmail.required = true;
        label.textContent = 'Email (@quality.co.ao) *';
        hint.textContent = 'Obrigatório. Ex: simeao.yeto@quality.co.ao';
        inputEmail.placeholder = 'nome.sobrenome@quality.co.ao';
    }
}
atualizarDestinoRegisto();
</script>
</body>
</html>
