<?php
require_once 'conexao.php';

// Segurança: Se não houver ID na sessão, expulsa para o login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$nome_usuario = $_SESSION['nome'];
$perfil_usuario = $_SESSION['perfil'];

$mensagem = "";

// Processar o formulário de alteração de senha
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_alterar_senha'])) {
    $nova_senha = $_POST['nova_senha'] ?? '';
    $confirma_senha = $_POST['confirma_senha'] ?? '';

    // Validações básicas de segurança
    if (empty($nova_senha) || empty($confirma_senha)) {
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: Preencha todos os campos.</div>";
    } elseif (strlen($nova_senha) < 6) {
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: A nova senha deve ter pelo menos 6 caracteres.</div>";
    } elseif ($nova_senha !== $confirma_senha) {
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: A nova senha e a confirmação não coincidem.</div>";
    } elseif ($nova_senha === '123456') {
        $mensagem = "<div style='background: rgba(245,158,11,0.2); color: var(--amber); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: Não pode reutilizar a senha padrão '123456'. Escolha uma senha segura.</div>";
    } else {
        // Criptografar a nova senha de forma segura
        $senha_cripto = password_hash($nova_senha, PASSWORD_DEFAULT);

        // Atualizar a senha na Base de Dados e garantir que o estado fica 'Ativo'
        $stmt = $pdo->prepare("UPDATE utilizadores SET password_hash = ?, estado = 'Ativo' WHERE id = ?");
        $stmt->execute([$senha_cripto, $user_id]);

        // Redirecionar diretamente para o Dashboard com sucesso
        header("Location: index.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QCCTICKET - Atualizar Senha Obrigatória</title>
    <link rel="stylesheet" href="styles.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body style="display: flex; align-items: center; justify-content: center; min-height: 100vh; background: var(--bg-main); margin: 0; padding: 20px;">

    <div class="card" style="width: 100%; max-width: 420px; padding: 30px; box-shadow: 0 8px 32px rgba(0,0,0,0.24); border-top: 4px solid var(--accent);">
        
        <div style="text-align: center; margin-bottom: 25px;">
            <h2 style="color: #fff; font-size: 22px; margin-bottom: 6px;">Primeiro Acesso Seguro</h2>
            <p style="color: var(--text-secondary); font-size: 13px; line-height: 1.5;">
                Olá, <b><?php echo htmlspecialchars($nome_usuario); ?></b>. Para proteger a sua conta e os dados da Quality Contact Center, é obrigatório alterar a senha inicial.
            </p>
        </div>

        <?php echo $mensagem; ?>

        <form action="alterar_senha.php" method="POST" style="display: flex; flex-direction: column; gap: 18px;">
            
            <div>
                <label style="display: block; margin-bottom: 6px; font-size: 12px; color: var(--text-secondary); font-weight: 500;">Nova Senha</label>
                <input type="password" name="nova_senha" required placeholder="Mínimo 6 caracteres" style="width: 100%; padding: 12px; background: var(--bg-input); border: 1px solid var(--border); color: #fff; border-radius: var(--radius-sm); font-size: 14px;">
            </div>

            <div>
                <label style="display: block; margin-bottom: 6px; font-size: 12px; color: var(--text-secondary); font-weight: 500;">Confirmar Nova Senha</label>
                <input type="password" name="confirma_senha" required placeholder="Repita a nova senha exatamente igual" style="width: 100%; padding: 12px; background: var(--bg-input); border: 1px solid var(--border); color: #fff; border-radius: var(--radius-sm); font-size: 14px;">
            </div>

            <button type="submit" name="btn_alterar_senha" style="width: 100%; padding: 12px; background: var(--accent); border: none; color: white; border-radius: var(--radius-sm); cursor: pointer; font-weight: 600; font-size: 14px; text-align: center; margin-top: 5px; transition: background 0.2s;">
                Salvar Nova Senha e Entrar
            </button>
            
        </form>

        <div style="text-align: center; margin-top: 25px; border-top: 1px solid var(--border); padding-top: 15px;">
            <a href="logout.php" style="color: var(--text-muted); text-decoration: none; font-size: 12px;">Sair do Sistema de Forma Segura</a>
        </div>

    </div>

</body>
</html>