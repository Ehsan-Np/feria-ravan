<?php
/**
 * ============================================================================
 *  لایهٔ دسترسی به دیتابیس — PDO با Prepared Statement (امن در برابر SQLi)
 * ============================================================================
 */
declare(strict_types=1);

final class DB
{
    private static ?PDO $pdo = null;

    /** اتصال (تک‌نمونه — Lazy Singleton) */
    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER, DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false, // ← Prepared واقعی (امنیتی)
                ]
            );
        }
        return self::$pdo;
    }

    /** نام جدول با پیشوند */
    public static function t(string $name): string
    {
        return '`' . DB_PREFIX . $name . '`';
    }

    /**
     * اجرای کوئری با پارامتر
     * ─ مقادیر بر اساس نوع PHP بایند می‌شوند (int → PARAM_INT و …).
     * ─ این کار برای کوئری‌های دارای LIMIT ?/OFFSET ? حیاتی است: MySQL در حالت
     *   Prepared واقعی (بدون emulation) فقط عددِ صحیح را در LIMIT می‌پذیرد؛
     *   ارسال رشته باعث خطای سینتکسی و توقف کامل کوئری می‌شد.
     */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        foreach (array_values($params) as $i => $v) {
            if (is_int($v) || is_bool($v)) {
                $st->bindValue($i + 1, (int)$v, PDO::PARAM_INT);
            } elseif ($v === null) {
                $st->bindValue($i + 1, null, PDO::PARAM_NULL);
            } elseif (is_float($v)) {
                $st->bindValue($i + 1, (string)$v, PDO::PARAM_STR);
            } else {
                $st->bindValue($i + 1, (string)$v, PDO::PARAM_STR);
            }
        }
        $st->execute();
        return $st;
    }

    /** گرفتن یک ردیف */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** گرفتن همهٔ ردیف‌ها */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** یک مقدار اسکالر */
    public static function val(string $sql, array $params = [])
    {
        return self::run($sql, $params)->fetchColumn();
    }

    /** درج و بازگرداندن شناسه */
    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql  = 'INSERT INTO ' . self::t($table)
              . ' (`' . implode('`,`', $cols) . '`) VALUES (' . rtrim(str_repeat('?,', count($cols)), ',') . ')';
        self::run($sql, array_values($data));
        return (int)self::pdo()->lastInsertId();
    }

    /** به‌روزرسانی شرطی ساده */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set = implode(',', array_map(fn($c) => "`$c`=?", array_keys($data)));
        $st  = self::run(
            'UPDATE ' . self::t($table) . " SET $set WHERE $where",
            array_merge(array_values($data), $whereParams)
        );
        return $st->rowCount();
    }

    private static ?array $settingsCache = null;

    /** خواندن تنظیمات (با کش داخلی برای سرعت) */
    public static function setting(string $key, $default = null)
    {
        if (self::$settingsCache === null) {
            self::$settingsCache = array_column(self::all('SELECT `key`,`value` FROM ' . self::t('settings') . ' WHERE autoload=1'), 'value', 'key');
        }
        return self::$settingsCache[$key] ?? $default;
    }

    /** ذخیرهٔ یک تنظیم (کش هم‌راستا می‌شود تا خواندنِ بعدیِ همان درخواست تازه باشد) */
    public static function setSetting(string $key, $value): void
    {
        self::run(
            'INSERT INTO ' . self::t('settings') . ' (`key`,`value`) VALUES (?,?)
             ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)',
            [$key, $value]
        );
        if (self::$settingsCache !== null) self::$settingsCache[$key] = $value;
    }
}
