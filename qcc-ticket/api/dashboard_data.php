<?php
/**
 * KIAMI — API JSON do dashboard (AJAX)
 *
 * Endpoint: api/dashboard_data.php
 * Retorna estatísticas, gráficos e notificações respeitando obterFiltroTickets().
 * Requer sessão autenticada.
 */
require_once __DIR__ . '/../conexao.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['erro' => 'Não autenticado']);
    exit;
}

$contexto = obterContextoUsuario($pdo);
[$where_clauses, $params] = obterFiltroTickets($contexto);
$where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

function contar(PDO $pdo, string $sql, array $params): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

$base = "FROM tickets t $where_sql";
$and = $where_sql ? ' AND ' : ' WHERE ';

$stats = [
    'abertos' => contar($pdo, "SELECT COUNT(*) $base $and t.estado = 'Aberto'", $params),
    'progresso' => contar($pdo, "SELECT COUNT(*) $base $and t.estado = 'Em Progresso'", $params),
    'resolvidos' => contar($pdo, "SELECT COUNT(*) $base $and t.estado = 'Resolvido'", $params),
    'sla_risco' => 0,
    'sla_vencido' => 0,
    'tempo_medio_horas' => 0,
];

// SLA
$sqlAtivos = "SELECT t.* $base $and t.estado <> 'Resolvido'";
$stmt = $pdo->prepare($sqlAtivos);
$stmt->execute($params);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
    $e = estadoSlaTicket($t);
    if ($e === 'risco') $stats['sla_risco']++;
    if ($e === 'vencido') $stats['sla_vencido']++;
}

// Tempo médio resolução
$sqlMedio = "SELECT AVG(TIMESTAMPDIFF(HOUR, t.data_criacao, t.data_resolucao)) $base $and t.data_resolucao IS NOT NULL";
$stmt = $pdo->prepare($sqlMedio);
$stmt->execute($params);
$stats['tempo_medio_horas'] = round((float)$stmt->fetchColumn(), 1);

// Por área
$sqlArea = "SELECT a.nome AS label, COUNT(t.id) AS total FROM tickets t JOIN areas a ON t.id_area_destino = a.id $where_sql GROUP BY a.id, a.nome ORDER BY total DESC";
$stmt = $pdo->prepare($sqlArea);
$stmt->execute($params);
$por_area = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Por prioridade
$sqlPri = "SELECT t.prioridade AS label, COUNT(*) AS total FROM tickets t $where_sql GROUP BY t.prioridade";
$stmt = $pdo->prepare($sqlPri);
$stmt->execute($params);
$por_prioridade = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Por técnico (gráfico)
$sqlTec = "
    SELECT COALESCE(tec.nome, 'Não atribuído') AS label, COUNT(t.id) AS total
    FROM tickets t
    LEFT JOIN utilizadores tec ON t.id_tecnico_atribuido = tec.id
    $where_sql
    GROUP BY tec.id, tec.nome ORDER BY total DESC LIMIT 10
";
$stmt = $pdo->prepare($sqlTec);
$stmt->execute($params);
$por_tecnico = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Top Técnico — só para quem pode ver métricas técnicas no dashboard
$top_tecnico = [];
if (podeVerDashboardMetricasTecnicas($contexto)) {
    try {
        $visaoAmpla = in_array($contexto['perfil'] ?? '', ['Admin', 'Diretor Geral'], true)
            || utilizadorPertenceArea($contexto, AREA_DIRECAO);

        if ($visaoAmpla) {
            $sqlTop = "
                SELECT u.nome AS label,
                       COUNT(t.id) AS atribuidos,
                       SUM(CASE WHEN t.estado = 'Resolvido' THEN 1 ELSE 0 END) AS concluidos,
                       COUNT(t.id) AS total
                FROM utilizadores u
                LEFT JOIN tickets t ON t.id_tecnico_atribuido = u.id
                WHERE u.perfil IN ('Tecnico', 'Responsavel') AND u.estado = 'Ativo'
                GROUP BY u.id, u.nome
                HAVING atribuidos > 0
                ORDER BY concluidos DESC, atribuidos DESC
                LIMIT 10
            ";
            $top_tecnico = $pdo->query($sqlTop)->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $idsAreas = obterIdsAreasContexto($contexto);
            if (!empty($idsAreas)) {
                $ph = implode(',', array_fill(0, count($idsAreas), '?'));
                $sqlTopArea = "
                    SELECT u.nome AS label,
                           COUNT(t.id) AS atribuidos,
                           SUM(CASE WHEN t.estado = 'Resolvido' THEN 1 ELSE 0 END) AS concluidos,
                           COUNT(t.id) AS total
                    FROM utilizadores u
                    LEFT JOIN utilizador_areas ua ON ua.id_utilizador = u.id
                    LEFT JOIN tickets t ON t.id_tecnico_atribuido = u.id
                    WHERE u.perfil IN ('Tecnico', 'Responsavel') AND u.estado = 'Ativo'
                      AND (u.id_area IN ($ph) OR ua.id_area IN ($ph))
                    GROUP BY u.id, u.nome
                    HAVING atribuidos > 0
                    ORDER BY concluidos DESC, atribuidos DESC
                    LIMIT 10
                ";
                $stTop = $pdo->prepare($sqlTopArea);
                $stTop->execute(array_merge($idsAreas, $idsAreas));
                $top_tecnico = $stTop->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } catch (PDOException $e) {
        $top_tecnico = [];
    }
}

// Por estado (gráfico pizza)
$sqlEst = "SELECT t.estado AS label, COUNT(*) AS total FROM tickets t $where_sql GROUP BY t.estado";
$stmt = $pdo->prepare($sqlEst);
$stmt->execute($params);
$por_estado = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Notificações
$notificacoes = array_map(static function (array $n): array {
    return [
        'tipo' => $n['tipo'] ?? '',
        'mensagem' => $n['mensagem'] ?? '',
        'data_criacao' => $n['data_criacao'] ?? null,
    ];
}, obterNotificacoesUtilizador($pdo, $contexto, 8, true));

echo json_encode([
    'stats' => $stats,
    'por_area' => $por_area,
    'por_prioridade' => $por_prioridade,
    'por_tecnico' => $por_tecnico,
    'top_tecnico' => $top_tecnico,
    'por_estado' => $por_estado,
    'notificacoes' => $notificacoes,
    'atualizado' => date('d/m/Y H:i:s'),
]);
