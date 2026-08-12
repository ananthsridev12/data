<?php

require_once __DIR__ . '/EmailAccountCipher.php';

/** Thrown on any SMTP-level failure (connect, auth, or a rejected command) -- callers show $ex->getMessage() directly, it's already the server's own reason. */
final class SmtpException extends RuntimeException
{
}

/**
 * A minimal SMTP client for sending a member's own custom email reports
 * through their own connected mailbox (see connect_email.php) -- this app
 * has no Composer/vendor dependency yet (see app/includes/SaleshandyKeyCipher.php's
 * hand-rolled encryption for the same reasoning), so rather than introduce
 * one just for outbound mail, this implements the small slice of RFC 5321
 * actually needed: EHLO, STARTTLS/implicit-TLS, AUTH LOGIN, MAIL
 * FROM/RCPT TO/DATA. TLS certificate verification is always on -- never
 * disabled, even for a misconfigured/self-signed relay.
 */
final class SmtpMailer
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption, // 'none'|'ssl'|'tls'
        private readonly string $username,
        private readonly string $password,
        private readonly string $fromEmail,
        private readonly string $fromName
    ) {
    }

    /** Loads and decrypts $userId's connected SMTP account, or null if they haven't connected one. */
    public static function forUser(PDO $db, int $userId): ?self
    {
        $stmt = $db->prepare(
            'SELECT smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password, smtp_from_email, smtp_from_name
               FROM users WHERE id = ? AND smtp_password IS NOT NULL'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $password = EmailAccountCipher::decrypt($row['smtp_password']);
        if ($password === null) {
            return null;
        }
        return new self(
            (string) $row['smtp_host'],
            (int) $row['smtp_port'],
            (string) $row['smtp_encryption'],
            (string) $row['smtp_username'],
            $password,
            (string) $row['smtp_from_email'],
            (string) $row['smtp_from_name']
        );
    }

    /** Connects and authenticates, then disconnects -- validates credentials without sending anything, for the connect page's test-before-save. Throws SmtpException on any failure. */
    public function testConnection(): void
    {
        $sock = $this->connectAndAuthenticate();
        $this->command($sock, "QUIT\r\n", ['221']);
        fclose($sock);
    }

    /** @param string[] $recipients */
    public function send(array $recipients, string $subject, string $htmlBody): void
    {
        if (!$recipients) {
            throw new SmtpException('No recipients given.');
        }

        $sock = $this->connectAndAuthenticate();
        try {
            $this->command($sock, "MAIL FROM:<{$this->fromEmail}>\r\n", ['250']);
            foreach ($recipients as $recipient) {
                $this->command($sock, "RCPT TO:<{$recipient}>\r\n", ['250', '251']);
            }
            $this->command($sock, "DATA\r\n", ['354']);
            fwrite($sock, $this->buildMessage($recipients, $subject, $htmlBody));
            $this->readResponse($sock, ['250']);
            $this->command($sock, "QUIT\r\n", ['221']);
        } finally {
            fclose($sock);
        }
    }

    /** @return resource */
    private function connectAndAuthenticate()
    {
        if ($this->host === '' || $this->port <= 0) {
            throw new SmtpException('SMTP host/port not configured.');
        }

        $scheme = $this->encryption === 'ssl' ? 'ssl' : 'tcp';
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $sock = @stream_socket_client(
            "{$scheme}://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($sock === false) {
            throw new SmtpException("Could not connect to {$this->host}:{$this->port} -- {$errstr} ({$errno})");
        }
        stream_set_timeout($sock, 15);

        $this->readResponse($sock, ['220']);
        $this->ehlo($sock);

        if ($this->encryption === 'tls') {
            $this->command($sock, "STARTTLS\r\n", ['220']);
            if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($sock);
                throw new SmtpException('STARTTLS negotiation failed.');
            }
            $this->ehlo($sock);
        }

        if ($this->username !== '') {
            $this->command($sock, "AUTH LOGIN\r\n", ['334']);
            $this->command($sock, base64_encode($this->username) . "\r\n", ['334']);
            $this->command($sock, base64_encode($this->password) . "\r\n", ['235']);
        }

        return $sock;
    }

    /** @param resource $sock */
    private function ehlo($sock): void
    {
        $localName = gethostname() ?: 'localhost';
        $this->command($sock, "EHLO {$localName}\r\n", ['250']);
    }

    /** @param resource $sock */
    private function command($sock, string $line, array $okCodes): string
    {
        fwrite($sock, $line);
        return $this->readResponse($sock, $okCodes);
    }

    /**
     * Reads a (possibly multi-line, "250-..." continuation) SMTP response
     * and throws unless its status code is in $okCodes.
     * @param resource $sock
     * @param string[] $okCodes
     */
    private function readResponse($sock, array $okCodes): string
    {
        $lines = [];
        $code = '';
        do {
            $line = fgets($sock, 515);
            if ($line === false) {
                throw new SmtpException('Connection to SMTP server was lost while waiting for a response.');
            }
            $lines[] = $line;
            $code = substr($line, 0, 3);
            $more = substr($line, 3, 1) === '-';
        } while ($more);

        if (!in_array($code, $okCodes, true)) {
            throw new SmtpException('SMTP server rejected the request: ' . trim(implode(' ', $lines)));
        }

        return implode('', $lines);
    }

    /** @param string[] $recipients */
    private function buildMessage(array $recipients, string $subject, string $htmlBody): string
    {
        $encodedSubject = function_exists('mb_encode_mimeheader')
            ? mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n")
            : $subject;
        $fromHeader = $this->fromName !== ''
            ? '"' . str_replace('"', '', $this->fromName) . "\" <{$this->fromEmail}>"
            : $this->fromEmail;
        $atPos = strrpos($this->fromEmail, '@');
        $fromDomain = $atPos !== false ? substr($this->fromEmail, $atPos + 1) : 'localhost';

        $headers = [
            'From: ' . $fromHeader,
            'To: ' . implode(', ', $recipients),
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $fromDomain . '>',
        ];

        return implode("\r\n", $headers) . "\r\n\r\n" . $this->dotStuff($htmlBody) . "\r\n.\r\n";
    }

    /** RFC 5321 transparency: a line beginning with "." gets an extra "." prefixed, or the server reads it as the end-of-DATA marker. */
    private function dotStuff(string $body): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $body));
        foreach ($lines as &$line) {
            if (str_starts_with($line, '.')) {
                $line = '.' . $line;
            }
        }
        return implode("\r\n", $lines);
    }
}
