<?php
/**
 * KIAMI — Módulo de autoaprendizagem
 *
 * Quiz com 5 perguntas aleatórias (banco com 40+ perguntas após atualizar_banco.php).
 * Guarda histórico em formacao_historico com pontuação e explicações.
 */
require_once 'conexao.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$nome_usuario = $_SESSION['nome'];
$perfil_usuario = $_SESSION['perfil'];
$contexto = obterContextoUsuario($pdo);
$user_id_num = $contexto['user_id_numerico'];

$mensagem = '';
$resultado = null;

// Submeter respostas do teste
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_submeter_teste'])) {
    $respostas = $_POST['resposta'] ?? [];
    $ids = array_map('intval', array_keys($respostas));

    if (count($ids) < 1) {
        $mensagem = 'Responda às perguntas antes de submeter.';
    } else {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM formacao_perguntas WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $perguntas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $corretas = 0;
        $total = count($perguntas);
        $detalhes = [];

        foreach ($perguntas as $p) {
            $resp = $respostas[$p['id']] ?? '';
            $acertou = strtoupper($resp) === strtoupper($p['resposta_correta']);
            if ($acertou) $corretas++;
            $detalhes[] = [
                'pergunta' => $p['pergunta'],
                'sua_resposta' => $resp,
                'correta' => $p['resposta_correta'],
                'explicacao' => $p['explicacao'],
                'acertou' => $acertou,
            ];
        }

        $percentagem = $total > 0 ? round(($corretas / $total) * 100) : 0;
        $msgMotivacional = match (true) {
            $percentagem === 100 => 'Excelente! Domina estes conceitos à perfeição!',
            $percentagem >= 80 => 'Muito bom! Continue a aprender e a melhorar.',
            $percentagem >= 60 => 'Bom trabalho! Revise as explicações para reforçar.',
            $percentagem >= 40 => 'Não desanime! A prática leva à perfeição.',
            default => 'Continue a tentar! Cada teste é uma oportunidade de aprender.',
        };

        try {
            $pdo->prepare("INSERT INTO formacao_historico (id_utilizador, pontuacao, total_perguntas, percentagem, detalhes_json) VALUES (?, ?, ?, ?, ?)")
                ->execute([$user_id_num, $corretas, $total, $percentagem, json_encode($detalhes, JSON_UNESCAPED_UNICODE)]);
        } catch (PDOException $e) {}

        $resultado = [
            'corretas' => $corretas,
            'total' => $total,
            'percentagem' => $percentagem,
            'mensagem' => $msgMotivacional,
            'detalhes' => $detalhes,
        ];
    }
}

