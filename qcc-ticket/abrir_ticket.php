<?php
/**
 * KIAMI — Abertura pública de ticket (sem login)
 *
 * Página acessível sem autenticação. O criador fica como sistema.convidado.
 * O solicitante pode escolher qualquer área/departamento de destino.
 */
require_once 'conexao.php';

$mensagem = '';
$sucesso = false;
$codigoGerado = '';

// Mostrar TODAS as áreas disponíveis para o solicitante escolher o destino
$areas_destino = $pdo->query("SELECT id, nome FROM areas ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
// Operações (origem do ticket) para identificar a operação do solicitante
$operacoes_origem = $pdo->query("SELECT id, nome FROM operacoes ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $slaAbertura = resolverPrioridadeESlaAbertura($_POST);
    $titulo = $slaAbertura['titulo'] !== '' ? $slaAbertura['titulo'] : resolverAssuntoTicket($_POST, $pdo);
    $descricao = trim($_POST['descricao'] ?? '');
    $prioridade = $slaAbertura['prioridade'];
    $impacto = $slaAbertura['impacto'];
    $urgencia = $slaAbertura['urgencia'];
    $dataLimite = $slaAbertura['data_limite_sla'];
    $dataLimiteResp = $slaAbertura['data_limite_resposta'];
    $id_area_destino = (int)($_POST['id_area_destino'] ?? 0);
    $id_operacao_origem = (int)($_POST['id_operacao_origem'] ?? 0);

    // Processar imagem opcional (guardada em uploads/tickets)
    $upload = processarUploadImagem($_FILES['anexo'] ?? [], 'tickets');

    if (!$upload['sucesso']) {
        $mensagem = $upload['erro'];
    } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // O email é obrigatório para podermos enviar avisos automáticos ao solicitante
        $mensagem = 'Indique um endereço de email válido para receber atualizações do ticket.';
    } elseif ($id_operacao_origem <= 0) {
        $mensagem = 'Selecione a operação a que pertence.';
    } elseif (!empty($nome) && !empty($titulo) && !empty($descricao) && $id_area_destino > 0) {
        $codigo = gerarCodigoTicket($pdo);
        $idSistema = obterIdUtilizadorSistema($pdo);

        $stmt = $pdo->prepare("
            INSERT INTO tickets (codigo, titulo, nome_solicitante, email_solicitante, descricao, prioridade, impacto, urgencia, estado, anexo, id_criador, id_area_destino, id_operacao_origem, data_criacao, data_limite_sla, data_limite_resposta)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Aberto', ?, ?, ?, ?, NOW(), ?, ?)
        ");
        // Tenta inserir; se o índice UNIQUE rejeitar o código (concorrência), regenera e repete
        $tentativas = 0;
        while (true) {
            try {
                $stmt->execute([$codigo, $titulo, $nome, $email, $descricao, $prioridade, $impacto, $urgencia, $upload['caminho'], $idSistema, $id_area_destino, $id_operacao_origem, $dataLimite, $dataLimiteResp]);
                break;
            } catch (PDOException $e) {
                if (str_contains($e->getMessage(), 'Unknown column') || str_contains($e->getMessage(), 'impacto')) {
                    $stmt = $pdo->prepare("
                        INSERT INTO tickets (codigo, titulo, nome_solicitante, email_solicitante, descricao, prioridade, estado, anexo, id_criador, id_area_destino, id_operacao_origem, data_criacao, data_limite_sla)
                        VALUES (?, ?, ?, ?, ?, ?, 'Aberto', ?, ?, ?, ?, NOW(), ?)
                    ");
                    $stmt->execute([$codigo, $titulo, $nome, $email, $descricao, $prioridade, $upload['caminho'], $idSistema, $id_area_destino, $id_operacao_origem, $dataLimite]);
                    break;
                }
                if ($e->getCode() === '23000' && $tentativas < 5) {
                    $tentativas++;
                    $codigo = gerarCodigoTicket($pdo);
                    continue;
                }
                throw $e;
            }
        }
        $novoId = (int)$pdo->lastInsertId();

        registarHistoricoTicket($pdo, $novoId, null, 'Criação', "Ticket público criado por $nome ($codigo)");
        registarAuditoria($pdo, 'Criação', "Ticket público $codigo criado por $nome");
        notificarDestinosNovoTicket($pdo, $novoId);
        notificarSolicitanteNovoTicket($pdo, $novoId);

        $sucesso = true;
        $codigoGerado = $codigo;
    } else {
        $mensagem = 'Preencha todos os campos obrigatórios.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KIAMI - Abrir Ticket</title>
    <link rel="stylesheet" href="style.css">
    <script src="tema.js"></script></head>
<body style="display: flex; align-items: center; justify-content: center; min-height: 100vh; background: #0f172a; margin: 0; padding: 20px;">
    <div class="card" style="width: 100%; max-width: 520px; padding: 30px; background: #1e293b; border-radius: 8px; color: #fff;">
        <div style="text-align: center; margin-bottom: 24px;">
            <h2>🎫 Abrir Ticket de Suporte</h2>
            <p style="color: #94a3b8; font-size: 14px;">Não precisa de login. Guarde o código gerado para consultar o estado depois.</p>
        </div>

        <?php if ($sucesso): ?>
            <div style="background: rgba(34,197,94,0.2); color: #4ade80; padding: 16px; border-radius: 6px; margin-bottom: 20px; text-align: center;">
                <p style="margin: 0 0 8px 0;">Ticket registado com sucesso!</p>
                <p style="font-size: 22px; font-weight: bold; margin: 0;"><?php echo htmlspecialchars($codigoGerado); ?></p>
                <p style="font-size: 13px; margin-top: 10px; color: #86efac;">Enviámos uma confirmação para o seu email. Será avisado a cada atualização do ticket.</p>
            </div>
            <div style="text-align: center;">
                <a href="login.php" style="color: #60a5fa;">Ir para Login</a>
            </div>
        <?php else: ?>
            <?php if ($mensagem): ?>
                <div style="background: rgba(239,68,68,0.2); color: #f87171; padding: 10px; border-radius: 4px; margin-bottom: 16px; font-size: 14px;">
                    <?php echo htmlspecialchars($mensagem); ?>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: 14px;">
                <div>
                    <label style="display: block; margin-bottom: 6px; color: #cbd5e1; font-size: 13px;">Nome *</label>
                    <input type="text" name="nome" required value="<?php echo htmlspecialchars($_POST['nome'] ?? ''); ?>" placeholder="O seu nome completo" style="width: 100%; padding: 10px; background: #334155; border: 1px solid #475569; color: #fff; border-radius: 4px; box-sizing: border-box;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 6px; color: #cbd5e1; font-size: 13px;">E-mail *</label>
                    <input type="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" placeholder="seu.email@exemplo.com" style="width: 100%; padding: 10px; background: #334155; border: 1px solid #475569; color: #fff; border-radius: 4px; box-sizing: border-box;">
                    <small style="color: #94a3b8; font-size: 11px;">Enviaremos para aqui a confirmação e todas as atualizações do ticket.</small>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 6px; color: #cbd5e1; font-size: 13px;">Operação *</label>
                    <select name="id_operacao_origem" required style="width: 100%; padding: 10px; background: #334155; border: 1px solid #475569; color: #fff; border-radius: 4px;">
                        <option value="">-- Selecionar --</option>
                        <?php foreach ($operacoes_origem as $op): ?>
                            <option value="<?php echo $op['id']; ?>" <?php echo (($_POST['id_operacao_origem'] ?? '') == $op['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($op['nome']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color: #94a3b8; font-size: 11px;">Identifique a operação onde trabalha (ex: ENSA, BAI, INACOM).</small>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 6px; color: #cbd5e1; font-size: 13px;">Área / Departamento *</label>
                    <select name="id_area_destino" required style="width: 100%; padding: 10px; background: #334155; border: 1px solid #475569; color: #fff; border-radius: 4px;">
                        <option value="">-- Selecionar --</option>
                        <?php foreach ($areas_destino as $area): ?>
                            <option value="<?php echo $area['id']; ?>" <?php echo (($_POST['id_area_destino'] ?? '') == $area['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($area['nome']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <?php
                        $assuntosAberturaForm = ($_SERVER['REQUEST_METHOD'] === 'POST')
                            ? obterAssuntosSubmetidosTicket($_POST)
                            : [];
                        $estiloPub = 'width: 100%; padding: 10px; background: #334155; border: 1px solid #475569; color: #fff; border-radius: 4px; box-sizing: border-box;';
                        echo htmlSeletorAssuntoCascata($pdo, '', 'pub', $estiloPub, true, $assuntosAberturaForm);
                    ?>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <div>
                        <label style="display: block; margin-bottom: 6px; color: #cbd5e1; font-size: 13px;">Impacto *</label>
                        <select name="impacto" style="width: 100%; padding: 10px; background: #334155; border: 1px solid #475569; color: #fff; border-radius: 4px;">
                            <option value="Utilizador">Utilizador</option>
                            <option value="Equipa">Equipa</option>
                            <option value="Operação">Operação</option>
                            <option value="Empresa">Empresa</option>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 6px; color: #cbd5e1; font-size: 13px;">Urgência</label>
                        <select name="urgencia" style="width: 100%; padding: 10px; background: #334155; border: 1px solid #475569; color: #fff; border-radius: 4px;">
                            <option value="">Automática</option>
                            <option value="Baixa">Baixa</option>
                            <option value="Média">Média</option>
                            <option value="Alta">Alta</option>
                            <option value="Crítica">Crítica</option>
                        </select>
                    </div>
                </div>
                <small style="color: #94a3b8; font-size: 11px;">Prioridade e SLA são calculados automaticamente.</small>
                <div>
                    <label style="display: block; margin-bottom: 6px; color: #cbd5e1; font-size: 13px;">Descrição *</label>
                    <textarea name="descricao" required rows="5" placeholder="Descreva o problema em detalhe..." style="width: 100%; padding: 10px; background: #334155; border: 1px solid #475569; color: #fff; border-radius: 4px; resize: vertical; font-family: inherit; box-sizing: border-box;"><?php echo htmlspecialchars($_POST['descricao'] ?? ''); ?></textarea>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 6px; color: #cbd5e1; font-size: 13px;">Imagem (opcional)</label>
                    <input type="file" name="anexo" accept="image/png, image/jpeg, image/gif, image/webp" style="width: 100%; padding: 8px; background: #334155; border: 1px solid #475569; color: #cbd5e1; border-radius: 4px; box-sizing: border-box; font-size: 13px;">
                    <small style="color: #94a3b8; font-size: 11px;">Anexe uma captura de ecrã do problema (JPG, PNG, GIF ou WEBP, máx. 5 MB).</small>
                </div>
                <button type="submit" style="padding: 12px; background: #3b82f6; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">Submeter Ticket</button>
            </form>

            <div style="margin-top: 20px; text-align: center; font-size: 13px;">
                <a href="login.php" style="color: #60a5fa; text-decoration: none;">Já tem conta? Fazer login</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
