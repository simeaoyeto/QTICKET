<?php
/**
 * KIAMI — Detalhe de um ticket
 *
 * Comentários, alteração de estado, atribuição de técnico, assumir ticket,
 * histórico e validação de acesso via podeVerTicket().
 */
require_once 'conexao.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$nome_usuario = $_SESSION['nome'];
$perfil_usuario = $_SESSION['perfil'];
$contexto = obterContextoUsuario($pdo);
$user_id_num = $contexto['user_id_numerico'];

$ticket_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($ticket_id <= 0) {
    header("Location: tickets_lista.php");
    exit;
}

// Carregar ticket cedo para validar permissões antes de qualquer ação
$stmtEarly = $pdo->prepare("SELECT * FROM tickets WHERE id = ?");
$stmtEarly->execute([$ticket_id]);
$ticketEarly = $stmtEarly->fetch(PDO::FETCH_ASSOC);
if (!$ticketEarly || !podeVerTicket($ticketEarly, $contexto, $pdo)) {
    die("Acesso negado. Não tem permissão para ver este ticket.");
}

// Separação de funções: quem ABRE o ticket não o pode RESOLVER.
// Serve para garantir imparcialidade — o solicitante apenas acompanha,
// comenta e (quando resolvido por outra pessoa) confirma o encerramento.
$souCriador = ($user_id_num > 0 && (int)($ticketEarly['id_criador'] ?? 0) === $user_id_num);

// Só quem pertence à ÁREA DE DESTINO ATUAL do ticket (ou o técnico atribuído/Admin)
// pode tratá-lo. Após um reencaminhamento, a equipa de origem perde este direito.
$podeTratar = podeTratarTicket($ticketEarly, $contexto);

$mensagem = "";

// =========================================================
// PROCESSAR SUBMISSÃO DE COMENTÁRIO/MENSAGEM (POST)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_comentar'])) {
    $comentario = trim($_POST['comentario'] ?? '');
    
    if (!empty($comentario) && $user_id_num) {
        $stmt_com = $pdo->prepare("INSERT INTO comentarios (id_ticket, id_utilizador, comentario, data_envio) VALUES (?, ?, ?, NOW())");
        $stmt_com->execute([$ticket_id, $user_id_num, $comentario]);
        registarHistoricoTicket($pdo, $ticket_id, $user_id_num, 'Comentário', mb_substr($comentario, 0, 100));
        notificarPlataformaAtualizacaoTicket($pdo, $ticket_id, 'Novo Comentário', 'Novo comentário: ' . mb_substr($comentario, 0, 120), $user_id_num);
        // Avisar o solicitante por email — exceto se foi ele próprio a comentar
        if (!$souCriador) {
            notificarSolicitanteAtualizacaoTicket($pdo, $ticket_id, 'Novo comentário', mb_substr($comentario, 0, 150));
        }
        $mensagem = "<div style='background: rgba(34,197,94,0.15); color: var(--green); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>Comentário adicionado!</div>";
    }
}

// =========================================================
// PROCESSAR ALTERAÇÃO DE ESTADO / ATRIBUIÇÃO (POST)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_alterar_estado'])) {
    $novo_estado = $_POST['novo_estado'] ?? '';
    // «Reencaminhado» só via formulário de reencaminhamento (muda área); não aqui
    $estadosValidos = ['Aberto', 'Em Progresso', 'Resolvido'];

    if (!in_array($novo_estado, $estadosValidos, true)) {
        $mensagem = "<div style='background: rgba(239,68,68,0.15); color: var(--red); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>Estado inválido. Para reencaminhar use a opção «Reencaminhar» (escolhe a área de destino).</div>";
    } elseif ($souCriador) {
        // Quem abre o ticket não pode alterar o fluxo; apenas acompanhar e comentar
        $mensagem = "<div style='background: rgba(239,68,68,0.15); color: var(--red); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>Não pode alterar o fluxo de um ticket que foi aberto por si. A resolução cabe à equipa responsável; pode apenas acompanhar e comentar.</div>";
    } elseif (!podeResolverTickets($contexto)) {
        // Convidado e Operador apenas abrem e acompanham — não resolvem
        $mensagem = "<div style='background: rgba(239,68,68,0.15); color: var(--red); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>Não tem permissão para alterar o estado do ticket. O seu perfil apenas permite abrir e acompanhar tickets.</div>";
    } elseif (!$podeTratar) {
        // Ticket já reencaminhado para outra área — o tratamento cabe à equipa que o recebeu
        $mensagem = "<div style='background: rgba(239,68,68,0.15); color: var(--red); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>Este ticket pertence agora a outra área. O tratamento cabe à equipa que o recebeu; pode apenas acompanhar e comentar.</div>";
    } elseif ($novo_estado === 'Resolvido' && ticketENovaEntradaRedes($ticketEarly)) {
        $mensagem = "<div style='background: rgba(245,158,11,0.15); color: var(--amber); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>Para concluir uma <b>Nova entrada</b>, use a checklist: seleccione o que foi feito e indique se ficou algo pendente. Se não ficou pendente, o ticket passa a Resolvido automaticamente.</div>";
    } else {
        $estadoAnterior = $ticketEarly['estado'] ?? '';
        $sqlExtra = '';
        if ($novo_estado === 'Resolvido') {
            $sqlExtra = ', data_resolucao = NOW()';
        }
        $stmt_est = $pdo->prepare("UPDATE tickets SET estado = ? $sqlExtra WHERE id = ?");
        $stmt_est->execute([$novo_estado, $ticket_id]);
        registarHistoricoTicket($pdo, $ticket_id, $user_id_num, 'Estado', "Estado alterado para $novo_estado");
        registarAuditoria($pdo, 'Alteração', "Ticket #$ticket_id → $novo_estado");
        // Notifica por email quem abriu o ticket — apenas se o estado mudou de facto
        if ($novo_estado !== $estadoAnterior) {
            notificarSolicitanteAtualizacaoTicket($pdo, $ticket_id, 'Mudança de estado', "O ticket passou de «{$estadoAnterior}» para «{$novo_estado}».");
            // Notificação na plataforma: criador, técnico atribuído e área de destino
            notificarPlataformaAtualizacaoTicket(
                $pdo,
                $ticket_id,
                'Mudança de estado',
                "O ticket passou de «{$estadoAnterior}» para «{$novo_estado}».",
                $user_id_num
            );
        }
        $mensagem = "<div style='background: rgba(34,197,94,0.15); color: var(--green); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>Estado do ticket atualizado para <b>$novo_estado</b>!</div>";
    }
}

// Assumir ticket (Auto-atribuição): o técnico/responsável do grupo pega no ticket
// sem esperar que o Responsável lho atribua.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_assumir']) && !$souCriador && $podeTratar) {
    $estadoAntesAssumir = $ticketEarly['estado'] ?? '';
    $stmt_ass = $pdo->prepare("
        UPDATE tickets 
        SET id_tecnico_atribuido = ?, 
            estado = CASE WHEN estado = 'Aberto' THEN 'Em Progresso' ELSE estado END 
        WHERE id = ?
    ");
    $stmt_ass->execute([$user_id_num, $ticket_id]);
    registarHistoricoTicket($pdo, $ticket_id, $user_id_num, 'Assumido', 'Técnico assumiu o ticket');
    $detalheAssumir = $estadoAntesAssumir === 'Aberto'
        ? 'Um técnico assumiu o seu ticket. O estado passou de «Aberto» para «Em Progresso».'
        : 'Um técnico assumiu o seu ticket e está a tratá-lo.';
    notificarSolicitanteAtualizacaoTicket($pdo, $ticket_id, 'Mudança de estado', $detalheAssumir);
    notificarPlataformaAtualizacaoTicket($pdo, $ticket_id, 'Ticket em atendimento', $detalheAssumir, $user_id_num);
    $mensagem = "<div style='background: rgba(34,197,94,0.15); color: var(--green); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>Assumiu este ticket. Já pode resolvê-lo sem esperar por atribuição.</div>";
}

