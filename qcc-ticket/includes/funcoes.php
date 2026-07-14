<?php
/**
 * KIAMI — Funções centrais do sistema
 *
 * Este ficheiro concentra a lógica de negócio reutilizada em todo o projeto:
 * - SLA, códigos de ticket e prioridades
 * - Permissões e visibilidade por perfil/área/operação
 * - Auditoria, histórico, notificações e segurança de login
 *
 * Áreas técnicas (grupo = área): id 1 = Redes & Sistemas, id 2 = Desenvolvimento
 */

/** Converte rótulos de perfil do formulário para o valor guardado na BD */
function mapearPerfilParaBd(string $perfil): string
{
    $mapa = [
        'Utilizador Comum' => 'Comum',
        'Cliente Operacional' => 'Operador', // legado → Operador
        'Cliente' => 'Operador',
        'Responsável de Área' => 'Responsavel',
    ];
    return $mapa[$perfil] ?? $perfil;
}

/** ID da área Direção */
const AREA_DIRECAO = 3;

/**
 * Valida email de conta.
 * @return array{0:bool,1:?string} [ok, emailNormalizadoOuNull] — se !ok, índice 1 é mensagem de erro
 */
function validarEmailConta(?string $email, bool $obrigatorioQuality = false): array
{
    $email = trim((string)$email);
    if ($email === '') {
        if ($obrigatorioQuality) {
            return [false, 'O email é obrigatório e deve terminar em @quality.co.ao.'];
        }
        return [true, null];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Indique um email válido.'];
    }
    if ($obrigatorioQuality && !preg_match('/@quality\.co\.ao$/i', $email)) {
        return [false, 'O email deve terminar em @quality.co.ao (ex: nome.sobrenome@quality.co.ao).'];
    }
    return [true, $email];
}

