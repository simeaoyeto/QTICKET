<?php
/**
 * KIAMI — Envio de email via SMTP
 *
 * Cliente SMTP nativo (sem PHPMailer). Configuração em config/email.php.
 * Usado principalmente por recuperar_senha.php.
 */

/** Carrega config/email.php uma única vez por pedido */
function obterConfigEmail(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $path = __DIR__ . '/../config/email.php';
    if (!file_exists($path)) {
        $config = ['ativo' => false];
        return $config;
    }

    $config = require $path;
    return is_array($config) ? $config : ['ativo' => false];
}

/** Deteta URL base do sistema (config ou HTTP_HOST) para links em emails */
function obterUrlBaseSistema(): string
{
    $cfg = obterConfigEmail();
    if (!empty($cfg['url_base'])) {
        return rtrim($cfg['url_base'], '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = str_replace('\\', '/', dirname($script));
    $dir = ($dir === '/' || $dir === '\\') ? '' : $dir;

    return $scheme . '://' . $host . $dir;
}

/** True em localhost — permite mostrar link de debug se SMTP falhar */
function isAmbienteLocal(): bool
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        || str_starts_with($host, 'localhost:')
        || str_starts_with($host, '127.0.0.1:');
}

/**
 * Implementação SMTP mínima (AUTH LOGIN, TLS/SSL).
 * Suporta Office 365 e servidores compatíveis.
 */
class SmtpMailer
{
    private array $cfg;
    private $socket = null;
    private string $ultimoErro = '';

    public function __construct(array $config)
    {
        $this->cfg = $config;
    }

    public function getUltimoErro(): string
    {
        return $this->ultimoErro;
    }

    public function enviar(string $para, string $assunto, string $html, string $texto = ''): bool
    {
        if (empty($this->cfg['ativo'])) {
            $this->ultimoErro = 'Envio de email desativado na configuração.';
            return false;
        }

        if (empty($this->cfg['host']) || empty($this->cfg['from_email'])) {
            $this->ultimoErro = 'Configuração SMTP incompleta.';
            return false;
        }

        $texto = $texto ?: strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html));
        $boundary = 'bnd_' . bin2hex(random_bytes(8));
        $headers = $this->montarHeaders($para, $assunto, $boundary);
        $body = $this->montarCorpo($texto, $html, $boundary);

        try {
            if (!$this->conectar()) {
                return false;
            }
            $this->comando('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
            if (($this->cfg['encryption'] ?? '') === 'tls') {
                $this->comando('STARTTLS');
                if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Falha ao iniciar TLS.');
                }
                $this->comando('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
            }
            if (!empty($this->cfg['username'])) {
                $this->comando('AUTH LOGIN');
                $this->comando(base64_encode($this->cfg['username']));
                $this->comando(base64_encode($this->cfg['password'] ?? ''), [235]);
            }
            $this->comando('MAIL FROM:<' . $this->cfg['from_email'] . '>');
            $this->comando('RCPT TO:<' . $para . '>');
            $this->comando('DATA', [354]);
            $this->escrever($headers . "\r\n" . $body . "\r\n.\r\n");
            $this->lerResposta([250]);
            $this->comando('QUIT', [221]);
            $this->fechar();
            return true;
        } catch (Throwable $e) {
            $this->ultimoErro = $e->getMessage();
            $this->fechar();
            return false;
        }
    }

    private function conectar(): bool
    {
        $host = $this->cfg['host'];
        $port = (int)($this->cfg['port'] ?? 587);
        $enc = $this->cfg['encryption'] ?? 'tls';

        if ($enc === 'ssl') {
            $host = 'ssl://' . $host;
        }

        $errno = 0;
        $errstr = '';
        $this->socket = @stream_socket_client(
            $host . ':' . $port,
            $errno,
            $errstr,
            20,
            STREAM_CLIENT_CONNECT
        );

        if (!$this->socket) {
            $this->ultimoErro = "Não foi possível ligar ao SMTP: $errstr ($errno)";
            return false;
        }

        stream_set_timeout($this->socket, 20);
        $this->lerResposta([220]);
        return true;
    }

    private function comando(string $cmd, array $ok = [250]): void
    {
        $this->escrever($cmd . "\r\n");
        $this->lerResposta($ok);
    }

    private function escrever(string $data): void
    {
        $written = fwrite($this->socket, $data);
        if ($written === false) {
            throw new RuntimeException('Falha ao escrever no socket SMTP.');
        }
    }

    private function lerResposta(array $ok): string
    {
        $response = '';
        while ($line = fgets($this->socket, 515)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $ok, true)) {
            throw new RuntimeException(trim($response) ?: 'Resposta SMTP inválida.');
        }
        return $response;
    }

    private function fechar(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
    }

    private function montarHeaders(string $para, string $assunto, string $boundary): string
    {
        $fromName = $this->cfg['from_name'] ?? 'KIAMI';
        $fromEmail = $this->cfg['from_email'];
        $replyTo = $this->cfg['reply_to'] ?? $fromEmail;
        $encodedSubject = '=?UTF-8?B?' . base64_encode($assunto) . '?=';

        $headers = [];
        $headers[] = 'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromEmail . '>';
        $headers[] = 'To: <' . $para . '>';
        $headers[] = 'Reply-To: <' . $replyTo . '>';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $headers[] = 'Subject: ' . $encodedSubject;
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'Message-ID: <' . time() . '.' . bin2hex(random_bytes(4)) . '@qccticket.local>';

        return implode("\r\n", $headers);
    }

    private function montarCorpo(string $texto, string $html, string $boundary): string
    {
        $body = '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($texto)) . "\r\n";
        $body .= '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($html)) . "\r\n";
        $body .= '--' . $boundary . '--';
        return $body;
    }
}

