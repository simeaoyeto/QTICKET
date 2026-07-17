<?php
/**
 * KIAMI — Base de Conhecimento (KB)
 *
 * Listagem, criação e edição de artigos de ajuda.
 * Admin, Responsáveis e Técnicos (Redes/Desenvolvimento) podem publicar, editar e eliminar.
 */
require_once 'conexao.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$nome_usuario = $_SESSION['nome'];
$perfil_usuario = $_SESSION['perfil'];
$contexto = obterContextoUsuario($pdo);
$user_id_num = $contexto['user_id_numerico'];
$mensagem = '';

$podeGerirKb = podeGerirKb($contexto);

// Compatibilidade com nome antigo usado no HTML
$podePublicarKb = $podeGerirKb;

garantirSchemaKbVisibilidade($pdo);
$opcoesVisibilidadeKb = obterOpcoesVisibilidadeKb();

// Artigo a editar (preenche o formulário em modo edição)
$artigoEditar = null;

// =========================================================
// PROCESSAR REMOÇÃO DE ARTIGO (DELETE)
// =========================================================
if (isset($_GET['acao']) && $_GET['acao'] === 'remover' && isset($_GET['id'])) {
    $id_artigo = (int)$_GET['id'];

    if ($podeGerirKb) {
        $stmt_del = $pdo->prepare("DELETE FROM kb_artigos WHERE id = ?");
        $stmt_del->execute([$id_artigo]);
        registarAuditoria($pdo, 'Exclusão', "Artigo KB #$id_artigo removido");
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Artigo removido com sucesso!</div>";
    } else {
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: Não tem permissão para apagar artigos.</div>";
    }
}

// =========================================================
// CARREGAR ARTIGO PARA EDIÇÃO (GET)
// =========================================================
if (isset($_GET['acao']) && $_GET['acao'] === 'editar' && isset($_GET['id']) && $podeGerirKb) {
    $stmt_ed = $pdo->prepare("SELECT * FROM kb_artigos WHERE id = ?");
    $stmt_ed->execute([(int)$_GET['id']]);
    $artigoEditar = $stmt_ed->fetch(PDO::FETCH_ASSOC) ?: null;
}

// =========================================================
// PROCESSAR NOVA PUBLICAÇÃO (POST)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_publicar'])) {
    if (!$podeGerirKb) {
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: Não tem permissão para publicar artigos.</div>";
    } else {
        $titulo = trim($_POST['titulo'] ?? '');
        $conteudo = trim($_POST['conteudo'] ?? '');
        $categoria = $_POST['categoria'] ?? 'Geral';
        $visibilidade = normalizarVisibilidadeKb($_POST['visibilidade'] ?? 'todos');

        // Processar imagem opcional (guardada em uploads/kb)
        $upload = processarUploadImagem($_FILES['imagem'] ?? [], 'kb');

        if (!$upload['sucesso']) {
            $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: " . htmlspecialchars($upload['erro']) . "</div>";
        } elseif (!empty($titulo) && !empty($conteudo) && $user_id_num) {
            $stmt_ins = $pdo->prepare("INSERT INTO kb_artigos (titulo, conteudo, imagem, categoria, visibilidade, id_autor, data_criacao) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $stmt_ins->execute([$titulo, $conteudo, $upload['caminho'], $categoria, $visibilidade, $user_id_num]);
            registarAuditoria($pdo, 'Criação', "Artigo KB criado: $titulo (visibilidade=$visibilidade)");
            $mensagem = "<div style='background: rgba(34,197,94,0.2); color: var(--green); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Novo conhecimento publicado!</div>";
        } else {
            $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: Preencha todos os campos.</div>";
        }
    }
}