/** Gera (ou reutiliza) o token CSRF da sessão */
function gerarTokenCsrf(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Valida token CSRF (timing-safe) */
function validarTokenCsrf(?string $token): bool
{
    $esperado = $_SESSION['csrf_token'] ?? '';
    if ($esperado === '' || $token === null || $token === '') {
        return false;
    }
    return hash_equals($esperado, $token);
}

/**
 * Técnicos/Responsáveis activos de uma área (coluna principal ou multiárea).
 *
 * @return array<int, array{id:int|string, nome:string, perfil:string, ultimo_acesso?:mixed, sessao_ativa?:mixed}>
 */
function obterTecnicosDaArea(PDO $pdo, int $idArea): array
{
    if ($idArea <= 0) {
        return [];
    }
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT u.id, u.nome, u.perfil, u.ultimo_acesso, u.sessao_ativa
            FROM utilizadores u
            LEFT JOIN utilizador_areas ua ON ua.id_utilizador = u.id
            WHERE u.estado = 'Ativo'
              AND u.perfil IN ('Tecnico', 'Responsavel')
              AND (u.id_area = ? OR ua.id_area = ?)
            ORDER BY u.nome ASC
        ");
        $stmt->execute([$idArea, $idArea]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/** True se o técnico/responsável pertence à área indicada */
function tecnicoPertenceArea(PDO $pdo, int $idTecnico, int $idArea): bool
{
    if ($idTecnico <= 0 || $idArea <= 0) {
        return false;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT 1 FROM utilizadores u
            LEFT JOIN utilizador_areas ua ON ua.id_utilizador = u.id
            WHERE u.id = ? AND u.estado = 'Ativo' AND u.perfil IN ('Tecnico', 'Responsavel')
              AND (u.id_area = ? OR ua.id_area = ?)
            LIMIT 1
        ");
        $stmt->execute([$idTecnico, $idArea, $idArea]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Pode atribuir técnico já na abertura do ticket
 * (destino = uma das suas áreas, ou Admin).
 */
function podeAtribuirNaAbertura(array $contexto, int $idAreaDestino): bool
{
    if ($idAreaDestino <= 0) {
        return false;
    }
    if (($contexto['perfil'] ?? '') === 'Admin') {
        return true;
    }
    if (!in_array($contexto['perfil'] ?? '', ['Tecnico', 'Responsavel'], true)) {
        return false;
    }
    return utilizadorPertenceArea($contexto, $idAreaDestino);
}

/** Normaliza prioridade (corrige "Media" legado → "Média") */
function normalizarPrioridade(string $prioridade): string
{
    $prioridade = trim($prioridade);
    if ($prioridade === 'Media') {
        return 'Média';
    }
    return in_array($prioridade, ['Alta', 'Média', 'Baixa'], true) ? $prioridade : 'Média';
}

/**
 * Calcula data limite do SLA conforme prioridade:
 * Alta = 4h | Média = 24h | Baixa = 72h
 *
 * @param string      $prioridade Prioridade do ticket
 * @param string|null $dataBase   Data de referência (por omissão "agora"). Ao editar
 *                                a prioridade de um ticket existente, deve passar-se a
 *                                data de criação para o prazo continuar justo.
 */
function calcularDataLimiteSla(string $prioridade, ?string $dataBase = null): string
{
    $horas = match (normalizarPrioridade($prioridade)) {
        'Alta' => 4,
        'Baixa' => 72,
        default => 24,
    };
    $base = $dataBase ? strtotime($dataBase) : time();
    return date('Y-m-d H:i:s', strtotime("+{$horas} hours", $base));
}

/**
 * Gera código sequencial único no formato QCC-AAAA-NNNNNN (ex: QCC-2026-000001).
 * Usa a maior sequência já existente e valida na BD para nunca repetir códigos,
 * mesmo com criações em simultâneo. A coluna `codigo` tem também índice UNIQUE.
 */
function gerarCodigoTicket(PDO $pdo): string
{
    $ano = date('Y');
    $prefixo = "QCC-{$ano}-";

    // Maior sequência já atribuída este ano (a parte numérica começa na posição 10)
    $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(codigo, 10) AS UNSIGNED)) FROM tickets WHERE codigo LIKE ?");
    $stmt->execute([$prefixo . '%']);
    $sequencia = (int)$stmt->fetchColumn() + 1;

    // Confirma que o código está livre; se já existir, incrementa até encontrar um novo
    $verifica = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE codigo = ?");
    do {
        $codigo = $prefixo . str_pad((string)$sequencia, 6, '0', STR_PAD_LEFT);
        $verifica->execute([$codigo]);
        $existe = (int)$verifica->fetchColumn() > 0;
        $sequencia++;
    } while ($existe);

    return $codigo;
}

/**
 * Lista predefinida de assuntos (fallback e seed inicial da base de dados).
 *
 * @return string[]
 */
function obterAssuntosTicketPadrao(): array
{
    return [
        'Acesso / Palavra-passe',
        'Internet / Rede',
        'Email / Correio eletrónico',
        'Impressora / Digitalização',
        'Software / Aplicação (erro)',
        'Instalação de programa',
        'Hardware / Equipamento',
        'Telefonia / VoIP',
        'Sistema interno / Plataforma',
        'Pedido de acesso / Nova conta',
        'Backup / Recuperação de dados',
        'Segurança / Vírus',
    ];
}

/**
 * Assuntos activos para os formulários de ticket (lidos da BD).
 * Se a tabela não existir ou estiver vazia, usa a lista predefinida.
 *
 * @return string[]
 */
function obterAssuntosTicket(?PDO $pdo = null): array
{
    if ($pdo === null) {
        return obterAssuntosTicketPadrao();
    }
    try {
        $stmt = $pdo->query("SELECT titulo FROM ticket_assuntos WHERE ativo = 1 ORDER BY ordem ASC, titulo ASC");
        $lista = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($lista)) {
            return $lista;
        }
    } catch (PDOException $e) {
        // Tabela ainda não criada
    }
    return obterAssuntosTicketPadrao();
}

/** Gestão de assuntos: Admin e staff das áreas técnicas */
function podeGerirAssuntosTicket(array $contexto): bool
{
    return podeGerirUtilizadores($contexto);
}

/**
 * Resolve o assunto (título) do ticket a partir dos dados submetidos.
 * Se o utilizador escolheu "Outros", usa o texto livre; caso contrário,
 * usa o assunto predefinido selecionado na lista.
 */
function resolverAssuntoTicket(array $post): string
{
    $selecionado = trim($post['assunto_predefinido'] ?? '');
    if ($selecionado === '__outro__' || $selecionado === 'Outros') {
        return trim($post['assunto_outro'] ?? '');
    }
    return $selecionado;
}

/**
 * Gera as <option> do seletor de assuntos, marcando o valor atual como
 * selecionado. Se o valor atual não constar da lista, assume-se "Outros".
 */
function opcoesAssuntoTicket(string $valorAtual = '', ?PDO $pdo = null): string
{
    $assuntos = obterAssuntosTicket($pdo);
    $ehPredefinido = in_array($valorAtual, $assuntos, true);
    $html = '';
    foreach ($assuntos as $assunto) {
        $sel = ($valorAtual === $assunto) ? ' selected' : '';
        $a = htmlspecialchars($assunto);
        $html .= "<option value=\"{$a}\"{$sel}>{$a}</option>";
    }
    // "Outros" fica selecionado quando há um valor que não pertence à lista
    $selOutro = ($valorAtual !== '' && !$ehPredefinido) ? ' selected' : '';
    $html .= "<option value=\"__outro__\"{$selOutro}>Outros (especificar)</option>";
    return $html;
}

/**
 * Considera um utilizador "online/disponível" quando:
 *   1. tem uma sessão ativa (ainda não fez logout), E
 *   2. o seu último acesso ocorreu dentro dos últimos minutos (por omissão 2 min).
 *
 * O sinal de sessão ativa (sessao_ativa) garante que o técnico fica offline de
 * imediato ao terminar a sessão, enquanto o ultimo_acesso é preservado para se
 * poder mostrar "há quanto tempo" ele esteve online (texto de offline).
 *
 * @param int|bool|null $sessaoAtiva 1/0 da coluna sessao_ativa (null ou 1 = ignora o sinal)
 */
function tecnicoEstaOnline(?string $ultimoAcesso, $sessaoAtiva = 1, int $limiteSegundos = 120): bool
{
    // Sessão terminada (logout explícito) → offline imediato, sem olhar para o tempo
    if ($sessaoAtiva !== null && !(int)$sessaoAtiva) {
        return false;
    }
    if (empty($ultimoAcesso)) {
        return false;
    }
    return (time() - strtotime($ultimoAcesso)) <= $limiteSegundos;
}

/**
 * Formata uma duração em minutos como texto legível (ex: "2d 3h", "45 min").
 * Usado nos relatórios para mostrar o tempo que um ticket levou a ser resolvido.
 */
function formatarDuracaoMinutos(?int $minutos): string
{
    if ($minutos === null || $minutos < 0) {
        return '—';
    }
    if ($minutos < 60) {
        return $minutos . ' min';
    }
    $dias = intdiv($minutos, 1440);
    $horas = intdiv($minutos % 1440, 60);
    $min = $minutos % 60;
    $partes = [];
    if ($dias > 0) {
        $partes[] = $dias . 'd';
    }
    if ($horas > 0) {
        $partes[] = $horas . 'h';
    }
    if ($min > 0 && $dias === 0) {
        $partes[] = $min . 'm';
    }
    return $partes ? implode(' ', $partes) : '0 min';
}

/** Texto amigável do "visto pela última vez" (ex: "há 2 min", "há 1 h") */
function ultimoAcessoTexto(?string $ultimoAcesso): string
{
    if (empty($ultimoAcesso)) {
        return 'nunca entrou';
    }
    $seg = max(0, time() - strtotime($ultimoAcesso));
    if ($seg < 60) {
        return 'agora mesmo';
    }
    if ($seg < 3600) {
        return 'há ' . floor($seg / 60) . ' min';
    }
    if ($seg < 86400) {
        return 'há ' . floor($seg / 3600) . ' h';
    }
    return 'há ' . floor($seg / 86400) . ' dia(s)';
}

/**
 * Atualiza o carimbo de "último acesso" do utilizador autenticado.
 * Chamado no bootstrap (conexao.php) para saber quem está online, mas
 * limitado a uma escrita por minuto para não sobrecarregar a base de dados.
 */
function marcarAtividadeUtilizador(PDO $pdo): void
{
    $userId = idUtilizadorNumerico();
    if (!$userId) {
        return;
    }
    $agora = time();
    $ultimaEscrita = $_SESSION['ultima_marcacao_atividade'] ?? 0;
    if (($agora - $ultimaEscrita) < 60) {
        return; // já foi atualizado há menos de 1 minuto
    }
    try {
        // Regista atividade e garante a sessão marcada como ativa (presença online)
        $pdo->prepare("UPDATE utilizadores SET ultimo_acesso = NOW(), sessao_ativa = 1 WHERE id = ?")->execute([$userId]);
        $_SESSION['ultima_marcacao_atividade'] = $agora;
    } catch (PDOException $e) {
        // Silencioso: a marcação de presença não deve quebrar a navegação
    }
}

/** Retorna o ID numérico da sessão (null se login por área/operação sem conta real) */
function idUtilizadorNumerico(): ?int
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    return is_numeric($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

/**
 * Monta contexto do utilizador autenticado a partir da sessão e da BD.
 * Usado em todas as verificações de permissão e filtros de listagem.
 * ids_areas: todas as áreas pelas quais o utilizador é responsável (multiárea).
 */
function obterContextoUsuario(PDO $pdo): array
{
    $userId = $_SESSION['user_id'] ?? null;
    $perfil = $_SESSION['perfil'] ?? '';
    $idArea = $_SESSION['id_area'] ?? null;
    $idOperacao = $_SESSION['id_operacao'] ?? null;
    $idsAreas = [];

    // Revalidar área/operação na BD para utilizadores com conta registada
    if (is_numeric($userId)) {
        $stmt = $pdo->prepare("SELECT id_area, id_operacao FROM utilizadores WHERE id = ?");
        $stmt->execute([(int)$userId]);
        $dados = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $idArea = $dados['id_area'] ?? $idArea;
        $idOperacao = $dados['id_operacao'] ?? $idOperacao;
        $idsAreas = obterIdsAreasUtilizador($pdo, (int)$userId);
        if (!empty($idsAreas)) {
            $idArea = $idsAreas[0];
        }
    } elseif (!empty($idArea)) {
        $idsAreas = [(int)$idArea];
    }

    return [
        'user_id' => $userId,
        'user_id_numerico' => idUtilizadorNumerico(),
        'perfil' => $perfil,
        'id_area' => $idArea,
        'ids_areas' => $idsAreas,
        'id_operacao' => $idOperacao,
        'nome' => $_SESSION['nome'] ?? '',
        // ID da conta partilhada de tickets abertos sem login (sistema.convidado).
        // Usado para impedir que utilizadores comuns vejam a "pilha" pública.
        'id_convidado' => obterIdUtilizadorConvidado($pdo),
        // ID da área Formadores — membros desta área têm visibilidade restrita
        // aos tickets do próprio grupo (ver obterFiltroTickets / podeVerTicket).
        'id_area_formadores' => obterIdAreaFormadores($pdo),
        // Operação INACOM — tickets desta operação são visíveis a Redes e Desenvolvimento
        'id_operacao_inacom' => obterIdOperacaoInacom($pdo),
    ];
}

/**
 * Lista de IDs de área do utilizador (tabela utilizador_areas + fallback id_area).
 *
 * @return int[]
 */
function obterIdsAreasUtilizador(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    try {
        $stmt = $pdo->prepare("SELECT id_area FROM utilizador_areas WHERE id_utilizador = ? ORDER BY id_area ASC");
        $stmt->execute([$userId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if (!empty($ids)) {
            return array_values(array_unique($ids));
        }
    } catch (PDOException $e) {
        // Tabela ainda não migrada — cai no fallback
    }
    try {
        $stmt = $pdo->prepare("SELECT id_area FROM utilizadores WHERE id = ?");
        $stmt->execute([$userId]);
        $id = $stmt->fetchColumn();
        return $id ? [(int)$id] : [];
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Sincroniza as áreas do utilizador (multiárea) e actualiza utilizadores.id_area
 * com a primeira área da lista (compatibilidade).
 *
 * @param int[] $idsAreas
 */
function sincronizarAreasUtilizador(PDO $pdo, int $userId, array $idsAreas): void
{
    if ($userId <= 0) {
        return;
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $idsAreas), static fn($v) => $v > 0)));
    try {
        $pdo->prepare("DELETE FROM utilizador_areas WHERE id_utilizador = ?")->execute([$userId]);
        if (!empty($ids)) {
            $ins = $pdo->prepare("INSERT INTO utilizador_areas (id_utilizador, id_area) VALUES (?, ?)");
            foreach ($ids as $idArea) {
                $ins->execute([$userId, $idArea]);
            }
        }
    } catch (PDOException $e) {
        // Silencioso se a tabela ainda não existir
    }
    $principal = $ids[0] ?? null;
    try {
        $pdo->prepare("UPDATE utilizadores SET id_area = ? WHERE id = ?")->execute([$principal, $userId]);
    } catch (PDOException $e) {
        // Silencioso
    }
}

/**
 * IDs de área no contexto (ids_areas ou fallback id_area).
 *
 * @return int[]
 */
function obterIdsAreasContexto(array $contexto): array
{
    $ids = $contexto['ids_areas'] ?? [];
    if (!is_array($ids)) {
        $ids = [];
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($v) => $v > 0)));
    if (empty($ids) && !empty($contexto['id_area'])) {
        $ids = [(int)$contexto['id_area']];
    }
    return $ids;
}

/** True se o utilizador pertence à área indicada (multiárea). */
function utilizadorPertenceArea(array $contexto, int $idArea): bool
{
    if ($idArea <= 0) {
        return false;
    }
    return in_array($idArea, obterIdsAreasContexto($contexto), true);
}

/**
 * True se o único vínculo do utilizador é a área Formadores
 * (quem tem Formadores + outra área não fica isolado).
 */
function soPertenceAreaFormadores(array $contexto): bool
{
    $idF = (int)($contexto['id_area_formadores'] ?? 0);
    if ($idF <= 0) {
        return false;
    }
    $ids = obterIdsAreasContexto($contexto);
    return count($ids) === 1 && $ids[0] === $idF;
}

/**
 * Devolve o ID da área "Formadores" (ou null se ainda não existir).
 * Membros desta área só veem tickets do próprio grupo — ver obterFiltroTickets().
 */
function obterIdAreaFormadores(PDO $pdo): ?int
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    try {
        $id = $pdo->query("SELECT id FROM areas WHERE nome = 'Formadores' LIMIT 1")->fetchColumn();
        $cache = $id ? (int)$id : null;
    } catch (PDOException $e) {
        $cache = null;
    }
    return $cache;
}

/**
 * Devolve o ID da conta partilhada sistema.convidado (tickets sem login).
 * Não cria a conta — devolve null se ainda não existir.
 */
function obterIdUtilizadorConvidado(PDO $pdo): ?int
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    try {
        $id = $pdo->query("SELECT id FROM utilizadores WHERE username = 'sistema.convidado' LIMIT 1")->fetchColumn();
        $cache = $id ? (int)$id : null;
    } catch (PDOException $e) {
        $cache = null;
    }
    return $cache;
}

/**
 * Constrói cláusulas WHERE para listar apenas tickets visíveis ao perfil atual.
 * Admin/Diretor Geral: vê tudo. Técnico/Responsável: área + atribuídos + criados.
 * Cliente: todos os tickets da SUA operação (ex: um cliente Africell vê os
 * tickets abertos por clientes da Africell). Comum: apenas os próprios tickets.
 *
 * @return array{0: string[], 1: mixed[]} [cláusulas WHERE, parâmetros PDO]
 */
function clausulaSqlTicketReencaminhadoPorUtilizador(int $userId): array
{
    return [
        "EXISTS (SELECT 1 FROM ticket_historico h WHERE h.id_ticket = t.id AND h.id_utilizador = ? AND h.acao = 'Reencaminhamento')",
        $userId,
    ];
}

/** True se o utilizador reencaminhou este ticket (mantém acesso de acompanhamento). */
function utilizadorReencaminhouTicket(PDO $pdo, int $userId, int $ticketId): bool
{
    if ($userId <= 0 || $ticketId <= 0) {
        return false;
    }
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM ticket_historico WHERE id_ticket = ? AND id_utilizador = ? AND acao = 'Reencaminhamento' LIMIT 1");
        $stmt->execute([$ticketId, $userId]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

function obterFiltroTickets(array $contexto): array
{
    $where = [];
    $params = [];
    $perfil = $contexto['perfil'];
    $userId = $contexto['user_id_numerico'];
    $idsAreas = obterIdsAreasContexto($contexto);
    $idOperacao = $contexto['id_operacao'];

    // Visão global — sem filtro adicional
    if (in_array($perfil, ['Admin', 'Diretor Geral'], true)) {
        return [$where, $params];
    }

    // Formadores: só se a única área for Formadores (quem também tem Redes/Dev vê o resto)
    $idFormadores = $contexto['id_area_formadores'] ?? null;
    if ($idFormadores && soPertenceAreaFormadores($contexto)) {
        $cond = [];
        $cond[] = "t.id_area_destino = ?";
        $params[] = $idFormadores;
        $cond[] = "t.id_criador = ?";
        $params[] = $userId;
        $cond[] = "t.id_tecnico_atribuido = ?";
        $params[] = $userId;
        [$sqlReenc, $paramReenc] = clausulaSqlTicketReencaminhadoPorUtilizador((int)$userId);
        $cond[] = $sqlReenc;
        $params[] = $paramReenc;
        $where[] = "(" . implode(" OR ", $cond) . ")";
        return [$where, $params];
    }

    // Staff técnico (Responsáveis e Técnicos): tickets de TODAS as suas áreas,
    // da sua operação/cliente, atribuídos a si ou criados por si.
    // Tickets da operação INACOM caem automaticamente para Redes & Desenvolvimento.
    if (in_array($perfil, ['Responsavel', 'Tecnico'], true)) {
        $cond = [];
        $cond[] = "t.id_tecnico_atribuido = ?";
        $params[] = $userId;
        $cond[] = "t.id_criador = ?";
        $params[] = $userId;
        if (!empty($idsAreas)) {
            $ph = implode(',', array_fill(0, count($idsAreas), '?'));
            $cond[] = "t.id_area_destino IN ($ph)";
            foreach ($idsAreas as $ia) {
                $params[] = $ia;
            }
        }
        if ($idOperacao) {
            $cond[] = "t.id_operacao_origem = ?";
            $params[] = $idOperacao;
        }
        // INACOM: visível para as duas áreas técnicas (Redes & Sistemas e Desenvolvimento)
        if (pertenceAreaTecnica($contexto)) {
            $idInacom = $contexto['id_operacao_inacom'] ?? null;
            if ($idInacom) {
                $cond[] = "t.id_operacao_origem = ?";
                $params[] = $idInacom;
            }
        }
        [$sqlReenc, $paramReenc] = clausulaSqlTicketReencaminhadoPorUtilizador((int)$userId);
        $cond[] = $sqlReenc;
        $params[] = $paramReenc;
        $where[] = "(" . implode(" OR ", $cond) . ")";
        return [$where, $params];
    }

    // Operador: vê todos os tickets da sua operação (abertos pelo grupo).
    // Sem operação definida, só vê os que abriu.
    if ($perfil === 'Operador') {
        $cond = [];
        if ($idOperacao) {
            $cond[] = "t.id_operacao_origem = ?";
            $params[] = $idOperacao;
        } else {
            $cond[] = "t.id_criador = ?";
            $params[] = $userId;
        }
        [$sqlReenc, $paramReenc] = clausulaSqlTicketReencaminhadoPorUtilizador((int)$userId);
        $cond[] = $sqlReenc;
        $params[] = $paramReenc;
        $where[] = "(" . implode(" OR ", $cond) . ")";
        return [$where, $params];
    }

    // Utilizador comum: vê APENAS os tickets que ele próprio abriu.
    // (Não pode ver tickets abertos por outras pessoas, mesmo de outras áreas.)
    // Além disso, nunca mostra a "pilha" partilhada de tickets abertos sem login
    // (conta sistema.convidado), evitando que se vejam pedidos de terceiros.
    $idConvidado = $contexto['id_convidado'] ?? null;
    if ($idConvidado && (int)$userId === (int)$idConvidado) {
        // A própria conta convidada não deve listar os tickets anónimos de todos.
        $where[] = "1 = 0";
        return [$where, $params];
    }

    $where[] = "(t.id_criador = ? OR " . clausulaSqlTicketReencaminhadoPorUtilizador((int)$userId)[0] . ")";
    $params[] = $userId;
    $params[] = $userId;

    return [$where, $params];
}

/** Verifica se o utilizador pode abrir/ver um ticket específico (detalhe e ações) */
function podeVerTicket(array $ticket, array $contexto, ?PDO $pdo = null): bool
{
    $perfil = $contexto['perfil'];
    $userId = $contexto['user_id_numerico'];

    if (in_array($perfil, ['Admin', 'Diretor Geral'], true)) {
        return true;
    }

    // Formadores: só o próprio grupo (quando a única área é Formadores)
    $idFormadores = $contexto['id_area_formadores'] ?? null;
    if ($idFormadores && soPertenceAreaFormadores($contexto)) {
        if ((int)($ticket['id_area_destino'] ?? 0) === (int)$idFormadores) {
            return true;
        }
        if ($userId && (int)($ticket['id_criador'] ?? 0) === $userId) {
            return true;
        }
        if ($userId && (int)($ticket['id_tecnico_atribuido'] ?? 0) === $userId) {
            return true;
        }
        if ($pdo && $userId && utilizadorReencaminhouTicket($pdo, $userId, (int)$ticket['id'])) {
            return true;
        }
        return false;
    }

    if (in_array($perfil, ['Responsavel', 'Tecnico'], true)) {
        if (utilizadorPertenceArea($contexto, (int)($ticket['id_area_destino'] ?? 0))) {
            return true;
        }
        // Visibilidade por operação: responsáveis/técnicos da operação (ex: Africell)
        if (!empty($contexto['id_operacao']) && (int)($ticket['id_operacao_origem'] ?? 0) === (int)$contexto['id_operacao']) {
            return true;
        }
        // INACOM: redes e desenvolvimento vêem todos os tickets desta operação
        if (pertenceAreaTecnica($contexto)
            && !empty($contexto['id_operacao_inacom'])
            && (int)($ticket['id_operacao_origem'] ?? 0) === (int)$contexto['id_operacao_inacom']) {
            return true;
        }
        if ($userId && (int)$ticket['id_tecnico_atribuido'] === $userId) {
            return true;
        }
        if ($userId && (int)$ticket['id_criador'] === $userId) {
            return true;
        }
        if ($pdo && $userId && utilizadorReencaminhouTicket($pdo, $userId, (int)$ticket['id'])) {
            return true;
        }
        return false;
    }

    // Operador: pode ver os tickets da sua operação
    if ($perfil === 'Operador') {
        if (!empty($contexto['id_operacao']) && (int)($ticket['id_operacao_origem'] ?? 0) === (int)$contexto['id_operacao']) {
            return true;
        }
        if ($userId && (int)$ticket['id_criador'] === $userId) {
            return true;
        }
        if ($pdo && $userId && utilizadorReencaminhouTicket($pdo, $userId, (int)$ticket['id'])) {
            return true;
        }
        return false;
    }

    // Utilizador comum: só pode ver os tickets que abriu.
    // A conta partilhada sistema.convidado (tickets sem login) nunca navega na
    // pilha pública — esses tickets só são tratados pela equipa da área/Admin.
    $idConvidado = $contexto['id_convidado'] ?? null;
    if ($idConvidado && (int)$userId === (int)$idConvidado) {
        return false;
    }
    if ($userId && (int)$ticket['id_criador'] === $userId) {
        return true;
    }
    if ($pdo && $userId && utilizadorReencaminhouTicket($pdo, $userId, (int)$ticket['id'])) {
        return true;
    }

    return false;
}

// IDs das áreas técnicas que gerem o sistema: Redes & Sistemas (1) e Desenvolvimento (2)
const AREAS_TECNICAS = [1, 2];

/** Indica se o utilizador pertence a Redes & Sistemas ou Desenvolvimento (qualquer das suas áreas) */
function pertenceAreaTecnica(array $contexto): bool
{
    $ids = obterIdsAreasContexto($contexto);
    foreach ($ids as $id) {
        if (in_array($id, AREAS_TECNICAS, true)) {
            return true;
        }
    }
    return false;
}

/**
 * Quem pode TRATAR/RESOLVER tickets (assumir, atribuir, mudar estado, editar).
 * Apenas equipa técnica: Admin, Responsável e Técnico. Convidado (conta pública
 * sistema.convidado), utilizadores Comuns e Clientes SÓ podem abrir e acompanhar.
 */
function podeResolverTickets(array $contexto): bool
{
    $perfil = $contexto['perfil'] ?? '';
    if (!in_array($perfil, ['Admin', 'Responsavel', 'Tecnico'], true)) {
        return false;
    }
    // A conta partilhada de tickets sem login nunca resolve nada
    $idConvidado = $contexto['id_convidado'] ?? null;
    if ($idConvidado && (int)($contexto['user_id_numerico'] ?? 0) === (int)$idConvidado) {
        return false;
    }
    return true;
}

/**
 * Verifica se o utilizador pode TRATAR (assumir, atribuir, alterar estado, editar)
 * ESTE ticket em concreto — ao contrário de podeResolverTickets(), que apenas valida
 * o perfil de forma genérica.
 *
 * Regra-chave: o tratamento pertence sempre à ÁREA DE DESTINO ATUAL do ticket.
 * Quando um ticket é reencaminhado para outra área, a equipa da área anterior deixa
 * de o poder tratar — só o pode acompanhar. Passam a tratá-lo apenas:
 *   - a equipa (responsáveis/técnicos) da área que RECEBEU o ticket;
 *   - o técnico a quem o ticket estiver atribuído;
 *   - o Admin, que mantém controlo total sobre qualquer ticket.
 */
function podeTratarTicket(array $ticket, array $contexto): bool
{
    // Perfis sem permissão de resolução (Comum, Cliente, Convidado, Diretor Geral)
    // nunca tratam tickets — apenas abrem/acompanham.
    if (!podeResolverTickets($contexto)) {
        return false;
    }

    // Admin tem controlo total, independentemente da área do ticket
    if (($contexto['perfil'] ?? '') === 'Admin') {
        return true;
    }

    $userId = (int)($contexto['user_id_numerico'] ?? 0);

    // O técnico a quem o ticket está atribuído mantém sempre o tratamento
    if ($userId > 0 && (int)($ticket['id_tecnico_atribuido'] ?? 0) === $userId) {
        return true;
    }

    // Responsável/Técnico trata tickets cuja área de destino ATUAL é uma das suas.
    // Após um reencaminhamento, id_area_destino passa a ser a nova área, pelo que
    // a equipa de origem deixa automaticamente de poder tratar — excepto se também
    // for responsável por essa nova área.
    if (utilizadorPertenceArea($contexto, (int)($ticket['id_area_destino'] ?? 0))) {
        return true;
    }

    return false;
}

/**
 * Pode EDITAR o conteúdo do ticket (assunto, descrição, prioridade).
 * Apenas Admin e técnicos/responsáveis de Redes & Sistemas / Desenvolvimento.
 * Os restantes utilizadores (Formadores, RH, Cliente, etc.) NÃO editam.
 */
function podeEditarTicket(array $ticket, array $contexto): bool
{
    if (($contexto['perfil'] ?? '') === 'Admin') {
        return true;
    }
    if (!in_array($contexto['perfil'] ?? '', ['Responsavel', 'Tecnico'], true)) {
        return false;
    }
    if (!pertenceAreaTecnica($contexto)) {
        return false;
    }
    // Precisam poder tratar o ticket (área de destino actual = a sua, ou atribuído)
    return podeTratarTicket($ticket, $contexto);
}

/**
 * Pode gerir o email de caixa postal de cada área (grupo de notificação).
 * Admin + técnicos/responsáveis de Redes & Sistemas e Desenvolvimento.
 */
function podeGerirEmailsAreas(array $contexto): bool
{
    return podeAcederAdministracao($contexto);
}

/**
 * ID da operação INACOM (tickets INACOM caem para Redes e Desenvolvimento).
 */
function obterIdOperacaoInacom(PDO $pdo): ?int
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    try {
        $id = $pdo->query("SELECT id FROM operacoes WHERE UPPER(nome) = 'INACOM' LIMIT 1")->fetchColumn();
        $cache = $id ? (int)$id : null;
    } catch (PDOException $e) {
        $cache = null;
    }
    return $cache;
}

/** True se o ticket pertence à operação INACOM */
function ticketEInacom(array $ticket, PDO $pdo): bool
{
    $idInacom = obterIdOperacaoInacom($pdo);
    return $idInacom && (int)($ticket['id_operacao_origem'] ?? 0) === $idInacom;
}

/**
 * Áreas disponíveis como DESTINO ao abrir um ticket, conforme o perfil.
 *
 * - Operador: apenas Redes & Sistemas (1) e Desenvolvimento (2)
 * - Restantes (Formadores, RH, técnicos, etc.): todas as áreas / direcções
 *
 * @return array<int, array{id:int|string, nome:string}>
 */
function obterAreasDestinoAbertura(PDO $pdo, array $contexto): array
{
    try {
        if (($contexto['perfil'] ?? '') === 'Operador') {
            $stmt = $pdo->prepare("SELECT id, nome FROM areas WHERE id IN (1, 2) ORDER BY FIELD(id, 1, 2)");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $pdo->query("SELECT id, nome FROM areas ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Operações disponíveis ao abrir um ticket.
 * Operador: só a sua operação (trancada). Restantes: todas.
 *
 * @return array<int, array{id:int|string, nome:string}>
 */
function obterOperacoesAbertura(PDO $pdo, array $contexto): array
{
    try {
        if (($contexto['perfil'] ?? '') === 'Operador' && !empty($contexto['id_operacao'])) {
            $stmt = $pdo->prepare("SELECT id, nome FROM operacoes WHERE id = ?");
            $stmt->execute([(int)$contexto['id_operacao']]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $pdo->query("SELECT id, nome FROM operacoes ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Valida se a área escolhida na abertura é permitida para o perfil actual.
 */
function areaDestinoPermitidaAbertura(int $idArea, array $contexto): bool
{
    if ($idArea <= 0) {
        return false;
    }
    if (($contexto['perfil'] ?? '') === 'Operador') {
        return in_array($idArea, [1, 2], true); // Redes & Sistemas, Desenvolvimento
    }
    return true;
}

/**
 * Painel técnico completo, gestão de users e relatórios:
 * Admin OU (Responsável/Técnico nas áreas 1 ou 2)
 */
function podeAcederAdministracao(array $contexto): bool
{
    if (($contexto['perfil'] ?? '') === 'Admin') {
        return true;
    }
    return in_array($contexto['perfil'] ?? '', ['Responsavel', 'Tecnico'], true)
        && pertenceAreaTecnica($contexto);
}

/**
 * Painel com métricas técnicas / Top Técnico no dashboard:
 * Admin, Diretor Geral, área Direção, e staff de Redes & Sistemas / Desenvolvimento.
 */
function podeVerDashboardMetricasTecnicas(array $contexto): bool
{
    $perfil = $contexto['perfil'] ?? '';
    if (in_array($perfil, ['Admin', 'Diretor Geral'], true)) {
        return true;
    }
    if (podeAcederAdministracao($contexto)) {
        return true;
    }
    if (utilizadorPertenceArea($contexto, AREA_DIRECAO)) {
        return true;
    }
    return false;
}

/** Alias semântico — gestão de utilizadores usa as mesmas regras */
function podeGerirUtilizadores(array $contexto): bool
{
    return podeAcederAdministracao($contexto);
}

/**
 * Pode consultar a equipa disponível (menu lateral e painel no ticket).
 * Admin + Técnicos/Responsáveis de Redes & Sistemas (1) e Desenvolvimento (2).
 */
function podeVerEquipaDisponivel(array $contexto): bool
{
    if (($contexto['perfil'] ?? '') === 'Admin') {
        return true;
    }
    return in_array($contexto['perfil'] ?? '', ['Tecnico', 'Responsavel'], true)
        && pertenceAreaTecnica($contexto);
}

/**
 * Lista técnicos e responsáveis das áreas técnicas (Redes & Sistemas e Desenvolvimento).
 *
 * @param int|null $idArea Filtrar por uma área (1 ou 2); null devolve ambas as equipas
 * @return array<int, array<string, mixed>>
 */
function obterEquipaTecnica(PDO $pdo, ?int $idArea = null): array
{
    $ids = AREAS_TECNICAS;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    // Inclui quem tem a área na coluna principal OU em utilizador_areas (multiárea)
    $sql = "
        SELECT DISTINCT u.id, u.nome, u.perfil, u.id_area, u.ultimo_acesso, u.sessao_ativa,
               COALESCE(a.nome,
                   (SELECT a2.nome FROM utilizador_areas ua2
                    INNER JOIN areas a2 ON a2.id = ua2.id_area
                    WHERE ua2.id_utilizador = u.id AND ua2.id_area IN ($placeholders)
                    ORDER BY ua2.id_area LIMIT 1)
               ) AS area
        FROM utilizadores u
        LEFT JOIN areas a ON u.id_area = a.id
        LEFT JOIN utilizador_areas ua ON ua.id_utilizador = u.id
        WHERE u.perfil IN ('Tecnico', 'Responsavel')
          AND u.estado = 'Ativo'
          AND (u.id_area IN ($placeholders) OR ua.id_area IN ($placeholders))
    ";
    $params = array_merge($ids, $ids, $ids);
    if ($idArea !== null && in_array($idArea, AREAS_TECNICAS, true)) {
        $sql .= ' AND (u.id_area = ? OR ua.id_area = ?)';
        $params[] = $idArea;
        $params[] = $idArea;
    }
    $sql .= ' ORDER BY u.nome ASC';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $lista = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Expandir: um membro multiárea aparece em cada área técnica a que pertence
        $expandida = [];
        foreach ($lista as $membro) {
            $idsUser = obterIdsAreasUtilizador($pdo, (int)$membro['id']);
            $areasMostrar = array_values(array_intersect($idsUser, AREAS_TECNICAS));
            if (empty($areasMostrar) && !empty($membro['id_area'])) {
                $areasMostrar = [(int)$membro['id_area']];
            }
            if ($idArea !== null) {
                $areasMostrar = array_values(array_filter($areasMostrar, static fn($a) => (int)$a === $idArea));
            }
            if (empty($areasMostrar)) {
                $expandida[] = $membro;
                continue;
            }
            foreach ($areasMostrar as $aid) {
                $linha = $membro;
                $linha['id_area'] = $aid;
                try {
                    $stN = $pdo->prepare('SELECT nome FROM areas WHERE id = ?');
                    $stN->execute([$aid]);
                    $linha['area'] = (string)($stN->fetchColumn() ?: $membro['area']);
                } catch (PDOException $e) {
                    // mantém nome anterior
                }
                $expandida[] = $linha;
            }
        }
        usort($expandida, static fn(array $a, array $b): int =>
            (int)tecnicoEstaOnline($b['ultimo_acesso'] ?? null, $b['sessao_ativa'] ?? 1)
            <=> (int)tecnicoEstaOnline($a['ultimo_acesso'] ?? null, $a['sessao_ativa'] ?? 1));
        return $expandida;
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Agrupa membros da equipa técnica por área (Redes & Sistemas, Desenvolvimento).
 *
 * @param array<int, array<string, mixed>> $membros
 * @return array<int, array{nome: string, membros: array<int, array<string, mixed>>}>
 */
function agruparEquipaPorArea(PDO $pdo, array $membros): array
{
    $grupos = [];
    try {
        $placeholders = implode(',', array_fill(0, count(AREAS_TECNICAS), '?'));
        $stmt = $pdo->prepare("SELECT id, nome FROM areas WHERE id IN ($placeholders) ORDER BY id ASC");
        $stmt->execute(AREAS_TECNICAS);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $area) {
            $grupos[(int)$area['id']] = ['nome' => $area['nome'], 'membros' => []];
        }
    } catch (PDOException $e) {
        $grupos = [
            1 => ['nome' => 'Redes & Sistemas', 'membros' => []],
            2 => ['nome' => 'Desenvolvimento', 'membros' => []],
        ];
    }

    foreach ($membros as $membro) {
        $idArea = (int)($membro['id_area'] ?? 0);
        if (isset($grupos[$idArea])) {
            $grupos[$idArea]['membros'][] = $membro;
        }
    }

    return $grupos;
}

/**
 * Consulta do registo de auditoria do sistema completo.
 * Acesso: Admin + Responsáveis das áreas técnicas (Redes & Sistemas / Desenvolvimento).
 */
function podeAcederAuditoria(array $contexto): bool
{
    if (($contexto['perfil'] ?? '') === 'Admin') {
        return true;
    }
    return ($contexto['perfil'] ?? '') === 'Responsavel'
        && pertenceAreaTecnica($contexto);
}

/** Regista ação na tabela auditoria (falha silenciosa para não bloquear o fluxo principal) */
function registarAuditoria(PDO $pdo, string $acao, string $detalhes): void
{
    try {
        $stmt = $pdo->prepare("INSERT INTO auditoria (id_utilizador, acao, detalhes) VALUES (?, ?, ?)");
        $stmt->execute([idUtilizadorNumerico(), $acao, $detalhes]);
    } catch (PDOException $e) {
        // Não bloquear operação principal se auditoria falhar
    }
}

/** Histórico de alterações por ticket (timeline no detalhe) */
function registarHistoricoTicket(PDO $pdo, int $idTicket, ?int $idUtilizador, string $acao, string $detalhes = ''): void
{
    try {
        $stmt = $pdo->prepare("INSERT INTO ticket_historico (id_ticket, id_utilizador, acao, detalhes) VALUES (?, ?, ?, ?)");
        $stmt->execute([$idTicket, $idUtilizador, $acao, $detalhes]);
    } catch (PDOException $e) {
        // Não bloquear operação principal
    }
}

/**
 * Utilizador fictício para tickets abertos sem login (abrir_ticket.php).
 * Criado automaticamente na primeira utilização.
 */
function obterIdUtilizadorSistema(PDO $pdo): int
{
    $stmt = $pdo->prepare("SELECT id FROM utilizadores WHERE username = 'sistema.convidado' LIMIT 1");
    $stmt->execute();
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int)$id;
    }

    $senha = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
    $pdo->prepare("INSERT INTO utilizadores (nome, username, password_hash, perfil, estado) VALUES ('Sistema Convidado', 'sistema.convidado', ?, 'Comum', 'Inativo')")
        ->execute([$senha]);

    return (int)$pdo->lastInsertId();
}

/**
 * Cria ou atualiza utilizador efémero para login por nome de área ou operação.
 * Usado em login.php quando o username não é uma conta interna.
 */
function garantirUtilizadorSessao(PDO $pdo, string $username, string $nome, string $perfil, ?int $idArea = null, ?int $idOperacao = null): int
{
    $usernameBd = strtolower(preg_replace('/\s+/', '.', trim($username)));
    $perfilBd = mapearPerfilParaBd($perfil);

    $stmt = $pdo->prepare("SELECT id FROM utilizadores WHERE username = ? LIMIT 1");
    $stmt->execute([$usernameBd]);
    $existente = $stmt->fetchColumn();

    if ($existente) {
        $pdo->prepare("UPDATE utilizadores SET nome = ?, perfil = ?, id_area = ?, id_operacao = ?, estado = 'Ativo', ultimo_acesso = NOW() WHERE id = ?")
            ->execute([$nome, $perfilBd, $idArea, $idOperacao, $existente]);
        return (int)$existente;
    }

    $senha = password_hash(bin2hex(random_bytes(12)), PASSWORD_BCRYPT);
    $pdo->prepare("INSERT INTO utilizadores (nome, username, password_hash, perfil, id_area, id_operacao, estado) VALUES (?, ?, ?, ?, ?, ?, 'Ativo')")
        ->execute([$nome, $usernameBd, $senha, $perfilBd, $idArea, $idOperacao]);

    return (int)$pdo->lastInsertId();
}

/** Insere notificação in-app para um utilizador ou área */
function criarNotificacao(PDO $pdo, ?int $idUtilizador, ?int $idArea, string $tipo, string $mensagem, ?int $idTicket = null): void
{
    try {
        $stmt = $pdo->prepare("INSERT INTO notificacoes (id_utilizador, id_area, id_ticket, tipo, mensagem) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$idUtilizador, $idArea, $idTicket, $tipo, $mensagem]);
    } catch (PDOException $e) {
        // Silencioso
    }
}

/** Notifica todos os membros de uma área (ex: novo ticket na fila do grupo) */
function notificarAreaTicket(PDO $pdo, int $idArea, int $idTicket, string $tipo, string $mensagem): void
{
    criarNotificacao($pdo, null, $idArea, $tipo, $mensagem, $idTicket);
}

/**
 * Notificação IN-APP (plataforma) quando o ticket muda de estado / atendimento.
 * Avisa: quem abriu, técnico atribuído e toda a área de destino (exceto quem fez a ação).
 */
function notificarPlataformaAtualizacaoTicket(PDO $pdo, int $idTicket, string $tipo, string $mensagem, ?int $idAutorAcao = null): void
{
    try {
        $stmt = $pdo->prepare("SELECT id, codigo, id_criador, id_tecnico_atribuido, id_area_destino FROM tickets WHERE id = ?");
        $stmt->execute([$idTicket]);
        $t = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$t) {
            return;
        }
        $codigo = $t['codigo'] ?: ('#' . $t['id']);
        $msg = "Ticket {$codigo}: {$mensagem}";

        $jaNotificados = [];
        if ($idAutorAcao) {
            $jaNotificados[$idAutorAcao] = true;
        }

        // Quem abriu
        $idCriador = (int)($t['id_criador'] ?? 0);
        if ($idCriador > 0 && empty($jaNotificados[$idCriador])) {
            criarNotificacao($pdo, $idCriador, null, $tipo, $msg, $idTicket);
            $jaNotificados[$idCriador] = true;
        }

        // Técnico atribuído
        $idTec = (int)($t['id_tecnico_atribuido'] ?? 0);
        if ($idTec > 0 && empty($jaNotificados[$idTec])) {
            criarNotificacao($pdo, $idTec, null, $tipo, $msg, $idTicket);
            $jaNotificados[$idTec] = true;
        }

        // Área de destino (visível a todos os membros dessa área)
        $idArea = (int)($t['id_area_destino'] ?? 0);
        if ($idArea > 0) {
            criarNotificacao($pdo, null, $idArea, $tipo, $msg, $idTicket);
        }
    } catch (Throwable $e) {
        // Silencioso
    }
}

/**
 * Após criar um ticket: notifica a área destino; se for INACOM,
 * notifica também Redes & Sistemas e Desenvolvimento.
 * Envia email para a caixa postal da área.
 */
function notificarDestinosNovoTicket(PDO $pdo, int $idTicket): void
{
    try {
        $stmt = $pdo->prepare("
            SELECT t.*, a.nome AS nome_area, a.email AS email_area, o.nome AS nome_operacao
            FROM tickets t
            LEFT JOIN areas a ON t.id_area_destino = a.id
            LEFT JOIN operacoes o ON t.id_operacao_origem = o.id
            WHERE t.id = ?
        ");
        $stmt->execute([$idTicket]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ticket) {
            return;
        }

        $codigo = $ticket['codigo'] ?: ('#' . $ticket['id']);
        $titulo = $ticket['titulo'] ?? '';
        $nome = $ticket['nome_solicitante'] ?: 'Utilizador';
        $msg = "Novo ticket {$codigo}: {$titulo}";

        $idArea = (int)($ticket['id_area_destino'] ?? 0);
        if ($idArea > 0) {
            notificarAreaTicket($pdo, $idArea, $idTicket, 'Novo Ticket', $msg);
            enviarEmailCaixaAreaTicket($pdo, $idArea, $ticket);
        }

        // INACOM: cai para as duas áreas técnicas (notificação + email)
        $idInacom = obterIdOperacaoInacom($pdo);
        if ($idInacom && (int)($ticket['id_operacao_origem'] ?? 0) === $idInacom) {
            foreach (AREAS_TECNICAS as $idAreaTec) {
                if ($idAreaTec !== $idArea) {
                    notificarAreaTicket($pdo, $idAreaTec, $idTicket, 'Novo Ticket INACOM', $msg);
                    enviarEmailCaixaAreaTicket($pdo, $idAreaTec, $ticket);
                }
            }
        }
    } catch (Throwable $e) {
        // Silencioso — não bloqueia a abertura do ticket
    }
}

/**
 * Conta notificações pendentes (não lidas) do utilizador actual —
 * dirigidas a si ou à sua área (Admin vê todas).
 */
function contarNotificacoesPendentes(PDO $pdo, array $contexto): int
{
    try {
        if (($contexto['perfil'] ?? '') === 'Admin') {
            return (int)$pdo->query("SELECT COUNT(*) FROM notificacoes WHERE lida = 0")->fetchColumn();
        }
        $idsAreas = obterIdsAreasContexto($contexto);
        $sql = "SELECT COUNT(*) FROM notificacoes WHERE lida = 0 AND (id_utilizador = ?";
        $params = [(int)($contexto['user_id_numerico'] ?? 0)];
        if (!empty($idsAreas)) {
            $ph = implode(',', array_fill(0, count($idsAreas), '?'));
            $sql .= " OR id_area IN ($ph)";
            foreach ($idsAreas as $ia) {
                $params[] = $ia;
            }
        }
        $sql .= ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Lista notificações do utilizador (dirigidas a si ou à sua área).
 *
 * @return array<int, array<string, mixed>>
 */
function obterNotificacoesUtilizador(PDO $pdo, array $contexto, int $limite = 50, bool $soNaoLidas = false): array
{
    try {
        $whereLida = $soNaoLidas ? 'AND lida = 0' : '';
        if (($contexto['perfil'] ?? '') === 'Admin') {
            $stmt = $pdo->prepare("SELECT * FROM notificacoes WHERE 1=1 {$whereLida} ORDER BY id DESC LIMIT ?");
            $stmt->bindValue(1, $limite, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $idsAreas = obterIdsAreasContexto($contexto);
            $sql = "SELECT * FROM notificacoes WHERE (id_utilizador = ?";
            $params = [(int)($contexto['user_id_numerico'] ?? 0)];
            if (!empty($idsAreas)) {
                $ph = implode(',', array_fill(0, count($idsAreas), '?'));
                $sql .= " OR id_area IN ($ph)";
                foreach ($idsAreas as $ia) {
                    $params[] = $ia;
                }
            }
            $sql .= ") {$whereLida} ORDER BY id DESC LIMIT ?";
            $params[] = $limite;
            $stmt = $pdo->prepare($sql);
            foreach ($params as $i => $v) {
                $stmt->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->execute();
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/** Marca notificações como lidas (do utilizador/área ou uma específica) */
function marcarNotificacoesLidas(PDO $pdo, array $contexto, ?int $idNotificacao = null): void
{
    try {
        $idsAreas = obterIdsAreasContexto($contexto);
        $uid = (int)($contexto['user_id_numerico'] ?? 0);

        if ($idNotificacao) {
            if (($contexto['perfil'] ?? '') === 'Admin') {
                $pdo->prepare("UPDATE notificacoes SET lida = 1 WHERE id = ?")->execute([$idNotificacao]);
            } else {
                $sql = "UPDATE notificacoes SET lida = 1 WHERE id = ? AND (id_utilizador = ?";
                $params = [$idNotificacao, $uid];
                if (!empty($idsAreas)) {
                    $ph = implode(',', array_fill(0, count($idsAreas), '?'));
                    $sql .= " OR id_area IN ($ph)";
                    foreach ($idsAreas as $ia) {
                        $params[] = $ia;
                    }
                }
                $sql .= ')';
                $pdo->prepare($sql)->execute($params);
            }
            return;
        }
        if (($contexto['perfil'] ?? '') === 'Admin') {
            $pdo->exec("UPDATE notificacoes SET lida = 1 WHERE lida = 0");
        } else {
            $sql = "UPDATE notificacoes SET lida = 1 WHERE lida = 0 AND (id_utilizador = ?";
            $params = [$uid];
            if (!empty($idsAreas)) {
                $ph = implode(',', array_fill(0, count($idsAreas), '?'));
                $sql .= " OR id_area IN ($ph)";
                foreach ($idsAreas as $ia) {
                    $params[] = $ia;
                }
            }
            $sql .= ')';
            $pdo->prepare($sql)->execute($params);
        }
    } catch (PDOException $e) {
        // Silencioso
    }
}

/**
 * Envia email para a caixa postal da área quando um ticket é aberto/reencaminhado.
 * Template: "UM TICKET FOI ABERTO POR: XXX" + código + link para o painel.
 */
function enviarEmailCaixaAreaTicket(PDO $pdo, int $idArea, array $ticket): void
{
    try {
        $stmt = $pdo->prepare("SELECT nome, email FROM areas WHERE id = ?");
        $stmt->execute([$idArea]);
        $area = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$area || empty($area['email']) || !filter_var($area['email'], FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $codigo = $ticket['codigo'] ?: ('#' . ($ticket['id'] ?? ''));
        $nomeAberto = $ticket['nome_solicitante'] ?: 'Utilizador';
        $titulo = $ticket['titulo'] ?? '';
        $nomeArea = $area['nome'] ?? '';
        $urlBase = obterUrlBaseSistema();
        $linkPainel = $urlBase . '/ticket_detalhes.php?id=' . (int)($ticket['id'] ?? 0);
        $linkLogin = $urlBase . '/login.php';

        $assunto = "KIAMI — Novo ticket {$codigo} para {$nomeArea}";
        $intro = "UM TICKET FOI ABERTO POR: {$nomeAberto}. O código do ticket é {$codigo}.";
        $linhas = [
            'Código' => $codigo,
            'Aberto por' => $nomeAberto,
            'Área' => $nomeArea,
            'Prioridade' => $ticket['prioridade'] ?? 'Média',
            'Estado' => $ticket['estado'] ?? 'Aberto',
        ];
        $html = montarEmailTicketHtml($nomeArea, $codigo, $titulo, $intro, $linhas, '#3b82f6');
        // Acrescenta botão de acesso ao painel
        $html = str_replace(
            'Para acompanhar o progresso, aceda ao sistema KIAMI com a sua conta.',
            'Clique no link para aceder ao painel de gestão e tratar o ticket.<br><br>'
            . '<a href="' . htmlspecialchars($linkPainel, ENT_QUOTES, 'UTF-8') . '" style="background:#3b82f6;color:#fff;text-decoration:none;padding:12px 22px;border-radius:6px;font-weight:bold;display:inline-block;">Abrir ticket no KIAMI</a>'
            . '<br><br>Se precisar de autenticar: <a href="' . htmlspecialchars($linkLogin, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($linkLogin, ENT_QUOTES, 'UTF-8') . '</a>',
            $html
        );
        $texto = "UM TICKET FOI ABERTO POR: {$nomeAberto}\nCódigo: {$codigo}\nAssunto: {$titulo}\nÁrea: {$nomeArea}\n\nAceder ao painel: {$linkPainel}\nLogin: {$linkLogin}";
        enviarEmail($area['email'], $assunto, $html, $texto);
    } catch (Throwable $e) {
        // Silencioso
    }
}

/**
 * HTML do link «Notificações» na barra lateral, com badge do nº pendente.
 * Visível para todos os utilizadores autenticados.
 */
function htmlNavNotificacoes(int $pendentes = 0): string
{
    $activo = (basename($_SERVER['PHP_SELF'] ?? '') === 'notificacoes_lista.php') ? ' active' : '';
    $badge = '';
    if ($pendentes > 0) {
        $n = $pendentes > 99 ? '99+' : (string)$pendentes;
        $badge = ' <span class="notif-badge" title="' . htmlspecialchars($n) . ' pendente(s)">🔔 ' . htmlspecialchars($n) . '</span>';
    } else {
        $badge = ' <span style="opacity:.7;">🔔</span>';
    }
    return '<a href="notificacoes_lista.php" class="nav-item' . $activo . '">' . $badge . ' <span>Notificações</span></a>';
}

/**
 * Descobre o email para onde avisar o solicitante de um ticket.
 *
 * Ordem de prioridade:
 * 1. email_solicitante guardado no próprio ticket (aberturas públicas sem login);
 * 2. email da conta do utilizador que criou o ticket (utilizadores.email).
 *
 * @param array $ticket Linha do ticket (deve conter email_solicitante e id_criador)
 * @return string|null  Email válido ou null se não existir
 */
function obterEmailSolicitanteTicket(PDO $pdo, array $ticket): ?string
{
    // 1) Email indicado na abertura pública
    if (!empty($ticket['email_solicitante']) && filter_var($ticket['email_solicitante'], FILTER_VALIDATE_EMAIL)) {
        return $ticket['email_solicitante'];
    }

    // 2) Email da conta do criador (ignora a conta genérica do sistema)
    if (!empty($ticket['id_criador'])) {
        try {
            $stmt = $pdo->prepare("SELECT email, username FROM utilizadores WHERE id = ?");
            $stmt->execute([(int)$ticket['id_criador']]);
            $dados = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if (($dados['username'] ?? '') !== 'sistema.convidado'
                && !empty($dados['email'])
                && filter_var($dados['email'], FILTER_VALIDATE_EMAIL)) {
                return $dados['email'];
            }
        } catch (PDOException $e) {
            // Silencioso
        }
    }

    return null;
}

/**
 * Envia email de CONFIRMAÇÃO de abertura ao solicitante de um ticket.
 * Não bloqueia o fluxo se o email estiver desativado ou em falta.
 */
function notificarSolicitanteNovoTicket(PDO $pdo, int $idTicket): void
{
    try {
        $stmt = $pdo->prepare("
            SELECT t.*, a.nome AS nome_area
            FROM tickets t
            LEFT JOIN areas a ON t.id_area_destino = a.id
            WHERE t.id = ?
        ");
        $stmt->execute([$idTicket]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ticket) {
            return;
        }

        $email = obterEmailSolicitanteTicket($pdo, $ticket);
        if (!$email) {
            return;
        }

        $nome = $ticket['nome_solicitante'] ?: 'Utilizador';
        $codigo = $ticket['codigo'] ?: ('#' . $ticket['id']);
        enviarEmailTicketCriado($email, $nome, $codigo, $ticket['titulo'], $ticket['prioridade'], $ticket['nome_area'] ?? '');
    } catch (Throwable $e) {
        // Nunca interromper a criação do ticket por causa do email
    }
}

/**
 * Envia email de ATUALIZAÇÃO ao solicitante (mudança de estado, atribuição,
 * reencaminhamento, novo comentário, etc.). Falha em silêncio.
 *
 * @param string $tipoEvento Descrição curta do evento (ex: "Mudança de estado")
 * @param string $detalhe    Informação adicional (opcional)
 */
function notificarSolicitanteAtualizacaoTicket(PDO $pdo, int $idTicket, string $tipoEvento, string $detalhe = ''): void
{
    try {
        $stmt = $pdo->prepare("SELECT * FROM tickets WHERE id = ?");
        $stmt->execute([$idTicket]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ticket) {
            return;
        }

        $email = obterEmailSolicitanteTicket($pdo, $ticket);
        if (!$email) {
            return;
        }

        $nome = $ticket['nome_solicitante'] ?: 'Utilizador';
        $codigo = $ticket['codigo'] ?: ('#' . $ticket['id']);
        enviarEmailTicketAtualizado($email, $nome, $codigo, $ticket['titulo'], $ticket['estado'], $tipoEvento, $detalhe);
    } catch (Throwable $e) {
        // Silencioso
    }
}

/**
 * Escalonamento automático de tickets sem atendimento.
 *
 * Regra de negócio: quando um ticket é aberto e ninguém o assume nem altera o
 * seu estado, os responsáveis da área de destino devem ser avisados. Gera-se
 * uma notificação aos 5 minutos e outra aos 10 minutos.
 *
 * Considera-se "sem atendimento" um ticket ainda no estado 'Aberto' e sem
 * técnico atribuído. Assim que alguém o assume (passa a 'Em Progresso') ou
 * muda o estado, deixa de escalar.
 *
 * As colunas notif_escala_5 / notif_escala_10 evitam notificações repetidas.
 * É seguro chamar esta função com frequência (ex: no polling de notificações):
 * a marcação é feita antes do envio para impedir duplicados em concorrência.
 */
function verificarEscalonamentoTickets(PDO $pdo): void
{
    try {
        // Níveis de escalonamento: minutos decorridos => coluna de controlo
        $niveis = [5 => 'notif_escala_5', 10 => 'notif_escala_10'];

        foreach ($niveis as $minutos => $coluna) {
            // Tickets ainda por atender que já ultrapassaram o tempo limite deste nível
            $sql = "SELECT t.id, t.codigo, t.id_area_destino, a.nome AS nome_area
                    FROM tickets t
                    LEFT JOIN areas a ON t.id_area_destino = a.id
                    WHERE t.estado = 'Aberto'
                      AND t.id_tecnico_atribuido IS NULL
                      AND t.$coluna = 0
                      AND t.data_criacao <= (NOW() - INTERVAL $minutos MINUTE)";
            $tickets = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            foreach ($tickets as $t) {
                // Marca primeiro; só notifica quem "ganhou" a atualização (evita duplicados)
                $upd = $pdo->prepare("UPDATE tickets SET $coluna = 1 WHERE id = ? AND $coluna = 0");
                $upd->execute([$t['id']]);
                if ($upd->rowCount() === 0) {
                    continue; // outro pedido concorrente já tratou este ticket
                }

                $codigo = $t['codigo'] ?: ('#' . $t['id']);
                $area = $t['nome_area'] ?? 'a área de destino';
                $tipo = 'Escalonamento';
                $mensagem = "⏰ Ticket {$codigo} sem atendimento há {$minutos} min na área {$area}. Requer atenção do responsável.";

                // Responsáveis ativos da área de destino (coluna ou multiárea)
                $stmtResp = $pdo->prepare("
                    SELECT DISTINCT u.id
                    FROM utilizadores u
                    LEFT JOIN utilizador_areas ua ON ua.id_utilizador = u.id
                    WHERE u.perfil = 'Responsavel'
                      AND u.estado = 'Ativo'
                      AND (u.id_area = ? OR ua.id_area = ?)
                ");
                $stmtResp->execute([$t['id_area_destino'], $t['id_area_destino']]);
                $responsaveis = $stmtResp->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($responsaveis)) {
                    // Notifica cada responsável individualmente
                    foreach ($responsaveis as $idResp) {
                        criarNotificacao($pdo, (int)$idResp, null, $tipo, $mensagem, (int)$t['id']);
                    }
                } else {
                    // Sem responsável definido: notifica a área inteira como alternativa
                    criarNotificacao($pdo, null, (int)$t['id_area_destino'], $tipo, $mensagem, (int)$t['id']);
                }
            }
        }
    } catch (PDOException $e) {
        // Silencioso — o escalonamento nunca deve interromper o fluxo normal
    }
}

/** Verifica bloqueio após 5 tentativas falhadas (15 minutos) */
function verificarBloqueioLogin(PDO $pdo, string $username): bool
{
    try {
        $stmt = $pdo->prepare("SELECT tentativas, bloqueado_ate FROM tentativas_login WHERE username = ?");
        $stmt->execute([$username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        if (!empty($row['bloqueado_ate']) && strtotime($row['bloqueado_ate']) > time()) {
            return true;
        }
        return false;
    } catch (PDOException $e) {
        return false;
    }
}

/** Incrementa contador de falhas ou limpa após login bem-sucedido */
function registarTentativaLogin(PDO $pdo, string $username, bool $sucesso): void
{
    try {
        if ($sucesso) {
            $pdo->prepare("DELETE FROM tentativas_login WHERE username = ?")->execute([$username]);
            return;
        }

        $stmt = $pdo->prepare("SELECT tentativas FROM tentativas_login WHERE username = ?");
        $stmt->execute([$username]);
        $tentativas = (int)($stmt->fetchColumn() ?: 0) + 1;

        if ($tentativas >= 5) {
            $bloqueadoAte = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            $pdo->prepare("INSERT INTO tentativas_login (username, tentativas, bloqueado_ate) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE tentativas = ?, bloqueado_ate = ?")
                ->execute([$username, $tentativas, $bloqueadoAte, $tentativas, $bloqueadoAte]);
        } else {
            $pdo->prepare("INSERT INTO tentativas_login (username, tentativas) VALUES (?, ?) ON DUPLICATE KEY UPDATE tentativas = ?")
                ->execute([$username, $tentativas, $tentativas]);
        }
    } catch (PDOException $e) {
        // Silencioso
    }
}

/**
 * Estado visual do SLA: ok | risco (<1h) | vencido | cumprido | resolvido | sem_sla
 */
function estadoSlaTicket(array $ticket): string
{
    if ($ticket['estado'] === 'Resolvido') {
        if (!empty($ticket['data_resolucao']) && !empty($ticket['data_limite_sla'])) {
            return strtotime($ticket['data_resolucao']) <= strtotime($ticket['data_limite_sla']) ? 'cumprido' : 'vencido';
        }
        return 'resolvido';
    }

    if (empty($ticket['data_limite_sla'])) {
        return 'sem_sla';
    }

    $agora = time();
    $limite = strtotime($ticket['data_limite_sla']);
    if ($agora > $limite) {
        return 'vencido';
    }
    if ($limite - $agora <= 3600) {
        return 'risco';
    }
    return 'ok';
}

/**
 * Processa o upload de uma imagem enviada num formulário.
 *
 * Valida tipo (jpg, png, gif, webp) e tamanho (máx. 5 MB), gera nome único
 * e move o ficheiro para a subpasta indicada dentro de /uploads.
 *
 * @param array  $ficheiro  Entrada de $_FILES (ex: $_FILES['imagem'])
 * @param string $subpasta  Subpasta em uploads/ (ex: 'tickets' ou 'kb')
 * @return array{sucesso: bool, caminho: ?string, erro: string}
 *         caminho = caminho relativo para guardar na BD (ex: uploads/kb/xxx.png)
 */
function processarUploadImagem(array $ficheiro, string $subpasta): array
{
    // Nenhum ficheiro enviado — não é erro (campo é opcional)
    if (!isset($ficheiro['error']) || $ficheiro['error'] === UPLOAD_ERR_NO_FILE) {
        return ['sucesso' => true, 'caminho' => null, 'erro' => ''];
    }

    if ($ficheiro['error'] !== UPLOAD_ERR_OK) {
        return ['sucesso' => false, 'caminho' => null, 'erro' => 'Falha no envio da imagem (código ' . $ficheiro['error'] . ').'];
    }

    // Limite de 5 MB
    if ($ficheiro['size'] > 5 * 1024 * 1024) {
        return ['sucesso' => false, 'caminho' => null, 'erro' => 'A imagem excede o tamanho máximo de 5 MB.'];
    }

    // Validar tipo real pelo conteúdo (não confiar na extensão)
    $extensoesPermitidas = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($ficheiro['tmp_name']);
    if (!isset($extensoesPermitidas[$mime])) {
        return ['sucesso' => false, 'caminho' => null, 'erro' => 'Formato inválido. Use JPG, PNG, GIF ou WEBP.'];
    }

    // Garantir que a pasta de destino existe
    $subpastaSegura = preg_replace('/[^a-z0-9_-]/i', '', $subpasta);
    $dirBase = __DIR__ . '/../uploads/' . $subpastaSegura;
    if (!is_dir($dirBase) && !mkdir($dirBase, 0775, true) && !is_dir($dirBase)) {
        return ['sucesso' => false, 'caminho' => null, 'erro' => 'Não foi possível criar a pasta de uploads.'];
    }

    // Nome único para evitar colisões
    $nomeFicheiro = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $extensoesPermitidas[$mime];
    $destinoAbsoluto = $dirBase . '/' . $nomeFicheiro;

    if (!move_uploaded_file($ficheiro['tmp_name'], $destinoAbsoluto)) {
        return ['sucesso' => false, 'caminho' => null, 'erro' => 'Não foi possível guardar a imagem no servidor.'];
    }

    return ['sucesso' => true, 'caminho' => 'uploads/' . $subpastaSegura . '/' . $nomeFicheiro, 'erro' => ''];
}

/**
 * Elimina utilizador tratando dependências na BD (evita erro de FK).
 * Reatribui artigos KB e tickets criados ao utilizador sistema.convidado.
 *
 * @return array{sucesso: bool, erro: string}
 */
function eliminarUtilizadorSeguro(PDO $pdo, int $idUtilizador): array
{
    try {
        $idSistema = obterIdUtilizadorSistema($pdo);

        if ($idUtilizador === $idSistema || $idUtilizador === 1) {
            return ['sucesso' => false, 'erro' => 'Não é possível remover esta conta protegida.'];
        }

        $pdo->beginTransaction();

        // kb_artigos não tem ON DELETE — reatribuir autor
        $pdo->prepare("UPDATE kb_artigos SET id_autor = ? WHERE id_autor = ?")
            ->execute([$idSistema, $idUtilizador]);

        // tickets.id_criador tem ON DELETE CASCADE — reatribuir para não apagar tickets
        $pdo->prepare("UPDATE tickets SET id_criador = ? WHERE id_criador = ?")
            ->execute([$idSistema, $idUtilizador]);

        // Limpar atribuição de técnico (opcional, SET NULL já trataria no DELETE)
        $pdo->prepare("UPDATE tickets SET id_tecnico_atribuido = NULL WHERE id_tecnico_atribuido = ?")
            ->execute([$idUtilizador]);

        $pdo->prepare("DELETE FROM utilizadores WHERE id = ?")->execute([$idUtilizador]);

        $pdo->commit();
        return ['sucesso' => true, 'erro' => ''];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['sucesso' => false, 'erro' => $e->getMessage()];
    }
}

/**
 * Categorias disponíveis na Base de Conhecimento.
 * Combina lista predefinida com categorias já usadas na BD.
 */
function obterCategoriasKb(PDO $pdo): array
{
    $predefinidas = [
        'Internet',
        'Altitude',
        'Redes e Sistemas',
        'Desenvolvimento',
        'Geral / Tutoriais',
        'Email',
        'Acesso',
        'Hardware',
        'Software',
        'VPN',
        'Telefonia',
    ];

    try {
        $dbCats = $pdo->query("SELECT DISTINCT categoria FROM kb_artigos WHERE categoria <> '' ORDER BY categoria")
            ->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        $dbCats = [];
    }

    $todas = array_merge($predefinidas, $dbCats);
    $unicas = [];
    foreach ($todas as $cat) {
        $cat = trim((string)$cat);
        if ($cat !== '' && !in_array($cat, $unicas, true)) {
            $unicas[] = $cat;
        }
    }

    sort($unicas, SORT_NATURAL | SORT_FLAG_CASE);
    return $unicas;
}