// Atribuição de Tarefa (Apenas Responsável ou Admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_atribuir']) && in_array($perfil_usuario, ['Admin', 'Responsavel'])) {
    $id_tecnico = (int)($_POST['id_tecnico'] ?? 0);
    $id_criador_ticket = (int)($ticketEarly['id_criador'] ?? 0);

    $idAreaDestinoTicket = (int)($ticketEarly['id_area_destino'] ?? 0);
    if (!$podeTratar) {
        // Responsável de outra área não pode atribuir um ticket que já não é da sua área
        $mensagem = "<div style='background: rgba(239,68,68,0.15); color: var(--red); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>Este ticket pertence agora a outra área. A atribuição cabe à equipa que o recebeu.</div>";
    } elseif ($id_tecnico > 0 && $id_tecnico === $id_criador_ticket) {
        $mensagem = "<div style='background: rgba(239,68,68,0.15); color: var(--red); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>Não pode atribuir o ticket à pessoa que o abriu. A resolução deve ser feita por outro membro da equipa.</div>";
    } elseif ($id_tecnico > 0 && !tecnicoPertenceArea($pdo, $id_tecnico, $idAreaDestinoTicket)) {
        $mensagem = "<div style='background: rgba(239,68,68,0.15); color: var(--red); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>O técnico seleccionado não pertence à área deste ticket.</div>";
    } elseif ($id_tecnico > 0) {
        $stmt_atr = $pdo->prepare("UPDATE tickets SET id_tecnico_atribuido = ?, estado = 'Em Progresso' WHERE id = ?");
        $stmt_atr->execute([$id_tecnico, $ticket_id]);
        registarHistoricoTicket($pdo, $ticket_id, $user_id_num, 'Atribuição', "Ticket atribuído ao técnico #$id_tecnico");
        criarNotificacao($pdo, $id_tecnico, null, 'Ticket Atribuído', "Foi-lhe atribuído o ticket #$ticket_id", $ticket_id);
        notificarSolicitanteAtualizacaoTicket($pdo, $ticket_id, 'Mudança de estado', 'O seu ticket foi atribuído a um técnico e o estado passou para «Em Progresso».');
        notificarPlataformaAtualizacaoTicket($pdo, $ticket_id, 'Ticket atribuído', 'O ticket foi atribuído a um técnico e passou para «Em Progresso».', $user_id_num);
        $mensagem = "<div style='background: rgba(61,111,255,0.15); color: var(--accent); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>Tarefa atribuída e movida para Em Progresso!</div>";
    }
}

// =========================================================
// EDITAR TICKET (assunto, descrição e prioridade)
// Permissões: Admin, Responsáveis (Helpdesk/Desenvolvimento) e Técnicos.
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_editar_ticket'])) {
    if (!podeEditarTicket($ticketEarly, $contexto)) {
        $mensagem = "<div style='background: rgba(239,68,68,0.15); color: var(--red); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>Não tem permissão para editar este ticket. A edição está reservada aos técnicos e responsáveis de Redes & Sistemas e Desenvolvimento.</div>";
    } else {
        $novoAssunto = resolverAssuntoTicket($_POST);
        $novaDescricao = trim($_POST['descricao'] ?? '');
        $novaPrioridade = normalizarPrioridade($_POST['prioridade'] ?? 'Média');

        if ($novoAssunto === '' || $novaDescricao === '') {
            $mensagem = "<div style='background: rgba(239,68,68,0.15); color: var(--red); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>Indique o assunto e a descrição do ticket.</div>";
        } else {
            // Recalcula o prazo do SLA a partir da data de criação, respeitando a nova prioridade
            $novaDataLimite = calcularDataLimiteSla($novaPrioridade, $ticketEarly['data_criacao'] ?? null);
            $stmt_ed = $pdo->prepare("UPDATE tickets SET titulo = ?, descricao = ?, prioridade = ?, data_limite_sla = ? WHERE id = ?");
            $stmt_ed->execute([$novoAssunto, $novaDescricao, $novaPrioridade, $novaDataLimite, $ticket_id]);
            registarHistoricoTicket($pdo, $ticket_id, $user_id_num, 'Edição', "Ticket editado — assunto: $novoAssunto; prioridade: $novaPrioridade");
            registarAuditoria($pdo, 'Alteração', "Ticket #$ticket_id editado");
            $mensagem = "<div style='background: rgba(34,197,94,0.15); color: var(--green); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>Ticket atualizado com sucesso!</div>";
        }
    }
}

// =========================================================
// REENCAMINHAR TICKET PARA OUTRA ÁREA (disponível a TODOS os utilizadores)
// Se um ticket foi aberto para a área errada, qualquer utilizador com acesso
// pode reencaminhá-lo para a área correta. O progresso continua a ser controlado.
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_reencaminhar'])) {
    $nova_area = (int)($_POST['nova_area'] ?? 0);
    $motivo = trim($_POST['motivo_reencaminhamento'] ?? '');
    $area_atual = (int)$ticketEarly['id_area_destino'];

    if ($nova_area <= 0) {
        $mensagem = "<div style='background: rgba(239,68,68,0.15); color: var(--red); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>Selecione a área de destino para reencaminhar.</div>";
    } elseif ($nova_area === $area_atual) {
        $mensagem = "<div style='background: rgba(239,68,68,0.15); color: var(--red); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>O ticket já pertence a essa área. Escolha uma área diferente.</div>";
    } else {
        // Confirmar que a nova área existe
        $stmt_verifica = $pdo->prepare("SELECT nome FROM areas WHERE id = ?");
        $stmt_verifica->execute([$nova_area]);
        $nome_nova_area = $stmt_verifica->fetchColumn();

        if (!$nome_nova_area) {
            $mensagem = "<div style='background: rgba(239,68,68,0.15); color: var(--red); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>Área de destino inválida.</div>";
        } else {
            // Reencaminhar: muda a área, limpa o técnico anterior e marca como Reencaminhado
            $stmt_reenc = $pdo->prepare("
                UPDATE tickets
                SET id_area_destino = ?,
                    id_tecnico_atribuido = NULL,
                    estado = 'Reencaminhado'
                WHERE id = ?
            ");
            $stmt_reenc->execute([$nova_area, $ticket_id]);

            $detalhe = "Reencaminhado para a área '$nome_nova_area'";
            if ($motivo !== '') {
                $detalhe .= " — Motivo: $motivo";
            }
            registarHistoricoTicket($pdo, $ticket_id, $user_id_num, 'Reencaminhamento', $detalhe);
            registarAuditoria($pdo, 'Alteração', "Ticket #$ticket_id reencaminhado para '$nome_nova_area'");
            notificarAreaTicket($pdo, $nova_area, $ticket_id, 'Ticket Reencaminhado', "Ticket #$ticket_id reencaminhado para a vossa área: $nome_nova_area");
            // Recarrega o ticket e envia email à caixa da nova área
            $stmtMail = $pdo->prepare("SELECT * FROM tickets WHERE id = ?");
            $stmtMail->execute([$ticket_id]);
            $ticketMail = $stmtMail->fetch(PDO::FETCH_ASSOC) ?: $ticketEarly;
            $ticketMail['id_area_destino'] = $nova_area;
            enviarEmailCaixaAreaTicket($pdo, $nova_area, $ticketMail);
            notificarSolicitanteAtualizacaoTicket($pdo, $ticket_id, 'Mudança de estado', "O seu ticket foi reencaminhado para a área «{$nome_nova_area}» e o estado passou para «Reencaminhado».");
            notificarPlataformaAtualizacaoTicket($pdo, $ticket_id, 'Ticket Reencaminhado', "Reencaminhado para a área «{$nome_nova_area}».", $user_id_num);

            // Registar o motivo como comentário para ficar visível no histórico de interações
            if ($motivo !== '' && $user_id_num) {
                $stmt_com = $pdo->prepare("INSERT INTO comentarios (id_ticket, id_utilizador, comentario, data_envio) VALUES (?, ?, ?, NOW())");
                $stmt_com->execute([$ticket_id, $user_id_num, "Reencaminhado para $nome_nova_area. Motivo: $motivo"]);
            }

            $mensagem = "<div style='background: rgba(245,158,11,0.15); color: var(--amber); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>Ticket reencaminhado para <b>$nome_nova_area</b>. A nova equipa foi notificada e pode acompanhar o progresso.</div>";
        }
    }
}

