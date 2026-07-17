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

require_once __DIR__ . '/catalogo_servicos.php';
require_once __DIR__ . '/permissoes.php';

/** Converte rótulos de perfil do formulário para o valor guardado na BD */
function mapearPerfilParaBd(string $perfil): string
{
    $mapa = [
        'Utilizador Comum' => 'Operador', // legado — perfil Comum removido
        'Comum' => 'Operador',
        'Cliente Operacional' => 'Operador',
        'Cliente' => 'Operador',
        'Responsável de Área' => 'Responsavel',
        'Supervisão' => 'Supervisao',
    ];
    return $mapa[$perfil] ?? $perfil;
}

/** Perfis ligados a uma operação (escolhem operação, não área livre) */
function perfilUsaOperacao(string $perfil): bool
{
    return in_array($perfil, ['Operador', 'Supervisao'], true);
}

/** True se o contexto é Supervisão da operação */
function perfilESupervisao(array $contexto): bool
{
    return ($contexto['perfil'] ?? '') === 'Supervisao'
        || ($contexto['codigo_perfil'] ?? '') === 'supervisao';
}

/**
 * Garante área «Supervisão {OPERAÇÃO}» por cada operação e liga operacoes.id_area_supervisao.
 * Idempotente.
 */
/**
 * Garante áreas «Supervisão {Operação}» ligadas a cada operação.
 *
 * @param bool $criarEmFalta Se false, não cria áreas novas (só repara ligações a áreas já existentes).
 *                           Usar true apenas em migrações / pedido explícito — nunca em cada page load,
 *                           senão áreas removidas pelo admin voltam ao actualizar a página.
 */
