<?php
/**
 * KIAMI — Script de migração/atualização da base de dados
 *
 * Executar no browser após instalação ou upgrade (ex: http://localhost/qcc-ticket/atualizar_banco.php).
 * Cria tabelas novas, colunas em falta, seeds de formação e corrige dados legados.
 * Idempotente: pode ser executado várias vezes com segurança.
 * Restrito a Admin; só corre migrações via POST com token CSRF.
 */
require_once 'conexao.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$contextoMigracao = obterContextoUsuario($pdo);
if (($contextoMigracao['perfil'] ?? '') !== 'Admin') {
    http_response_code(403);
    registarAuditoria($pdo, 'Acesso Negado', 'Tentativa de executar atualizar_banco.php sem perfil Admin');
    die('Acesso negado. Apenas administradores podem atualizar a base de dados.');
}

$mensagens = [];
$tokenCsrf = gerarTokenCsrf();
$executarMigracoes = $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['confirmar_migracao'])
    && validarTokenCsrf($_POST['csrf'] ?? null);

if (!$executarMigracoes) {
    ?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atualizar Base de Dados - KIAMI</title>
    <link rel="stylesheet" href="style.css">
    <script src="tema.js"></script>
</head>
<body style="padding: 40px; max-width: 700px; margin: 0 auto;">
    <div class="card">
        <h1 style="color: var(--accent); margin-bottom: 20px;">Atualização da Base de Dados</h1>
        <p style="color: var(--text-secondary); line-height: 1.6; margin-bottom: 20px;">
            Esta acção cria/altera estruturas na base de dados. Só deve ser executada após instalação ou upgrade.
            Confirme para continuar.
        </p>
        <form method="POST" action="atualizar_banco.php">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($tokenCsrf); ?>">
            <button type="submit" name="confirmar_migracao" value="1" style="padding: 12px 22px; background: var(--accent); border: none; color: #fff; border-radius: var(--radius-sm); cursor: pointer; font-weight: 600;">
                Confirmar e actualizar
            </button>
        </form>
        <p style="margin-top: 25px;">
            <a href="index.php" style="color: var(--accent);">Cancelar e voltar ao Painel</a>
        </p>
    </div>
</body>
</html>
    <?php
    exit;
}

/** Executa SQL e regista resultado na lista de mensagens (sucesso ou aviso) */
function executarSql(PDO $pdo, string $sql, string $descricao): void
{
    global $mensagens;
    try {
        $pdo->exec($sql);
        $mensagens[] = "✅ $descricao";
    } catch (PDOException $e) {
        $mensagens[] = "⚠️ $descricao — " . $e->getMessage();
    }
}

registarAuditoria($pdo, 'Alteração', 'Migração/atualização da base de dados executada');

// Colunas novas em tickets
executarSql($pdo, "ALTER TABLE tickets ADD COLUMN IF NOT EXISTS codigo VARCHAR(20) NULL AFTER id", "Coluna codigo em tickets");
executarSql($pdo, "ALTER TABLE tickets ADD COLUMN IF NOT EXISTS nome_solicitante VARCHAR(100) NULL AFTER titulo", "Coluna nome_solicitante em tickets");
// E-mail de quem abriu o ticket — permite enviar avisos automáticos (criação, mudanças de estado, etc.)
executarSql($pdo, "ALTER TABLE tickets ADD COLUMN IF NOT EXISTS email_solicitante VARCHAR(150) NULL AFTER nome_solicitante", "Coluna email_solicitante em tickets");
executarSql($pdo, "ALTER TABLE tickets ADD COLUMN IF NOT EXISTS id_area_destino INT NULL AFTER id_criador", "Coluna id_area_destino em tickets");
executarSql($pdo, "ALTER TABLE tickets ADD COLUMN IF NOT EXISTS id_operacao_origem INT NULL AFTER id_area_destino", "Coluna id_operacao_origem em tickets");
// Token de acompanhamento/reabertura pública (link nos emails, sem necessidade de login)
executarSql($pdo, "ALTER TABLE tickets ADD COLUMN IF NOT EXISTS token_acompanhamento VARCHAR(64) NULL AFTER codigo", "Coluna token_acompanhamento em tickets");
executarSql($pdo, "ALTER TABLE utilizadores ADD COLUMN IF NOT EXISTS id_operacao INT NULL AFTER id_area", "Coluna id_operacao em utilizadores");
executarSql($pdo, "ALTER TABLE utilizadores ADD COLUMN IF NOT EXISTS telefone VARCHAR(40) NULL AFTER email", "Coluna telefone em utilizadores");
// Presença online: guarda o último acesso para mostrar que técnicos estão disponíveis
executarSql($pdo, "ALTER TABLE utilizadores ADD COLUMN IF NOT EXISTS ultimo_acesso DATETIME NULL AFTER estado", "Coluna ultimo_acesso em utilizadores");
// Sinal de sessão ativa: fica 1 enquanto o utilizador tem sessão iniciada e 0 após o logout.
// Combinado com ultimo_acesso, permite mostrar offline imediato no logout, preservando o "visto por último".
executarSql($pdo, "ALTER TABLE utilizadores ADD COLUMN IF NOT EXISTS sessao_ativa TINYINT(1) NOT NULL DEFAULT 0 AFTER ultimo_acesso", "Coluna sessao_ativa em utilizadores");

// Email de caixa postal por área (grupo de notificação automática ao abrir ticket)
executarSql($pdo, "ALTER TABLE areas ADD COLUMN IF NOT EXISTS email VARCHAR(150) NULL AFTER nome", "Coluna email em areas");
try {
    $pdo->exec("UPDATE areas SET email = 'helpdesk@quality.co.ao' WHERE id IN (1, 2) AND (email IS NULL OR email = '')");
    $mensagens[] = "✅ Emails default (helpdesk@quality.co.ao) aplicados a Redes & Sistemas e Desenvolvimento";
} catch (PDOException $e) {
    $mensagens[] = "⚠️ Seed email áreas — " . $e->getMessage();
}

