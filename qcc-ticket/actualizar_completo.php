<?php
/**
 * KIAMI — Actualização completa da BD (CLI / browser Admin)
 * Garante schemas, seeds e consistência dos dados.
 */
require_once __DIR__ . '/conexao.php';

$cli = (PHP_SAPI === 'cli');
$ok = [];
$avisos = [];

function passo(string $msg): void
{
    global $ok, $cli;
    $ok[] = $msg;
    if ($cli) {
        echo "[OK] $msg\n";
    }
}

function aviso(string $msg): void
{
    global $avisos, $cli;
    $avisos[] = $msg;
    if ($cli) {
        echo "[!] $msg\n";
    }
}

try {
    // Collation alinhada (evita Illegal mix of collations)
    try {
        $dbNome = $banco ?? 'qccticket';
        $pdo->exec("ALTER DATABASE `" . str_replace('`', '``', $dbNome) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        passo('Base de dados: charset utf8mb4_unicode_ci');
    } catch (Throwable $e) {
        aviso('Collation BD: ' . $e->getMessage());
    }

    // Tabelas / colunas críticas
    $sqls = [
        "ALTER TABLE utilizadores ADD COLUMN IF NOT EXISTS id_perfil INT NULL",
        "ALTER TABLE utilizadores ADD COLUMN IF NOT EXISTS id_operacao INT NULL",
        "ALTER TABLE utilizadores ADD COLUMN IF NOT EXISTS telefone VARCHAR(40) NULL",
        "ALTER TABLE utilizadores ADD COLUMN IF NOT EXISTS ultimo_acesso DATETIME NULL",
        "ALTER TABLE utilizadores ADD COLUMN IF NOT EXISTS sessao_ativa TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE utilizadores MODIFY COLUMN perfil VARCHAR(100) NOT NULL DEFAULT 'Operador'",
        "ALTER TABLE operacoes ADD COLUMN IF NOT EXISTS id_area_supervisao INT NULL",
        "ALTER TABLE areas ADD COLUMN IF NOT EXISTS email VARCHAR(150) NULL",
        "ALTER TABLE tickets ADD COLUMN IF NOT EXISTS token_acompanhamento VARCHAR(64) NULL",
        "ALTER TABLE kb_artigos ADD COLUMN IF NOT EXISTS visibilidade VARCHAR(40) NOT NULL DEFAULT 'todos'",
    ];
    foreach ($sqls as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            aviso($e->getMessage());
        }
    }
    passo('Colunas críticas garantidas');

    // Uniformizar collations das tabelas principais usadas em JOINs de texto
    $tabelas = ['utilizadores', 'perfis', 'areas', 'operacoes', 'grupos_permissao', 'notificacoes', 'tickets'];
    foreach ($tabelas as $t) {
        try {
            $pdo->exec("ALTER TABLE `$t` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            // ignore se tabela não existir ainda
        }
    }
    passo('Collations das tabelas principais alinhadas');

    garantirSchemaPerfis($pdo);
    passo('Schema de perfis / grupos / permissões');

    garantirAreasSupervisao($pdo, true);
    $nSup = (int)$pdo->query("SELECT COUNT(*) FROM operacoes WHERE id_area_supervisao IS NOT NULL")->fetchColumn();
    passo("Áreas de Supervisão por operação ($nSup operações)");

    garantirGruposPorAreas($pdo);
    garantirGrupoOperacao($pdo);
    $nGrupos = (int)$pdo->query("SELECT COUNT(*) FROM grupos_permissao")->fetchColumn();
    $nOp = (int)$pdo->query("SELECT COUNT(*) FROM utilizador_grupos ug INNER JOIN grupos_permissao g ON g.id=ug.id_grupo WHERE g.nome='Operação'")->fetchColumn();
    passo("Grupos de permissão ($nGrupos) — grupo Operação com $nOp membros");

    // Reaplicar seed de permissões em perfis de sistema com 0 flags
    $stPerfis = $pdo->query("SELECT id, codigo FROM perfis WHERE is_sistema = 1");
    $insP = $pdo->prepare("INSERT IGNORE INTO perfil_permissoes (id_perfil, permissao) VALUES (?, ?)");
    $stCount = $pdo->prepare("SELECT COUNT(*) FROM perfil_permissoes WHERE id_perfil = ?");
    $nSeed = 0;
    foreach ($stPerfis->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $stCount->execute([(int)$p['id']]);
        if ((int)$stCount->fetchColumn() > 0) {
            continue;
        }
        foreach (permissoesSeedPorCodigo((string)$p['codigo']) as $flag) {
            $insP->execute([(int)$p['id'], $flag]);
            $nSeed++;
        }
    }
    passo("Seed de permissões em perfis vazios ($nSeed flags)");

    // Ligar id_perfil em falta
    $pdo->exec("
        UPDATE utilizadores u
        INNER JOIN perfis p ON (
            (u.perfil = 'Admin' AND p.codigo = 'admin')
            OR (u.perfil = 'Diretor Geral' AND p.codigo = 'diretor_geral')
            OR (u.perfil = 'Responsavel' AND p.codigo = 'responsavel')
            OR (u.perfil = 'Tecnico' AND p.codigo = 'tecnico')
            OR (u.perfil = 'Operador' AND p.codigo = 'operador')
            OR (u.perfil IN ('Supervisao','Supervisão') AND p.codigo = 'supervisao')
            OR (u.perfil = p.nome)
            OR (u.perfil = p.codigo)
        )
        SET u.id_perfil = p.id
        WHERE u.id_perfil IS NULL
    ");
    passo('Utilizadores sem id_perfil ligados ao perfil correcto');

    // Supervisão: garantir área correcta
    $stSupUsers = $pdo->query("
        SELECT u.id, u.id_operacao FROM utilizadores u
        WHERE (u.perfil IN ('Supervisao','Supervisão') OR u.id_perfil = (SELECT id FROM perfis WHERE codigo='supervisao' LIMIT 1))
          AND u.id_operacao IS NOT NULL
    ");
    $nSupUsers = 0;
    foreach ($stSupUsers->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $idArea = obterIdAreaSupervisaoOperacao($pdo, (int)$u['id_operacao']);
        if ($idArea) {
            $pdo->prepare("UPDATE utilizadores SET id_area = ? WHERE id = ?")->execute([$idArea, (int)$u['id']]);
            sincronizarAreasUtilizador($pdo, (int)$u['id'], [$idArea]);
            $nSupUsers++;
        }
    }
    passo("Contas Supervisão sincronizadas com área ($nSupUsers)");

    // Contagens finais
    $resumo = [
        'perfis' => (int)$pdo->query("SELECT COUNT(*) FROM perfis")->fetchColumn(),
        'grupos' => (int)$pdo->query("SELECT COUNT(*) FROM grupos_permissao")->fetchColumn(),
        'areas' => (int)$pdo->query("SELECT COUNT(*) FROM areas")->fetchColumn(),
        'operacoes' => (int)$pdo->query("SELECT COUNT(*) FROM operacoes")->fetchColumn(),
        'utilizadores_ativos' => (int)$pdo->query("SELECT COUNT(*) FROM utilizadores WHERE estado='Ativo'")->fetchColumn(),
        'tickets' => (int)$pdo->query("SELECT COUNT(*) FROM tickets")->fetchColumn(),
    ];
    passo('Resumo: ' . json_encode($resumo, JSON_UNESCAPED_UNICODE));

} catch (Throwable $e) {
    aviso('Falha geral: ' . $e->getMessage());
    if ($cli) {
        exit(1);
    }
}

if ($cli) {
    echo "\nConcluído: " . count($ok) . " passos, " . count($avisos) . " avisos.\n";
    exit(count($avisos) && empty($ok) ? 1 : 0);
}

// Browser: só Admin
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$ctx = obterContextoUsuario($pdo);
if (($ctx['perfil'] ?? '') !== 'Admin') {
    http_response_code(403);
    die('Acesso negado.');
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <title>Actualização completa — KIAMI</title>
    <link rel="stylesheet" href="style.css">
</head>
<body style="padding:40px;max-width:720px;margin:0 auto;">
<div class="card">
    <h1 style="color:var(--accent);margin-bottom:16px;">Actualização completa</h1>
    <ul style="line-height:1.9;color:var(--text-secondary);">
        <?php foreach ($ok as $m): ?><li>✅ <?php echo htmlspecialchars($m); ?></li><?php endforeach; ?>
        <?php foreach ($avisos as $m): ?><li>⚠️ <?php echo htmlspecialchars($m); ?></li><?php endforeach; ?>
    </ul>
    <p style="margin-top:20px;"><a href="index.php" style="color:var(--accent);">Voltar ao Painel</a>
        · <a href="atualizar_banco.php" style="color:var(--accent);">Actualizar base de dados (legado)</a></p>
</div>
</body>
</html>
