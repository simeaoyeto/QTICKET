<?php
/**
 * KIAMI — Autenticação
 *
 * Dois modos de login (por ordem de verificação):
 * 1. Operação — username = nome da operação (ENSA, QCC…); perfil Operador; redireciona para tickets
 * 2. Utilizador interno — username + password_hash na tabela utilizadores
 *
 * Segurança: bloqueio após 5 tentativas; senha 123456 obriga alterar_senha.php
 */

require_once 'conexao.php';

// Já autenticado → dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");

    exit;

}



$erro = "";



if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');

    $password = trim($_POST['password'] ?? '');



    if (!empty($username)) {

        if (verificarBloqueioLogin($pdo, $username)) {

            $erro = "Conta temporariamente bloqueada após várias tentativas inválidas. Tente novamente em 15 minutos.";

        } else {

            // Login por nome de operação → conta Operador (sessão rápida)
            $stmtOp = $pdo->prepare("SELECT id, nome FROM operacoes WHERE LOWER(nome) = LOWER(?)");
            $stmtOp->execute([$username]);

            $operacao = $stmtOp->fetch(PDO::FETCH_ASSOC);



            if ($operacao) {

                $nome = !empty($password) ? $password : "Operador " . $operacao['nome'];

                $userId = garantirUtilizadorSessao($pdo, 'operador.' . strtolower($operacao['nome']), $nome, 'Operador', null, (int)$operacao['id']);



                $_SESSION['user_id'] = $userId;

                $_SESSION['username'] = strtolower($operacao['nome']);

                $_SESSION['nome'] = $nome;

                $_SESSION['perfil'] = 'Operador';

                $_SESSION['id_operacao'] = (int)$operacao['id'];

                $_SESSION['ultimo_acesso'] = time();

                unset($_SESSION['forcar_troca_senha']);



                registarAuditoria($pdo, 'Login', "Login operador/operação: {$operacao['nome']}");

                header("Location: tickets_lista.php");

                exit;

            }



            // Login tradicional com conta na BD
            $stmt = $pdo->prepare("SELECT * FROM utilizadores WHERE username = ?");
            $stmt->execute([$username]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);



            if ($user && password_verify($password, $user['password_hash'])) {

                if (($user['estado'] ?? '') === 'Pendente') {
                    $erro = "A sua conta ainda aguarda aprovação de Redes & Sistemas. Só poderá entrar depois de ser aceite.";
                } elseif (($user['estado'] ?? '') !== 'Ativo') {
                    $erro = "Conta inativa ou recusada. Contacte Redes & Sistemas.";
                } else {

                registarTentativaLogin($pdo, $username, true);



                $_SESSION['user_id'] = (int)$user['id'];

                $_SESSION['username'] = $user['username'];

                $_SESSION['nome'] = $user['nome'];

                $_SESSION['perfil'] = $user['perfil'];

                $_SESSION['id_area'] = $user['id_area'];

                $_SESSION['id_operacao'] = $user['id_operacao'];

                $_SESSION['ultimo_acesso'] = time();



                $pdo->prepare("UPDATE utilizadores SET ultimo_acesso = NOW(), sessao_ativa = 1 WHERE id = ?")->execute([$user['id']]);

                registarAuditoria($pdo, 'Login', "Login utilizador: {$user['username']}");



                // Senha inicial obrigatória a alterar no primeiro acesso
                if (password_verify('123456', $user['password_hash'])) {

                    $_SESSION['forcar_troca_senha'] = true;
                    header("Location: alterar_senha.php");

                    exit;

                }



                unset($_SESSION['forcar_troca_senha']);

                header("Location: index.php");

                exit;

                }

            }



            if (empty($erro)) {
                registarTentativaLogin($pdo, $username, false);
                $erro = "Credenciais inválidas ou conta inativa.";
            }

        }

    } else {

        $erro = "Por favor, preencha todos os campos.";

    }

}

?>

<!DOCTYPE html>

<html lang="pt-PT">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>KIAMI - Login</title>

    <link rel="stylesheet" href="style.css">
    <script src="tema.js"></script>

    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>

<body class="auth-page">

    <div class="auth-card">

        <div style="text-align: center; margin-bottom: 24px;">

            <h2>KIAMI</h2>

            <p class="auth-subtitle">Quality Contact Center</p>

            <p class="auth-subtitle" style="font-style: italic; margin-top: 8px;">Mais do que procura, exactamente o que precisa!</p>

        </div>



        <?php if (!empty($erro)): ?>

            <div class="auth-alert auth-alert-erro"><?php echo htmlspecialchars($erro); ?></div>

        <?php endif; ?>



        <?php if (isset($_GET['erro']) && $_GET['erro'] === 'timeout'): ?>

            <div class="auth-alert auth-alert-aviso">Sessão expirada por inatividade (15 min).</div>

        <?php endif; ?>



        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'senha_alterada'): ?>

            <div class="auth-alert auth-alert-sucesso">Palavra-passe alterada com sucesso. Faça login.</div>

        <?php endif; ?>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'registo_ok'): ?>

            <div class="auth-alert auth-alert-sucesso">Pedido de conta enviado. Aguarde a aprovação de Redes & Sistemas.</div>

        <?php endif; ?>



        <form action="login.php" method="POST">

            <div style="margin-bottom: 16px;">

                <label style="display: block; margin-bottom: 6px; color: #cbd5e1; font-size: 13px;">Utilizador ou Operação</label>

                <input type="text" name="username" required placeholder="Ex: admin, ENSA" class="auth-input">

            </div>

            <div style="margin-bottom: 24px;">

                <label style="display: block; margin-bottom: 6px; color: #cbd5e1; font-size: 13px;">Palavra-passe / Nome do Solicitante</label>

                <input type="password" name="password" required placeholder="Senha ou nome completo" class="auth-input">

            </div>

            <button type="submit" class="auth-btn">Entrar</button>

        </form>



        <div class="auth-links">

            <a href="registo.php">Criar conta</a>

            <span> · </span>

            <a href="recuperar_senha.php">Esqueci a palavra-passe</a>

            <span> · </span>

            <a href="abrir_ticket.php">Abrir ticket sem login</a>

        </div>

    </div>

</body>

</html>

