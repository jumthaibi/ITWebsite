<?php
require_once '../db.php';
require_once '../auth.php';
require_once 'profile_helpers.php';

$auth_error = '';
$auth_mode = 'login';
$auth_open = false;
$chat_error = '';
$profile_schema_ready = ensureProfileSchema($pdo);

function getProfileImageUrl(?string $imagePath): string {
    if (empty($imagePath)) {
        return '';
    }
    $imagePath = trim($imagePath);
    if (preg_match('/^(https?:\/\/|data:)/i', $imagePath)) {
        return $imagePath;
    }
    $clean = ltrim($imagePath, '/');
    if (str_starts_with($clean, 'portal/')) {
        $clean = substr($clean, 7);
    }
    if (str_starts_with($clean, 'pages/')) {
        $clean = substr($clean, 6);
    }
    $clean = ltrim($clean, './');
    if (str_starts_with($clean, '../')) {
        return $clean;
    }
    return '../' . $clean;
}

function chatIdentity(PDO $pdo): array
{
    $userId = (int) ($_SESSION['games_user_id'] ?? 0);
    $username = (string) ($_SESSION['games_username'] ?? '');
    $isAdmin = (int) ($_SESSION['games_is_admin'] ?? 0);

    return [
        'id' => $userId,
        'username' => $username,
        'is_admin' => $isAdmin === 1,
    ];
}

function chatJsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

function fetchChatMessages(PDO $pdo, bool $profileSchemaReady = true): array
{
    $profileColumns = $profileSchemaReady
        ? ', COALESCE(u.username, m.username) AS current_username, COALESCE(u.profile_image_data, \'\') AS profile_image, COALESCE(u.profile_image_type, \'\') AS profile_image_type'
        : ', m.username AS current_username, \'\' AS profile_image, \'\' AS profile_image_type';
    $stmt = $pdo->query(
        'SELECT m.id, m.user_id, m.username, m.message, m.image_data, m.image_mime, m.created_at,
                COALESCE(u.is_admin, 0) AS author_is_admin' . $profileColumns . '
         FROM chat_messages m
         LEFT JOIN users u ON u.id = m.user_id
         ORDER BY m.created_at DESC, m.id DESC
         LIMIT 500'
    );

    return array_reverse($stmt->fetchAll());
}

function chatMessagePayload(array $message, int $user_id, bool $is_admin): array
{
    $image_src = null;
    if (!empty($message['image_data'])) {
        $mime = !empty($message['image_mime']) ? $message['image_mime'] : 'image/jpeg';
        $data = $message['image_data'];
        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }
        $image_src = 'data:' . $mime . ';base64,' . base64_encode($data);
    }

    $profileImageRaw = (string) ($message['profile_image'] ?? '');

    return [
        'id' => (int) $message['id'],
        'user_id' => (int) $message['user_id'],
        'username' => (string) ($message['current_username'] ?? $message['username']),
        'message' => $message['message'] !== null ? (string) $message['message'] : null,
        'image_src' => $image_src,
        'profile_image' => getProfileImageUrl(profileImageToDataUri($profileImageRaw, (string) ($message['profile_image_type'] ?? ''))),
        'created_at' => date(DATE_ATOM, strtotime($message['created_at'])),
        'time_label' => date('M j, Y · g:i A', strtotime($message['created_at'])),
        'author_is_admin' => (int) $message['author_is_admin'] === 1,
        'can_delete' => $is_admin || (int) $message['user_id'] === $user_id,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth_action'])) {
    $auth_mode = $_POST['auth_action'] === 'register' ? 'register' : 'login';
    $auth_open = true;
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $username)) {
        $auth_error = 'Use 3–30 characters: letters, numbers, dots, dashes, or underscores.';
    } elseif ($password === '' || ($auth_mode === 'register' && strlen($password) < 4)) {
        $auth_error = $auth_mode === 'register'
            ? 'Your password must be at least 4 characters.'
            : 'Please enter your password.';
    } else {
        $stmt = $pdo->prepare('SELECT id, username, password, is_admin FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($auth_mode === 'register') {
            if ($user) {
                $auth_error = 'That username is already taken. Please choose another one.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO users (username, password, is_admin) VALUES (?, ?, 0)');
                $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
                $_SESSION['games_user_id'] = (int) $pdo->lastInsertId();
                $_SESSION['games_username'] = $username;
                $_SESSION['games_is_admin'] = 0;
                $_SESSION['user_id'] = (int) $pdo->lastInsertId();
                $_SESSION['username'] = $username;
                $_SESSION['is_admin'] = 0;
                $_SESSION['is_admin'] = 0;
                header('Location: chat.php');
                exit();
            }
        } elseif ($user && password_verify($password, (string) $user['password'])) {
            sign_in_user($user);
            header('Location: chat.php');
            exit();
        } else {
            $auth_error = 'That username and password do not match.';
        }
    }
}

