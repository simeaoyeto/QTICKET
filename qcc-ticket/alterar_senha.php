<?php
/**
 * KIAMI — Alteração obrigatória ou voluntária de palavra-passe
 *
 * Acesso quando: senha ainda é 123456 OU sessão com forcar_troca_senha.
 * Apenas utilizadores com conta numérica na BD (não login por área/operação).
 */
require_once 'conexao.php';


if (!isset($_SESSION['user_id'])) {

    header("Location: login.php");

    exit;

}



$user_id_num = idUtilizadorNumerico();

$nome_usuario = $_SESSION['nome'] ?? '';

$perfil_usuario = $_SESSION['perfil'] ?? '';



// Apenas utilizadores internos com conta na BD podem alterar senha (não login por área/operação)
if (!$user_id_num) {

    header("Location: index.php");

    exit;

}



// Verificar se ainda tem senha padrão ou foi forçado a trocar

$stmt = $pdo->prepare("SELECT password_hash FROM utilizadores WHERE id = ? AND estado = 'Ativo'");

$stmt->execute([$user_id_num]);

$hashAtual = $stmt->fetchColumn();



if (!$hashAtual) {

    header("Location: login.php");

    exit;

}



$temSenhaPadrao = password_verify('123456', $hashAtual);

// A troca é OBRIGATÓRIA se ainda usa a senha padrão ou se foi forçada.
// Caso contrário, é uma alteração VOLUNTÁRIA (o utilizador decidiu mudar a senha).
$obrigatorio = $temSenhaPadrao || !empty($_SESSION['forcar_troca_senha']);



$mensagem = '';

$tipo = '';



// --- Processar formulário de nova senha ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_alterar_senha'])) {
    $senha_atual = $_POST['senha_atual'] ?? '';
    $nova_senha = $_POST['nova_senha'] ?? '';
    $confirma_senha = $_POST['confirma_senha'] ?? '';



    if (!$obrigatorio && !password_verify($senha_atual, $hashAtual)) {

        // Numa alteração voluntária exige-se a senha atual como confirmação de identidade
        $mensagem = 'A palavra-passe atual está incorreta.';

        $tipo = 'erro';

    } elseif (empty($nova_senha) || empty($confirma_senha)) {

        $mensagem = 'Preencha todos os campos.';

        $tipo = 'erro';

    } elseif (strlen($nova_senha) < 6) {

        $mensagem = 'A nova palavra-passe deve ter pelo menos 6 caracteres.';

        $tipo = 'erro';

    } elseif ($nova_senha !== $confirma_senha) {

        $mensagem = 'A nova palavra-passe e a confirmação não coincidem.';

        $tipo = 'erro';

    } elseif ($nova_senha === '123456') {

        $mensagem = "Não pode reutilizar a senha padrão '123456'. Escolha uma senha segura.";

        $tipo = 'erro';

    } elseif (password_verify($nova_senha, $hashAtual)) {

        $mensagem = 'A nova palavra-passe deve ser diferente da actual.';

        $tipo = 'erro';

    } else {

        $senha_cripto = password_hash($nova_senha, PASSWORD_DEFAULT);

        $pdo->prepare("UPDATE utilizadores SET password_hash = ?, estado = 'Ativo' WHERE id = ?")

            ->execute([$senha_cripto, $user_id_num]);



        unset($_SESSION['forcar_troca_senha']);

        registarAuditoria($pdo, 'Alteração Senha', "Palavra-passe alterada pelo utilizador #{$user_id_num}");



        header("Location: index.php?msg=senha_atualizada");

        exit;

    }

}

?>

<!DOCTYPE html>

<html lang="pt-PT">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>KIAMI - Alterar Palavra-passe</title>

    <link rel="stylesheet" href="style.css">
    <script src="tema.js"></script>

    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>

<body class="auth-page">

    <div class="auth-card" style="border-top: 4px solid var(--accent);">

        <div style="text-align: center; margin-bottom: 25px;">

            <h2><?php echo $obrigatorio ? 'Primeiro Acesso Seguro' : 'Alterar Palavra-passe'; ?></h2>

            <p class="auth-subtitle" style="line-height: 1.6;">

                Olá, <b><?php echo htmlspecialchars($nome_usuario); ?></b>.<br>

                <?php if ($obrigatorio): ?>

                É obrigatório alterar a palavra-passe inicial antes de continuar.

                <?php else: ?>

                Defina uma nova palavra-passe para a sua conta.

                <?php endif; ?>

            </p>

        </div>



        <?php if ($mensagem): ?>

            <div class="auth-alert auth-alert-<?php echo $tipo === 'erro' ? 'erro' : 'aviso'; ?>">

                <?php echo htmlspecialchars($mensagem); ?>

            </div>

        <?php endif; ?>



        <form action="alterar_senha.php" method="POST" style="display: flex; flex-direction: column; gap: 18px;">

            <?php if (!$obrigatorio): ?>

            <div>

                <label style="display: block; margin-bottom: 6px; font-size: 12px; color: var(--text-secondary); font-weight: 500;">Palavra-passe Atual</label>

                <input type="password" name="senha_atual" required placeholder="A sua palavra-passe atual" class="auth-input">

            </div>

            <?php endif; ?>

            <div>

                <label style="display: block; margin-bottom: 6px; font-size: 12px; color: var(--text-secondary); font-weight: 500;">Nova Palavra-passe</label>

                <input type="password" name="nova_senha" required minlength="6" placeholder="Mínimo 6 caracteres" class="auth-input">

            </div>

            <div>

                <label style="display: block; margin-bottom: 6px; font-size: 12px; color: var(--text-secondary); font-weight: 500;">Confirmar Nova Palavra-passe</label>

                <input type="password" name="confirma_senha" required minlength="6" placeholder="Repita a nova palavra-passe" class="auth-input">

            </div>

            <button type="submit" name="btn_alterar_senha" class="auth-btn">Guardar e Continuar</button>

        </form>



        <div style="text-align: center; margin-top: 25px; border-top: 1px solid var(--border); padding-top: 15px;">

            <a href="logout.php" style="color: var(--text-muted); text-decoration: none; font-size: 12px;">🚪 Sair do sistema</a>

        </div>

    </div>

</body>

</html>

