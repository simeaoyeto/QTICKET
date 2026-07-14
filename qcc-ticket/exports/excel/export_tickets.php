<?php
/**
 * KIAMI — Exportação Excel (.xls HTML) de tickets
 *
 * Gera ficheiro compatível com Excel via tabela HTML + BOM UTF-8.
 */
require_once __DIR__ . '/../../conexao.php';require_once __DIR__ . '/../../includes/relatorio_query.php';

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

registarAuditoria($pdo, 'Download Relatório', 'Exportação Excel de tickets');

$nome = 'relatorio_tickets_' . date('Y-m-d_His') . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nome . '"');
echo "\xEF\xBB\xBF";

echo '<html><head><meta charset="UTF-8"></head><body>';
echo '<table border="1">';
echo '<tr>';
$cabecalhos = ['Código', 'Assunto', 'Solicitante', 'Estado', 'Prioridade', 'Área', 'Operação', 'Técnico', 'Data Abertura', 'Data Resolução', 'Tempo de Resolução', 'SLA Limite', 'SLA Status'];
foreach ($cabecalhos as $h) {
    echo '<th style="background:#3b82f6;color:#fff;">' . htmlspecialchars($h) . '</th>';
}
echo '</tr>';

foreach ($tickets as $t) {
    $sla = estadoSlaTicket($t);
    $slaTexto = match ($sla) {
        'cumprido' => 'Cumprido', 'vencido' => 'Vencido', 'risco' => 'Em risco',
        'ok' => 'No prazo', 'resolvido' => 'Resolvido', default => '—',
    };
    $tempoResol = ($t['estado'] === 'Resolvido' && isset($t['minutos_resolucao']))
        ? formatarDuracaoMinutos((int)$t['minutos_resolucao'])
        : 'Em curso';
    echo '<tr>';
    echo '<td>' . htmlspecialchars($t['codigo'] ?? $t['id']) . '</td>';
    echo '<td>' . htmlspecialchars($t['titulo']) . '</td>';
    echo '<td>' . htmlspecialchars($t['solicitante'] ?? '') . '</td>';
    echo '<td>' . htmlspecialchars($t['estado']) . '</td>';
    echo '<td>' . htmlspecialchars($t['prioridade']) . '</td>';
    echo '<td>' . htmlspecialchars($t['area'] ?? '') . '</td>';
    echo '<td>' . htmlspecialchars($t['operacao'] ?? 'Interno') . '</td>';
    echo '<td>' . htmlspecialchars($t['tecnico'] ?? '') . '</td>';
    echo '<td>' . htmlspecialchars($t['data_criacao']) . '</td>';
    echo '<td>' . htmlspecialchars($t['data_resolucao'] ?? '') . '</td>';
    echo '<td>' . htmlspecialchars($tempoResol) . '</td>';
    echo '<td>' . htmlspecialchars($t['data_limite_sla'] ?? '') . '</td>';
    echo '<td>' . htmlspecialchars($slaTexto) . '</td>';
    echo '</tr>';
}

echo '</table></body></html>';
