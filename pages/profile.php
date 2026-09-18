<?php
require_once '../session.php';
require_once '../db.php';
require_once 'profile_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function profileJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

/**
 * Helper to format the profile image blob into a proper base64 data URI with correct MIME type.
 */
function formatProfileImage(?array &$profile): void
{
    if (!empty($profile['profile_image'])) {
        $imageData = $profile['profile_image'];
        if (is_resource($imageData)) {
            $imageData = stream_get_contents($imageData);
        }
        if ($imageData !== false && strlen($imageData) > 0) {
            if (strpos($imageData, 'data:') !== 0) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->buffer($imageData);
                if (!$mimeType || $mimeType === 'application/octet-stream') {
                    $info = @getimagesizefromstring($imageData);
                    $mimeType = $info['mime'] ?? 'image/jpeg';
                }
                $profile['profile_image'] = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
            }
        } else {
            $profile['profile_image'] = '';
        }
    } else {
        $profile['profile_image'] = '';
    }
}

$profileSchemaReady = ensureProfileSchema($pdo);
if (!$profileSchemaReady) {
    profileJson(['ok' => false, 'error' => 'Profiles are not available until the users table migration is applied.'], 503);
}

$currentUserId = (int) ($_SESSION['games_user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['profile_api'] ?? '') === '1') {
    $requestedId = filter_var($_GET['user_id'] ?? null, FILTER_VALIDATE_INT);
    $requestedId = $requestedId ?: $currentUserId;
    $profile = getUserProfile($pdo, (int) $requestedId);

    if (!$profile) {
        profileJson(['ok' => false, 'error' => 'That profile could not be found.'], 404);
    }

    formatProfileImage($profile);
    $profile['can_edit'] = $currentUserId > 0 && $currentUserId === $profile['id'];
    profileJson(['ok' => true, 'profile' => $profile]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['profile_action'] ?? '') !== 'update') {
    profileJson(['ok' => false, 'error' => 'That profile action is not available.'], 405);
}

if ($currentUserId <= 0) {
    profileJson(['ok' => false, 'error' => 'Please sign in before editing your profile.'], 401);
}
$username = trim((string) ($_POST['username'] ?? ''));
$bio = trim((string) ($_POST['bio'] ?? ''));
if (!preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $username)) {
    profileJson(['ok' => false, 'error' => 'Use 3–30 letters, numbers, dots, dashes, or underscores for your username.'], 422);
}
if (mb_strlen($bio) > 600) {
    profileJson(['ok' => false, 'error' => 'Your bio must be 600 characters or fewer.'], 422);
}

$stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
$stmt->execute([$username, $currentUserId]);
if ($stmt->fetch()) {
    profileJson(['ok' => false, 'error' => 'That username is already taken.'], 422);
}

$profileImageData = null;
$imageMime = '';
$removeProfileImage = filter_var($_POST['remove_profile_image'] ?? false, FILTER_VALIDATE_BOOLEAN);
$hasNewImage = !$removeProfileImage
    && isset($_FILES['profile_image'])
    && $_FILES['profile_image']['error'] !== UPLOAD_ERR_NO_FILE;
if ($hasNewImage) {
    if ($_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
        profileJson(['ok' => false, 'error' => 'The profile image could not be uploaded.'], 422);
    }
    if ((int) $_FILES['profile_image']['size'] > 5 * 1024 * 1024) {
        profileJson(['ok' => false, 'error' => 'Profile images must be 5 MB or smaller.'], 422);
    }

    $imageInfo = @getimagesize($_FILES['profile_image']['tmp_name']);
    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    $imageMime = $imageInfo['mime'] ?? '';
    if (!$imageInfo || !isset($allowedMimes[$imageMime])) {
        profileJson(['ok' => false, 'error' => 'Please choose a valid JPG, PNG, GIF, or WebP image.'], 422);
    }

    $profileImageData = file_get_contents($_FILES['profile_image']['tmp_name']);
    if ($profileImageData === false) {
        profileJson(['ok' => false, 'error' => 'The profile image could not be read.'], 500);
    }
}

try {
    if ($removeProfileImage) {
        $stmt = $pdo->prepare('UPDATE users SET username = ?, bio = ?, profile_image_data = NULL, profile_image_type = NULL WHERE id = ?');
        $stmt->execute([$username, $bio, $currentUserId]);
    } elseif ($profileImageData !== null) {
        $stmt = $pdo->prepare('UPDATE users SET username = ?, bio = ?, profile_image_data = ?, profile_image_type = ? WHERE id = ?');
        $stmt->bindValue(1, $username, PDO::PARAM_STR);
        $stmt->bindValue(2, $bio, PDO::PARAM_STR);
        $stmt->bindValue(3, $profileImageData, PDO::PARAM_LOB);
        $stmt->bindValue(4, $imageMime, PDO::PARAM_STR);
        $stmt->bindValue(5, $currentUserId, PDO::PARAM_INT);
        $stmt->execute();
    } else {
        $stmt = $pdo->prepare('UPDATE users SET username = ?, bio = ? WHERE id = ?');
        $stmt->execute([$username, $bio, $currentUserId]);
    }
} catch (Throwable $e) {
    if (stripos($e->getMessage(), 'max_allowed_packet') !== false) {
        profileJson(['ok' => false, 'error' => 'That image is too large for the database server to accept. Please try a smaller one.'], 413);
    }
    error_log('Profile update failed: ' . $e->getMessage());
    profileJson(['ok' => false, 'error' => 'The profile could not be saved right now.'], 500);
}

$_SESSION['games_username'] = $username;
$_SESSION['username'] = $username;
$profile = getUserProfile($pdo, $currentUserId);
formatProfileImage($profile);
$profile['can_edit'] = true;
profileJson(['ok' => true, 'profile' => $profile]);