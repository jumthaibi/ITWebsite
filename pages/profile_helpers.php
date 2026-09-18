<?php

/**
 * Profile fields are kept on the existing users table so the same account
 * works in the arcade, chat, and future portal pages.
 */
function ensureProfileSchema(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $pdo->query('SELECT bio, profile_image_data, profile_image_type FROM users LIMIT 1');
        return $ready = true;
    } catch (Throwable $e) {
        return $ready = false;
    }
}

/**
 * Turn a stored profile_image value (raw LONGBLOB bytes) into an inline
 * data: URI. Values that are already data: URIs or http(s) URLs pass
 * through untouched, so this is safe to call on anything.
 */
function profileImageToDataUri(?string $raw, string $hintMime = ''): string
{
    $raw = (string) $raw;
    if ($raw === '' || preg_match('/^(https?:\/\/|data:)/i', $raw)) {
        return $raw;
    }

    $mimeType = '';
    if ($hintMime !== '' && str_starts_with(strtolower($hintMime), 'image/')) {
        $mimeType = strtolower($hintMime);
    }
    if ($mimeType === '' && class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($raw);
        if ($detected && str_starts_with($detected, 'image/')) {
            $mimeType = $detected;
        } else {
            $info = @getimagesizefromstring($raw);
            $mimeType = $info['mime'] ?? '';
        }
    }

    return 'data:' . ($mimeType !== '' ? $mimeType : 'image/jpeg') . ';base64,' . base64_encode($raw);
}

function getUserProfile(PDO $pdo, int $userId): ?array
{
    if ($userId <= 0 || !ensureProfileSchema($pdo)) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, username, bio, profile_image_data, profile_image_type,
                profile_image_path, is_admin
         FROM users
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->execute([$userId]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$profile) {
        return null;
    }

    $imageBase64 = (string) ($profile['profile_image_path'] ?? '');
    $rawImage = $profile['profile_image_data'] ?? null;
    if (is_resource($rawImage)) {
        $rawImage = stream_get_contents($rawImage);
    }
    if (is_string($rawImage) && $rawImage !== '') {
        $imageBase64 = profileImageToDataUri($rawImage, (string) ($profile['profile_image_type'] ?? ''));
    }

    return [
        'id' => (int) $profile['id'],
        'username' => (string) $profile['username'],
        'bio' => (string) ($profile['bio'] ?? ''),
        'profile_image' => $imageBase64,
        'is_admin' => (int) $profile['is_admin'] === 1,
    ];
}