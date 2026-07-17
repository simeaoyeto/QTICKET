<?php
/**
 * KIAMI — Catálogo de Serviços ITIL v4 (fonte de verdade)
 *
 * Estrutura: Categoria → Subcategoria → Tipo → Item
 * Metadados: classificação ITIL, SLA resposta/resolução, aprovação, multi-selecção, Outros.
 */

/** Ordem de severidade (maior = mais crítico) */
const CATALOGO_SEVERIDADE = [
    'Baixa' => 1,
    'Média' => 2,
    'Alta' => 3,
    'Crítica' => 4,
];

/**
 * Matriz SLA por prioridade: [resposta_minutos, resolucao_horas]
 *
 * @return array<string, array{0:int,1:float}>
 */
function catalogoMatrizSlaPrioridade(): array
{
    return [
        'Crítica' => [15, 4],
        'Alta' => [30, 8],
        'Média' => [120, 24],
        'Baixa' => [480, 72],
    ];
}

/**
 * Matriz Impacto × Urgência → Prioridade
 *
 * @return array<string, array<string, string>>
 */
function catalogoMatrizImpactoUrgencia(): array
{
    // Impacto × Urgência
    return [
        'Empresa' => ['Baixa' => 'Média', 'Média' => 'Alta', 'Alta' => 'Crítica', 'Crítica' => 'Crítica'],
        'Operação' => ['Baixa' => 'Média', 'Média' => 'Alta', 'Alta' => 'Alta', 'Crítica' => 'Crítica'],
        'Equipa' => ['Baixa' => 'Baixa', 'Média' => 'Média', 'Alta' => 'Alta', 'Crítica' => 'Alta'],
        'Utilizador' => ['Baixa' => 'Baixa', 'Média' => 'Baixa', 'Alta' => 'Média', 'Crítica' => 'Alta'],
    ];
}

/**
 * Cria um item do catálogo com defaults.
 *
 * @param array<string, mixed> $d
 * @return array<string, mixed>
 */
function catalogoItem(array $d): array
{
    $prio = $d['prioridade_base'] ?? 'Média';
    $matriz = catalogoMatrizSlaPrioridade();
    [$respDef, $resDef] = $matriz[$prio] ?? $matriz['Média'];

    return [
        'chave' => $d['chave'],
        'categoria' => $d['categoria'],
        'subcategoria' => $d['subcategoria'],
        'tipo' => $d['tipo'],
        'item' => $d['item'],
        'classificacao_itil' => $d['classificacao_itil'] ?? $d['tipo'],
        'sla_resposta_min' => (int)($d['sla_resposta_min'] ?? $respDef),
        'sla_resolucao_h' => (float)($d['sla_resolucao_h'] ?? $resDef),
        'prioridade_base' => $prio,
        'urgencia_base' => $d['urgencia_base'] ?? $prio, // alinhada à prioridade base por omissão
        'aprovacao' => !empty($d['aprovacao']),
        'anexo_obrigatorio' => !empty($d['anexo_obrigatorio']),
        'multiplo' => array_key_exists('multiplo', $d) ? (bool)$d['multiplo'] : true,
        'tem_outros' => !empty($d['tem_outros']),
        'exemplo' => $d['exemplo'] ?? '',
        'grupo_multiplo' => $d['grupo_multiplo'] ?? null,
        'nova_entrada' => !empty($d['nova_entrada']),
    ];
}

/**
 * Catálogo completo QCC / KIAMI.
 *
 * @return array<int, array<string, mixed>>
 */
