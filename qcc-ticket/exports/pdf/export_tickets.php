<?php
/**
 * KIAMI — Exportação PDF de tickets
 *
 * Aplica os mesmos filtros de relatorios.php. Acesso: Admin, áreas técnicas ou Diretor Geral.
 */
require_once __DIR__ . '/../../conexao.php';
require_once __DIR__ . '/../../includes/relatorio_query.php';
require_once __DIR__ . '/../../includes/pdf_export.php';

if (!isset($_SESSION['user_id'])) {
    die('Acesso negado.');
}

$contexto = obterContextoUsuario($pdo);
if (!podeAcederAdministracao($contexto) && $contexto['perfil'] !== 'Diretor Geral') {
    die('Acesso negado.');
}

$filtros = obterFiltrosRelatorioFromRequest();
[$sql, $params] = construirQueryRelatorioTickets($filtros, $contexto);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

registarAuditoria($pdo, 'Download Relatório', 'Exportação PDF de tickets');

// Resolver nomes dos filtros aplicados para o cabeçalho do PDF
$descricaoFiltros = [];
if (!empty($filtros['data_inicio'])) {
    $descricaoFiltros['Período (início)'] = date('d/m/Y', strtotime($filtros['data_inicio']));
}
if (!empty($filtros['data_fim'])) {
    $descricaoFiltros['Período (fim)'] = date('d/m/Y', strtotime($filtros['data_fim']));
}
if (!empty($filtros['id_area'])) {
    $stmtArea = $pdo->prepare('SELECT nome FROM areas WHERE id = ?');
    $stmtArea->execute([(int)$filtros['id_area']]);
    $descricaoFiltros['Área'] = $stmtArea->fetchColumn() ?: '—';
}
if (!empty($filtros['id_operacao'])) {
    $stmtOp = $pdo->prepare('SELECT nome FROM operacoes WHERE id = ?');
    $stmtOp->execute([(int)$filtros['id_operacao']]);
    $descricaoFiltros['Operação'] = $stmtOp->fetchColumn() ?: '—';
}
if (!empty($filtros['id_tecnico'])) {
    $stmtTec = $pdo->prepare('SELECT nome FROM utilizadores WHERE id = ?');
    $stmtTec->execute([(int)$filtros['id_tecnico']]);
    $descricaoFiltros['Técnico'] = $stmtTec->fetchColumn() ?: '—';
}
if (!empty($filtros['prioridade'])) {
    $descricaoFiltros['Prioridade'] = $filtros['prioridade'];
}
if (!empty($filtros['estado'])) {
    $descricaoFiltros['Estado'] = $filtros['estado'];
}
if (!empty($filtros['sla'])) {
    $descricaoFiltros['SLA'] = $filtros['sla'] === 'cumprido' ? 'Cumprido' : 'Vencido';
}
if (empty($descricaoFiltros)) {
    $descricaoFiltros['Filtros'] = 'Nenhum (todos os tickets)';
}

// Estatísticas resumidas
$contagemEstados = [
    'Aberto' => 0,
    'Em Progresso' => 0,
    'Reencaminhado' => 0,
    'Resolvido' => 0,
];
$slaCumprido = 0;
$slaVencido = 0;
$somaMinResolucao = 0;
$qtdResolvidos = 0;

foreach ($tickets as $t) {
    $estado = $t['estado'] ?? '';
    if (isset($contagemEstados[$estado])) {
        $contagemEstados[$estado]++;
    }
    $sla = estadoSlaTicket($t);
    if ($sla === 'cumprido') {
        $slaCumprido++;
    } elseif ($sla === 'vencido') {
        $slaVencido++;
    }
    // Acumula o tempo real de resolução (só tickets já resolvidos)
    if ($estado === 'Resolvido' && isset($t['minutos_resolucao'])) {
        $somaMinResolucao += (int)$t['minutos_resolucao'];
        $qtdResolvidos++;
    }
}
$mediaResolucao = $qtdResolvidos > 0 ? (int) round($somaMinResolucao / $qtdResolvidos) : null;

$pdf = new PdfExport('KIAMI — Relatório de Tickets', 'Quality Contact Center');

$pdf->secao('Filtros Aplicados');
$pdf->resumo($descricaoFiltros);

$pdf->secao('Resumo Estatístico');
$pdf->resumo([
    'Total de tickets' => (string)count($tickets),
    'Abertos' => (string)$contagemEstados['Aberto'],
    'Em Progresso' => (string)$contagemEstados['Em Progresso'],
    'Reencaminhados' => (string)$contagemEstados['Reencaminhado'],
    'Resolvidos' => (string)$contagemEstados['Resolvido'],
    'SLA cumprido' => (string)$slaCumprido,
    'SLA vencido' => (string)$slaVencido,
    'Tempo médio de resolução' => $mediaResolucao !== null ? formatarDuracaoMinutos($mediaResolucao) : '—',
]);

$pdf->secao('Listagem Detalhada');

$linhas = [];
foreach ($tickets as $t) {
    $sla = estadoSlaTicket($t);
    $slaTexto = match ($sla) {
        'cumprido' => 'Cumprido',
        'vencido' => 'Vencido',
        'risco' => 'Em risco',
        'ok' => 'No prazo',
        'resolvido' => 'Resolvido',
        default => '—',
    };

    // Tempo real de resolução; "Em curso" enquanto não estiver resolvido
    $tempoResol = ($t['estado'] === 'Resolvido' && isset($t['minutos_resolucao']))
        ? formatarDuracaoMinutos((int)$t['minutos_resolucao'])
        : 'Em curso';

    $linhas[] = [
        $t['codigo'] ?? ('#' . $t['id']),
        $t['titulo'],
        $t['estado'],
        $t['prioridade'],
        $t['area'] ?? '—',
        $t['operacao'] ?? '—',
        $t['tecnico'] ?? '—',
        $slaTexto,
        date('d/m/Y H:i', strtotime($t['data_criacao'])),
        $tempoResol,
    ];
}

$larguras = [46, 94, 50, 30, 52, 50, 52, 38, 60, 43];
$pdf->tabela(
    ['Código', 'Assunto', 'Estado', 'Prior.', 'Área', 'Operação', 'Técnico', 'SLA', 'Abertura', 'Tempo Resol.'],
    $linhas,
    $larguras
);

$conteudo = $pdf->output();
$nome = 'relatorio_tickets_' . date('Y-m-d_His') . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $nome . '"');
header('Content-Length: ' . strlen($conteudo));
echo $conteudo;