$identity = chatIdentity($pdo);
$chat_user_id = $identity['id'];
$chat_username = $identity['username'];
$chat_is_admin = $identity['is_admin'];
$chat_logged_in = $chat_user_id > 0 && $chat_username !== '';
$chat_profile = $profile_schema_ready ? getUserProfile($pdo, $chat_user_id) : null;
$chat_profile_image = getProfileImageUrl($chat_profile['profile_image'] ?? '');
$chat_ajax = ($_POST['chat_ajax'] ?? '') === '1';

$chat_ready = true;

$chat_api = (string) ($_GET['chat_api'] ?? '');
if ($chat_api === 'messages') {
    if (!$chat_logged_in) {
        chatJsonResponse(['ok' => false, 'error' => 'Please sign in before using the chat.'], 401);
    }
    if (!$chat_ready) {
        chatJsonResponse(['ok' => false, 'error' => 'The chat database is not available right now.'], 503);
    }

    $messages = array_map(
        fn (array $message): array => chatMessagePayload($message, $chat_user_id, $chat_is_admin),
        fetchChatMessages($pdo, $profile_schema_ready)
    );
    chatJsonResponse(['ok' => true, 'messages' => $messages]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['chat_action'] ?? '') !== '') {
    if (!$chat_logged_in) {
        $chat_error = 'Please sign in before using the chat.';
        $auth_open = true;
    } elseif (!$chat_ready) {
        $chat_error = 'The chat database is not available right now.';
    } elseif ($_POST['chat_action'] === 'delete') {
        $message_id = filter_var($_POST['message_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$message_id) {
            $chat_error = 'That message could not be found.';
        } else {
            $stmt = $pdo->prepare('SELECT id, user_id FROM chat_messages WHERE id = ? LIMIT 1');
            $stmt->execute([$message_id]);
            $message_to_delete = $stmt->fetch();

            if (!$message_to_delete) {
                $chat_error = 'That message has already been removed.';
            } elseif (!$chat_is_admin && (int) $message_to_delete['user_id'] !== $chat_user_id) {
                $chat_error = 'You can only delete your own messages.';
            } else {
                $stmt = $pdo->prepare('DELETE FROM chat_messages WHERE id = ?');
                $stmt->execute([$message_id]);
                if ($chat_ajax) {
                    chatJsonResponse(['ok' => true, 'deleted_id' => $message_id]);
                }
                header('Location: chat.php');
                exit();
            }
        }
    } elseif ($_POST['chat_action'] === 'send') {
        $message_text = trim($_POST['message'] ?? '');
        $image_data = null;
        $image_mime = null;
        $has_upload = isset($_FILES['chat_image']) && $_FILES['chat_image']['error'] !== UPLOAD_ERR_NO_FILE;

        if (mb_strlen($message_text) > 2000) {
            $chat_error = 'Messages must be 2,000 characters or fewer.';
        } elseif ($has_upload && $_FILES['chat_image']['error'] !== UPLOAD_ERR_OK) {
            $chat_error = 'The image could not be uploaded.';
        } elseif ($has_upload && (int) $_FILES['chat_image']['size'] > 2 * 1024 * 1024) {
            $chat_error = 'Images must be 2 MB or smaller after compression.';
        } elseif ($message_text === '' && !$has_upload) {
            $chat_error = 'Write a message or choose an image first.';
        } elseif ($has_upload) {
            $image_info = @getimagesize($_FILES['chat_image']['tmp_name']);
            $allowed_mimes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
            ];
            $image_mime = $image_info['mime'] ?? '';

            if (!$image_info || !isset($allowed_mimes[$image_mime])) {
                $chat_error = 'Please choose a valid JPG, PNG, GIF, or WebP image.';
            } else {
                $image_data = file_get_contents($_FILES['chat_image']['tmp_name']);
                if ($image_data === false) {
                    $chat_error = 'The image data could not be read.';
                }
            }
        }

        if ($chat_error === '') {
            try {
                $stmt = $pdo->prepare('INSERT INTO chat_messages (user_id, username, message, image_data, image_mime) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([
                    $chat_user_id, 
                    $chat_username, 
                    $message_text !== '' ? $message_text : null, 
                    $image_data, 
                    $image_mime
                ]);
                if ($chat_ajax) {
                    chatJsonResponse(['ok' => true, 'message_id' => (int) $pdo->lastInsertId()]);
                }
                header('Location: chat.php');
                exit();
            } catch (Throwable $e) {
                $chat_error = 'Your message could not be sent.';
            }
        }
    }

    if ($chat_ajax) {
        chatJsonResponse(['ok' => false, 'error' => $chat_error !== '' ? $chat_error : 'That chat action could not be completed.'], 422);
    }
}

if (!$chat_logged_in) {
    $auth_open = true;
}