// =========================================================
// PROCESSAR EDIÇÃO DE ARTIGO EXISTENTE (POST)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btn_editar'])) {
    if (!$podeGerirKb) {
        $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: Não tem permissão para editar artigos.</div>";
    } else {
        $id_artigo = (int)($_POST['id_artigo'] ?? 0);
        $titulo = trim($_POST['titulo'] ?? '');
        $conteudo = trim($_POST['conteudo'] ?? '');
        $categoria = $_POST['categoria'] ?? 'Geral';
        $visibilidade = normalizarVisibilidadeKb($_POST['visibilidade'] ?? 'todos');

        // Processar nova imagem (opcional). Se enviada, substitui a atual.
        $upload = processarUploadImagem($_FILES['imagem'] ?? [], 'kb');

        if (!$upload['sucesso']) {
            $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: " . htmlspecialchars($upload['erro']) . "</div>";
        } elseif ($id_artigo > 0 && !empty($titulo) && !empty($conteudo)) {
            if ($upload['caminho'] !== null) {
                // Remover imagem antiga do disco antes de substituir
                $antiga = $pdo->prepare("SELECT imagem FROM kb_artigos WHERE id = ?");
                $antiga->execute([$id_artigo]);
                $imgAntiga = $antiga->fetchColumn();
                if ($imgAntiga && file_exists(__DIR__ . '/' . $imgAntiga)) {
                    @unlink(__DIR__ . '/' . $imgAntiga);
                }
                $stmt_upd = $pdo->prepare("UPDATE kb_artigos SET titulo = ?, conteudo = ?, categoria = ?, visibilidade = ?, imagem = ? WHERE id = ?");
                $stmt_upd->execute([$titulo, $conteudo, $categoria, $visibilidade, $upload['caminho'], $id_artigo]);
            } else {
                // Sem nova imagem — mantém a existente
                $stmt_upd = $pdo->prepare("UPDATE kb_artigos SET titulo = ?, conteudo = ?, categoria = ?, visibilidade = ? WHERE id = ?");
                $stmt_upd->execute([$titulo, $conteudo, $categoria, $visibilidade, $id_artigo]);
            }
            registarAuditoria($pdo, 'Alteração', "Artigo KB #$id_artigo editado: $titulo (visibilidade=$visibilidade)");
            $mensagem = "<div style='background: rgba(34,197,94,0.2); color: var(--green); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Artigo atualizado com sucesso!</div>";
        } else {
            $mensagem = "<div style='background: rgba(239,68,68,0.2); color: var(--red); padding: 12px; border-radius: var(--radius-sm); margin-bottom: 20px;'>Erro: Preencha todos os campos.</div>";
        }
    }
}

// =========================================================
// FILTROS DE PESQUISA E CATEGORIA (disponível a todos os utilizadores)
// =========================================================
$pesquisaKb = trim($_GET['pesquisa'] ?? '');
$categoriaFiltro = trim($_GET['categoria'] ?? '');
$visibilidadeFiltro = trim($_GET['visibilidade'] ?? '');
$categoriasKb = obterCategoriasKb($pdo);

// =========================================================
// PROCURAR ARTIGOS PUBLICADOS (com filtros opcionais + visibilidade)
// =========================================================
$sqlKb = "
    SELECT k.*, u.nome AS nome_autor, u.perfil AS perfil_autor
    FROM kb_artigos k
    JOIN utilizadores u ON k.id_autor = u.id
    WHERE 1=1
";
$paramsKb = [];

[$sqlVis, $paramsVis] = clausulaSqlVisibilidadeKb($contexto, 'k');
$sqlKb .= $sqlVis;
$paramsKb = array_merge($paramsKb, $paramsVis);

if ($categoriaFiltro !== '') {
    $sqlKb .= " AND k.categoria = ?";
    $paramsKb[] = $categoriaFiltro;
}

if ($visibilidadeFiltro !== '' && isset($opcoesVisibilidadeKb[$visibilidadeFiltro]) && $podeGerirKb) {
    $sqlKb .= " AND COALESCE(k.visibilidade, 'todos') = ?";
    $paramsKb[] = $visibilidadeFiltro;
}

if ($pesquisaKb !== '') {
    $sqlKb .= " AND (k.titulo LIKE ? OR k.conteudo LIKE ? OR k.categoria LIKE ?)";
    $termo = '%' . $pesquisaKb . '%';
    $paramsKb[] = $termo;
    $paramsKb[] = $termo;
    $paramsKb[] = $termo;
}

$sqlKb .= " ORDER BY k.id DESC";

