<?php
/**
 * KIAMI — Reabertura pública de ticket (sem login)
 *
 * Acessível a partir do link do email de resolução. Valida o par id+token e,
 * se o ticket estiver Resolvido, permite ao solicitante reabri-lo indicando o
 * motivo (o problema persiste). Reabrir volta o estado a «Aberto», regista
 * comentário/histórico e notifica a equipa responsável.
 */
require_once 'conexao.php';

$ticket_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
if ($ticket_id <= 0 && isset($_POST['id'])) {
    $ticket_id = (int)$_POST['id'];
}

$erro = '';
$sucesso = false;
$ticket = null;

// Validar ticket + token
if ($ticket_id > 0 && $token !== '') {
    try {
        $stmt = $pdo->prepare("SELECT * FROM tickets WHERE id = ? LIMIT 1");
        $stmt->execute([$ticket_id]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        $ticket = null;
    }
}

$tokenValido = $ticket
    && !empty($ticket['token_acompanhamento'])
    && hash_equals((string)$ticket['token_acompanhamento'], $token);

if (!$tokenValido) {
    $erro = 'Ligação inválida ou expirada. Verifique se copiou o link completo do email, ou contacte o suporte.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_reabrir'])) {
    $motivo = trim($_POST['motivo'] ?? '');
    if (($ticket['estado'] ?? '') !== 'Resolvido') {
        $erro = 'Este ticket já não está resolvido — a equipa já o está a tratar. Não é necessário reabrir.';
    } elseif ($motivo === '') {
        $erro = 'Descreva brevemente o que continua por resolver.';
    } else {
        try {
            $pdo->prepare("UPDATE tickets SET estado = 'Aberto', data_resolucao = NULL WHERE id = ?")
                ->execute([$ticket_id]);

            $idCriador = (int)($ticket['id_criador'] ?? 0);
            $nomeSolic = $ticket['nome_solicitante'] ?: 'Solicitante';
            $comentario = "[Reaberto pelo solicitante] {$motivo}";

            if ($idCriador > 0) {
                try {
                    $pdo->prepare("INSERT INTO comentarios (id_ticket, id_utilizador, comentario, data_envio) VALUES (?, ?, ?, NOW())")
                        ->execute([$ticket_id, $idCriador, $comentario]);
                } catch (PDOException $e) {
                    // comentário é acessório — não bloqueia a reabertura
                }
            }

            registarHistoricoTicket($pdo, $ticket_id, $idCriador > 0 ? $idCriador : null, 'Reabertura', mb_substr($motivo, 0, 200));
            registarAuditoria($pdo, 'Alteração', "Ticket #{$ticket_id} reaberto pelo solicitante ({$nomeSolic})");

            // Avisar a equipa responsável (na plataforma) e a caixa da área por email
            notificarPlataformaAtualizacaoTicket(
                $pdo,
                $ticket_id,
                'Ticket reaberto',
                'O solicitante reabriu o ticket: ' . mb_substr($motivo, 0, 150),
                null
            );
            $idArea = (int)($ticket['id_area_destino'] ?? 0);
            if ($idArea > 0) {
                try {
                    enviarEmailCaixaAreaTicket($pdo, $idArea, $ticket);
                } catch (Throwable $e) {
                    // email é acessório
                }
            }

            $sucesso = true;
            // Recarregar estado actualizado para a UI
            $ticket['estado'] = 'Aberto';
        } catch (PDOException $e) {
            $erro = 'Não foi possível reabrir o ticket neste momento. Tente novamente mais tarde.';
        }
    }
}