function obterCatalogoServicosCompleto(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $itens = [];

    // —— Posto de Trabalho ——
    $itens[] = catalogoItem([
        'chave' => 'pt_hw_entrega_pc', 'categoria' => 'Posto de Trabalho', 'subcategoria' => 'Hardware',
        'tipo' => 'Solicitação de Serviço', 'item' => 'Entrega / substituição de computador',
        'classificacao_itil' => 'Solicitação de Serviço', 'prioridade_base' => 'Média',
        'multiplo' => true, 'grupo_multiplo' => 'nova_entrada', 'nova_entrada' => true,
        'exemplo' => 'Novo colaborador precisa de portátil corporativo',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'pt_hw_avaria', 'categoria' => 'Posto de Trabalho', 'subcategoria' => 'Hardware',
        'tipo' => 'Incidente', 'item' => 'Avaria de hardware',
        'classificacao_itil' => 'Incidente', 'prioridade_base' => 'Alta',
        'exemplo' => 'Ecrã partido / teclado sem resposta',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'pt_sw_instalacao', 'categoria' => 'Posto de Trabalho', 'subcategoria' => 'Software',
        'tipo' => 'Solicitação de Serviço', 'item' => 'Instalação de aplicativos',
        'classificacao_itil' => 'Solicitação de Serviço', 'prioridade_base' => 'Média',
        'multiplo' => true, 'grupo_multiplo' => 'nova_entrada', 'nova_entrada' => true,
        'exemplo' => 'Instalar Office, Chrome e antivírus',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'pt_sw_lentidao', 'categoria' => 'Posto de Trabalho', 'subcategoria' => 'Software',
        'tipo' => 'Incidente', 'item' => 'Lentidão / desempenho',
        'classificacao_itil' => 'Incidente', 'prioridade_base' => 'Média',
        'exemplo' => 'PC demora a abrir aplicações',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'pt_sw_virus', 'categoria' => 'Posto de Trabalho', 'subcategoria' => 'Software',
        'tipo' => 'Incidente', 'item' => 'Segurança / vírus',
        'classificacao_itil' => 'Incidente', 'prioridade_base' => 'Alta',
        'anexo_obrigatorio' => true, 'exemplo' => 'Alerta do antivírus',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'pt_outro_equip', 'categoria' => 'Posto de Trabalho', 'subcategoria' => 'Outros',
        'tipo' => 'Incidente', 'item' => 'Outro Equipamento',
        'classificacao_itil' => 'Incidente', 'prioridade_base' => 'Média',
        'tem_outros' => true, 'exemplo' => 'Headset / dock não listado',
    ]);

    // —— Rede e Conectividade ——
    $itens[] = catalogoItem([
        'chave' => 'rede_sem_internet', 'categoria' => 'Rede e Conectividade', 'subcategoria' => 'LAN / Wi-Fi',
        'tipo' => 'Incidente', 'item' => 'Sem acesso à Internet',
        'classificacao_itil' => 'Incidente', 'prioridade_base' => 'Crítica',
        'sla_resposta_min' => 15, 'sla_resolucao_h' => 4,
        'multiplo' => true, 'grupo_multiplo' => 'sem_internet',
        'exemplo' => 'Máquina sem Internet na operação',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'rede_wifi', 'categoria' => 'Rede e Conectividade', 'subcategoria' => 'LAN / Wi-Fi',
        'tipo' => 'Incidente', 'item' => 'Problema de Wi-Fi / cabo',
        'classificacao_itil' => 'Incidente', 'prioridade_base' => 'Alta',
        'exemplo' => 'Wi-Fi cai com frequência',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'rede_vpn', 'categoria' => 'Rede e Conectividade', 'subcategoria' => 'VPN',
        'tipo' => 'Incidente', 'item' => 'VPN / acesso remoto',
        'classificacao_itil' => 'Incidente', 'prioridade_base' => 'Alta',
        'exemplo' => 'VPN não autentica',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'rede_mapa_pasta', 'categoria' => 'Rede e Conectividade', 'subcategoria' => 'Mapeamentos',
        'tipo' => 'Solicitação de Serviço', 'item' => 'Mapeamento de pasta',
        'classificacao_itil' => 'Solicitação de Serviço', 'prioridade_base' => 'Média',
        'multiplo' => true, 'grupo_multiplo' => 'nova_entrada', 'nova_entrada' => true,
        'exemplo' => 'Mapear pasta da operação BAI',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'rede_mapa_impressora', 'categoria' => 'Rede e Conectividade', 'subcategoria' => 'Mapeamentos',
        'tipo' => 'Solicitação de Serviço', 'item' => 'Mapeamento de impressora',
        'classificacao_itil' => 'Solicitação de Serviço', 'prioridade_base' => 'Baixa',
        'exemplo' => 'Adicionar impressora de piso',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'rede_outro', 'categoria' => 'Rede e Conectividade', 'subcategoria' => 'Outros',
        'tipo' => 'Incidente', 'item' => 'Outro Problema de Rede',
        'classificacao_itil' => 'Incidente', 'prioridade_base' => 'Média',
        'tem_outros' => true, 'exemplo' => 'DNS / proxy não previsto',
    ]);

    // —— Colaboração ——
    $itens[] = catalogoItem([
        'chave' => 'email_criacao', 'categoria' => 'Colaboração e Comunicação', 'subcategoria' => 'Email / Outlook',
        'tipo' => 'Acesso', 'item' => 'Criação de e-mail',
        'classificacao_itil' => 'Acesso', 'prioridade_base' => 'Média',
        'aprovacao' => true, 'multiplo' => true, 'grupo_multiplo' => 'nova_entrada', 'nova_entrada' => true,
        'exemplo' => 'Criar mailbox para novo colaborador',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'email_nao_recebe', 'categoria' => 'Colaboração e Comunicação', 'subcategoria' => 'Email / Outlook',
        'tipo' => 'Incidente', 'item' => 'Não recebe emails',
        'classificacao_itil' => 'Incidente', 'prioridade_base' => 'Alta',
        'exemplo' => 'Caixa de entrada sem mensagens novas',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'email_nao_envia', 'categoria' => 'Colaboração e Comunicação', 'subcategoria' => 'Email / Outlook',
        'tipo' => 'Incidente', 'item' => 'Não envia emails',
        'classificacao_itil' => 'Incidente', 'prioridade_base' => 'Alta',
        'exemplo' => 'Outlook em fila de saída',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'email_config', 'categoria' => 'Colaboração e Comunicação', 'subcategoria' => 'Email / Outlook',
        'tipo' => 'Solicitação de Serviço', 'item' => 'Configuração Outlook / cliente',
        'classificacao_itil' => 'Solicitação de Serviço', 'prioridade_base' => 'Média',
        'multiplo' => true, 'grupo_multiplo' => 'nova_entrada',
        'exemplo' => 'Configurar perfil Outlook',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'teams_sem_som', 'categoria' => 'Colaboração e Comunicação', 'subcategoria' => 'Teams',
        'tipo' => 'Incidente', 'item' => 'Teams sem som / áudio',
        'classificacao_itil' => 'Incidente', 'prioridade_base' => 'Alta',
        'multiplo' => true, 'grupo_multiplo' => 'teams_audio',
        'exemplo' => 'Não se ouve em reunião Teams',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'teams_geral', 'categoria' => 'Colaboração e Comunicação', 'subcategoria' => 'Teams',
        'tipo' => 'Incidente', 'item' => 'Problema Teams (geral)',
        'classificacao_itil' => 'Incidente', 'prioridade_base' => 'Média',
        'multiplo' => true, 'grupo_multiplo' => 'teams_audio',
        'exemplo' => 'Teams não inicia',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'audio_dispositivo', 'categoria' => 'Colaboração e Comunicação', 'subcategoria' => 'Áudio',
        'tipo' => 'Incidente', 'item' => 'Dispositivo de áudio / headset',
        'classificacao_itil' => 'Incidente', 'prioridade_base' => 'Média',
        'multiplo' => true, 'grupo_multiplo' => 'teams_audio',
        'exemplo' => 'Microfone não detectado',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'voip_chamadas', 'categoria' => 'Colaboração e Comunicação', 'subcategoria' => 'Telefonia / VoIP',
        'tipo' => 'Incidente', 'item' => 'Chamadas / áudio VoIP',
        'classificacao_itil' => 'Incidente', 'prioridade_base' => 'Alta',
        'exemplo' => 'Ramal sem tom',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'colab_outra_app', 'categoria' => 'Colaboração e Comunicação', 'subcategoria' => 'Outros',
        'tipo' => 'Incidente', 'item' => 'Outra Aplicação',
        'classificacao_itil' => 'Incidente', 'prioridade_base' => 'Média',
        'tem_outros' => true, 'exemplo' => 'App de chat não catalogada',
    ]);

    // —— Identidade e Acessos ——
    $itens[] = catalogoItem([
        'chave' => 'acesso_windows', 'categoria' => 'Identidade e Acessos', 'subcategoria' => 'Contas Windows',
        'tipo' => 'Acesso', 'item' => 'Criação de acessos ao Windows',
        'classificacao_itil' => 'Acesso', 'prioridade_base' => 'Média',
        'aprovacao' => true, 'multiplo' => true, 'grupo_multiplo' => 'nova_entrada', 'nova_entrada' => true,
        'exemplo' => 'Criar utilizador AD para nova entrada',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'acesso_password', 'categoria' => 'Identidade e Acessos', 'subcategoria' => 'Contas Windows',
        'tipo' => 'Acesso', 'item' => 'Palavra-passe / desbloqueio',
        'classificacao_itil' => 'Acesso', 'prioridade_base' => 'Alta',
        'exemplo' => 'Conta bloqueada',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'acesso_sistemas', 'categoria' => 'Identidade e Acessos', 'subcategoria' => 'Sistemas',
        'tipo' => 'Acesso', 'item' => 'Atribuir sistemas / permissões',
        'classificacao_itil' => 'Acesso', 'prioridade_base' => 'Média',
        'aprovacao' => true, 'multiplo' => true, 'grupo_multiplo' => 'nova_entrada', 'nova_entrada' => true,
        'exemplo' => 'Acesso ao CRM e pasta partilhada',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'acesso_permissoes', 'categoria' => 'Identidade e Acessos', 'subcategoria' => 'Permissões',
        'tipo' => 'Alteração', 'item' => 'Alteração de permissões',
        'classificacao_itil' => 'Alteração', 'prioridade_base' => 'Média',
        'aprovacao' => true, 'exemplo' => 'Remover acesso a pasta sensível',
    ]);

    // —— Impressão ——
    $itens[] = catalogoItem([
        'chave' => 'imp_nao_imprime', 'categoria' => 'Impressão e Digitalização', 'subcategoria' => 'Impressoras',
        'tipo' => 'Incidente', 'item' => 'Impressora não imprime',
        'classificacao_itil' => 'Incidente', 'prioridade_base' => 'Média',
        'exemplo' => 'Trabalho fica em fila',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'imp_scanner', 'categoria' => 'Impressão e Digitalização', 'subcategoria' => 'Scanners',
        'tipo' => 'Incidente', 'item' => 'Scanner / digitalização',
        'classificacao_itil' => 'Incidente', 'prioridade_base' => 'Baixa',
        'exemplo' => 'Scanner não digitaliza para pasta',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'imp_instalacao', 'categoria' => 'Impressão e Digitalização', 'subcategoria' => 'Impressoras',
        'tipo' => 'Solicitação de Serviço', 'item' => 'Instalação de impressora',
        'classificacao_itil' => 'Solicitação de Serviço', 'prioridade_base' => 'Baixa',
        'exemplo' => 'Instalar driver no posto',
    ]);

    // —— Sistemas de Negócio ——
    $itens[] = catalogoItem([
        'chave' => 'sis_wallboard', 'categoria' => 'Sistemas de Negócio', 'subcategoria' => 'Portais / Wallboards',
        'tipo' => 'Incidente', 'item' => 'Wallboard / painel parado',
        'classificacao_itil' => 'Incidente', 'prioridade_base' => 'Alta',
        'multiplo' => true, 'grupo_multiplo' => 'sem_internet',
        'exemplo' => 'Wallboard parado na operação BAI',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'sis_portal', 'categoria' => 'Sistemas de Negócio', 'subcategoria' => 'Portais / Wallboards',
        'tipo' => 'Incidente', 'item' => 'Portal / aplicação indisponível',
        'classificacao_itil' => 'Incidente', 'prioridade_base' => 'Crítica',
        'sla_resposta_min' => 15, 'sla_resolucao_h' => 4,
        'exemplo' => 'Portal Africell indisponível para todos os agentes',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'sis_erro_app', 'categoria' => 'Sistemas de Negócio', 'subcategoria' => 'Apps internas',
        'tipo' => 'Incidente', 'item' => 'Erro em aplicação / plataforma',
        'classificacao_itil' => 'Incidente', 'prioridade_base' => 'Alta',
        'exemplo' => 'Erro 500 no sistema interno',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'sis_melhoria', 'categoria' => 'Sistemas de Negócio', 'subcategoria' => 'Apps internas',
        'tipo' => 'Alteração', 'item' => 'Pedido de melhoria',
        'classificacao_itil' => 'Alteração', 'prioridade_base' => 'Baixa',
        'aprovacao' => true, 'exemplo' => 'Novo campo no formulário',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'sis_outro', 'categoria' => 'Sistemas de Negócio', 'subcategoria' => 'Outros',
        'tipo' => 'Incidente', 'item' => 'Outro Sistema',
        'classificacao_itil' => 'Incidente', 'prioridade_base' => 'Média',
        'tem_outros' => true, 'exemplo' => 'Sistema de terceiros não listado',
    ]);

    // —— Segurança e CCTV ——
    $itens[] = catalogoItem([
        'chave' => 'sec_cctv', 'categoria' => 'Segurança e CCTV', 'subcategoria' => 'Imagens CCTV',
        'tipo' => 'Solicitação de Serviço', 'item' => 'Solicitação de imagens CCTV',
        'classificacao_itil' => 'Solicitação de Serviço', 'prioridade_base' => 'Alta',
        'aprovacao' => true, 'anexo_obrigatorio' => false,
        'multiplo' => true, 'grupo_multiplo' => 'cctv_seguranca',
        'exemplo' => 'Imagens CCTV por furto — período e câmaras',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'sec_fisica', 'categoria' => 'Segurança e CCTV', 'subcategoria' => 'Segurança física',
        'tipo' => 'Incidente', 'item' => 'Incidente de segurança física',
        'classificacao_itil' => 'Incidente', 'prioridade_base' => 'Alta',
        'multiplo' => true, 'grupo_multiplo' => 'cctv_seguranca',
        'exemplo' => 'Furto / acesso não autorizado a zona',
    ]);

    // —— Nova Entrada / Saída ——
    $itens[] = catalogoItem([
        'chave' => 'ne_onboarding', 'categoria' => 'Nova Entrada / Saída', 'subcategoria' => 'Onboarding',
        'tipo' => 'Solicitação de Serviço', 'item' => 'Onboarding colaborador (checklist Redes)',
        'classificacao_itil' => 'Solicitação de Serviço', 'prioridade_base' => 'Média',
        'multiplo' => true, 'grupo_multiplo' => 'nova_entrada', 'nova_entrada' => true,
        'exemplo' => 'Nova entrada: PC, email, Windows, pasta, apps',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'ne_offboarding', 'categoria' => 'Nova Entrada / Saída', 'subcategoria' => 'Offboarding',
        'tipo' => 'Solicitação de Serviço', 'item' => 'Saída de colaborador',
        'classificacao_itil' => 'Solicitação de Serviço', 'prioridade_base' => 'Alta',
        'aprovacao' => true, 'exemplo' => 'Desactivar contas e recolher equipamento',
    ]);

    // —— Dados e Backup ——
    $itens[] = catalogoItem([
        'chave' => 'dados_recuperacao', 'categoria' => 'Dados e Backup', 'subcategoria' => 'Recuperação',
        'tipo' => 'Incidente', 'item' => 'Recuperação de ficheiros',
        'classificacao_itil' => 'Incidente', 'prioridade_base' => 'Alta',
        'exemplo' => 'Ficheiro apagado por engano',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'dados_backup', 'categoria' => 'Dados e Backup', 'subcategoria' => 'Backup',
        'tipo' => 'Manutenção Preventiva', 'item' => 'Backup / cópia de segurança',
        'classificacao_itil' => 'Manutenção Preventiva', 'prioridade_base' => 'Média',
        'exemplo' => 'Validar backup do servidor de ficheiros',
    ]);

    // —— Outros ——
    $itens[] = catalogoItem([
        'chave' => 'outro_solicitacao', 'categoria' => 'Outros', 'subcategoria' => 'Geral',
        'tipo' => 'Solicitação de Serviço', 'item' => 'Outra Solicitação',
        'classificacao_itil' => 'Solicitação de Serviço', 'prioridade_base' => 'Baixa',
        'tem_outros' => true, 'exemplo' => 'Pedido não previsto no catálogo',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'outro_incidente', 'categoria' => 'Outros', 'subcategoria' => 'Geral',
        'tipo' => 'Incidente', 'item' => 'Outro Incidente',
        'classificacao_itil' => 'Incidente', 'prioridade_base' => 'Média',
        'tem_outros' => true, 'exemplo' => 'Incidente não classificado',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'outro_problema', 'categoria' => 'Outros', 'subcategoria' => 'Geral',
        'tipo' => 'Problema', 'item' => 'Análise de Problema (recorrente)',
        'classificacao_itil' => 'Problema', 'prioridade_base' => 'Média',
        'exemplo' => 'Quedas repetidas de Internet na mesma operação',
    ]);
    $itens[] = catalogoItem([
        'chave' => 'outro_monitor', 'categoria' => 'Outros', 'subcategoria' => 'Monitorização',
        'tipo' => 'Evento de Monitorização', 'item' => 'Alerta de monitorização',
        'classificacao_itil' => 'Evento de Monitorização', 'prioridade_base' => 'Alta',
        'exemplo' => 'Alerta Zabbix / servidor down',
    ]);

    $cache = $itens;
    return $cache;
}