// =========================================================
// EDITAR SLA / PRIORIDADE / URGÊNCIA (Técnicos Redes/Dev + Responsáveis)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_editar_sla'])) {
    if (!podeEditarSlaTicket($ticketEarly, $contexto) || $souCriador) {
        $mensagem = "<div style='background: rgba(239,68,68,0.15); color: var(--red); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>Não tem permissão para editar o SLA deste ticket.</div>";
    } else {
        $novaPrio = normalizarPrioridade($_POST['prioridade_sla'] ?? 'Média');
        $novaUrg = catalogoNormalizarNivel($_POST['urgencia_sla'] ?? $novaPrio);
        $novoImpacto = trim($_POST['impacto_sla'] ?? ($ticketEarly['impacto'] ?? 'Utilizador'));
        if (!isset(catalogoMatrizImpactoUrgencia()[$novoImpacto])) {
            $novoImpacto = 'Utilizador';
        }
        $respMin = isset($_POST['sla_resposta_min']) && $_POST['sla_resposta_min'] !== ''
            ? (int)$_POST['sla_resposta_min']
            : calcularMinutosRespostaSla($novaPrio);
        $resolH = isset($_POST['sla_resolucao_h']) && $_POST['sla_resolucao_h'] !== ''
            ? (float)$_POST['sla_resolucao_h']
            : (float)(catalogoMatrizSlaPrioridade()[$novaPrio][1] ?? 24);

        $dataBase = $ticketEarly['data_criacao'] ?? null;
        [$dataResp, $dataResol] = calcularDatasLimiteSlaCatalogo($respMin, $resolH, $dataBase);

        try {
            $stmtSla = $pdo->prepare("UPDATE tickets SET prioridade = ?, urgencia = ?, impacto = ?, data_limite_sla = ?, data_limite_resposta = ? WHERE id = ?");
            $stmtSla->execute([$novaPrio, $novaUrg, $novoImpacto, $dataResol, $dataResp, $ticket_id]);
        } catch (PDOException $e) {
            $pdo->prepare("UPDATE tickets SET prioridade = ?, data_limite_sla = ? WHERE id = ?")
                ->execute([$novaPrio, $dataResol, $ticket_id]);
        }
        registarHistoricoTicket($pdo, $ticket_id, $user_id_num, 'SLA', "SLA editado — prioridade $novaPrio, urgência $novaUrg, impacto $novoImpacto, resposta {$respMin}min, resolução {$resolH}h");
        registarAuditoria($pdo, 'Alteração', "Ticket #$ticket_id SLA actualizado");
        $mensagem = "<div style='background: rgba(34,197,94,0.15); color: var(--green); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>SLA actualizado: <b>$novaPrio</b> (resposta {$respMin} min / resolução {$resolH} h).</div>";
    }
}

// =========================================================
// CHECKLIST NOVA ENTRADA (Redes & Sistemas)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_guardar_checklist'])) {
    if ($souCriador) {
        $mensagem = "<div style='background: rgba(239,68,68,0.15); color: var(--red); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>Quem abriu o ticket não pode actualizar a checklist.</div>";
    } elseif (!$podeTratar) {
        $mensagem = "<div style='background: rgba(239,68,68,0.15); color: var(--red); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>Só a equipa de Redes & Sistemas pode actualizar esta checklist.</div>";
    } elseif (!ticketENovaEntradaRedes($ticketEarly)) {
        $mensagem = "<div style='background: rgba(239,68,68,0.15); color: var(--red); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>Este ticket não é uma Nova entrada de Redes & Sistemas.</div>";
    } else {
        $chavesFeitas = [];
        if (!empty($_POST['checklist_feito']) && is_array($_POST['checklist_feito'])) {
            foreach ($_POST['checklist_feito'] as $chave) {
                $chave = trim((string)$chave);
                if ($chave !== '' && isset(obterItensChecklistNovaEntrada()[$chave])) {
                    $chavesFeitas[] = $chave;
                }
            }
        }
        $respostaPendente = $_POST['ficou_pendente'] ?? '';
        if ($respostaPendente !== 'sim' && $respostaPendente !== 'nao') {
            $mensagem = "<div style='background: rgba(239,68,68,0.15); color: var(--red); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>Indique se ficou algo pendente (Sim ou Não).</div>";
        } else {
            $ficouPendente = ($respostaPendente === 'sim');
            $resultado = guardarChecklistNovaEntrada($pdo, $ticket_id, $chavesFeitas, $ficouPendente, $user_id_num);
            if ($resultado['ok']) {
                $estadoNovo = $resultado['estado'] ?? '';
                if ($estadoNovo !== '' && $estadoNovo !== ($ticketEarly['estado'] ?? '')) {
                    notificarSolicitanteAtualizacaoTicket(
                        $pdo,
                        $ticket_id,
                        'Mudança de estado',
                        $ficouPendente
                            ? 'A checklist de Nova entrada ficou com itens pendentes. O ticket permanece em progresso.'
                            : 'A checklist de Nova entrada foi concluída e o ticket foi marcado como Resolvido.'
                    );
                    notificarPlataformaAtualizacaoTicket(
                        $pdo,
                        $ticket_id,
                        $ficouPendente ? 'Nova entrada pendente' : 'Nova entrada concluída',
                        $resultado['mensagem'],
                        $user_id_num
                    );
                }
                $cor = $ficouPendente ? 'var(--amber)' : 'var(--green)';
                $bg = $ficouPendente ? 'rgba(245,158,11,0.15)' : 'rgba(34,197,94,0.15)';
                $mensagem = "<div style='background: {$bg}; color: {$cor}; padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>{$resultado['mensagem']}</div>";
            } else {
                $mensagem = "<div style='background: rgba(239,68,68,0.15); color: var(--red); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>{$resultado['mensagem']}</div>";
            }
        }
    }
}

// =========================================================
// BUSCAR INFORMAÇÕES DO TICKET ATUAL
// =========================================================
$query_ticket = "
    SELECT t.*, 
           u.nome AS nome_criador, 
           a.nome AS nome_area, 
           o.nome AS nome_operacao,
           tec.nome AS nome_tecnico
    FROM tickets t
    LEFT JOIN utilizadores u ON t.id_criador = u.id
    LEFT JOIN areas a ON t.id_area_destino = a.id
    LEFT JOIN operacoes o ON t.id_operacao_origem = o.id
    LEFT JOIN utilizadores tec ON t.id_tecnico_atribuido = tec.id
    WHERE t.id = ?
";
$stmt = $pdo->prepare($query_ticket);
$stmt->execute([$ticket_id]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
    die("Ticket não encontrado.");
}

if (!podeVerTicket($ticket, $contexto, $pdo)) {
    die("Acesso negado. Não tem permissão para ver este ticket.");
}

// Recalcular a permissão de tratamento com o estado ATUAL do ticket (já reflete
// qualquer reencaminhamento efetuado nesta mesma requisição).
$podeTratar = podeTratarTicket($ticket, $contexto);
$podeEditar = podeEditarTicket($ticket, $contexto);
$podeEditarSla = podeEditarSlaTicket($ticket, $contexto);

// Checklist Nova entrada (Redes & Sistemas)
$ehNovaEntradaRedes = ticketENovaEntradaRedes($ticket);
$checklistItens = [];
$checklistResumo = ['total' => 0, 'feitos' => 0, 'pendentes' => 0, 'completa' => false, 'tem_pendente' => false];
if ($ehNovaEntradaRedes) {
    try {
        $checklistItens = garantirChecklistTicket($pdo, $ticket_id);
        $checklistResumo = resumoChecklistNovaEntrada($checklistItens);
    } catch (PDOException $e) {
        $ehNovaEntradaRedes = false;
        $mensagem .= "<div style='background: rgba(245,158,11,0.15); color: var(--amber); padding: 10px; border-radius: var(--radius-sm); margin-bottom: 15px;'>Checklist indisponível. Execute «Atualizar Banco» no painel Admin.</div>";
    }
}

