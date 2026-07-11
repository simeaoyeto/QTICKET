<?php
require_once 'conexao.php';

// Se já estiver logado, vai direto para o index/dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$erro = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($username)) {
        // 1. REGRA DAS ÁREAS ADMINISTRATIVAS
        $areasAdministrativas = ['finanças', 'rh', 'qualidade', 'comercial', 'serviços gerais', 'formação'];
        if (in_array(strtolower($username), $areasAdministrativas)) {
            $_SESSION['user_id'] = 'ADM_' . strtoupper(str_replace(' ', '', $username));
            $_SESSION['username'] = strtolower($username);
            $_SESSION['nome'] = !empty($password) ? $password : "Solicitante " . $username;
            $_SESSION['perfil'] = 'Comum';
            $_SESSION['ultimo_acesso'] = time();
            
            header("Location: index.php");
            exit;
        }

        // 2. REGRA DOS CLIENTES OPERACIONAIS
        $stmtOp = $pdo->prepare("SELECT id, nome FROM operacoes WHERE LOWER(nome) = LOWER(?)");
        $stmtOp->execute([$username]);
        $operacao = $stmtOp->fetch(PDO::FETCH_ASSOC);

        if ($operacao) {
            $_SESSION['user_id'] = 'CLIENTE_' . $operacao['id'];
            $_SESSION['username'] = strtolower($operacao['nome']);
            $_SESSION['nome'] = !empty($password) ? $password : "Operador " . $operacao['nome'];
            $_SESSION['perfil'] = 'Cliente';
            $_SESSION['id_operacao'] = $operacao['id'];
            $_SESSION['ultimo_acesso'] = time();

            header("Location: index.php");
            exit;
        }

        // 3. UTILIZADORES INTERNOS REGISTADOS (Admin, Técnico, Responsável)
        $stmt = $pdo->prepare("SELECT * FROM utilizadores WHERE username = ? AND estado = 'Ativo'");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['nome'] = $user['nome'];
            $_SESSION['perfil'] = $user['perfil'];
            $_SESSION['id_area'] = $user['id_area'];
            $_SESSION['ultimo_acesso'] = time();

            // Se a senha for a default '123456', podemos redirecionar para alterar
            if (password_verify('123456', $user['password_hash'])) {
                header("Location: alterar_senha.php");
                exit;
            }

            header("Location: index.php");
            exit;
        } else {
            $erro = "Credenciais inválidas ou conta inativa.";
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
    <title>QCCTICKET - Login</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body class="login-body" style="display: flex; align-items: center; justify-content: center; min-height: 100vh; background-image: linear-gradient(rgba(15, 23, 42, 0.8),
 rgba(15, 23, 42, 0.8)), url('img/fundo-qcc.jpeg'); background-size: cover; background-position: center; background-repeat: no-repeat; background-attachment: fixed; margin: 0;"

style="display: flex; align-items: center; justify-content: center; min-height: 100vh; background: #111;">
    <div class="card" style="width: 100%; max-width: 400px; padding: 30px; background: #222; border-radius: 8px; color: #fff;">
        <div style="text-align: center; margin-bottom: 24px;">
            <h2>QCCTICKET</h2>
            <p style="color: #aaa;">Quality Contact Center</p>
        </div>

        <?php if (!empty($erro)): ?>
            <div style="background: rgba(239,68,68,0.2); color: #f87171; padding: 10px; border-radius: 4px; margin-bottom: 16px; font-size: 14px;">
                <?php echo $erro; ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['erro']) && $_GET['erro'] === 'timeout'): ?>
            <div style="background: rgba(245,158,11,0.2); color: #fbbf24; padding: 10px; border-radius: 4px; margin-bottom: 16px; font-size: 14px;">
                Sessão expirada por inatividade (15 min).
            </div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <div style="margin-bottom: 16px;">
                <label style="display: block; margin-bottom: 6px; color: #ccc;">Utilizador, Área ou Operação</label>
                <input type="text" name="username" required placeholder="Ex: admin, ENSA, RH" style="width: 100%; padding: 10px; background: #333; border: 1px solid #444; color: #fff; border-radius: 4px;">
            </div>
            <div style="margin-bottom: 24px;">
                <label style="display: block; margin-bottom: 6px; color: #ccc;">Palavra-passe / Nome do Solicitante</label>
                <input type="password" name="password" required placeholder="Sua senha ou Seu Nome Completo" style="width: 100%; padding: 10px; background: #333; border: 1px solid #444; color: #fff; border-radius: 4px;">
            </div>
            <button type="submit" style="width: 100%; padding: 12px; background: #3b82f6; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">Entrar</button>
        </form>
    </div>
</body>
</html>