<?php
declare(strict_types=1);

/*
 * Simple local database connection.
 *
 * Start MySQL locally, create a database called "it_website", and change
 * these four values only if your local MySQL setup uses different details.
 */
$host = '127.0.0.1';
$port = '3306';
$db = 'it_website';
$user = 'root';
$pass = '';

$dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_STRINGIFY_FETCHES => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    error_log('ITWebsite database connection failed: ' . $e->getMessage());
    http_response_code(503);
    exit('Cannot connect to MySQL. Make sure MySQL is running and the "it_website" database exists.');
}