function garantirAreasSupervisao(PDO $pdo, bool $criarEmFalta = false): void
{
    static $schemaOk = false;
    static $syncFeito = false;

    if (!$schemaOk) {
        try {
            $pdo->exec("ALTER TABLE operacoes ADD COLUMN IF NOT EXISTS id_area_supervisao INT NULL AFTER nome");
        } catch (PDOException $e) {
            // ignore
        }
        try {
            $pdo->exec("ALTER TABLE operacoes ADD COLUMN IF NOT EXISTS supervisao_auto TINYINT(1) NOT NULL DEFAULT 1");
        } catch (PDOException $e) {
            // ignore
        }
        $schemaOk = true;
    }

    // Em navegação normal: não recriar áreas apagadas
    if (!$criarEmFalta) {
        if ($syncFeito) {
            return;
        }
        $syncFeito = true;
        // Limpa FKs órfãs (área apagada) sem recriar
        try {
            $pdo->exec("
                UPDATE operacoes o
                LEFT JOIN areas a ON a.id = o.id_area_supervisao
                SET o.id_area_supervisao = NULL
                WHERE o.id_area_supervisao IS NOT NULL AND a.id IS NULL
            ");
        } catch (PDOException $e) {
            // ignore
        }
        return;
    }

    try {
        $ops = $pdo->query("
            SELECT id, nome, id_area_supervisao, COALESCE(supervisao_auto, 1) AS supervisao_auto
            FROM operacoes
            ORDER BY id ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        try {
            $ops = $pdo->query("SELECT id, nome, id_area_supervisao FROM operacoes ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e2) {
            return;
        }
    }

    $stFind = $pdo->prepare("SELECT id FROM areas WHERE nome = ? LIMIT 1");
    $stIns = $pdo->prepare("INSERT INTO areas (nome) VALUES (?)");
    $stUpdOp = $pdo->prepare("UPDATE operacoes SET id_area_supervisao = ? WHERE id = ?");
    $stExisteArea = $pdo->prepare("SELECT id FROM areas WHERE id = ? LIMIT 1");

    foreach ($ops as $op) {
        $idOp = (int)$op['id'];
        $nomeOp = trim((string)$op['nome']);
        if ($idOp <= 0 || $nomeOp === '') {
            continue;
        }
        // Admin removeu a área de supervisão desta operação — não recriar
        if ((int)($op['supervisao_auto'] ?? 1) === 0) {
            continue;
        }

        $nomeArea = 'Supervisão ' . $nomeOp;
        $idArea = (int)($op['id_area_supervisao'] ?? 0);

        if ($idArea > 0) {
            try {
                $stExisteArea->execute([$idArea]);
                if ($stExisteArea->fetchColumn()) {
                    continue;
                }
            } catch (PDOException $e) {
                // recria abaixo
            }
            $idArea = 0;
        }

        try {
            $stFind->execute([$nomeArea]);
            $idArea = (int)($stFind->fetchColumn() ?: 0);
            if ($idArea <= 0) {
                $stIns->execute([$nomeArea]);
                $idArea = (int)$pdo->lastInsertId();
            }
            if ($idArea > 0) {
                $stUpdOp->execute([$idArea, $idOp]);
            }
        } catch (PDOException $e) {
            // ignore operação individual
        }
    }
}

/**
 * Marca que a área de supervisão desta operação foi removida de propósito
 * (evita que volte a ser criada ao actualizar a página).
 */
function desactivarAreaSupervisaoOperacao(PDO $pdo, int $idArea): void
{
    if ($idArea <= 0) {
        return;
    }
    try {
        $pdo->exec("ALTER TABLE operacoes ADD COLUMN IF NOT EXISTS supervisao_auto TINYINT(1) NOT NULL DEFAULT 1");
    } catch (PDOException $e) {
        // ignore
    }
    try {
        $st = $pdo->prepare("
            UPDATE operacoes
            SET id_area_supervisao = NULL, supervisao_auto = 0
            WHERE id_area_supervisao = ?
        ");
        $st->execute([$idArea]);

        // Também por nome: «Supervisão X» → operação X
        $stNome = $pdo->prepare('SELECT nome FROM areas WHERE id = ?');
        $stNome->execute([$idArea]);
        $nomeArea = trim((string)($stNome->fetchColumn() ?: ''));
        if ($nomeArea !== '' && preg_match('/^Supervis[aã]o\s+(.+)$/iu', $nomeArea, $m)) {
            $nomeOp = trim($m[1]);
            if ($nomeOp !== '') {
                $stOp = $pdo->prepare("
                    UPDATE operacoes
                    SET id_area_supervisao = NULL, supervisao_auto = 0
                    WHERE nome = ?
                ");
                $stOp->execute([$nomeOp]);
            }
        }
    } catch (PDOException $e) {
        // ignore
    }
}

/**
 * Se o admin voltar a criar «Supervisão X», reactiva a ligação automática.
 */
function reactivarAreaSupervisaoSeAplicavel(PDO $pdo, int $idArea, string $nomeArea): void
{
    if ($idArea <= 0 || !preg_match('/^Supervis[aã]o\s+(.+)$/iu', trim($nomeArea), $m)) {
        return;
    }
    $nomeOp = trim($m[1]);
    if ($nomeOp === '') {
        return;
    }
    try {
        $pdo->exec("ALTER TABLE operacoes ADD COLUMN IF NOT EXISTS supervisao_auto TINYINT(1) NOT NULL DEFAULT 1");
    } catch (PDOException $e) {
        // ignore
    }
    try {
        $st = $pdo->prepare("
            UPDATE operacoes
            SET id_area_supervisao = ?, supervisao_auto = 1
            WHERE nome = ?
        ");
        $st->execute([$idArea, $nomeOp]);
    } catch (PDOException $e) {
        // ignore
    }
}

/** ID da área de supervisão da operação (cria se necessário e se não foi desactivada). */
function obterIdAreaSupervisaoOperacao(PDO $pdo, int $idOperacao): ?int
{
    if ($idOperacao <= 0) {
        return null;
    }
    garantirAreasSupervisao($pdo, false);

    try {
        $st = $pdo->prepare("
            SELECT id_area_supervisao, nome, COALESCE(supervisao_auto, 1) AS supervisao_auto
            FROM operacoes WHERE id = ?
        ");
        $st->execute([$idOperacao]);
        $op = $st->fetch(PDO::FETCH_ASSOC);
        if (!$op) {
            return null;
        }
        $id = (int)($op['id_area_supervisao'] ?: 0);
        if ($id > 0) {
            $stEx = $pdo->prepare('SELECT id FROM areas WHERE id = ?');
            $stEx->execute([$id]);
            if ($stEx->fetchColumn()) {
                return $id;
            }
        }
        // Removida de propósito pelo admin
        if ((int)($op['supervisao_auto'] ?? 1) === 0) {
            return null;
        }
        // Cria só esta operação (pedido explícito: registo / conta supervisão)
        $nomeOp = trim((string)$op['nome']);
        if ($nomeOp === '') {
            return null;
        }
        $nomeArea = 'Supervisão ' . $nomeOp;
        $stFind = $pdo->prepare('SELECT id FROM areas WHERE nome = ? LIMIT 1');
        $stFind->execute([$nomeArea]);
        $idArea = (int)($stFind->fetchColumn() ?: 0);
        if ($idArea <= 0) {
            $pdo->prepare('INSERT INTO areas (nome) VALUES (?)')->execute([$nomeArea]);
            $idArea = (int)$pdo->lastInsertId();
        }
        if ($idArea > 0) {
            $pdo->prepare('UPDATE operacoes SET id_area_supervisao = ?, supervisao_auto = 1 WHERE id = ?')
                ->execute([$idArea, $idOperacao]);
            return $idArea;
        }
    } catch (PDOException $e) {
        return null;
    }
    return null;
}

/**
 * Para Supervisão: devolve [id_area, idsAreas] da área de supervisão da operação.
 * Para Operador: [null, []]. Caso contrário null (não altera).
 *
 * @return array{0:?int,1:list<int>}|null
 */
function resolverDestinoAreasPerfilOperacao(PDO $pdo, string $perfil, ?int $idOperacao): ?array
{
    if ($perfil === 'Operador') {
        return [null, []];
    }
    if ($perfil === 'Supervisao') {
        $idArea = $idOperacao ? obterIdAreaSupervisaoOperacao($pdo, $idOperacao) : null;
        if ($idArea) {
            return [$idArea, [$idArea]];
        }
        return [null, []];
    }
    return null;
}

/** ID da área Direção */
const AREA_DIRECAO = 3;

/** ID da área Redes & Sistemas */
const AREA_REDES_SISTEMAS = 1;

/** ID da área Desenvolvimento */
const AREA_DESENVOLVIMENTO = 2;

/** Separador entre vários assuntos no título do ticket */
const SEPARADOR_ASSUNTOS_TICKET = ' | ';

/** Máximo de assuntos na abertura de um ticket */
const MAX_ASSUNTOS_ABERTURA_TICKET = 8;

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

/** Normaliza prioridade (inclui Crítica; corrige "Media" legado → "Média") */
function normalizarPrioridade(string $prioridade): string
{
    return catalogoNormalizarNivel($prioridade);
}

/**
 * Calcula data limite do SLA de resolução conforme prioridade.
 * Crítica=4h | Alta=8h | Média=24h | Baixa=72h
 * (Resposta: usar calcularDatasLimiteSlaCatalogo / matriz do catálogo.)
 */
function calcularDataLimiteSla(string $prioridade, ?string $dataBase = null): string
{
    $prio = normalizarPrioridade($prioridade);
    $matriz = catalogoMatrizSlaPrioridade();
    $horas = (float)($matriz[$prio][1] ?? 24);
    $base = $dataBase ? strtotime($dataBase) : time();
    return date('Y-m-d H:i:s', (int)($base + ($horas * 3600)));
}

/**
 * Minutos de resposta SLA pela prioridade.
 */
function calcularMinutosRespostaSla(string $prioridade): int
{
    $prio = normalizarPrioridade($prioridade);
    $matriz = catalogoMatrizSlaPrioridade();
    return (int)($matriz[$prio][0] ?? 120);
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
/**
 * Árvore profissional de assuntos (categoria → detalhes).
 * Usada no seed e como fallback se a BD ainda não tiver hierarquia.
 *
 * @return array<string, string[]>
 */
function obterArvoreAssuntosTicketPadrao(): array
{
    return catalogoParaArvoreAssuntos();
}

/**
 * Itens fixos da checklist de Nova entrada (Redes & Sistemas).
 *
 * @return array<string, string> chave => rótulo
 */
function obterItensChecklistNovaEntrada(): array
{
    $mapa = [];
    foreach (catalogoItensNovaEntrada() as $item) {
        $mapa[$item['chave']] = $item['item'];
    }
    // Fallback estável se o catálogo falhar
    if (empty($mapa)) {
        return [
            'pt_hw_entrega_pc' => 'Entrega / substituição de computador',
            'email_criacao' => 'Criação de e-mail',
            'acesso_windows' => 'Criação de acessos ao Windows',
            'rede_mapa_pasta' => 'Mapeamento de pasta',
            'pt_sw_instalacao' => 'Instalação de aplicativos',
        ];
    }
    return $mapa;
}

/**
 * Ticket de Nova entrada destinado a Redes & Sistemas.
 */
function ticketENovaEntradaRedes(array $ticket): bool
{
    $area = (int)($ticket['id_area_destino'] ?? 0);
    if ($area !== AREA_REDES_SISTEMAS) {
        return false;
    }
    $titulo = (string)($ticket['titulo'] ?? '');
    if ($titulo === '') {
        return false;
    }
    foreach (parseAssuntosTicketTitulo($titulo) as $assunto) {
        if (stripos($assunto, 'Nova entrada') !== false || stripos($assunto, 'Nova Entrada') !== false) {
            return true;
        }
        if (stripos($assunto, 'Onboarding') !== false) {
            return true;
        }
    }
    return stripos($titulo, 'Nova entrada') !== false || stripos($titulo, 'Onboarding') !== false;
}

/**
 * Resolve prioridade + SLA a partir dos assuntos seleccionados e impacto/urgência do POST.
 *
 * @return array{titulo:string,prioridade:string,urgencia:string,impacto:string,sla_resposta_min:int,sla_resolucao_h:float,data_limite_resposta:string,data_limite_sla:string}
 */
function resolverPrioridadeESlaAbertura(array $post, ?string $dataBase = null): array
{
    $assuntos = obterAssuntosSubmetidosTicket($post);
    $titulo = !empty($assuntos) ? implode(SEPARADOR_ASSUNTOS_TICKET, $assuntos) : resolverAssuntoTicket($post);
    if ($titulo === '' && !empty($assuntos)) {
        $titulo = implode(SEPARADOR_ASSUNTOS_TICKET, $assuntos);
    }

    $itensCat = [];
    $ehNovaEntrada = false;
    foreach ($assuntos as $a) {
        $item = catalogoObterPorTitulo($a);
        if ($item) {
            $itensCat[] = $item;
            if (!empty($item['nova_entrada']) || stripos($a, 'Nova Entrada') !== false || stripos($a, 'Onboarding') !== false) {
                $ehNovaEntrada = true;
            }
        }
        if (stripos($a, 'Nova Entrada') !== false || stripos($a, 'Onboarding') !== false) {
            $ehNovaEntrada = true;
        }
    }

    if ($ehNovaEntrada) {
        $subs = catalogoItensNovaEntrada();
        $sla = resolverSlaNovaEntrada($subs);
    } elseif (!empty($itensCat)) {
        $sla = resolverSlaCatalogoMultiplo($itensCat);
    } else {
        $prioManual = normalizarPrioridade($post['prioridade'] ?? 'Média');
        $m = catalogoMatrizSlaPrioridade()[$prioManual] ?? catalogoMatrizSlaPrioridade()['Média'];
        $sla = [
            'prioridade' => $prioManual,
            'sla_resposta_min' => $m[0],
            'sla_resolucao_h' => $m[1],
            'urgencia' => $prioManual,
        ];
    }

    $impacto = trim($post['impacto'] ?? '');
    if ($impacto === '' || !isset(catalogoMatrizImpactoUrgencia()[$impacto])) {
        $impacto = inferirImpactoDescricao(trim($post['descricao'] ?? ''));
    }
    $urgenciaPost = trim($post['urgencia'] ?? '');
    $urgencia = $urgenciaPost !== ''
        ? catalogoNormalizarNivel($urgenciaPost)
        : catalogoNormalizarNivel($sla['urgencia']);

    // Prioridade final: max(catálogo, matriz impacto×urgência)
    $prioMatriz = calcularPrioridadeImpactoUrgencia($impacto, $urgencia);
    $scoreCat = CATALOGO_SEVERIDADE[$sla['prioridade']] ?? 2;
    $scoreMat = CATALOGO_SEVERIDADE[$prioMatriz] ?? 2;
    $prioridade = $scoreCat >= $scoreMat ? $sla['prioridade'] : $prioMatriz;

    // Se prioridade subiu pela matriz, ajustar SLA à matriz da nova prioridade
    if ((CATALOGO_SEVERIDADE[$prioridade] ?? 2) > (CATALOGO_SEVERIDADE[$sla['prioridade']] ?? 2)) {
        $m = catalogoMatrizSlaPrioridade()[$prioridade];
        $sla['sla_resposta_min'] = $m[0];
        $sla['sla_resolucao_h'] = $m[1];
    }

    [$dataResp, $dataResol] = calcularDatasLimiteSlaCatalogo(
        (int)$sla['sla_resposta_min'],
        (float)$sla['sla_resolucao_h'],
        $dataBase
    );

    return [
        'titulo' => $titulo,
        'prioridade' => $prioridade,
        'urgencia' => $urgencia,
        'impacto' => $impacto,
        'sla_resposta_min' => (int)$sla['sla_resposta_min'],
        'sla_resolucao_h' => (float)$sla['sla_resolucao_h'],
        'data_limite_resposta' => $dataResp,
        'data_limite_sla' => $dataResol,
    ];
}

/**
 * Garante linhas da checklist para o ticket (criação lazy).
 *
 * @return array<int, array<string, mixed>>
 */
function garantirChecklistTicket(PDO $pdo, int $idTicket): array
{
    $itens = obterItensChecklistNovaEntrada();
    $stExiste = $pdo->prepare("SELECT COUNT(*) FROM ticket_checklist WHERE id_ticket = ?");
    $stExiste->execute([$idTicket]);
    if ((int)$stExiste->fetchColumn() === 0) {
        $ins = $pdo->prepare("
            INSERT INTO ticket_checklist (id_ticket, item_chave, item_rotulo, feito, pendente, ordem)
            VALUES (?, ?, ?, 0, 0, ?)
        ");
        $ordem = 10;
        foreach ($itens as $chave => $rotulo) {
            $ins->execute([$idTicket, $chave, $rotulo, $ordem]);
            $ordem += 10;
        }
    }

    $st = $pdo->prepare("
        SELECT * FROM ticket_checklist
        WHERE id_ticket = ?
        ORDER BY ordem ASC, id ASC
    ");
    $st->execute([$idTicket]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Guarda checklist: itens feitos + se ficou algo pendente.
 * Pendente → Em Progresso; Concluída → Resolvido.
 *
 * @param string[] $chavesFeitas
 * @return array{ok:bool, mensagem:string, estado:?string}
 */
function guardarChecklistNovaEntrada(
    PDO $pdo,
    int $idTicket,
    array $chavesFeitas,
    bool $ficouPendente,
    ?int $idUtilizador
): array {
    $itens = obterItensChecklistNovaEntrada();
    garantirChecklistTicket($pdo, $idTicket);

    $upd = $pdo->prepare("
        UPDATE ticket_checklist
        SET feito = ?, pendente = ?, id_utilizador = ?, data_atualizacao = NOW()
        WHERE id_ticket = ? AND item_chave = ?
    ");

    $feitos = [];
    $pendentes = [];
    foreach ($itens as $chave => $rotulo) {
        $feito = in_array($chave, $chavesFeitas, true) ? 1 : 0;
        // Se ficou pendente e o item não foi feito, marca como pendente
        $pendente = ($ficouPendente && !$feito) ? 1 : 0;
        $upd->execute([$feito, $pendente, $idUtilizador, $idTicket, $chave]);
        if ($feito) {
            $feitos[] = $rotulo;
        }
        if ($pendente) {
            $pendentes[] = $rotulo;
        }
    }

    if ($ficouPendente) {
        $pdo->prepare("
            UPDATE tickets
            SET estado = 'Em Progresso', data_resolucao = NULL
            WHERE id = ?
        ")->execute([$idTicket]);
        $detalhe = 'Checklist Nova entrada — Pendente. Feito: '
            . (empty($feitos) ? 'nenhum' : implode('; ', $feitos));
        if (!empty($pendentes)) {
            $detalhe .= '. Pendente: ' . implode('; ', $pendentes);
        }
        registarHistoricoTicket($pdo, $idTicket, $idUtilizador, 'Checklist', $detalhe);
        return [
            'ok' => true,
            'mensagem' => 'Checklist guardada. O ticket ficou marcado como <b>Pendente</b> (Em Progresso).',
            'estado' => 'Em Progresso',
        ];
    }

    // Sem pendências → concluída
    if (count($feitos) === 0) {
        return [
            'ok' => false,
            'mensagem' => 'Seleccione pelo menos um item feito, ou indique que ficou algo pendente.',
            'estado' => null,
        ];
    }

    $pdo->prepare("
        UPDATE tickets
        SET estado = 'Resolvido', data_resolucao = NOW()
        WHERE id = ?
    ")->execute([$idTicket]);
    $detalhe = 'Checklist Nova entrada — Concluída. Feito: ' . implode('; ', $feitos);
    registarHistoricoTicket($pdo, $idTicket, $idUtilizador, 'Checklist', $detalhe);
    return [
        'ok' => true,
        'mensagem' => 'Checklist concluída. O ticket foi marcado como <b>Resolvido</b>.',
        'estado' => 'Resolvido',
    ];
}

/**
 * Resumo do estado da checklist (para badges).
 *
 * @param array<int, array<string, mixed>> $linhas
 * @return array{total:int, feitos:int, pendentes:int, completa:bool, tem_pendente:bool}
 */
function resumoChecklistNovaEntrada(array $linhas): array
{
    $total = count($linhas);
    $feitos = 0;
    $pendentes = 0;
    foreach ($linhas as $l) {
        if (!empty($l['feito'])) {
            $feitos++;
        }
        if (!empty($l['pendente'])) {
            $pendentes++;
        }
    }
    return [
        'total' => $total,
        'feitos' => $feitos,
        'pendentes' => $pendentes,
        'completa' => $total > 0 && $feitos === $total && $pendentes === 0,
        'tem_pendente' => $pendentes > 0,
    ];
}

/** Lista plana legado (fallback) */
function obterAssuntosTicketPadrao(): array
{
    $lista = [];
    foreach (obterArvoreAssuntosTicketPadrao() as $categoria => $detalhes) {
        foreach ($detalhes as $detalhe) {
            $lista[] = formatarAssuntoTicketCascata($categoria, $detalhe);
        }
    }
    return $lista;
}

/** Junta categoria e detalhe no título guardado no ticket */
function formatarAssuntoTicketCascata(string $categoria, string $detalhe): string
{
    $categoria = trim($categoria);
    $detalhe = trim($detalhe);
    if ($categoria === '') {
        return $detalhe;
    }
    if ($detalhe === '') {
        return $categoria;
    }
    return $categoria . ' › ' . $detalhe;
}

/**
 * @return array{0:?string,1:?string} [categoria, detalhe]
 */
function decomporAssuntoTicketCascata(string $titulo): array
{
    $titulo = trim($titulo);
    if ($titulo === '') {
        return [null, null];
    }
    if (str_contains($titulo, ' › ')) {
        [$cat, $det] = explode(' › ', $titulo, 2);
        return [trim($cat), trim($det)];
    }
    // Compatibilidade com títulos antigos (lista plana)
    return [null, $titulo];
}

/**
 * Árvore activa a partir da BD (id_pai NULL = categoria).
 *
 * @return array<string, string[]>
 */
function obterArvoreAssuntosTicket(?PDO $pdo = null): array
{
    if ($pdo === null) {
        return obterArvoreAssuntosTicketPadrao();
    }
    try {
        $cats = $pdo->query("
            SELECT id, titulo FROM ticket_assuntos
            WHERE ativo = 1 AND (id_pai IS NULL OR id_pai = 0)
            ORDER BY ordem ASC, titulo ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($cats)) {
            return obterArvoreAssuntosTicketPadrao();
        }
        $arvore = [];
        $stFilhos = $pdo->prepare("
            SELECT titulo FROM ticket_assuntos
            WHERE ativo = 1 AND id_pai = ?
            ORDER BY ordem ASC, titulo ASC
        ");
        foreach ($cats as $cat) {
            $stFilhos->execute([(int)$cat['id']]);
            $filhos = $stFilhos->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($filhos)) {
                $arvore[$cat['titulo']] = $filhos;
            }
        }
        return !empty($arvore) ? $arvore : obterArvoreAssuntosTicketPadrao();
    } catch (PDOException $e) {
        return obterArvoreAssuntosTicketPadrao();
    }
}

/**
 * Assuntos activos (títulos finais) para validação / compatibilidade.
 *
 * @return string[]
 */
function obterAssuntosTicket(?PDO $pdo = null): array
{
    $lista = [];
    foreach (obterArvoreAssuntosTicket($pdo) as $categoria => $detalhes) {
        foreach ($detalhes as $detalhe) {
            $lista[] = formatarAssuntoTicketCascata($categoria, $detalhe);
        }
    }
    // Inclui títulos antigos ainda activos sem pai (lista plana legada)
    if ($pdo !== null) {
        try {
            $temPai = false;
            try {
                $temPai = (bool)$pdo->query("SELECT COUNT(*) FROM ticket_assuntos WHERE id_pai IS NOT NULL AND id_pai > 0")->fetchColumn();
            } catch (PDOException $e) {
                $temPai = false;
            }
            if (!$temPai) {
                $stmt = $pdo->query("SELECT titulo FROM ticket_assuntos WHERE ativo = 1 ORDER BY ordem ASC, titulo ASC");
                $flat = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($flat)) {
                    return $flat;
                }
            }
        } catch (PDOException $e) {
            // ignora
        }
    }
    return $lista ?: obterAssuntosTicketPadrao();
}

/** Gestão de assuntos: Admin e staff das áreas técnicas / flag gerir_assuntos */
function podeGerirAssuntosTicket(array $contexto): bool
{
    if (temPermissao($contexto, 'gerir_assuntos')) {
        if (!empty($contexto['is_perfil_sistema'])) {
            return podeAcederAdministracao($contexto);
        }
        return true;
    }
    return podeGerirUtilizadores($contexto);
}

/**
 * Resolve o assunto (título) a partir do formulário em cascata ou legado.
 */
/**
 * Resolve um único assunto a partir dos campos de cascata (sem lista múltipla).
 */
function resolverAssuntoCascataUnico(array $post): string
{
    $categoria = trim($post['assunto_categoria'] ?? '');
    $detalhe = trim($post['assunto_detalhe'] ?? '');
    if ($categoria === '__outro__' || $detalhe === '__outro__') {
        return trim($post['assunto_outro'] ?? '');
    }
    if ($categoria !== '' && $detalhe !== '') {
        return formatarAssuntoTicketCascata($categoria, $detalhe);
    }

    $selecionado = trim($post['assunto_predefinido'] ?? '');
    if ($selecionado === '__outro__' || $selecionado === 'Outros') {
        return trim($post['assunto_outro'] ?? '');
    }
    return $selecionado;
}

/**
 * @return string[]
 */
function parseAssuntosTicketTitulo(string $titulo): array
{
    $titulo = trim($titulo);
    if ($titulo === '') {
        return [];
    }
    if (str_contains($titulo, SEPARADOR_ASSUNTOS_TICKET)) {
        return array_values(array_filter(array_map('trim', explode(SEPARADOR_ASSUNTOS_TICKET, $titulo))));
    }
    return [$titulo];
}

/**
 * Lista de assuntos seleccionados no POST (abertura com múltiplos assuntos).
 *
 * @return string[]
 */
function obterAssuntosSubmetidosTicket(array $post): array
{
    $lista = [];
    if (!empty($post['assuntos_selecionados']) && is_array($post['assuntos_selecionados'])) {
        foreach ($post['assuntos_selecionados'] as $raw) {
            $a = trim((string)$raw);
            if ($a !== '' && !in_array($a, $lista, true)) {
                $lista[] = $a;
            }
        }
    }
    if (!empty($lista)) {
        return array_slice($lista, 0, MAX_ASSUNTOS_ABERTURA_TICKET);
    }
    $unico = resolverAssuntoCascataUnico($post);
    return $unico !== '' ? [$unico] : [];
}

/**
 * @return string[]
 */
function resolverAssuntosTicket(array $post, ?PDO $pdo = null): array
{
    $lista = obterAssuntosSubmetidosTicket($post);
    if (empty($lista)) {
        $unico = resolverAssuntoCascataUnico($post);
        if ($unico !== '') {
            $lista[] = $unico;
        }
    }
    return array_slice($lista, 0, MAX_ASSUNTOS_ABERTURA_TICKET);
}

function resolverAssuntoTicket(array $post, ?PDO $pdo = null): string
{
    $lista = resolverAssuntosTicket($post, $pdo);
    if (empty($lista)) {
        return '';
    }
    $titulo = implode(SEPARADOR_ASSUNTOS_TICKET, $lista);
    if (strlen($titulo) > 500) {
        $titulo = mb_substr($titulo, 0, 497, 'UTF-8') . '...';
    }
    return $titulo;
}

/**
 * Opções legado (select único) — mantido para compatibilidade.
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
    $selOutro = ($valorAtual !== '' && !$ehPredefinido) ? ' selected' : '';
    $html .= "<option value=\"__outro__\"{$selOutro}>Outros (especificar)</option>";
    return $html;
}

/**
 * HTML do seletor em cascata (categoria → detalhe → Outros).
 *
 * @param string[] $assuntosSelecionados Assuntos já escolhidos (modo múltiplo)
 * @param string $estiloSelect CSS inline opcional para o select
 */
function htmlSeletorAssuntoCascata(
    ?PDO $pdo,
    string $valorAtual = '',
    string $idPrefixo = 'assunto',
    string $estiloSelect = '',
    bool $multiplo = false,
    array $assuntosSelecionados = []
): string {
    if ($multiplo) {
        return htmlSeletorAssuntoCascataMultiplo($pdo, $idPrefixo, $estiloSelect, $assuntosSelecionados);
    }

    $arvore = obterArvoreAssuntosTicket($pdo);
    [$catAtual, $detAtual] = decomporAssuntoTicketCascata($valorAtual);
    $titulosFinais = obterAssuntosTicket($pdo);
    $ehLivre = ($valorAtual !== '' && !in_array($valorAtual, $titulosFinais, true));

    // Se título antigo plano, tenta mapear ao detalhe numa categoria
    if ($catAtual === null && $detAtual !== null && !$ehLivre) {
        foreach ($arvore as $cat => $dets) {
            if (in_array($detAtual, $dets, true) || in_array($valorAtual, $dets, true)) {
                $catAtual = $cat;
                if (in_array($valorAtual, $dets, true)) {
                    $detAtual = $valorAtual;
                }
                break;
            }
        }
        // Título completo antigo tipo "Internet / Rede"
        if ($catAtual === null) {
            $ehLivre = true;
        }
    }

    if ($estiloSelect === '') {
        $estiloSelect = 'width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:var(--text-primary); border-radius:var(--radius-sm);';
    }

    $idCat = $idPrefixo . '_categoria';
    $idDet = $idPrefixo . '_detalhe';
    $idOutro = $idPrefixo . '_outro';
    $idWrapDet = $idPrefixo . '_wrap_detalhe';
    $idWrapOutro = $idPrefixo . '_wrap_outro';
    $jsonArvore = json_encode($arvore, JSON_UNESCAPED_UNICODE);

    $html = '<div class="assunto-cascata" data-prefix="' . htmlspecialchars($idPrefixo) . '">';
    $html .= '<label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Categoria</label>';
    $html .= '<select name="assunto_categoria" id="' . htmlspecialchars($idCat) . '" required style="' . $estiloSelect . '">';
    $html .= '<option value="">-- Escolha a categoria --</option>';
    foreach (array_keys($arvore) as $cat) {
        $sel = (!$ehLivre && $catAtual === $cat) ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($cat) . '"' . $sel . '>' . htmlspecialchars($cat) . '</option>';
    }
    $selOutroCat = $ehLivre ? ' selected' : '';
    $html .= '<option value="__outro__"' . $selOutroCat . '>Outros (especificar)</option>';
    $html .= '</select>';

    $mostrarDet = !$ehLivre && $catAtual !== null;
    $html .= '<div id="' . htmlspecialchars($idWrapDet) . '" style="margin-top:10px; display:' . ($mostrarDet ? 'block' : 'none') . ';">';
    $html .= '<label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Detalhe do pedido</label>';
    $html .= '<select name="assunto_detalhe" id="' . htmlspecialchars($idDet) . '" style="' . $estiloSelect . '">';
    $html .= '<option value="">-- Escolha o detalhe --</option>';
    if ($mostrarDet && isset($arvore[$catAtual])) {
        foreach ($arvore[$catAtual] as $det) {
            $sel = ($detAtual === $det) ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($det) . '"' . $sel . '>' . htmlspecialchars($det) . '</option>';
        }
    }
    $html .= '</select>';
    $html .= '<small style="display:block; margin-top:6px; color:var(--text-muted); font-size:11px;">Após escolher o detalhe, complete a descrição do ticket.</small>';
    $html .= '</div>';

    $html .= '<div id="' . htmlspecialchars($idWrapOutro) . '" style="margin-top:10px; display:' . ($ehLivre ? 'block' : 'none') . ';">';
    $html .= '<label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Descreva o assunto</label>';
    $html .= '<input type="text" name="assunto_outro" id="' . htmlspecialchars($idOutro) . '" value="' . ($ehLivre ? htmlspecialchars($valorAtual) : '') . '" placeholder="Ex: pedido específico" maxlength="150" style="' . $estiloSelect . '">';
    $html .= '</div>';
    $html .= '</div>';

    $html .= '<script>(function(){';
    $html .= 'var ARVORE=' . $jsonArvore . ';';
    $html .= 'var cat=document.getElementById(' . json_encode($idCat) . ');';
    $html .= 'var det=document.getElementById(' . json_encode($idDet) . ');';
    $html .= 'var wrapDet=document.getElementById(' . json_encode($idWrapDet) . ');';
    $html .= 'var wrapOutro=document.getElementById(' . json_encode($idWrapOutro) . ');';
    $html .= 'var outro=document.getElementById(' . json_encode($idOutro) . ');';
    $html .= 'if(!cat||!det)return;';
    $html .= 'function actualizar(){';
    $html .= 'var v=cat.value;';
    $html .= 'if(v==="__outro__"){wrapDet.style.display="none";wrapOutro.style.display="block";det.required=false;det.value="";outro.required=true;return;}';
    $html .= 'wrapOutro.style.display="none";outro.required=false;outro.value="";';
    $html .= 'if(!v||!ARVORE[v]){wrapDet.style.display="none";det.required=false;det.innerHTML="<option value=\\"\\">-- Escolha o detalhe --</option>";return;}';
    $html .= 'wrapDet.style.display="block";det.required=true;';
    $html .= 'var prev=det.value;det.innerHTML="<option value=\\"\\">-- Escolha o detalhe --</option>";';
    $html .= '(ARVORE[v]||[]).forEach(function(d){var o=document.createElement("option");o.value=d;o.textContent=d;if(d===prev)o.selected=true;det.appendChild(o);});';
    $html .= '}';
    $html .= 'cat.addEventListener("change",actualizar);';
    $html .= '})();</script>';

    return $html;
}

/**
 * Seletor em cascata com vários assuntos na abertura do ticket.
 *
 * @param string[] $assuntosSelecionados
 */
function htmlSeletorAssuntoCascataMultiplo(?PDO $pdo, string $idPrefixo, string $estiloSelect, array $assuntosSelecionados = []): string
{
    $arvore = obterArvoreAssuntosTicket($pdo);
    if ($estiloSelect === '') {
        $estiloSelect = 'width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:var(--text-primary); border-radius:var(--radius-sm);';
    }

    $idCat = $idPrefixo . '_categoria';
    $idDet = $idPrefixo . '_detalhe';
    $idOutro = $idPrefixo . '_outro';
    $idWrapDet = $idPrefixo . '_wrap_detalhe';
    $idWrapOutro = $idPrefixo . '_wrap_outro';
    $idLista = $idPrefixo . '_lista';
    $idBtn = $idPrefixo . '_btn_add';
    $idContador = $idPrefixo . '_contador';
    $jsonArvore = json_encode($arvore, JSON_UNESCAPED_UNICODE);
    $jsonLista = json_encode(array_values($assuntosSelecionados), JSON_UNESCAPED_UNICODE);
    $max = MAX_ASSUNTOS_ABERTURA_TICKET;

    $html = '<div class="assunto-cascata assunto-cascata-multiplo" data-prefix="' . htmlspecialchars($idPrefixo) . '">';
    $html .= '<p style="margin:0 0 10px; font-size:12px; color:var(--text-secondary);">Pode adicionar vários assuntos ao mesmo ticket (até ' . $max . '). Escolha categoria e detalhe e clique em <strong>Adicionar assunto</strong>.</p>';
    $html .= '<label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Categoria</label>';
    $html .= '<select id="' . htmlspecialchars($idCat) . '" style="' . $estiloSelect . '">';
    $html .= '<option value="">-- Escolha a categoria --</option>';
    foreach (array_keys($arvore) as $cat) {
        $html .= '<option value="' . htmlspecialchars($cat) . '">' . htmlspecialchars($cat) . '</option>';
    }
    $html .= '<option value="__outro__">Outros (especificar)</option>';
    $html .= '</select>';

    $html .= '<div id="' . htmlspecialchars($idWrapDet) . '" style="margin-top:10px; display:none;">';
    $html .= '<label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Detalhe do pedido</label>';
    $html .= '<select id="' . htmlspecialchars($idDet) . '" style="' . $estiloSelect . '">';
    $html .= '<option value="">-- Escolha o detalhe --</option>';
    $html .= '</select>';
    $html .= '</div>';

    $html .= '<div id="' . htmlspecialchars($idWrapOutro) . '" style="margin-top:10px; display:none;">';
    $html .= '<label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Descreva o assunto</label>';
    $html .= '<input type="text" id="' . htmlspecialchars($idOutro) . '" placeholder="Ex: pedido específico" maxlength="150" style="' . $estiloSelect . '">';
    $html .= '</div>';

    $html .= '<div style="margin-top:12px; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">';
    $html .= '<button type="button" id="' . htmlspecialchars($idBtn) . '" class="btn-assunto-add" style="padding:8px 14px; background:var(--accent); color:#fff; border:none; border-radius:var(--radius-sm); cursor:pointer; font-size:13px;">+ Adicionar assunto</button>';
    $html .= '<span id="' . htmlspecialchars($idContador) . '" style="font-size:12px; color:var(--text-muted);">0/' . $max . ' assuntos</span>';
    $html .= '</div>';

    $html .= '<div id="' . htmlspecialchars($idLista) . '" class="assunto-chip-list" style="margin-top:12px;"></div>';
    $html .= '</div>';

    // Script externo ao PHP-string: evita quebrar aspas no querySelector (bug do botão)
    $selRoot = '.assunto-cascata-multiplo[data-prefix="' . htmlspecialchars($idPrefixo, ENT_QUOTES, 'UTF-8') . '"]';
    $html .= '<script>(function(){';
    $html .= 'var ARVORE=' . $jsonArvore . ';';
    $html .= 'var MAX=' . (int)$max . ';';
    $html .= 'var lista=' . $jsonLista . ';';
    $html .= 'var root=document.querySelector(' . json_encode($selRoot) . ');';
    $html .= 'if(!root)return;';
    $html .= 'var cat=document.getElementById(' . json_encode($idCat) . ');';
    $html .= 'var det=document.getElementById(' . json_encode($idDet) . ');';
    $html .= 'var wrapDet=document.getElementById(' . json_encode($idWrapDet) . ');';
    $html .= 'var wrapOutro=document.getElementById(' . json_encode($idWrapOutro) . ');';
    $html .= 'var outro=document.getElementById(' . json_encode($idOutro) . ');';
    $html .= 'var btnAdd=document.getElementById(' . json_encode($idBtn) . ');';
    $html .= 'var contador=document.getElementById(' . json_encode($idContador) . ');';
    $html .= 'var listaEl=document.getElementById(' . json_encode($idLista) . ');';
    $html .= 'if(!cat||!det||!listaEl||!btnAdd)return;';
    $html .= 'function formatarAssunto(c,d){c=(c||"").trim();d=(d||"").trim();if(!c)return d;if(!d)return c;return c+" › "+d;}';
    $html .= 'function actualizarCascata(){';
    $html .= 'var v=cat.value;';
    $html .= 'if(v==="__outro__"){wrapDet.style.display="none";wrapOutro.style.display="block";det.value="";return;}';
    $html .= 'wrapOutro.style.display="none";if(outro)outro.value="";';
    $html .= 'if(!v||!ARVORE[v]){wrapDet.style.display="none";det.innerHTML="<option value=\\"\\">-- Escolha o detalhe --</option>";return;}';
    $html .= 'wrapDet.style.display="block";';
    $html .= 'var prev=det.value;det.innerHTML="<option value=\\"\\">-- Escolha o detalhe --</option>";';
    $html .= '(ARVORE[v]||[]).forEach(function(d){var o=document.createElement("option");o.value=d;o.textContent=d;if(d===prev)o.selected=true;det.appendChild(o);});';
    $html .= '}';
    $html .= 'function assuntoActual(){';
    $html .= 'var v=cat.value;';
    $html .= 'if(v==="__outro__"){var t=(outro&&outro.value||"").trim();return t||null;}';
    $html .= 'if(v&&det.value)return formatarAssunto(v,det.value);';
    $html .= 'return null;';
    $html .= '}';
    $html .= 'function renderLista(){';
    $html .= 'listaEl.innerHTML="";';
    $html .= 'lista.forEach(function(item,idx){';
    $html .= 'var chip=document.createElement("div");chip.className="assunto-chip";';
    $html .= 'chip.innerHTML="<span class=\\"assunto-chip-texto\\"></span><button type=\\"button\\" class=\\"assunto-chip-remover\\" aria-label=\\"Remover assunto\\">×</button>";';
    $html .= 'chip.querySelector(".assunto-chip-texto").textContent=item;';
    $html .= 'var hidden=document.createElement("input");hidden.type="hidden";hidden.name="assuntos_selecionados[]";hidden.value=item;';
    $html .= 'chip.appendChild(hidden);';
    $html .= 'chip.querySelector(".assunto-chip-remover").addEventListener("click",function(){lista.splice(idx,1);renderLista();});';
    $html .= 'listaEl.appendChild(chip);';
    $html .= '});';
    $html .= 'contador.textContent=lista.length+"/"+MAX+" assuntos";';
    $html .= 'btnAdd.disabled=lista.length>=MAX;';
    $html .= '}';
    $html .= 'function adicionarAssunto(silencioso){';
    $html .= 'var a=assuntoActual();';
    $html .= 'if(!a){if(!silencioso)alert("Seleccione categoria e detalhe, ou preencha Outros.");return false;}';
    $html .= 'if(lista.indexOf(a)>=0){if(!silencioso)alert("Este assunto já foi adicionado.");return false;}';
    $html .= 'if(lista.length>=MAX){if(!silencioso)alert("Máximo de "+MAX+" assuntos.");return false;}';
    $html .= 'lista.push(a);renderLista();cat.value="";actualizarCascata();return true;';
    $html .= '}';
    $html .= 'cat.addEventListener("change",actualizarCascata);';
    $html .= 'btnAdd.addEventListener("click",function(ev){ev.preventDefault();ev.stopPropagation();adicionarAssunto(false);});';
    $html .= 'var form=root.closest("form");';
    $html .= 'if(form){form.addEventListener("submit",function(e){';
    $html .= 'var pendente=assuntoActual();';
    $html .= 'if(pendente&&lista.indexOf(pendente)<0&&lista.length<MAX){lista.push(pendente);renderLista();}';
    $html .= 'if(lista.length===0){e.preventDefault();alert("Adicione pelo menos um assunto ao ticket.");}';
    $html .= '});}';
    $html .= 'actualizarCascata();renderLista();';
    $html .= '})();</script>';

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

/**
 * Formata duração em segundos (ex: "45 s", "12 min", "2h 5m").
 */
function formatarDuracaoSegundos(?int $segundos): string
{
    if ($segundos === null || $segundos < 0) {
        return '—';
    }
    if ($segundos < 60) {
        return $segundos . ' s';
    }
    return formatarDuracaoMinutos((int)round($segundos / 60));
}

/**
 * Calcula o controlo de tempos de um ticket a partir das datas e do histórico.
 *
 * @param list<array<string,mixed>> $historico
 * @return array{
 *   tempo_aberto:string,
 *   aberto_em_curso:bool,
 *   tempo_ate_primeiro_assumir:?string,
 *   tempo_ate_resolucao:?string,
 *   marcos:list<array<string,mixed>>,
 *   assumicoes:list<array<string,mixed>>,
 *   por_pessoa:list<array<string,mixed>>
 * }
 */
function obterControloTemposTicket(array $ticket, array $historico): array
{
    $criacaoTs = !empty($ticket['data_criacao']) ? (int)strtotime((string)$ticket['data_criacao']) : time();
    $resolucaoTs = !empty($ticket['data_resolucao']) ? (int)strtotime((string)$ticket['data_resolucao']) : null;
    if (($ticket['estado'] ?? '') === 'Resolvido' && $resolucaoTs === null) {
        // Fallback: último evento de estado Resolvido no histórico
        foreach ($historico as $h) {
            if (($h['acao'] ?? '') === 'Estado' && stripos((string)($h['detalhes'] ?? ''), 'Resolvido') !== false) {
                $resolucaoTs = (int)strtotime((string)($h['data_registo'] ?? ''));
                break;
            }
        }
    }
    $agora = time();
    $fimAberto = $resolucaoTs ?? $agora;

    $hist = $historico;
    usort($hist, static function (array $a, array $b): int {
        $ida = (int)($a['id'] ?? 0);
        $idb = (int)($b['id'] ?? 0);
        if ($ida !== $idb) {
            return $ida <=> $idb;
        }
        return strtotime((string)($a['data_registo'] ?? '')) <=> strtotime((string)($b['data_registo'] ?? ''));
    });

    $acoesMarco = ['Assumido', 'Atribuição', 'Estado', 'Reencaminhamento', 'Reabertura'];
    $marcos = [[
        'rotulo' => 'Aberto',
        'detalhe' => 'Ticket aberto',
        'autor' => null,
        'quando' => date('d/m/Y H:i', $criacaoTs),
        'quando_ts' => $criacaoTs,
        'desde_abertura' => '0 s',
        'desde_anterior' => '—',
    ]];
    $prevTs = $criacaoTs;
    $jaTemResolucao = false;

    foreach ($hist as $h) {
        $acao = (string)($h['acao'] ?? '');
        if (!in_array($acao, $acoesMarco, true)) {
            continue;
        }
        $ts = !empty($h['data_registo']) ? (int)strtotime((string)$h['data_registo']) : $prevTs;
        $detalhe = trim((string)($h['detalhes'] ?? ''));
        $rotulo = $acao;
        if ($acao === 'Estado') {
            if (stripos($detalhe, 'Resolvido') !== false) {
                $rotulo = 'Resolvido';
                $jaTemResolucao = true;
            } elseif (stripos($detalhe, 'Em Progresso') !== false) {
                $rotulo = 'Em Progresso';
            } elseif (stripos($detalhe, 'Aberto') !== false) {
                $rotulo = 'Reaberto / Aberto';
            } else {
                $rotulo = 'Mudança de estado';
            }
        } elseif ($acao === 'Assumido') {
            $rotulo = 'Assumido';
        } elseif ($acao === 'Atribuição') {
            $rotulo = 'Atribuído';
        }

        $marcos[] = [
            'rotulo' => $rotulo,
            'detalhe' => $detalhe !== '' ? $detalhe : $rotulo,
            'autor' => $h['nome_autor'] ?? 'Sistema',
            'quando' => date('d/m/Y H:i', $ts),
            'quando_ts' => $ts,
            'desde_abertura' => formatarDuracaoSegundos(max(0, $ts - $criacaoTs)),
            'desde_anterior' => formatarDuracaoSegundos(max(0, $ts - $prevTs)),
        ];
        $prevTs = $ts;
    }

    if ($resolucaoTs && !$jaTemResolucao) {
        $marcos[] = [
            'rotulo' => 'Resolvido',
            'detalhe' => 'Ticket resolvido',
            'autor' => null,
            'quando' => date('d/m/Y H:i', $resolucaoTs),
            'quando_ts' => $resolucaoTs,
            'desde_abertura' => formatarDuracaoSegundos(max(0, $resolucaoTs - $criacaoTs)),
            'desde_anterior' => formatarDuracaoSegundos(max(0, $resolucaoTs - $prevTs)),
        ];
    }

    $assumeEvents = [];
    foreach ($hist as $h) {
        if (in_array((string)($h['acao'] ?? ''), ['Assumido', 'Atribuição'], true)) {
            $assumeEvents[] = $h;
        }
    }

    $idTecnicoAtual = (int)($ticket['id_tecnico_atribuido'] ?? 0);
    $assumicoes = [];
    $nAssume = count($assumeEvents);
    for ($i = 0; $i < $nAssume; $i++) {
        $ev = $assumeEvents[$i];
        $start = !empty($ev['data_registo']) ? (int)strtotime((string)$ev['data_registo']) : $criacaoTs;
        $idUser = (int)($ev['id_utilizador'] ?? 0);
        $fim = null;
        $activo = false;

        if (isset($assumeEvents[$i + 1])) {
            $fim = (int)strtotime((string)$assumeEvents[$i + 1]['data_registo']);
        } elseif ($resolucaoTs) {
            $fim = $resolucaoTs;
        } else {
            $fim = $agora;
            $activo = ($idTecnicoAtual <= 0 || $idUser === $idTecnicoAtual || $idUser === 0);
        }

        if ($resolucaoTs && $fim > $resolucaoTs) {
            $fim = $resolucaoTs;
            $activo = false;
        }

        $assumicoes[] = [
            'nome' => (string)($ev['nome_autor'] ?? 'Desconhecido'),
            'id_utilizador' => $idUser,
            'inicio' => date('d/m/Y H:i', $start),
            'fim' => date('d/m/Y H:i', $fim),
            'duracao' => formatarDuracaoSegundos(max(0, $fim - $start)),
            'segundos' => max(0, $fim - $start),
            'activo' => $activo && $resolucaoTs === null,
            'tipo' => (string)($ev['acao'] ?? 'Assumido'),
        ];
    }

    $porPessoaMap = [];
    foreach ($assumicoes as $a) {
        $key = $a['id_utilizador'] > 0 ? ('id:' . $a['id_utilizador']) : ('n:' . $a['nome']);
        if (!isset($porPessoaMap[$key])) {
            $porPessoaMap[$key] = [
                'nome' => $a['nome'],
                'segundos' => 0,
                'periodos' => 0,
                'activo' => false,
            ];
        }
        $porPessoaMap[$key]['segundos'] += (int)$a['segundos'];
        $porPessoaMap[$key]['periodos']++;
        if (!empty($a['activo'])) {
            $porPessoaMap[$key]['activo'] = true;
        }
    }
    $porPessoa = [];
    foreach ($porPessoaMap as $p) {
        $p['duracao'] = formatarDuracaoSegundos((int)$p['segundos']);
        $porPessoa[] = $p;
    }

    $primeiroAssumir = $assumeEvents[0] ?? null;
    $tempoAteAssumir = null;
    if ($primeiroAssumir && !empty($primeiroAssumir['data_registo'])) {
        $tempoAteAssumir = formatarDuracaoSegundos(max(0, (int)strtotime((string)$primeiroAssumir['data_registo']) - $criacaoTs));
    }

    return [
        'tempo_aberto' => formatarDuracaoSegundos(max(0, $fimAberto - $criacaoTs)),
        'aberto_em_curso' => $resolucaoTs === null,
        'tempo_ate_primeiro_assumir' => $tempoAteAssumir,
        'tempo_ate_resolucao' => $resolucaoTs !== null
            ? formatarDuracaoSegundos(max(0, $resolucaoTs - $criacaoTs))
            : null,
        'marcos' => $marcos,
        'assumicoes' => $assumicoes,
        'por_pessoa' => $porPessoa,
    ];
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
    garantirSchemaPerfis($pdo);
    garantirAreasSupervisao($pdo);

    $userId = $_SESSION['user_id'] ?? null;
    $perfil = $_SESSION['perfil'] ?? '';
    $idArea = $_SESSION['id_area'] ?? null;
    $idOperacao = $_SESSION['id_operacao'] ?? null;
    $idsAreas = [];

    // Revalidar área/operação/perfil na BD para utilizadores com conta registada
    if (is_numeric($userId)) {
        try {
            $stmt = $pdo->prepare("SELECT id_area, id_operacao, perfil, id_perfil FROM utilizadores WHERE id = ?");
            $stmt->execute([(int)$userId]);
            $dados = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $stmt = $pdo->prepare("SELECT id_area, id_operacao, perfil FROM utilizadores WHERE id = ?");
            $stmt->execute([(int)$userId]);
            $dados = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        $idArea = $dados['id_area'] ?? $idArea;
        $idOperacao = $dados['id_operacao'] ?? $idOperacao;
        if (!empty($dados['perfil'])) {
            $perfil = $dados['perfil'];
            $_SESSION['perfil'] = $perfil;
        }
        $idsAreas = obterIdsAreasUtilizador($pdo, (int)$userId);
        if (!empty($idsAreas)) {
            $idArea = $idsAreas[0];
        }
    } elseif (!empty($idArea)) {
        $idsAreas = [(int)$idArea];
    }

    $permsCtx = carregarPermissoesContexto($pdo, is_numeric($userId) ? (int)$userId : null, (string)$perfil);

    return [
        'user_id' => $userId,
        'user_id_numerico' => idUtilizadorNumerico(),
        'perfil' => $perfil,
        'id_area' => $idArea,
        'ids_areas' => $idsAreas,
        'id_operacao' => $idOperacao,
        'nome' => $_SESSION['nome'] ?? '',
        'id_perfil' => $permsCtx['id_perfil'],
        'codigo_perfil' => $permsCtx['codigo_perfil'],
        'is_perfil_sistema' => $permsCtx['is_perfil_sistema'],
        'permissoes' => $permsCtx['permissoes'],
        'ids_grupos' => $permsCtx['ids_grupos'],
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
    return count($ids) === 1 && (int)$ids[0] === $idF;
}

/**
 * IDs de área a usar na visibilidade de tickets/gráficos.
 * Quem NÃO é apenas Formadores não mistura tickets da área Formadores
 * com o trabalho técnico (Redes/Dev), mesmo que Formadores esteja nas suas áreas.
 *
 * @return int[]
 */
function idsAreasParaFiltroTickets(array $contexto): array
{
    $ids = array_values(array_unique(array_map('intval', obterIdsAreasContexto($contexto))));
    $idF = (int)($contexto['id_area_formadores'] ?? 0);
    if ($idF <= 0 || soPertenceAreaFormadores($contexto)) {
        return $ids;
    }
    if (in_array($contexto['perfil'] ?? '', ['Admin', 'Diretor Geral'], true)) {
        return $ids;
    }
    return array_values(array_filter($ids, static fn(int $id): bool => $id !== $idF));
}

/**
 * True se o utilizador (por ID) só pertence à área Formadores.
 */
function utilizadorSoAreaFormadores(PDO $pdo, int $userId, ?int $idFormadores = null): bool
{
    if ($userId <= 0) {
        return false;
    }
    $idF = $idFormadores ?? obterIdAreaFormadores($pdo);
    if (!$idF) {
        return false;
    }
    $ids = obterIdsAreasUtilizador($pdo, $userId);
    return count($ids) === 1 && (int)$ids[0] === (int)$idF;
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
 * tickets abertos por clientes da Africell). Operador sem operação: apenas os próprios tickets.
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
    // Formadores fica isolado: quem não é só-formador não vê a fila Formadores.
    if (in_array($perfil, ['Responsavel', 'Tecnico'], true)) {
        $cond = [];
        $cond[] = "t.id_tecnico_atribuido = ?";
        $params[] = $userId;
        $cond[] = "t.id_criador = ?";
        $params[] = $userId;
        $idsAreasFiltro = idsAreasParaFiltroTickets($contexto);
        if (!empty($idsAreasFiltro)) {
            $ph = implode(',', array_fill(0, count($idsAreasFiltro), '?'));
            $cond[] = "t.id_area_destino IN ($ph)";
            foreach ($idsAreasFiltro as $ia) {
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

        // Bloqueia destino Formadores (excepto tickets próprios / atribuídos a si)
        $idFormadoresExc = (int)($contexto['id_area_formadores'] ?? 0);
        if ($idFormadoresExc > 0 && !soPertenceAreaFormadores($contexto)) {
            $where[] = "(t.id_area_destino <> ? OR t.id_criador = ? OR t.id_tecnico_atribuido = ?)";
            $params[] = $idFormadoresExc;
            $params[] = $userId;
            $params[] = $userId;
        }

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

    // Supervisão: tickets da sua operação + tickets destinários à sua área de supervisão
    if ($perfil === 'Supervisao') {
        $cond = [];
        $cond[] = "t.id_criador = ?";
        $params[] = $userId;
        $cond[] = "t.id_tecnico_atribuido = ?";
        $params[] = $userId;
        if ($idOperacao) {
            $cond[] = "t.id_operacao_origem = ?";
            $params[] = $idOperacao;
        }
        if (!empty($idsAreas)) {
            $ph = implode(',', array_fill(0, count($idsAreas), '?'));
            $cond[] = "t.id_area_destino IN ($ph)";
            foreach ($idsAreas as $ia) {
                $params[] = $ia;
            }
        }
        [$sqlReenc, $paramReenc] = clausulaSqlTicketReencaminhadoPorUtilizador((int)$userId);
        $cond[] = $sqlReenc;
        $params[] = $paramReenc;
        $where[] = "(" . implode(" OR ", $cond) . ")";
        return [$where, $params];
    }

    // Utilizador sem perfil técnico: vê APENAS os tickets que ele próprio abriu.
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
        // Isolamento Formadores: quem não é só-formador não abre tickets da fila Formadores
        $idFormadores = (int)($contexto['id_area_formadores'] ?? 0);
        $destinoFormadores = $idFormadores > 0 && (int)($ticket['id_area_destino'] ?? 0) === $idFormadores;
        if ($destinoFormadores && !soPertenceAreaFormadores($contexto)) {
            $ehProprio = $userId && (
                (int)($ticket['id_criador'] ?? 0) === $userId
                || (int)($ticket['id_tecnico_atribuido'] ?? 0) === $userId
            );
            if (!$ehProprio && !($pdo && $userId && utilizadorReencaminhouTicket($pdo, $userId, (int)$ticket['id']))) {
                return false;
            }
        }

        $idsAreasFiltro = idsAreasParaFiltroTickets($contexto);
        if (!empty($idsAreasFiltro) && in_array((int)($ticket['id_area_destino'] ?? 0), $idsAreasFiltro, true)) {
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

    // Supervisão: operação + área de supervisão + próprios / atribuídos
    if ($perfil === 'Supervisao') {
        if (!empty($contexto['id_operacao']) && (int)($ticket['id_operacao_origem'] ?? 0) === (int)$contexto['id_operacao']) {
            return true;
        }
        if (utilizadorPertenceArea($contexto, (int)($ticket['id_area_destino'] ?? 0))) {
            return true;
        }
        if ($userId && (int)($ticket['id_tecnico_atribuido'] ?? 0) === $userId) {
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

    // Utilizador externo / fallback: só pode ver os tickets que abriu.
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
 * sistema.convidado), Operadores e restantes perfis SÓ podem abrir e acompanhar.
 */
function podeResolverTickets(array $contexto): bool
{
    // A conta partilhada de tickets sem login nunca resolve nada
    $idConvidado = $contexto['id_convidado'] ?? null;
    if ($idConvidado && (int)($contexto['user_id_numerico'] ?? 0) === (int)$idConvidado) {
        return false;
    }
    if (temPermissao($contexto, 'resolver_tickets')) {
        return true;
    }
    $perfil = $contexto['perfil'] ?? '';
    return in_array($perfil, ['Admin', 'Responsavel', 'Tecnico', 'Supervisao'], true);
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
    // Perfis sem permissão de resolução (Operador, Convidado, Diretor Geral)
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
    if (($contexto['perfil'] ?? '') === 'Admin' || ($contexto['codigo_perfil'] ?? '') === 'admin') {
        return true;
    }
    if (!temPermissao($contexto, 'alterar_projecto')
        && !in_array($contexto['perfil'] ?? '', ['Responsavel', 'Tecnico'], true)) {
        return false;
    }
    if (!pertenceAreaTecnica($contexto)) {
        return false;
    }
    return podeTratarTicket($ticket, $contexto);
}

/**
 * Pode editar prazos SLA / urgência do ticket:
 * Admin, Responsável, Técnico de Redes & Sistemas ou Desenvolvimento (que trate o ticket).
 */
function podeEditarSlaTicket(array $ticket, array $contexto): bool
{
    return podeEditarTicket($ticket, $contexto);
}

/**
 * Pode gerir o email de caixa postal de cada área (grupo de notificação).
 * Admin + técnicos/responsáveis de Redes & Sistemas e Desenvolvimento.
 */
function podeGerirEmailsAreas(array $contexto): bool
{
    if (temPermissao($contexto, 'gerir_emails_areas')) {
        if (!empty($contexto['is_perfil_sistema'])) {
            return podeAcederAdministracao($contexto);
        }
        return true;
    }
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
 * - Operador: Redes & Sistemas, Desenvolvimento e Supervisão da sua operação
 * - Supervisão: Redes & Sistemas e Desenvolvimento (reporta às áreas técnicas)
 * - Restantes (Formadores, RH, técnicos, etc.): todas as áreas / direcções
 *
 * @return array<int, array{id:int|string, nome:string}>
 */
function obterAreasDestinoAbertura(PDO $pdo, array $contexto): array
{
    try {
        $perfil = $contexto['perfil'] ?? '';
        $codigo = $contexto['codigo_perfil'] ?? '';

        if ($perfil === 'Operador' || $codigo === 'operador') {
            $ids = [1, 2];
            $idSup = !empty($contexto['id_operacao'])
                ? obterIdAreaSupervisaoOperacao($pdo, (int)$contexto['id_operacao'])
                : null;
            if ($idSup) {
                $ids[] = $idSup;
            }
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $order = implode(',', $ids);
            $stmt = $pdo->prepare("SELECT id, nome FROM areas WHERE id IN ($ph) ORDER BY FIELD(id, $order)");
            $stmt->execute($ids);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($perfil === 'Supervisao' || $codigo === 'supervisao') {
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
 * Operador / Supervisão: só a sua operação (trancada). Restantes: todas.
 *
 * @return array<int, array{id:int|string, nome:string}>
 */
function obterOperacoesAbertura(PDO $pdo, array $contexto): array
{
    try {
        $perfil = $contexto['perfil'] ?? '';
        if (perfilUsaOperacao($perfil) && !empty($contexto['id_operacao'])) {
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
function areaDestinoPermitidaAbertura(int $idArea, array $contexto, ?PDO $pdo = null): bool
{
    if ($idArea <= 0) {
        return false;
    }
    $perfil = $contexto['perfil'] ?? '';
    $codigo = $contexto['codigo_perfil'] ?? '';

    if ($perfil === 'Operador' || $codigo === 'operador') {
        if (in_array($idArea, [1, 2], true)) {
            return true;
        }
        if ($pdo && !empty($contexto['id_operacao'])) {
            $idSup = obterIdAreaSupervisaoOperacao($pdo, (int)$contexto['id_operacao']);
            return $idSup !== null && $idArea === $idSup;
        }
        return false;
    }

    if ($perfil === 'Supervisao' || $codigo === 'supervisao') {
        return in_array($idArea, [1, 2], true);
    }

    return true;
}

/**
 * Painel técnico completo, gestão de users e relatórios:
 * Admin OU (Responsável/Técnico nas áreas 1 ou 2)
 */
function podeAcederAdministracao(array $contexto): bool
{
    if (($contexto['perfil'] ?? '') === 'Admin' || ($contexto['codigo_perfil'] ?? '') === 'admin') {
        return true;
    }
    // Perfis custom: flag aceder_admin basta
    if (empty($contexto['is_perfil_sistema']) && temPermissao($contexto, 'aceder_admin')) {
        return true;
    }
    // Perfis de sistema: flag + área técnica (comportamento legado)
    if (temPermissao($contexto, 'aceder_admin') && pertenceAreaTecnica($contexto)) {
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

/** Gestão de utilizadores — flag gerir_utilizadores (+ área técnica nos perfis de sistema) */
function podeGerirUtilizadores(array $contexto): bool
{
    if (($contexto['perfil'] ?? '') === 'Admin' || ($contexto['codigo_perfil'] ?? '') === 'admin') {
        return true;
    }
    if (temPermissao($contexto, 'gerir_utilizadores')) {
        if (!empty($contexto['is_perfil_sistema'])) {
            return pertenceAreaTecnica($contexto);
        }
        return true;
    }
    return podeAcederAdministracao($contexto);
}

/**
 * Pode consultar a equipa disponível (menu lateral e painel no ticket).
 * Admin + Técnicos/Responsáveis de Redes & Sistemas (1) e Desenvolvimento (2).
 */
function podeVerEquipaDisponivel(array $contexto): bool
{
    if (($contexto['perfil'] ?? '') === 'Admin' || ($contexto['codigo_perfil'] ?? '') === 'admin') {
        return true;
    }
    if (temPermissao($contexto, 'ver_equipa')) {
        if (!empty($contexto['is_perfil_sistema'])) {
            return pertenceAreaTecnica($contexto);
        }
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

        // Admin: aparece na Equipa Disponível para se ver o tempo logado / presença
        if ($idArea === null) {
            try {
                $stAdm = $pdo->query("
                    SELECT u.id, u.nome, u.perfil, u.id_area, u.ultimo_acesso, u.sessao_ativa,
                           'Administração' AS area
                    FROM utilizadores u
                    WHERE u.estado = 'Ativo'
                      AND (u.perfil = 'Admin' OR u.id = 1)
                    ORDER BY u.nome ASC
                ");
                foreach ($stAdm->fetchAll(PDO::FETCH_ASSOC) ?: [] as $adm) {
                    $adm['id_area'] = 0; // balde Administração
                    $adm['area'] = 'Administração';
                    $expandida[] = $adm;
                }
            } catch (PDOException $e) {
                // ignore
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
 * Agrupa membros da equipa técnica por área (Redes & Sistemas, Desenvolvimento)
 * e inclui o balde Administração para o perfil Admin.
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

    // Secção própria para Administrador(es)
    $grupos[0] = ['nome' => 'Administração', 'membros' => []];

    foreach ($membros as $membro) {
        $idArea = (int)($membro['id_area'] ?? 0);
        $perfil = (string)($membro['perfil'] ?? '');
        if ($perfil === 'Admin' || $idArea === 0) {
            $grupos[0]['membros'][] = $membro;
            continue;
        }
        if (isset($grupos[$idArea])) {
            $grupos[$idArea]['membros'][] = $membro;
        }
    }

    // Não mostrar Administração vazia
    if (empty($grupos[0]['membros'])) {
        unset($grupos[0]);
    }

    return $grupos;
}

/**
 * Consulta do registo de auditoria do sistema completo.
 * Acesso: Admin + Responsáveis das áreas técnicas (Redes & Sistemas / Desenvolvimento).
 */
function podeAcederAuditoria(array $contexto): bool
{
    if (($contexto['perfil'] ?? '') === 'Admin' || ($contexto['codigo_perfil'] ?? '') === 'admin') {
        return true;
    }
    if (temPermissao($contexto, 'ver_auditoria')) {
        if (!empty($contexto['is_perfil_sistema'])) {
            return ($contexto['perfil'] ?? '') === 'Responsavel' && pertenceAreaTecnica($contexto);
        }
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
    $pdo->prepare("INSERT INTO utilizadores (nome, username, password_hash, perfil, estado) VALUES ('Sistema Convidado', 'sistema.convidado', ?, 'Operador', 'Inativo')")
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
 * Fragmento SQL + params para notificações visíveis ao contexto.
 * - Utilizador normal: dirigidas a si ou às suas áreas
 * - Admin: próprias + notificações de área (gestão). Não vê notificações
 *   pessoais de outras contas (ex.: «Conta aprovada» do pedido aceite).
 *
 * @return array{0:string,1:list<mixed>} [sqlWhereSemWhere, params]
 */
function clausulaSqlNotificacoesVisiveis(array $contexto): array
{
    $uid = (int)($contexto['user_id_numerico'] ?? 0);

    if (($contexto['perfil'] ?? '') === 'Admin' || ($contexto['codigo_perfil'] ?? '') === 'admin') {
        // Pessoais do Admin + alertas de área (id_utilizador NULL)
        return ['(id_utilizador = ? OR (id_utilizador IS NULL AND id_area IS NOT NULL))', [$uid]];
    }

    $idsAreas = obterIdsAreasContexto($contexto);
    $sql = '(id_utilizador = ?';
    $params = [$uid];
    if (!empty($idsAreas)) {
        $ph = implode(',', array_fill(0, count($idsAreas), '?'));
        $sql .= " OR id_area IN ($ph)";
        foreach ($idsAreas as $ia) {
            $params[] = $ia;
        }
    }
    $sql .= ')';
    return [$sql, $params];
}

/**
 * Conta notificações pendentes (não lidas) do utilizador actual —
 * dirigidas a si ou à sua área (Admin: próprias + de área, não as pessoais de outros).
 */
function contarNotificacoesPendentes(PDO $pdo, array $contexto): int
{
    try {
        [$where, $params] = clausulaSqlNotificacoesVisiveis($contexto);
        $sql = "SELECT COUNT(*) FROM notificacoes WHERE lida = 0 AND {$where}";
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
        [$where, $params] = clausulaSqlNotificacoesVisiveis($contexto);
        $whereLida = $soNaoLidas ? 'AND lida = 0' : '';
        $sql = "SELECT * FROM notificacoes WHERE {$where} {$whereLida} ORDER BY id DESC LIMIT ?";
        $params[] = $limite;
        $stmt = $pdo->prepare($sql);
        foreach ($params as $i => $v) {
            $stmt->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/** Marca notificações como lidas (do utilizador/área ou uma específica) */
function marcarNotificacoesLidas(PDO $pdo, array $contexto, ?int $idNotificacao = null): void
{
    try {
        [$where, $params] = clausulaSqlNotificacoesVisiveis($contexto);

        if ($idNotificacao) {
            $sql = "UPDATE notificacoes SET lida = 1 WHERE id = ? AND {$where}";
            $pdo->prepare($sql)->execute(array_merge([$idNotificacao], $params));
            return;
        }

        $sql = "UPDATE notificacoes SET lida = 1 WHERE lida = 0 AND {$where}";
        $pdo->prepare($sql)->execute($params);
    } catch (PDOException $e) {
        // Silencioso
    }
}

/**
 * Marca como lidas as notificações «Nova conta pendente» relativas a um username.
 * Usado ao aceitar/recusar para limpar o alerta da equipa técnica.
 */
function marcarNotificacoesContaPendenteLidas(PDO $pdo, string $username): void
{
    $username = trim($username);
    if ($username === '') {
        return;
    }
    try {
        $stmt = $pdo->prepare("
            UPDATE notificacoes
            SET lida = 1
            WHERE lida = 0
              AND tipo = 'Nova conta pendente'
              AND mensagem LIKE ?
        ");
        $stmt->execute(['%@' . $username . '%']);
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
 * Devolve (gerando e guardando se necessário) o token de acompanhamento/reabertura
 * público de um ticket. Usado para construir links seguros nos emails, permitindo
 * ao solicitante reabrir o ticket sem iniciar sessão.
 */
function obterTokenAcompanhamentoTicket(PDO $pdo, int $idTicket): ?string
{
    if ($idTicket <= 0) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT token_acompanhamento FROM tickets WHERE id = ?");
        $stmt->execute([$idTicket]);
        $token = $stmt->fetchColumn();
        if ($token) {
            return (string)$token;
        }
        $novo = bin2hex(random_bytes(24));
        $pdo->prepare("UPDATE tickets SET token_acompanhamento = ? WHERE id = ?")->execute([$novo, $idTicket]);
        return $novo;
    } catch (PDOException $e) {
        // Coluna ainda não migrada — segue sem token
        return null;
    }
}

/**
 * Constrói o link público de reabertura de um ticket (id + token).
 * Devolve string vazia se não houver token disponível.
 */
function construirLinkReaberturaTicket(PDO $pdo, int $idTicket): string
{
    $token = obterTokenAcompanhamentoTicket($pdo, $idTicket);
    if (!$token) {
        return '';
    }
    return obterUrlBaseSistema() . '/reabrir_ticket.php?id=' . $idTicket . '&token=' . urlencode($token);
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
        // Quando o ticket fica resolvido, oferece link público de reabertura
        $linkReabrir = '';
        if (($ticket['estado'] ?? '') === 'Resolvido') {
            $linkReabrir = construirLinkReaberturaTicket($pdo, (int)$ticket['id']);
        }
        enviarEmailTicketAtualizado($email, $nome, $codigo, $ticket['titulo'], $ticket['estado'], $tipoEvento, $detalhe, $linkReabrir);
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

/**
 * Opções de visibilidade dos artigos KB (quem pode ler).
 * Separado da categoria (tópico): controla o público-alvo.
 *
 * @return array<string,string> codigo => rótulo
 */
function obterOpcoesVisibilidadeKb(): array
{
    return [
        'todos' => 'Todos (toda a empresa)',
        'operacoes' => 'Operações',
        'redes_sistemas' => 'Redes & Sistemas (área técnica)',
        'desenvolvimento' => 'Desenvolvimento (área técnica)',
    ];
}

function normalizarVisibilidadeKb(?string $valor): string
{
    $valor = trim((string)$valor);
    $ops = obterOpcoesVisibilidadeKb();
    return isset($ops[$valor]) ? $valor : 'todos';
}

function rotuloVisibilidadeKb(?string $valor): string
{
    $ops = obterOpcoesVisibilidadeKb();
    $v = normalizarVisibilidadeKb($valor);
    return $ops[$v] ?? 'Todos';
}

/**
 * Garante a coluna visibilidade em kb_artigos.
 */
function garantirSchemaKbVisibilidade(PDO $pdo): void
{
    static $feito = false;
    if ($feito) {
        return;
    }
    $feito = true;
    try {
        $pdo->exec("ALTER TABLE kb_artigos ADD COLUMN IF NOT EXISTS visibilidade VARCHAR(40) NOT NULL DEFAULT 'todos' AFTER categoria");
    } catch (PDOException $e) {
        // ignore
    }
}

/**
 * Indica se o utilizador pode LER um artigo com determinada visibilidade.
 * Quem gere a KB (Admin / staff técnico com permissão) vê tudo.
 */
function podeVerVisibilidadeKb(string $visibilidade, array $contexto): bool
{
    $visibilidade = normalizarVisibilidadeKb($visibilidade);

    if (podeGerirKb($contexto)) {
        return true;
    }
    if (($contexto['perfil'] ?? '') === 'Admin' || ($contexto['codigo_perfil'] ?? '') === 'admin') {
        return true;
    }

    if ($visibilidade === 'todos') {
        return true;
    }

    if ($visibilidade === 'operacoes') {
        return ($contexto['perfil'] ?? '') === 'Operador'
            || !empty($contexto['id_operacao']);
    }

    if ($visibilidade === 'redes_sistemas') {
        return utilizadorPertenceArea($contexto, AREA_REDES_SISTEMAS);
    }

    if ($visibilidade === 'desenvolvimento') {
        return utilizadorPertenceArea($contexto, AREA_DESENVOLVIMENTO);
    }

    return false;
}

/**
 * Fragmento SQL + params para filtrar artigos KB pela visibilidade do contexto.
 * Quem gere a KB não recebe filtro (vê todos).
 *
 * @return array{0:string,1:list<mixed>} [sqlAnd, params]
 */
function clausulaSqlVisibilidadeKb(array $contexto, string $alias = 'k'): array
{
    if (podeGerirKb($contexto)
        || ($contexto['perfil'] ?? '') === 'Admin'
        || ($contexto['codigo_perfil'] ?? '') === 'admin') {
        return ['', []];
    }

    $col = $alias . '.visibilidade';
    $permitidas = ['todos'];

    if (($contexto['perfil'] ?? '') === 'Operador' || !empty($contexto['id_operacao'])) {
        $permitidas[] = 'operacoes';
    }
    if (utilizadorPertenceArea($contexto, AREA_REDES_SISTEMAS)) {
        $permitidas[] = 'redes_sistemas';
    }
    if (utilizadorPertenceArea($contexto, AREA_DESENVOLVIMENTO)) {
        $permitidas[] = 'desenvolvimento';
    }

    $permitidas = array_values(array_unique($permitidas));
    $placeholders = implode(',', array_fill(0, count($permitidas), '?'));
    // Artigos antigos sem coluna tratada como 'todos' via COALESCE
    return [" AND COALESCE({$col}, 'todos') IN ($placeholders)", $permitidas];
}