// Colunas para anexos de imagem
executarSql($pdo, "ALTER TABLE tickets ADD COLUMN IF NOT EXISTS anexo VARCHAR(255) NULL AFTER estado", "Coluna anexo (imagem) em tickets");
executarSql($pdo, "ALTER TABLE kb_artigos ADD COLUMN IF NOT EXISTS imagem VARCHAR(255) NULL AFTER conteudo", "Coluna imagem em kb_artigos");
executarSql($pdo, "ALTER TABLE kb_artigos ADD COLUMN IF NOT EXISTS visibilidade VARCHAR(40) NOT NULL DEFAULT 'todos' AFTER categoria", "Coluna visibilidade em kb_artigos");

// Colunas de controlo do escalonamento por tempo (avisos aos responsáveis da área)
// Marcam se já foi enviada a notificação de 5 e de 10 minutos para tickets sem atendimento.
executarSql($pdo, "ALTER TABLE tickets ADD COLUMN IF NOT EXISTS notif_escala_5 TINYINT(1) NOT NULL DEFAULT 0 AFTER data_resolucao", "Coluna notif_escala_5 em tickets");
executarSql($pdo, "ALTER TABLE tickets ADD COLUMN IF NOT EXISTS notif_escala_10 TINYINT(1) NOT NULL DEFAULT 0 AFTER notif_escala_5", "Coluna notif_escala_10 em tickets");

// Normalização de nomes de operação (ex: Africell → AFRICELL, em maiúsculas)
executarSql($pdo, "UPDATE operacoes SET nome = 'AFRICELL' WHERE LOWER(nome) = 'africell'", "Operação Africell normalizada para AFRICELL");
executarSql($pdo, "UPDATE operacoes SET nome = 'MULTICHOICE' WHERE LOWER(nome) = 'multichoice'", "Operação Multichoice normalizada para MULTICHOICE");

// Área Formadores (formação interna / equipa de formadores)
try {
    $existeFormadores = $pdo->query("SELECT id FROM areas WHERE nome = 'Formadores' LIMIT 1")->fetchColumn();
    if (!$existeFormadores) {
        $pdo->exec("INSERT INTO areas (nome) VALUES ('Formadores')");
        $mensagens[] = "✅ Área «Formadores» criada";
    } else {
        $mensagens[] = "✅ Área «Formadores» já existe";
    }
} catch (PDOException $e) {
    $mensagens[] = "⚠️ Área Formadores — " . $e->getMessage();
}

// Áreas em falta (mesma lógica das restantes)
$areasNovas = ['Legal', 'Logística', 'Gestão de Projectos'];
foreach ($areasNovas as $nomeArea) {
    try {
        $stmtExiste = $pdo->prepare("SELECT id FROM areas WHERE nome = ? LIMIT 1");
        $stmtExiste->execute([$nomeArea]);
        if (!$stmtExiste->fetchColumn()) {
            $emailArea = null;
            if ($nomeArea === 'Gestão de Projectos') {
                $emailArea = 'projectos@quality.co.ao';
            }
            $pdo->prepare("INSERT INTO areas (nome, email) VALUES (?, ?)")->execute([$nomeArea, $emailArea]);
            $mensagens[] = "✅ Área «{$nomeArea}» criada";
        } else {
            $mensagens[] = "✅ Área «{$nomeArea}» já existe";
        }
    } catch (PDOException $e) {
        $mensagens[] = "⚠️ Área {$nomeArea} — " . $e->getMessage();
    }
}
// Corrige nomes mal codificados (ex.: LogÝstica → Logística)
try {
    $corrigidos = $pdo->exec("UPDATE areas SET nome = 'Logística' WHERE nome LIKE 'Log_stica' AND nome <> 'Logística'");
    if ($corrigidos > 0) {
        $mensagens[] = "✅ Nome «Logística» corrigido na tabela areas";
    }
} catch (PDOException $e) {
    $mensagens[] = "⚠️ Correção Logística — " . $e->getMessage();
}

// Migração de estados: remover 'Fechado' e legados — apenas Aberto, Em Progresso, Reencaminhado, Resolvido
executarSql($pdo, "UPDATE tickets SET estado = 'Resolvido', data_resolucao = COALESCE(data_resolucao, NOW()) WHERE estado = 'Fechado'", "Tickets 'Fechado' convertidos para 'Resolvido'");
executarSql($pdo, "UPDATE tickets SET estado = 'Em Progresso' WHERE estado = 'Aguardando Utilizador'", "Estado legado 'Aguardando Utilizador' convertido para 'Em Progresso'");
executarSql($pdo, "ALTER TABLE tickets MODIFY COLUMN estado ENUM('Aberto','Em Progresso','Reencaminhado','Resolvido') NOT NULL DEFAULT 'Aberto'", "Enum de estados actualizado (4 estados válidos)");

// Perfil Operador + estado Pendente (aprovação de contas por Redes & Sistemas)
executarSql(
    $pdo,
    "ALTER TABLE utilizadores MODIFY COLUMN perfil ENUM('Admin','Diretor Geral','Responsavel','Tecnico','Comum','Cliente','Operador') NOT NULL",
    "Enum de perfil (inclui legado Cliente temporariamente para migração)"
);
try {
    $pdo->exec("UPDATE utilizadores SET perfil = 'Operador' WHERE perfil = 'Cliente'");
    $pdo->exec("UPDATE utilizadores SET perfil = 'Operador' WHERE id_operacao IS NOT NULL AND perfil = 'Comum' AND (username LIKE 'cliente.%' OR username LIKE 'operador.%')");
    $mensagens[] = "✅ Perfis Cliente migrados para Operador";
} catch (PDOException $e) {
    $mensagens[] = "⚠️ Migração Cliente→Operador — " . $e->getMessage();
}
executarSql(
    $pdo,
    "ALTER TABLE utilizadores MODIFY COLUMN perfil ENUM('Admin','Diretor Geral','Responsavel','Tecnico','Comum','Operador') NOT NULL",
    "Enum de perfil sem Cliente (removido)"
);
executarSql(
    $pdo,
    "ALTER TABLE utilizadores MODIFY COLUMN estado ENUM('Ativo','Inativo','Pendente') NOT NULL DEFAULT 'Ativo'",
    "Enum de estado de utilizador com Pendente (aprovação)"
);

