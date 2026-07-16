<?php
/**
 * Configuração SMTP — copie email.example.php para email.php
 *
 * Usado por includes/enviar_email.php (recuperação de senha, testar_email.php).
 */
return [    'ativo' => true,
    'host' => 'smtp.office365.com',
    'port' => 587,
    'encryption' => 'tls', // tls | ssl | none
    'username' => 'noreply@quality.co.ao',
    'password' => 'COLOQUE_A_SENHA_AQUI',
    'from_email' => 'noreply@quality.co.ao',
    'from_name' => 'KIAMI - Quality Contact Center',
    'reply_to' => 'suporte@quality.co.ao',
    'url_base' => '', // vazio = detetar automaticamente (ex: http://localhost/qcc-ticket)
    'debug_local' => true, // em localhost, mostra link se o envio falhar
];
