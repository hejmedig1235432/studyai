<?php
declare(strict_types=1);

namespace App;

use PDO;

final class DB
{
    private static ?PDO $pdo = null;

    public static function driver(): string
    {
        return strtolower(Env::get('DB_DRIVER', 'sqlite')) === 'mysql' ? 'mysql' : 'sqlite';
    }

    public static function pdo(): PDO
    {
        if (self::$pdo) {
            return self::$pdo;
        }
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        if (self::driver() === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                Env::get('DB_HOST', '127.0.0.1'),
                Env::get('DB_PORT', '3306'),
                Env::get('DB_NAME', 'studyai')
            );
            $opts[PDO::ATTR_EMULATE_PREPARES] = false;
            self::$pdo = new PDO($dsn, Env::get('DB_USER', 'root'), Env::get('DB_PASS', '') ?? '', $opts);
        } else {
            $path = self::sqlitePath();
            self::$pdo = new PDO('sqlite:' . $path, null, null, $opts);
            self::$pdo->exec('PRAGMA journal_mode=WAL');
            self::$pdo->exec('PRAGMA foreign_keys=ON');
            self::$pdo->exec('PRAGMA busy_timeout=10000');
            self::$pdo->exec('PRAGMA synchronous=NORMAL');
        }
        return self::$pdo;
    }

    public static function sqlitePath(): string
    {
        $path = Env::get('DB_DATABASE', 'storage/database.sqlite');
        if (!preg_match('~^([a-zA-Z]:)?[\\\\/]~', $path)) {
            $path = ROOT . '/' . $path;
        }
        return $path;
    }

    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function val(string $sql, array $params = []): mixed
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function col(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', array_fill(0, count($cols), '?'))
        );
        self::exec($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $params = []): int
    {
        $sets = [];
        foreach (array_keys($data) as $c) {
            $sets[] = "$c = ?";
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $sets), $where);
        return self::exec($sql, array_merge(array_values($data), $params));
    }

    /** Builds "?, ?, ?" for IN() clauses. */
    public static function in(array $values): string
    {
        return $values ? implode(',', array_fill(0, count($values), '?')) : 'NULL';
    }

    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ---------------------------------------------------------------- migrations

    private static function migrationFiles(): array
    {
        $files = glob(ROOT . '/migrations/' . self::driver() . '/*.sql') ?: [];
        sort($files);
        return $files;
    }

    /** Runs pending migrations when the migration set changed (cheap check on every request). */
    public static function autoMigrate(): void
    {
        $files = self::migrationFiles();
        $sig = md5(self::driver() . implode('|', array_map('basename', $files)) . (self::driver() === 'sqlite' ? self::sqlitePath() : Env::get('DB_NAME', '')));
        $marker = ROOT . '/storage/cache/migrated.' . self::driver();
        if (is_file($marker) && trim((string) file_get_contents($marker)) === $sig
            && (self::driver() !== 'sqlite' || is_file(self::sqlitePath()))) {
            return;
        }
        self::migrate();
        file_put_contents($marker, $sig);
    }

    /** @return string[] names of applied migrations */
    public static function migrate(): array
    {
        $pdo = self::pdo();
        $pdo->exec(self::driver() === 'mysql'
            ? 'CREATE TABLE IF NOT EXISTS migrations (name VARCHAR(190) NOT NULL PRIMARY KEY, applied_at BIGINT NOT NULL) ENGINE=InnoDB'
            : 'CREATE TABLE IF NOT EXISTS migrations (name TEXT PRIMARY KEY, applied_at INTEGER NOT NULL)');
        $done = array_flip(self::col('SELECT name FROM migrations'));
        $applied = [];
        foreach (self::migrationFiles() as $file) {
            $name = basename($file);
            if (isset($done[$name])) {
                continue;
            }
            $sql = (string) file_get_contents($file);
            $statements = array_filter(array_map('trim', preg_split('/;\s*(\r?\n|$)/', $sql) ?: []));
            // MySQL DDL auto-commits, so we only wrap SQLite in a transaction.
            if (self::driver() === 'sqlite') {
                $pdo->beginTransaction();
            }
            try {
                foreach ($statements as $stmt) {
                    $pdo->exec($stmt);
                }
                self::insert('migrations', ['name' => $name, 'applied_at' => time()]);
                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw new \RuntimeException("Migration $name failed: " . $e->getMessage(), 0, $e);
            }
            $applied[] = $name;
        }
        return $applied;
    }
}
