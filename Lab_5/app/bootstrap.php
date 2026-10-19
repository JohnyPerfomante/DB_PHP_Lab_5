<?php
declare(strict_types=1);
date_default_timezone_set('Europe/Kyiv');
ini_set('session.use_strict_mode', '1');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $dsn = 'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1')
             . ';dbname=' . (getenv('DB_NAME') ?: 'fleet_stock') . ';charset=utf8mb4';
        $pdo = new PDO($dsn, getenv('DB_USER') ?: 'fleet', getenv('DB_PASS') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}
function run(string $sql, array $params = []): PDOStatement {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}
function e(mixed $text): string { return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function input(string $key): string {
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) throw new DomainException('Некоректний формат поля ' . $key);
    return trim($value);
}
function text_field(string $key, string $label, int $min, int $max): string {
    $value = input($key);
    $length = preg_match_all('/./us', $value);
    if ($length === false || $length < $min || $length > $max) {
        throw new DomainException("$label: довжина від $min до $max символів.");
    }
    return $value;
}
function integer(string $key, int $min = 1, int $max = 1000000): int {
    $value = input($key);
    if (!preg_match('/^\d+$/D', $value) || strlen($value) > 10 || (int)$value < $min || (int)$value > $max) {
        throw new DomainException("Поле $key: потрібне ціле число від $min до $max.");
    }
    return (int)$value;
}
function csrf(): string { return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'; }
function redirect(string $page, string $message): never {
    $_SESSION['flash'] = $message;
    header('Location: /?page=' . rawurlencode($page), true, 303);
    exit;
}
function quantity_status(int $qty, int $min): array {
    if ($qty === 0) return ['empty', 'Відсутні'];
    return $qty <= $min ? ['low', 'Низький запас'] : ['ok', 'Достатньо'];
}
function local_date(string $utc): string {
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('Europe/Kyiv'))->format('d.m.Y H:i');
}
