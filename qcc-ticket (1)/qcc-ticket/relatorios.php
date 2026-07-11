<?php
require_once 'conexao.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$nome_usuario = $_SESSION['nome'];
$perfil_usuario = $_SESSION['perfil'];

// BLOQUEIO DE SEGURANÇA: Apenas perfis de gestão podem ver relatórios estatísticos
if (!in_array($perfil_usuario, ['Admin', 'Diretor Geral', 'Responsavel'])) {
    die("Acesso negado. Não tem permissões para visualizar relatórios estatísticos.");
}

// =========================================================
// MÉTRICA 1: ADQUIRIR ESTATÍSTICAS POR ÁREA TÉCNICA
// =========================================================
$query_areas = "
    SELECT a.nome AS area,
           COUNT(t.id) AS total,
           SUM(CASE WHEN t.estado = 'Aberto' THEN 1 ELSE 0 END) AS abertos,
           SUM(CASE WHEN t.estado = 'Em Progresso' THEN 1 ELSE 0 END) AS progresso,
           SUM(CASE WHEN t.estado IN ('Resolvido', 'Fechado') THEN 1 ELSE 0 END) AS resolvidos
    FROM areas a
    LEFT JOIN tickets t ON t.id_area_destino = a.id
    GROUP BY a.id, a.nome
    ORDER BY total DESC
";
$relatorio_areas = $pdo->query($query_areas)->fetchAll(PDO::FETCH_ASSOC);

// =========================================================
// MÉTRICA 2: ADQUIRIR ESTATÍSTICAS POR CLIENTE OPERACIONAL
// =========================================================
$query_operacoes = "
    SELECT o.nome AS operacao,
           COUNT(t.id) AS total,
           SUM(CASE WHEN t.estado IN ('Resolvido', 'Fechado') THEN 1 ELSE 0 END) AS resolvidos
    FROM operacoes o
    LEFT JOIN tickets t ON t.id_operacao_origem = o.id
    GROUP BY o.id, o.nome
    ORDER BY total DESC
";
$relatorio_operacoes = $pdo->query($query_operacoes)->fetchAll(PDO::FETCH_ASSOC);

// =========================================================
// MÉTRICA 3: PERFORMANCE INDIVIDUAL DOS TÉCNICOS
// =========================================================
$query_tecnicos = "
    SELECT u.nome AS tecnico,
           COUNT(t.id) AS atribuidos,
           SUM(CASE WHEN t.estado IN ('Resolvido', 'Fechado') THEN 1 ELSE 0 END) AS concluidos
    FROM utilizadores u
    LEFT JOIN tickets t ON t.id_tecnico_atribuido = u.id
    WHERE u.perfil = 'Tecnico' AND u.estado = 'Ativo'
    GROUP BY u.id, u.nome
    ORDER BY concluidos DESC, atribuidos DESC