$stmtKb = $pdo->prepare($sqlKb);
$stmtKb->execute($paramsKb);
$artigos = $stmtKb->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KIAMI - Base de Conhecimento</title>
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
            <a href="kb_lista.php" class="nav-item active">📚 <span>Base Conhecimento</span></a>
            <a href="formacao.php" class="nav-item">🎓 <span>Autoaprendizagem</span></a>
            <a href="empresa.php" class="nav-item">🏢 <span>A Empresa</span></a>
            <?php echo htmlNavNotificacoes((int)($notif_pendentes ?? 0)); ?>
            
            <?php if (podeAcederAdministracao($contexto)): ?>
                <div class="nav-section-title">Administração</div>
                <a href="perfis_lista.php" class="nav-item">🪪 <span>Gestão de Perfis</span></a>
                <a href="usuarios_lista.php" class="nav-item nav-sub">👥 <span>Utilizadores</span></a>
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
                <h1>Base de Conhecimento</h1>
                <p>Consulte tutoriais, resoluções de erros frequentes e procedimentos internos da Quality Contact Center.</p>
            </div>

            <?php echo $mensagem; ?>

            <div class="card" style="margin-bottom: 20px;">
                <h3 style="font-size: 15px; color: var(--accent); margin-bottom: 12px;">🔍 Pesquisar na Base de Conhecimento</h3>
                <form action="kb_lista.php" method="GET" style="display: grid; grid-template-columns: 1fr 180px <?php echo $podeGerirKb ? '200px' : ''; ?> auto auto; gap: 12px; align-items: end;">
                    <div>
                        <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Palavra-chave</label>
                        <input type="text" name="pesquisa" value="<?php echo htmlspecialchars($pesquisaKb); ?>" placeholder="Ex: VPN, internet, altitude..." style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:var(--text-primary); border-radius:var(--radius-sm);">
                    </div>
                    <div>
                        <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Categoria</label>
                        <select name="categoria" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:var(--text-primary); border-radius:var(--radius-sm);">
                            <option value="">Todas as categorias</option>
                            <?php foreach ($categoriasKb as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $categoriaFiltro === $cat ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($podeGerirKb): ?>
                    <div>
                        <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Visibilidade</label>
                        <select name="visibilidade" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:var(--text-primary); border-radius:var(--radius-sm);">
                            <option value="">Todas</option>
                            <?php foreach ($opcoesVisibilidadeKb as $cod => $rotulo): ?>
                                <option value="<?php echo htmlspecialchars($cod); ?>" <?php echo $visibilidadeFiltro === $cod ? 'selected' : ''; ?>><?php echo htmlspecialchars($rotulo); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <button type="submit" style="padding: 10px 18px; background: var(--accent); border:none; color:#fff; border-radius:var(--radius-sm); cursor:pointer; font-weight:600; font-size:13px;">Filtrar</button>
                    <a href="kb_lista.php" style="padding: 10px 14px; background: var(--bg-input); border:1px solid var(--border); color:var(--text-secondary); border-radius:var(--radius-sm); text-decoration:none; font-size:13px; text-align:center;">Limpar</a>
                </form>
                <?php if ($pesquisaKb !== '' || $categoriaFiltro !== '' || $visibilidadeFiltro !== ''): ?>
                    <p style="margin-top: 12px; font-size: 12px; color: var(--text-muted);">
                        <?php echo count($artigos); ?> artigo(s) encontrado(s)
                        <?php if ($categoriaFiltro !== ''): ?> na categoria <b><?php echo htmlspecialchars($categoriaFiltro); ?></b><?php endif; ?>
                        <?php if ($visibilidadeFiltro !== ''): ?> · visibilidade <b><?php echo htmlspecialchars(rotuloVisibilidadeKb($visibilidadeFiltro)); ?></b><?php endif; ?>
                        <?php if ($pesquisaKb !== ''): ?> com o termo <b><?php echo htmlspecialchars($pesquisaKb); ?></b><?php endif; ?>.
                    </p>
                <?php endif; ?>
            </div>

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 25px; align-items: start;">
                
                <div style="display: flex; flex-direction: column; gap: 20px;">
                    <?php if (empty($artigos)): ?>
                        <div class="card" style="text-align: center; color: var(--text-muted); padding: 40px;">
                            <?php if ($pesquisaKb !== '' || $categoriaFiltro !== '' || $visibilidadeFiltro !== ''): ?>
                                Nenhum artigo encontrado com os filtros seleccionados. Tente outra categoria, visibilidade ou palavra-chave.
                            <?php else: ?>
                                Nenhuma documentação disponível para o seu perfil, ou ainda sem artigos publicados.
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($artigos as $art): ?>
                        <?php $visArt = normalizarVisibilidadeKb($art['visibilidade'] ?? 'todos'); ?>
                        <div class="card" style="position: relative;">
                            <div style="display:flex; flex-wrap:wrap; gap:6px; align-items:center;">
                                <span style="font-size: 10px; background: var(--bg-input); color: var(--accent); padding: 3px 8px; border-radius: 4px; font-weight: 600; text-transform: uppercase;">
                                    📁 <?php echo htmlspecialchars($art['categoria']); ?>
                                </span>
                                <span style="font-size: 10px; background: rgba(139,92,246,0.15); color: #c4b5fd; padding: 3px 8px; border-radius: 4px; font-weight: 600;">
                                    👁 <?php echo htmlspecialchars(rotuloVisibilidadeKb($visArt)); ?>
                                </span>
                            </div>

                            <h2 style="font-size: 18px; color: #fff; margin: 10px 0 6px 0;"><?php echo htmlspecialchars($art['titulo']); ?></h2>
                            
                            <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 15px;">
                                Por: <b><?php echo htmlspecialchars($art['nome_autor']); ?></b> (<?php echo $art['perfil_autor']; ?>) | 
                                data: <?php echo date('d/m/Y', strtotime($art['data_criacao'])); ?>
                            </div>

                            <p style="color: var(--text-secondary); font-size: 14px; line-height: 1.6; white-space: pre-line;"><?php echo htmlspecialchars($art['conteudo']); ?></p>

                            <?php if (!empty($art['imagem']) && file_exists(__DIR__ . '/' . $art['imagem'])): ?>
                                <div style="margin-top: 15px;">
                                    <a href="<?php echo htmlspecialchars($art['imagem']); ?>" target="_blank">
                                        <img src="<?php echo htmlspecialchars($art['imagem']); ?>" alt="Imagem do artigo" style="max-width: 100%; max-height: 350px; border-radius: var(--radius-sm); border: 1px solid var(--border);">
                                    </a>
                                </div>
                            <?php endif; ?>

                            <?php if ($podeGerirKb): ?>
                                <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid var(--border); text-align: right;">
                                    <a href="kb_lista.php?acao=editar&id=<?php echo $art['id']; ?>#form-kb"
                                       style="color: var(--accent); font-size: 12px; text-decoration: none; font-weight: 600; margin-right: 15px;">
                                        ✏️ Editar
                                    </a>
                                    <a href="kb_lista.php?acao=remover&id=<?php echo $art['id']; ?>" 
                                       style="color: var(--red); font-size: 12px; text-decoration: none; font-weight: 600;" 
                                       onclick="return confirm('Deseja mesmo apagar este artigo da Base de Conhecimento?');">
                                        🗑️ Apagar
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($podeGerirKb): ?>
                <?php
                    // Modo edição quando um artigo foi carregado; caso contrário, modo criação
                    $emEdicao = $artigoEditar !== null;
                    $valTitulo = $emEdicao ? htmlspecialchars($artigoEditar['titulo']) : '';
                    $valConteudo = $emEdicao ? htmlspecialchars($artigoEditar['conteudo']) : '';
                    $valCategoria = $emEdicao ? $artigoEditar['categoria'] : 'Geral / Tutoriais';
                    $valVisibilidade = $emEdicao ? normalizarVisibilidadeKb($artigoEditar['visibilidade'] ?? 'todos') : 'todos';
                ?>
                <div class="card" id="form-kb" style="<?php echo $emEdicao ? 'border-top: 3px solid var(--amber);' : ''; ?>">
                    <h3 style="color: var(--accent); margin-bottom: 15px; font-size: 16px;">
                        <?php echo $emEdicao ? '✏️ Editar Artigo' : '📝 Partilhar Conhecimento'; ?>
                    </h3>
                    <form action="kb_lista.php" method="POST" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: 15px;">
                        <?php if ($emEdicao): ?>
                            <input type="hidden" name="id_artigo" value="<?php echo (int)$artigoEditar['id']; ?>">
                        <?php endif; ?>
                        <div>
                            <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Título do Artigo</label>
                            <input type="text" name="titulo" required value="<?php echo $valTitulo; ?>" placeholder="Ex: Como configurar a VPN no Windows" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Categoria</label>
                            <select name="categoria" style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                                <?php foreach ($categoriasKb as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $valCategoria === $cat ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Visibilidade para</label>
                            <select name="visibilidade" required style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm);">
                                <?php foreach ($opcoesVisibilidadeKb as $cod => $rotulo): ?>
                                    <option value="<?php echo htmlspecialchars($cod); ?>" <?php echo $valVisibilidade === $cod ? 'selected' : ''; ?>><?php echo htmlspecialchars($rotulo); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small style="color:var(--text-muted); font-size:11px; display:block; margin-top:6px;">
                                <b>Todos</b> — qualquer colaborador. <b>Operações</b> — operadores/equipas de operação.
                                <b>Redes &amp; Sistemas</b> / <b>Desenvolvimento</b> — apenas essas áreas técnicas.
                            </small>
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Conteúdo / Passo a Passo</label>
                            <textarea name="conteudo" required rows="8" placeholder="Escreva a solução detalhada aqui..." style="width:100%; padding: 10px; background: var(--bg-input); border: 1px solid var(--border); color:#fff; border-radius:var(--radius-sm); resize: vertical; font-family:inherit; font-size:13px;"><?php echo $valConteudo; ?></textarea>
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:5px; font-size:12px; color:var(--text-secondary);">Imagem (opcional)</label>
                            <?php if ($emEdicao && !empty($artigoEditar['imagem']) && file_exists(__DIR__ . '/' . $artigoEditar['imagem'])): ?>
                                <div style="margin-bottom: 8px;">
                                    <img src="<?php echo htmlspecialchars($artigoEditar['imagem']); ?>" alt="Imagem atual" style="max-width: 100%; max-height: 120px; border-radius: var(--radius-sm); border: 1px solid var(--border);">
                                    <small style="display:block; color: var(--text-muted); font-size: 11px;">Imagem atual — envie outra para substituir.</small>
                                </div>
                            <?php endif; ?>
                            <input type="file" name="imagem" accept="image/png, image/jpeg, image/gif, image/webp" style="width:100%; padding: 8px; background: var(--bg-input); border: 1px solid var(--border); color:var(--text-secondary); border-radius:var(--radius-sm); font-size:12px;">
                            <small style="color: var(--text-muted); font-size: 11px;">Ilustração ou captura de ecrã (JPG, PNG, GIF, WEBP — máx. 5 MB).</small>
                        </div>
                        <?php if ($emEdicao): ?>
                            <button type="submit" name="btn_editar" style="padding: 12px; background: var(--amber); border:none; color:#111; border-radius:var(--radius-sm); cursor:pointer; font-weight:600; text-align:center;">Guardar Alterações</button>
                            <a href="kb_lista.php" style="text-align:center; color: var(--text-muted); font-size: 13px; text-decoration: none;">Cancelar edição</a>
                        <?php else: ?>
                            <button type="submit" name="btn_publicar" style="padding: 12px; background: var(--accent); border:none; color:white; border-radius:var(--radius-sm); cursor:pointer; font-weight:600; text-align:center;">Publicar na Base</button>
                        <?php endif; ?>
                    </form>
                </div>
                <?php else: ?>
                <div class="card" style="color: var(--text-muted); font-size: 14px;">
                    Apenas Administradores, Responsáveis de Área e Técnicos de Redes/Desenvolvimento podem publicar artigos.
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
    <script src="notificacoes.js"></script>
</body>
</html>