/** Envia email genérico; retorna ['sucesso' => bool, 'erro' => string] */
function enviarEmail(string $para, string $assunto, string $html, string $texto = ''): array
{
    $cfg = obterConfigEmail();
    $mailer = new SmtpMailer($cfg);
    $ok = $mailer->enviar($para, $assunto, $html, $texto);
    return ['sucesso' => $ok, 'erro' => $mailer->getUltimoErro()];
}

/** Monta e envia email HTML de recuperação de senha com token de 1 hora */
function enviarEmailRecuperacaoSenha(string $emailDestino, string $nome, string $token): array
{
    $urlBase = obterUrlBaseSistema();
    $link = $urlBase . '/recuperar_senha.php?token=' . urlencode($token);
    $nomeSeguro = htmlspecialchars($nome, ENT_QUOTES, 'UTF-8');

    $assunto = 'KIAMI — Recuperação de palavra-passe';

    $html = <<<HTML
<!DOCTYPE html>
<html lang="pt-PT">
<head><meta charset="UTF-8"></head>
<body style="font-family: Arial, sans-serif; background:#f1f5f9; padding:20px;">
  <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:8px;padding:30px;border-top:4px solid #3b82f6;">
    <h2 style="color:#1e293b;margin:0 0 10px;">KIAMI</h2>
    <p style="color:#64748b;font-size:14px;">Quality Contact Center</p>
    <p style="color:#334155;font-size:15px;line-height:1.6;">Olá <strong>{$nomeSeguro}</strong>,</p>
    <p style="color:#334155;font-size:15px;line-height:1.6;">Recebemos um pedido para redefinir a sua palavra-passe. Clique no botão abaixo para criar uma nova. O link expira em <strong>1 hora</strong>.</p>
    <p style="text-align:center;margin:28px 0;">
      <a href="{$link}" style="background:#3b82f6;color:#fff;text-decoration:none;padding:12px 28px;border-radius:6px;font-weight:bold;font-size:15px;">Redefinir Palavra-passe</a>
    </p>
    <p style="color:#64748b;font-size:12px;word-break:break-all;">Se o botão não funcionar, copie este link:<br>{$link}</p>
    <hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">
    <p style="color:#94a3b8;font-size:12px;">Se não solicitou esta alteração, ignore este email. A sua conta permanece segura.</p>
  </div>
</body>
</html>
HTML;

    $texto = "Olá {$nome},\n\nRecebemos um pedido para redefinir a sua palavra-passe no KIAMI.\n\nAceda ao link (válido por 1 hora):\n{$link}\n\nSe não solicitou, ignore este email.";

    return enviarEmail($emailDestino, $assunto, $html, $texto);
}

/**
 * Modelo HTML base para emails relacionados com tickets.
 *
 * Centraliza o cabeçalho, rodapé e a "caixa" com o código do ticket, para que
 * todos os avisos (criação, mudança de estado, comentário, etc.) tenham o
 * mesmo aspeto visual.
 *
 * @param string $nomeSolicitante  Nome de quem abriu o ticket
 * @param string $codigo           Código do ticket (ex: QCC-2026-000001)
 * @param string $titulo           Assunto do ticket
 * @param string $intro            Frase de introdução específica do evento
 * @param array  $linhas           Pares "rótulo" => "valor" a mostrar na tabela
 * @param string $corDestaque      Cor da barra superior/estado (hex)
 */