";
$relatorio_tecnicos = $pdo->query($query_tecnicos)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <title>QCCTICKET - Relatórios e Métricas</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="app-layout">
        <!-- SIDEBAR -->
        <div id="sidebar">
            <div class="sidebar-brand"><h3>QCCTICKET</h3><span>Quality Support</span></div>
            <div class="nav-section-title">Geral</div>
            <a href="index.php" class="nav-item">📊 <span>Dashboard</span></a>
            <a href="tickets_lista.php" class="nav-item">🎫 <span>Meus Tickets</span></a>
            <a href="kb_lista.php" class="nav-item">📚 <span>Base Conhecimento</span></a>
            <a href="empresa.php" class="nav-item">🏢 <span>A Empresa</span></a>
            
            <div class="nav-section-title">Administração</div>
            <?php if ($perfil_usuario === 'Admin'): ?>
                <a href="usuarios_lista.php" class="nav-item">👥 <span>Gestão de Users</span></a>
            <?php endif; ?>
            <a href="relatorios.php" class="nav-item active">📈 <span>Relatórios</span></a>

            <div class="sidebar-footer">
                <div class="user-badge">
                    <div style="font-size: 14px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo htmlspecialchars($nome_usuario); ?></div>
                    <div style="font-size: 11px; color: var(--accent); font-weight: 600; margin-top: 4px;">⚙️ <?php echo htmlspecialchars($perfil_usuario); ?></div>
                </div>
                <a href="logout.php" class="btn-danger">Sair do Sistema</a>
            </div>
        </div>

        <!-- CONTEÚDO PRINCIPAL -->
        <div id="main-content">
            <div class="page-header">
                <h1>Relatórios e Auditoria</h1>
                <p>Analise a distribuição de carga de trabalho, volumetria de clientes e eficiência operacional das equipas.</p>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin-bottom: 25px;">
                
                <!-- TABELA 1: DISTRIBUIÇÃO POR ÁREA -->
                <div class="card" style="padding: 0; overflow: hidden;">
                    <div style="padding: 15px 20px; background: rgba(255,255,255,0.02); border-bottom: 1px solid var(--border);">
                        <h3 style="font-size: 15px; color: var(--accent);">🏢 Desempenho por Área Técnica</h3>
                    </div>
                    <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
                        <thead>
                            <tr style="background: var(--bg-sidebar); border-bottom: 1px solid var(--border); color: var(--text-secondary);">
                                <th style="padding: 12px 20px;">Departamento</th>
                                <th style="padding: 12px; text-align: center;">Total</th>
                                <th style="padding: 12px; text-align: center; color: #3b82f6;">Abertos</th>
                                <th style="padding: 12px; text-align: center; color: var(--amber);">Progresso</th>
                                <th style="padding: 12px; text-align: center; color: var(--green);">Resolvidos</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($relatorio_areas as $ra): ?>
                                <tr style="border-bottom: 1px solid var(--border);">
                                    <td style="padding: 12px 20px; font-weight: 500; color: #fff;"><?php echo htmlspecialchars($ra['area']); ?></td>
                                    <td style="padding: 12px; text-align: center; font-weight: bold;"><?php echo $ra['total']; ?></td>
                                    <td style="padding: 12px; text-align: center; color: var(--text-secondary);"><?php echo $ra['abertos']; ?></td>
                                    <td style="padding: 12px; text-align: center; color: var(--text-secondary);"><?php echo $ra['progresso']; ?></td>
                                    <td style="padding: 12px; text-align: center; color: var(--green); font-weight: 500;"><?php echo $ra['resolvidos']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- TABELA 2: VOLUMETRIA POR OPERAÇÃO CLIENTE -->
                <div class="card" style="padding: 0; overflow: hidden;">
                    <div style="padding: 15px 20px; background: rgba(255,255,255,0.02); border-bottom: 1px solid var(--border);">
                        <h3 style="font-size: 15px; color: var(--amber);">📱 Volumetria por Cliente Operacional</h3>
                    </div>
                    <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
                        <thead>
                            <tr style="background: var(--bg-sidebar); border-bottom: 1px solid var(--border); color: var(--text-secondary);">
                                <th style="padding: 12px 20px;">Cliente / Parceiro</th>
                                <th style="padding: 12px; text-align: center;">Chamados Gerados</th>
                                <th style="padding: 12px; text-align: center; color: var(--green);">Casos Concluídos</th>
                                <th style="padding: 12px; text-align: center;">Taxa Resolução</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($relatorio_operacoes as $ro): ?>
                                <?php 
                                    $taxa = $ro['total'] > 0 ? round(($ro['resolvidos'] / $ro['total']) * 100) : 0;
                                ?>
                                <tr style="border-bottom: 1px solid var(--border);">
                                    <td style="padding: 12px 20px; font-weight: 500; color: #fff;">📱 <?php echo htmlspecialchars($ro['operacao']); ?></td>
                                    <td style="padding: 12px; text-align: center; font-weight: 600;"><?php echo $ro['total']; ?></td>
                                    <td style="padding: 12px; text-align: center; color: var(--green);"><?php echo $ro['resolvidos']; ?></td>
                                    <td style="padding: 12px; text-align: center;">
                                        <span style="background: rgba(16,185,129,0.1); color: var(--green); padding: 2px 6px; border-radius: 4px; font-weight: 600; font-size: 11px;"><?php echo $taxa; ?>%</span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            </div>

            <!-- TABELA 3: EFICIÊNCIA DOS TÉCNICOS -->
            <div class="card" style="padding: 0; overflow: hidden;">
                <div style="padding: 15px 20px; background: rgba(255,255,255,0.02); border-bottom: 1px solid var(--border);">
                    <h3 style="font-size: 15px; color: var(--green);">🎯 Ranking de Produtividade dos Técnicos</h3>
                </div>
                <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
                    <thead>
                        <tr style="background: var(--bg-sidebar); border-bottom: 1px solid var(--border); color: var(--text-secondary);">
                            <th style="padding: 15px 20px;">Nome do Especialista Técnico</th>
                            <th style="padding: 15px; text-align: center;">Total de Casos Atribuídos</th>
                            <th style="padding: 15px; text-align: center; color: var(--green);">Casos Resolvidos com Sucesso</th>
                            <th style="padding: 15px; text-align: center;">Performance Relativa</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($relatorio_tecnicos)): ?>
                            <tr>
                                <td colspan="4" style="padding: 20px; text-align: center; color: var(--text-muted);">Nenhum técnico registado ou com tarefas associadas no momento.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($relatorio_tecnicos as $rt): ?>
                            <?php 
                                $barra = $rt['atribuidos'] > 0 ? round(($rt['concluidos'] / $rt['atribuidos']) * 100) : 0;
                            ?>
                            <tr style="border-bottom: 1px solid var(--border);">
                                <td style="padding: 15px 20px; font-weight: 500; color: #fff;">🛠️ <?php echo htmlspecialchars($rt['tecnico']); ?></td>
                                <td style="padding: 15px; text-align: center; font-weight: 600; color: var(--text-primary);"><?php echo $rt['atribuidos']; ?></td>
                                <td style="padding: 15px; text-align: center; color: var(--green); font-weight: 600;"><?php echo $rt['concluidos']; ?></td>
                                <td style="padding: 15px; width: 30%;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <div style="flex-grow: 1; height: 6px; background: var(--bg-input); border-radius: 3px; overflow: hidden;">
                                            <div style="width: <?php echo $barra; ?>%; height: 100%; background: var(--green); border-radius: 3px;"></div>
                                        </div>
                                        <span style="font-size: 11px; font-weight: 600; color: var(--text-secondary); width: 35px; text-align: right;"><?php echo $barra; ?>%</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</body>
</html>