<?php
/**
 * KIAMI — API JSON de notificações recentes (para alertas de ambiente de trabalho)
 *
 * Endpoint: api/notificacoes.php
 * Devolve as notificações não lidas relevantes para o utilizador autenticado.
 * É consultado periodicamente pelo JavaScript (notificacoes.js) que dispara
 * um alerta nativo do navegador/sistema quando surge uma nova notificação.
 *
 * Requer sessão autenticada.
 */
require_once __DIR__ . '/../conexao.php';
header('Content-Type: application/json; charset=utf-8');

// Sem sessão não há notificações a devolver
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['erro' => 'Não autenticado']);
    exit;
}

$contexto = obterContextoUsuario($pdo);
$perfil = $_SESSION['perfil'] ?? '';

// Aproveita cada sondagem para escalar tickets sem atendimento (5 e 10 min).
// Como notificacoes.js consulta este endpoint periodicamente, funciona como um
// "relógio" que dispara os avisos aos responsáveis sem necessitar de cron.
verificarEscalonamentoTickets($pdo);

$notificacoes = obterNotificacoesUtilizador($pdo, $contexto, 15, true);
// Manter só os campos usados pelo front-end
$notificacoes = array_map(static function (array $n): array {
    return [
        'id' => $n['id'] ?? null,
        'tipo' => $n['tipo'] ?? '',
        'mensagem' => $n['mensagem'] ?? '',
        'id_ticket' => $n['id_ticket'] ?? null,
        'data_criacao' => $n['data_criacao'] ?? null,
    ];
}, $notificacoes);

echo json_encode(['notificacoes' => $notificacoes], JSON_UNESCAPED_UNICODE);