$chat_messages = [];
if ($chat_ready) {
    $chat_messages = fetchChatMessages($pdo, $profile_schema_ready);
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Chat | IT Students Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Varela+Round&display=swap" rel="stylesheet">
</head>
<body>
    <div class="os-window app-window chat-window">
        <div class="window-header">
            <div class="window-title"><span class="os-icon">🌐</span> IT-Students-Hub.io - chat</div>
            <div class="window-controls">
                <span class="control-btn minimize"></span>
                <span class="control-btn maximize"></span>
                <span class="control-btn close"></span>
            </div>
        </div>

        <div class="browser-toolbar">
            <div class="nav-arrows"><button class="arrow-btn" type="button">⬅</button><button class="arrow-btn" type="button">➡</button></div>
            <div class="address-bar"><span>🔒 http://it-students-hub.io/portal/pages/chat.php</span></div>
            <div class="browser-tools"><button class="tool-btn" type="button">🔍</button></div>
        </div>

        <div class="browser-tabs">
            <div class="tab"><a href="../index.php" style="text-decoration:none; color:inherit;">🏠 home</a></div>
            <div class="tab"><a href="../materials/materials.php" style="text-decoration:none; color:inherit;">📚 materials</a></div>
            <div class="tab"><a href="gpa.php" style="text-decoration:none; color:inherit;">📊 gpa calculator</a></div>
            <div class="tab"><a href="games.php" style="text-decoration:none; color:inherit;">🎮 games</a></div>
            <div class="tab active"><a href="chat.php" style="text-decoration:none; color:inherit;">💬 chat</a></div>
            <div class="navbar-account">
                <?php if ($chat_logged_in): ?>
                    <div class="games-user-chip">
                        <button class="profile-trigger games-profile-trigger" type="button" data-profile-user-id="<?= (int) $chat_user_id ?>" aria-label="Open your profile">
                            <span class="profile-trigger-avatar">
                                <?php if ($chat_profile_image): ?>
                                    <img class="profile-avatar-image" src="<?= htmlspecialchars($chat_profile_image) ?>" alt="">
                                <?php else: ?>
                                    👤
                                <?php endif; ?>
                            </span>
                            <strong><?= htmlspecialchars($chat_username) ?><?= $chat_is_admin ? ' (admin)' : '' ?></strong>
                        </button>
                        <a class="games-logout" href="../logout.php?next=pages/chat.php" aria-label="Log out" title="Log out">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M10 5H6.75A1.75 1.75 0 0 0 5 6.75v10.5A1.75 1.75 0 0 0 6.75 19H10"></path>
                                <path d="M12 12h7"></path>
                                <path d="m16 8 4 4-4 4"></path>
                            </svg>
                            <span>Log out</span>
                        </a>
                    </div>
                <?php else: ?>
                    <button class="games-login-link" id="openChatAuth" type="button">🔐 sign in</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="window-body chat-body<?= !$chat_logged_in ? ' is-locked' : '' ?>">
            <main class="main-content chat-main">
                <section class="content-box chat-card">
                    <div class="chat-card-heading">
                        <div>
                            <span class="card-header-tag">LIVE BOARD</span>
                            <h3>Messages from the community</h3>
                        </div>
                        <div class="chat-heading-status">
                            <span class="chat-live-status" id="chatLiveStatus">● LIVE · syncing</span>
                            <span class="chat-count" id="chatMessageCount"><?= count($chat_messages) ?> messages</span>
                        </div>
                    </div>

                    <?php if ($chat_error): ?>
                        <div class="auth-error chat-error" role="alert">⚠️ <?= htmlspecialchars($chat_error) ?></div>
                    <?php endif; ?>
                    <div class="auth-error chat-error" id="chatClientError" role="alert" hidden></div>

                    <div class="chat-messages" id="chatMessages" aria-live="polite">
                        <?php if (!$chat_messages): ?>
                            <div class="chat-empty">No messages yet. Start the conversation! 🫧</div>
                        <?php else: ?>
                            <?php foreach ($chat_messages as $chat_message): ?>
                                <?php
                                $is_own_message = $chat_logged_in && (int) $chat_message['user_id'] === $chat_user_id;
                                $can_delete = $chat_is_admin || $is_own_message;
                                
                                $image_src = '';
                                if (!empty($chat_message['image_data'])) {
                                    $mime = !empty($chat_message['image_mime']) ? $chat_message['image_mime'] : 'image/jpeg';
                                    $data = $chat_message['image_data'];
                                    if (is_resource($data)) {
                                        $data = stream_get_contents($data);
                                    }
                                    $image_src = 'data:' . $mime . ';base64,' . base64_encode($data);
                                }
                                $msg_profile_image = getProfileImageUrl(profileImageToDataUri((string) ($chat_message['profile_image'] ?? ''), (string) ($chat_message['profile_image_type'] ?? '')));
                                ?>
                                <article class="chat-message <?= $is_own_message ? 'is-own' : 'is-other' ?>">
                                    <div class="chat-message-meta">
                                        <button class="profile-trigger chat-author-profile" type="button" data-profile-user-id="<?= (int) $chat_message['user_id'] ?>" aria-label="Open <?= htmlspecialchars($chat_message['current_username'] ?? $chat_message['username']) ?>'s profile">
                                            <span class="profile-trigger-avatar">
                                                <?php if ($msg_profile_image !== ''): ?>
                                                    <img class="profile-avatar-image" src="<?= htmlspecialchars($msg_profile_image) ?>" alt="">
                                                <?php else: ?>
                                                    👤
                                                <?php endif; ?>
                                            </span>
                                            <strong><?= htmlspecialchars($chat_message['current_username'] ?? $chat_message['username']) ?><?= (int) $chat_message['author_is_admin'] === 1 ? ' <span class="chat-admin-label">(admin)</span>' : '' ?></strong>
                                        </button>
                                        <time datetime="<?= htmlspecialchars(date(DATE_ATOM, strtotime($chat_message['created_at']))) ?>"><?= htmlspecialchars(date('M j, Y · g:i A', strtotime($chat_message['created_at']))) ?></time>
                                        <?php if ($can_delete): ?>
                                            <form method="post" action="chat.php" class="chat-delete-form">
                                                <input type="hidden" name="chat_action" value="delete">
                                                <input type="hidden" name="message_id" value="<?= (int) $chat_message['id'] ?>">
                                                <button type="submit" class="chat-delete" title="<?= $chat_is_admin && !$is_own_message ? 'Delete this message as admin' : 'Delete your message' ?>">🗑️</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($chat_message['message'] !== null && $chat_message['message'] !== ''): ?>
                                        <p><?= nl2br(htmlspecialchars($chat_message['message'])) ?></p>
                                    <?php endif; ?>
                                    <?php if ($image_src): ?>
                                        <a class="chat-image-link" href="<?= htmlspecialchars($image_src) ?>" data-chat-image="<?= htmlspecialchars($image_src) ?>" data-chat-image-alt="Image sent by <?= htmlspecialchars($chat_message['username']) ?>">
                                            <img src="<?= htmlspecialchars($image_src) ?>" alt="Image sent by <?= htmlspecialchars($chat_message['username']) ?>">
                                        </a>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <?php if ($chat_logged_in): ?>
                        <form class="chat-composer" method="post" action="chat.php" enctype="multipart/form-data">
                            <input type="hidden" name="chat_action" value="send">
                            <label for="chatMessage">Write a message</label>
                            <textarea id="chatMessage" name="message" maxlength="2000" placeholder="Share something with the community..."></textarea>
                            <div class="chat-composer-actions">
                                <label class="chat-image-picker" for="chatImage">🖼️ Add image</label>
                                <input id="chatImage" name="chat_image" type="file" accept="image/jpeg,image/png,image/gif,image/webp">
                                <span class="chat-file-name" id="chatFileName">No image selected</span>
                                <button class="aero-button primary" type="submit">Send message</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="chat-login-prompt">
                            <strong>Sign in to join the conversation.</strong>
                            <span>Your account also works in the Pixel Games section.</span>
                            <button class="aero-button primary" id="openChatAuthInline" type="button">Sign in or create account</button>
                        </div>
                    <?php endif; ?>
                </section>
            </main>
        </div>

        <div class="window-statusbar">
            <span>💬 student chat loaded | images compressed to under 2 MB (stored in database)</span>
            <span class="status-icons-right">📶 🔊 🪟</span>
        </div>
    </div>

    <?php include 'profile_modal.php'; ?>
    <script>
        window.profileConfig = {
            endpoint: 'profile.php',
            currentUserId: <?= (int) $chat_user_id ?>
        };
    </script>
    <script src="../assets/js/profile.js"></script>

    <div class="auth-modal <?= $auth_open ? 'open' : '' ?>" id="chatAuthModal" aria-hidden="<?= $auth_open ? 'false' : 'true' ?>">
        <div class="os-window auth-dialog-window chat-simple-dialog" role="dialog" aria-modal="true" aria-labelledby="chatAuthTitle">
            <div class="window-header">
                <div class="window-title"><span class="os-icon">💬</span> Student Chat Sign In</div>
                <div class="window-controls">
                    <span class="control-btn minimize"></span>
                    <span class="control-btn maximize"></span>
                    <button class="control-btn close auth-window-close" id="closeChatAuth" type="button" aria-label="Close sign in dialog"></button>
                </div>
            </div>
            <div class="browser-toolbar"><div class="address-bar"><span>Chat Account Dialog</span></div></div>
            <div class="window-body auth-dialog-body">
                <div class="content-box auth-panel">
                    <span class="card-header-tag">COMMUNITY CHAT ACCESS</span>
                    <h2 id="chatAuthTitle">Enter the student chat 💬</h2>
                    <p class="auth-description">Use your Pixel Games account, or create one to join the conversation.</p>
                    <div class="auth-tabs">
                        <button type="button" class="<?= $auth_mode === 'login' ? 'active' : '' ?>" data-chat-auth-mode="login">Sign in</button>
                        <button type="button" class="<?= $auth_mode === 'register' ? 'active' : '' ?>" data-chat-auth-mode="register">Create account</button>
                    </div>
                    <?php if ($auth_error): ?>
                        <div class="auth-error" role="alert">⚠️ <?= htmlspecialchars($auth_error) ?></div>
                    <?php endif; ?>
                    <form class="auth-form <?= $auth_mode === 'register' ? 'is-register' : '' ?>" id="chatAuthForm" method="post" action="chat.php">
                        <input type="hidden" name="auth_action" id="chatAuthAction" value="<?= $auth_mode ?>">
                        <label for="chatAuthUsername">Username</label>
                        <input id="chatAuthUsername" name="username" type="text" minlength="3" maxlength="30" pattern="[A-Za-z0-9_.-]{3,30}" autocomplete="username" placeholder="e.g. pixel_student" required>
                        <small>3–30 letters, numbers, dots, dashes, or underscores.</small>
                        <label for="chatAuthPassword">Password</label>
                        <input id="chatAuthPassword" name="password" type="password" minlength="4" autocomplete="<?= $auth_mode === 'register' ? 'new-password' : 'current-password' ?>" placeholder="Your password" required>
                        <button class="aero-button primary auth-submit" type="submit"><?= $auth_mode === 'register' ? 'Create account & join' : 'Sign in & join' ?></button>
                    </form>
                </div>
            </div>
            <div class="window-statusbar"><span>Frutiger Aero Account System</span><span>Ready</span></div>
        </div>
    </div>

    <!-- Keep chat logout consistent with the Pixel Games account flow. -->
    <div class="auth-modal games-alert-modal" id="chatLogoutModal" aria-hidden="true">
        <div class="os-window auth-dialog-window games-alert-window chat-simple-dialog" role="alertdialog" aria-modal="true" aria-labelledby="chatLogoutTitle">
            <div class="window-header">
                <div class="window-title"><span class="os-icon">⚠️</span> System Notice</div>
                <div class="window-controls">
                    <span class="control-btn minimize"></span>
                    <span class="control-btn maximize"></span>
                    <button class="control-btn close auth-window-close" id="closeChatLogout" type="button" aria-label="Close logout confirmation"></button>
                </div>
            </div>
            <div class="browser-toolbar">
                <div class="address-bar"><span>System Alert Dialog</span></div>
            </div>
            <div class="window-body auth-dialog-body">
                <div class="content-box auth-panel games-alert-panel">
                    <span class="card-header-tag">ACCOUNT NOTICE</span>
                    <h2 id="chatLogoutTitle">Logout Confirmation</h2>
                    <p class="auth-description">Are you sure you want to leave the student chat? You can sign in again anytime to rejoin the conversation.</p>
                    <div class="games-alert-actions">
                        <button class="aero-button primary" id="confirmChatLogout" type="button">Log out</button>
                        <button class="aero-button secondary" id="cancelChatLogout" type="button">Stay signed in</button>
                    </div>
                </div>
            </div>
            <div class="window-statusbar"><span>Frutiger Aero Alert System</span><span>Ready</span></div>
        </div>
    </div>

    <div class="chat-image-lightbox" id="chatImageLightbox" aria-hidden="true">
        <div class="chat-image-lightbox-backdrop" data-chat-image-close></div>
        <div class="chat-image-lightbox-dialog" role="dialog" aria-modal="true" aria-labelledby="chatImageLightboxTitle">
            <div class="chat-image-lightbox-header">
                <strong id="chatImageLightboxTitle">Chat image preview</strong>
                <button class="chat-image-lightbox-close" id="closeChatImage" type="button" aria-label="Close image preview">×</button>
            </div>
            <div class="chat-image-lightbox-body">
                <img id="chatImageLightboxImage" src="" alt="">
            </div>
        </div>
    </div>

    <script>
        (() => {
            const resolveProfileImageUrl = (imagePath) => {
                if (!imagePath) return '';
                if (/^(https?:\/\/|data:)/i.test(imagePath)) return imagePath;
                let clean = String(imagePath).replace(/^\/+/, '');
                if (clean.startsWith('portal/')) clean = clean.substring(7);
                if (clean.startsWith('pages/')) clean = clean.substring(6);
                clean = clean.replace(/^\.\/+/, '');
                return clean.startsWith('../') ? clean : `../${clean}`;
            };

            const modal = document.getElementById('chatAuthModal');
            const openButtons = [document.getElementById('openChatAuth'), document.getElementById('openChatAuthInline')].filter(Boolean);
            const closeButton = document.getElementById('closeChatAuth');
            const chatLogoutLink = document.querySelector('.games-logout');
            const chatLogoutModal = document.getElementById('chatLogoutModal');
            const confirmChatLogout = document.getElementById('confirmChatLogout');
            const cancelChatLogout = document.getElementById('cancelChatLogout');
            const closeChatLogout = document.getElementById('closeChatLogout');
            const imageLightbox = document.getElementById('chatImageLightbox');
            const imageLightboxImage = document.getElementById('chatImageLightboxImage');
            const closeChatImage = document.getElementById('closeChatImage');
            const action = document.getElementById('chatAuthAction');
            const password = document.getElementById('chatAuthPassword');
            const submit = document.querySelector('#chatAuthForm .auth-submit');
            let logoutUrl = '';

            const syncModalLock = () => {
                const hasOpenOverlay = modal.classList.contains('open')
                    || chatLogoutModal.classList.contains('open')
                    || imageLightbox.classList.contains('open');
                document.body.classList.toggle('modal-open', hasOpenOverlay);
            };

            const openModal = () => {
                modal.classList.add('open');
                modal.setAttribute('aria-hidden', 'false');
                syncModalLock();
            };
            const closeModal = () => {
                modal.classList.remove('open');
                modal.setAttribute('aria-hidden', 'true');
                syncModalLock();
            };

            const openLogoutAlert = (event) => {
                event.preventDefault();
                logoutUrl = chatLogoutLink.href;
                chatLogoutModal.classList.add('open');
                chatLogoutModal.setAttribute('aria-hidden', 'false');
                syncModalLock();
                cancelChatLogout.focus();
            };
            const closeLogoutAlert = () => {
                chatLogoutModal.classList.remove('open');
                chatLogoutModal.setAttribute('aria-hidden', 'true');
                syncModalLock();
            };

            const openImageLightbox = (src, alt = 'Chat image preview') => {
                if (!src || !imageLightbox || !imageLightboxImage) return;
                imageLightboxImage.src = src;
                imageLightboxImage.alt = alt;
                imageLightbox.classList.add('open');
                imageLightbox.setAttribute('aria-hidden', 'false');
                syncModalLock();
                closeChatImage.focus();
            };

            const closeImageLightbox = () => {
                if (!imageLightbox || !imageLightboxImage) return;
                imageLightbox.classList.remove('open');
                imageLightbox.setAttribute('aria-hidden', 'true');
                imageLightboxImage.src = '';
                imageLightboxImage.alt = '';
                syncModalLock();
            };

            openButtons.forEach((button) => button.addEventListener('click', openModal));
            if (closeButton) closeButton.addEventListener('click', closeModal);
            if (chatLogoutLink) chatLogoutLink.addEventListener('click', openLogoutAlert);
            confirmChatLogout.addEventListener('click', () => {
                if (logoutUrl) window.location.href = logoutUrl;
            });
            cancelChatLogout.addEventListener('click', closeLogoutAlert);
            closeChatLogout.addEventListener('click', closeLogoutAlert);
            chatLogoutModal.addEventListener('click', (event) => {
                if (event.target === chatLogoutModal) closeLogoutAlert();
            });
            closeChatImage.addEventListener('click', closeImageLightbox);
            imageLightbox.addEventListener('click', (event) => {
                if (event.target === imageLightbox || event.target.matches('[data-chat-image-close]')) {
                    closeImageLightbox();
                }
            });
            document.querySelectorAll('[data-chat-auth-mode]').forEach((button) => {
                button.addEventListener('click', () => {
                    const mode = button.dataset.chatAuthMode;
                    action.value = mode;
                    document.querySelectorAll('[data-chat-auth-mode]').forEach((tab) => tab.classList.toggle('active', tab === button));
                    password.autocomplete = mode === 'register' ? 'new-password' : 'current-password';
                    submit.textContent = mode === 'register' ? 'Create account & join' : 'Sign in & join';
                });
            });

            const fileInput = document.getElementById('chatImage');
            const fileName = document.getElementById('chatFileName');
            // Keep the final multipart upload below common 2 MB PHP limits.
            const maxImageBytes = 1.8 * 1024 * 1024;
            const targetImageBytes = 1.4 * 1024 * 1024;
            let compressionRequest = 0;

            const formatFileSize = (bytes) => {
                if (bytes < 1024 * 1024) {
                    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
                }
                return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
            };

            const canvasToBlob = (canvas, quality) => new Promise((resolve, reject) => {
                canvas.toBlob((blob) => {
                    if (blob) {
                        resolve(blob);
                    } else {
                        reject(new Error('The browser could not compress this image.'));
                    }
                }, 'image/jpeg', quality);
            });

            const compressImage = (file) => new Promise((resolve, reject) => {
                const objectUrl = URL.createObjectURL(file);
                const image = new Image();

                image.onload = async () => {
                    try {
                        const maxDimension = 1800;
                        let scale = Math.min(1, maxDimension / Math.max(image.naturalWidth, image.naturalHeight));
                        let quality = 0.76;
                        let blob = null;

                        for (let attempt = 0; attempt < 12; attempt += 1) {
                            const canvas = document.createElement('canvas');
                            canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
                            canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
                            const context = canvas.getContext('2d', { alpha: false });
                            context.fillStyle = '#ffffff';
                            context.fillRect(0, 0, canvas.width, canvas.height);
                            context.drawImage(image, 0, 0, canvas.width, canvas.height);
                            blob = await canvasToBlob(canvas, quality);

                            if (blob.size <= targetImageBytes) {
                                break;
                            }

                            if (quality > 0.4) {
                                quality -= 0.08;
                            } else {
                                scale *= 0.75;
                                quality = 0.68;
                            }
                        }

                        if (!blob || blob.size > maxImageBytes) {
                            throw new Error('This image is still larger than 1.8 MB after compression. Try a smaller image.');
                        }

                        const compressedName = file.name.replace(/\.[^/.]+$/, '') + '.jpg';
                        resolve(new File([blob], compressedName, {
                            type: 'image/jpeg',
                            lastModified: Date.now()
                        }));
                    } catch (error) {
                        reject(error);
                    } finally {
                        URL.revokeObjectURL(objectUrl);
                    }
                };

                image.onerror = () => {
                    URL.revokeObjectURL(objectUrl);
                    reject(new Error('The selected file is not a readable image.'));
                };
                image.src = objectUrl;
            });

            if (fileInput && fileName) {
                fileInput.addEventListener('change', async () => {
                    const file = fileInput.files[0];
                    const requestId = ++compressionRequest;

                    if (!file) {
                        fileName.textContent = 'No image selected';
                        return;
                    }

                    fileName.textContent = 'Compressing image…';
                    try {
                        const compressedFile = await compressImage(file);
                        if (requestId !== compressionRequest) {
                            return;
                        }

                        const dataTransfer = new DataTransfer();
                        dataTransfer.items.add(compressedFile);
                        fileInput.files = dataTransfer.files;
                        fileName.textContent = `${compressedFile.name} (${formatFileSize(compressedFile.size)}, compressed)`;
                    } catch (error) {
                        if (requestId !== compressionRequest) {
                            return;
                        }
                        fileInput.value = '';
                        fileName.textContent = error.message || 'The image could not be compressed.';
                    }
                });
            }

            const chatIsLoggedIn = <?= $chat_logged_in ? 'true' : 'false' ?>;
            const chatComposer = document.querySelector('.chat-composer');
            const chatTextarea = chatComposer ? chatComposer.querySelector('textarea[name="message"]') : null;
            const messageList = document.getElementById('chatMessages');
            const messageCount = document.getElementById('chatMessageCount');
            const liveStatus = document.getElementById('chatLiveStatus');
            const clientError = document.getElementById('chatClientError');
            let livePollInFlight = false;

            if (messageList) {
                messageList.addEventListener('click', (event) => {
                    const imageLink = event.target.closest('.chat-image-link');
                    if (!imageLink || !messageList.contains(imageLink)) return;
                    event.preventDefault();
                    openImageLightbox(
                        imageLink.dataset.chatImage || imageLink.href,
                        imageLink.dataset.chatImageAlt || imageLink.querySelector('img')?.alt || 'Chat image preview'
                    );
                });
            }

            const setClientError = (message = '') => {
                if (!clientError) return;
                clientError.textContent = message ? `⚠️ ${message}` : '';
                clientError.hidden = !message;
            };

            const setLiveStatus = (message, state = '') => {
                if (!liveStatus) return;
                liveStatus.textContent = `● ${message}`;
                liveStatus.classList.toggle('is-offline', state === 'offline');
            };

            const appendMessageText = (element, value) => {
                String(value || '').split('\n').forEach((part, index, parts) => {
                    if (index > 0) element.appendChild(document.createElement('br'));
                    element.appendChild(document.createTextNode(part));
                });
            };

            const buildLiveMessage = (message) => {
                const article = document.createElement('article');
                article.className = `chat-message ${message.user_id === <?= (int) $chat_user_id ?> ? 'is-own' : 'is-other'}`;

                const meta = document.createElement('div');
                meta.className = 'chat-message-meta';
                const authorButton = document.createElement('button');
                authorButton.type = 'button';
                authorButton.className = 'profile-trigger chat-author-profile';
                authorButton.dataset.profileUserId = String(message.user_id || '');
                authorButton.setAttribute('aria-label', `Open ${message.username || 'student'}'s profile`);
                const authorAvatar = document.createElement('span');
                authorAvatar.className = 'profile-trigger-avatar';
                if (message.profile_image) {
                    const authorImage = document.createElement('img');
                    authorImage.src = resolveProfileImageUrl(message.profile_image);
                    authorImage.alt = '';
                    authorImage.className = 'profile-avatar-image';
                    authorAvatar.appendChild(authorImage);
                } else {
                    authorAvatar.textContent = '👤';
                }
                authorButton.appendChild(authorAvatar);
                const author = document.createElement('strong');
                author.textContent = message.username || 'student';
                if (message.author_is_admin) {
                    const adminLabel = document.createElement('span');
                    adminLabel.className = 'chat-admin-label';
                    adminLabel.textContent = ' (admin)';
                    author.appendChild(adminLabel);
                }
                authorButton.appendChild(author);
                meta.appendChild(authorButton);

                const time = document.createElement('time');
                time.dateTime = message.created_at || '';
                time.textContent = message.time_label || '';
                meta.appendChild(time);

                if (message.can_delete) {
                    const deleteForm = document.createElement('form');
                    deleteForm.method = 'post';
                    deleteForm.action = 'chat.php';
                    deleteForm.className = 'chat-delete-form';
                    [
                        ['chat_action', 'delete'],
                        ['message_id', String(message.id)]
                    ].forEach(([name, value]) => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = name;
                        input.value = value;
                        deleteForm.appendChild(input);
                    });
                    const deleteButton = document.createElement('button');
                    deleteButton.type = 'submit';
                    deleteButton.className = 'chat-delete';
                    deleteButton.title = message.author_is_admin && message.user_id !== <?= (int) $chat_user_id ?> ? 'Delete this message as admin' : 'Delete your message';
                    deleteButton.textContent = '🗑️';
                    deleteForm.appendChild(deleteButton);
                    meta.appendChild(deleteForm);
                }
                article.appendChild(meta);

                if (message.message) {
                    const text = document.createElement('p');
                    appendMessageText(text, message.message);
                    article.appendChild(text);
                }

                if (message.image_src) {
                    const imageLink = document.createElement('a');
                    imageLink.className = 'chat-image-link';
                    imageLink.href = message.image_src;
                    imageLink.dataset.chatImage = message.image_src;
                    imageLink.dataset.chatImageAlt = `Image sent by ${message.username || 'student'}`;
                    const image = document.createElement('img');
                    image.src = message.image_src;
                    image.alt = `Image sent by ${message.username || 'student'}`;
                    imageLink.appendChild(image);
                    article.appendChild(imageLink);
                }

                return article;
            };

            const renderLiveMessages = (messages) => {
                if (!messageList) return;
                const wasNearBottom = messageList.scrollHeight - messageList.scrollTop - messageList.clientHeight < 80;
                const fragment = document.createDocumentFragment();
                if (!messages.length) {
                    const empty = document.createElement('div');
                    empty.className = 'chat-empty';
                    empty.textContent = 'No messages yet. Start the conversation! 🫧';
                    fragment.appendChild(empty);
                } else {
                    messages.forEach((message) => fragment.appendChild(buildLiveMessage(message)));
                }
                messageList.replaceChildren(fragment);
                if (messageCount) {
                    messageCount.textContent = `${messages.length} message${messages.length === 1 ? '' : 's'}`;
                }
                if (wasNearBottom) {
                    messageList.scrollTop = messageList.scrollHeight;
                }
            };

            const loadLiveMessages = async () => {
                if (!chatIsLoggedIn || !messageList || livePollInFlight) return;
                livePollInFlight = true;
                try {
                    const response = await fetch('chat.php?chat_api=messages', {
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: { Accept: 'application/json' }
                    });
                    const data = await response.json();
                    if (!response.ok || !data.ok) {
                        throw new Error(data.error || 'The live chat connection is unavailable.');
                    }
                    renderLiveMessages(Array.isArray(data.messages) ? data.messages : []);
                    setLiveStatus(`LIVE · updated ${new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}`);
                } catch (error) {
                    setLiveStatus('OFFLINE · retrying', 'offline');
                } finally {
                    livePollInFlight = false;
                }
            };

            if (chatComposer && chatIsLoggedIn) {
                if (chatTextarea) {
                    chatTextarea.addEventListener('keydown', (event) => {
                        if (event.key === 'Enter' && !event.shiftKey) {
                            event.preventDefault();
                            chatComposer.requestSubmit();
                        }
                    });
                }

                chatComposer.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    setClientError('');
                    const sendButton = chatComposer.querySelector('button[type="submit"]');
                    if (sendButton) sendButton.disabled = true;
                    const formData = new FormData(chatComposer);
                    formData.append('chat_ajax', '1');

                    try {
                        const response = await fetch('chat.php', {
                            method: 'POST',
                            body: formData,
                            credentials: 'same-origin',
                            headers: { Accept: 'application/json' }
                        });
                        const data = await response.json();
                        if (!response.ok || !data.ok) {
                            throw new Error(data.error || 'Your message could not be sent.');
                        }
                        chatComposer.querySelector('textarea').value = '';
                        if (fileInput) fileInput.value = '';
                        if (fileName) fileName.textContent = 'No image selected';
                        await loadLiveMessages();
                    } catch (error) {
                        setClientError(error.message || 'Your message could not be sent.');
                    } finally {
                        if (sendButton) sendButton.disabled = false;
                    }
                });
            }

            if (messageList && chatIsLoggedIn) {
                messageList.addEventListener('submit', async (event) => {
                    const deleteForm = event.target.closest('.chat-delete-form');
                    if (!deleteForm) return;
                    event.preventDefault();
                    setClientError('');
                    const formData = new FormData(deleteForm);
                    formData.append('chat_ajax', '1');
                    try {
                        const response = await fetch('chat.php', {
                            method: 'POST',
                            body: formData,
                            credentials: 'same-origin',
                            headers: { Accept: 'application/json' }
                        });
                        const data = await response.json();
                        if (!response.ok || !data.ok) {
                            throw new Error(data.error || 'That message could not be deleted.');
                        }
                        await loadLiveMessages();
                    } catch (error) {
                        setClientError(error.message || 'That message could not be deleted.');
                    }
                });
            }

            if (chatIsLoggedIn && messageList) {
                loadLiveMessages();
                window.setInterval(loadLiveMessages, 3000);
            }

            if (modal.classList.contains('open')) {
                syncModalLock();
            }

            document.addEventListener('keydown', (event) => {
                if (event.key !== 'Escape') return;
                if (imageLightbox.classList.contains('open')) closeImageLightbox();
                else if (chatLogoutModal.classList.contains('open')) closeLogoutAlert();
                else if (modal.classList.contains('open')) closeModal();
            });
        })();
    </script>
</body>
</html>