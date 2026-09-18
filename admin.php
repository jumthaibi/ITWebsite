<?php
require_once 'db.php';
require_once 'auth.php';

// Handle Login Submission
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND is_admin = 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, (string) $user['password'])) {
        sign_in_user($user);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $user['username'];
        header("Location: admin.php");
        exit();
    } else {
        $login_error = "Invalid username or password, or unauthorized access.";
    }
}

$is_logged_in = current_user_is_admin();

// Helper function to build structured HTML from multi-blocks (Heading + Points + Code)
function buildMultiBlockHtml(string $subheading, array $blocks): string {
    $html = "";
    
    if (!empty(trim($subheading))) {
        $html .= "<h3>" . htmlspecialchars(trim($subheading)) . "</h3>";
    }
    
    if (!empty($blocks) && is_array($blocks)) {
        foreach ($blocks as $block) {
            $heading = trim($block['heading'] ?? '');
            $points_raw = trim($block['points'] ?? '');
            $code_raw = trim($block['code'] ?? '');
            
            if (!empty($heading)) {
                $html .= "<h4>" . htmlspecialchars($heading) . "</h4>";
            }
            
            if (!empty($points_raw)) {
                $lines = explode("\n", str_replace("\r", "", $points_raw));
                $html .= "<ul>";
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (!empty($line)) {
                        // Keep explanation text safe, then allow a small inline-code
                        // convention: `cout` becomes <code>cout</code>.
                        $safe_line = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
                        $safe_line = preg_replace('/`([^`\r\n]+)`/', '<code>$1</code>', $safe_line);
                        $html .= "<li>" . $safe_line . "</li>";
                    }
                }
                $html .= "</ul>";
            }
            
            if (!empty($code_raw)) {
                $formatted_code = nl2br(htmlspecialchars($code_raw));
                $formatted_code = preg_replace('/ {4}/', '&nbsp;&nbsp;&nbsp;&nbsp;', $formatted_code);
                $formatted_code = preg_replace('/ {2}/', '&nbsp;&nbsp;', $formatted_code);
                $html .= "<button class=\"blk-btn\">" . $formatted_code . "</button>";
            }
            
            $html .= "<br>";
        }
    }

    return $html;
}

// Read text from stored lesson HTML while preserving inline-code markers and line breaks.
function extractNodeText(DOMNode $node, bool $preserveCode = true): string {
    $text = '';

    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_TEXT_NODE) {
            $text .= $child->nodeValue;
            continue;
        }

        if ($child->nodeType !== XML_ELEMENT_NODE) {
            continue;
        }

        $tag = strtolower($child->nodeName);
        if ($tag === 'br') {
            $text .= "\n";
        } elseif ($tag === 'code' && $preserveCode) {
            $text .= '`' . extractNodeText($child, false) . '`';
        } else {
            $text .= extractNodeText($child, $preserveCode);
        }
    }

    return str_replace(
        "\xc2\xa0",
        ' ',
        html_entity_decode($text, ENT_QUOTES, 'UTF-8')
    );
}

// Helper function to parse stored HTML back into blocks for the Edit form.
// This uses the HTML structure rather than a single regex so existing lesson
// text is loaded back into the correct fields before an update.
function extractMultiBlocks(string $html_content): array {
    $subheading = '';
    $blocks = [];
    $current_block = null;

    $previous_errors = libxml_use_internal_errors(true);
    $document = new DOMDocument('1.0', 'UTF-8');
    $document->loadHTML(
        '<?xml encoding="UTF-8"><div id="lesson-edit-fragment">' . $html_content . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous_errors);

    $root = null;
    foreach ($document->childNodes as $candidate) {
        if ($candidate instanceof DOMElement && $candidate->getAttribute('id') === 'lesson-edit-fragment') {
            $root = $candidate;
            break;
        }
    }

    if ($root instanceof DOMElement) {
        foreach ($root->childNodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($node->tagName);

            if ($tag === 'h3' && $subheading === '') {
                $subheading = trim(extractNodeText($node, false));
                continue;
            }

            if ($tag === 'h4') {
                if ($current_block !== null) {
                    $blocks[] = $current_block;
                }

                $current_block = [
                    'heading' => trim(extractNodeText($node, false)),
                    'points' => [],
                    'code' => ''
                ];
                continue;
            }

            if ($current_block === null) {
                continue;
            }

            if ($tag === 'ul') {
                foreach ($node->getElementsByTagName('li') as $list_item) {
                    $point = trim(extractNodeText($list_item));
                    if ($point !== '') {
                        $current_block['points'][] = $point;
                    }
                }
            } elseif ($tag === 'p') {
                // Older sections may use paragraphs instead of bullet lists.
                $paragraph = trim(extractNodeText($node));
                if ($paragraph !== '') {
                    $current_block['points'][] = $paragraph;
                }
            } elseif ($tag === 'button' && strpos(' ' . $node->getAttribute('class') . ' ', ' blk-btn ') !== false) {
                $current_block['code'] = trim(extractNodeText($node, false));
            }
        }
    }

    if ($current_block !== null) {
        $blocks[] = $current_block;
    }

    $blocks = array_map(function ($block) {
        return [
            'heading' => $block['heading'],
            'points' => implode("\n", $block['points']),
            'code' => $block['code']
        ];
    }, $blocks);

    if (empty($blocks)) {
        $blocks[] = ['heading' => '', 'points' => '', 'code' => ''];
    }

    return ['subheading' => $subheading, 'blocks' => $blocks];
}

