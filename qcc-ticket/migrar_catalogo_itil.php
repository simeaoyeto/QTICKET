<?php
/**
 * Migração CLI do catálogo ITIL (uso local / XAMPP).
 * php migrar_catalogo_itil.php
 */
require_once __DIR__ . '/conexao.php';

echo "Migrando catálogo ITIL...\n";

$stmts = [
    "ALTER TABLE ticket_assuntos ADD COLUMN IF NOT EXISTS chave_catalogo VARCHAR(80) NULL",
    "ALTER TABLE ticket_assuntos ADD COLUMN IF NOT EXISTS classificacao_itil VARCHAR(60) NULL",
    "ALTER TABLE ticket_assuntos ADD COLUMN IF NOT EXISTS sla_resposta_min INT NULL",
    "ALTER TABLE ticket_assuntos ADD COLUMN IF NOT EXISTS sla_resolucao_h DECIMAL(6,2) NULL",
    "ALTER TABLE ticket_assuntos ADD COLUMN IF NOT EXISTS prioridade_base VARCHAR(20) NULL",
    "ALTER TABLE ticket_assuntos ADD COLUMN IF NOT EXISTS urgencia_base VARCHAR(20) NULL",
    "ALTER TABLE ticket_assuntos ADD COLUMN IF NOT EXISTS aprovacao TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE ticket_assuntos ADD COLUMN IF NOT EXISTS anexo_obrigatorio TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE ticket_assuntos ADD COLUMN IF NOT EXISTS multiplo TINYINT(1) NOT NULL DEFAULT 1",
    "ALTER TABLE ticket_assuntos ADD COLUMN IF NOT EXISTS tem_outros TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE ticket_assuntos MODIFY COLUMN titulo VARCHAR(255) NOT NULL",
    "ALTER TABLE tickets ADD COLUMN IF NOT EXISTS impacto VARCHAR(30) NULL",
    "ALTER TABLE tickets ADD COLUMN IF NOT EXISTS urgencia VARCHAR(20) NULL",
    "ALTER TABLE tickets ADD COLUMN IF NOT EXISTS data_limite_resposta DATETIME NULL",
];

foreach ($stmts as $sql) {
    try {
        $pdo->exec($sql);
        echo "OK: " . substr($sql, 0, 70) . "...\n";
    } catch (PDOException $e) {
        echo "SKIP: " . $e->getMessage() . "\n";
    }
}

try {
    $pdo->exec("ALTER TABLE tickets MODIFY COLUMN prioridade ENUM('Crítica','Alta','Média','Baixa') NOT NULL DEFAULT 'Média'");
    echo "OK: prioridade ENUM com Crítica\n";
} catch (PDOException $e) {
    echo "SKIP prioridade: " . $e->getMessage() . "\n";
}

// Seed catálogo
try {
    $pdo->exec("UPDATE ticket_assuntos SET ativo = 0");
    $stEx = $pdo->prepare("SELECT id FROM ticket_assuntos WHERE titulo = ? LIMIT 1");
    $insCat = $pdo->prepare("INSERT INTO ticket_assuntos (titulo, id_pai, ordem, ativo) VALUES (?, NULL, ?, 1)");
    $updCat = $pdo->prepare("UPDATE ticket_assuntos SET ativo = 1, id_pai = NULL, ordem = ? WHERE id = ?");
    $insDet = $pdo->prepare("INSERT INTO ticket_assuntos (titulo, id_pai, ordem, ativo, chave_catalogo, classificacao_itil, sla_resposta_min, sla_resolucao_h, prioridade_base, urgencia_base, aprovacao, anexo_obrigatorio, multiplo, tem_outros) VALUES (?,?,?,1,?,?,?,?,?,?,?,?,?,?)");
    $updDet = $pdo->prepare("UPDATE ticket_assuntos SET ativo = 1, id_pai = ?, ordem = ?, chave_catalogo = ?, classificacao_itil = ?, sla_resposta_min = ?, sla_resolucao_h = ?, prioridade_base = ?, urgencia_base = ?, aprovacao = ?, anexo_obrigatorio = ?, multiplo = ?, tem_outros = ? WHERE id = ?");

    $ordemCat = 10;
    $idsCat = [];
    foreach (catalogoParaArvoreAssuntos() as $categoria => $detalhes) {
        $stEx->execute([$categoria]);
        $idCat = $stEx->fetchColumn();
        if ($idCat) {
            $updCat->execute([$ordemCat, $idCat]);
            $idCat = (int)$idCat;
        } else {
            $insCat->execute([$categoria, $ordemCat]);
            $idCat = (int)$pdo->lastInsertId();
        }
        $idsCat[$categoria] = $idCat;
        $ordemCat += 10;
    }

    $n = 0;
    $ordemPorCat = [];
    foreach (obterCatalogoServicosCompleto() as $item) {
        $cat = $item['categoria'];
        $idCat = $idsCat[$cat] ?? null;
        if (!$idCat) continue;
        $label = $item['subcategoria'] . ' › ' . $item['item'];
        $ordemPorCat[$cat] = ($ordemPorCat[$cat] ?? 0) + 10;
        $stEx->execute([$label]);
        $idDet = $stEx->fetchColumn();
        $meta = [
            $item['chave'], $item['classificacao_itil'], $item['sla_resposta_min'], $item['sla_resolucao_h'],
            $item['prioridade_base'], $item['urgencia_base'],
            $item['aprovacao'] ? 1 : 0, $item['anexo_obrigatorio'] ? 1 : 0,
            $item['multiplo'] ? 1 : 0, $item['tem_outros'] ? 1 : 0,
        ];
        if ($idDet) {
            $updDet->execute(array_merge([$idCat, $ordemPorCat[$cat]], $meta, [(int)$idDet]));
        } else {
            $insDet->execute(array_merge([$label, $idCat, $ordemPorCat[$cat]], $meta));
        }
        $n++;
    }
    echo "Seed OK: $n itens do catálogo\n";
} catch (PDOException $e) {
    echo "ERRO seed: " . $e->getMessage() . "\n";
}

$cats = (int)$pdo->query("SELECT COUNT(*) FROM ticket_assuntos WHERE ativo=1 AND (id_pai IS NULL OR id_pai=0)")->fetchColumn();
$dets = (int)$pdo->query("SELECT COUNT(*) FROM ticket_assuntos WHERE ativo=1 AND id_pai IS NOT NULL AND id_pai>0")->fetchColumn();
echo "Categorias activas: $cats | Detalhes activos: $dets\n";
echo "Concluído.\n";
