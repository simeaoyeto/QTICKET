# KIAMI — Documentação de Instalação

Sistema de gestão de tickets da **Quality Contact Center**.

> **Última actualização (Jul 2026):** perfil **Operador** (substitui Cliente operacional); **registo público** com aprovação por Redes & Sistemas; **multiárea** (um responsável/técnico pode gerir várias áreas); **Top Técnico** no dashboard (Admin, Direção, Redes, Desenvolvimento); atribuição a técnico **na abertura** do ticket; email obrigatório `@quality.co.ao` (exceto Operador); filtro **Atribuídos**; notificações in-app em mudança de estado; emails por área; Formadores; modo claro/escuro e restantes melhorias anteriores.

---

## 1. Visão geral do projecto

| Item | Detalhe |
|---|---|
| **Nome** | KIAMI |
| **Tecnologia** | PHP 8.x + MySQL/MariaDB |
| **Frontend** | HTML, CSS, Chart.js, DataTables (CDN) |
| **Servidor** | Apache (XAMPP) ou IIS (Windows Server 2016) |
| **Idioma** | Português de Angola |

### Estrutura de pastas

```
qcc-ticket/
├── api/
│   ├── dashboard_data.php  # Dados AJAX do painel (métricas + Top Técnico)
│   └── notificacoes.php    # Sondagem de notificações + escalonamento automático
├── config/                 # Configurações (BD, email) — não expor em produção
│   ├── database.php
│   ├── database.example.php
│   ├── email.php
│   └── email.example.php
├── exports/
│   ├── pdf/
│   └── excel/
├── includes/
│   ├── funcoes.php         # Permissões, SLA, multiárea, emails, auditoria
│   ├── enviar_email.php
│   ├── relatorio_query.php
│   └── pdf_export.php
├── uploads/
│   ├── tickets/
│   └── kb/
├── 2-qccticket/1-Database/
│   └── qccticket.sql
├── login.php               # Entrada (área / operação / conta)
├── registo.php             # Pedido de conta pública (fica Pendente)
├── index.php               # Painel operacional + Top Técnico
├── tickets_lista.php       # Tickets (filtros incl. Atribuídos + abertura)
├── ticket_detalhes.php     # Detalhe, comentários, atribuição, reencaminhar
├── abrir_ticket.php        # Abertura pública sem login
├── notificacoes_lista.php  # Painel de notificações in-app
├── usuarios_lista.php      # Gestão + Aceitar/Recusar contas pendentes
├── emails_areas.php        # Emails de caixa postal por área
├── assuntos_lista.php
├── equipa_online.php
├── relatorios.php
├── auditoria.php
├── kb_lista.php
├── formacao.php
├── empresa.php
├── recuperar_senha.php
├── alterar_senha.php
├── atualizar_banco.php     # Migrações idempotentes (obrigatório após updates)
├── notificacoes.js
├── tema.js
├── conexao.php
├── web.config
└── INSTALACAO.md
```

---

## 2. Requisitos

### Desenvolvimento (PC local)