/**
 * Título canónico do item (para tickets / assuntos).
 */
function catalogoTituloItem(array $item): string
{
    return $item['categoria'] . ' › ' . $item['item'];
}

/**
 * Árvore Category → [ "Subcategoria › Item", ... ] para UI legada de 2 níveis.
 *
 * @return array<string, string[]>
 */
function catalogoParaArvoreAssuntos(): array
{
    $arvore = [];
    foreach (obterCatalogoServicosCompleto() as $item) {
        $cat = $item['categoria'];
        $label = $item['subcategoria'] . ' › ' . $item['item'];
        if (!isset($arvore[$cat])) {
            $arvore[$cat] = [];
        }
        if (!in_array($label, $arvore[$cat], true)) {
            $arvore[$cat][] = $label;
        }
    }
    return $arvore;
}

/**
 * Árvore 3 níveis: Categoria → Subcategoria → [itens]
 *
 * @return array<string, array<string, array<int, array<string, mixed>>>>
 */
function catalogoArvoreCascata(): array
{
    $arvore = [];
    foreach (obterCatalogoServicosCompleto() as $item) {
        $arvore[$item['categoria']][$item['subcategoria']][] = $item;
    }
    return $arvore;
}

/**
 * @return array<string, mixed>|null
 */
function catalogoObterPorChave(string $chave): ?array
{
    foreach (obterCatalogoServicosCompleto() as $item) {
        if ($item['chave'] === $chave) {
            return $item;
        }
    }
    return null;
}

