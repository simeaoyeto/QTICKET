<?php
/**
 * KIAMI — Gestão de Perfis e Grupos de permissão
 *
 * Modelo híbrido: perfis de sistema protegidos + perfis custom + grupos.
 */
require_once 'conexao.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$nome_usuario = $_SESSION['nome'];
$perfil_usuario = $_SESSION['perfil'];
$contexto = obterContextoUsuario($pdo);
garantirSchemaPerfis($pdo);

if (!podeGerirPerfis($contexto)) {
    http_response_code(403);
    registarAuditoria($pdo, 'Acesso Negado', 'Tentativa de aceder à gestão de perfis');
    die('Acesso negado. A gestão de perfis está reservada ao Admin e a quem tenha a permissão «Gerir perfis e grupos».');
}

$mensagem = '';
$tab = ($_GET['tab'] ?? 'perfis') === 'grupos' ? 'grupos' : 'perfis';
$perfilEditar = null;
$grupoEditar = null;
$perfilMembros = null;
$grupoMembros = null;
$utilizadorPermissoesEditar = null;
$permissoesDiretasEditar = [];
$tokenCsrf = gerarTokenCsrf();

function slugCodigoPerfil(string $nome): string
{
    $s = mb_strtolower(trim($nome), 'UTF-8');
    $s = preg_replace('/[^a-z0-9]+/u', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s);
    $s = trim($s ?? '', '_');
    return $s !== '' ? mb_substr($s, 0, 50) : 'perfil_' . bin2hex(random_bytes(3));
}

function flagsPost(array $post): array
{
    $raw = $post['permissoes'] ?? [];
    if (!is_array($raw)) {
        return [];
    }
    return array_values(array_filter(array_map('strval', $raw), static fn($f) => isset(PERMISSOES_CATALOGO[$f])));
}

// ---------- Remover perfil ----------
if (isset($_GET['acao'], $_GET['id']) && $_GET['acao'] === 'remover_perfil') {
    $idRem = (int)$_GET['id'];
    if (!validarTokenCsrf($_GET['csrf'] ?? null)) {
        $mensagem = "<div class='msg-err'>Pedido inválido ou expirado.</div>";
    } else {
        $st = $pdo->prepare('SELECT * FROM perfis WHERE id = ?');
        $st->execute([$idRem]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) {
            $mensagem = "<div class='msg-err'>Perfil não encontrado.</div>";
        } elseif ((int)$p['is_sistema'] === 1) {
            $mensagem = "<div class='msg-err'>Não é possível remover perfis de sistema.</div>";
        } else {
            $stN = $pdo->prepare('SELECT COUNT(*) FROM utilizadores WHERE id_perfil = ?');
            $stN->execute([$idRem]);
            $nUsers = (int)$stN->fetchColumn();
            if ($nUsers > 0) {
                $mensagem = "<div class='msg-err'>Não é possível remover: existem $nUsers utilizador(es) com este perfil.</div>";
            } else {
                $pdo->prepare('DELETE FROM perfis WHERE id = ?')->execute([$idRem]);
                registarAuditoria($pdo, 'Exclusão', "Perfil removido: {$p['nome']} (#$idRem)");
                $mensagem = "<div class='msg-ok'>Perfil «" . htmlspecialchars($p['nome']) . "» removido.</div>";
            }
        }
    }
    $tab = 'perfis';
}

// ---------- Remover grupo ----------
if (isset($_GET['acao'], $_GET['id']) && $_GET['acao'] === 'remover_grupo') {
    $idRem = (int)$_GET['id'];
    if (!validarTokenCsrf($_GET['csrf'] ?? null)) {
        $mensagem = "<div class='msg-err'>Pedido inválido ou expirado.</div>";
    } else {
        $st = $pdo->prepare('SELECT * FROM grupos_permissao WHERE id = ?');
        $st->execute([$idRem]);
        $g = $st->fetch(PDO::FETCH_ASSOC);
        if (!$g) {
            $mensagem = "<div class='msg-err'>Grupo não encontrado.</div>";
        } else {
            $pdo->prepare('DELETE FROM utilizador_grupos WHERE id_grupo = ?')->execute([$idRem]);
            $pdo->prepare('DELETE FROM grupos_permissao WHERE id = ?')->execute([$idRem]);
            registarAuditoria($pdo, 'Exclusão', "Grupo de permissão removido: {$g['nome']} (#$idRem)");
            $mensagem = "<div class='msg-ok'>Grupo «" . htmlspecialchars($g['nome']) . "» removido.</div>";
        }
    }
    $tab = 'grupos';
}

// ---------- Editar (GET) ----------
if (isset($_GET['acao'], $_GET['id']) && $_GET['acao'] === 'editar_perfil') {
    $st = $pdo->prepare('SELECT * FROM perfis WHERE id = ?');
    $st->execute([(int)$_GET['id']]);
    $perfilEditar = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $tab = 'perfis';
}
if (isset($_GET['acao'], $_GET['id']) && $_GET['acao'] === 'editar_grupo') {
    $st = $pdo->prepare('SELECT * FROM grupos_permissao WHERE id = ?');
    $st->execute([(int)$_GET['id']]);
    $grupoEditar = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $tab = 'grupos';
}

// ---------- Ver membros de perfil / grupo e gerir permissões individuais ----------
if (isset($_GET['acao'], $_GET['id']) && $_GET['acao'] === 'ver_membros_perfil') {
    $idPerfilMembros = (int)$_GET['id'];
    $st = $pdo->prepare('SELECT * FROM perfis WHERE id = ?');
    $st->execute([$idPerfilMembros]);
    $perfilMembros = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $tab = 'perfis';
}

if (isset($_GET['acao'], $_GET['id']) && $_GET['acao'] === 'ver_membros_grupo') {
    $idGrupoMembros = (int)$_GET['id'];
    $st = $pdo->prepare('SELECT * FROM grupos_permissao WHERE id = ?');
    $st->execute([$idGrupoMembros]);
    $grupoMembros = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $tab = 'grupos';
}