- Windows 10/11
- [XAMPP](https://www.apachefriends.org/) com PHP **8.0+** e MySQL/MariaDB
- Navegador moderno (Chrome, Edge, Firefox) com **notificações activadas**

### Produção (Windows Server 2016)

- Windows Server 2016 ou superior
- **IIS 10** com CGI/FastCGI
- **PHP 8.0+** (Non-Thread Safe para IIS)
- **MySQL 8** ou **MariaDB 10.4+**
- Certificado SSL (recomendado)
- Conta SMTP corporativa (Office 365, Exchange, etc.)

### Extensões PHP necessárias

```
pdo_mysql
openssl
mbstring
session
json
fileinfo
```

### Permissões de escrita

A pasta `uploads/` deve permitir escrita pelo utilizador do servidor web:

- **XAMPP:** normalmente funciona sem configuração extra
- **IIS:** conceda escrita a `IIS_IUSRS` em `uploads/`

---

## 3. Instalação no PC local (XAMPP)

### Passo 1 — Copiar o projecto

```
C:\xampp\htdocs\qcc-ticket
```

> Se já existir uma cópia antiga em `htdocs`, **substitua todos os ficheiros** pelos mais recentes (excepto `config/database.php`, `config/email.php` e `uploads/`).

### Passo 2 — Criar a base de dados

1. Inicie Apache e MySQL no XAMPP
2. phpMyAdmin: `http://localhost/phpmyadmin`
3. Crie a BD `qccticket` (utf8mb4_unicode_ci)
4. Importe: `2-qccticket/1-Database/qccticket.sql`

### Passo 3 — Configurar ligação à BD

Edite `config/database.php`:

```php
return [
    'host' => 'localhost',
    'banco' => 'qccticket',
    'usuario' => 'root',
    'senha' => '',
    'charset' => 'utf8mb4',
];
```

### Passo 4 — Executar migração (obrigatório)

```
http://localhost/qcc-ticket/atualizar_banco.php
```

O script é **idempotente**. Confirme linhas ✅ para, entre outras:

| Item | Descrição |
|---|---|
| Colunas em `tickets` | `codigo`, `email_solicitante`, `anexo`, `id_area_destino`, `id_operacao_origem`, `notif_escala_5/10` |
| Colunas em `utilizadores` | `id_operacao`, `ultimo_acesso`, `sessao_ativa` |
| Coluna `areas.email` | Caixa postal por área |
| Perfil `Operador` | Enum actualizado; perfil legado `Cliente` migrado para `Operador` |
| Estado `Pendente` | Contas à espera de aprovação |
| Tabela `utilizador_areas` | Multiárea (várias áreas por utilizador) |
| Tabelas | `notificacoes`, `ticket_historico`, `ticket_assuntos`, `formacao_*`, etc. |
| Área Formadores | Criada se ainda não existir |
| Operação INACOM | Verificada/criada |

### Passo 5 — Configurar email (opcional em local)

Copie `config/email.example.php` → `config/email.php`. Em local pode usar `debug_local => true`.

### Passo 6 — Aceder ao sistema

```
http://localhost/qcc-ticket/login.php
```

| Utilizador | Senha | Perfil |
|---|---|---|
| `admin` | `123456` | Administrador |

> Com senha `123456`, o sistema obriga a definir uma nova palavra-passe.

**Outros pontos de entrada:**

| URL | Uso |
|---|---|
| `registo.php` | Pedir conta (fica **Pendente** até Redes & Sistemas aceitar) |
| `abrir_ticket.php` | Abrir ticket **sem login** |
| `recuperar_senha.php` | Recuperação de palavra-passe |

### Passo 7 — Limpar cache

Após actualizar ficheiros: **Ctrl+F5**.

---

## 4. Instalação no ambiente corporativo (Windows Server 2016)

### Passo 1 — Instalar IIS

Server Manager → Web Server (IIS) com CGI, Static Content, Default Document, HTTP Errors.

### Passo 2 — Instalar PHP

1. PHP 8.x **Non-Thread Safe** (x64) em `C:\PHP`
2. Active em `php.ini`: `pdo_mysql`, `openssl`, `mbstring`, `fileinfo`, `curl`
3. `date.timezone = Africa/Luanda`
4. `upload_max_filesize = 20M` / `post_max_size = 25M` / `session.gc_maxlifetime = 900`
5. Handler Mapping IIS: `*.php` → `C:\PHP\php-cgi.exe`

### Passo 3 — MySQL/MariaDB

```sql
CREATE DATABASE qccticket CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'qccticket_app'@'localhost' IDENTIFIED BY 'SenhaForteAqui!';
GRANT ALL PRIVILEGES ON qccticket.* TO 'qccticket_app'@'localhost';
FLUSH PRIVILEGES;
```

Importe `qccticket.sql` e execute `atualizar_banco.php`.

### Passo 4 — Publicar

1. Copie para `C:\inetpub\wwwroot\qccticket`
2. Configure o site no IIS (`web.config` protege `config/` e `includes/`)
3. Escrita em `uploads/` para o Application Pool

### Passo 5 — Segurança pós-instalação

- [ ] Alterar senha do `admin`
- [ ] Proteger ou remover `atualizar_banco.php` após migração
- [ ] `debug_local => false` em `config/email.php`
- [ ] Confirmar que `config/` não é acessível via browser
- [ ] Backups automáticos da BD

---

## 5. Perfis e permissões

| Perfil | Acesso |
|---|---|
| **Admin** | Acesso total; dashboard com Top Técnico; auditoria |
| **Diretor Geral** | Consulta: painel (métricas + Top Técnico) e relatórios |
| **Responsável / Técnico** (Redes ou Desenvolvimento) | Painel técnico, tickets das **suas áreas** (multiárea), gestão de utilizadores, relatórios, KB (escrever), atribuição na abertura |
| **Responsável / Técnico** (outras áreas) | Tickets das áreas a que está associado e atribuídos a si |
| **Comum** | Só os tickets que abriu; KB leitura; formação; empresa; notificações |
| **Operador** | Tickets da **sua operação** (trancada); abrir só para Redes ou Desenvolvimento; KB/formação/empresa/notificações (leitura); **sem email obrigatório** |

> O perfil **Cliente operacional** foi **removido**. Contas antigas são migradas para **Operador** por `atualizar_banco.php`.

### Multiárea

Um Responsável ou Técnico pode pertencer a **várias áreas** (ex.: Redes & Sistemas **e** Desenvolvimento).

- Guardado na tabela `utilizador_areas` (+ `utilizadores.id_area` como área principal)
- Configura-se em **Gestão de Utilizadores** (checkboxes de áreas)
- Vê e trata tickets de **todas** as suas áreas

### Registo público e aprovação

1. Qualquer pessoa acede a `registo.php` (link «Criar conta» no login)
2. Escolhe o **perfil**:
   - **Operador** → lista de **operações** (email opcional)
   - **Outros** → lista de **áreas** (email **obrigatório** `@quality.co.ao`)
3. Conta criada com estado **Pendente**
4. Redes & Sistemas / Admin em **Gestão de Utilizadores** → **Aceitar** ou **Recusar**
5. Só contas **Ativo** podem fazer login

### Login (três modos)

1. **Área administrativa** — username = nome da área (ex: `rh`, `direção`); password = nome do solicitante → perfil Comum
2. **Operação** — username = nome da operação (ex: `ENSA`) → sessão **Operador**
3. **Conta na BD** — username + password (estado deve ser `Ativo`)

### Regras de tickets

- **Códigos únicos:** `QCC-AAAA-NNNNNN`
- **Quem abre não resolve** o próprio ticket
- **Atribuir na abertura:** técnico/responsável da área (ou Admin), ao abrir para a **própria área**, pode atribuir logo a um técnico dessa área → estado **Em Progresso**
- **Filtros:** Todos, Abertos, Em Progresso, **Atribuídos**, Reencaminhados, Resolvidos (com contagens)
- **INACOM:** tickets desta operação visíveis a Redes e Desenvolvimento
- **Formadores:** área isolada (só vê tickets do próprio grupo, salvo multiárea com outras áreas)

### Gestão e conhecimento

- **Utilizadores / assuntos / emails das áreas / equipa online:** Admin + staff Redes/Desenvolvimento
- **Auditoria:** Admin + Responsáveis das áreas técnicas
- **KB:** staff técnico escreve; restantes leem
- **Notificações in-app:** criador, técnico atribuído e área destino (além de email SMTP quando existir)

### Dashboard (Top Técnico)

Visível para:

- Admin
- Diretor Geral / área Direção
- Staff de Redes & Sistemas e Desenvolvimento

Inclui métricas SLA, gráficos e ranking de produtividade dos técnicos.

---

## 6. Funcionalidades implementadas

| Módulo | Estado |
|---|---|
| Login / Logout / Timeout 15 min | ✅ |
| Registo público + aprovação Pendente | ✅ |
| Perfil Operador (substitui Cliente) | ✅ |
| Multiárea (`utilizador_areas`) | ✅ |
| Email `@quality.co.ao` obrigatório (exceto Operador) | ✅ |
| Top Técnico no dashboard | ✅ |
| Atribuir técnico na abertura do ticket | ✅ |
| Filtro Atribuídos + contagens por estado | ✅ |
| Notificações in-app (estado, atribuição, comentário) | ✅ |
| Emails de caixa por área (`emails_areas.php`) | ✅ |
| Bloqueio após 5 tentativas de login | ✅ |
| Recuperação / troca de senha | ✅ |
| Abertura pública sem login | ✅ |
| Upload de imagem (tickets e KB) | ✅ |
| Código QCC-AAAA-NNNNNN + SLA | ✅ |
| Assumir / atribuir / reencaminhar | ✅ |
| Separação de funções (quem abre não resolve) | ✅ |
| Escalonamento 5/10 min | ✅ |
| Emails automáticos ao solicitante | ✅ |
| Painel Chart.js + AJAX | ✅ |
| DataTables + filtros | ✅ |
| Relatórios PDF / Excel | ✅ |
| KB + Autoaprendizagem + A Empresa | ✅ |
| Modo claro / escuro | ✅ |
| Auditoria | ✅ |

### Notificações e escalonamento

1. **Novo ticket** — área destino (+ INACOM → Redes e Dev) + email da caixa da área
2. **Mudança de estado / atribuir / assumir / comentar** — notificação na plataforma ao criador, técnico e área
3. **Pop-ups** — `notificacoes.js` (toasts + alerta nativo opcional)
4. **Sondagem** — `api/notificacoes.php` a cada ~20 s
5. **Escalonamento** — Aberto sem técnico: aviso aos Responsáveis aos 5 e 10 minutos

### Emails ao solicitante

Enviados (se SMTP activo) em: abertura, mudança de estado, atribuição/assumir, novo comentário (exceto pelo próprio solicitante).

---

## 7. Actualização de versão

### 7.1 Backup

1. Exporte a BD `qccticket`
2. Copie a pasta `uploads/`

### 7.2 Sincronizar ficheiros

Substitua os ficheiros em `C:\xampp\htdocs\qcc-ticket`, **excepto**:

- `config/database.php`
- `config/email.php`
- `uploads/`

**Ficheiros críticos recentes:**

| Ficheiro | Alteração |
|---|---|
| `registo.php` | Registo público com perfil / operação / área |
| `atualizar_banco.php` | Operador, Pendente, `utilizador_areas`, migração Cliente→Operador |
| `includes/funcoes.php` | Multiárea, Operador, Top Técnico, emails, atribuição |
| `usuarios_lista.php` | Aceitar/Recusar, multiárea, validação email |
| `tickets_lista.php` | Atribuídos, atribuição na abertura, Operador |
| `index.php` / `api/dashboard_data.php` | Top Técnico + métricas para Direção/Admin |
| `login.php` | Link criar conta; login por operação → Operador |
| `emails_areas.php` | Caixas de email por área |
| `notificacoes_lista.php` | Painel de notificações |
| `tema.js` / `style.css` | Tema claro/escuro |

### 7.3 Executar migração

```
http://localhost/qcc-ticket/atualizar_banco.php
```

Verifique especialmente:

- `Enum de perfil sem Cliente (removido)`
- `Perfis Cliente migrados para Operador`
- `Tabela utilizador_areas`
- `Enum de estado … Pendente`

### 7.4 Testar

Siga a secção 8.

---

## 8. Plano de testes (checklist)

### Preparação

- [ ] Apache e MySQL a correr
- [ ] Ficheiros sincronizados em `htdocs`
- [ ] `atualizar_banco.php` com todos ✅ relevantes
- [ ] Ctrl+F5
- [ ] Notificações do browser autorizadas

### Autenticação, registo e perfis

| # | Teste | Esperado |
|---|---|---|
| 1 | Login `admin` / `123456` | Troca de senha ou painel |
| 2 | Link «Criar conta» → `registo.php` | Formulário com escolha de perfil |
| 3 | Registo Operador sem email | Pedido Pendente aceite |
| 4 | Registo Técnico sem `@quality.co.ao` | Erro — email obrigatório do domínio Quality |
| 5 | Aceitar conta em Gestão de Utilizadores | Conta Ativa; login possível |
| 6 | Recusar conta | Estado Inativo; login bloqueado |
| 7 | Login por operação (ex: ENSA) | Sessão Operador; tickets da operação |
| 8 | Logout | Sessão terminada |

### Tickets

| # | Teste | Esperado |
|---|---|---|
| 9 | Abrir ticket público | Todas as áreas/operações; email obrigatório |
| 10 | Operador abre ticket | Só destinos Redes ou Desenvolvimento; operação trancada |
| 11 | Técnico Redes abre para Redes e atribui colega | Ticket Em Progresso; técnico notificado |
| 12 | Filtro Atribuídos | Só tickets atribuídos a si; contagem correcta |
| 13 | Mudança de estado | Notificação in-app no outro perfil + email se existir |
| 14 | Criador tenta resolver | Bloqueado |
| 15 | Multiárea: responsável Redes+Dev | Vê/trata tickets das duas áreas |

### Dashboard e administração

| # | Teste | Esperado |
|---|---|---|
| 16 | Admin / Direção / Redes | Top Técnico visível no painel |
| 17 | Gestão: várias áreas num responsável | Checkboxes; lista mostra várias áreas |
| 18 | Emails das Áreas | Configurar e receber aviso ao novo ticket |
| 19 | Relatórios / PDF / Excel | Exportação com filtros |
| 20 | Modo claro/escuro | Alterna e persiste |

### KB / formação / empresa

| # | Teste | Esperado |
|---|---|---|
| 21 | Operador na KB | Só leitura |
| 22 | Autoaprendizagem | Quiz funcional |
| 23 | A Empresa | Conteúdo institucional |

---

## 9. Resolução de problemas

| Problema | Solução |
|---|---|
| Erro de ligação à BD | `config/database.php` + MySQL a correr |
| Página em branco | `display_errors` / `error.log` |
| Email não envia | `testar_email.php`; SMTP porta 587 |
| Conta Pendente não entra | Aceitar em Gestão de Utilizadores |
| «Cliente» ainda aparece | Execute `atualizar_banco.php` (migração para Operador) |
| Responsável não vê 2.ª área | Editar utilizador e marcar as áreas (multiárea) |
| Top Técnico não aparece | Perfil deve ser Admin, Direção ou staff Redes/Dev; Ctrl+F5 |
| Não atribui técnico na abertura | Destino = a sua área; perfil Técnico/Responsável/Admin |
| Email rejeitado no registo | Áreas internas exigem `*@quality.co.ao` |
| Imagem não carrega | Permissões em `uploads/` |
| Escalonamento não dispara | `atualizar_banco.php` + página aberta (polling) |
| Alterações não aparecem no XAMPP | Copiar ficheiros para `htdocs` + Ctrl+F5 |

### Logs

- **XAMPP:** `C:\xampp\apache\logs\error.log`
- **IIS:** Event Viewer → Application
- **PHP:** `error_log` no `php.ini`

---

## 10. Contactos de suporte interno

| Área | Extensões | Responsáveis |
|---|---|---|
| Redes, Sistemas & Helpdesk | 641 | Erivaldo Guimarães, João Geraldo |
| Desenvolvimento | 642 | Carlos Vissesse |

---

*Documentação KIAMI — Quality Contact Center — Jul 2026*
