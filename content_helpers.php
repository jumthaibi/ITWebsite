<?php
declare(strict_types=1);

/*
 * Lessons intentionally contain a small amount of formatting. Keep that
 * formatting, but remove attributes and every tag outside this allow-list so
 * a compromised editor account cannot store script/event-handler HTML.
 */
function safe_lesson_html(string $html): string
{
    $allowedTags = '<h1><h2><h3><h4><h5><h6><p><ul><ol><li><br><strong><em><code><pre>';
    $clean = strip_tags($html, $allowedTags);
    return (string) preg_replace(
        '/<(\/?)(h[1-6]|p|ul|ol|li|br|strong|em|code|pre)\b[^>]*>/i',
        '<$1$2>',
        $clean
    );
}