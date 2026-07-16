<?php
/**
 * KIAMI — Listagem e gestão de tickets
 *
 * Funcionalidades: criar ticket, assumir, remover (Admin), DataTables.
 * Visibilidade controlada por obterFiltroTickets() conforme perfil/área/operação.
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
$meu_id_area = $contexto['id_area'];
$meu_id_operacao = $contexto['id_operacao'];
$user_id_num = $contexto['user_id_numerico'];

$mensagem = "";

// =========================================================
// PROCESSAR AÇÕES RÁPIDAS DA TABELA (GET)
// =========================================================
if (isset($_GET['acao']) && isset($_GET['id'])) {
    $id_ticket_acao = (int)$_GET['id'];
    $acao = $_GET['acao'];

    // Controlo de acesso: só se pode agir sobre tickets que o utilizador tem
    // permissão de ver (mesma área/operação, atribuído ou criado por si).
    $stmt_acesso = $pdo->prepare("SELECT * FROM tickets WHERE id = ?");
    $stmt_acesso->execute([$id_ticket_acao]);
    $ticket_acesso = $stmt_acesso->fetch(PDO::FETCH_ASSOC);

    if (!$ticket_acesso || !podeVerTicket($ticket_acesso, $contexto, $pdo)) {
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Não tem permissão para agir sobre este ticket.</div>";
        $acao = '';
    }

    if ($acao === 'assumir' && in_array($perfil_usuario, ['Admin', 'Responsavel', 'Tecnico'])) {
        // Quem abriu o ticket não pode assumi-lo (separação de funções)
        $criador_ticket = (int)($ticket_acesso['id_criador'] ?? 0);

        if ($criador_ticket === $user_id_num) {
            $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Não pode assumir um ticket que foi aberto por si.</div>";
        } elseif (!podeTratarTicket($ticket_acesso, $contexto)) {
            // Ticket reencaminhado para outra área — só a equipa que o recebeu o pode assumir
            $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Este ticket pertence agora a outra área. Só a equipa que o recebeu o pode assumir.</div>";
        } else {
        $stmt = $pdo->prepare("
            UPDATE tickets 
            SET id_tecnico_atribuido = ?, 
                estado = CASE WHEN estado = 'Aberto' THEN 'Em Progresso' ELSE estado END 
            WHERE id = ?
        ");
        $stmt->execute([$user_id_num, $id_ticket_acao]);
        registarHistoricoTicket($pdo, $id_ticket_acao, $user_id_num, 'Assumido', 'Técnico assumiu o ticket');
        notificarSolicitanteAtualizacaoTicket($pdo, $id_ticket_acao, 'Mudança de estado', 'Um técnico assumiu o seu ticket. O estado pode ter passado para «Em Progresso».');
        notificarPlataformaAtualizacaoTicket($pdo, $id_ticket_acao, 'Ticket em atendimento', 'Um técnico assumiu o ticket. O estado pode ter passado para «Em Progresso».', $user_id_num);
        $mensagem = "<div style='background: rgba(34,197,94,0.2); color: var(--green); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Assumiu o ticket #$id_ticket_acao. Já pode tratá-lo e resolvê-lo.</div>";
        }
    } elseif ($acao === 'remover' && $perfil_usuario === 'Admin') {
        // Remover também os comentários associados para evitar erros de integridade (Foreign Key)
        $stmt_del_com = $pdo->prepare("DELETE FROM comentarios WHERE id_ticket = ?");
        $stmt_del_com->execute([$id_ticket_acao]);

        // Apaga o ticket
        $stmt = $pdo->prepare("DELETE FROM tickets WHERE id = ?");
        $stmt->execute([$id_ticket_acao]);

        // RECONTAGEM AUTOMÁTICA DO AUTO_INCREMENT:
        // Procura o maior ID atual na tabela
        $max_id = $pdo->query("SELECT MAX(id) FROM tickets")->fetchColumn();
        // Se a tabela ficou vazia, o próximo será 1. Se não, será o Maior ID + 1
        $proximo_id = $max_id ? $max_id + 1 : 1;
        // Altera o contador interno do MySQL
        $pdo->exec("ALTER TABLE tickets AUTO_INCREMENT = $proximo_id");

        registarAuditoria($pdo, 'Exclusão', "Ticket #$id_ticket_acao eliminado");
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Ticket #$id_ticket_acao eliminado permanentemente!</div>";
    }
}

// =========================================================
// PROCESSAR ABERTURA DE NOVO TICKET (POST)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_criar_ticket'])) {
    // Assunto vem de uma lista de categorias (ou texto livre se escolher "Outros")
    $titulo = resolverAssuntoTicket($_POST);
    $descricao = trim($_POST['descricao'] ?? '');
    $prioridade = normalizarPrioridade($_POST['prioridade'] ?? 'Média');
    $id_area_destino = (int)($_POST['id_area_destino'] ?? 0);
    // Fallback: se não veio área no POST e o utilizador tem área, usa a dele por defeito
    if ($id_area_destino <= 0 && !empty($meu_id_area)) {
        $id_area_destino = (int)$meu_id_area;
    }
    // Operador: força a operação de origem (nunca altera)
    if ($perfil_usuario === 'Operador' && !empty($meu_id_operacao)) {
        $id_operacao_sel = (int)$meu_id_operacao;
    } else {
        $id_operacao_sel = (int)($_POST['id_operacao_origem'] ?? 0);
    }
    $id_operacao_ticket = $id_operacao_sel > 0 ? $id_operacao_sel : ($meu_id_operacao ?: null);
    $id_tecnico_atribuir = (int)($_POST['id_tecnico_atribuido'] ?? 0);

    // Processar imagem opcional (guardada em uploads/tickets)
    $upload = processarUploadImagem($_FILES['anexo'] ?? [], 'tickets');

    if (!$upload['sucesso']) {
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: " . htmlspecialchars($upload['erro']) . "</div>";
    } elseif (!areaDestinoPermitidaAbertura($id_area_destino, $contexto)) {
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Área de destino não permitida para o seu perfil. Operadores só podem abrir tickets para Redes & Sistemas ou Desenvolvimento.</div>";
    } elseif ($perfil_usuario === 'Operador' && empty($meu_id_operacao)) {
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>A sua conta de Operador não tem operação associada. Contacte Redes & Sistemas.</div>";
    } elseif ($id_tecnico_atribuir > 0 && (!podeAtribuirNaAbertura($contexto, $id_area_destino) || !tecnicoPertenceArea($pdo, $id_tecnico_atribuir, $id_area_destino))) {
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Só pode atribuir a um técnico da área de destino escolhida (a sua área).</div>";
    } elseif ($id_tecnico_atribuir > 0 && $id_tecnico_atribuir === $user_id_num) {
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Não pode atribuir o ticket a si próprio na abertura. Escolha outro técnico da área.</div>";
    } elseif (!empty($titulo) && !empty($descricao) && $id_area_destino > 0 && $user_id_num) {
        $codigo = gerarCodigoTicket($pdo);
        $dataLimite = calcularDataLimiteSla($prioridade);

        // Email do solicitante: campo do formulário (se preenchido) ou email da conta
        $email_form = trim($_POST['email_solicitante'] ?? '');
        $stmt_email = $pdo->prepare("SELECT email FROM utilizadores WHERE id = ?");
        $stmt_email->execute([$user_id_num]);
        $email_conta = $stmt_email->fetchColumn() ?: null;
        $email_criador = null;
        if ($email_form !== '' && filter_var($email_form, FILTER_VALIDATE_EMAIL)) {
            $email_criador = $email_form;
        } elseif (!empty($email_conta) && filter_var($email_conta, FILTER_VALIDATE_EMAIL)) {
            $email_criador = $email_conta;
        }

        $estadoInicial = $id_tecnico_atribuir > 0 ? 'Em Progresso' : 'Aberto';
        $tecnicoSql = $id_tecnico_atribuir > 0 ? $id_tecnico_atribuir : null;

        $stmt_cad = $pdo->prepare("
            INSERT INTO tickets (codigo, titulo, nome_solicitante, email_solicitante, descricao, prioridade, estado, anexo, id_criador, id_area_destino, id_operacao_origem, id_tecnico_atribuido, data_criacao, data_limite_sla) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
        ");
        // Tenta inserir; se o índice UNIQUE rejeitar o código (concorrência), regenera e repete
        $tentativas = 0;
        while (true) {
            try {
                $stmt_cad->execute([$codigo, $titulo, $nome_usuario, $email_criador, $descricao, $prioridade, $estadoInicial, $upload['caminho'], $user_id_num, $id_area_destino, $id_operacao_ticket, $tecnicoSql, $dataLimite]);
                break;
            } catch (PDOException $e) {
                if ($e->getCode() === '23000' && $tentativas < 5) {
                    $tentativas++;
                    $codigo = gerarCodigoTicket($pdo);
                    continue;
                }
                throw $e;
            }
        }
        $novoId = (int)$pdo->lastInsertId();

        registarHistoricoTicket($pdo, $novoId, $user_id_num, 'Criação', "Ticket criado com código $codigo" . ($tecnicoSql ? " e atribuído ao técnico #$tecnicoSql" : ''));
        if ($tecnicoSql) {
            registarHistoricoTicket($pdo, $novoId, $user_id_num, 'Atribuição', "Ticket atribuído ao técnico #$tecnicoSql na abertura");
            criarNotificacao($pdo, $tecnicoSql, null, 'Ticket Atribuído', "Foi-lhe atribuído o ticket $codigo", $novoId);
            notificarPlataformaAtualizacaoTicket($pdo, $novoId, 'Ticket atribuído', 'O ticket foi atribuído a um técnico na abertura.', $user_id_num);
        }
        registarAuditoria($pdo, 'Criação', "Ticket $codigo criado");

        // Notifica área de destino (+ INACOM → Redes e Desenvolvimento) e envia email à caixa da área
        notificarDestinosNovoTicket($pdo, $novoId);

        // Aviso automático por email ao solicitante (confirmação de abertura com o código)
        notificarSolicitanteNovoTicket($pdo, $novoId);

        $avisoEmail = $email_criador
            ? " Foi enviado um email de confirmação para <b>" . htmlspecialchars($email_criador) . "</b>."
            : " <span style='color:var(--amber);'>Não foi possível enviar email: indique um email válido no formulário ou associe um email à sua conta.</span>";
        $avisoAtrib = $tecnicoSql ? " O ticket ficou <b>Em Progresso</b> e atribuído ao técnico seleccionado." : '';
        $mensagem = "<div style='background: rgba(34,197,94,0.2); color: var(--green); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Ticket <b>$codigo</b> submetido com sucesso! A equipa técnica foi notificada.{$avisoAtrib}{$avisoEmail}</div>";
    } else {
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: Preencha todos os campos obrigatórios.</div>";
    }
}

// =========================================================
// CONSTRUÇÃO DO FILTRO DINÂMICO DE VISUALIZAÇÃO
// =========================================================
$filtro_estado = $_GET['estado'] ?? ($_GET['filtro'] ?? 'Todos');
[$where_clauses, $params] = obterFiltroTickets($contexto);

if ($filtro_estado === 'Atribuidos') {
    $where_clauses[] = "t.id_tecnico_atribuido = ?";
    $params[] = $user_id_num;
} elseif ($filtro_estado !== 'Todos') {
    $where_clauses[] = "t.estado = ?";
    $params[] = $filtro_estado;
}

$where_sql = "";
if (count($where_clauses) > 0) {
    $where_sql = "WHERE " . implode(" AND ", $where_clauses);
}

$query_tickets = "
    SELECT t.*, 
           u.nome AS nome_criador, 
           a.nome AS nome_area, 
           o.nome AS nome_operacao
    FROM tickets t
    LEFT JOIN utilizadores u ON t.id_criador = u.id
    LEFT JOIN areas a ON t.id_area_destino = a.id
    LEFT JOIN operacoes o ON t.id_operacao_origem = o.id
    $where_sql
    ORDER BY 
        CASE t.prioridade WHEN 'Alta' THEN 1 WHEN 'Média' THEN 2 WHEN 'Baixa' THEN 3 END, 
        t.id DESC
";

$stmt_tickets = $pdo->prepare($query_tickets);
$stmt_tickets->execute($params);
$lista_tickets = $stmt_tickets->fetchAll(PDO::FETCH_ASSOC);

// Contagens por filtro (visibilidade já aplicada)
[$baseWhere, $baseParams] = obterFiltroTickets($contexto);
$contarComFiltro = function (string $extraSql = '', array $extraParams = []) use ($pdo, $baseWhere, $baseParams): int {
    $w = $baseWhere;
    $p = $baseParams;
    if ($extraSql !== '') {
        $w[] = $extraSql;
        foreach ($extraParams as $ep) {
            $p[] = $ep;
        }
    }
    $sqlWhere = count($w) > 0 ? ('WHERE ' . implode(' AND ', $w)) : '';
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM tickets t $sqlWhere");
        $st->execute($p);
        return (int)$st->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
};
$contagens = [
    'Todos' => $contarComFiltro(),
    'Aberto' => $contarComFiltro('t.estado = ?', ['Aberto']),
    'Em Progresso' => $contarComFiltro('t.estado = ?', ['Em Progresso']),
    'Reencaminhado' => $contarComFiltro('t.estado = ?', ['Reencaminhado']),
    'Resolvido' => $contarComFiltro('t.estado = ?', ['Resolvido']),
    'Atribuidos' => $contarComFiltro('t.id_tecnico_atribuido = ?', [$user_id_num]),
];

$areas_destino = obterAreasDestinoAbertura($pdo, $contexto);
$operacoes_lista = obterOperacoesAbertura($pdo, $contexto);

// Técnicos por área (para atribuir na abertura quando o destino é a própria área)
$tecnicosPorArea = [];
$podeMostrarAtribuicao = in_array($perfil_usuario, ['Admin', 'Tecnico', 'Responsavel'], true);
if ($podeMostrarAtribuicao) {
    $areasParaTec = $perfil_usuario === 'Admin'
        ? $areas_destino
        : array_filter($areas_destino, static fn($a) => utilizadorPertenceArea($contexto, (int)$a['id']));
    foreach ($areasParaTec as $ad) {
        $listaTec = obterTecnicosDaArea($pdo, (int)$ad['id']);
        $tecnicosPorArea[(int)$ad['id']] = array_values(array_filter(
            $listaTec,
            static fn($t) => (int)$t['id'] !== $user_id_num
        ));
    }
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KIAMI - Tickets</title>
    <link rel="stylesheet" href="style.css">
    <script src="tema.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script></head>
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
                <a href="usuarios_lista.php" class="nav-item">👥 <span>Gestão de Utilizadores</span></a>
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
            <div class="page-header">
                <h1>Painel de Incidentes e Solicitações</h1>
                <p>Acompanhe em tempo real o progresso dos seus pedidos de suporte.</p>
            </div>

            <?php echo $mensagem; ?>

            <div style="display: flex; gap: 10px; margin-bottom: 25px; flex-wrap: wrap;">
                <?php
                $tabsFiltro = [
                    'Todos' => ['label' => '📋 Todos', 'href' => 'tickets_lista.php?estado=Todos'],
                    'Aberto' => ['label' => '📂 Abertos', 'href' => 'tickets_lista.php?estado=Aberto'],
                    'Em Progresso' => ['label' => '⏳ Em Progresso', 'href' => 'tickets_lista.php?estado=Em Progresso'],
                    'Atribuidos' => ['label' => '👤 Atribuídos', 'href' => 'tickets_lista.php?estado=Atribuidos'],
                    'Reencaminhado' => ['label' => '🔀 Reencaminhados', 'href' => 'tickets_lista.php?estado=Reencaminhado'],
                    'Resolvido' => ['label' => '✅ Resolvidos', 'href' => 'tickets_lista.php?estado=Resolvido'],
                ];
                foreach ($tabsFiltro as $chave => $tab):
                    $activo = ($filtro_estado === $chave);
                    $n = (int)($contagens[$chave] ?? 0);
                ?>
                <a href="<?php echo htmlspecialchars($tab['href']); ?>" style="padding: 8px 16px; background: <?php echo $activo ? 'var(--accent)' : 'var(--bg-sidebar)'; ?>; color:#fff; border-radius: var(--radius-sm); text-decoration:none; font-size:13px; font-weight:500;">
                    <?php echo $tab['label']; ?> <span style="opacity:.85; font-size:11px;">(<?php echo $n; ?>)</span>
                </a>
                <?php endforeach; ?>
            </div>

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 25px; align-items: start;">
                
                <div class="card" style="padding:0; overflow: hidden;">
                    <table id="tabela-tickets" style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
                        <thead>
                            <tr style="background: var(--bg-sidebar); border-bottom: 1px solid var(--border);">
                                <th style="padding: 15px;">Código / Assunto</th>
                                <th style="padding: 15px;">Origem</th>
                                <th style="padding: 15px;">Destino</th>
                                <th style="padding: 15px;">Prioridade</th>
                                <th style="padding: 15px;">SLA</th>
                                <th style="padding: 15px;">Estado</th>
                                <th style="padding: 15px; text-align: center;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lista_tickets as $ticket): 
                                $sla = estadoSlaTicket($ticket);
                                $slaCor = match($sla) { 'vencido' => 'var(--red)', 'risco' => 'var(--amber)', 'cumprido' => 'var(--green)', default => 'var(--text-muted)' };
                                $slaTexto = match($sla) { 'vencido' => 'Vencido', 'risco' => 'Em risco', 'cumprido' => 'Cumprido', 'ok' => 'No prazo', default => '-' };
                                $nomeExibir = !empty($ticket['nome_solicitante']) ? $ticket['nome_solicitante'] : ($ticket['nome_criador'] ?? 'Anónimo');
                            ?>
                                <tr style="border-bottom: 1px solid var(--border); background: rgba(255,255,255,0.01);">
                                    <td style="padding: 15px;">
                                        <div style="font-weight:600; color:#fff; font-size:14px; margin-bottom:4px;"><?php echo htmlspecialchars($ticket['codigo'] ?? ('#' . $ticket['id'])); ?> — <?php echo htmlspecialchars($ticket['titulo']); ?></div>
                                        <div style="font-size:12px; color:var(--text-muted);">Por: <?php echo htmlspecialchars($nomeExibir); ?></div>
                                    </td>
                                    <td style="padding: 15px; color:var(--text-secondary);">
                                        <?php echo $ticket['nome_operacao'] ? '📱 ' . htmlspecialchars($ticket['nome_operacao']) : '📱 Interno'; ?>
                                    </td>
                                    <td style="padding: 15px;">
                                        <span style="color:var(--accent); font-weight:500;">🛠️ <?php echo htmlspecialchars($ticket['nome_area']); ?></span>
                                    </td>
                                    <td style="padding: 15px;">
                                        <?php 
                                            $p_color = 'var(--text-secondary)';
                                            if($ticket['prioridade'] === 'Alta') $p_color = 'var(--red)';
                                            if($ticket['prioridade'] === 'Média') $p_color = 'var(--amber)';
                                        ?>
                                        <span style="color: <?php echo $p_color; ?>; font-weight: 600;"><?php echo $ticket['prioridade']; ?></span>
                                    </td>
                                    <td style="padding: 15px;">
                                        <span style="color: <?php echo $slaCor; ?>; font-size: 12px; font-weight: 600;"><?php echo $slaTexto; ?></span>
                                    </td>
                                    <td style="padding: 15px;">
                                        <?php 
                                            $status_style = "color: var(--green); background: rgba(16,185,129,0.1);";
                                            if($ticket['estado'] === 'Aberto') $status_style = "color: #3b82f6; background: rgba(59,130,246,0.1);";
                                            if($ticket['estado'] === 'Em Progresso') $status_style = "color: var(--amber); background: rgba(245,158,11,0.1);";
                                        ?>
                                        <span style="<?php echo $status_style; ?> padding: 4px 8px; border-radius: 10px; font-size: 11px; font-weight:600; text-transform: uppercase;"><?php echo $ticket['estado']; ?></span>
                                    </td>
                                    
                                    <td style="padding: 15px; text-align: center; white-space: nowrap;">
                                        <?php
                                        $podeTratarLinha = podeTratarTicket($ticket, $contexto)
                                            && (int)$ticket['id_criador'] !== $user_id_num
                                            && $ticket['estado'] !== 'Resolvido';
                                        ?>
                                        <?php if ($podeTratarLinha): ?>
                                            <a href="ticket_detalhes.php?id=<?php echo $ticket['id']; ?>" style="color: #fff; text-decoration: none; font-weight: 600; font-size: 12px; background: var(--bg-input); padding: 4px 8px; border-radius: var(--radius-sm); border: 1px solid var(--border); margin-right: 5px;">Tratar</a>
                                        <?php else: ?>
                                            <a href="ticket_detalhes.php?id=<?php echo $ticket['id']; ?>" style="color: var(--accent); text-decoration: none; font-weight: 600; font-size: 12px; background: rgba(61,111,255,0.08); padding: 4px 8px; border-radius: var(--radius-sm); border: 1px solid rgba(61,111,255,0.35); margin-right: 5px;">👁️ Acompanhar estado</a>
                                        <?php endif; ?>

                                        <?php if ($podeTratarLinha && (int)$ticket['id_tecnico_atribuido'] !== $user_id_num): ?>
                                            <a href="tickets_lista.php?acao=assumir&id=<?php echo $ticket['id']; ?>" style="color: var(--green); text-decoration: none; font-weight: 600; font-size: 12px; margin-right: 5px;" onclick="return confirm('Assumir este ticket? Ficará responsável por resolvê-lo.');">Assumir</a>
                                        <?php endif; ?>

                                        <?php if ($perfil_usuario === 'Admin'): ?>
                                            <a href="tickets_lista.php?acao=remover&id=<?php echo $ticket['id']; ?>" style="color: var(--red); text-decoration: none; font-weight: 600; font-size: 12px;" onclick="return confirm('ATENÇÃO: Isto apagará o ticket e todo o seu histórico de mensagens permanentemente. Continuar?');">Remover</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card">
                    <h3 style="margin-bottom: 15px; font-size: 16px; color: var(--accent);">📝 Abrir Novo Ticket</h3>
                    <form action="tickets_lista.php" method="POST" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: 15px;">
                        <div>
                            <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Assunto</label>
                            <select name="assunto_predefinido" required onchange="alternarOutroAssunto(this, 'novo_outro')" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                                <option value="">-- Selecione o assunto --</option>
                                <?php echo opcoesAssuntoTicket('', $pdo); ?>
                            </select>
                            <input type="text" name="assunto_outro" id="novo_outro" placeholder="Escreva o assunto" style="width:100%; margin-top:6px; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm); display:none;">
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Email para notificações</label>
                            <?php
                            $emailContaForm = '';
                            try {
                                $stEm = $pdo->prepare("SELECT email FROM utilizadores WHERE id = ?");
                                $stEm->execute([$user_id_num]);
                                $emailContaForm = (string)($stEm->fetchColumn() ?: '');
                            } catch (PDOException $e) {
                                $emailContaForm = '';
                            }
                            ?>
                            <input type="email" name="email_solicitante" value="<?php echo htmlspecialchars($emailContaForm); ?>" placeholder="ex: seu.email@empresa.co.ao" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                            <small style="color: var(--text-muted); font-size: 11px;">Receberá confirmação de abertura e aviso sempre que o estado do ticket mudar.</small>
                        </div>
                        <div>
                            <?php
                            $areaAuto = (int)($meu_id_area ?? 0);
                            if ($areaAuto <= 0 && $perfil_usuario === 'Operador') {
                                $areaAuto = 1;
                            }
                            $isOperador = ($perfil_usuario === 'Operador');
                            $operacaoTrancada = $isOperador && !empty($meu_id_operacao);
                            $nomeMinhaArea = '';
                            if ($areaAuto > 0) {
                                foreach ($areas_destino as $ad) {
                                    if ((int)$ad['id'] === $areaAuto) {
                                        $nomeMinhaArea = $ad['nome'];
                                        break;
                                    }
                                }
                                if ($nomeMinhaArea === '' && !$isOperador) {
                                    try {
                                        $stNm = $pdo->prepare("SELECT nome FROM areas WHERE id = ?");
                                        $stNm->execute([$areaAuto]);
                                        $nomeMinhaArea = (string)($stNm->fetchColumn() ?: '');
                                    } catch (PDOException $e) {
                                        $nomeMinhaArea = '';
                                    }
                                }
                            }
                            ?>
                            <?php if (!$isOperador && $areaAuto > 0): ?>
                                <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Área / Direção (a minha)</label>
                                <input type="text" value="<?php echo htmlspecialchars($nomeMinhaArea !== '' ? $nomeMinhaArea : 'A minha área'); ?>" disabled style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm); opacity:.9;">
                                <small style="color: var(--text-muted); font-size: 11px; display:block; margin-bottom:12px;">Identifica a área a que pertence (ex: Formadores).</small>

                                <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Destino — áreas da empresa</label>
                                <select name="id_area_destino" id="sel-area-destino" required onchange="actualizarTecnicosAbertura()" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                                    <option value="">-- Escolher área de destino --</option>
                                    <?php foreach ($areas_destino as $ad): ?>
                                        <option value="<?php echo $ad['id']; ?>" <?php echo ((int)$ad['id'] === $areaAuto) ? 'selected' : ''; ?>><?php echo htmlspecialchars($ad['nome']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small style="color: var(--text-muted); font-size: 11px;">RH, Redes &amp; Sistemas, Desenvolvimento, Formadores, Direção e restantes áreas.</small>
                            <?php else: ?>
                                <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Área / Direção de Destino</label>
                                <select name="id_area_destino" id="sel-area-destino" required onchange="actualizarTecnicosAbertura()" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                                    <option value="">-- Selecionar área / direcção --</option>
                                    <?php foreach($areas_destino as $ad): ?>
                                        <option value="<?php echo $ad['id']; ?>" <?php echo ($areaAuto > 0 && (int)$ad['id'] === $areaAuto) ? 'selected' : ''; ?>><?php echo htmlspecialchars($ad['nome']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small style="color: var(--text-muted); font-size: 11px;">
                                    <?php if ($isOperador): ?>
                                        Operadores podem abrir tickets para <b>Redes &amp; Sistemas</b> ou <b>Desenvolvimento</b>.
                                    <?php else: ?>
                                        Escolha a área ou direcção que deve receber o ticket.
                                    <?php endif; ?>
                                </small>
                            <?php endif; ?>
                        </div>
                        <?php if ($podeMostrarAtribuicao && !empty($tecnicosPorArea)): ?>
                        <div id="bloco-atribuir-tecnico" style="display:none;">
                            <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Atribuir a técnico da área (opcional)</label>
                            <select name="id_tecnico_atribuido" id="sel-tecnico-abertura" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                                <option value="">-- Sem atribuição (fica em Aberto) --</option>
                            </select>
                            <small style="color: var(--text-muted); font-size: 11px;">Disponível quando o destino é a sua área. O ticket passa a Em Progresso.</small>
                        </div>
                        <?php endif; ?>
                        <div>
                            <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Operação</label>
                            <?php if ($operacaoTrancada): ?>
                                <select name="id_operacao_origem" disabled style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                                    <?php foreach($operacoes_lista as $op): ?>
                                        <option value="<?php echo $op['id']; ?>" selected><?php echo htmlspecialchars($op['nome']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="id_operacao_origem" value="<?php echo (int)$meu_id_operacao; ?>">
                                <small style="color: var(--text-muted); font-size: 11px;">A operação está trancada no seu perfil e não pode ser alterada.</small>
                            <?php else: ?>
                                <select name="id_operacao_origem" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                                    <option value="">-- Interno / opcional --</option>
                                    <?php foreach($operacoes_lista as $op): ?>
                                        <option value="<?php echo $op['id']; ?>" <?php echo ((int)$meu_id_operacao === (int)$op['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($op['nome']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small style="color: var(--text-muted); font-size: 11px;">Se escolher <b>INACOM</b>, o ticket fica automaticamente visível para Redes &amp; Sistemas e Desenvolvimento.</small>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Nível de Urgência</label>
                            <select name="prioridade" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                                <option value="Baixa">🟢 Baixa</option>
                                <option value="Média" selected>🟡 Média</option>
                                <option value="Alta">🔴 Alta</option>
                            </select>
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Descrição Detalhada</label>
                            <textarea name="descricao" required rows="5" placeholder="Descreva o problema..." style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm); resize: vertical; font-family:inherit; font-size:13px;"></textarea>
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Imagem (opcional)</label>
                            <input type="file" name="anexo" accept="image/png, image/jpeg, image/gif, image/webp" style="width:100%; padding: 8px; background: var(--bg-input); border: 1px solid var(--border); color:var(--text-secondary); border-radius:var(--radius-sm); font-size:12px;">
                            <small style="color: var(--text-muted); font-size: 11px;">Captura de ecrã do problema (JPG, PNG, GIF, WEBP — máx. 5 MB).</small>
                        </div>
                        <button type="submit" name="btn_criar_ticket" style="padding: 12px; background: var(--accent); border:none; color:white; border-radius:var(--radius-sm); cursor:pointer; font-weight:600; text-align:center;">Submeter Ticket</button>
                    </form>
                </div>

            </div>
        </div>
    </div>
<script>
$(document).ready(function() {
    $('#tabela-tickets').DataTable({
        pageLength: 15,
        order: [],
        scrollY: '55vh',          // barra de rolagem vertical no corpo da tabela
        scrollCollapse: true,     // encolhe se houver poucos registos
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.8/i18n/pt-PT.json',
            emptyTable: 'Nenhum ticket encontrado.'
        },
        columnDefs: [{ orderable: false, targets: -1 }]
    });
});

// Mostra o campo de texto livre quando se escolhe "Outros" no assunto
function alternarOutroAssunto(select, idCampo) {
    var campo = document.getElementById(idCampo);
    if (!campo) return;
    var mostrar = select.value === '__outro__';
    campo.style.display = mostrar ? 'block' : 'none';
    campo.required = mostrar;
    if (mostrar) campo.focus();
}

const tecnicosPorArea = <?php echo json_encode($tecnicosPorArea ?? new stdClass(), JSON_UNESCAPED_UNICODE); ?>;
function actualizarTecnicosAbertura() {
    var bloco = document.getElementById('bloco-atribuir-tecnico');
    var selTec = document.getElementById('sel-tecnico-abertura');
    var selArea = document.getElementById('sel-area-destino');
    if (!bloco || !selTec || !selArea) return;
    var idArea = String(selArea.value || '');
    var lista = (tecnicosPorArea && tecnicosPorArea[idArea]) ? tecnicosPorArea[idArea] : [];
    selTec.innerHTML = '<option value="">-- Sem atribuição (fica em Aberto) --</option>';
    if (lista.length) {
        bloco.style.display = 'block';
        lista.forEach(function (t) {
            var opt = document.createElement('option');
            opt.value = t.id;
            opt.textContent = t.nome + (t.perfil ? ' (' + t.perfil + ')' : '');
            selTec.appendChild(opt);
        });
    } else {
        bloco.style.display = 'none';
        selTec.value = '';
    }
}
document.addEventListener('DOMContentLoaded', function () { actualizarTecnicosAbertura(); });
</script>
    <script src="notificacoes.js"></script>
</body>
</html>