// Carregar 5 perguntas aleatórias (se não estiver a ver resultado)
$perguntasTeste = [];
if (!$resultado) {
    try {
        $perguntasTeste = $pdo->query("SELECT * FROM formacao_perguntas WHERE ativo = 1 ORDER BY RAND() LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $perguntasTeste = [];
    }
}

// Histórico do utilizador
$historico = [];
try {
    $stmtH = $pdo->prepare("SELECT * FROM formacao_historico WHERE id_utilizador = ? ORDER BY id DESC LIMIT 10");
    $stmtH->execute([$user_id_num]);
    $historico = $stmtH->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <title>KIAMI - Autoaprendizagem</title>
    <link rel="stylesheet" href="style.css">
    <script src="tema.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"></head>
<body>
<div class="app-layout">
    <div id="sidebar">
        <div class="sidebar-brand"><h3>KIAMI</h3><span>Suporte Quality</span></div>
        <div class="nav-section-title">Geral</div>
        <a href="index.php" class="nav-item">📊 <span>Painel</span></a>
        <a href="tickets_lista.php" class="nav-item">🎫 <span>Tickets</span></a>
        <a href="kb_lista.php" class="nav-item">📚 <span>Base Conhecimento</span></a>
        <a href="formacao.php" class="nav-item active">🎓 <span>Autoaprendizagem</span></a>
        <a href="empresa.php" class="nav-item">🏢 <span>A Empresa</span></a>
            <?php echo htmlNavNotificacoes((int)($notif_pendentes ?? 0)); ?>
        <?php if (podeAcederAdministracao($contexto)): ?>
            <div class="nav-section-title">Administração</div>
            <a href="usuarios_lista.php" class="nav-item">👥 <span>Gestão de Utilizadores</span></a>
            <a href="equipa_online.php" class="nav-item">🟢 <span>Equipa Disponível</span></a>
            <a href="assuntos_lista.php" class="nav-item">📋 <span>Assuntos de Ticket</span></a>
                <a href="emails_areas.php" class="nav-item">✉️ <span>Emails das Áreas</span></a>
            <a href="relatorios.php" class="nav-item">📈 <span>Relatórios</span></a>
            <?php if (podeAcederAuditoria($contexto)): ?>
            <a href="auditoria.php" class="nav-item">🔍 <span>Auditoria</span></a>
            <?php endif; ?>
        <?php elseif ($perfil_usuario === 'Diretor Geral'): ?>
            <div class="nav-section-title">Consulta</div>
            <a href="relatorios.php" class="nav-item">📈 <span>Relatórios</span></a>
        <?php endif; ?>
        <div class="sidebar-footer">
            <div class="user-badge">
                <div style="font-size: 14px; font-weight: 600;"><?php echo htmlspecialchars($nome_usuario); ?></div>
                <div style="font-size: 11px; color: var(--accent); font-weight: 600; margin-top: 4px;">⚙️ <?php echo htmlspecialchars($perfil_usuario); ?></div>
            </div>
<?php if (idUtilizadorNumerico()): ?>
            <a href="alterar_senha.php" style="display:block; text-align:center; margin-bottom:8px; padding:9px; background:var(--bg-input); color:var(--text-primary); text-decoration:none; border-radius:var(--radius-sm); font-size:13px; border:1px solid var(--border);">🔑 Alterar Palavra-passe</a>
            <?php endif; ?>
            <a href="logout.php" class="btn-danger">🚪 Sair do Sistema</a>
        </div>
    </div>

    <div id="main-content">
        <div class="page-header">
            <h1>🎓 Área de Autoaprendizagem</h1>
            <p>Teste os seus conhecimentos de informática básica. Cada teste tem 5 perguntas escolhidas aleatoriamente de um banco alargado.</p>
        </div>

        <?php if ($resultado): ?>
            <div class="card" style="border-top: 3px solid var(--accent); margin-bottom: 25px;">
                <h2 style="color: #fff; margin-bottom: 15px;">Resultado do Teste</h2>
                <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 20px;">
                    <div class="stat-card" style="flex:1; min-width:120px;">
                        <span class="label">Pontuação</span>
                        <span class="value"><?php echo $resultado['corretas']; ?>/<?php echo $resultado['total']; ?></span>
                    </div>
                    <div class="stat-card" style="flex:1; min-width:120px;">
                        <span class="label">Percentagem</span>
                        <span class="value" style="color: var(--green);"><?php echo $resultado['percentagem']; ?>%</span>
                    </div>
                </div>
                <p style="color: var(--accent); font-size: 16px; font-weight: 600; margin-bottom: 20px;"><?php echo htmlspecialchars($resultado['mensagem']); ?></p>

                <?php foreach ($resultado['detalhes'] as $i => $d): ?>
                    <div class="card" style="margin-bottom: 12px; background: <?php echo $d['acertou'] ? 'rgba(16,185,129,0.05)' : 'rgba(239,68,68,0.05)'; ?>; border-left: 3px solid <?php echo $d['acertou'] ? 'var(--green)' : 'var(--red)'; ?>;">
                        <p style="font-weight: 600; color: #fff; margin-bottom: 8px;"><?php echo ($i+1); ?>. <?php echo htmlspecialchars($d['pergunta']); ?></p>
                        <?php if (!$d['acertou']): ?>
                            <p style="font-size: 13px; color: var(--red);">A sua resposta: <?php echo htmlspecialchars($d['sua_resposta']); ?></p>
                            <p style="font-size: 13px; color: var(--green);">Resposta correta: <?php echo htmlspecialchars($d['correta']); ?></p>
                            <p style="font-size: 13px; color: var(--text-secondary); margin-top: 6px;"><?php echo htmlspecialchars($d['explicacao']); ?></p>
                        <?php else: ?>
                            <p style="font-size: 13px; color: var(--green);">✓ Resposta correta!</p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <a href="formacao.php" style="display: inline-block; margin-top: 15px; padding: 10px 20px; background: var(--accent); color: #fff; text-decoration: none; border-radius: var(--radius-sm); font-weight: 600;">Fazer Novo Teste</a>
            </div>

        <?php elseif (empty($perguntasTeste)): ?>
            <div class="card">
                <p style="color: var(--text-muted);">Nenhuma pergunta disponível. Execute <a href="atualizar_banco.php" style="color: var(--accent);">atualizar_banco.php</a> para carregar o conteúdo.</p>
            </div>
        <?php else: ?>
            <form method="POST" class="card">
                <h3 style="color: var(--accent); margin-bottom: 20px;">Teste — <?php echo count($perguntasTeste); ?> perguntas</h3>
                <?php foreach ($perguntasTeste as $i => $p): ?>
                    <div style="margin-bottom: 25px; padding-bottom: 20px; border-bottom: 1px solid var(--border);">
                        <p style="font-weight: 600; color: #fff; margin-bottom: 12px;"><?php echo ($i+1); ?>. <?php echo htmlspecialchars($p['pergunta']); ?></p>
                        <?php foreach (['A' => $p['opcao_a'], 'B' => $p['opcao_b'], 'C' => $p['opcao_c']] as $letra => $texto): ?>
                            <label style="display: block; padding: 8px 12px; margin-bottom: 6px; background: var(--bg-input); border-radius: var(--radius-sm); cursor: pointer; font-size: 14px; color: var(--text-secondary);">
                                <input type="radio" name="resposta[<?php echo $p['id']; ?>]" value="<?php echo $letra; ?>" required style="margin-right: 8px;">
                                <b><?php echo $letra; ?>)</b> <?php echo htmlspecialchars($texto); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <button type="submit" name="btn_submeter_teste" style="padding: 12px 24px; background: var(--accent); border: none; color: #fff; border-radius: var(--radius-sm); font-weight: 600; cursor: pointer;">Submeter Teste</button>
            </form>
        <?php endif; ?>

        <?php if (!empty($historico)): ?>
        <div class="card" style="margin-top: 25px;">
            <h3 style="color: var(--text-secondary); margin-bottom: 15px;">📋 Histórico dos seus testes</h3>
            <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--border); color: var(--text-muted);">
                        <th style="padding: 10px; text-align: left;">Data</th>
                        <th style="padding: 10px; text-align: center;">Pontuação</th>
                        <th style="padding: 10px; text-align: center;">Percentagem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historico as $h): ?>
                    <tr style="border-bottom: 1px solid var(--border);">
                        <td style="padding: 10px;"><?php echo date('d/m/Y H:i', strtotime($h['data_teste'])); ?></td>
                        <td style="padding: 10px; text-align: center;"><?php echo $h['pontuacao']; ?>/<?php echo $h['total_perguntas']; ?></td>
                        <td style="padding: 10px; text-align: center; color: var(--green); font-weight: 600;"><?php echo $h['percentagem']; ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
    <script src="notificacoes.js"></script>
</body>
</html>
