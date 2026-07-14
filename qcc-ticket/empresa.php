<?php
/**
 * KIAMI — Página institucional "A Empresa"
 * Informação sobre a Quality Contact Center (conteúdo estático).
 */
require_once 'conexao.php';
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
$nome_usuario = $_SESSION['nome'];
$perfil_usuario = $_SESSION['perfil'];
$contexto = obterContextoUsuario($pdo);
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <title>KIAMI - A Empresa</title>
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
            <a href="formacao.php" class="nav-item">🎓 <span>Autoaprendizagem</span></a>
            <a href="empresa.php" class="nav-item active">🏢 <span>A Empresa</span></a>
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
                    <div style="font-size: 14px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo htmlspecialchars($nome_usuario); ?></div>
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
                <h1>Sobre a Quality Contact Center</h1>
                <p>Informações institucionais, cultura organizacional e canais de comunicação interna.</p>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px; align-items: start;">
                <div class="card" style="line-height: 1.8; grid-column: 1 / -1;">
                    <h3 style="color: var(--accent); margin-bottom: 10px;">Quem Somos</h3>
                    <p style="color: var(--text-secondary); margin-bottom: 0;">
                        A <b>Quality Contact Center (QCC)</b> é referência em Angola na gestão de contact centers,
                        suporte técnico e operações críticas de atendimento ao cliente. Com equipas multidisciplinares
                        em Redes &amp; Sistemas, Desenvolvimento e áreas de suporte, garantimos respostas rápidas,
                        processos claros e acompanhamento próximo de cada pedido — dentro e fora da organização.
                    </p>
                </div>

                <div class="card" style="line-height: 1.8; border-top: 3px solid var(--accent);">
                    <h3 style="color: var(--accent); margin-bottom: 10px;">🎯 Missão</h3>
                    <p style="color: var(--text-secondary); margin: 0;">
                        Aproximar fornecedores, clientes e potenciais clientes, estabelecendo uma relação de confiança
                        e gerando valor através da prestação de um serviço de qualidade.
                    </p>
                </div>

                <div class="card" style="line-height: 1.8; border-top: 3px solid var(--green);">
                    <h3 style="color: var(--green); margin-bottom: 10px;">🔭 Visão</h3>
                    <p style="color: var(--text-secondary); margin: 0;">
                        Ser o factor crítico do sucesso dos nossos parceiros.
                    </p>
                </div>

                <div class="card" style="line-height: 1.8; grid-column: 1 / -1;">
                    <h3 style="color: var(--accent); margin-bottom: 15px;">⭐ Valores</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                        <div style="padding: 14px; background: var(--bg-input); border-radius: var(--radius-sm); border-left: 3px solid var(--accent);">
                            <strong style="color: #fff;">Qualidade</strong>
                            <p style="color: var(--text-secondary); font-size: 13px; margin: 6px 0 0;">Compromisso com a excelência em cada serviço prestado e em cada interação com os nossos parceiros.</p>
                        </div>
                        <div style="padding: 14px; background: var(--bg-input); border-radius: var(--radius-sm); border-left: 3px solid var(--green);">
                            <strong style="color: #fff;">Integridade</strong>
                            <p style="color: var(--text-secondary); font-size: 13px; margin: 6px 0 0;">Transparência, ética e respeito pela confiança depositada em nós.</p>
                        </div>
                        <div style="padding: 14px; background: var(--bg-input); border-radius: var(--radius-sm); border-left: 3px solid var(--amber);">
                            <strong style="color: #fff;">Compromisso</strong>
                            <p style="color: var(--text-secondary); font-size: 13px; margin: 6px 0 0;">Dedicação total ao cumprimento das responsabilidades assumidas com clientes e parceiros.</p>
                        </div>
                        <div style="padding: 14px; background: var(--bg-input); border-radius: var(--radius-sm); border-left: 3px solid #8b5cf6;">
                            <strong style="color: #fff;">Sigilo</strong>
                            <p style="color: var(--text-secondary); font-size: 13px; margin: 6px 0 0;">Proteção rigorosa da informação confidencial de clientes, fornecedores e da organização.</p>
                        </div>
                    </div>
                </div>

                <div class="card" style="line-height: 1.8;">
                    <h3 style="color: var(--accent); margin-bottom: 12px;">Contactos Rápidos</h3>
                    <p style="color: var(--text-secondary); margin-bottom: 12px;">
                        <b>E-mail geral:</b> helpdesk@quality.co.ao<br>
                        <b>Suporte técnico:</b> suporte@quality.co.ao<br>
                        <b>Website:</b> www.quality.co.ao
                    </p>
                    <p style="color: var(--text-secondary); margin: 0; padding-top: 12px; border-top: 1px solid var(--border);">
                        <b>Sala Técnica — Extensões internas:</b><br>
                        <span style="color: var(--accent); font-weight: 700; font-size: 18px;">641</span> — Redes &amp; Sistemas<br>
                        <span style="color: var(--accent); font-weight: 700; font-size: 18px;">642</span> — Desenvolvimento
                    </p>
                </div>

                <div class="card" style="line-height: 1.8;">
                    <h3 style="color: var(--accent); margin-bottom: 12px;">Horário &amp; SLA</h3>
                    <p style="color: var(--text-secondary); margin-bottom: 10px;">
                        <b>Atendimento interno / externo — Redes e Sistemas:</b> Segunda a Segunda, 24h00 – 24h00<br>
                        <b>Atendimento interno:</b> Segunda a Sexta, 08h00 – 17h00<br>
                        <b>Desenvolvimento:</b> Segunda a Sexta, 08h00 – 17h00<br>
                        <b>Plantão crítico:</b> conforme escala da equipa técnica
                    </p>
                    <ul style="color: var(--text-secondary); font-size: 14px; margin: 0; padding-left: 18px;">
                        <li>Prioridade <b style="color: var(--red);">Alta</b> — resposta em até 4 horas</li>
                        <li>Prioridade <b style="color: var(--amber);">Média</b> — resposta em até 24 horas</li>
                        <li>Prioridade <b style="color: var(--green);">Baixa</b> — resposta em até 72 horas</li>
                    </ul>
                </div>

                <div class="card" style="line-height: 1.8; grid-column: 1 / -1;">
                    <h3 style="color: var(--accent); margin-bottom: 12px;">Sabia que…</h3>
                    <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 15px;">Curiosidades e dados úteis sobre a QCC e o KIAMI.</p>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px;">
                        <?php
                        $curiosidades = [
                            'O lema da QCC é: «Mais do que procura, exactamente o que precisa!»',
                            'O KIAMI gera códigos automáticos no formato QCC-AAAA-NNNNNN para cada pedido.',
                            'A equipa de Redes & Sistemas trata VPN, conectividade, servidores e segurança de rede.',
                            'A área de Desenvolvimento mantém integrações, portais e melhorias nas aplicações internas.',
                            'Pode abrir um ticket sem login em «Abrir ticket sem login» na página de entrada.',
                            'A Base de Conhecimento reúne tutoriais aprovados pela equipa técnica.',
                            'O módulo de Autoaprendizagem ajuda a reforçar boas práticas de informática no dia a dia.',
                            'Clientes e operações (ENSA, BAI, AFRICELL, INACOM, etc.) podem aceder com o nome da operação (perfil Operador) ou criar conta.',
                            'Áreas administrativas (RH, Finanças, Formadores…) entram com o nome da área no login.',
                            'Senhas iniciais devem ser alteradas no primeiro acesso por razões de segurança.',
                        ];
                        shuffle($curiosidades);
                        foreach (array_slice($curiosidades, 0, 6) as $item):
                        ?>
                        <div style="padding: 12px 14px; background: rgba(59,130,246,0.06); border-radius: var(--radius-sm); font-size: 13px; color: var(--text-secondary); border: 1px solid var(--border);">
                            <?php echo htmlspecialchars($item); ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <p style="color: var(--text-muted); font-size: 11px; margin-top: 12px; font-style: italic;">As curiosidades são apresentadas de forma aleatória a cada visita à página.</p>
                </div>

                <div class="card" style="line-height: 1.8; grid-column: 1 / -1;">
                    <h3 style="color: var(--accent); margin-bottom: 12px;">Estrutura &amp; Operações</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; color: var(--text-secondary); font-size: 14px;">
                        <div>
                            <strong style="color: #fff;">Áreas Técnicas</strong>
                            <ul style="margin: 8px 0 0; padding-left: 18px;">
                                <li>Redes &amp; Sistemas</li>
                                <li>Desenvolvimento</li>
                            </ul>
                        </div>
                        <div>
                            <strong style="color: #fff;">Áreas de Suporte</strong>
                            <ul style="margin: 8px 0 0; padding-left: 18px;">
                                <li>Recursos Humanos</li>
                                <li>Finanças</li>
                                <li>Qualidade &amp; Formação</li>
                                <li>Formadores</li>
                                <li>Comercial</li>
                            </ul>
                        </div>
                        <div>
                            <strong style="color: #fff;">Operações</strong>
                            <ul style="margin: 8px 0 0; padding-left: 18px;">
                                <li>ENSA · BAI · AFRICELL</li>
                                <li>INACOM · MULTICHOICE · Q-EASY</li>
                                <li>QCC e outras operações</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="notificacoes.js"></script>
</body>
</html>
