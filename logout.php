<?php
require_once 'auth.php';

sign_out_user();

$allowedDestinations = [
    'index.php',
    'admin.php',
    'accounts_manager.php',
    'pages/games.php',
    'pages/chat.php',
];
$destination = $_GET['next'] ?? 'index.php';

if (!in_array($destination, $allowedDestinations, true)) {
    $destination = 'index.php';
}

header('Location: ' . $destination);
exit();