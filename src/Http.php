<?php
declare(strict_types=1);

namespace App;

final class Http
{
    private static ?array $input = null;

    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    /** Merged JSON body + form fields + query string. */
    public static function input(): array
    {
        if (self::$input !== null) {
            return self::$input;
        }
        $data = [];
        $type = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($type, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode($raw ?: '[]', true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
        self::$input = array_merge($_GET, $_POST, $data);
        return self::$input;
    }

    public static function str(string $key, string $default = '', int $max = 10000): string
    {
        $v = self::input()[$key] ?? $default;
        if (!is_scalar($v)) {
            return $default;
        }
        $v = trim((string) $v);
        return mb_substr($v, 0, $max);
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::input()[$key] ?? null;
        return is_numeric($v) ? (int) $v : $default;
    }

    public static function arr(string $key): array
    {
        $v = self::input()[$key] ?? [];
        return is_array($v) ? $v : [];
    }

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public static function download(string $filename, string $contentType, string $body): never
    {
        $safe = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $filename) ?: 'download';
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $safe . '"');
        header('Content-Length: ' . strlen($body));
        header('Cache-Control: no-store');
        echo $body;
        exit;
    }
}
