<?php
/**
 * KIAMI — Perfis dinâmicos, grupos de permissão e checklist de flags.
 *
 * Modelo híbrido: 6 perfis de sistema (protegidos) + perfis custom + grupos.
 * As áreas (utilizador_areas) continuam separadas dos grupos de permissão.
 */

/** Flags estáveis usadas na UI e no código */
const PERMISSOES_CATALOGO = [
    'aceder_admin' => 'Ver secção Administração',
    'gerir_utilizadores' => 'Gerir utilizadores',
    'gerir_perfis' => 'Gerir perfis e grupos',
    'ver_equipa' => 'Ver equipa disponível',
    'gerir_assuntos' => 'Gerir assuntos de ticket',
    'gerir_emails_areas' => 'Gerir emails / áreas',
    'ver_relatorios' => 'Ver relatórios',
    'ver_auditoria' => 'Ver auditoria',
    'resolver_tickets' => 'Tratar / resolver tickets',
    'gerir_kb' => 'Gerir Base de Conhecimento',
    'alterar_projecto' => 'Alterações a nível do projecto',
];

/** Mapa perfil ENUM legado → codigo na tabela perfis */
const PERFIS_SISTEMA_CODIGO = [
    'Admin' => 'admin',
    'Diretor Geral' => 'diretor_geral',
    'Responsavel' => 'responsavel',
    'Tecnico' => 'tecnico',
    'Operador' => 'operador',
    'Supervisao' => 'supervisao',
];

/** Permissões seed por codigo de perfil de sistema */
function permissoesSeedPorCodigo(string $codigo): array
{
    $todas = array_keys(PERMISSOES_CATALOGO);
    switch ($codigo) {
        case 'admin':
            return $todas;
        case 'diretor_geral':
            return ['ver_relatorios'];
        case 'responsavel':
            return [
                'aceder_admin', 'gerir_utilizadores', 'gerir_perfis', 'ver_equipa',
                'gerir_assuntos', 'gerir_emails_areas', 'ver_relatorios', 'ver_auditoria',
                'resolver_tickets', 'gerir_kb', 'alterar_projecto',
            ];
        case 'tecnico':
            return [
                'aceder_admin', 'gerir_utilizadores', 'ver_equipa',
                'gerir_assuntos', 'gerir_emails_areas', 'ver_relatorios',
                'resolver_tickets', 'gerir_kb', 'alterar_projecto',
            ];
        case 'supervisao':
            // Trata situações da operação e pode reportar às áreas técnicas
            return ['resolver_tickets'];
        case 'operador':
        default:
            return [];
    }
}

/**
 * Garante tabelas, seed dos perfis de sistema e coluna id_perfil.
 * Seguro chamar em cada request (IF NOT EXISTS / INSERT IGNORE).
 */
