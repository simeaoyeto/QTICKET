<?php
/**
 * KIAMI — Consultas partilhadas para relatórios e exportações
 *
 * Usado por relatorios.php, exports/pdf e exports/excel.
 * Filtros via GET/POST: datas, técnico, área, operação, prioridade, estado, SLA.
 */

/**
 * Monta SQL de listagem de tickets com JOINs e filtros opcionais.
 * Se $contexto for passado (e não for Admin/Diretor Geral), aplica o mesmo
 * filtro de visibilidade da listagem de tickets (área + operação do utilizador).
 *
 * @return array{0: string, 1: mixed[]} [SQL, parâmetros PDO]
 */
function construirQueryRelatorioTickets(array $filtros, ?array $contexto = null): array
{
    $where = ['1=1'];
    $params = [];

    // Escopo de visibilidade: técnicos/responsáveis só veem área + operações deles
    if ($contexto !== null) {
        $perfil = $contexto['perfil'] ?? '';
        if (!in_array($perfil, ['Admin', 'Diretor Geral'], true)) {
            [$clausulasVis, $paramsVis] = obterFiltroTickets($contexto);
            foreach ($clausulasVis as $c) {
                $where[] = $c;
            }
            foreach ($paramsVis as $p) {
                $params[] = $p;
            }
        }
    }

    // --- Filtros de data ---
    if (!empty($filtros['data_inicio'])) {
        $where[] = 't.data_criacao >= ?';
        $params[] = $filtros['data_inicio'] . ' 00:00:00';
    }
    if (!empty($filtros['data_fim'])) {
        $where[] = 't.data_criacao <= ?';
        $params[] = $filtros['data_fim'] . ' 23:59:59';
    }
    // --- Filtros de entidade ---
    if (!empty($filtros['id_tecnico'])) {
        $where[] = 't.id_tecnico_atribuido = ?';
        $params[] = (int)$filtros['id_tecnico'];
    }
    if (!empty($filtros['id_area'])) {
        $where[] = 't.id_area_destino = ?';
        $params[] = (int)$filtros['id_area'];
    }
    if (!empty($filtros['id_operacao'])) {
        $where[] = 't.id_operacao_origem = ?';
        $params[] = (int)$filtros['id_operacao'];
    }
    if (!empty($filtros['prioridade'])) {
        $where[] = 't.prioridade = ?';
        $params[] = $filtros['prioridade'];
    }
    if (!empty($filtros['estado'])) {
        $where[] = 't.estado = ?';
        $params[] = $filtros['estado'];
    }
    // --- Filtro SLA: cumprido vs vencido ---
    if (!empty($filtros['sla'])) {
        if ($filtros['sla'] === 'cumprido') {
            $where[] = "t.data_resolucao IS NOT NULL AND t.data_resolucao <= t.data_limite_sla";
        } elseif ($filtros['sla'] === 'vencido') {
            $where[] = "(t.data_resolucao IS NOT NULL AND t.data_resolucao > t.data_limite_sla) OR (t.data_resolucao IS NULL AND t.data_limite_sla < NOW() AND t.estado <> 'Resolvido')";
        }
    }

    $sql = "
        SELECT t.*,
               COALESCE(t.nome_solicitante, u.nome) AS solicitante,
               a.nome AS area,
               o.nome AS operacao,
               tec.nome AS tecnico,
               TIMESTAMPDIFF(HOUR, t.data_criacao, COALESCE(t.data_resolucao, NOW())) AS horas_resolucao,
               -- Tempo real de resolução (em minutos): só quando o ticket já foi resolvido
               CASE WHEN t.data_resolucao IS NOT NULL
                    THEN TIMESTAMPDIFF(MINUTE, t.data_criacao, t.data_resolucao)
                    ELSE NULL END AS minutos_resolucao
        FROM tickets t
        LEFT JOIN utilizadores u ON t.id_criador = u.id
        LEFT JOIN areas a ON t.id_area_destino = a.id
        LEFT JOIN operacoes o ON t.id_operacao_origem = o.id
        LEFT JOIN utilizadores tec ON t.id_tecnico_atribuido = tec.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY t.id DESC
    ";

    return [$sql, $params];
}

/** Lê filtros do pedido HTTP atual (GET ou POST) */
function obterFiltrosRelatorioFromRequest(): array
{
    return [
        'data_inicio' => $_GET['data_inicio'] ?? $_POST['data_inicio'] ?? '',
        'data_fim' => $_GET['data_fim'] ?? $_POST['data_fim'] ?? '',
        'id_tecnico' => $_GET['id_tecnico'] ?? $_POST['id_tecnico'] ?? '',
        'id_area' => $_GET['id_area'] ?? $_POST['id_area'] ?? '',
        'id_operacao' => $_GET['id_operacao'] ?? $_POST['id_operacao'] ?? '',
        'prioridade' => $_GET['prioridade'] ?? $_POST['prioridade'] ?? '',
        'estado' => $_GET['estado'] ?? $_POST['estado'] ?? '',
        'sla' => $_GET['sla'] ?? $_POST['sla'] ?? '',
    ];
}