if (isset($_GET['acao'], $_GET['uid']) && $_GET['acao'] === 'permissoes_utilizador') {
    $uidPerm = (int)$_GET['uid'];
    $st = $pdo->prepare("
        SELECT u.*, p.nome AS nome_perfil
        FROM utilizadores u
        LEFT JOIN perfis p ON p.id = u.id_perfil
        WHERE u.id = ?
    ");
    $st->execute([$uidPerm]);
    $utilizadorPermissoesEditar = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($utilizadorPermissoesEditar) {
        $permissoesDiretasEditar = obterPermissoesDiretasUtilizador($pdo, $uidPerm);
    }
    $tab = ($_GET['origem'] ?? 'perfis') === 'grupos' ? 'grupos' : 'perfis';
}

// ---------- Guardar perfil ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_salvar_perfil'])) {
    $idEdit = (int)($_POST['id_perfil'] ?? 0);
    $nome = trim($_POST['nome'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $flags = flagsPost($_POST);
    $tab = 'perfis';

    if ($nome === '') {
        $mensagem = "<div class='msg-err'>Indique o nome do perfil.</div>";
    } elseif ($idEdit > 0) {
        $st = $pdo->prepare('SELECT * FROM perfis WHERE id = ?');
        $st->execute([$idEdit]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) {
            $mensagem = "<div class='msg-err'>Perfil não encontrado.</div>";
        } else {
            // Admin de sistema: garantir flags críticas
            if ((int)$p['is_sistema'] === 1 && $p['codigo'] === 'admin') {
                $flags = array_values(array_unique(array_merge($flags, array_keys(PERMISSOES_CATALOGO))));
            }
            $pdo->prepare('UPDATE perfis SET nome = ?, descricao = ? WHERE id = ?')
                ->execute([$nome, $descricao !== '' ? $descricao : null, $idEdit]);
            sincronizarPermissoesPerfil($pdo, $idEdit, $flags);
            registarAuditoria($pdo, 'Alteração', "Perfil #$idEdit actualizado: $nome");
            $mensagem = "<div class='msg-ok'>Perfil actualizado com sucesso.</div>";
            $perfilEditar = null;
        }
    } else {
        $codigo = slugCodigoPerfil($nome);
        $stDup = $pdo->prepare('SELECT id FROM perfis WHERE codigo = ? OR nome = ? LIMIT 1');
        $stDup->execute([$codigo, $nome]);
        if ($stDup->fetchColumn()) {
            $codigo .= '_' . substr(bin2hex(random_bytes(2)), 0, 4);
        }
        $pdo->prepare('INSERT INTO perfis (codigo, nome, descricao, is_sistema, ativo) VALUES (?, ?, ?, 0, 1)')
            ->execute([$codigo, $nome, $descricao !== '' ? $descricao : null]);
        $novoId = (int)$pdo->lastInsertId();
        sincronizarPermissoesPerfil($pdo, $novoId, $flags);
        registarAuditoria($pdo, 'Criação', "Perfil criado: $nome (#$novoId)");
        $mensagem = "<div class='msg-ok'>Perfil <b>" . htmlspecialchars($nome) . "</b> criado.</div>";
    }
}

// ---------- Guardar grupo ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_salvar_grupo'])) {
    $idEdit = (int)($_POST['id_grupo'] ?? 0);
    $nome = trim($_POST['nome'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $flags = flagsPost($_POST);
    $membros = array_map('intval', $_POST['membros'] ?? []);
    $tab = 'grupos';

    if ($nome === '') {
        $mensagem = "<div class='msg-err'>Indique o nome do grupo.</div>";
    } elseif ($idEdit > 0) {
        $stDup = $pdo->prepare('SELECT id FROM grupos_permissao WHERE nome = ? AND id <> ?');
        $stDup->execute([$nome, $idEdit]);
        if ($stDup->fetchColumn()) {
            $mensagem = "<div class='msg-err'>Já existe um grupo com esse nome.</div>";
        } else {
            $pdo->prepare('UPDATE grupos_permissao SET nome = ?, descricao = ? WHERE id = ?')
                ->execute([$nome, $descricao !== '' ? $descricao : null, $idEdit]);
            sincronizarPermissoesGrupo($pdo, $idEdit, $flags);
            // Sync membros: remove todos do grupo e reinsere
            $pdo->prepare('DELETE FROM utilizador_grupos WHERE id_grupo = ?')->execute([$idEdit]);
            $ins = $pdo->prepare('INSERT INTO utilizador_grupos (id_utilizador, id_grupo) VALUES (?, ?)');
            foreach (array_unique($membros) as $uid) {
                if ($uid > 0) {
                    $ins->execute([$uid, $idEdit]);
                }
            }
            registarAuditoria($pdo, 'Alteração', "Grupo #$idEdit actualizado: $nome");
            $mensagem = "<div class='msg-ok'>Grupo actualizado com sucesso.</div>";
            $grupoEditar = null;
        }
    } else {
        $stDup = $pdo->prepare('SELECT id FROM grupos_permissao WHERE nome = ?');
        $stDup->execute([$nome]);
        if ($stDup->fetchColumn()) {
            $mensagem = "<div class='msg-err'>Já existe um grupo com esse nome.</div>";
        } else {
            $pdo->prepare('INSERT INTO grupos_permissao (nome, descricao, ativo) VALUES (?, ?, 1)')
                ->execute([$nome, $descricao !== '' ? $descricao : null]);
            $novoId = (int)$pdo->lastInsertId();
            sincronizarPermissoesGrupo($pdo, $novoId, $flags);
            $ins = $pdo->prepare('INSERT INTO utilizador_grupos (id_utilizador, id_grupo) VALUES (?, ?)');
            foreach (array_unique($membros) as $uid) {
                if ($uid > 0) {
                    $ins->execute([$uid, $novoId]);
                }
            }
            registarAuditoria($pdo, 'Criação', "Grupo criado: $nome (#$novoId)");
            $mensagem = "<div class='msg-ok'>Grupo <b>" . htmlspecialchars($nome) . "</b> criado.</div>";
        }
    }
}

// ---------- Guardar permissões adicionais de uma pessoa ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_salvar_permissoes_utilizador'])) {
    $uidPerm = (int)($_POST['id_utilizador'] ?? 0);
    $origemPerm = ($_POST['origem'] ?? 'perfis') === 'grupos' ? 'grupos' : 'perfis';
    $flags = flagsPost($_POST);
    $tab = $origemPerm;

    if (!validarTokenCsrf($_POST['csrf'] ?? null)) {
        $mensagem = "<div class='msg-err'>Pedido inválido ou expirado.</div>";
    } else {
        $st = $pdo->prepare('SELECT id, nome, username FROM utilizadores WHERE id = ?');
        $st->execute([$uidPerm]);
        $uPerm = $st->fetch(PDO::FETCH_ASSOC);
        if (!$uPerm) {
            $mensagem = "<div class='msg-err'>Utilizador não encontrado.</div>";
        } elseif ((int)$uPerm['id'] === 1) {
            $mensagem = "<div class='msg-err'>A conta Administrador Geral já tem acesso total e está protegida.</div>";
        } else {
            sincronizarPermissoesDiretasUtilizador($pdo, $uidPerm, $flags);
            registarAuditoria(
                $pdo,
                'Alteração',
                "Permissões individuais de {$uPerm['username']} actualizadas: " . implode(', ', $flags)
            );
            $mensagem = "<div class='msg-ok'>Permissões adicionais de <b>"
                . htmlspecialchars($uPerm['nome']) . "</b> actualizadas.</div>";
        }
    }
}

// ---------- Adicionar / excluir pessoa num grupo existente ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && (isset($_POST['btn_adicionar_membro_grupo']) || isset($_POST['btn_excluir_membro_grupo']))) {
    $idGrupoMembros = (int)($_POST['id_grupo'] ?? 0);
    $idUtilizadorMembro = (int)($_POST['id_utilizador'] ?? 0);
    $tab = 'grupos';

    if (!validarTokenCsrf($_POST['csrf'] ?? null)) {
        $mensagem = "<div class='msg-err'>Pedido inválido ou expirado. Recarregue a página.</div>";
    } else {
        $stGrupo = $pdo->prepare('SELECT * FROM grupos_permissao WHERE id = ?');
        $stGrupo->execute([$idGrupoMembros]);
        $grupoMembros = $stGrupo->fetch(PDO::FETCH_ASSOC) ?: null;

        $stUtilizador = $pdo->prepare('SELECT id, nome, username FROM utilizadores WHERE id = ?');
        $stUtilizador->execute([$idUtilizadorMembro]);
        $utilizadorMembro = $stUtilizador->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$grupoMembros) {
            $mensagem = "<div class='msg-err'>Grupo não encontrado.</div>";
        } elseif (!$utilizadorMembro) {
            $mensagem = "<div class='msg-err'>Utilizador não encontrado.</div>";
        } elseif (isset($_POST['btn_adicionar_membro_grupo'])) {
            $pdo->prepare('INSERT IGNORE INTO utilizador_grupos (id_utilizador, id_grupo) VALUES (?, ?)')
                ->execute([$idUtilizadorMembro, $idGrupoMembros]);
            registarAuditoria(
                $pdo,
                'Alteração',
                "Utilizador {$utilizadorMembro['username']} (#$idUtilizadorMembro) adicionado ao grupo {$grupoMembros['nome']} (#$idGrupoMembros)"
            );
            $mensagem = "<div class='msg-ok'><b>" . htmlspecialchars($utilizadorMembro['nome'])
                . "</b> foi adicionado ao grupo «" . htmlspecialchars($grupoMembros['nome']) . "».</div>";
        } else {
            $pdo->prepare('DELETE FROM utilizador_grupos WHERE id_utilizador = ? AND id_grupo = ?')
                ->execute([$idUtilizadorMembro, $idGrupoMembros]);
            registarAuditoria(
                $pdo,
                'Alteração',
                "Utilizador {$utilizadorMembro['username']} (#$idUtilizadorMembro) excluído do grupo {$grupoMembros['nome']} (#$idGrupoMembros)"
            );
            $mensagem = "<div class='msg-ok'><b>" . htmlspecialchars($utilizadorMembro['nome'])
                . "</b> foi excluído do grupo «" . htmlspecialchars($grupoMembros['nome']) . "».</div>";
        }
    }
}