// Handle Admin Actions
$success_msg = '';
$error_msg = '';

if ($is_logged_in && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Edit Main Page (index.php)
    if ($action == 'update_index') {
        $title = trim($_POST['section_title']);
        $intro = trim($_POST['section_intro']);
        
        $stmt_check = $pdo->prepare("SELECT id FROM content WHERE page_name = 'index'");
        $stmt_check->execute();
        $existing_index = $stmt_check->fetch();

        if ($existing_index) {
            $stmt = $pdo->prepare("UPDATE content SET section_title = ?, section_content = ?, updated_at = NOW() WHERE page_name = 'index'");
            $stmt->execute([$title, $intro]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO content (page_name, section_title, section_content) VALUES ('index', ?, ?)");
            $stmt->execute([$title, $intro]);
        }
        $success_msg = "Main page updated successfully!";
    }

    // 2. Add Material Card
    elseif ($action == 'add_material') {
        $title = trim($_POST['title']);
        $slug = trim($_POST['slug']);
        $description = trim($_POST['description']);
        // An image is optional. Do not fall back to a file that is not included
        // in this project, otherwise new material cards show a broken image.
        $image = trim($_POST['image']);

        if (!empty($title) && !empty($slug)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO materials (title, slug, description, image) VALUES (?, ?, ?, ?)");
                $stmt->execute([$title, $slug, $description, $image]);

                $stmt_content = $pdo->prepare("INSERT INTO content (page_name, section_title, section_content) VALUES (?, 'Introduction', ?)");
                $stmt_content->execute([$slug, "<h3>Welcome</h3><p>Introduction to " . htmlspecialchars($title) . "</p>"]);

                $success_msg = "Material card added successfully!";
            } catch (PDOException $e) {
                $error_msg = "Error: Slug already exists or database error.";
            }
        } else {
            $error_msg = "Title and Slug are required.";
        }
    }

    // 3. Delete Material Card
    elseif ($action == 'delete_material') {
        $material_id = $_POST['material_id'] ?? 0;
        $slug = $_POST['slug'] ?? '';

        if ($material_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM materials WHERE id = ?");
            $stmt->execute([$material_id]);

            if (!empty($slug)) {
                $stmt_c = $pdo->prepare("DELETE FROM content WHERE page_name = ?");
                $stmt_c->execute([$slug]);
            }
            $success_msg = "Material card and its content deleted successfully!";
        }
    }

    // 4. Add Material Content Section with Multi-Blocks
    elseif ($action == 'add_content_section') {
        $page_name = trim($_POST['page_name']);
        $section_title = trim($_POST['section_title']);
        $subheading = trim((string) ($_POST['section_subheading'] ?? ''));
        $block_headings = $_POST['block_heading'] ?? [];
        $block_points = $_POST['block_points'] ?? [];
        $block_codes = $_POST['block_code'] ?? [];

        $blocks = [];
        for ($i = 0; $i < count($block_headings); $i++) {
            $blocks[] = [
                'heading' => $block_headings[$i],
                'points' => $block_points[$i] ?? '',
                'code' => $block_codes[$i] ?? ''
            ];
        }

        if (!empty($page_name) && !empty($section_title)) {
            $section_content = buildMultiBlockHtml($subheading, $blocks);
            $stmt = $pdo->prepare("INSERT INTO content (page_name, section_title, section_content) VALUES (?, ?, ?)");
            $stmt->execute([$page_name, $section_title, $section_content]);
            $success_msg = "Lesson section added successfully!";
        } else {
            $error_msg = "Material slug and Section Title are required.";
        }
    }

    // 5. Update Existing Material Content Section
    elseif ($action == 'edit_content_section') {
        $content_id = $_POST['content_id'] ?? 0;
        $section_title = trim($_POST['section_title']);
        $subheading = trim((string) ($_POST['section_subheading'] ?? ''));
        $block_headings = $_POST['block_heading'] ?? [];
        $block_points = $_POST['block_points'] ?? [];
        $block_codes = $_POST['block_code'] ?? [];

        $blocks = [];
        for ($i = 0; $i < count($block_headings); $i++) {
            $blocks[] = [
                'heading' => $block_headings[$i],
                'points' => $block_points[$i] ?? '',
                'code' => $block_codes[$i] ?? ''
            ];
        }

        if ($content_id > 0 && !empty($section_title)) {
            $section_content = buildMultiBlockHtml($subheading, $blocks);
            $stmt = $pdo->prepare("UPDATE content SET section_title = ?, section_content = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$section_title, $section_content, $content_id]);
            $success_msg = "Lesson section updated successfully!";
        } else {
            $error_msg = "Section Title is required.";
        }
    }

    // 6. Delete Content Section
    elseif ($action == 'delete_content_section') {
        $content_id = $_POST['content_id'] ?? 0;
        if ($content_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM content WHERE id = ?");
            $stmt->execute([$content_id]);
            $success_msg = "Content section deleted successfully!";
        }
    }

}

// Fetch data
$index_content = [];
$materials = [];
$all_content = [];

if ($is_logged_in) {
    $stmt = $pdo->prepare("SELECT * FROM content WHERE page_name = 'index'");
    $stmt->execute();
    $index_content = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT * FROM materials");
    $stmt->execute();
    $materials = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT * FROM content WHERE page_name != 'index' ORDER BY page_name, id");
    $stmt->execute();
    $all_content = $stmt->fetchAll();

}

$grouped_content = [];
foreach ($all_content as $c) {
    $grouped_content[$c['page_name']][] = $c;
}

$selected_material_slug = trim((string) ($_GET['material_slug'] ?? ''));
$material_slugs = array_map(
    static function ($material) {
        return (string) ($material['slug'] ?? '');
    },
    $materials
);
if (!in_array($selected_material_slug, $material_slugs, true)) {
    $selected_material_slug = '';
}

$editing_section = null;
if ($is_logged_in && isset($_GET['edit_section'])) {
    $edit_id = intval($_GET['edit_section']);
    foreach ($all_content as $c) {
        if ($c['id'] == $edit_id) {
            $editing_section = $c;
            $selected_material_slug = (string) $c['page_name'];
            break;
        }
    }
}

$selected_material = null;
foreach ($materials as $material) {
    if ((string) ($material['slug'] ?? '') === $selected_material_slug) {
        $selected_material = $material;
        break;
    }
}
$selected_sections = $selected_material_slug !== ''
    ? ($grouped_content[$selected_material_slug] ?? [])
    : [];

$active_tab = $_GET['tab'] ?? 'dashboard';
$allowed_tabs = ['dashboard', 'edit_index', 'manage_cards', 'add_lesson', 'manage_lessons'];
if (!in_array($active_tab, $allowed_tabs, true)) {
    $active_tab = 'dashboard';
}
if ($editing_section) {
    $active_tab = 'edit_section_form';
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IT Students Portal | Frutiger Aero Edition</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Varela+Round&display=swap" rel="stylesheet">
    <style>
        .admin-window .admin-body > :not(.auth-modal) .form-group { margin-bottom: 12px; }
        .admin-window .admin-body > :not(.auth-modal) label { display: block; margin-bottom: 4px; font-weight: bold; color: #0b3d63; font-size: 0.85rem; }
        .admin-window .admin-body > :not(.auth-modal) input[type="text"], .admin-window .admin-body > :not(.auth-modal) input[type="password"], .admin-window .admin-body > :not(.auth-modal) textarea, .admin-window .admin-body > :not(.auth-modal) select { width: 100%; padding: 8px 10px; border: 1px solid #8ab8e6; border-radius: 6px; box-sizing: border-box; font-family: 'Varela Round', sans-serif; font-size: 0.85rem; background: #ffffff; color: #333; }
        .admin-window .admin-body > :not(.auth-modal) textarea { height: 70px; }
        .admin-window .admin-body > :not(.auth-modal) textarea.code-box { font-family: monospace; background: #f4f8fc; }
        .alert-success { background: rgba(76, 175, 80, 0.2); color: #2e7d32; padding: 10px; margin-bottom: 12px; border-radius: 6px; border: 1px solid rgba(76, 175, 80, 0.4); font-size: 0.85rem; }
        .alert-danger { background: rgba(229, 57, 53, 0.2); color: #c62828; padding: 10px; margin-bottom: 12px; border-radius: 6px; border: 1px solid rgba(229, 57, 53, 0.4); font-size: 0.85rem; }
        .admin-window table { width: 100%; border-collapse: collapse; margin-top: 6px; margin-bottom: 15px; font-size: 0.82rem; }
        .admin-window table, .admin-window th, .admin-window td { border: 1px solid #b3d7ff; padding: 8px; text-align: left; }
        .admin-window th { background: #e2f0ff; color: #0b3d63; }
        .admin-window td { background: rgba(255,255,255,0.6); color: #37474f; }
        .helper-text { font-size: 0.75rem; color: #546e7a; margin-top: 3px; margin-bottom: 0; }
        .admin-section { margin-top: 0; }
        .material-group-card { background: rgba(240, 248, 255, 0.5); border: 1px solid #b3d7ff; border-radius: 8px; padding: 12px; margin-bottom: 12px; }
        .material-group-title { font-size: 0.9rem; color: #01579b; font-weight: bold; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
        
        .topic-block-card {
            background: rgba(255, 255, 255, 0.8);
            border: 1px solid #99c2ff;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 10px;
            position: relative;
        }
        .remove-block-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #e57373;
            color: white;
            border: none;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            cursor: pointer;
        }

        /* Keep every admin tab reachable on narrow screens. */
        .browser-tabs {
            flex-wrap: wrap;
            overflow-x: visible;
            overflow-y: visible;
        }
        .browser-tabs .tab {
            white-space: nowrap;
            flex-shrink: 0;
        }
    </style>
</head>
<body>

    <!-- Match the chat page's alert dialog exactly. -->
    <div class="auth-modal games-alert-modal" id="aero-alert-overlay" aria-hidden="true">
        <div class="os-window auth-dialog-window games-alert-window chat-simple-dialog" role="alertdialog" aria-modal="true" aria-labelledby="aero-alert-title">
            <div class="window-header">
                <div class="window-title"><span class="os-icon">⚠️</span> System Notice</div>
                <div class="window-controls">
                    <span class="control-btn minimize"></span>
                    <span class="control-btn maximize"></span>
                    <button class="control-btn close auth-window-close" type="button" onclick="closeAeroAlert(false)" aria-label="Close alert"></button>
                </div>
            </div>
            <div class="browser-toolbar">
                <div class="address-bar"><span>System Alert Dialog</span></div>
            </div>
            <div class="window-body auth-dialog-body">
                <div class="content-box auth-panel games-alert-panel">
                    <span class="card-header-tag">ACCOUNT NOTICE</span>
                    <h2 id="aero-alert-title">Action Alert</h2>
                    <p class="auth-description" id="aero-alert-msg">Are you sure you want to proceed with this modification or deletion?</p>
                    <div class="games-alert-actions">
                        <button class="aero-button primary" id="aero-alert-confirm" type="button">Confirm</button>
                        <button class="aero-button secondary" type="button" onclick="closeAeroAlert(false)">Cancel</button>
                    </div>
                </div>
            </div>
            <div class="window-statusbar"><span>Frutiger Aero Alert System</span><span>Ready</span></div>
        </div>
    </div>

    <!-- OS Window Container -->
    <div class="os-window app-window admin-window">
        <!-- Window Title Bar -->
        <div class="window-header">
            <div class="window-title">
                <span class="os-icon">🌐</span> IT-Students-Hub.io - admin
            </div>
            <div class="window-controls">
                <span class="control-btn minimize"></span>
                <span class="control-btn maximize"></span>
                <span class="control-btn close"></span>
            </div>
        </div>

        <!-- Browser Toolbar -->
        <div class="browser-toolbar">
            <div class="nav-arrows">
                <button class="arrow-btn">⬅</button>
                <button class="arrow-btn">➡</button>
            </div>
            <div class="address-bar">
                <span>🔒 http://it-students-hub.io/admin.php</span>
            </div>
            <div class="browser-tools">
                <button class="tool-btn">🔍</button>
            </div>
        </div>

        <!-- Browser Tabs -->
        <div class="browser-tabs">
            <?php if ($is_logged_in): ?>
                <div class="tab <?= ($active_tab == 'dashboard') ? 'active' : '' ?>"><a href="admin.php?tab=dashboard" style="text-decoration:none; color:inherit;">🏠 dashboard overview</a></div>
                <div class="tab <?= ($active_tab == 'edit_index') ? 'active' : '' ?>"><a href="admin.php?tab=edit_index" style="text-decoration:none; color:inherit;">📝 edit main page</a></div>
                <div class="tab <?= ($active_tab == 'manage_cards') ? 'active' : '' ?>"><a href="admin.php?tab=manage_cards" style="text-decoration:none; color:inherit;">🃏 manage cards</a></div>
                <div class="tab <?= ($active_tab == 'add_lesson') ? 'active' : '' ?>"><a href="admin.php?tab=add_lesson" style="text-decoration:none; color:inherit;">➕ add lessons section</a></div>
                <div class="tab <?= ($active_tab == 'manage_lessons') ? 'active' : '' ?>"><a href="admin.php?tab=manage_lessons" style="text-decoration:none; color:inherit;">📚 manage existing sections</a></div>
            <?php else: ?>
                <div class="tab active"><a href="admin.php" style="text-decoration:none; color:inherit;">⚙️ admin</a></div>
            <?php endif; ?>
        </div>

        <!-- Main Window Content (Exact fixed size matching index.php window-body style with vertical scrolling) -->
        <div class="window-body admin-body" style="padding: 14px; gap: 14px; flex-direction: column; align-items: stretch; display: flex;">
            
            <?php if (!$is_logged_in): ?>
                <!-- Match the chat page's sign-in dialog exactly. -->
                <div class="auth-modal open" id="adminAuthModal" aria-hidden="false">
                    <div class="os-window auth-dialog-window chat-simple-dialog" role="dialog" aria-modal="true" aria-labelledby="adminAuthTitle">
                        <div class="window-header">
                            <div class="window-title"><span class="os-icon">⚙️</span> Admin Portal Sign In</div>
                            <div class="window-controls">
                                <span class="control-btn minimize"></span>
                                <span class="control-btn maximize"></span>
                                <span class="control-btn close"></span>
                            </div>
                        </div>
                        <div class="browser-toolbar"><div class="address-bar"><span>Admin Account Dialog</span></div></div>
                        <div class="window-body auth-dialog-body">
                            <div class="content-box auth-panel">
                                <span class="card-header-tag">ADMIN PORTAL ACCESS</span>
                                <h2 id="adminAuthTitle">Sign in to the admin portal ⚙️</h2>
                                <p class="auth-description">Use an authorized administrator account to manage the student portal.</p>
                                <?php if (!empty($login_error)): ?>
                                    <div class="auth-error" role="alert">⚠️ <?= htmlspecialchars($login_error) ?></div>
                                <?php endif; ?>
                                <form class="auth-form" method="POST" action="admin.php">
                                    <input type="hidden" name="login" value="1">
                                    <label for="adminAuthUsername">Username</label>
                                    <input id="adminAuthUsername" name="username" type="text" autocomplete="username" placeholder="Your username" required>
                                    <label for="adminAuthPassword">Password</label>
                                    <input id="adminAuthPassword" name="password" type="password" autocomplete="current-password" placeholder="Your password" required>
                                    <button class="aero-button primary auth-submit" type="submit">Sign in</button>
                                </form>
                            </div>
                        </div>
                        <div class="window-statusbar"><span>Frutiger Aero Account System</span><span>Ready</span></div>
                    </div>
                </div>
            <?php else: ?>
                
                <div class="content-box admin-welcome-card" style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; margin: 0; flex-shrink: 0;">
                    <div>
                        <h2 style="font-size: 1.1rem; color: #01579b; margin-bottom: 2px;">Welcome, <?= htmlspecialchars($_SESSION['admin_username']) ?> ✨</h2>
                        <p style="font-size: 0.8rem; color: #546e7a; margin: 0;">Manage your academic portal contents seamlessly with Frutiger Aero design.</p>
                    </div>
                    <div class="admin-welcome-actions">
                        <a href="index.php" target="_blank" class="aero-button secondary" style="text-decoration: none; padding: 6px 12px; font-size: 0.8rem; display: inline-block; margin-right: 8px;">View Website</a>
                        <a href="logout.php?next=admin.php" id="logout-trigger" class="aero-button" style="background: linear-gradient(to bottom, #ffcdd2, #e57373); color: #b71c1c; border-color: #e53935; text-decoration: none; padding: 6px 12px; font-size: 0.8rem; display: inline-block;">Logout</a>
                    </div>
                </div>

                <?php if (!empty($success_msg)): ?>
                    <div class="alert-success" style="flex-shrink: 0;"><?= htmlspecialchars($success_msg) ?></div>
                <?php endif; ?>
                <?php if (!empty($error_msg)): ?>
                    <div class="alert-danger" style="flex-shrink: 0;"><?= htmlspecialchars($error_msg) ?></div>
                <?php endif; ?>

                <?php if ($editing_section): 
                    $parsed = extractMultiBlocks($editing_section['section_content']);
                ?>
                    <div class="content-box admin-section" style="border: 2px solid #0288d1; background: rgba(235, 245, 255, 0.9); margin: 0;">
                        <h3 style="color: #01579b; font-size: 1rem; margin-bottom: 12px;">Edit Lesson Section: "<?= htmlspecialchars($editing_section['section_title']) ?>"</h3>
                        <form method="POST" class="aero-monitored-form">
                            <input type="hidden" name="action" value="edit_content_section">
                            <input type="hidden" name="content_id" value="<?= $editing_section['id'] ?>">
                            
                            <div class="form-group">
                                <label>Material Subject</label>
                                <input type="text" value="<?= htmlspecialchars($editing_section['page_name']) ?>" disabled style="background: #e9ecef;">
                            </div>
                            <div class="form-group">
                                <label>Main Section Title</label>
                                <input type="text" name="section_title" value="<?= htmlspecialchars($editing_section['section_title']) ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Main Subheading (Optional)</label>
                                <input type="text" name="section_subheading" value="<?= htmlspecialchars($parsed['subheading']) ?>" placeholder="Optional subheading...">
                            </div>

                            <h4 style="color: #01579b; font-size: 0.9rem; margin-top: 15px; margin-bottom: 8px;">Content Blocks (Headings, Points & Code)</h4>
                            <div id="edit-blocks-container">
                                <?php foreach ($parsed['blocks'] as $b): ?>
                                    <div class="topic-block-card">
                                        <button type="button" class="aero-button remove-block-btn admin-action-icon admin-delete-action" aria-label="Remove topic block" title="Remove topic block" onclick="this.parentElement.remove()">🗑</button>
                                        <div class="form-group">
                                            <label>Topic / Point Heading (e.g., What is C#?)</label>
                                            <input type="text" name="block_heading[]" value="<?= htmlspecialchars($b['heading']) ?>" placeholder="Heading...">
                                        </div>
                                        <div class="form-group">
                                            <label>Explanation Points (Each line becomes a bullet point)</label>
                                            <textarea name="block_points[]" placeholder="Point 1&#10;Use `cout` to print output"><?= htmlspecialchars($b['points']) ?></textarea>
                                            <small style="display:block; color:#546e7a; margin-top:4px;">Highlight a short syntax word by wrapping it in backticks, for example: `cout`, `cin`, or `int main()`.</small>
                                        </div>
                                        <div class="form-group">
                                            <label>Code Snippet (Optional)</label>
                                            <textarea name="block_code[]" class="code-box" placeholder="Console.WriteLine('Hello');"><?= htmlspecialchars($b['code']) ?></textarea>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <button type="button" class="aero-button secondary" onclick="addEditBlock()" style="margin-bottom: 15px; font-size: 0.8rem;">+ Add Another Topic Block</button>

                            <div class="edit-form-actions" style="display: flex; gap: 10px;">
                                <button type="submit" class="aero-button primary save-trigger" style="flex-grow: 1;">Save Changes</button>
                                <a href="admin.php?tab=manage_lessons&amp;material_slug=<?= urlencode($editing_section['page_name']) ?>" class="aero-button secondary" style="text-align: center; text-decoration: none; padding-top: 8px;">Cancel</a>
                            </div>
                        </form>
                    </div>
                <?php else: ?>

                    <?php if ($active_tab == 'dashboard'): ?>
                        <div class="content-box admin-section" style="margin: 0;">
                            <h3 style="color: #01579b; font-size: 1rem; margin-bottom: 10px;">📊 Control Center Overview</h3>
                            <p style="font-size: 0.85rem; color: #37474f; line-height: 1.5;">
                                Welcome to your admin command dashboard. Use the navigation tabs above to switch focus:
                            </p>
                            <ul class="admin-overview-points" style="font-size: 0.85rem; color: #37474f; margin-top: 10px; line-height: 1.6;">
                                <li><strong>Edit main page:</strong> Modify website title and welcome message on index.php dynamically.</li>
                                <li><strong>Manage Cards:</strong> Create or delete material subject cards.</li>
                                <li><strong>Add Lesson Section:</strong> Add multiple structured topic blocks (Headings, explanation points, and code boxes) repeatedly as needed.</li>
                                <li><strong>Manage Existing Lessons:</strong> Review and edit structured modules.</li>
                            </ul>
                        </div>

                    <?php elseif ($active_tab == 'edit_index'): ?>
                        <div class="content-box admin-section" style="margin: 0;">
                            <h3 style="color: #01579b; font-size: 1rem; margin-bottom: 10px;">Edit Main Page (index.php Welcome Text)</h3>
                            <form method="POST" class="aero-monitored-form">
                                <input type="hidden" name="action" value="update_index">
                                <div class="form-group">
                                    <label>Main Heading Title</label>
                                    <input type="text" name="section_title" value="<?= htmlspecialchars($index_content['section_title'] ?? 'Welcome to my Frutiger Aero Space! 🫧✨') ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Welcome Message Paragraph</label>
                                    <textarea name="section_intro" required style="height: 90px;"><?= htmlspecialchars(isset($index_content['section_content']) ? strip_tags($index_content['section_content']) : '') ?></textarea>
                                </div>
                                <button type="submit" class="aero-button primary save-trigger" style="font-size: 0.85rem; padding: 8px 15px;">Update Main Page</button>
                            </form>
                        </div>

                    <?php elseif ($active_tab == 'manage_cards'): ?>
                        <div class="content-box admin-section" style="margin: 0;">
                            <h3 style="color: #01579b; font-size: 1rem; margin-bottom: 10px;">Manage Materials Cards (materials.php)</h3>
                            
                            <div style="background: rgba(240, 248, 255, 0.7); border: 1px solid #b3d7ff; padding: 12px; border-radius: 8px; margin-bottom: 15px;">
                                <h4 style="color: #0277bd; font-size: 0.9rem; margin-bottom: 8px;">Add New Material Card</h4>
                                <form method="POST" class="aero-monitored-form">
                                    <input type="hidden" name="action" value="add_material">
                                    <div class="form-group">
                                        <label>Card Title (e.g., C# Fundamentals)</label>
                                        <input type="text" name="title" placeholder="C# Fundamentals" required>
                                    </div>
                                    <div class="form-group">
                                        <label>Short Identifier / Slug (e.g., csharp-basics)</label>
                                        <input type="text" name="slug" placeholder="csharp-basics" required>
                                    </div>
                                    <div class="form-group">
                                        <label>Short Description</label>
                                        <textarea name="description" placeholder="Brief summary..." style="height: 50px;" required></textarea>
                                    </div>
                                    <div class="form-group">
                                        <label>Image Path (Optional)</label>
                                        <input type="text" name="image" value="" placeholder="Optional image path or URL">
                                    </div>
                                    <button type="submit" class="aero-button primary save-trigger" style="font-size: 0.85rem; padding: 8px 15px;">Add Material Card</button>
                                </form>
                            </div>

                            <h4 style="color: #0277bd; font-size: 0.9rem; margin-bottom: 6px;">Existing Material Cards</h4>
                            <table class="material-cards-table">
                                <tr>
                                    <th>Title</th>
                                    <th>Slug</th>
                                    <th>Description</th>
                                    <th>Action</th>
                                </tr>
                                <?php foreach ($materials as $mat): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($mat['title']) ?></strong></td>
                                    <td><code><?= htmlspecialchars($mat['slug']) ?></code></td>
                                    <td><?= htmlspecialchars($mat['description']) ?></td>
                                    <td>
                                        <form method="POST" class="aero-delete-form" style="display:inline;">
                                            <input type="hidden" name="action" value="delete_material">
                                            <input type="hidden" name="material_id" value="<?= $mat['id'] ?>">
                                            <input type="hidden" name="slug" value="<?= $mat['slug'] ?>">
                                            <button type="button" class="aero-button admin-action-icon admin-delete-action delete-trigger" aria-label="Delete material card" title="Delete material card">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>

                    <?php elseif ($active_tab == 'add_lesson'): ?>
                        <div class="content-box admin-section" style="margin: 0;">
                            <h3 style="color: #01579b; font-size: 1rem; margin-bottom: 10px;">Add Lesson Section (with Multiple Topic Blocks)</h3>

                            <div style="background: rgba(240, 248, 255, 0.7); border: 1px solid #b3d7ff; padding: 12px; border-radius: 8px; margin-bottom: 15px;">
                                <form method="POST" class="aero-monitored-form">
                                    <input type="hidden" name="action" value="add_content_section">
                                    <div class="form-group">
                                        <label>Select Material Subject</label>
                                        <select name="page_name" required>
                                            <option value="">-- Choose Material Card --</option>
                                            <?php foreach ($materials as $mat): ?>
                                                <option value="<?= htmlspecialchars($mat['slug']) ?>"><?= htmlspecialchars($mat['title']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Section Title (e.g., Module 1: Introduction)</label>
                                        <input type="text" name="section_title" placeholder="Introduction" required>
                                    </div>
                                    <div class="form-group">
                                        <label>Subheading (Optional - e.g., The language of logic & speed)</label>
                                        <input type="text" name="section_subheading" placeholder="Optional subheading...">
                                    </div>

                                    <h4 style="color: #01579b; font-size: 0.9rem; margin-top: 15px; margin-bottom: 8px;">Topic Blocks (Add as many as you want: Heading, Points, Code)</h4>
                                    
                                    <div id="blocks-container">
                                        <div class="topic-block-card">
                                            <button type="button" class="aero-button remove-block-btn add-lesson-remove-btn admin-action-icon admin-delete-action" aria-label="Remove topic block" title="Remove topic block" onclick="this.parentElement.remove()">Remove</button>
                                            <div class="form-group">
                                                <label>Topic / Point Heading (e.g., What is C#?)</label>
                                                <input type="text" name="block_heading[]" placeholder="What is C#?" required>
                                            </div>
                                            <div class="form-group">
                                                <label>Explanation Points (Each line becomes a bullet point)</label>
                                            <textarea name="block_points[]" placeholder="Object-oriented programming language&#10;Use `cout` to print output&#10;Runs on .NET"></textarea>
                                            <small style="display:block; color:#546e7a; margin-top:4px;">Highlight a short syntax word by wrapping it in backticks, for example: `cout`, `cin`, or `int main()`.</small>
                                            </div>
                                            <div class="form-group">
                                                <label>Code Snippet (Optional)</label>
                                                <textarea name="block_code[]" class="code-box" placeholder="using System;&#10;Console.WriteLine('Hello');"></textarea>
                                            </div>
                                        </div>
                                    </div>

                                    <button type="button" class="aero-button secondary" onclick="addBlock()" style="margin-bottom: 15px; font-size: 0.8rem;">+ Add Another Topic Block</button>

                                    <div>
                                        <button type="submit" class="aero-button primary save-trigger" style="font-size: 0.85rem; padding: 8px 15px; width: 100%;">Save and Add Lesson Section</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                    <?php elseif ($active_tab == 'manage_lessons'): ?>
                        <div class="content-box admin-section manage-lessons-panel" style="margin: 0;">
                            <h3 style="color: #01579b; font-size: 1rem; margin-bottom: 6px;">Manage Existing Lesson Sections</h3>
                            <p class="manage-lessons-intro">Choose one material to view and manage only its lesson sections.</p>

                            <?php if (empty($materials)): ?>
                                <p class="manage-lessons-empty">No material cards found.</p>
                            <?php else: ?>
                                <form method="GET" class="manage-lessons-selector">
                                    <input type="hidden" name="tab" value="manage_lessons">
                                    <label for="lesson-material-select">Material</label>
                                    <select id="lesson-material-select" name="material_slug" onchange="this.form.submit()">
                                        <option value="">Choose a material...</option>
                                        <?php foreach ($materials as $material): ?>
                                            <option value="<?= htmlspecialchars($material['slug']) ?>" <?= ($selected_material_slug === (string) $material['slug']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($material['title']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <noscript><button type="submit" class="aero-button secondary">Show sections</button></noscript>
                                </form>

                                <?php if ($selected_material_slug === ''): ?>
                                    <div class="manage-lessons-placeholder">
                                        <span class="manage-lessons-placeholder-icon">📚</span>
                                        <strong>Select a material to continue</strong>
                                        <span>Its sections and edit controls will appear here.</span>
                                    </div>
                                <?php elseif (empty($selected_sections)): ?>
                                    <div class="manage-lessons-placeholder">
                                        <span class="manage-lessons-placeholder-icon">📝</span>
                                        <strong>No sections yet</strong>
                                        <span>This material does not have any lesson sections to manage.</span>
                                    </div>
                                <?php else: ?>
                                    <div class="material-group-card selected-material-group">
                                        <div class="material-group-title">
                                            📁 <span><?= htmlspecialchars($selected_material['title'] ?? $selected_material_slug) ?></span>
                                            <code><?= htmlspecialchars($selected_material_slug) ?></code>
                                        </div>
                                        <table class="lessons-table">
                                            <tr>
                                                <th>Section Title</th>
                                                <th class="admin-actions-heading">Actions</th>
                                            </tr>
                                            <?php foreach ($selected_sections as $c): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($c['section_title']) ?></strong></td>
                                                <td class="admin-actions-cell">
                                                    <a href="admin.php?edit_section=<?= (int) $c['id'] ?>&amp;material_slug=<?= urlencode($selected_material_slug) ?>" class="aero-button secondary admin-action-icon admin-edit-action" aria-label="Edit lesson section" title="Edit lesson section">Edit</a>
                                                    <form method="POST" class="aero-delete-form" style="display:inline; margin-left: 4px;">
                                                        <input type="hidden" name="action" value="delete_content_section">
                                                        <input type="hidden" name="content_id" value="<?= (int) $c['id'] ?>">
                                                        <input type="hidden" name="material_slug" value="<?= htmlspecialchars($selected_material_slug) ?>">
                                                        <button type="button" class="aero-button admin-action-icon admin-delete-action delete-trigger" aria-label="Delete lesson section" title="Delete lesson section">Delete</button>
                                                    </form>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                <?php endif; ?>

            <?php endif; ?>

        </div>

        <!-- Window Status Bar -->
        <div class="window-statusbar" style="padding: 4px 13px;">
            <span>(made with Frutiger Aero vibes & childhood passion) | Made by Jumana - All Rights Reserved</span>
            <span class="status-icons-right">📶 🔊 🪟</span>
        </div>
    </div>

    <script>
        function addBlock() {
            const container = document.getElementById('blocks-container');
            const blockHtml = `
                <div class="topic-block-card">
                    <button type="button" class="aero-button remove-block-btn add-lesson-remove-btn admin-action-icon admin-delete-action" aria-label="Remove topic block" title="Remove topic block" onclick="this.parentElement.remove()">Remove</button>
                    <div class="form-group">
                        <label>Topic / Point Heading</label>
                        <input type="text" name="block_heading[]" placeholder="Heading..." required>
                    </div>
                    <div class="form-group">
                        <label>Explanation Points (Each line becomes a bullet)</label>
                        <textarea name="block_points[]" placeholder="Point 1&#10;Use &#96;cout&#96; to print output"></textarea>
                        <small style="display:block; color:#546e7a; margin-top:4px;">Use backticks for inline syntax, for example: &#96;cout&#96; or &#96;int main()&#96;.</small>
                    </div>
                    <div class="form-group">
                        <label>Code Snippet (Optional)</label>
                        <textarea name="block_code[]" class="code-box" placeholder="Code here..."></textarea>
                    </div>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', blockHtml);
        }

        function addEditBlock() {
            const container = document.getElementById('edit-blocks-container');
            const blockHtml = `
                <div class="topic-block-card">
                    <button type="button" class="aero-button remove-block-btn admin-action-icon admin-delete-action" aria-label="Remove topic block" title="Remove topic block" onclick="this.parentElement.remove()">🗑</button>
                    <div class="form-group">
                        <label>Topic / Point Heading</label>
                        <input type="text" name="block_heading[]" placeholder="Heading..." required>
                    </div>
                    <div class="form-group">
                        <label>Explanation Points (Each line becomes a bullet)</label>
                        <textarea name="block_points[]" placeholder="Point 1&#10;Use &#96;cout&#96; to print output"></textarea>
                        <small style="display:block; color:#546e7a; margin-top:4px;">Use backticks for inline syntax, for example: &#96;cout&#96; or &#96;int main()&#96;.</small>
                    </div>
                    <div class="form-group">
                        <label>Code Snippet (Optional)</label>
                        <textarea name="block_code[]" class="code-box" placeholder="Code here..."></textarea>
                    </div>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', blockHtml);
        }

        // Custom Aero Alert Dialog System
        let currentActionCallback = null;

        function showAeroAlert(title, message, callback) {
            document.getElementById('aero-alert-title').innerText = title;
            document.getElementById('aero-alert-msg').innerText = message;
            const alertModal = document.getElementById('aero-alert-overlay');
            alertModal.classList.add('open');
            alertModal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('modal-open');
            currentActionCallback = callback;
        }

        function closeAeroAlert(confirmed) {
            const alertModal = document.getElementById('aero-alert-overlay');
            alertModal.classList.remove('open');
            alertModal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('modal-open');
            if (confirmed && currentActionCallback) {
                currentActionCallback();
            }
            currentActionCallback = null;
        }

        document.getElementById('aero-alert-confirm').addEventListener('click', function() {
            closeAeroAlert(true);
        });

        // Attach event listeners to trigger the Aero Alert instead of default browser confirm prompts
        document.addEventListener('DOMContentLoaded', function() {
            // Intercept save / submit actions
            document.querySelectorAll('.aero-monitored-form').forEach(form => {
                const saveBtn = form.querySelector('.save-trigger');
                if (saveBtn) {
                    saveBtn.addEventListener('click', function(e) {
                        if (form.checkValidity()) {
                            e.preventDefault();
                            showAeroAlert(
                                'Save Edits Notice',
                                'You are about to save changes to the database. Do you want to proceed?',
                                function() { form.submit(); }
                            );
                        }
                    });
                }
            });

            // Intercept delete actions
            document.querySelectorAll('.aero-delete-form').forEach(form => {
                const deleteBtn = form.querySelector('.delete-trigger');
                if (deleteBtn) {
                    deleteBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        showAeroAlert(
                            'Delete Confirmation',
                            'Warning: You are about to delete this item permanently. Do you wish to continue?',
                            function() { form.submit(); }
                        );
                    });
                }
            });

            // Intercept logout action
            const logoutBtn = document.getElementById('logout-trigger');
            if (logoutBtn) {
                logoutBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const logoutUrl = this.getAttribute('href');
                    showAeroAlert(
                        'Logout Confirmation',
                        'Are you sure you want to end your session and log out?',
                        function() { window.location.href = logoutUrl; }
                    );
                });
            }
        });
    </script>
</body>
</html>