/**
 * Resolve item pelo título do ticket (Categoria › Item ou Sub › Item).
 *
 * @return array<string, mixed>|null
 */
function catalogoObterPorTitulo(string $titulo): ?array
{
    $titulo = trim($titulo);
    foreach (obterCatalogoServicosCompleto() as $item) {
        if (catalogoTituloItem($item) === $titulo) {
            return $item;
        }
        $alt = $item['subcategoria'] . ' › ' . $item['item'];
        if ($alt === $titulo || $item['item'] === $titulo) {
            return $item;
        }
        // Título completo com sub no meio: Categoria › Sub › Item
        if ($titulo === $item['categoria'] . ' › ' . $alt) {
            return $item;
        }
    }
    // Fallback: contém o nome do item
    foreach (obterCatalogoServicosCompleto() as $item) {
        if (stripos($titulo, $item['item']) !== false && stripos($titulo, $item['categoria']) !== false) {
            return $item;
        }
    }
    return null;
}

/**
 * Derivação automática de urgência a partir do SLA de resolução (horas).
 */
function catalogoUrgenciaPorSlaResolucao(float $horas): string
{
    if ($horas <= 4) {
        return 'Crítica';
    }
    if ($horas <= 8) {
        return 'Alta';
    }
    if ($horas <= 24) {
        return 'Média';
    }
    return 'Baixa';
}