// Buscar comentários do Ticket
$stmt_comentarios = $pdo->prepare("
    SELECT c.*, u.nome AS nome_autor, u.perfil AS perfil_autor 
    FROM comentarios c 
    JOIN utilizadores u ON c.id_utilizador = u.id 
    WHERE c.id_ticket = ? 
    ORDER BY c.id ASC
");
$stmt_comentarios->execute([$ticket_id]);
$lista_comentarios = $stmt_comentarios->fetchAll(PDO::FETCH_ASSOC);

// Histórico de alterações
$historico = [];
try {
    $stmt_hist = $pdo->prepare("
        SELECT h.*, u.nome AS nome_autor 
        FROM ticket_historico h 
        LEFT JOIN utilizadores u ON h.id_utilizador = u.id 
        WHERE h.id_ticket = ? 
        ORDER BY h.id DESC
    ");
    $stmt_hist->execute([$ticket_id]);
    $historico = $stmt_hist->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $historico = [];
}

$controloTempos = obterControloTemposTicket($ticket, $historico);

$slaEstado = estadoSlaTicket($ticket);
$slaCor = match($slaEstado) { 'vencido' => 'var(--red)', 'risco' => 'var(--amber)', 'cumprido' => 'var(--green)', default => 'var(--text-secondary)' };
$nomeSolicitante = !empty($ticket['nome_solicitante']) ? $ticket['nome_solicitante'] : ($ticket['nome_criador'] ?? 'Anónimo');

// Equipa da área de destino do ticket: técnicos e responsáveis (qualquer área)
$lista_tecnicos = [];
$idAreaTicket = (int)($ticket['id_area_destino'] ?? 0);
if ($idAreaTicket > 0) {
    $lista_tecnicos = array_values(array_filter(
        obterTecnicosDaArea($pdo, $idAreaTicket),
        static fn(array $tec): bool => (int)$tec['id'] !== (int)($ticket['id_criador'] ?? 0)
    ));
}
$totalTecnicosOnline = count(array_filter($lista_tecnicos, static fn(array $t): bool => tecnicoEstaOnline($t['ultimo_acesso'] ?? null, $t['sessao_ativa'] ?? 1)));

// Admin + equipas Redes & Sistemas e Desenvolvimento
$podeVerDisponibilidade = podeVerEquipaDisponivel($contexto);

// Todas as áreas (para reencaminhamento), exceto a área atual do ticket
$areas_reencaminhar = $pdo->prepare("SELECT id, nome FROM areas WHERE id != ? ORDER BY nome ASC");
$areas_reencaminhar->execute([$ticket['id_area_destino']]);
$lista_areas_reencaminhar = $areas_reencaminhar->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KIAMI - Detalhes do Ticket #<?php echo $ticket['id']; ?></title>
    <link rel="stylesheet" href="style.css">
    <script src="tema.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"></head>
<body>
    <div class="app-layout">
        <div id="sidebar">
            <div class="sidebar-brand"><h3>KIAMI</h3><span>Suporte Quality</span></div>
            <div class="nav-section-title">Geral</div>
            <a href="index.php" class="nav-item">📊 <span>Painel</span></a>
            <a href="tickets_lista.php" class="nav-item active">🎫 <span>Tickets</span></a>
            <a href="kb_lista.php" class="nav-item">📚 <span>Base Conhecimento</span></a>
            <a href="formacao.php" class="nav-item">🎓 <span>Autoaprendizagem</span></a>
            <a href="empresa.php" class="nav-item">🏢 <span>A Empresa</span></a>
            <?php echo htmlNavNotificacoes((int)($notif_pendentes ?? 0)); ?>
            
            <?php if (podeAcederAdministracao($contexto)): ?>
                <div class="nav-section-title">Administração</div>
                <a href="perfis_lista.php" class="nav-item">🪪 <span>Gestão de Perfis</span></a>
                <a href="usuarios_lista.php" class="nav-item nav-sub">👥 <span>Utilizadores</span></a>
                <a href="equipa_online.php" class="nav-item">🟢 <span>Equipa Disponível</span></a>
                <a href="assuntos_lista.php" class="nav-item">📋 <span>Assuntos de Ticket</span></a>
                <a href="emails_areas.php" class="nav-item">✉️ <span>Emails das Áreas</span></a>
                <a href="relatorios.php" class="nav-item">📈 <span>Relatórios</span></a>
                <?php if (podeAcederAuditoria($contexto)): ?>
                <a href="auditoria.php" class="nav-item">🔍 <span>Auditoria</span></a>
                <?php endif; ?>
            <?php elseif ($perfil_usuario === 'Diretor Geral'): ?>
                <div class="nav-section-title">Consulta</div>
                <a href="relatorios.php" class="nav-item">📈 <span>Relatórios</span></a>
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
            <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                <div>
                    <a href="tickets_lista.php" style="color: var(--accent); text-decoration: none; font-size: 13px; font-weight: 600;">← Voltar para a lista</a>
                    <h1 style="margin-top: 5px;"><?php echo htmlspecialchars($ticket['codigo'] ?? ('Ticket #' . $ticket['id'])); ?></h1>
                </div>
                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                    <span style="padding: 6px 12px; background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13px;">
                        Estado Atual: <b style="color: var(--accent);"><?php echo $ticket['estado']; ?></b>
                    </span>
                    <?php if (!$podeTratar): ?>
                        <span style="padding: 6px 12px; background: rgba(61,111,255,0.1); border: 1px solid rgba(61,111,255,0.35); border-radius: var(--radius-sm); font-size: 12px; color: var(--accent); font-weight: 600;">👁️ Acompanhar estado</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php echo $mensagem; ?>

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 25px; align-items: start;">
                
                <div>
                    <div class="card" style="margin-bottom: 25px;">
                        <h2 style="font-size: 20px; color: #fff; margin-bottom: 10px;"><?php echo htmlspecialchars($ticket['titulo']); ?></h2>
                        <div style="font-size: 13px; color: var(--text-secondary); margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid var(--border);">
                            Solicitante: <b><?php echo htmlspecialchars($nomeSolicitante); ?></b> |
                            Origem: <b><?php echo $ticket['nome_operacao'] ? htmlspecialchars($ticket['nome_operacao']) : 'Interno'; ?></b> |
                            Prioridade: <b><?php echo htmlspecialchars($ticket['prioridade']); ?></b> |
                            SLA: <b style="color: <?php echo $slaCor; ?>;"><?php echo date('d/m/Y H:i', strtotime($ticket['data_limite_sla'])); ?></b> |
                            Data: <?php echo date('d/m/Y H:i', strtotime($ticket['data_criacao'])); ?> |
                            <?php if (!empty($controloTempos['aberto_em_curso'])): ?>
                                Aberto há: <b style="color: var(--accent);"><?php echo htmlspecialchars($controloTempos['tempo_aberto']); ?></b>
                            <?php else: ?>
                                Tempo total: <b style="color: var(--green);"><?php echo htmlspecialchars($controloTempos['tempo_ate_resolucao'] ?? $controloTempos['tempo_aberto']); ?></b>
                            <?php endif; ?>
                        </div>
                        <p style="color: var(--text-primary); font-size: 14px; line-height: 1.6; white-space: pre-line;"><?php echo htmlspecialchars($ticket['descricao']); ?></p>

                        <?php if (!empty($ticket['anexo']) && file_exists(__DIR__ . '/' . $ticket['anexo'])): ?>
                            <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid var(--border);">
                                <div style="font-size: 12px; color: var(--text-secondary); margin-bottom: 8px;">📎 Imagem anexada:</div>
                                <a href="<?php echo htmlspecialchars($ticket['anexo']); ?>" target="_blank">
                                    <img src="<?php echo htmlspecialchars($ticket['anexo']); ?>" alt="Anexo do ticket" style="max-width: 100%; max-height: 400px; border-radius: var(--radius-sm); border: 1px solid var(--border);">
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <h3 style="font-size: 16px; margin-bottom: 15px; color: var(--text-secondary);">💬 Histórico e Interações</h3>
                    <div style="display: flex; flex-direction: column; gap: 15px; margin-bottom: 25px;">
                        <?php if (empty($lista_comentarios)): ?>
                            <p style="color: var(--text-muted); font-size: 14px; font-style: italic;">Nenhum comentário técnico adicionado ainda.</p>
                        <?php endif; ?>
                        
                        <?php foreach ($lista_comentarios as $com): ?>
                            <div class="card" style="background: <?php echo (int)$com['id_utilizador'] === $user_id_num ? 'rgba(61,111,255,0.05)' : 'var(--bg-sidebar)'; ?>; border-left: 3px solid <?php echo (int)$com['id_utilizador'] === $user_id_num ? 'var(--accent)' : 'var(--border)'; ?>; padding: 15px;">
                                <div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 6px; color: var(--text-secondary);">
                                    <span><b><?php echo htmlspecialchars($com['nome_autor']); ?></b> (<?php echo $com['perfil_autor']; ?>)</span>
                                    <span><?php echo date('d/m/Y H:i', strtotime($com['data_envio'])); ?></span>
                                </div>
                                <p style="font-size: 14px; color: var(--text-primary); line-height: 1.5; white-space: pre-line;"><?php echo htmlspecialchars($com['comentario']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!empty($historico)): ?>
                    <h3 style="font-size: 16px; margin: 25px 0 15px; color: var(--text-secondary);">📋 Histórico de Alterações</h3>
                    <div style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 25px;">
                        <?php
                        $criacaoTsHist = !empty($ticket['data_criacao']) ? (int)strtotime((string)$ticket['data_criacao']) : null;
                        $histAsc = $historico;
                        usort($histAsc, static fn(array $a, array $b): int => ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0)));
                        $prevMarcoTs = $criacaoTsHist;
                        $temposPorId = [];
                        foreach ($histAsc as $hAsc) {
                            $tsH = !empty($hAsc['data_registo']) ? (int)strtotime((string)$hAsc['data_registo']) : null;
                            $desdeAbertura = ($criacaoTsHist && $tsH) ? formatarDuracaoSegundos(max(0, $tsH - $criacaoTsHist)) : null;
                            $desdeAnterior = ($prevMarcoTs && $tsH) ? formatarDuracaoSegundos(max(0, $tsH - $prevMarcoTs)) : null;
                            $temposPorId[(int)($hAsc['id'] ?? 0)] = [
                                'desde_abertura' => $desdeAbertura,
                                'desde_anterior' => $desdeAnterior,
                            ];
                            if ($tsH && in_array((string)($hAsc['acao'] ?? ''), ['Criação', 'Assumido', 'Atribuição', 'Estado', 'Reencaminhamento', 'Reabertura'], true)) {
                                $prevMarcoTs = $tsH;
                            }
                        }
                        foreach ($historico as $h):
                            $tid = (int)($h['id'] ?? 0);
                            $tInfo = $temposPorId[$tid] ?? null;
                        ?>
                            <div style="font-size: 12px; padding: 10px 14px; background: var(--bg-sidebar); border-radius: var(--radius-sm); border-left: 2px solid var(--border);">
                                <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                                    <div>
                                        <span style="color: var(--accent); font-weight: 600;"><?php echo htmlspecialchars($h['acao']); ?></span>
                                        — <?php echo htmlspecialchars($h['detalhes']); ?>
                                    </div>
                                    <span style="color: var(--text-muted); white-space:nowrap;">
                                        <?php echo htmlspecialchars($h['nome_autor'] ?? 'Sistema'); ?> · <?php echo date('d/m/Y H:i', strtotime($h['data_registo'])); ?>
                                    </span>
                                </div>
                                <?php if ($tInfo && ($tInfo['desde_abertura'] || $tInfo['desde_anterior'])): ?>
                                    <div style="margin-top:6px; color: var(--text-secondary); font-size:11px;">
                                        <?php if (!empty($tInfo['desde_abertura'])): ?>
                                            <span style="margin-right:12px;">Desde abertura: <b style="color:#fff;"><?php echo htmlspecialchars($tInfo['desde_abertura']); ?></b></span>
                                        <?php endif; ?>
                                        <?php if (!empty($tInfo['desde_anterior']) && $tInfo['desde_anterior'] !== '—'): ?>
                                            <span>Desde marco anterior: <b style="color:#fff;"><?php echo htmlspecialchars($tInfo['desde_anterior']); ?></b></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <div class="card">
                        <form action="ticket_detalhes.php?id=<?php echo $ticket_id; ?>" method="POST">
                            <textarea name="comentario" required placeholder="Escreva uma mensagem ou atualização técnica..." rows="3" style="width:100%; padding: 12px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm); resize: vertical; margin-bottom: 10px; font-family: inherit;"></textarea>
                            <button type="submit" name="btn_comentar" style="padding: 10px 20px; background: var(--accent); border:none; color:white; border-radius:var(--radius-sm); cursor:pointer; font-weight:600;">Enviar Mensagem</button>
                        </form>
                    </div>
                </div>

                <div style="display: flex; flex-direction: column; gap: 25px;">
                    
                    <div class="card" style="background: var(--bg-sidebar);">
                        <h3 style="font-size: 14px; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 15px; letter-spacing: 0.5px;">Atribuição</h3>
                        <div style="font-size: 14px; margin-bottom: 10px;">
                            <span style="color: var(--text-muted);">Destino:</span> <b style="color: #fff;"><?php echo htmlspecialchars($ticket['nome_area']); ?></b>
                        </div>
                        <div style="font-size: 14px;">
                            <span style="color: var(--text-muted);">Técnico:</span> 
                            <b style="color: var(--accent);"><?php echo $ticket['nome_tecnico'] ? htmlspecialchars($ticket['nome_tecnico']) : 'Não Atribuído'; ?></b>
                        </div>
                    </div>

                    <?php
                        // Barra de progresso visual conforme o estado do ticket
                        $progresso = match ($ticket['estado']) {
                            'Aberto' => 15,
                            'Reencaminhado' => 35,
                            'Em Progresso' => 60,
                            'Resolvido' => 100,
                            default => 10,
                        };
                        $corProgresso = $ticket['estado'] === 'Resolvido' ? 'var(--green)' : 'var(--accent)';
                    ?>
                    <div class="card">
                        <h3 style="font-size: 14px; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 12px; letter-spacing: 0.5px;">Progresso</h3>
                        <div style="background: var(--bg-input); border-radius: 20px; height: 10px; overflow: hidden; margin-bottom: 8px;">
                            <div style="width: <?php echo $progresso; ?>%; height: 100%; background: <?php echo $corProgresso; ?>; border-radius: 20px; transition: width .3s;"></div>
                        </div>
                        <div style="font-size: 12px; color: var(--text-secondary); display: flex; justify-content: space-between;">
                            <span>Estado: <b style="color:#fff;"><?php echo htmlspecialchars($ticket['estado']); ?></b></span>
                            <span><?php echo $progresso; ?>%</span>
                        </div>
                    </div>

                    <div class="card" style="border: 1px solid rgba(61,111,255,0.25);">
                        <h3 style="font-size: 14px; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 14px; letter-spacing: 0.5px;">⏱ Controlo de tempos</h3>

                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:14px;">
                            <div style="padding:10px; background:var(--bg-input); border-radius:var(--radius-sm);">
                                <div style="font-size:11px; color:var(--text-muted); margin-bottom:4px;">
                                    <?php echo !empty($controloTempos['aberto_em_curso']) ? 'Aberto há' : 'Tempo total (aberto → resolvido)'; ?>
                                </div>
                                <div style="font-size:16px; font-weight:700; color:#fff;"><?php echo htmlspecialchars($controloTempos['tempo_aberto']); ?></div>
                            </div>
                            <div style="padding:10px; background:var(--bg-input); border-radius:var(--radius-sm);">
                                <div style="font-size:11px; color:var(--text-muted); margin-bottom:4px;">Até 1º assumir</div>
                                <div style="font-size:16px; font-weight:700; color:var(--accent);">
                                    <?php echo htmlspecialchars($controloTempos['tempo_ate_primeiro_assumir'] ?? 'Ainda não'); ?>
                                </div>
                            </div>
                            <div style="padding:10px; background:var(--bg-input); border-radius:var(--radius-sm); grid-column:1 / -1;">
                                <div style="font-size:11px; color:var(--text-muted); margin-bottom:4px;">Até resolução</div>
                                <div style="font-size:16px; font-weight:700; color:<?php echo $controloTempos['tempo_ate_resolucao'] ? 'var(--green)' : 'var(--amber)'; ?>;">
                                    <?php echo htmlspecialchars($controloTempos['tempo_ate_resolucao'] ?? 'Em curso'); ?>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($controloTempos['por_pessoa'])): ?>
                            <div style="font-size:12px; color:var(--text-secondary); font-weight:600; margin-bottom:8px; text-transform:uppercase; letter-spacing:0.4px;">Tempo por pessoa (assumiu)</div>
                            <div style="display:flex; flex-direction:column; gap:8px; margin-bottom:14px;">
                                <?php foreach ($controloTempos['por_pessoa'] as $pp): ?>
                                    <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; padding:8px 10px; background:var(--bg-sidebar); border-radius:var(--radius-sm); font-size:13px;">
                                        <span>
                                            <b style="color:#fff;"><?php echo htmlspecialchars($pp['nome']); ?></b>
                                            <?php if (!empty($pp['activo'])): ?>
                                                <span style="margin-left:6px; font-size:10px; padding:2px 6px; border-radius:999px; background:rgba(34,197,94,0.2); color:var(--green); font-weight:600;">activo</span>
                                            <?php endif; ?>
                                            <?php if ((int)($pp['periodos'] ?? 0) > 1): ?>
                                                <span style="color:var(--text-muted); font-size:11px;"> · <?php echo (int)$pp['periodos']; ?>×</span>
                                            <?php endif; ?>
                                        </span>
                                        <b style="color:var(--accent); white-space:nowrap;"><?php echo htmlspecialchars($pp['duracao']); ?></b>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p style="font-size:12px; color:var(--text-muted); font-style:italic; margin-bottom:14px;">Ainda ninguém assumiu este ticket.</p>
                        <?php endif; ?>

                        <?php if (count($controloTempos['marcos'] ?? []) > 1): ?>
                            <div style="font-size:12px; color:var(--text-secondary); font-weight:600; margin-bottom:8px; text-transform:uppercase; letter-spacing:0.4px;">Marcos</div>
                            <div style="display:flex; flex-direction:column; gap:6px;">
                                <?php foreach ($controloTempos['marcos'] as $marco): ?>
                                    <div style="font-size:12px; padding:8px 10px; background:var(--bg-sidebar); border-radius:var(--radius-sm); border-left:2px solid var(--accent);">
                                        <div style="display:flex; justify-content:space-between; gap:8px; flex-wrap:wrap;">
                                            <b style="color:#fff;"><?php echo htmlspecialchars($marco['rotulo']); ?></b>
                                            <span style="color:var(--text-muted);"><?php echo htmlspecialchars($marco['quando']); ?></span>
                                        </div>
                                        <div style="margin-top:4px; color:var(--text-secondary);">
                                            Desde abertura: <b style="color:#fff;"><?php echo htmlspecialchars($marco['desde_abertura']); ?></b>
                                            <?php if (($marco['desde_anterior'] ?? '—') !== '—'): ?>
                                                · Desde anterior: <b style="color:#fff;"><?php echo htmlspecialchars($marco['desde_anterior']); ?></b>
                                            <?php endif; ?>
                                            <?php if (!empty($marco['autor'])): ?>
                                                · <?php echo htmlspecialchars((string)$marco['autor']); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($ehNovaEntradaRedes): ?>
                        <div class="card checklist-nova-entrada" style="border: 1px solid rgba(61,111,255,0.35); background: rgba(61,111,255,0.04);">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px; margin-bottom:12px;">
                                <h3 style="font-size: 15px; color: var(--accent); margin:0;">Nova entrada — Redes & Sistemas</h3>
                                <?php if ($checklistResumo['completa'] || $ticket['estado'] === 'Resolvido'): ?>
                                    <span style="font-size:11px; padding:3px 8px; border-radius:999px; background:rgba(34,197,94,0.2); color:var(--green); font-weight:600;">Concluída</span>
                                <?php elseif ($checklistResumo['tem_pendente']): ?>
                                    <span style="font-size:11px; padding:3px 8px; border-radius:999px; background:rgba(245,158,11,0.2); color:var(--amber); font-weight:600;">Pendente</span>
                                <?php else: ?>
                                    <span style="font-size:11px; padding:3px 8px; border-radius:999px; background:rgba(148,163,184,0.2); color:var(--text-secondary); font-weight:600;">Em curso</span>
                                <?php endif; ?>
                            </div>
                            <p style="font-size:12px; color:var(--text-secondary); margin:0 0 14px; line-height:1.45;">
                                Seleccione o que foi feito. Depois indique se ficou algo pendente.
                            </p>

                            <?php if ($podeTratar && !$souCriador && $ticket['estado'] !== 'Resolvido'): ?>
                                <form action="ticket_detalhes.php?id=<?php echo $ticket_id; ?>" method="POST" style="display:flex; flex-direction:column; gap:12px;">
                                    <div style="display:flex; flex-direction:column; gap:8px;">
                                        <?php foreach ($checklistItens as $item): ?>
                                            <label class="checklist-item" style="display:flex; align-items:center; gap:10px; padding:8px 10px; background:var(--bg-input); border:1px solid var(--border); border-radius:var(--radius-sm); cursor:pointer; font-size:13px; color:#fff;">
                                                <input type="checkbox" name="checklist_feito[]" value="<?php echo htmlspecialchars($item['item_chave']); ?>"
                                                    <?php echo !empty($item['feito']) ? 'checked' : ''; ?>
                                                    style="width:16px; height:16px;">
                                                <span><?php echo htmlspecialchars($item['item_rotulo']); ?></span>
                                                <?php if (!empty($item['pendente'])): ?>
                                                    <span style="margin-left:auto; font-size:10px; color:var(--amber);">pendente</span>
                                                <?php endif; ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>

                                    <div style="padding:12px; background:var(--bg-sidebar); border-radius:var(--radius-sm); border:1px solid var(--border);">
                                        <p style="font-size:13px; color:#fff; margin:0 0 10px; font-weight:600;">Ficou algo pendente?</p>
                                        <div style="display:flex; gap:16px; flex-wrap:wrap;">
                                            <label style="display:flex; align-items:center; gap:6px; font-size:13px; color:var(--text-primary); cursor:pointer;">
                                                <input type="radio" name="ficou_pendente" value="sim" required>
                                                Sim — marcar como Pendente
                                            </label>
                                            <label style="display:flex; align-items:center; gap:6px; font-size:13px; color:var(--text-primary); cursor:pointer;">
                                                <input type="radio" name="ficou_pendente" value="nao" required>
                                                Não — marcar como Concluída
                                            </label>
                                        </div>
                                    </div>

                                    <button type="submit" name="btn_guardar_checklist" style="padding:10px; background:var(--accent); border:none; color:white; border-radius:var(--radius-sm); cursor:pointer; font-weight:600; font-size:13px;">
                                        Guardar checklist
                                    </button>
                                </form>
                            <?php else: ?>
                                <ul style="margin:0; padding-left:18px; display:flex; flex-direction:column; gap:6px;">
                                    <?php foreach ($checklistItens as $item): ?>
                                        <li style="font-size:13px; color:var(--text-primary);">
                                            <?php if (!empty($item['feito'])): ?>
                                                <span style="color:var(--green);">✓</span>
                                            <?php elseif (!empty($item['pendente'])): ?>
                                                <span style="color:var(--amber);">○</span>
                                            <?php else: ?>
                                                <span style="color:var(--text-muted);">–</span>
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars($item['item_rotulo']); ?>
                                            <?php if (!empty($item['pendente']) && empty($item['feito'])): ?>
                                                <span style="font-size:11px; color:var(--amber);">(pendente)</span>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <p style="font-size:11px; color:var(--text-muted); margin:12px 0 0;">
                                    <?php echo (int)$checklistResumo['feitos']; ?>/<?php echo (int)$checklistResumo['total']; ?> feitos
                                    <?php if ($checklistResumo['tem_pendente']): ?> · com pendências<?php endif; ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($souCriador): ?>
                        <div class="card" style="border: 1px solid rgba(61,111,255,0.3); background: rgba(61,111,255,0.05);">
                            <h3 style="font-size: 14px; color: var(--accent); margin-bottom: 8px;">Ticket aberto por si</h3>
                            <p style="font-size: 12px; color: var(--text-secondary); line-height: 1.5;">
                                Pode acompanhar o progresso, comentar e reencaminhar se necessário.
                                A resolução deve ser feita pela equipa responsável — não pode assumir nem marcar como resolvido.
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php if (!$souCriador && !$podeTratar && podeResolverTickets($contexto) && $ticket['estado'] !== 'Resolvido'): ?>
                        <div class="card" style="border: 1px solid rgba(245,158,11,0.3); background: rgba(245,158,11,0.05);">
                            <h3 style="font-size: 14px; color: var(--amber); margin-bottom: 8px;">👀 Apenas acompanhamento</h3>
                            <p style="font-size: 12px; color: var(--text-secondary); line-height: 1.5;">
                                Este ticket pertence atualmente à área <b><?php echo htmlspecialchars($ticket['nome_area']); ?></b>.
                                O tratamento (assumir, atribuir, alterar estado e editar) cabe à equipa que o recebeu.
                                Pode acompanhar o progresso e comentar.
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php if ($ticket['estado'] !== 'Resolvido'): ?>
                        <div class="card" style="border: 1px solid rgba(245,158,11,0.3); background: rgba(245,158,11,0.03);">
                            <h3 style="font-size: 15px; color: var(--amber); margin-bottom: 8px;">🔀 Reencaminhar Ticket</h3>
                            <p style="font-size: 12px; color: var(--text-secondary); margin-bottom: 12px;">Ticket aberto para a área errada? Reencaminhe-o para a equipa certa. O progresso continua a ser acompanhado.</p>
                            <form action="ticket_detalhes.php?id=<?php echo $ticket_id; ?>" method="POST" style="display: flex; flex-direction: column; gap: 10px;">
                                <select name="nova_area" required style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                                    <option value="">-- Escolher nova área --</option>
                                    <?php foreach ($lista_areas_reencaminhar as $ar): ?>
                                        <option value="<?php echo $ar['id']; ?>"><?php echo htmlspecialchars($ar['nome']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <textarea name="motivo_reencaminhamento" rows="2" placeholder="Motivo (opcional)..." style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm); resize: vertical; font-family:inherit; font-size:13px;"></textarea>
                                <button type="submit" name="btn_reencaminhar" style="padding: 10px; background: var(--amber); border:none; color:#111; border-radius:var(--radius-sm); cursor:pointer; font-weight:600; font-size:13px;" onclick="return confirm('Reencaminhar este ticket para a área selecionada?');">Reencaminhar</button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <?php if (!$souCriador && $podeTratar && (int)$ticket['id_tecnico_atribuido'] !== $user_id_num && $ticket['estado'] !== 'Resolvido'): ?>
                        <div class="card" style="border: 1px solid rgba(16,185,129,0.3); background: rgba(16,185,129,0.03);">
                            <h3 style="font-size: 15px; color: var(--green); margin-bottom: 8px;">🙋 Assumir Ticket</h3>
                            <p style="font-size: 12px; color: var(--text-secondary); margin-bottom: 12px;">Este ticket foi enviado ao seu grupo. Assuma-o para o resolver sem esperar por atribuição.</p>
                            <form action="ticket_detalhes.php?id=<?php echo $ticket_id; ?>" method="POST">
                                <button type="submit" name="btn_assumir" style="width: 100%; padding: 10px; background: var(--green); border:none; color:white; border-radius:var(--radius-sm); cursor:pointer; font-weight:600; font-size:13px;">Assumir este ticket</button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <?php if ($podeVerDisponibilidade): ?>
                        <div class="card">
                            <h3 style="font-size: 15px; color: var(--green); margin-bottom: 4px;">🟢 Equipa Disponível — <?php echo htmlspecialchars($ticket['nome_area'] ?? 'Área'); ?></h3>
                            <p style="font-size: 11px; color: var(--text-muted); margin-bottom: 12px;"><?php echo $totalTecnicosOnline; ?> de <?php echo count($lista_tecnicos); ?> membro(s) online nesta equipa (Redes & Sistemas ou Desenvolvimento).</p>
                            <?php if (empty($lista_tecnicos)): ?>
                                <p style="font-size: 12px; color: var(--text-muted); font-style: italic;">Sem técnicos associados a esta área.</p>
                            <?php else: ?>
                                <div style="display: flex; flex-direction: column; gap: 8px;">
                                    <?php foreach ($lista_tecnicos as $tec):
                                        $online = tecnicoEstaOnline($tec['ultimo_acesso'] ?? null, $tec['sessao_ativa'] ?? 1);
                                    ?>
                                        <div style="display: flex; align-items: center; justify-content: space-between; font-size: 13px; padding: 6px 8px; background: var(--bg-input); border-radius: var(--radius-sm);">
                                            <span style="color: #fff;">
                                                <span style="display:inline-block; width:9px; height:9px; border-radius:50%; background: <?php echo $online ? 'var(--green)' : 'var(--text-muted)'; ?>; margin-right:6px;"></span>
                                                <?php echo htmlspecialchars($tec['nome']); ?>
                                            </span>
                                            <span style="font-size: 11px; color: <?php echo $online ? 'var(--green)' : 'var(--text-muted)'; ?>;">
                                                <?php echo $online ? 'disponível' : ultimoAcessoTexto($tec['ultimo_acesso'] ?? null); ?>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (in_array($perfil_usuario, ['Admin', 'Responsavel']) && $podeTratar): ?>
                        <div class="card">
                            <h3 style="font-size: 15px; color: var(--accent); margin-bottom: 12px;">🎯 Atribuir a Técnico</h3>
                            <form action="ticket_detalhes.php?id=<?php echo $ticket_id; ?>" method="POST" style="display: flex; flex-direction: column; gap: 10px;">
                                <select name="id_tecnico" required style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                                    <option value="">-- Escolha o Técnico --</option>
                                    <?php foreach ($lista_tecnicos as $tec):
                                        $online = tecnicoEstaOnline($tec['ultimo_acesso'] ?? null, $tec['sessao_ativa'] ?? 1);
                                    ?>
                                        <option value="<?php echo $tec['id']; ?>" <?php echo ($ticket['id_tecnico_atribuido'] == $tec['id']) ? 'selected' : ''; ?>><?php echo ($online ? '🟢 ' : '⚪ ') . htmlspecialchars($tec['nome']) . ($online ? ' (disponível)' : ''); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" name="btn_atribuir" style="padding: 9px; background: var(--accent); border:none; color:white; border-radius:var(--radius-sm); cursor:pointer; font-weight:600; font-size:13px;">Confirmar Atribuição</button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <?php if ($podeEditarSla && !$souCriador): ?>
                        <?php
                            $prioActual = normalizarPrioridade($ticket['prioridade'] ?? 'Média');
                            $urgActual = catalogoNormalizarNivel($ticket['urgencia'] ?? $prioActual);
                            $impActual = $ticket['impacto'] ?? 'Utilizador';
                            $matrizP = catalogoMatrizSlaPrioridade()[$prioActual] ?? [120, 24];
                            $respDef = $matrizP[0];
                            $resolDef = $matrizP[1];
                            if (!empty($ticket['data_criacao']) && !empty($ticket['data_limite_sla'])) {
                                $resolDef = max(0.25, round((strtotime($ticket['data_limite_sla']) - strtotime($ticket['data_criacao'])) / 3600, 2));
                            }
                            if (!empty($ticket['data_criacao']) && !empty($ticket['data_limite_resposta'])) {
                                $respDef = max(1, (int)round((strtotime($ticket['data_limite_resposta']) - strtotime($ticket['data_criacao'])) / 60));
                            }
                        ?>
                        <div class="card" style="border: 1px solid rgba(61,111,255,0.3);">
                            <h3 style="font-size: 15px; color: var(--accent); margin-bottom: 8px;">SLA e Prioridade</h3>
                            <p style="font-size: 11px; color: var(--text-muted); margin-bottom: 10px;">
                                Definido automaticamente pelo catálogo. Editável por técnicos de Redes/Desenvolvimento e responsáveis.
                            </p>
                            <form action="ticket_detalhes.php?id=<?php echo $ticket_id; ?>" method="POST" style="display:flex; flex-direction:column; gap:10px;">
                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                                    <div>
                                        <label style="display:block; margin-bottom:4px; font-size:11px; color:var(--text-secondary);">Prioridade</label>
                                        <select name="prioridade_sla" style="width:100%; padding:8px; background:var(--bg-input); border:1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                                            <?php foreach (['Crítica','Alta','Média','Baixa'] as $p): ?>
                                            <option value="<?php echo $p; ?>" <?php echo $prioActual === $p ? 'selected' : ''; ?>><?php echo $p; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:4px; font-size:11px; color:var(--text-secondary);">Urgência</label>
                                        <select name="urgencia_sla" style="width:100%; padding:8px; background:var(--bg-input); border:1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                                            <?php foreach (['Crítica','Alta','Média','Baixa'] as $u): ?>
                                            <option value="<?php echo $u; ?>" <?php echo $urgActual === $u ? 'selected' : ''; ?>><?php echo $u; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <label style="display:block; margin-bottom:4px; font-size:11px; color:var(--text-secondary);">Impacto</label>
                                    <select name="impacto_sla" style="width:100%; padding:8px; background:var(--bg-input); border:1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                                        <?php foreach (['Utilizador','Equipa','Operação','Empresa'] as $i): ?>
                                        <option value="<?php echo $i; ?>" <?php echo $impActual === $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                                    <div>
                                        <label style="display:block; margin-bottom:4px; font-size:11px; color:var(--text-secondary);">Resposta (min)</label>
                                        <input type="number" name="sla_resposta_min" min="1" value="<?php echo (int)$respDef; ?>" style="width:100%; padding:8px; background:var(--bg-input); border:1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                                    </div>
                                    <div>
                                        <label style="display:block; margin-bottom:4px; font-size:11px; color:var(--text-secondary);">Resolução (h)</label>
                                        <input type="number" name="sla_resolucao_h" min="0.25" step="0.25" value="<?php echo htmlspecialchars((string)$resolDef); ?>" style="width:100%; padding:8px; background:var(--bg-input); border:1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                                    </div>
                                </div>
                                <button type="submit" name="btn_editar_sla" style="padding:9px; background:var(--accent); border:none; color:#fff; border-radius:var(--radius-sm); cursor:pointer; font-weight:600; font-size:13px;">Guardar SLA</button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <?php if ($podeEditar): ?>
                        <div class="card">
                            <h3 style="font-size: 15px; color: #fff; margin-bottom: 12px;">✏️ Editar Ticket</h3>
                            <form action="ticket_detalhes.php?id=<?php echo $ticket_id; ?>" method="POST" style="display: flex; flex-direction: column; gap: 10px;">
                                <div>
                                    <label style="display:block; margin-bottom:4px; font-size:12px; color:var(--text-secondary);">Assunto</label>
                                    <?php
                                    echo htmlSeletorAssuntoCascata(
                                        $pdo,
                                        $ticket['titulo'] ?? '',
                                        'edit',
                                        'width:100%; padding: 9px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);'
                                    );
                                    ?>
                                </div>
                                <div>
                                    <label style="display:block; margin-bottom:4px; font-size:12px; color:var(--text-secondary);">Prioridade</label>
                                    <select name="prioridade" style="width:100%; padding: 9px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                                        <option value="Baixa" <?php echo $ticket['prioridade'] === 'Baixa' ? 'selected' : ''; ?>>Baixa</option>
                                        <option value="Média" <?php echo $ticket['prioridade'] === 'Média' ? 'selected' : ''; ?>>Média</option>
                                        <option value="Alta" <?php echo $ticket['prioridade'] === 'Alta' ? 'selected' : ''; ?>>Alta</option>
                                        <option value="Crítica" <?php echo ($ticket['prioridade'] ?? '') === 'Crítica' ? 'selected' : ''; ?>>Crítica</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="display:block; margin-bottom:4px; font-size:12px; color:var(--text-secondary);">Descrição</label>
                                    <textarea name="descricao" rows="4" required style="width:100%; padding: 9px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm); resize: vertical; font-family:inherit; font-size:13px;"><?php echo htmlspecialchars($ticket['descricao'] ?? ''); ?></textarea>
                                </div>
                                <button type="submit" name="btn_editar_ticket" style="padding: 9px; background: var(--bg-input); border:1px solid var(--border); color:white; border-radius:var(--radius-sm); cursor:pointer; font-weight:600; font-size:13px;">Guardar Alterações</button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <?php if ($podeTratar && !$souCriador): ?>
                        <div class="card">
                            <h3 style="font-size: 15px; color: #fff; margin-bottom: 12px;">🔄 Alterar Estado</h3>
                            <form action="ticket_detalhes.php?id=<?php echo $ticket_id; ?>" method="POST" style="display: flex; flex-direction: column; gap: 10px;">
                                <select name="novo_estado" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                                    <option value="Aberto" <?php echo $ticket['estado'] === 'Aberto' ? 'selected' : ''; ?>>Aberto</option>
                                    <option value="Em Progresso" <?php echo in_array($ticket['estado'], ['Em Progresso', 'Reencaminhado'], true) ? 'selected' : ''; ?>>Em Progresso</option>
                                    <option value="Resolvido" <?php echo $ticket['estado'] === 'Resolvido' ? 'selected' : ''; ?>>Resolvido</option>
                                </select>
                                <?php if ($ticket['estado'] === 'Reencaminhado'): ?>
                                <p style="font-size: 11px; color: var(--text-secondary); margin: 0;">Estado actual: Reencaminhado. Para mudar de área use «Reencaminhar».</p>
                                <?php endif; ?>
                                <button type="submit" name="btn_alterar_estado" style="padding: 9px; background: var(--bg-input); border:1px solid var(--border); color:white; border-radius:var(--radius-sm); cursor:pointer; font-weight:600; font-size:13px;">Atualizar Fluxo</button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <?php if ($souCriador && $ticket['estado'] === 'Resolvido'): ?>
                        <div class="card" style="border: 1px solid rgba(16,185,129,0.3); background: rgba(16,185,129,0.03);">
                            <h3 style="font-size: 15px; color: var(--green); margin-bottom: 8px;">✔️ Ticket Resolvido</h3>
                            <p style="font-size: 12px; color: var(--text-secondary);">A equipa responsável marcou este ticket como resolvido.</p>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>
    <script>
        // Mostra/esconde o campo de texto livre quando se escolhe "Outros" no assunto
        function alternarOutroAssunto(select, idCampo) {
            var campo = document.getElementById(idCampo);
            if (!campo) return;
            var mostrar = select.value === '__outro__';
            campo.style.display = mostrar ? 'block' : 'none';
            if (mostrar) { campo.required = true; campo.focus(); } else { campo.required = false; }
        }
    </script>
    <script src="notificacoes.js"></script>
</body>
</html>