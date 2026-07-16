<?php
/**
 * Configuração SMTP ativa.
 * Edite com as credenciais reais do servidor de email da empresa.
 */
return [
    'ativo' => true,
    'host' => 'smtp.office365.com',
    'port' => 587,
    'encryption' => 'tls',
    'username' => 'noreply@quality.co.ao',
    'password' => '',
    'from_email' => 'noreply@quality.co.ao',
    'from_name' => 'KIAMI - Quality Contact Center',
    'reply_to' => 'suporte@quality.co.ao',
    'url_base' => '',
    'debug_local' => true,
];