function catalogoNormalizarNivel(string $nivel): string
{
    $nivel = trim($nivel);
    if ($nivel === 'Media' || $nivel === 'Médio' || $nivel === 'Medio') {
        return 'Média';
    }
    if ($nivel === 'Critica' || $nivel === 'Crítico' || $nivel === 'Critico') {
        return 'Crítica';
    }
    if ($nivel === 'Baixo') {
        return 'Baixa';
    }
    if ($nivel === 'Alto') {
        return 'Alta';
    }
    return in_array($nivel, ['Crítica', 'Alta', 'Média', 'Baixa'], true) ? $nivel : 'Média';
}

/**
 * Calcula prioridade a partir de Impacto × Urgência.
 */
function calcularPrioridadeImpactoUrgencia(string $impacto, string $urgencia): string
{
    $impacto = trim($impacto);
    $urgencia = catalogoNormalizarNivel($urgencia);
    $mapa = catalogoMatrizImpactoUrgencia();
    if (!isset($mapa[$impacto])) {
        $impacto = 'Utilizador';
    }
    return $mapa[$impacto][$urgencia] ?? 'Média';
}

/**
 * Entre vários itens, devolve o mais crítico (Regra 1 e 2).
 *
 * @param array<int, array<string, mixed>> $itens
 * @return array{prioridade:string,sla_resposta_min:int,sla_resolucao_h:float,urgencia:string}
 */
