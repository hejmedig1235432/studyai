<?php
declare(strict_types=1);

namespace App;

/** Sends plain-text mail via "log" (default, writes storage/logs/mail.log), PHP mail(), or SMTP. */
final class Mailer
{
    public static function driver(): string
    {
        return strtolower(Env::get('MAIL_DRIVER', 'log'));
    }

    public static function send(string $to, string $subject, string $body): bool
    {
        $from = Env::get('MAIL_FROM', 'Studiepilot <no-reply@localhost>');
        try {
            return match (self::driver()) {
                'mail' => mail($to, $subject, $body, "From: $from\r\nContent-Type: text/plain; charset=utf-8"),
                'smtp' => self::smtp($to, $subject, $body, $from),
                default => self::logMail($to, $subject, $body),
            };
        } catch (\Throwable $e) {
            Support::log('Mail failed: ' . $e->getMessage());
            return false;
        }
    }

    private static function logMail(string $to, string $subject, string $body): bool
    {
        Support::log("To: $to\nSubject: $subject\n\n$body\n" . str_repeat('-', 60), 'mail');
        return true;
    }

    private static function smtp(string $to, string $subject, string $body, string $from): bool
    {
        $host = (string) Env::get('SMTP_HOST', '');
        $port = Env::int('SMTP_PORT', 587);
        $secure = strtolower((string) Env::get('SMTP_SECURE', 'tls')); // tls | ssl | none
        $user = (string) Env::get('SMTP_USER', '');
        $pass = (string) Env::get('SMTP_PASS', '');
        $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $fp = stream_socket_client($remote, $errno, $errstr, 15);
        if (!$fp) {
            throw new \RuntimeException("SMTP connect failed: $errstr");
        }
        stream_set_timeout($fp, 15);
        $read = static function () use ($fp): string {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $cmd = static function (string $c, array $ok) use ($fp, $read): string {
            fwrite($fp, $c . "\r\n");
            $r = $read();
            if (!in_array((int) substr($r, 0, 3), $ok, true)) {
                throw new \RuntimeException('SMTP error: ' . trim($r));
            }
            return $r;
        };
        $read();
        $cmd('EHLO studyai', [250]);
        if ($secure === 'tls') {
            $cmd('STARTTLS', [220]);
            stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $cmd('EHLO studyai', [250]);
        }
        if ($user !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($user), [334]);
            $cmd(base64_encode($pass), [235]);
        }
        preg_match('/<([^>]+)>/', $from, $m);
        $fromAddr = $m[1] ?? $from;
        $cmd("MAIL FROM:<$fromAddr>", [250]);
        $cmd("RCPT TO:<$to>", [250, 251]);
        $cmd('DATA', [354]);
        $headers = "From: $from\r\nTo: <$to>\r\nSubject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\nDate: " . date('r');
        $data = $headers . "\r\n\r\n" . preg_replace('/^\./m', '..', str_replace("\n", "\r\n", $body));
        $cmd($data . "\r\n.", [250]);
        $cmd('QUIT', [221]);
        fclose($fp);
        return true;
    }
}
