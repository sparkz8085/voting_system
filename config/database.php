<?php
/**
 * PDO connection singleton.
 *
 * This file is only loaded through includes/app.php. Direct requests are
 * rejected even on servers where .htaccess is not honoured.
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Forbidden.');
}

final class Database
{
    private static ?PDO $connection = null;

    private function __construct()
    {
    }

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        try {
            self::$connection = new PDO($dsn, DB_USER, DB_PASSWORD, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);

            // Align MySQL's clock with PHP's configured timezone so NOW() and
            // PHP's displayed times agree. The offset form works on MariaDB.
            $offset = (new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE)))->format('P');
            self::$connection->exec('SET time_zone = ' . self::$connection->quote($offset));
        } catch (PDOException $exception) {
            // Do not leak credentials or SQL details in production responses.
            throw new RuntimeException(
                'Could not connect to the voting database. Check config/config.local.php and confirm the database has been imported.',
                0,
                $exception
            );
        }

        return self::$connection;
    }
}