function resolverSlaCatalogoMultiplo(array $itens): array
{
    if (empty($itens)) {
        $m = catalogoMatrizSlaPrioridade()['Média'];
        return [
            'prioridade' => 'Média',
            'sla_resposta_min' => $m[0],
            'sla_resolucao_h' => $m[1],
            'urgencia' => 'Média',
        ];
    }

    $melhor = null;
    $melhorScore = -1;
    foreach ($itens as $item) {
        $prio = catalogoNormalizarNivel($item['prioridade_base'] ?? 'Média');
        $score = CATALOGO_SEVERIDADE[$prio] ?? 2;
        // Desempate: menor SLA resolução = mais crítico
        $scoreF = $score * 1000 - (float)($item['sla_resolucao_h'] ?? 24);
        if ($scoreF > $melhorScore) {
            $melhorScore = $scoreF;
            $melhor = $item;
        }
    }

    $prio = catalogoNormalizarNivel($melhor['prioridade_base'] ?? 'Média');
    return [
        'prioridade' => $prio,
        'sla_resposta_min' => (int)$melhor['sla_resposta_min'],
        'sla_resolucao_h' => (float)$melhor['sla_resolucao_h'],
        'urgencia' => catalogoNormalizarNivel($melhor['urgencia_base'] ?? $prio),
    ];
}

