<?php
declare(strict_types=1);

namespace App;

/** Small shared helpers: settings store, encryption, logging, text utilities. */
final class Support
{
    // ------------------------------------------------------------------ settings

    public static function setting(string $name, mixed $default = null): mixed
    {
        $v = DB::val('SELECT value FROM settings WHERE name = ?', [$name]);
        if ($v === null) {
            return $default;
        }
        $d = json_decode((string) $v, true);
        return json_last_error() === JSON_ERROR_NONE ? $d : $default;
    }

    public static function setSetting(string $name, mixed $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        if (DB::val('SELECT COUNT(*) FROM settings WHERE name = ?', [$name])) {
            DB::update('settings', ['value' => $json, 'updated_at' => time()], 'name = ?', [$name]);
        } else {
            DB::insert('settings', ['name' => $name, 'value' => $json, 'updated_at' => time()]);
        }
    }

    // ---------------------------------------------------------------- encryption

    private static function appKey(): string
    {
        $k = Env::get('APP_KEY');
        if (!$k) {
            $file = ROOT . '/storage/app.key';
            if (!is_file($file)) {
                file_put_contents($file, base64_encode(random_bytes(32)));
                @chmod($file, 0600);
            }
            $k = trim((string) file_get_contents($file));
        }
        return hash('sha256', $k, true);
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::appKey(), OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $blob): ?string
    {
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $pt = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::appKey(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $pt === false ? null : $pt;
    }

    // ------------------------------------------------------------------- logging

    public static function log(string $msg, string $file = 'app'): void
    {
        @file_put_contents(ROOT . "/storage/logs/$file.log", '[' . date('c') . '] ' . $msg . PHP_EOL, FILE_APPEND);
    }

    // ---------------------------------------------------------------------- text

    public static function utf8(string $s): string
    {
        if (str_starts_with($s, "\xEF\xBB\xBF")) {
            $s = substr($s, 3);
        } elseif (str_starts_with($s, "\xFF\xFE")) {
            $s = mb_convert_encoding(substr($s, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($s, "\xFE\xFF")) {
            $s = mb_convert_encoding(substr($s, 2), 'UTF-8', 'UTF-16BE');
        }
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        }
        // Strip control chars except tab/newline.
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? $s;
    }

    public static function clean(string $s): string
    {
        $s = self::utf8($s);
        $s = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $s);
        $s = preg_replace('/[ \t]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/ *\n */u', "\n", $s) ?? $s;
        $s = preg_replace('/\n{3,}/u', "\n\n", $s) ?? $s;
        return trim($s);
    }

    public static function excerpt(string $s, int $len): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
        return mb_strlen($s) > $len ? rtrim(mb_substr($s, 0, $len - 1)) . '…' : $s;
    }

    /** Normalises an answer for lenient comparison. */
    public static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        if (class_exists('Normalizer')) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_D);
            if ($n !== false) {
                $s = preg_replace('/\p{Mn}+/u', '', $n) ?? $s;
            }
        }
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/^(the|a|an|de|het|een) /u', '', trim($s)) ?? $s;
        return trim($s);
    }

    public static function jsonOrNull(mixed $v): ?string
    {
        return $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public static function decode(?string $json, mixed $default = []): mixed
    {
        if ($json === null || $json === '') {
            return $default;
        }
        $d = json_decode($json, true);
        return $d ?? $default;
    }

    public static function today(int $offsetDays = 0): string
    {
        return date('Y-m-d', time() + $offsetDays * 86400);
    }
}
