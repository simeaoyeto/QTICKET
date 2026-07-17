-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 08, 2026 at 01:46 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `qccticket`
--

-- --------------------------------------------------------

--
-- Table structure for table `areas`
--

CREATE TABLE `areas` (
  `id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `data_criacao` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `areas`
--

INSERT INTO `areas` (`id`, `nome`, `email`, `data_criacao`) VALUES
(1, 'Redes & Sistemas', 'helpdesk@quality.co.ao', '2026-07-02 22:02:14'),
(2, 'Desenvolvimento', 'helpdesk@quality.co.ao', '2026-07-02 22:02:14'),
(3, 'Direção', NULL, '2026-07-07 20:00:32'),
(4, 'RH', NULL, '2026-07-07 20:00:32'),
(5, 'Finanças', NULL, '2026-07-07 20:00:32'),
(6, 'Comercial', NULL, '2026-07-07 20:00:32'),
(7, 'Auditoria', NULL, '2026-07-07 20:00:32'),
(8, 'Formadores', NULL, '2026-07-13 20:00:00'),
(9, 'Legal', NULL, '2026-07-16 12:00:00'),
(10, 'Logística', NULL, '2026-07-16 12:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `auditoria`
--

CREATE TABLE `auditoria` (
  `id` int(11) NOT NULL,
  `id_utilizador` int(11) DEFAULT NULL,
  `acao` varchar(100) NOT NULL,
  `detalhes` text NOT NULL,
  `data_registo` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `base_conhecimento`
--

CREATE TABLE `base_conhecimento` (
  `id` int(11) NOT NULL,
  `titulo` varchar(150) NOT NULL,
  `conteudo` text NOT NULL,
  `categoria` enum('Rede','Email','Acesso','Hardware','Software') NOT NULL,
  `tipo_conteudo` enum('operacional','tecnico','basico') NOT NULL,
  `data_criacao` timestamp NOT NULL DEFAULT current_timestamp(),
  `data_atualizacao` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `base_conhecimento`
--

INSERT INTO `base_conhecimento` (`id`, `titulo`, `conteudo`, `categoria`, `tipo_conteudo`, `data_criacao`, `data_atualizacao`) VALUES
(1, 'Procedimento Sem Internet', '1. Verifique se o cabo de rede está conectado.\n2. Reinicie o switch local.\n3. Caso persista, contacte a equipa de Redes.', 'Rede', 'operacional', '2026-07-02 22:02:14', '2026-07-02 22:02:14'),
(2, 'Senha Bloqueada no Sistema', 'Para desbloquear o acesso às plataformas internas, utilize a opção \"Recuperar Senha\" na página de login ou solicite suporte à área administrativa.', 'Acesso', 'basico', '2026-07-02 22:02:14', '2026-07-02 22:02:14'),
(3, 'Configuração de Email Corporativo', 'Passo a passo avançado para configuração de servidores IMAP/SMTP em novos terminais de atendimento.', 'Email', 'tecnico', '2026-07-02 22:02:14', '2026-07-02 22:02:14'),
(4, 'Como configurar VPN no Windows', '1. Vá em Configurações (ou pressione as teclas Windows + I) e acesse Rede e Internet > VPN.\r\n2. Clique em Adicionar VPN.\r\n3. Preencha os campos com os dados fornecidos pelo seu serviço de VPN (Provedor, nome da conexão, endereço do servidor, tipo de login e credenciais) e clique em Salvar.\r\n4. Para ativar, basta clicar no ícone de rede na barra de tarefas, selecionar a sua VPN e clicar em Conectar', '', 'tecnico', '2026-07-07 21:43:04', '2026-07-07 21:43:04');

-- --------------------------------------------------------

--
-- Table structure for table `comentarios`
--

CREATE TABLE `comentarios` (
  `id` int(11) NOT NULL,
  `id_ticket` int(11) NOT NULL,
  `id_utilizador` int(11) NOT NULL,
  `comentario` text NOT NULL,
  `data_envio` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kb_artigos`
--

CREATE TABLE `kb_artigos` (
  `id` int(11) NOT NULL,
  `titulo` varchar(255) NOT NULL,
  `conteudo` text NOT NULL,
  `categoria` varchar(100) NOT NULL,
  `id_autor` int(11) NOT NULL,
  `data_criacao` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `kb_artigos`
--

INSERT INTO `kb_artigos` (`id`, `titulo`, `conteudo`, `categoria`, `id_autor`, `data_criacao`) VALUES
(2, 'Como configurar VPN no Windows', '1. Windows + I\r\n2. Rede e Internet\r\n3. VPN\r\n4. Adicionar VPN\r\n\r\nPreenche:\r\n\r\n• Fornecedor de VPN: Windows (incorporado)\r\n• Nome da ligação: Minha VPN\r\n• Endereço do servidor: (fornecido pela VPN)\r\n• Tipo de VPN: Automático\r\n• Tipo de início de sessão: Nome de utilizador e palavra-passe\r\n• Nome de utilizador: (fornecido)\r\n• Palavra-passe: (fornecida)\r\n\r\n5. Guardar\r\n6. Ligar', 'Redes e Sistemas', 1, '2026-07-07 22:57:29'),
(3, 'Como configurar VPN no Linux', 'Configurar VPN no Linux\r\n\r\n1. Abrir Definições.\r\n2. Ir para Rede.\r\n3. Clicar em VPN.\r\n4. Selecionar Adicionar VPN.\r\n5. Escolher o tipo de VPN.\r\n6. Introduzir:\r\n   • Endereço do servidor\r\n   • Nome de utilizador\r\n   • Palavra-passe\r\n7. Guardar.\r\n8. Ativar a VPN.', 'Redes e Sistemas', 7, '2026-07-07 23:00:05');

-- --------------------------------------------------------

--
-- Table structure for table `operacoes`
--

CREATE TABLE `operacoes` (
  `id` int(11) NOT NULL,
  `nome` varchar(50) NOT NULL,
  `data_criacao` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `operacoes`
--

INSERT INTO `operacoes` (`id`, `nome`, `data_criacao`) VALUES
(1, 'AFRICELL', '2026-07-02 22:02:14'),
(2, 'BAI', '2026-07-02 22:02:14'),
(3, 'ENSA', '2026-07-02 22:02:14'),
(4, 'MULTICHOICE', '2026-07-02 22:02:14'),
(5, 'Q-EASY', '2026-07-02 22:02:14'),
(6, 'QCC', '2026-07-02 22:02:14'),
(7, 'INACOM', '2026-07-02 22:02:14');

-- --------------------------------------------------------

--
-- Table structure for table `ticket_assuntos`
--

CREATE TABLE `ticket_assuntos` (
  `id` int(11) NOT NULL,
  `titulo` varchar(150) NOT NULL,
  `ordem` int(11) NOT NULL DEFAULT 0,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `data_criacao` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ticket_assuntos`
--

INSERT INTO `ticket_assuntos` (`id`, `titulo`, `ordem`, `ativo`) VALUES
(1, 'Acesso / Palavra-passe', 10, 1),
(2, 'Internet / Rede', 20, 1),
(3, 'Email / Correio eletrónico', 30, 1),
(4, 'Impressora / Digitalização', 40, 1),
(5, 'Software / Aplicação (erro)', 50, 1),
(6, 'Instalação de programa', 60, 1),
(7, 'Hardware / Equipamento', 70, 1),
(8, 'Telefonia / VoIP', 80, 1),
(9, 'Sistema interno / Plataforma', 90, 1),
(10, 'Pedido de acesso / Nova conta', 100, 1),
(11, 'Backup / Recuperação de dados', 110, 1),
(12, 'Segurança / Vírus', 120, 1);

-- --------------------------------------------------------

--
-- Table structure for table `tickets`
--

CREATE TABLE `tickets` (
  `id` int(11) NOT NULL,
  `codigo` varchar(20) DEFAULT NULL,
  `titulo` varchar(150) NOT NULL,
  `nome_solicitante` varchar(100) DEFAULT NULL,
  `email_solicitante` varchar(150) DEFAULT NULL,
  `descricao` text NOT NULL,
  `prioridade` enum('Alta','Média','Baixa') NOT NULL,
  `estado` enum('Aberto','Em Progresso','Reencaminhado','Resolvido') DEFAULT 'Aberto',
  `anexo` varchar(255) DEFAULT NULL,
  `id_criador` int(11) NOT NULL,
  `id_operacao` int(11) DEFAULT NULL,
  `id_area_destino` int(11) NOT NULL,
  `id_operacao_origem` int(11) DEFAULT NULL,
  `id_tecnico_atribuido` int(11) DEFAULT NULL,
  `data_criacao` timestamp NOT NULL DEFAULT current_timestamp(),
  `data_limite_sla` datetime NOT NULL,
  `data_resolucao` datetime DEFAULT NULL,
  `notif_escala_5` tinyint(1) NOT NULL DEFAULT 0,
  `notif_escala_10` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tickets`
--

INSERT INTO `tickets` (`id`, `titulo`, `descricao`, `prioridade`, `estado`, `anexo`, `id_criador`, `id_operacao`, `id_area_destino`, `id_operacao_origem`, `id_tecnico_atribuido`, `data_criacao`, `data_limite_sla`, `data_resolucao`) VALUES
(1, 'Falha de Rede VPN', 'Problema básico', 'Baixa', 'Aberto', NULL, 7, NULL, 1, NULL, NULL, '2026-07-07 22:01:07', '0000-00-00 00:00:00', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `utilizadores`
--

CREATE TABLE `utilizadores` (
  `id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `perfil` enum('Admin','Diretor Geral','Responsavel','Tecnico','Operador') NOT NULL,
  `id_area` int(11) DEFAULT NULL,
  `id_operacao` int(11) DEFAULT NULL,
  `estado` enum('Ativo','Inativo','Pendente') DEFAULT 'Ativo',
  `ultimo_acesso` datetime DEFAULT NULL,
  `sessao_ativa` tinyint(1) NOT NULL DEFAULT 0,
  `data_criacao` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `utilizadores`
--

INSERT INTO `utilizadores` (`id`, `nome`, `email`, `username`, `password_hash`, `perfil`, `id_area`, `id_operacao`, `estado`, `ultimo_acesso`, `data_criacao`) VALUES
(1, 'Administrador Geral', 'admin@quality.co.ao', 'admin', '$2y$10$j4PyhTKP.lxTFJQh8EUltuO7Ye029tu3TS7FngJ/YoNoE6qNZUrmO', 'Admin', NULL, NULL, 'Ativo', NULL, '2026-07-02 22:02:14'),
(2, 'Carlos Vissesse', 'carlos.vissesse@quality.co.ao', 'carlos.vissesse', '$2y$10$TKh8H1.PfQx37YgCzwiKb.KjNyWgaHb9cbcoQgdIVFlYg7B77UdFm', 'Responsavel', 2, NULL, 'Ativo', NULL, '2026-07-02 22:02:14'),
(6, 'Manuel Comum', NULL, 'manuel.comum', '$2y$10$oboZKwExed8oj9rONHZSueWctKxftMdUJfjSymR.QD7KKXc5NsnNu', 'Tecnico', 2, NULL, 'Ativo', NULL, '2026-07-07 20:03:14'),
(7, 'João Geraldo', NULL, 'joao.geraldo', '$2y$10$zxBFssL4jmv8tMYRSLL0d.mbbU6wKBRaOUF1pqtOQiE.P9p5V/d6C', 'Tecnico', 1, NULL, 'Ativo', NULL, '2026-07-07 21:17:12'),
(8, 'Erivaldo Guimarães', NULL, 'erivaldo.g', '$2y$10$tdCqg/CvRjzRkHnJCHPNEO3ATdqLHVRIaHuRDcRRCemY78xSqyyH2', 'Responsavel', 1, NULL, 'Ativo', NULL, '2026-07-07 22:07:32'),
(9, 'Hernani Mateus', NULL, 'user.1', '$2y$10$SdGYjcNeEUmJjy1taSCsIut1REy6T960B1dpLLOYbhkqgVcwv59/e', '', NULL, 1, 'Ativo', NULL, '2026-07-07 22:47:27'),
(10, 'Nelson Obama', NULL, 'nelson', '$2y$10$8KN7zkaJnpJHsUyKIAA5neKYOUXMSsYvOB0ryWLfA2HKWrg8GKABi', 'Diretor Geral', 7, NULL, 'Ativo', NULL, '2026-07-07 23:27:53'),
(11, 'Rui Santos', NULL, 'rui.santos', '$2y$10$gD55I2nDYlqpkHNXSl5AiePhowTFBZbuv0K4uhNTAobl6TtYHsyfC', '', NULL, 3, 'Ativo', NULL, '2026-07-07 23:30:15');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `areas`
--
ALTER TABLE `areas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nome` (`nome`);

--
-- Indexes for table `auditoria`
--
ALTER TABLE `auditoria`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_utilizador` (`id_utilizador`);

--
-- Indexes for table `base_conhecimento`
--
ALTER TABLE `base_conhecimento`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `comentarios`
--
ALTER TABLE `comentarios`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_ticket` (`id_ticket`),
  ADD KEY `id_utilizador` (`id_utilizador`);

--
-- Indexes for table `kb_artigos`
--
ALTER TABLE `kb_artigos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_autor` (`id_autor`);

--
-- Indexes for table `operacoes`
--
ALTER TABLE `operacoes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nome` (`nome`);

--
-- Indexes for table `ticket_assuntos`
--
ALTER TABLE `ticket_assuntos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_titulo` (`titulo`);

--
-- Indexes for table `tickets`
--
ALTER TABLE `tickets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_codigo` (`codigo`),
  ADD KEY `id_criador` (`id_criador`),
  ADD KEY `id_operacao` (`id_operacao`),
  ADD KEY `id_area_destino` (`id_area_destino`),
  ADD KEY `id_tecnico_atribuido` (`id_tecnico_atribuido`);

--
-- Indexes for table `utilizadores`
--
ALTER TABLE `utilizadores`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `id_area` (`id_area`),
  ADD KEY `id_operacao` (`id_operacao`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `areas`
--
ALTER TABLE `areas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `auditoria`
--
ALTER TABLE `auditoria`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `base_conhecimento`
--
ALTER TABLE `base_conhecimento`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `comentarios`
--
ALTER TABLE `comentarios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `kb_artigos`
--
ALTER TABLE `kb_artigos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `operacoes`
--
ALTER TABLE `operacoes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `ticket_assuntos`
--
ALTER TABLE `ticket_assuntos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `tickets`
--
ALTER TABLE `tickets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `utilizadores`
--
ALTER TABLE `utilizadores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `auditoria`
--
ALTER TABLE `auditoria`
  ADD CONSTRAINT `auditoria_ibfk_1` FOREIGN KEY (`id_utilizador`) REFERENCES `utilizadores` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `comentarios`
--
ALTER TABLE `comentarios`
  ADD CONSTRAINT `comentarios_ibfk_1` FOREIGN KEY (`id_ticket`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `comentarios_ibfk_2` FOREIGN KEY (`id_utilizador`) REFERENCES `utilizadores` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `kb_artigos`
--
ALTER TABLE `kb_artigos`
  ADD CONSTRAINT `kb_artigos_ibfk_1` FOREIGN KEY (`id_autor`) REFERENCES `utilizadores` (`id`);

--
-- Constraints for table `tickets`
--
ALTER TABLE `tickets`
  ADD CONSTRAINT `tickets_ibfk_1` FOREIGN KEY (`id_criador`) REFERENCES `utilizadores` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tickets_ibfk_2` FOREIGN KEY (`id_operacao`) REFERENCES `operacoes` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `tickets_ibfk_3` FOREIGN KEY (`id_area_destino`) REFERENCES `areas` (`id`),
  ADD CONSTRAINT `tickets_ibfk_4` FOREIGN KEY (`id_tecnico_atribuido`) REFERENCES `utilizadores` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `utilizadores`
--
ALTER TABLE `utilizadores`
  ADD CONSTRAINT `utilizadores_ibfk_1` FOREIGN KEY (`id_area`) REFERENCES `areas` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `utilizadores_ibfk_2` FOREIGN KEY (`id_operacao`) REFERENCES `operacoes` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