function montarEmailTicketHtml(string $nomeSolicitante, string $codigo, string $titulo, string $intro, array $linhas, string $corDestaque = '#3b82f6'): string
{
    $nomeSeguro = htmlspecialchars($nomeSolicitante, ENT_QUOTES, 'UTF-8');
    $codigoSeguro = htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8');
    $tituloSeguro = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
    $introSeguro = htmlspecialchars($intro, ENT_QUOTES, 'UTF-8');

    // Constrói as linhas de detalhe (estado, prioridade, área, etc.)
    $linhasHtml = '';
    foreach ($linhas as $rotulo => $valor) {
        $r = htmlspecialchars((string)$rotulo, ENT_QUOTES, 'UTF-8');
        $v = htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
        $linhasHtml .= "<tr>
            <td style='padding:6px 0;color:#64748b;font-size:13px;'>{$r}</td>
            <td style='padding:6px 0;color:#1e293b;font-size:13px;font-weight:bold;text-align:right;'>{$v}</td>
        </tr>";
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="pt-PT">
<head><meta charset="UTF-8"></head>
<body style="font-family: Arial, sans-serif; background:#f1f5f9; padding:20px;">
  <div style="max-width:540px;margin:0 auto;background:#fff;border-radius:8px;padding:30px;border-top:4px solid {$corDestaque};">
    <h2 style="color:#1e293b;margin:0 0 4px;">KIAMI</h2>
    <p style="color:#64748b;font-size:13px;margin:0 0 18px;">Quality Contact Center — Suporte</p>
    <p style="color:#334155;font-size:15px;line-height:1.6;margin:0 0 6px;">Olá <strong>{$nomeSeguro}</strong>,</p>
    <p style="color:#334155;font-size:15px;line-height:1.6;margin:0 0 18px;">{$introSeguro}</p>
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:16px 18px;margin:0 0 18px;">
      <p style="margin:0 0 4px;color:#64748b;font-size:12px;">Código do ticket</p>
      <p style="margin:0 0 12px;color:{$corDestaque};font-size:20px;font-weight:bold;">{$codigoSeguro}</p>
      <p style="margin:0 0 12px;color:#1e293b;font-size:15px;font-weight:bold;">{$tituloSeguro}</p>
      <table style="width:100%;border-collapse:collapse;border-top:1px solid #e2e8f0;">
        {$linhasHtml}
      </table>
    </div>
    <p style="color:#64748b;font-size:13px;line-height:1.6;">Para acompanhar o progresso, aceda ao sistema KIAMI com a sua conta.</p>
    <hr style="border:none;border-top:1px solid #e2e8f0;margin:20px 0;">
    <p style="color:#94a3b8;font-size:12px;">Este é um email automático. Por favor não responda diretamente a esta mensagem.</p>
  </div>
</body>
</html>
HTML;
}

/**
 * Aviso de confirmação enviado a quem abre um ticket (público ou autenticado).
 */
function enviarEmailTicketCriado(string $emailDestino, string $nome, string $codigo, string $titulo, string $prioridade, string $area): array
{
    $assunto = "KIAMI — O seu ticket {$codigo} foi aberto";
    $intro = "O seu ticket com o código {$codigo} foi aberto com sucesso. Guarde este código para acompanhar o progresso. Será notificado por email sempre que o estado mudar.";
    $linhas = [
        'Código' => $codigo,
        'Estado' => 'Aberto',
        'Prioridade' => $prioridade,
        'Área de destino' => $area ?: '—',
        'Data' => date('d/m/Y H:i'),
    ];
    $html = montarEmailTicketHtml($nome, $codigo, $titulo, $intro, $linhas, '#3b82f6');
    $texto = "Olá {$nome},\n\nO seu ticket com o código {$codigo} foi aberto com sucesso.\nAssunto: {$titulo}\nPrioridade: {$prioridade}\nÁrea: {$area}\nEstado: Aberto\n\nSerá avisado por email sempre que o estado do ticket mudar.";
    return enviarEmail($emailDestino, $assunto, $html, $texto);
}

/**
 * Aviso de atualização de um ticket (mudança de estado, atribuição,
 * reencaminhamento, novo comentário, etc.).
 */
function enviarEmailTicketAtualizado(string $emailDestino, string $nome, string $codigo, string $titulo, string $estado, string $tipoEvento, string $detalhe = ''): array
{
    // Cor conforme o estado atual, para reforço visual
    $cor = match ($estado) {
        'Resolvido' => '#22c55e',
        'Em Progresso' => '#f59e0b',
        'Reencaminhado' => '#8b5cf6',
        default => '#3b82f6',
    };

    $assunto = "KIAMI — O ticket {$codigo} mudou de estado ({$estado})";
    $intro = "O seu ticket com o código {$codigo} foi atualizado: {$tipoEvento}.";
    $linhas = [
        'Código' => $codigo,
        'Estado atual' => $estado,
        'Atualização' => $tipoEvento,
    ];
    if ($detalhe !== '') {
        $linhas['Detalhe'] = $detalhe;
    }
    $linhas['Data'] = date('d/m/Y H:i');

    $html = montarEmailTicketHtml($nome, $codigo, $titulo, $intro, $linhas, $cor);
    $texto = "Olá {$nome},\n\nO seu ticket com o código {$codigo} ({$titulo}) foi atualizado.\nEvento: {$tipoEvento}\nEstado atual: {$estado}\n" . ($detalhe !== '' ? "Detalhe: {$detalhe}\n" : '') . "\nAceda ao KIAMI para acompanhar o progresso.";
    return enviarEmail($emailDestino, $assunto, $html, $texto);
}
