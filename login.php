<?php
require_once 'db.php';
require_once 'auth.php';

$message = '';
$next = $_GET['next'] ?? $_POST['next'] ?? 'index.php';
$allowedDestinations = [
    'index.php',
    'admin.php',
    'accounts_manager.php',
    'pages/games.php',
    'pages/chat.php',
];
if (!in_array($next, $allowedDestinations, true)) {
    $next = 'index.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $message = 'Please enter your username and password.';
    } else {
        $stmt = $pdo->prepare(
            'SELECT id, username, password, is_admin FROM users WHERE username = ? LIMIT 1'
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, (string)$user['password'])) {
            sign_in_user($user);
            header('Location: ' . $next);
            exit();
        }

        $message = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In | IT Students Hub</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Varela+Round&display=swap" rel="stylesheet">
</head>
<body>
    <div class="os-window app-window classic-window" style="max-width: 520px; margin: 8vh auto;">
        <div class="window-header">
            <div class="window-title"><span class="os-icon">🔑</span> IT Students Hub / Sign In</div>
        </div>
        <div class="window-body" style="padding: 24px;">
            <div class="content-box">
                <h2>Sign in</h2>
                <?php if ($message): ?>
                    <p class="auth-error" role="alert"><?= htmlspecialchars($message) ?></p>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
                    <label for="username">Username</label>
                    <input id="username" name="username" type="text" required autocomplete="username">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" required autocomplete="current-password">
                    <button class="aero-button primary" type="submit" style="margin-top: 14px;">Sign In</button>
                </form>
                <p style="margin-bottom: 0;">
                    New account? Open <a href="pages/games.php">Games</a> or <a href="pages/chat.php">Chat</a> to register.
                </p>
            </div>
        </div>
    </div>
</body>
</html>