$tokenCsrf = gerarTokenCsrf();

// Dados para UI
garantirGruposPorAreas($pdo);
$listaPerfis = $pdo->query("
    SELECT p.*,
           (SELECT COUNT(*) FROM utilizadores u
            WHERE u.id_perfil = p.id
               OR u.perfil COLLATE utf8mb4_unicode_ci = p.nome COLLATE utf8mb4_unicode_ci
               OR (p.codigo = 'admin' AND u.perfil COLLATE utf8mb4_unicode_ci = 'Admin')
               OR (p.codigo = 'diretor_geral' AND u.perfil COLLATE utf8mb4_unicode_ci = 'Diretor Geral')
               OR (p.codigo = 'responsavel' AND u.perfil COLLATE utf8mb4_unicode_ci = 'Responsavel')
               OR (p.codigo = 'tecnico' AND u.perfil COLLATE utf8mb4_unicode_ci = 'Tecnico')
               OR (p.codigo = 'operador' AND u.perfil COLLATE utf8mb4_unicode_ci IN ('Operador', 'Comum'))
               OR (p.codigo = 'supervisao' AND u.perfil COLLATE utf8mb4_unicode_ci IN ('Supervisao', 'Supervisão'))) AS n_users,
           (SELECT COUNT(*) FROM perfil_permissoes pp WHERE pp.id_perfil = p.id) AS n_perms
    FROM perfis p
    ORDER BY p.is_sistema DESC, p.nome ASC
")->fetchAll(PDO::FETCH_ASSOC);

$listaGrupos = $pdo->query("
    SELECT g.*,
           (SELECT COUNT(*) FROM utilizador_grupos ug WHERE ug.id_grupo = g.id) AS n_membros,
           (SELECT COUNT(*) FROM grupo_permissoes gp WHERE gp.id_grupo = g.id) AS n_perms
    FROM grupos_permissao g
    ORDER BY g.nome ASC
")->fetchAll(PDO::FETCH_ASSOC);

$utilizadoresActivos = $pdo->query("
    SELECT id, nome, username, perfil FROM utilizadores WHERE estado = 'Ativo' ORDER BY nome ASC
")->fetchAll(PDO::FETCH_ASSOC);

$membrosPerfilLista = [];
if ($perfilMembros) {
    $mapaTexto = array_flip(PERFIS_SISTEMA_CODIGO);
    $textoPerfil = $mapaTexto[$perfilMembros['codigo']] ?? $perfilMembros['nome'];
    $textosPerfil = [$textoPerfil, $perfilMembros['nome']];
    if ($perfilMembros['codigo'] === 'operador') {
        $textosPerfil[] = 'Comum'; // legado
    }
    $textosPerfil = array_values(array_unique($textosPerfil));
    $ph = implode(',', array_fill(0, count($textosPerfil), '?'));
    $st = $pdo->prepare("
        SELECT u.*,
               COALESCE(
                   NULLIF((
                       SELECT GROUP_CONCAT(DISTINCT a.nome ORDER BY a.nome SEPARATOR ', ')
                       FROM utilizador_areas ua
                       INNER JOIN areas a ON a.id = ua.id_area
                       WHERE ua.id_utilizador = u.id
                   ), ''),
                   (SELECT a2.nome FROM areas a2 WHERE a2.id = u.id_area)
               ) AS nomes_areas,
               (SELECT o.nome FROM operacoes o WHERE o.id = u.id_operacao) AS nome_operacao,
               (SELECT GROUP_CONCAT(g.nome ORDER BY g.nome SEPARATOR ', ')
                FROM utilizador_grupos ug
                INNER JOIN grupos_permissao g ON g.id = ug.id_grupo
                WHERE ug.id_utilizador = u.id) AS nomes_grupos,
               (SELECT COUNT(*) FROM utilizador_permissoes up WHERE up.id_utilizador = u.id) AS n_perms_diretas
        FROM utilizadores u
        WHERE u.id_perfil = ? OR u.perfil COLLATE utf8mb4_unicode_ci IN ($ph)
        ORDER BY u.nome ASC
    ");
    $st->execute(array_merge([(int)$perfilMembros['id']], $textosPerfil));
    $membrosPerfilLista = $st->fetchAll(PDO::FETCH_ASSOC);
}

$membrosGrupoLista = [];
$utilizadoresDisponiveisGrupo = [];
$grupoEOperacao = false;
if ($grupoMembros) {
    $grupoEOperacao = strcasecmp(trim((string)($grupoMembros['nome'] ?? '')), 'Operação') === 0
        || strcasecmp(trim((string)($grupoMembros['nome'] ?? '')), 'Operacao') === 0;

    $st = $pdo->prepare("
        SELECT u.*, COALESCE(p.nome, u.perfil) AS nome_perfil,
               o.nome AS nome_operacao,
               (SELECT COUNT(*) FROM utilizador_permissoes up WHERE up.id_utilizador = u.id) AS n_perms_diretas
        FROM utilizador_grupos ug
        INNER JOIN utilizadores u ON u.id = ug.id_utilizador
        LEFT JOIN perfis p ON p.id = u.id_perfil
        LEFT JOIN operacoes o ON o.id = u.id_operacao
        WHERE ug.id_grupo = ?
        ORDER BY o.nome ASC, u.nome ASC
    ");
    $st->execute([(int)$grupoMembros['id']]);
    $membrosGrupoLista = $st->fetchAll(PDO::FETCH_ASSOC);

    if ($grupoEOperacao) {
        // Só pessoas com operação, ainda fora do grupo
        $stDisponiveis = $pdo->prepare("
            SELECT u.id, u.nome, u.username, COALESCE(p.nome, u.perfil) AS nome_perfil, o.nome AS nome_operacao
            FROM utilizadores u
            LEFT JOIN perfis p ON p.id = u.id_perfil
            LEFT JOIN operacoes o ON o.id = u.id_operacao
            WHERE u.estado = 'Ativo'
              AND u.id_operacao IS NOT NULL AND u.id_operacao > 0
              AND NOT EXISTS (
                  SELECT 1 FROM utilizador_grupos ug
                  WHERE ug.id_utilizador = u.id AND ug.id_grupo = ?
              )
            ORDER BY o.nome ASC, u.nome ASC
        ");
    } else {
        $stDisponiveis = $pdo->prepare("
            SELECT u.id, u.nome, u.username, COALESCE(p.nome, u.perfil) AS nome_perfil,
                   (SELECT o.nome FROM operacoes o WHERE o.id = u.id_operacao) AS nome_operacao
            FROM utilizadores u
            LEFT JOIN perfis p ON p.id = u.id_perfil
            WHERE u.estado = 'Ativo'
              AND NOT EXISTS (
                  SELECT 1
                  FROM utilizador_grupos ug
                  WHERE ug.id_utilizador = u.id
                    AND ug.id_grupo = ?
              )
            ORDER BY u.nome ASC
        ");
    }
    $stDisponiveis->execute([(int)$grupoMembros['id']]);
    $utilizadoresDisponiveisGrupo = $stDisponiveis->fetchAll(PDO::FETCH_ASSOC);
}

$permissoesHerdadasEditar = [];
$gruposUtilizadorEditar = [];
if ($utilizadorPermissoesEditar) {
    $idPerfilU = (int)($utilizadorPermissoesEditar['id_perfil'] ?? 0);
    $permissoesPerfilU = obterPermissoesPerfil($pdo, $idPerfilU);
    $permissoesGrupoU = obterPermissoesGruposUtilizador($pdo, (int)$utilizadorPermissoesEditar['id']);
    $permissoesHerdadasEditar = array_values(array_unique(array_merge($permissoesPerfilU, $permissoesGrupoU)));
    try {
        $st = $pdo->prepare("
            SELECT g.nome
            FROM utilizador_grupos ug
            INNER JOIN grupos_permissao g ON g.id = ug.id_grupo
            WHERE ug.id_utilizador = ?
            ORDER BY g.nome
        ");
        $st->execute([(int)$utilizadorPermissoesEditar['id']]);
        $gruposUtilizadorEditar = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (PDOException $e) {
        $gruposUtilizadorEditar = [];
    }
}

$flagsPerfilEditar = [];
if ($perfilEditar) {
    $flagsPerfilEditar = obterPermissoesPerfil($pdo, (int)$perfilEditar['id']);
}
$flagsPerfilMembros = [];
if ($perfilMembros) {
    $flagsPerfilMembros = obterPermissoesPerfil($pdo, (int)$perfilMembros['id']);
}
$flagsGrupoEditar = [];
$membrosGrupoEditar = [];
if ($grupoEditar) {
    $stF = $pdo->prepare('SELECT permissao FROM grupo_permissoes WHERE id_grupo = ?');
    $stF->execute([(int)$grupoEditar['id']]);
    $flagsGrupoEditar = array_map('strval', $stF->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $stM = $pdo->prepare('SELECT id_utilizador FROM utilizador_grupos WHERE id_grupo = ?');
    $stM->execute([(int)$grupoEditar['id']]);
    $membrosGrupoEditar = array_map('intval', $stM->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

function htmlChecklistPermissoes(array $seleccionadas, string $prefix = '', array $opcoes = []): string
{
    $somenteLeitura = !empty($opcoes['readonly']);
    $herdadas = array_map('strval', $opcoes['herdadas'] ?? []);
    $mostrarResumo = !array_key_exists('resumo', $opcoes) || !empty($opcoes['resumo']);
    $nomeInput = $prefix !== '' ? $prefix . 'permissoes[]' : 'permissoes[]';

    $nSel = 0;
    foreach (array_keys(PERMISSOES_CATALOGO) as $flag) {
        if (in_array($flag, $seleccionadas, true) || in_array($flag, $herdadas, true)) {
            $nSel++;
        }
    }

    $html = '';
    if ($mostrarResumo) {
        $html .= '<div style="font-size:12px;color:var(--text-secondary);margin-bottom:8px;">'
            . '<b style="color:#fff;">' . $nSel . '</b> de ' . count(PERMISSOES_CATALOGO)
            . ' permissões activas'
            . ($somenteLeitura ? ' <span style="color:var(--text-muted);">(apenas visualização)</span>' : '')
            . '</div>';
    }

    $html .= '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:8px 14px;padding:12px;background:var(--bg-input);border:1px solid var(--border);border-radius:var(--radius-sm);">';
    foreach (PERMISSOES_CATALOGO as $flag => $label) {
        $activa = in_array($flag, $seleccionadas, true);
        $herdada = in_array($flag, $herdadas, true) && !$activa;
        $marcado = $activa || $herdada;
        $bloquear = $somenteLeitura || $herdada;

        $estiloItem = $marcado
            ? 'background:rgba(34,197,94,0.12);border:1px solid rgba(34,197,94,0.4);'
            : 'background:transparent;border:1px solid transparent;';

        $html .= '<label style="font-size:13px;color:var(--text-primary);cursor:'
            . ($bloquear ? 'default' : 'pointer')
            . ';display:flex;align-items:flex-start;gap:8px;padding:8px;border-radius:var(--radius-sm);'
            . $estiloItem . '">';

        if ($bloquear) {
            // Visual apenas — disabled não vai no POST
            $html .= '<input type="checkbox"' . ($marcado ? ' checked' : '') . ' disabled style="margin-top:3px;">';
            if ($activa && !$somenteLeitura) {
                // Permissão adicional directa + também herdada: manter no POST
                $html .= '<input type="hidden" name="' . htmlspecialchars($nomeInput) . '" value="' . htmlspecialchars($flag) . '">';
            }
        } else {
            $html .= '<input type="checkbox" name="' . htmlspecialchars($nomeInput) . '" value="'
                . htmlspecialchars($flag) . '"' . ($activa ? ' checked' : '') . ' style="margin-top:3px;">';
        }

        $html .= '<span><b style="font-weight:600;color:' . ($marcado ? 'var(--green)' : 'inherit') . ';">'
            . htmlspecialchars($label) . '</b>';
        if ($herdada) {
            $html .= ' <span style="font-size:10px;color:var(--accent);font-weight:600;">(já no perfil/grupo)</span>';
        }
        $html .= '<br><span style="font-size:11px;color:var(--text-muted);">' . htmlspecialchars($flag) . '</span></span></label>';
    }
    $html .= '</div>';
    return $html;
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KIAMI - Gestão de Perfis</title>
    <link rel="stylesheet" href="style.css">
    <script src="tema.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .msg-ok { background:rgba(34,197,94,0.15); color:var(--green); padding:12px; border-radius:var(--radius-sm); margin-bottom:16px; }
        .msg-err { background:rgba(239,68,68,0.15); color:var(--red); padding:12px; border-radius:var(--radius-sm); margin-bottom:16px; }
        .tabs { display:flex; gap:8px; margin-bottom:20px; }
        .tabs a { padding:10px 18px; border-radius:var(--radius-sm); text-decoration:none; font-size:14px; font-weight:600; border:1px solid var(--border); color:var(--text-secondary); background:var(--bg-input); }
        .tabs a.active { background:var(--accent); color:#fff; border-color:var(--accent); }
    </style>
</head>
<body>
<div class="app-layout">
    <div id="sidebar">
        <div class="sidebar-brand"><h3>KIAMI</h3><span>Suporte Quality</span></div>
        <div class="nav-section-title">Geral</div>
        <a href="index.php" class="nav-item">📊 <span>Painel</span></a>
        <a href="tickets_lista.php" class="nav-item">🎫 <span>Tickets</span></a>
        <a href="kb_lista.php" class="nav-item">📚 <span>Base Conhecimento</span></a>
        <a href="empresa.php" class="nav-item">🏢 <span>A Empresa</span></a>
        <?php echo htmlNavNotificacoes((int)($notif_pendentes ?? 0)); ?>
        <?php echo htmlNavAdministracao($contexto, 'perfis_lista.php'); ?>
        <div class="sidebar-footer">
            <div class="user-badge">
                <div style="font-size:14px;font-weight:600;"><?php echo htmlspecialchars($nome_usuario); ?></div>
                <div style="font-size:11px;color:var(--accent);font-weight:600;margin-top:4px;">⚙️ <?php echo htmlspecialchars($perfil_usuario); ?></div>
            </div>
            <?php if (idUtilizadorNumerico()): ?>
            <a href="alterar_senha.php" style="display:block;text-align:center;margin-bottom:8px;padding:9px;background:var(--bg-input);color:var(--text-primary);text-decoration:none;border-radius:var(--radius-sm);font-size:13px;border:1px solid var(--border);">🔑 Alterar Palavra-passe</a>
            <?php endif; ?>
            <a href="logout.php" class="btn-danger">🚪 Sair do Sistema</a>
        </div>
    </div>

    <div id="main-content">
        <div class="page-header">
            <h1>Gestão de Perfis</h1>
            <p>Crie perfis com checklist de permissões e grupos que somam acesso aos utilizadores. As áreas de responsabilidade continuam a gerir-se em Utilizadores.</p>
        </div>

        <?php echo $mensagem; ?>

        <div class="tabs">
            <a href="perfis_lista.php?tab=perfis" class="<?php echo $tab === 'perfis' ? 'active' : ''; ?>">Perfis</a>
            <a href="perfis_lista.php?tab=grupos" class="<?php echo $tab === 'grupos' ? 'active' : ''; ?>">Grupos de permissão</a>
        </div>

        <?php if ($utilizadorPermissoesEditar): ?>
        <div class="card" id="permissoes-utilizador" style="margin-bottom:24px;border-top:3px solid var(--accent);">
            <h3 style="margin-bottom:5px;font-size:16px;color:var(--accent);">
                Permissões adicionais — <?php echo htmlspecialchars($utilizadorPermissoesEditar['nome']); ?>
            </h3>
            <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px;">
                Perfil: <b><?php echo htmlspecialchars($utilizadorPermissoesEditar['nome_perfil'] ?: $utilizadorPermissoesEditar['perfil']); ?></b>
                <?php if ($gruposUtilizadorEditar): ?>
                    · Grupos: <b><?php echo htmlspecialchars(implode(', ', $gruposUtilizadorEditar)); ?></b>
                <?php endif; ?>
            </p>
            <div style="background:rgba(59,130,246,0.1);border:1px solid rgba(59,130,246,0.25);padding:10px 12px;border-radius:var(--radius-sm);font-size:12px;color:var(--text-secondary);margin-bottom:14px;">
                Estas permissões são exclusivas desta pessoa e somam-se às do perfil e dos grupos.
                Desmarcar uma permissão herdada do perfil/grupo não a remove; para isso, edite o perfil ou grupo correspondente.
                <?php if ($permissoesHerdadasEditar): ?>
                    <div style="margin-top:6px;color:var(--text-muted);">
                        Herdadas: <?php echo htmlspecialchars(implode(', ', $permissoesHerdadasEditar)); ?>
                    </div>
                <?php endif; ?>
            </div>
            <form method="POST" action="perfis_lista.php?tab=<?php echo htmlspecialchars($tab); ?>">
                <input type="hidden" name="id_utilizador" value="<?php echo (int)$utilizadorPermissoesEditar['id']; ?>">
                <input type="hidden" name="origem" value="<?php echo htmlspecialchars($tab); ?>">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($tokenCsrf); ?>">
                <?php echo htmlChecklistPermissoes($permissoesDiretasEditar, '', [
                    'herdadas' => $permissoesHerdadasEditar,
                ]); ?>
                <div style="display:flex;gap:10px;align-items:center;margin-top:14px;">
                    <button type="submit" name="btn_salvar_permissoes_utilizador" style="padding:10px 18px;background:var(--accent);border:none;color:#fff;border-radius:var(--radius-sm);cursor:pointer;font-weight:600;">
                        Guardar permissões da pessoa
                    </button>
                    <a href="perfis_lista.php?tab=<?php echo htmlspecialchars($tab); ?>" style="font-size:13px;color:var(--text-muted);">Cancelar</a>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($tab === 'perfis'): ?>
        <div class="card" style="margin-bottom:24px;" id="form-perfil">
            <h3 style="margin-bottom:14px;font-size:16px;color:var(--accent);">
                <?php echo $perfilEditar ? '✏️ Editar perfil' : '➕ Novo perfil'; ?>
            </h3>
            <form method="POST" action="perfis_lista.php?tab=perfis">
                <?php if ($perfilEditar): ?>
                    <input type="hidden" name="id_perfil" value="<?php echo (int)$perfilEditar['id']; ?>">
                <?php endif; ?>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                    <div>
                        <label style="display:block;margin-bottom:5px;font-size:12px;color:var(--text-secondary);">Nome</label>
                        <input type="text" name="nome" required value="<?php echo $perfilEditar ? htmlspecialchars($perfilEditar['nome']) : ''; ?>"
                               <?php echo ($perfilEditar && (int)$perfilEditar['is_sistema'] === 1) ? '' : ''; ?>
                               placeholder="Ex: Coordenador Helpdesk"
                               style="width:100%;padding:10px;background:var(--bg-input);border:1px solid var(--border);color:#fff;border-radius:var(--radius-sm);box-sizing:border-box;">
                        <?php if ($perfilEditar && (int)$perfilEditar['is_sistema'] === 1): ?>
                            <small style="color:var(--text-muted);font-size:11px;">Perfil de sistema (código: <?php echo htmlspecialchars($perfilEditar['codigo']); ?>) — não pode ser removido.</small>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:5px;font-size:12px;color:var(--text-secondary);">Descrição</label>
                        <input type="text" name="descricao" value="<?php echo $perfilEditar ? htmlspecialchars($perfilEditar['descricao'] ?? '') : ''; ?>"
                               placeholder="Opcional"
                               style="width:100%;padding:10px;background:var(--bg-input);border:1px solid var(--border);color:#fff;border-radius:var(--radius-sm);box-sizing:border-box;">
                    </div>
                </div>
                <label style="display:block;margin-bottom:8px;font-size:12px;color:var(--text-secondary);">
                    Permissões do perfil
                    <?php if ($perfilEditar): ?>
                        <span style="color:var(--green);font-weight:600;">— as activas aparecem marcadas a verde</span>
                    <?php endif; ?>
                </label>
                <?php echo htmlChecklistPermissoes($flagsPerfilEditar); ?>
                <div style="margin-top:16px;display:flex;gap:10px;align-items:center;">
                    <button type="submit" name="btn_salvar_perfil" style="padding:11px 20px;background:var(--accent);border:none;color:#fff;border-radius:var(--radius-sm);cursor:pointer;font-weight:600;">
                        <?php echo $perfilEditar ? 'Guardar' : 'Criar perfil'; ?>
                    </button>
                    <?php if ($perfilEditar): ?>
                        <a href="perfis_lista.php?tab=perfis" style="font-size:13px;color:var(--text-muted);">Cancelar</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="card" style="padding:0;overflow:hidden;">
            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                <thead>
                <tr style="background:var(--bg-sidebar);border-bottom:1px solid var(--border);">
                    <th style="padding:14px;text-align:left;">Nome</th>
                    <th style="padding:14px;text-align:left;">Tipo</th>
                    <th style="padding:14px;text-align:left;">Permissões</th>
                    <th style="padding:14px;text-align:left;">Utilizadores</th>
                    <th style="padding:14px;text-align:center;">Ações</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($listaPerfis as $p): ?>
                    <tr style="border-bottom:1px solid var(--border);">
                        <td style="padding:14px;color:#fff;font-weight:500;">
                            <?php echo htmlspecialchars($p['nome']); ?>
                            <?php if (!empty($p['descricao'])): ?>
                                <div style="font-size:11px;color:var(--text-muted);font-weight:400;"><?php echo htmlspecialchars($p['descricao']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="padding:14px;">
                            <?php if ((int)$p['is_sistema'] === 1): ?>
                                <span style="color:var(--amber);font-size:12px;">Sistema</span>
                            <?php else: ?>
                                <span style="color:var(--green);font-size:12px;">Custom</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:14px;color:var(--text-secondary);"><?php echo (int)$p['n_perms']; ?></td>
                        <td style="padding:14px;color:var(--text-secondary);"><?php echo (int)$p['n_users']; ?></td>
                        <td style="padding:14px;text-align:center;">
                            <a href="perfis_lista.php?tab=perfis&acao=ver_membros_perfil&id=<?php echo (int)$p['id']; ?>#membros-perfil" style="color:var(--green);font-weight:600;font-size:13px;text-decoration:none;margin-right:12px;">Membros</a>
                            <a href="perfis_lista.php?tab=perfis&acao=editar_perfil&id=<?php echo (int)$p['id']; ?>#form-perfil" style="color:var(--accent);font-weight:600;font-size:13px;text-decoration:none;margin-right:12px;">Editar</a>
                            <?php if ((int)$p['is_sistema'] !== 1): ?>
                                <a href="perfis_lista.php?tab=perfis&acao=remover_perfil&id=<?php echo (int)$p['id']; ?>&csrf=<?php echo urlencode($tokenCsrf); ?>"
                                   onclick="return confirm('Remover este perfil?');"
                                   style="color:var(--red);font-weight:600;font-size:13px;text-decoration:none;">Remover</a>
                            <?php else: ?>
                                <span style="color:var(--text-muted);font-size:12px;">Protegido</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($perfilMembros): ?>
        <div class="card" id="membros-perfil" style="margin-top:24px;padding:0;overflow:hidden;">
            <div style="padding:16px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:12px;">
                <div>
                    <h3 style="font-size:16px;color:var(--accent);margin:0;">Membros — <?php echo htmlspecialchars($perfilMembros['nome']); ?></h3>
                    <p style="font-size:12px;color:var(--text-muted);margin:4px 0 0;"><?php echo count($membrosPerfilLista); ?> pessoa(s) neste perfil</p>
                </div>
                <div style="display:flex;gap:12px;align-items:center;">
                    <a href="perfis_lista.php?tab=perfis&acao=editar_perfil&id=<?php echo (int)$perfilMembros['id']; ?>#form-perfil" style="font-size:13px;color:var(--accent);font-weight:600;">Editar permissões</a>
                    <a href="perfis_lista.php?tab=perfis" style="font-size:13px;color:var(--text-muted);">Fechar</a>
                </div>
            </div>
            <div style="padding:14px 18px;border-bottom:1px solid var(--border);">
                <div style="font-size:12px;color:var(--text-secondary);font-weight:600;margin-bottom:8px;text-transform:uppercase;letter-spacing:0.4px;">
                    Permissões deste perfil
                </div>
                <?php echo htmlChecklistPermissoes($flagsPerfilMembros, '', ['readonly' => true]); ?>
            </div>
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead>
                <tr style="background:var(--bg-sidebar);border-bottom:1px solid var(--border);">
                    <th style="padding:12px;text-align:left;">Nome</th>
                    <th style="padding:12px;text-align:left;">Utilizador</th>
                    <th style="padding:12px;text-align:left;">Área / Operação</th>
                    <th style="padding:12px;text-align:left;">Estado</th>
                    <th style="padding:12px;text-align:left;">Grupos adicionais</th>
                    <th style="padding:12px;text-align:left;">Permissões individuais</th>
                    <th style="padding:12px;text-align:center;">Ação</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($membrosPerfilLista)): ?>
                    <tr><td colspan="7" style="padding:20px;text-align:center;color:var(--text-muted);">Nenhum utilizador associado a este perfil.</td></tr>
                <?php endif; ?>
                <?php foreach ($membrosPerfilLista as $membro):
                    $destinoMembro = trim((string)($membro['nomes_areas'] ?? ''));
                    if ($destinoMembro === '') {
                        $destinoMembro = trim((string)($membro['nome_operacao'] ?? ''));
                    }
                ?>
                    <tr style="border-bottom:1px solid var(--border);">
                        <td style="padding:12px;color:var(--text-primary);font-weight:500;"><?php echo htmlspecialchars($membro['nome']); ?></td>
                        <td style="padding:12px;color:var(--text-secondary);"><?php echo htmlspecialchars($membro['username']); ?></td>
                        <td style="padding:12px;color:var(--text-secondary);"><?php echo htmlspecialchars($destinoMembro !== '' ? $destinoMembro : '—'); ?></td>
                        <td style="padding:12px;color:var(--text-secondary);"><?php echo htmlspecialchars($membro['estado']); ?></td>
                        <td style="padding:12px;color:var(--text-secondary);"><?php echo htmlspecialchars($membro['nomes_grupos'] ?: '—'); ?></td>
                        <td style="padding:12px;color:var(--text-secondary);"><?php echo (int)$membro['n_perms_diretas']; ?></td>
                        <td style="padding:12px;text-align:center;">
                            <?php if ((int)$membro['id'] !== 1): ?>
                                <a href="perfis_lista.php?tab=perfis&acao=permissoes_utilizador&uid=<?php echo (int)$membro['id']; ?>&perfil_id=<?php echo (int)$perfilMembros['id']; ?>#permissoes-utilizador" style="color:var(--accent);font-weight:600;text-decoration:none;">Gerir permissões</a>
                            <?php else: ?>
                                <span style="color:var(--text-muted);">Acesso total</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php else: /* grupos */ ?>

        <div class="card" style="margin-bottom:24px;" id="form-grupo">
            <h3 style="margin-bottom:14px;font-size:16px;color:var(--accent);">
                <?php echo $grupoEditar ? '✏️ Editar grupo' : '➕ Novo grupo de permissão'; ?>
            </h3>
            <form method="POST" action="perfis_lista.php?tab=grupos">
                <?php if ($grupoEditar): ?>
                    <input type="hidden" name="id_grupo" value="<?php echo (int)$grupoEditar['id']; ?>">
                <?php endif; ?>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                    <div>
                        <label style="display:block;margin-bottom:5px;font-size:12px;color:var(--text-secondary);">Nome do grupo</label>
                        <input type="text" name="nome" required value="<?php echo $grupoEditar ? htmlspecialchars($grupoEditar['nome']) : ''; ?>"
                               placeholder="Ex: Helpdesk, Gestão Projectos"
                               style="width:100%;padding:10px;background:var(--bg-input);border:1px solid var(--border);color:#fff;border-radius:var(--radius-sm);box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:5px;font-size:12px;color:var(--text-secondary);">Descrição</label>
                        <input type="text" name="descricao" value="<?php echo $grupoEditar ? htmlspecialchars($grupoEditar['descricao'] ?? '') : ''; ?>"
                               placeholder="Opcional"
                               style="width:100%;padding:10px;background:var(--bg-input);border:1px solid var(--border);color:#fff;border-radius:var(--radius-sm);box-sizing:border-box;">
                    </div>
                </div>
                <label style="display:block;margin-bottom:8px;font-size:12px;color:var(--text-secondary);">
                    Permissões do grupo
                    <?php if ($grupoEditar): ?>
                        <span style="color:var(--green);font-weight:600;">— as activas aparecem marcadas a verde</span>
                    <?php endif; ?>
                </label>
                <?php echo htmlChecklistPermissoes($flagsGrupoEditar); ?>
                <label style="display:block;margin:14px 0 8px;font-size:12px;color:var(--text-secondary);">Membros do grupo</label>
                <div style="max-height:200px;overflow:auto;padding:12px;background:var(--bg-input);border:1px solid var(--border);border-radius:var(--radius-sm);display:flex;flex-wrap:wrap;gap:8px 16px;">
                    <?php foreach ($utilizadoresActivos as $u): ?>
                        <label style="font-size:13px;color:var(--text-primary);cursor:pointer;display:flex;align-items:center;gap:6px;">
                            <input type="checkbox" name="membros[]" value="<?php echo (int)$u['id']; ?>"
                                <?php echo in_array((int)$u['id'], $membrosGrupoEditar, true) ? 'checked' : ''; ?>>
                            <?php echo htmlspecialchars($u['nome']); ?>
                            <span style="color:var(--text-muted);font-size:11px;">(<?php echo htmlspecialchars($u['username']); ?>)</span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div style="margin-top:16px;display:flex;gap:10px;align-items:center;">
                    <button type="submit" name="btn_salvar_grupo" style="padding:11px 20px;background:var(--green);border:none;color:#fff;border-radius:var(--radius-sm);cursor:pointer;font-weight:600;">
                        <?php echo $grupoEditar ? 'Guardar' : 'Criar grupo'; ?>
                    </button>
                    <?php if ($grupoEditar): ?>
                        <a href="perfis_lista.php?tab=grupos" style="font-size:13px;color:var(--text-muted);">Cancelar</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="card" style="padding:0;overflow:hidden;">
            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                <thead>
                <tr style="background:var(--bg-sidebar);border-bottom:1px solid var(--border);">
                    <th style="padding:14px;text-align:left;">Grupo</th>
                    <th style="padding:14px;text-align:left;">Permissões</th>
                    <th style="padding:14px;text-align:left;">Membros</th>
                    <th style="padding:14px;text-align:center;">Ações</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($listaGrupos)): ?>
                    <tr><td colspan="4" style="padding:20px;color:var(--text-muted);">Ainda não há grupos. Crie o primeiro acima.</td></tr>
                <?php endif; ?>
                <?php foreach ($listaGrupos as $g): ?>
                    <tr style="border-bottom:1px solid var(--border);">
                        <td style="padding:14px;color:#fff;font-weight:500;">
                            <?php echo htmlspecialchars($g['nome']); ?>
                            <?php if (!empty($g['descricao'])): ?>
                                <div style="font-size:11px;color:var(--text-muted);font-weight:400;"><?php echo htmlspecialchars($g['descricao']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="padding:14px;color:var(--text-secondary);"><?php echo (int)$g['n_perms']; ?></td>
                        <td style="padding:14px;color:var(--text-secondary);"><?php echo (int)$g['n_membros']; ?></td>
                        <td style="padding:14px;text-align:center;">
                            <a href="perfis_lista.php?tab=grupos&acao=ver_membros_grupo&id=<?php echo (int)$g['id']; ?>#membros-grupo" style="color:var(--green);font-weight:600;font-size:13px;text-decoration:none;margin-right:12px;">Membros</a>
                            <a href="perfis_lista.php?tab=grupos&acao=editar_grupo&id=<?php echo (int)$g['id']; ?>#form-grupo" style="color:var(--accent);font-weight:600;font-size:13px;text-decoration:none;margin-right:12px;">Editar</a>
                            <a href="perfis_lista.php?tab=grupos&acao=remover_grupo&id=<?php echo (int)$g['id']; ?>&csrf=<?php echo urlencode($tokenCsrf); ?>"
                               onclick="return confirm('Remover este grupo e desassociar os membros?');"
                               style="color:var(--red);font-weight:600;font-size:13px;text-decoration:none;">Remover</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($grupoMembros): ?>
        <div class="card" id="membros-grupo" style="margin-top:24px;padding:0;overflow:hidden;">
            <div style="padding:16px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:12px;">
                <div>
                    <h3 style="font-size:16px;color:var(--green);margin:0;">Membros — <?php echo htmlspecialchars($grupoMembros['nome']); ?></h3>
                    <p style="font-size:12px;color:var(--text-muted);margin:4px 0 0;"><?php echo count($membrosGrupoLista); ?> pessoa(s) neste grupo de permissão</p>
                </div>
                <a href="perfis_lista.php?tab=grupos" style="font-size:13px;color:var(--text-muted);">Fechar</a>
            </div>
            <div style="padding:14px 18px;border-bottom:1px solid var(--border);background:rgba(61,111,255,0.04);">
                <?php if (!empty($grupoEOperacao)): ?>
                    <p style="font-size:12px;color:var(--text-secondary);margin:0 0 10px;">
                        Este grupo reúne automaticamente todas as pessoas com operação associada
                        (ex.: <b>Jeiel Mulengo — AFRICELL</b>, <b>Miguel Canda — BAI</b>).
                    </p>
                <?php endif; ?>
                <form method="POST" action="perfis_lista.php?tab=grupos#membros-grupo" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($tokenCsrf); ?>">
                    <input type="hidden" name="id_grupo" value="<?php echo (int)$grupoMembros['id']; ?>">
                    <div style="min-width:280px;flex:1;">
                        <label style="display:block;margin-bottom:5px;font-size:12px;color:var(--text-secondary);">Adicionar pessoa ao grupo</label>
                        <select name="id_utilizador" required style="width:100%;padding:10px;background:var(--bg-input);border:1px solid var(--border);color:#fff;border-radius:var(--radius-sm);">
                            <option value="">-- Escolher pessoa --</option>
                            <?php foreach ($utilizadoresDisponiveisGrupo as $uDisponivel): ?>
                                <option value="<?php echo (int)$uDisponivel['id']; ?>">
                                    <?php echo htmlspecialchars($uDisponivel['nome']); ?>
                                    (<?php echo htmlspecialchars($uDisponivel['username']); ?>)
                                    <?php if (!empty($uDisponivel['nome_operacao'])): ?>
                                        — Operação <?php echo htmlspecialchars($uDisponivel['nome_operacao']); ?>
                                    <?php else: ?>
                                        — <?php echo htmlspecialchars($uDisponivel['nome_perfil']); ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" name="btn_adicionar_membro_grupo"
                            <?php echo empty($utilizadoresDisponiveisGrupo) ? 'disabled' : ''; ?>
                            style="padding:10px 16px;background:var(--green);border:none;color:#fff;border-radius:var(--radius-sm);cursor:pointer;font-weight:600;<?php echo empty($utilizadoresDisponiveisGrupo) ? 'opacity:.5;cursor:not-allowed;' : ''; ?>">
                        + Adicionar pessoa
                    </button>
                </form>
                <?php if (empty($utilizadoresDisponiveisGrupo)): ?>
                    <small style="display:block;margin-top:7px;color:var(--text-muted);">
                        <?php echo !empty($grupoEOperacao)
                            ? 'Todas as pessoas com operação já estão neste grupo.'
                            : 'Todos os utilizadores activos já pertencem a este grupo.'; ?>
                    </small>
                <?php endif; ?>
            </div>
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead>
                <tr style="background:var(--bg-sidebar);border-bottom:1px solid var(--border);">
                    <th style="padding:12px;text-align:left;">Nome</th>
                    <th style="padding:12px;text-align:left;">Utilizador</th>
                    <th style="padding:12px;text-align:left;">Operação</th>
                    <th style="padding:12px;text-align:left;">Perfil</th>
                    <th style="padding:12px;text-align:left;">Estado</th>
                    <th style="padding:12px;text-align:left;">Permissões individuais</th>
                    <th style="padding:12px;text-align:center;">Ação</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($membrosGrupoLista)): ?>
                    <tr><td colspan="7" style="padding:20px;text-align:center;color:var(--text-muted);">Este grupo ainda não tem membros.</td></tr>
                <?php endif; ?>
                <?php foreach ($membrosGrupoLista as $membro): ?>
                    <tr style="border-bottom:1px solid var(--border);">
                        <td style="padding:12px;color:var(--text-primary);font-weight:500;"><?php echo htmlspecialchars($membro['nome']); ?></td>
                        <td style="padding:12px;color:var(--text-secondary);"><?php echo htmlspecialchars($membro['username']); ?></td>
                        <td style="padding:12px;color:var(--accent);font-weight:600;">
                            <?php echo htmlspecialchars($membro['nome_operacao'] ?? '—'); ?>
                        </td>
                        <td style="padding:12px;color:var(--text-secondary);"><?php echo htmlspecialchars($membro['nome_perfil']); ?></td>
                        <td style="padding:12px;color:var(--text-secondary);"><?php echo htmlspecialchars($membro['estado']); ?></td>
                        <td style="padding:12px;color:var(--text-secondary);"><?php echo (int)$membro['n_perms_diretas']; ?></td>
                        <td style="padding:12px;text-align:center;">
                            <div style="display:flex;justify-content:center;align-items:center;gap:12px;flex-wrap:wrap;">
                                <?php if ((int)$membro['id'] !== 1): ?>
                                    <a href="perfis_lista.php?tab=grupos&acao=permissoes_utilizador&uid=<?php echo (int)$membro['id']; ?>&origem=grupos#permissoes-utilizador" style="color:var(--accent);font-weight:600;text-decoration:none;">Gerir permissões</a>
                                <?php else: ?>
                                    <span style="color:var(--text-muted);">Acesso total</span>
                                <?php endif; ?>
                                <form method="POST" action="perfis_lista.php?tab=grupos#membros-grupo" style="display:inline;"
                                      onsubmit="return confirm(<?php echo htmlspecialchars(json_encode('Excluir esta pessoa do grupo ' . $grupoMembros['nome'] . '?', JSON_HEX_APOS | JSON_HEX_QUOT)); ?>);">
                                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($tokenCsrf); ?>">
                                    <input type="hidden" name="id_grupo" value="<?php echo (int)$grupoMembros['id']; ?>">
                                    <input type="hidden" name="id_utilizador" value="<?php echo (int)$membro['id']; ?>">
                                    <button type="submit" name="btn_excluir_membro_grupo" style="padding:0;background:none;border:none;color:var(--red);font:inherit;font-weight:600;cursor:pointer;">Excluir do grupo</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<script src="notificacoes.js"></script>
</body>
</html>