function garantirSchemaPerfis(PDO $pdo): void
{
    static $feito = false;
    if ($feito) {
        return;
    }
    $feito = true;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS perfis (
            id INT AUTO_INCREMENT PRIMARY KEY,
            codigo VARCHAR(50) NOT NULL,
            nome VARCHAR(100) NOT NULL,
            descricao VARCHAR(255) NULL,
            is_sistema TINYINT(1) NOT NULL DEFAULT 0,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_perfis_codigo (codigo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS perfil_permissoes (
            id_perfil INT NOT NULL,
            permissao VARCHAR(50) NOT NULL,
            PRIMARY KEY (id_perfil, permissao),
            CONSTRAINT fk_pp_perfil FOREIGN KEY (id_perfil) REFERENCES perfis(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS grupos_permissao (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(100) NOT NULL,
            descricao VARCHAR(255) NULL,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_grupos_nome (nome)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS grupo_permissoes (
            id_grupo INT NOT NULL,
            permissao VARCHAR(50) NOT NULL,
            PRIMARY KEY (id_grupo, permissao),
            CONSTRAINT fk_gp_grupo FOREIGN KEY (id_grupo) REFERENCES grupos_permissao(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS utilizador_grupos (
            id_utilizador INT NOT NULL,
            id_grupo INT NOT NULL,
            PRIMARY KEY (id_utilizador, id_grupo),
            CONSTRAINT fk_ug_utilizador FOREIGN KEY (id_utilizador) REFERENCES utilizadores(id) ON DELETE CASCADE,
            CONSTRAINT fk_ug_grupo FOREIGN KEY (id_grupo) REFERENCES grupos_permissao(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS utilizador_permissoes (
            id_utilizador INT NOT NULL,
            permissao VARCHAR(50) NOT NULL,
            PRIMARY KEY (id_utilizador, permissao),
            CONSTRAINT fk_up_utilizador FOREIGN KEY (id_utilizador) REFERENCES utilizadores(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) {
        return;
    }

    // Seed perfis de sistema
    $seed = [
        ['admin', 'Administrador', 'Acesso total ao sistema', 1],
        ['diretor_geral', 'Diretor Geral', 'Consulta de relatórios e métricas', 1],
        ['responsavel', 'Responsável de Área', 'Gestão da área e tratamento de tickets', 1],
        ['tecnico', 'Técnico', 'Tratamento de tickets e acesso técnico', 1],
        ['operador', 'Operador', 'Abertura e acompanhamento de tickets', 1],
        ['supervisao', 'Supervisão', 'Supervisão da operação: trata pedidos da operação e reporta às áreas técnicas', 1],
    ];
    try {
        $ins = $pdo->prepare("INSERT IGNORE INTO perfis (codigo, nome, descricao, is_sistema, ativo) VALUES (?, ?, ?, ?, 1)");
        foreach ($seed as $row) {
            $ins->execute($row);
        }
        // Se o perfil supervisão já existia sem permissões, aplica o seed
        $stIdSup = $pdo->prepare("SELECT id FROM perfis WHERE codigo = 'supervisao'");
        $stIdSup->execute();
        $idSup = (int)$stIdSup->fetchColumn();
        if ($idSup > 0) {
            $stCountSup = $pdo->prepare("SELECT COUNT(*) FROM perfil_permissoes WHERE id_perfil = ?");
            $stCountSup->execute([$idSup]);
            if ((int)$stCountSup->fetchColumn() === 0) {
                $insPSup = $pdo->prepare("INSERT IGNORE INTO perfil_permissoes (id_perfil, permissao) VALUES (?, ?)");
                foreach (permissoesSeedPorCodigo('supervisao') as $flag) {
                    $insPSup->execute([$idSup, $flag]);
                }
            }
        }
        // Permissões seed (só se o perfil ainda não tiver nenhuma)
        $stId = $pdo->prepare("SELECT id FROM perfis WHERE codigo = ?");
        $stCount = $pdo->prepare("SELECT COUNT(*) FROM perfil_permissoes WHERE id_perfil = ?");
        $insP = $pdo->prepare("INSERT IGNORE INTO perfil_permissoes (id_perfil, permissao) VALUES (?, ?)");
        foreach (array_keys(PERFIS_SISTEMA_CODIGO) as $enumNome) {
            $codigo = PERFIS_SISTEMA_CODIGO[$enumNome];
            $stId->execute([$codigo]);
            $idPerfil = (int)$stId->fetchColumn();
            if ($idPerfil <= 0) {
                continue;
            }
            $stCount->execute([$idPerfil]);
            if ((int)$stCount->fetchColumn() > 0) {
                continue;
            }
            foreach (permissoesSeedPorCodigo($codigo) as $flag) {
                $insP->execute([$idPerfil, $flag]);
            }
        }
    } catch (PDOException $e) {
        // ignore
    }

    // Coluna id_perfil + perfil como VARCHAR (permite custom)
    try {
        $pdo->exec("ALTER TABLE utilizadores ADD COLUMN IF NOT EXISTS id_perfil INT NULL AFTER perfil");
    } catch (PDOException $e) {
        // ignore
    }
    try {
        // Alargar perfil para nomes custom (deixa de ser só ENUM)
        $pdo->exec("ALTER TABLE utilizadores MODIFY COLUMN perfil VARCHAR(100) NOT NULL DEFAULT 'Operador'");
    } catch (PDOException $e) {
        // ignore
    }

    // Migrar id_perfil a partir do texto perfil
    try {
        $pdo->exec("
            UPDATE utilizadores u
            INNER JOIN perfis p ON (
                (u.perfil = 'Admin' AND p.codigo = 'admin')
                OR (u.perfil = 'Diretor Geral' AND p.codigo = 'diretor_geral')
                OR (u.perfil = 'Responsavel' AND p.codigo = 'responsavel')
                OR (u.perfil = 'Tecnico' AND p.codigo = 'tecnico')
                OR (u.perfil = 'Operador' AND p.codigo = 'operador')
                OR (u.perfil = 'Supervisao' AND p.codigo = 'supervisao')
                OR (u.perfil = 'Supervisão' AND p.codigo = 'supervisao')
                OR (u.perfil = p.nome)
                OR (u.perfil = p.codigo)
            )
            SET u.id_perfil = p.id
            WHERE u.id_perfil IS NULL
        ");
    } catch (PDOException $e) {
        // ignore
    }
}

/** @return list<string> */
function obterPermissoesPerfil(PDO $pdo, int $idPerfil): array
{
    if ($idPerfil <= 0) {
        return [];
    }
    try {
        $st = $pdo->prepare("SELECT permissao FROM perfil_permissoes WHERE id_perfil = ?");
        $st->execute([$idPerfil]);
        return array_values(array_unique(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: [])));
    } catch (PDOException $e) {
        return [];
    }
}

/** @return list<string> */
function obterPermissoesGruposUtilizador(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    try {
        $st = $pdo->prepare("
            SELECT gp.permissao
            FROM utilizador_grupos ug
            INNER JOIN grupo_permissoes gp ON gp.id_grupo = ug.id_grupo
            INNER JOIN grupos_permissao g ON g.id = ug.id_grupo AND g.ativo = 1
            WHERE ug.id_utilizador = ?
        ");
        $st->execute([$userId]);
        return array_values(array_unique(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: [])));
    } catch (PDOException $e) {
        return [];
    }
}

/** @return list<string> Permissões adicionais atribuídas directamente à pessoa */
function obterPermissoesDiretasUtilizador(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    try {
        $st = $pdo->prepare("SELECT permissao FROM utilizador_permissoes WHERE id_utilizador = ?");
        $st->execute([$userId]);
        return array_values(array_unique(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: [])));
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Substitui as permissões adicionais de uma pessoa.
 *
 * @param list<string> $flags
 */
function sincronizarPermissoesDiretasUtilizador(PDO $pdo, int $userId, array $flags): void
{
    if ($userId <= 0) {
        return;
    }
    $flags = array_values(array_unique(array_filter(
        array_map('strval', $flags),
        static fn($f) => isset(PERMISSOES_CATALOGO[$f])
    )));
    $pdo->prepare("DELETE FROM utilizador_permissoes WHERE id_utilizador = ?")->execute([$userId]);
    if (empty($flags)) {
        return;
    }
    $ins = $pdo->prepare("INSERT INTO utilizador_permissoes (id_utilizador, permissao) VALUES (?, ?)");
    foreach ($flags as $flag) {
        $ins->execute([$userId, $flag]);
    }
}

/**
 * @return list<int>
 */
function obterIdsGruposUtilizador(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    try {
        $st = $pdo->prepare("SELECT id_grupo FROM utilizador_grupos WHERE id_utilizador = ?");
        $st->execute([$userId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * @param int[] $idsGrupos
 */
function sincronizarGruposUtilizador(PDO $pdo, int $userId, array $idsGrupos): void
{
    if ($userId <= 0) {
        return;
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $idsGrupos), static fn($v) => $v > 0)));
    try {
        $pdo->prepare("DELETE FROM utilizador_grupos WHERE id_utilizador = ?")->execute([$userId]);
        if (empty($ids)) {
            return;
        }
        $ins = $pdo->prepare("INSERT INTO utilizador_grupos (id_utilizador, id_grupo) VALUES (?, ?)");
        foreach ($ids as $idG) {
            $ins->execute([$userId, $idG]);
        }
    } catch (PDOException $e) {
        // ignore
    }
}

/**
 * @param list<string> $flags
 */
function sincronizarPermissoesPerfil(PDO $pdo, int $idPerfil, array $flags): void
{
    $flags = array_values(array_unique(array_filter($flags, static fn($f) => isset(PERMISSOES_CATALOGO[$f]))));
    $pdo->prepare("DELETE FROM perfil_permissoes WHERE id_perfil = ?")->execute([$idPerfil]);
    $ins = $pdo->prepare("INSERT INTO perfil_permissoes (id_perfil, permissao) VALUES (?, ?)");
    foreach ($flags as $f) {
        $ins->execute([$idPerfil, $f]);
    }
}

/**
 * @param list<string> $flags
 */
function sincronizarPermissoesGrupo(PDO $pdo, int $idGrupo, array $flags): void
{
    $flags = array_values(array_unique(array_filter($flags, static fn($f) => isset(PERMISSOES_CATALOGO[$f]))));
    $pdo->prepare("DELETE FROM grupo_permissoes WHERE id_grupo = ?")->execute([$idGrupo]);
    $ins = $pdo->prepare("INSERT INTO grupo_permissoes (id_grupo, permissao) VALUES (?, ?)");
    foreach ($flags as $f) {
        $ins->execute([$idGrupo, $f]);
    }
}

/**
 * Carrega metadados do perfil do utilizador e união de permissões (perfil + grupos).
 *
 * @return array{id_perfil:?int, codigo_perfil:?string, is_perfil_sistema:bool, permissoes:list<string>, ids_grupos:list<int>}
 */
function carregarPermissoesContexto(PDO $pdo, ?int $userId, string $perfilTexto): array
{
    garantirSchemaPerfis($pdo);

    $out = [
        'id_perfil' => null,
        'codigo_perfil' => null,
        'is_perfil_sistema' => false,
        'permissoes' => [],
        'ids_grupos' => [],
    ];

    $idPerfil = null;
    if ($userId && $userId > 0) {
        try {
            $st = $pdo->prepare("SELECT id_perfil FROM utilizadores WHERE id = ?");
            $st->execute([$userId]);
            $idPerfil = $st->fetchColumn();
            $idPerfil = $idPerfil !== false && $idPerfil !== null ? (int)$idPerfil : null;
        } catch (PDOException $e) {
            $idPerfil = null;
        }
        $out['ids_grupos'] = obterIdsGruposUtilizador($pdo, $userId);
    }

    if (!$idPerfil && isset(PERFIS_SISTEMA_CODIGO[$perfilTexto])) {
        try {
            $st = $pdo->prepare("SELECT id FROM perfis WHERE codigo = ? LIMIT 1");
            $st->execute([PERFIS_SISTEMA_CODIGO[$perfilTexto]]);
            $idPerfil = (int)$st->fetchColumn() ?: null;
        } catch (PDOException $e) {
            $idPerfil = null;
        }
    }

    if ($idPerfil) {
        try {
            $st = $pdo->prepare("SELECT id, codigo, is_sistema FROM perfis WHERE id = ? AND ativo = 1");
            $st->execute([$idPerfil]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if ($p) {
                $out['id_perfil'] = (int)$p['id'];
                $out['codigo_perfil'] = (string)$p['codigo'];
                $out['is_perfil_sistema'] = (int)$p['is_sistema'] === 1;
                $perms = obterPermissoesPerfil($pdo, (int)$p['id']);
                if ($userId && $userId > 0) {
                    $perms = array_values(array_unique(array_merge(
                        $perms,
                        obterPermissoesGruposUtilizador($pdo, $userId),
                        obterPermissoesDiretasUtilizador($pdo, $userId)
                    )));
                }
                $out['permissoes'] = $perms;
            }
        } catch (PDOException $e) {
            // ignore
        }
    }

    // Fallback legado se ainda sem permissões carregadas
    if (empty($out['permissoes']) && isset(PERFIS_SISTEMA_CODIGO[$perfilTexto])) {
        $codigo = PERFIS_SISTEMA_CODIGO[$perfilTexto];
        $out['codigo_perfil'] = $codigo;
        $out['is_perfil_sistema'] = true;
        $out['permissoes'] = permissoesSeedPorCodigo($codigo);
    }

    return $out;
}

/**
 * Verifica se o contexto tem a flag (Admin = sempre true).
 * Para alterar_projecto em perfis de sistema, exige também área técnica.
 */
function temPermissao(array $contexto, string $flag): bool
{
    if (($contexto['perfil'] ?? '') === 'Admin' || ($contexto['codigo_perfil'] ?? '') === 'admin') {
        return true;
    }

    $perms = $contexto['permissoes'] ?? [];
    if (!is_array($perms) || !in_array($flag, $perms, true)) {
        return false;
    }

    if ($flag === 'alterar_projecto') {
        // Alinha com regra de negócio: projecto só com área técnica
        if (!empty($contexto['is_perfil_sistema'])) {
            return pertenceAreaTecnica($contexto);
        }
        return pertenceAreaTecnica($contexto);
    }

    return true;
}

/**
 * Perfis activos para dropdowns (id => nome, + codigo / is_sistema).
 *
 * @return list<array{id:int,codigo:string,nome:string,is_sistema:int}>
 */
function listarPerfisActivos(PDO $pdo): array
{
    garantirSchemaPerfis($pdo);
    try {
        return $pdo->query("SELECT id, codigo, nome, is_sistema FROM perfis WHERE ativo = 1 ORDER BY is_sistema DESC, nome ASC")
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * @return list<array{id:int,nome:string,descricao:?string}>
 */
function listarGruposPermissaoActivos(PDO $pdo): array
{
    garantirSchemaPerfis($pdo);
    try {
        return $pdo->query("SELECT id, nome, descricao FROM grupos_permissao WHERE ativo = 1 ORDER BY nome ASC")
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Cria grupos de permissão a partir das áreas existentes (ex.: RH, Formadores)
 * e associa os utilizadores activos dessa área. Não cria grupos para áreas
 * «Supervisão …» (são destinos de ticket, não grupos de permissão).
 * Idempotente: se o grupo já existir, só sincroniza membros em falta.
 */
function garantirGruposPorAreas(PDO $pdo): void
{
    static $feito = false;
    if ($feito) {
        return;
    }
    $feito = true;

    garantirSchemaPerfis($pdo);

    try {
        $areas = $pdo->query("SELECT id, nome FROM areas ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return;
    }

    $stGrupo = $pdo->prepare('SELECT id FROM grupos_permissao WHERE nome = ? LIMIT 1');
    $stInsGrupo = $pdo->prepare('INSERT INTO grupos_permissao (nome, descricao, ativo) VALUES (?, ?, 1)');
    $stUsers = $pdo->prepare("
        SELECT DISTINCT u.id
        FROM utilizadores u
        LEFT JOIN utilizador_areas ua ON ua.id_utilizador = u.id
        WHERE u.estado = 'Ativo'
          AND (u.id_area = ? OR ua.id_area = ?)
    ");
    $stJaMembro = $pdo->prepare('SELECT 1 FROM utilizador_grupos WHERE id_utilizador = ? AND id_grupo = ? LIMIT 1');
    $stAdd = $pdo->prepare('INSERT IGNORE INTO utilizador_grupos (id_utilizador, id_grupo) VALUES (?, ?)');

    foreach ($areas as $area) {
        $nomeArea = trim((string)($area['nome'] ?? ''));
        $idArea = (int)($area['id'] ?? 0);
        if ($idArea <= 0 || $nomeArea === '') {
            continue;
        }
        // Destinos de supervisão por operação não viram grupos de permissão
        if (stripos($nomeArea, 'Supervisão ') === 0 || stripos($nomeArea, 'Supervisao ') === 0) {
            continue;
        }

        try {
            $stGrupo->execute([$nomeArea]);
            $idGrupo = (int)($stGrupo->fetchColumn() ?: 0);
            if ($idGrupo <= 0) {
                $stInsGrupo->execute([
                    $nomeArea,
                    'Grupo criado automaticamente a partir da área «' . $nomeArea . '».',
                ]);
                $idGrupo = (int)$pdo->lastInsertId();
            }
            if ($idGrupo <= 0) {
                continue;
            }

            $stUsers->execute([$idArea, $idArea]);
            $idsUsers = array_map('intval', $stUsers->fetchAll(PDO::FETCH_COLUMN) ?: []);
            foreach ($idsUsers as $uid) {
                if ($uid <= 0) {
                    continue;
                }
                $stJaMembro->execute([$uid, $idGrupo]);
                if (!$stJaMembro->fetchColumn()) {
                    $stAdd->execute([$uid, $idGrupo]);
                }
            }
        } catch (PDOException $e) {
            // Continua com as restantes áreas
        }
    }

    garantirGrupoOperacao($pdo);
}

/**
 * Garante o grupo único «Operação» com todas as pessoas ligadas a uma operação
 * (Operador, Supervisão, etc.). Ex.: Jeiel Mulengo — AFRICELL, Miguel Canda — BAI.
 */
function garantirGrupoOperacao(PDO $pdo): void
{
    garantirSchemaPerfis($pdo);

    try {
        $stGrupo = $pdo->prepare('SELECT id FROM grupos_permissao WHERE nome = ? LIMIT 1');
        $stGrupo->execute(['Operação']);
        $idGrupo = (int)($stGrupo->fetchColumn() ?: 0);
        if ($idGrupo <= 0) {
            $pdo->prepare('INSERT INTO grupos_permissao (nome, descricao, ativo) VALUES (?, ?, 1)')
                ->execute([
                    'Operação',
                    'Pessoas das operações (Operadores, Supervisão, etc.). Cada membro mostra a operação a que pertence.',
                ]);
            $idGrupo = (int)$pdo->lastInsertId();
        }
        if ($idGrupo <= 0) {
            return;
        }

        // Inclui todos os activos com operação associada
        $stUsers = $pdo->query("
            SELECT id FROM utilizadores
            WHERE estado = 'Ativo' AND id_operacao IS NOT NULL AND id_operacao > 0
        ");
        $idsComOp = array_map('intval', $stUsers->fetchAll(PDO::FETCH_COLUMN) ?: []);

        $stAdd = $pdo->prepare('INSERT IGNORE INTO utilizador_grupos (id_utilizador, id_grupo) VALUES (?, ?)');
        foreach ($idsComOp as $uid) {
            if ($uid > 0) {
                $stAdd->execute([$uid, $idGrupo]);
            }
        }

        // Remove do grupo quem já não tem operação (mantém a lista coerente)
        if (!empty($idsComOp)) {
            $ph = implode(',', array_fill(0, count($idsComOp), '?'));
            $stRem = $pdo->prepare("
                DELETE FROM utilizador_grupos
                WHERE id_grupo = ?
                  AND id_utilizador NOT IN ($ph)
            ");
            $stRem->execute(array_merge([$idGrupo], $idsComOp));
        } else {
            $pdo->prepare('DELETE FROM utilizador_grupos WHERE id_grupo = ?')->execute([$idGrupo]);
        }
    } catch (PDOException $e) {
        // Silencioso
    }
}

/**
 * Resolve id_perfil + texto perfil (legado) a partir do POST.
 * @return array{0:?int,1:string} [id_perfil, perfilTexto]
 */
function resolverPerfilPost(PDO $pdo, $idPerfilPost, string $perfilTextoFallback = 'Operador'): array
{
    garantirSchemaPerfis($pdo);
    $id = (int)$idPerfilPost;
    if ($id > 0) {
        try {
            $st = $pdo->prepare("SELECT id, codigo, nome, is_sistema FROM perfis WHERE id = ? AND ativo = 1");
            $st->execute([$id]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if ($p) {
                // Perfis de sistema mantêm o ENUM legado no campo perfil
                if ((int)$p['is_sistema'] === 1) {
                    $mapa = array_flip(PERFIS_SISTEMA_CODIGO);
                    $texto = $mapa[$p['codigo']] ?? $p['nome'];
                } else {
                    $texto = $p['nome'];
                }
                return [(int)$p['id'], $texto];
            }
        } catch (PDOException $e) {
            // fallthrough
        }
    }
    $texto = mapearPerfilParaBd($perfilTextoFallback);
    $idResolvido = null;
    if (isset(PERFIS_SISTEMA_CODIGO[$texto])) {
        try {
            $st = $pdo->prepare("SELECT id FROM perfis WHERE codigo = ?");
            $st->execute([PERFIS_SISTEMA_CODIGO[$texto]]);
            $idResolvido = (int)$st->fetchColumn() ?: null;
        } catch (PDOException $e) {
            $idResolvido = null;
        }
    }
    return [$idResolvido, $texto];
}

/**
 * HTML do bloco Administração (links condicionados por permissões).
 * «Gestão de Utilizadores» aparece como subcategoria de «Gestão de Perfis».
 */
function htmlNavAdministracao(array $contexto, string $paginaActiva = ''): string
{
    $html = '';
    $verAdmin = podeAcederAdministracao($contexto) || temPermissao($contexto, 'aceder_admin');
    $verRelatorios = $verAdmin || temPermissao($contexto, 'ver_relatorios') || ($contexto['perfil'] ?? '') === 'Diretor Geral';

    if ($verAdmin) {
        $html .= '<div class="nav-section-title">Administração</div>';

        $podeUsers = podeGerirUtilizadores($contexto) || temPermissao($contexto, 'gerir_utilizadores');
        $podePerfis = podeGerirPerfis($contexto) || $podeUsers;

        if ($podePerfis || $podeUsers) {
            if ($podePerfis) {
                $html .= '<a href="perfis_lista.php" class="nav-item' . ($paginaActiva === 'perfis_lista.php' ? ' active' : '') . '">'
                    . '🪪 <span>Gestão de Perfis</span></a>';
            }
            if ($podeUsers) {
                $html .= '<a href="usuarios_lista.php" class="nav-item nav-sub' . ($paginaActiva === 'usuarios_lista.php' ? ' active' : '') . '">'
                    . '👥 <span>Utilizadores</span></a>';
            }
        }

        $links = [];
        if (podeVerEquipaDisponivel($contexto) || temPermissao($contexto, 'ver_equipa')) {
            $links[] = ['equipa_online.php', '🟢', 'Equipa Disponível'];
        }
        if (podeGerirAssuntosTicket($contexto) || temPermissao($contexto, 'gerir_assuntos')) {
            $links[] = ['assuntos_lista.php', '📋', 'Assuntos de Ticket'];
        }
        if (podeGerirEmailsAreas($contexto) || temPermissao($contexto, 'gerir_emails_areas')) {
            $links[] = ['emails_areas.php', '✉️', 'Emails das Áreas'];
        }
        if ($verRelatorios) {
            $links[] = ['relatorios.php', '📈', 'Relatórios'];
        }
        if (podeAcederAuditoria($contexto) || temPermissao($contexto, 'ver_auditoria')) {
            $links[] = ['auditoria.php', '🔍', 'Auditoria'];
        }
        foreach ($links as [$href, $icon, $label]) {
            $active = ($paginaActiva === $href) ? ' active' : '';
            $html .= '<a href="' . htmlspecialchars($href) . '" class="nav-item' . $active . '">'
                . $icon . ' <span>' . htmlspecialchars($label) . '</span></a>';
        }
    } elseif (($contexto['perfil'] ?? '') === 'Diretor Geral' || temPermissao($contexto, 'ver_relatorios')) {
        $html .= '<div class="nav-section-title">Consulta</div>';
        $active = ($paginaActiva === 'relatorios.php') ? ' active' : '';
        $html .= '<a href="relatorios.php" class="nav-item' . $active . '">📈 <span>Relatórios</span></a>';
    }

    return $html;
}

/**
 * Bloco HTML fixo: Gestão de Perfis + subitem Utilizadores (para sidebars duplicadas).
 */
function htmlNavPerfisComUtilizadores(string $paginaActiva = ''): string
{
    $html = '<a href="perfis_lista.php" class="nav-item' . ($paginaActiva === 'perfis_lista.php' ? ' active' : '') . '">'
        . '🪪 <span>Gestão de Perfis</span></a>';
    $html .= '<a href="usuarios_lista.php" class="nav-item nav-sub' . ($paginaActiva === 'usuarios_lista.php' ? ' active' : '') . '">'
        . '👥 <span>Utilizadores</span></a>';
    return $html;
}

function podeGerirKb(array $contexto): bool
{
    if (temPermissao($contexto, 'gerir_kb')) {
        if (!empty($contexto['is_perfil_sistema'])) {
            return ($contexto['perfil'] ?? '') === 'Admin' || pertenceAreaTecnica($contexto);
        }
        return true;
    }
    // Legado
    return ($contexto['perfil'] ?? '') === 'Admin'
        || (in_array($contexto['perfil'] ?? '', ['Responsavel', 'Tecnico'], true) && pertenceAreaTecnica($contexto));
}

function podeGerirPerfis(array $contexto): bool
{
    if (($contexto['perfil'] ?? '') === 'Admin' || ($contexto['codigo_perfil'] ?? '') === 'admin') {
        return true;
    }
    if (temPermissao($contexto, 'gerir_perfis')) {
        if (!empty($contexto['is_perfil_sistema'])) {
            // Perfis de sistema com a flag: área técnica (Redes / Desenvolvimento)
            return pertenceAreaTecnica($contexto);
        }
        return true;
    }
    // Técnicos e Responsáveis de Redes & Sistemas (mesmo sem a flag no seed do Técnico)
    return in_array($contexto['perfil'] ?? '', ['Tecnico', 'Responsavel'], true)
        && utilizadorPertenceArea($contexto, AREA_REDES_SISTEMAS);
}