// Multiárea: um utilizador (ex. Responsável) pode pertencer a várias áreas
executarSql($pdo, "CREATE TABLE IF NOT EXISTS utilizador_areas (
    id_utilizador INT NOT NULL,
    id_area INT NOT NULL,
    PRIMARY KEY (id_utilizador, id_area),
    CONSTRAINT fk_ua_utilizador FOREIGN KEY (id_utilizador) REFERENCES utilizadores(id) ON DELETE CASCADE,
    CONSTRAINT fk_ua_area FOREIGN KEY (id_area) REFERENCES areas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Tabela utilizador_areas (multiárea)");

try {
    $pdo->exec("
        INSERT IGNORE INTO utilizador_areas (id_utilizador, id_area)
        SELECT id, id_area FROM utilizadores WHERE id_area IS NOT NULL
    ");
    $mensagens[] = "✅ Áreas existentes sincronizadas para utilizador_areas";
} catch (PDOException $e) {
    $mensagens[] = "⚠️ Sync utilizador_areas — " . $e->getMessage();
}

// =========================================================
// CÓDIGOS DE TICKET ÚNICOS
// Corrige códigos em falta (NULL) ou duplicados e cria índice UNIQUE
// para o servidor de BD impedir códigos repetidos de forma definitiva.
// =========================================================
try {
    // Reatribui um código único (baseado no ID) a tickets sem código ou com código repetido
    $todos = $pdo->query("SELECT id, codigo, data_criacao FROM tickets ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $usados = [];
    $upd = $pdo->prepare("UPDATE tickets SET codigo = ? WHERE id = ?");
    $corrigidos = 0;
    foreach ($todos as $t) {
        $codigo = $t['codigo'] ?? '';
        // Precisa de novo código se estiver vazio ou já tiver sido usado por outro ticket
        if ($codigo === '' || $codigo === null || isset($usados[$codigo])) {
            $ano = $t['data_criacao'] ? date('Y', strtotime($t['data_criacao'])) : date('Y');
            $novo = "QCC-{$ano}-" . str_pad((string)$t['id'], 6, '0', STR_PAD_LEFT);
            // Em caso improvável de colisão, acrescenta sufixo até ficar único
            $sufixo = 0;
            $candidato = $novo;
            while (isset($usados[$candidato])) {
                $sufixo++;
                $candidato = $novo . '-' . $sufixo;
            }
            $upd->execute([$candidato, $t['id']]);
            $usados[$candidato] = true;
            $corrigidos++;
        } else {
            $usados[$codigo] = true;
        }
    }
    $mensagens[] = $corrigidos > 0
        ? "✅ $corrigidos código(s) de ticket em falta/duplicados corrigidos"
        : "✅ Códigos de ticket já únicos";
} catch (PDOException $e) {
    $mensagens[] = "⚠️ Correção de códigos de ticket — " . $e->getMessage();
}
// Índice UNIQUE garante que a BD nunca aceita dois tickets com o mesmo código
executarSql($pdo, "ALTER TABLE tickets ADD UNIQUE INDEX uniq_codigo (codigo)", "Índice UNIQUE no código do ticket");

// Tabela histórico de tickets
executarSql($pdo, "CREATE TABLE IF NOT EXISTS ticket_historico (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_ticket INT NOT NULL,
    id_utilizador INT NULL,
    acao VARCHAR(100) NOT NULL,
    detalhes TEXT,
    data_registo TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_ticket) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (id_utilizador) REFERENCES utilizadores(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Tabela ticket_historico");

// Assuntos/categorias configuráveis para abertura de tickets
executarSql($pdo, "CREATE TABLE IF NOT EXISTS ticket_assuntos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titulo VARCHAR(150) NOT NULL,
    ordem INT NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    data_criacao DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_titulo (titulo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Tabela ticket_assuntos");

executarSql($pdo, "ALTER TABLE ticket_assuntos ADD COLUMN IF NOT EXISTS id_pai INT NULL DEFAULT NULL AFTER titulo", "Coluna id_pai em ticket_assuntos (hierarquia)");
executarSql($pdo, "ALTER TABLE tickets MODIFY COLUMN titulo VARCHAR(500) NOT NULL", "Alargar titulo para múltiplos assuntos");

// Metadados ITIL / SLA nos assuntos
executarSql($pdo, "ALTER TABLE ticket_assuntos ADD COLUMN IF NOT EXISTS chave_catalogo VARCHAR(80) NULL AFTER id_pai", "chave_catalogo em ticket_assuntos");
executarSql($pdo, "ALTER TABLE ticket_assuntos ADD COLUMN IF NOT EXISTS classificacao_itil VARCHAR(60) NULL AFTER chave_catalogo", "classificacao_itil");
executarSql($pdo, "ALTER TABLE ticket_assuntos ADD COLUMN IF NOT EXISTS sla_resposta_min INT NULL DEFAULT NULL AFTER classificacao_itil", "sla_resposta_min");
executarSql($pdo, "ALTER TABLE ticket_assuntos ADD COLUMN IF NOT EXISTS sla_resolucao_h DECIMAL(6,2) NULL DEFAULT NULL AFTER sla_resposta_min", "sla_resolucao_h");
executarSql($pdo, "ALTER TABLE ticket_assuntos ADD COLUMN IF NOT EXISTS prioridade_base VARCHAR(20) NULL DEFAULT NULL AFTER sla_resolucao_h", "prioridade_base");
executarSql($pdo, "ALTER TABLE ticket_assuntos ADD COLUMN IF NOT EXISTS urgencia_base VARCHAR(20) NULL DEFAULT NULL AFTER prioridade_base", "urgencia_base");
executarSql($pdo, "ALTER TABLE ticket_assuntos ADD COLUMN IF NOT EXISTS aprovacao TINYINT(1) NOT NULL DEFAULT 0 AFTER urgencia_base", "aprovacao");
executarSql($pdo, "ALTER TABLE ticket_assuntos ADD COLUMN IF NOT EXISTS anexo_obrigatorio TINYINT(1) NOT NULL DEFAULT 0 AFTER aprovacao", "anexo_obrigatorio");
executarSql($pdo, "ALTER TABLE ticket_assuntos ADD COLUMN IF NOT EXISTS multiplo TINYINT(1) NOT NULL DEFAULT 1 AFTER anexo_obrigatorio", "multiplo");
executarSql($pdo, "ALTER TABLE ticket_assuntos ADD COLUMN IF NOT EXISTS tem_outros TINYINT(1) NOT NULL DEFAULT 0 AFTER multiplo", "tem_outros");
executarSql($pdo, "ALTER TABLE ticket_assuntos MODIFY COLUMN titulo VARCHAR(255) NOT NULL", "Alargar titulo assuntos");

// Tickets: impacto, urgência, SLA resposta, prioridade Crítica
executarSql($pdo, "ALTER TABLE tickets ADD COLUMN IF NOT EXISTS impacto VARCHAR(30) NULL DEFAULT NULL AFTER prioridade", "impacto em tickets");
executarSql($pdo, "ALTER TABLE tickets ADD COLUMN IF NOT EXISTS urgencia VARCHAR(20) NULL DEFAULT NULL AFTER impacto", "urgencia em tickets");
executarSql($pdo, "ALTER TABLE tickets ADD COLUMN IF NOT EXISTS data_limite_resposta DATETIME NULL AFTER data_limite_sla", "data_limite_resposta");
try {
    $pdo->exec("ALTER TABLE tickets MODIFY COLUMN prioridade ENUM('Crítica','Alta','Média','Baixa') NOT NULL DEFAULT 'Média'");
    $mensagens[] = "✅ Prioridade com nível Crítica";
} catch (PDOException $e) {
    $mensagens[] = "⚠️ Enum prioridade — " . $e->getMessage();
}

// Seed / migração para árvore profissional (categoria → detalhe)
try {
    $nFilhos = 0;
    try {
        $nFilhos = (int)$pdo->query("SELECT COUNT(*) FROM ticket_assuntos WHERE id_pai IS NOT NULL AND id_pai > 0")->fetchColumn();
    } catch (PDOException $e) {
        $nFilhos = 0;
    }
    if ($nFilhos === 0) {
        // Desactiva lista plana antiga e carrega a árvore nova
        $pdo->exec("UPDATE ticket_assuntos SET ativo = 0");
        $insCat = $pdo->prepare("INSERT INTO ticket_assuntos (titulo, id_pai, ordem, ativo) VALUES (?, NULL, ?, 1)");
        $insDet = $pdo->prepare("INSERT INTO ticket_assuntos (titulo, id_pai, ordem, ativo) VALUES (?, ?, ?, 1)");
        $ordemCat = 10;
        foreach (obterArvoreAssuntosTicketPadrao() as $categoria => $detalhes) {
            // Evita colisão UNIQUE: reactiva se já existir com o mesmo título
            $stEx = $pdo->prepare("SELECT id FROM ticket_assuntos WHERE titulo = ? LIMIT 1");
            $stEx->execute([$categoria]);
            $idCat = $stEx->fetchColumn();
            if ($idCat) {
                $pdo->prepare("UPDATE ticket_assuntos SET ativo = 1, id_pai = NULL, ordem = ? WHERE id = ?")
                    ->execute([$ordemCat, $idCat]);
                $idCat = (int)$idCat;
            } else {
                $insCat->execute([$categoria, $ordemCat]);
                $idCat = (int)$pdo->lastInsertId();
            }
            $ordemDet = 10;
            foreach ($detalhes as $detalhe) {
                $stEx->execute([$detalhe]);
                $idDet = $stEx->fetchColumn();
                if ($idDet) {
                    $pdo->prepare("UPDATE ticket_assuntos SET ativo = 1, id_pai = ?, ordem = ? WHERE id = ?")
                        ->execute([$idCat, $ordemDet, $idDet]);
                } else {
                    $insDet->execute([$detalhe, $idCat, $ordemDet]);
                }
                $ordemDet += 10;
            }
            $ordemCat += 10;
        }
        $mensagens[] = "✅ Assuntos reorganizados em categorias profissionais (cascata)";
    } else {
        $mensagens[] = "✅ Hierarquia de assuntos já presente";
    }
} catch (PDOException $e) {
    $mensagens[] = "⚠️ Seed hierarquia assuntos — " . $e->getMessage();
}

// Seed / actualização Catálogo ITIL v4 (metadados SLA + novas categorias)
try {
    $temPosto = (int)$pdo->query("SELECT COUNT(*) FROM ticket_assuntos WHERE titulo = 'Posto de Trabalho' AND (id_pai IS NULL OR id_pai = 0) AND ativo = 1")->fetchColumn();
    $semMeta = (int)$pdo->query("SELECT COUNT(*) FROM ticket_assuntos WHERE id_pai IS NOT NULL AND id_pai > 0 AND ativo = 1 AND (sla_resolucao_h IS NULL OR chave_catalogo IS NULL)")->fetchColumn();
    if ($temPosto === 0 || $semMeta > 5) {
        $pdo->exec("UPDATE ticket_assuntos SET ativo = 0");
        $stEx = $pdo->prepare("SELECT id FROM ticket_assuntos WHERE titulo = ? LIMIT 1");
        $insCat = $pdo->prepare("INSERT INTO ticket_assuntos (titulo, id_pai, ordem, ativo) VALUES (?, NULL, ?, 1)");
        $updCat = $pdo->prepare("UPDATE ticket_assuntos SET ativo = 1, id_pai = NULL, ordem = ?, chave_catalogo = NULL, classificacao_itil = NULL, sla_resposta_min = NULL, sla_resolucao_h = NULL, prioridade_base = NULL, urgencia_base = NULL WHERE id = ?");
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

        $ordemPorCat = [];
        foreach (obterCatalogoServicosCompleto() as $item) {
            $cat = $item['categoria'];
            $idCat = $idsCat[$cat] ?? null;
            if (!$idCat) {
                continue;
            }
            $label = $item['subcategoria'] . ' › ' . $item['item'];
            $ordemPorCat[$cat] = ($ordemPorCat[$cat] ?? 0) + 10;
            $ordemDet = $ordemPorCat[$cat];
            $stEx->execute([$label]);
            $idDet = $stEx->fetchColumn();
            $paramsMeta = [
                $item['chave'],
                $item['classificacao_itil'],
                $item['sla_resposta_min'],
                $item['sla_resolucao_h'],
                $item['prioridade_base'],
                $item['urgencia_base'],
                $item['aprovacao'] ? 1 : 0,
                $item['anexo_obrigatorio'] ? 1 : 0,
                $item['multiplo'] ? 1 : 0,
                $item['tem_outros'] ? 1 : 0,
            ];
            if ($idDet) {
                $updDet->execute(array_merge([$idCat, $ordemDet], $paramsMeta, [(int)$idDet]));
            } else {
                $insDet->execute(array_merge([$label, $idCat, $ordemDet], $paramsMeta));
            }
        }
        $mensagens[] = "✅ Catálogo ITIL v4 sincronizado (assuntos + SLA)";
    } else {
        $mensagens[] = "✅ Catálogo ITIL já sincronizado";
    }
} catch (PDOException $e) {
    $mensagens[] = "⚠️ Seed catálogo ITIL — " . $e->getMessage();
}

// Compat: categoria legada «Nova entrada» (se ainda for usada)
try {
    $stCat = $pdo->prepare("SELECT id FROM ticket_assuntos WHERE titulo = 'Nova entrada' AND (id_pai IS NULL OR id_pai = 0) LIMIT 1");
    $stCat->execute();
    $idNovaEntrada = $stCat->fetchColumn();
    if ($idNovaEntrada) {
        $pdo->prepare("UPDATE ticket_assuntos SET ativo = 1 WHERE id = ?")->execute([(int)$idNovaEntrada]);
    }
} catch (PDOException $e) {
    // ignore
}

// Checklist de Nova entrada (Redes & Sistemas)
executarSql($pdo, "CREATE TABLE IF NOT EXISTS ticket_checklist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_ticket INT NOT NULL,
    item_chave VARCHAR(50) NOT NULL,
    item_rotulo VARCHAR(150) NOT NULL,
    feito TINYINT(1) NOT NULL DEFAULT 0,
    pendente TINYINT(1) NOT NULL DEFAULT 0,
    ordem INT NOT NULL DEFAULT 0,
    id_utilizador INT NULL,
    data_atualizacao DATETIME NULL,
    UNIQUE KEY uniq_ticket_item (id_ticket, item_chave),
    FOREIGN KEY (id_ticket) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (id_utilizador) REFERENCES utilizadores(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Tabela ticket_checklist");

executarSql($pdo, "CREATE TABLE IF NOT EXISTS tentativas_login (
    username VARCHAR(50) PRIMARY KEY,
    tentativas INT NOT NULL DEFAULT 0,
    bloqueado_ate DATETIME NULL,
    ultima_tentativa TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Tabela tentativas_login");

// Tabela recuperação de senha
executarSql($pdo, "CREATE TABLE IF NOT EXISTS password_reset (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_utilizador INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expira_em DATETIME NOT NULL,
    usado TINYINT(1) DEFAULT 0,
    FOREIGN KEY (id_utilizador) REFERENCES utilizadores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Tabela password_reset");

// Tabela notificações
executarSql($pdo, "CREATE TABLE IF NOT EXISTS notificacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_utilizador INT NULL,
    id_area INT NULL,
    id_ticket INT NULL,
    tipo VARCHAR(50) NOT NULL,
    mensagem TEXT NOT NULL,
    lida TINYINT(1) DEFAULT 0,
    data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_utilizador) REFERENCES utilizadores(id) ON DELETE CASCADE,
    FOREIGN KEY (id_area) REFERENCES areas(id) ON DELETE CASCADE,
    FOREIGN KEY (id_ticket) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Tabela notificacoes");

// Operação QCC em falta
try {
    $pdo->exec("INSERT IGNORE INTO operacoes (nome) VALUES ('QCC')");
    $pdo->exec("INSERT IGNORE INTO operacoes (nome) VALUES ('INACOM')");
    $mensagens[] = "✅ Operações QCC e INACOM verificadas";
} catch (PDOException $e) {
    $mensagens[] = "⚠️ Operações — " . $e->getMessage();
}

// Reclassificar artigos KB da categoria VOIP (removida) para Geral / Tutoriais
try {
    $afetados = $pdo->exec("UPDATE kb_artigos SET categoria = 'Geral / Tutoriais' WHERE categoria LIKE '%VOIP%' OR categoria LIKE '%Telecomunicações%'");
    if ($afetados > 0) {
        $mensagens[] = "✅ {$afetados} artigo(s) KB reclassificado(s) de VOIP para Geral / Tutoriais";
    }
} catch (PDOException $e) {
    $mensagens[] = "⚠️ Reclassificação KB VOIP — " . $e->getMessage();
}

// Utilizador sistema para tickets públicos
try {
    $check = $pdo->query("SELECT id FROM utilizadores WHERE username = 'sistema.convidado'")->fetchColumn();
    if (!$check) {
        $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
        $pdo->prepare("INSERT INTO utilizadores (nome, username, password_hash, perfil, estado) VALUES ('Sistema Convidado', 'sistema.convidado', ?, 'Operador', 'Inativo')")
            ->execute([$hash]);
        $mensagens[] = "✅ Utilizador sistema.convidado criado";
    }
} catch (PDOException $e) {
    $mensagens[] = "⚠️ Utilizador sistema — " . $e->getMessage();
}

// Corrigir perfis vazios
try {
    $pdo->exec("UPDATE utilizadores SET perfil = 'Operador' WHERE perfil = '' OR perfil IS NULL");
    $pdo->exec("UPDATE utilizadores SET perfil = 'Operador' WHERE id_operacao IS NOT NULL AND perfil = 'Comum' AND (username LIKE 'cliente.%' OR username LIKE 'operador.%')");
    $mensagens[] = "✅ Perfis vazios corrigidos";
} catch (PDOException $e) {
    $mensagens[] = "⚠️ Perfis — " . $e->getMessage();
}

// Gerar códigos para tickets existentes sem código
try {
    $tickets = $pdo->query("SELECT id FROM tickets WHERE codigo IS NULL OR codigo = '' ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tickets as $tid) {
        $codigo = gerarCodigoTicket($pdo);
        $pdo->prepare("UPDATE tickets SET codigo = ? WHERE id = ?")->execute([$codigo, $tid]);
    }
    if (count($tickets) > 0) {
        $mensagens[] = "✅ Códigos gerados para " . count($tickets) . " ticket(s)";
    }
} catch (PDOException $e) {
    $mensagens[] = "⚠️ Códigos tickets — " . $e->getMessage();
}

// Corrigir SLA inválido
try {
    $pdo->exec("UPDATE tickets SET data_limite_sla = DATE_ADD(data_criacao, INTERVAL 24 HOUR) WHERE data_limite_sla IS NULL OR data_limite_sla = '0000-00-00 00:00:00'");
    $mensagens[] = "✅ SLA inválido corrigido";
} catch (PDOException $e) {
    $mensagens[] = "⚠️ SLA — " . $e->getMessage();
}

// Tabelas autoaprendizagem
executarSql($pdo, "CREATE TABLE IF NOT EXISTS formacao_perguntas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pergunta TEXT NOT NULL,
    opcao_a VARCHAR(255) NOT NULL,
    opcao_b VARCHAR(255) NOT NULL,
    opcao_c VARCHAR(255) NOT NULL,
    resposta_correta ENUM('A','B','C') NOT NULL,
    explicacao TEXT NOT NULL,
    ativo TINYINT(1) DEFAULT 1,
    data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Tabela formacao_perguntas");

executarSql($pdo, "CREATE TABLE IF NOT EXISTS formacao_historico (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_utilizador INT NOT NULL,
    pontuacao INT NOT NULL,
    total_perguntas INT NOT NULL,
    percentagem INT NOT NULL,
    detalhes_json TEXT,
    data_teste TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_utilizador) REFERENCES utilizadores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Tabela formacao_historico");

// Seed perguntas de formação
try {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM formacao_perguntas")->fetchColumn();
    if ($count === 0) {
        $perguntas = [
            ['Quando um computador fica sem internet, o que deve fazer primeiro?', 'Chamar imediatamente o IT.', 'Verificar o cabo de rede ou Wi-Fi.', 'Esperar alguns minutos.', 'B', 'Antes de contactar o suporte, verifique sempre as ligações físicas (cabo) ou se o Wi-Fi está ligado.'],
            ['O que significa reiniciar o computador?', 'Apagar todos os ficheiros.', 'Desligar e ligar novamente para resolver problemas temporários.', 'Formatar o disco.', 'B', 'Reiniciar resolve muitos problemas temporários de software sem perder dados.'],
            ['Recebeu um email suspeito a pedir a sua password. O que faz?', 'Responde com a password.', 'Clica no link para verificar.', 'Não responde e reporta ao IT.', 'C', 'Nunca partilhe passwords por email. Empresas legítimas nunca pedem credenciais assim.'],
            ['Para guardar um documento no Word, usa:', 'Ctrl+S', 'Ctrl+P', 'Ctrl+Z', 'A', 'Ctrl+S guarda o documento. Ctrl+P imprime e Ctrl+Z desfaz.'],
            ['O que é um navegador de internet?', 'Um antivírus.', 'Um programa para aceder à internet (ex: Chrome, Edge).', 'Um tipo de impressora.', 'B', 'Navegadores permitem aceder a páginas web na internet.'],
            ['A password deve ser:', 'A mesma para todos os sistemas.', 'Escrita num post-it no monitor.', 'Única, forte e não partilhada.', 'C', 'Passwords fortes e únicas protegem a sua conta e os dados da empresa.'],
            ['O computador está muito lento. Primeiro passo?', 'Comprar um novo.', 'Verificar programas a correr e espaço em disco.', 'Desligar da tomada sempre.', 'B', 'Programas em segundo plano e pouco espaço em disco são causas comuns de lentidão.'],
            ['O que fazer se a impressora não imprime?', 'Bater na impressora.', 'Verificar papel, tinta e ligação à rede.', 'Desinstalar o Windows.', 'B', 'Verifique papel, tinta/toner e se a impressora está ligada à rede antes de pedir suporte.'],
            ['VPN serve para:', 'Acelerar o computador.', 'Ligação segura à rede da empresa a partir de fora.', 'Imprimir documentos.', 'B', 'VPN cria um túnel seguro para aceder a recursos internos remotamente.'],
            ['Ao sair do posto de trabalho, deve:', 'Deixar tudo aberto.', 'Bloquear o ecrã (Win+L).', 'Desligar o servidor.', 'B', 'Win+L bloqueia o ecrã e impede acesso não autorizado à sua sessão.'],
        ];
        $ins = $pdo->prepare("INSERT INTO formacao_perguntas (pergunta, opcao_a, opcao_b, opcao_c, resposta_correta, explicacao) VALUES (?,?,?,?,?,?)");
        foreach ($perguntas as $p) {
            $ins->execute($p);
        }
        $mensagens[] = "✅ " . count($perguntas) . " perguntas de formação inseridas";
    }
} catch (PDOException $e) {
    $mensagens[] = "⚠️ Formação — " . $e->getMessage();
}

// Banco alargado de perguntas — inserção idempotente (só adiciona as que ainda não existem).
// Assim os testes têm um leque muito maior e não repetem sempre as mesmas perguntas.
try {
    $bancoPerguntas = [
        // --- Segurança informática ---
        ['O que é phishing?', 'Um tipo de vírus físico.', 'Tentativa de enganar o utilizador para roubar dados.', 'Um programa de limpeza de disco.', 'B', 'Phishing engana o utilizador (email/site falso) para obter passwords e dados bancários.'],
        ['Qual destas é a password mais segura?', '123456', 'nome do utilizador', 'Qcc!2026#Rede', 'C', 'Passwords fortes combinam maiúsculas, minúsculas, números e símbolos, sem dados pessoais.'],
        ['Deve escrever a sua password num papel colado ao monitor?', 'Sim, para não esquecer.', 'Não, nunca.', 'Só às vezes.', 'B', 'Nunca exponha passwords. Use um gestor de senhas se precisar de ajuda a memorizar.'],
        ['Recebe um anexo inesperado de um remetente desconhecido. O que faz?', 'Abre para ver o que é.', 'Não abre e reporta ao IT.', 'Reenvia aos colegas.', 'B', 'Anexos inesperados podem conter malware. Confirme sempre a origem antes de abrir.'],
        ['O que é autenticação de dois fatores (2FA)?', 'Duas passwords iguais.', 'Uma segunda verificação além da password (ex: código no telemóvel).', 'Um antivírus.', 'B', 'O 2FA adiciona uma camada extra de segurança para além da password.'],
        ['Um site seguro começa por:', 'http://', 'https://', 'ftp://', 'B', 'O "s" em https indica ligação encriptada, mais segura para introduzir dados.'],

        // --- Hardware e manutenção ---
        ['O que é a RAM de um computador?', 'Memória temporária de trabalho.', 'O disco onde ficam os ficheiros.', 'A placa de vídeo.', 'A', 'A RAM guarda dados temporários enquanto os programas estão a correr.'],
        ['O ecrã do computador não liga, mas a torre está ligada. Primeiro passo?', 'Formatar o disco.', 'Verificar o cabo do monitor e a alimentação.', 'Comprar um monitor novo.', 'B', 'Verifique cabos de vídeo e energia antes de assumir avaria.'],
        ['O que é um SSD?', 'Um tipo de monitor.', 'Um disco de armazenamento mais rápido que o disco tradicional.', 'Um cabo de rede.', 'B', 'O SSD é mais rápido e resistente que o disco rígido mecânico (HDD).'],
        ['O rato deixou de funcionar. O que verifica primeiro?', 'A ligação USB/Bluetooth e as pilhas.', 'A placa gráfica.', 'A password do Windows.', 'A', 'Verifique a ligação e a bateria/pilhas antes de pedir substituição.'],
        ['Para desligar corretamente o computador deve:', 'Retirar a ficha da tomada.', 'Usar o menu Iniciar → Encerrar.', 'Manter o botão premido sempre.', 'B', 'Desligar pelo sistema evita perda de dados e danos no software.'],

        // --- Software e Office ---
        ['Qual atalho copia texto selecionado?', 'Ctrl+C', 'Ctrl+V', 'Ctrl+X', 'A', 'Ctrl+C copia, Ctrl+V cola e Ctrl+X corta.'],
        ['Qual atalho cola o texto copiado?', 'Ctrl+C', 'Ctrl+V', 'Ctrl+Z', 'B', 'Ctrl+V cola o conteúdo previamente copiado.'],
        ['No Excel, uma célula serve para:', 'Guardar um único valor ou fórmula.', 'Imprimir documentos.', 'Aceder à internet.', 'A', 'Cada célula guarda um valor, texto ou fórmula na folha de cálculo.'],
        ['Como desfaz a última ação num documento?', 'Ctrl+Z', 'Ctrl+P', 'Ctrl+S', 'A', 'Ctrl+Z desfaz a última alteração.'],
        ['O que é o PDF?', 'Um formato de documento que preserva a formatação.', 'Um antivírus.', 'Um navegador.', 'A', 'O PDF mantém o aspeto do documento em qualquer dispositivo.'],
        ['Para procurar uma palavra numa página web usa:', 'Ctrl+F', 'Ctrl+B', 'Ctrl+N', 'A', 'Ctrl+F abre a barra de pesquisa na página.'],

        // --- Redes e internet ---
        ['O que é o Wi-Fi?', 'Uma ligação de rede sem fios.', 'Um tipo de impressora.', 'Um programa de email.', 'A', 'O Wi-Fi permite ligar dispositivos à rede sem cabos.'],
        ['O que é um endereço IP?', 'Um identificador de um dispositivo na rede.', 'Uma password.', 'Um tipo de ficheiro.', 'A', 'O IP identifica cada dispositivo numa rede para comunicação.'],
        ['A internet está lenta em todos os computadores. O que faz?', 'Reinicia o router e verifica com o IT.', 'Formata o seu PC.', 'Compra outro computador.', 'A', 'Problemas gerais de rede costumam estar no router/ISP, não num só PC.'],
        ['O que faz um cabo de rede (Ethernet)?', 'Liga o computador à rede por fios.', 'Carrega o telemóvel.', 'Liga o monitor.', 'A', 'O cabo Ethernet fornece ligação de rede com fios, geralmente mais estável.'],

        // --- Boas práticas e procedimentos QCC ---
        ['Quando abre um ticket de suporte, deve descrever:', 'Apenas "não funciona".', 'O problema em detalhe, com o que aconteceu e quando.', 'Nada, o IT adivinha.', 'B', 'Quanto mais detalhe (mensagens de erro, passos), mais rápida é a resolução.'],
        ['Qual a prioridade de um sistema totalmente em baixo que impede o trabalho?', 'Baixa', 'Média', 'Alta', 'C', 'Falhas críticas que param o trabalho devem ser marcadas como prioridade Alta.'],
        ['Onde deve guardar ficheiros de trabalho importantes?', 'Só no ambiente de trabalho local.', 'Nas pastas de rede/backup definidas pela empresa.', 'Num pen drive pessoal.', 'B', 'As pastas de rede têm cópias de segurança; o local não garante recuperação.'],
        ['Antes de contactar o suporte, é boa prática:', 'Reiniciar e reunir detalhes do erro.', 'Desligar o servidor.', 'Apagar programas.', 'A', 'Reiniciar resolve muitos casos; reunir detalhes acelera o diagnóstico.'],
        ['O que deve fazer com emails de "prémios" que pedem dados pessoais?', 'Responder rapidamente.', 'Apagar e/ou reportar como spam.', 'Partilhar com colegas.', 'B', 'São golpes comuns; nunca forneça dados pessoais ou bancários.'],
        ['Perdeu acesso à sua conta por esquecer a password. O que faz?', 'Cria uma conta nova.', 'Usa a recuperação de senha ou contacta o IT.', 'Usa a conta de um colega.', 'B', 'Use a recuperação de senha oficial; nunca partilhe contas.'],
        ['Fez alterações importantes num documento. Deve:', 'Fechar sem guardar.', 'Guardar regularmente (Ctrl+S).', 'Desligar o PC.', 'B', 'Guardar com frequência evita perda de trabalho por falhas inesperadas.'],
        ['O que é uma cópia de segurança (backup)?', 'Uma segunda via dos dados para recuperação.', 'Um vírus.', 'Um programa de desenho.', 'A', 'O backup permite recuperar dados em caso de falha ou perda.'],
        ['Um colega pede a sua password para "resolver algo rápido". Você:', 'Dá a password.', 'Recusa e sugere que ele use a conta dele.', 'Escreve num papel.', 'B', 'Credenciais são pessoais e intransmissíveis, mesmo entre colegas.'],
        ['O que fazer ao notar comportamento estranho no PC (lentidão súbita, pop-ups)?', 'Ignorar.', 'Reportar ao IT — pode ser malware.', 'Continuar a usar normalmente.', 'B', 'Sinais estranhos podem indicar infeção; reporte para análise.'],
    ];

    $existe = $pdo->prepare("SELECT COUNT(*) FROM formacao_perguntas WHERE pergunta = ?");
    $insBanco = $pdo->prepare("INSERT INTO formacao_perguntas (pergunta, opcao_a, opcao_b, opcao_c, resposta_correta, explicacao) VALUES (?,?,?,?,?,?)");
    $novas = 0;
    foreach ($bancoPerguntas as $p) {
        $existe->execute([$p[0]]);
        if ((int)$existe->fetchColumn() === 0) {
            $insBanco->execute($p);
            $novas++;
        }
    }
    if ($novas > 0) {
        $mensagens[] = "✅ $novas novas perguntas de formação adicionadas";
    } else {
        $mensagens[] = "✅ Banco de perguntas de formação já atualizado";
    }
} catch (PDOException $e) {
    $mensagens[] = "⚠️ Banco de perguntas — " . $e->getMessage();
}

// Remover perfil Comum (legado): migrar contas existentes e actualizar ENUM
try {
    $pdo->exec("UPDATE utilizadores SET perfil = 'Operador' WHERE perfil = 'Comum'");
    $pdo->exec("UPDATE utilizadores SET perfil = 'Operador' WHERE username = 'sistema.convidado'");
    $mensagens[] = "✅ Perfis Comum migrados para Operador";
} catch (PDOException $e) {
    $mensagens[] = "⚠️ Migração Comum→Operador — " . $e->getMessage();
}
executarSql(
    $pdo,
    "ALTER TABLE utilizadores MODIFY COLUMN perfil ENUM('Admin','Diretor Geral','Responsavel','Tecnico','Operador') NOT NULL",
    "Enum de perfil sem Comum (removido)"
);

// =========================================================
// PERFIS DINÂMICOS + GRUPOS DE PERMISSÃO (modelo híbrido)
// =========================================================
require_once __DIR__ . '/includes/permissoes.php';
try {
    garantirSchemaPerfis($pdo);
    $mensagens[] = "✅ Schema de perfis / grupos / permissões individuais garantido e seed aplicado";
} catch (Throwable $e) {
    $mensagens[] = "⚠️ Perfis/grupos — " . $e->getMessage();
}

// Áreas de Supervisão por operação (Supervisão AFRICELL, Supervisão BAI, …)
try {
    garantirAreasSupervisao($pdo, true);
    $nSup = (int)$pdo->query("SELECT COUNT(*) FROM operacoes WHERE id_area_supervisao IS NOT NULL")->fetchColumn();
    $mensagens[] = "✅ Áreas de Supervisão por operação garantidas ({$nSup} operações ligadas)";
} catch (Throwable $e) {
    $mensagens[] = "⚠️ Áreas de Supervisão — " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atualizar Base de Dados - KIAMI</title>
    <link rel="stylesheet" href="style.css">
    <script src="tema.js"></script></head>
<body style="padding: 40px; max-width: 700px; margin: 0 auto;">
    <div class="card">
        <h1 style="color: var(--accent); margin-bottom: 20px;">Atualização da Base de Dados</h1>
        <ul style="line-height: 2; color: var(--text-secondary);">
            <?php foreach ($mensagens as $msg): ?>
                <li><?php echo htmlspecialchars($msg); ?></li>
            <?php endforeach; ?>
        </ul>
        <p style="margin-top: 25px;">
            <a href="index.php" style="color: var(--accent);">Ir para o Painel</a> |
            <a href="formacao.php" style="color: var(--accent);">Autoaprendizagem</a>
        </p>
        <p style="margin-top: 15px;">
            <a href="logout.php" class="btn-danger" style="display:inline-block; width:auto; padding:10px 20px;">🚪 Terminar Sessão</a>
        </p>
    </div>
</body>
</html>