$codigo = $ticket['codigo'] ?? ('#' . $ticket_id);
$titulo = $ticket['titulo'] ?? '';
$estadoActual = $ticket['estado'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KIAMI — Reabrir Ticket</title>
</head>
<body style="display:flex; align-items:center; justify-content:center; min-height:100vh; background:#0f172a; margin:0; padding:20px; font-family:Arial, sans-serif;">
    <div style="width:100%; max-width:520px; padding:30px; background:#1e293b; border-radius:8px; color:#fff; border-top:4px solid #ef4444;">
        <h2 style="margin:0 0 4px;">KIAMI</h2>
        <p style="color:#94a3b8; font-size:13px; margin:0 0 22px;">Quality Contact Center — Suporte</p>

        <?php if ($sucesso): ?>
            <div style="background:rgba(34,197,94,0.15); color:#4ade80; padding:18px; border-radius:6px; text-align:center;">
                <div style="font-size:34px; line-height:1; margin-bottom:10px;">&#10003;</div>
                <h3 style="margin:0 0 8px;">Ticket reaberto</h3>
                <p style="margin:0; font-size:14px; color:#bbf7d0;">
                    O ticket <strong><?php echo htmlspecialchars($codigo); ?></strong> foi reaberto e a equipa responsável foi notificada.
                    Vai receber novas atualizações por email.
                </p>
            </div>
        <?php elseif ($erro && !$tokenValido): ?>
            <div style="background:rgba(239,68,68,0.15); color:#f87171; padding:16px; border-radius:6px; font-size:14px;">
                <?php echo htmlspecialchars($erro); ?>
            </div>
        <?php else: ?>
            <?php if ($erro): ?>
                <div style="background:rgba(239,68,68,0.15); color:#f87171; padding:12px; border-radius:6px; font-size:14px; margin-bottom:16px;">
                    <?php echo htmlspecialchars($erro); ?>
                </div>
            <?php endif; ?>

            <?php if ($estadoActual !== 'Resolvido' && !$erro): ?>
                <div style="background:rgba(59,130,246,0.15); color:#93c5fd; padding:16px; border-radius:6px; font-size:14px;">
                    O ticket <strong><?php echo htmlspecialchars($codigo); ?></strong> está no estado
                    <strong><?php echo htmlspecialchars($estadoActual ?: 'em tratamento'); ?></strong> — já está a ser tratado, não é necessário reabrir.
                </div>
            <?php else: ?>
                <p style="color:#cbd5e1; font-size:14px; line-height:1.6; margin:0 0 6px;">
                    O seu ticket foi marcado como <strong style="color:#4ade80;">Resolvido</strong>.
                    Se o problema continua, descreva o que falta e reabra-o.
                </p>
                <div style="background:#334155; border-radius:6px; padding:14px 16px; margin:14px 0;">
                    <p style="margin:0 0 4px; color:#94a3b8; font-size:12px;">Código do ticket</p>
                    <p style="margin:0 0 8px; color:#f87171; font-size:18px; font-weight:bold;"><?php echo htmlspecialchars($codigo); ?></p>
                    <p style="margin:0; color:#e2e8f0; font-size:14px;"><?php echo htmlspecialchars($titulo); ?></p>
                </div>
                <form method="POST" action="reabrir_ticket.php">
                    <input type="hidden" name="id" value="<?php echo (int)$ticket_id; ?>">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES); ?>">
                    <label style="display:block; margin-bottom:6px; font-size:13px; color:#cbd5e1;">O que continua por resolver?</label>
                    <textarea name="motivo" required rows="5" placeholder="Descreva o que ainda não ficou resolvido..." style="width:100%; padding:10px; background:#334155; border:1px solid #475569; color:#fff; border-radius:4px; resize:vertical; font-family:inherit; box-sizing:border-box;"><?php echo htmlspecialchars($_POST['motivo'] ?? ''); ?></textarea>
                    <button type="submit" name="btn_reabrir" value="1" style="margin-top:16px; width:100%; padding:12px; background:#ef4444; color:#fff; border:none; border-radius:4px; cursor:pointer; font-weight:bold; font-size:15px;">Reabrir ticket</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <hr style="border:none; border-top:1px solid #334155; margin:24px 0 14px;">
        <p style="color:#64748b; font-size:12px; text-align:center; margin:0;">Quality Contact Center — KIAMI</p>
    </div>
</body>
</html>