/**
 * Nova Entrada: SLA final = maior tempo de resolução entre subtarefas (Regra 3).
 *
 * @param array<int, array<string, mixed>> $subtarefas
 * @return array{prioridade:string,sla_resposta_min:int,sla_resolucao_h:float,urgencia:string}
 */
function resolverSlaNovaEntrada(array $subtarefas): array
{
    if (empty($subtarefas)) {
        return resolverSlaCatalogoMultiplo([]);
    }
    $maxH = 0.0;
    $maxResp = 0;
    $piorPrio = 'Baixa';
    $piorScore = 0;
    foreach ($subtarefas as $item) {
        $h = (float)($item['sla_resolucao_h'] ?? 24);
        $r = (int)($item['sla_resposta_min'] ?? 120);
        if ($h > $maxH) {
            $maxH = $h;
        }
        if ($r > $maxResp) {
            $maxResp = $r;
        }
        $prio = catalogoNormalizarNivel($item['prioridade_base'] ?? 'Média');
        $sc = CATALOGO_SEVERIDADE[$prio] ?? 2;
        if ($sc > $piorScore) {
            $piorScore = $sc;
            $piorPrio = $prio;
        }
    }
    return [
        'prioridade' => $piorPrio,
        'sla_resposta_min' => $maxResp,
        'sla_resolucao_h' => $maxH,
        'urgencia' => catalogoUrgenciaPorSlaResolucao($maxH),
    ];
}

/**
 * Datas limite a partir de minutos/horas.
 *
 * @return array{0:string,1:string} [data_limite_resposta, data_limite_sla]
 */
function calcularDatasLimiteSlaCatalogo(int $respostaMin, float $resolucaoH, ?string $dataBase = null): array
{
    $base = $dataBase ? strtotime($dataBase) : time();
    $resp = date('Y-m-d H:i:s', $base + ($respostaMin * 60));
    $resol = date('Y-m-d H:i:s', (int)($base + ($resolucaoH * 3600)));
    return [$resp, $resol];
}

/**
 * Itens do grupo Nova Entrada (subtarefas).
 *
 * @return array<int, array<string, mixed>>
 */
function catalogoItensNovaEntrada(): array
{
    return array_values(array_filter(
        obterCatalogoServicosCompleto(),
        static fn(array $i) => !empty($i['nova_entrada']) && ($i['chave'] ?? '') !== 'ne_onboarding'
    ));
}

/**
 * Inferir impacto a partir do texto da descrição (heurística simples).
 */
function inferirImpactoDescricao(string $texto): string
{
    $t = mb_strtolower($texto, 'UTF-8');
    if (preg_match('/\b(todos|toda a empresa|empresa|africell|geral|todos os agentes)\b/u', $t)) {
        return 'Empresa';
    }
    if (preg_match('/\b(opera[cç][aã]o|bai|ensa|inacom|wallboard)\b/u', $t)) {
        return 'Operação';
    }
    if (preg_match('/\b(equipa|equipamento da equipa|departamento|piso)\b/u', $t)) {
        return 'Equipa';
    }
    return 'Utilizador';
}
