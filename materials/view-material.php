<?php
require_once '../session.php';
require_once '../db.php';
require_once '../content_helpers.php';

// Determine the slug robustly from various possible URL parameter formats (?slug=, ?page=, or ?python-basics)
$slug = '';
if (isset($_GET['slug'])) {
    $slug = trim($_GET['slug']);
} elseif (isset($_GET['page'])) {
    $slug = trim($_GET['page']);
} else {
    $keys = array_keys($_GET);
    if (!empty($keys)) {
        $slug = !empty($keys[0]) ? trim($keys[0]) : trim($_GET[$keys[0]]);
    }
}

if (empty($slug) && !empty($_SERVER['QUERY_STRING'])) {
    $slug = trim($_SERVER['QUERY_STRING'], '/=');
}

// Default fallback if no slug is provided
if (empty($slug)) {
    $slug = 'cpp-basics';
}

// Fetch material metadata from the database
$stmt = $pdo->prepare("SELECT * FROM materials WHERE slug = ?");
$stmt->execute([$slug]);
$material = $stmt->fetch();

// Fetch content sections corresponding to the requested slug/page_name
$stmtContent = $pdo->prepare("SELECT * FROM content WHERE page_name = ?");
$stmtContent->execute([$slug]);
$sections = $stmtContent->fetchAll();
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $material ? htmlspecialchars($material['title']) : 'Material View' ?> | IT Students Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Varela+Round&family=Fira+Code&display=swap" rel="stylesheet">
    <style>
        /* Custom styling for code boxes: white background, code font, text-align left */
        pre, code, .code-box, pre code {
            background-color: #ffffff !important;
            font-family: 'Fira Code', Consolas, Monaco, 'Andale Mono', monospace !important;
            text-align: left !important;
            direction: ltr !important;
        }
        /* Ensure any container holding code or generic block elements within sections handles code blocks nicely */
        .window-body pre, .window-body code {
            background: #ffffff;
            font-family: 'Fira Code', monospace;
            text-align: left;
            padding: 8px;
            border-radius: 4px;
            border: 1px solid #e0e0e0;
            display: block;
            overflow-x: auto;
        }
        .blk-btn{
            background-color: white!important;
        }
    </style>
</head>
<body>

    <!-- OS Window Container -->
    <div class="os-window app-window classic-window material-reader-window">
        <!-- Window Title Bar -->
        <div class="window-header">
            <div class="window-title">
                <span class="os-icon">🌐</span> IT-Students-Hub.io / Materials / <?= $material ? htmlspecialchars($material['title']) : htmlspecialchars($slug) ?>
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
                <button class="arrow-btn" onclick="history.back();">⬅</button>
                <button class="arrow-btn" onclick="history.forward();">➡</button>
            </div>
            <div class="address-bar">
                <span>🔒 http://it-students-hub.io/materials/view-material.php?slug=<?= htmlspecialchars($slug) ?></span>
            </div>
            <div class="browser-tools">
                <button class="tool-btn">🔍</button>
            </div>
        </div>

        <!-- Browser Tabs -->
        <div class="browser-tabs">
            <div class="tab"><a href="../index.php" style="text-decoration:none; color:inherit;">🏠 home</a></div>
            <div class="tab active"><a href="materials.php" style="text-decoration:none; color:inherit;">📚 materials</a></div>
            <div class="tab"><a href="../pages/gpa.php" style="text-decoration:none; color:inherit;">📊 gpa calculator</a></div>
            <div class="tab"><a href="../pages/games.php" style="text-decoration:none; color:inherit;">🎮 games</a></div>
            <div class="tab"><a href="../pages/chat.php" style="text-decoration:none; color:inherit;">💬 chat</a></div>
        </div>

        <!-- Main Window Content (Aero Style) - Height matched to index view (min-height: 380px) -->
        <div class="window-body material-reader-body" style="padding: 14px; gap: 14px;">
            
            <!-- Sidebar Navigation / Quick links (Thinner width: 220px) -->
            <div class="sidebar" style="padding: 12px; width: 220px; display: flex; flex-direction: column; height: 100%; box-sizing: border-box; overflow: hidden;">
                <div class="profile-pic-container" style="width: 50px; height: 50px; margin: 0 auto 6px auto;">
                    <div class="profile-glow"></div>
                    <div class="profile-avatar" style="font-size: 1.4rem;">📚</div>
                </div>
                <div class="profile-info" style="text-align: center; margin-bottom: 6px;">
                    <h3 style="margin-bottom: 2px; font-size: 0.85rem;"><?= $material ? htmlspecialchars($material['title']) : 'Study Material' ?></h3>
                    <p class="status-text" style="font-size: 0.7rem; margin-bottom: 0;">✨ Curriculum sections</p>
                </div>
                
                <div class="sidebar-links" style="gap: 4px; margin-bottom: 6px;">
                    <a href="materials.php" class="sidebar-link" style="padding: 4px 6px; font-size: 0.75rem; text-align:center; display:block;">⬅ Back to Materials</a>
                </div>

                <!-- Sections list under the title in the sidebar -->
                <div style="flex-grow: 1; overflow-y: auto; border-top: 1px solid rgba(179, 215, 255, 0.6); padding-top: 6px; margin-top: 2px;">
                    <p style="font-size: 0.72rem; font-weight: bold; color: #0277bd; margin-bottom: 4px;">Table of Contents:</p>
                    <?php if (!empty($sections)): ?>
                        <div style="display: flex; flex-direction: column; gap: 3px;">
                            <?php foreach ($sections as $index => $section): ?>
                                <a href="#section-<?= $index ?>" style="font-size: 0.72rem; color: #37474f; text-decoration: none; padding: 2px 5px; border-radius: 4px; background: rgba(255,255,255,0.5); display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($section['section_title']) ?>">
                                    ▪ <?= htmlspecialchars($section['section_title']) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Main Content Area with Fixed Height and Vertical Scroll -->
            <div class="main-content material-reader-content" style="gap: 8px; display: flex; flex-direction: column; margin-left: 0;">

                <!-- Scrollable Container for Sections -->
                <div class="content-box" style="padding: 10px; flex-grow: 1; overflow-y: auto; display: flex; flex-direction: column; gap: 10px;">
                    <?php if (!empty($sections)): ?>
                        <?php foreach ($sections as $index => $section): ?>
                            <div id="section-<?= $index ?>" style="background: rgba(245, 250, 255, 0.8); border: 1px solid #b3d7ff; border-radius: 8px; padding: 10px; box-shadow: inset 0 1px 0 rgba(255,255,255,1);">
                                <h2 style="font-size: 1.18rem; font-weight: 700; color: #0277bd; margin: 0 0 8px 0; padding: 0 0 5px 12px; border-bottom: 1px solid rgba(179, 215, 255, 0.75);"><?= htmlspecialchars($section['section_title']) ?></h2>
                                <div style="font-size: 0.79rem; color: #37474f; line-height: 1.4; padding-left: 12px;"><?= safe_lesson_html((string) $section['section_content']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="font-size: 0.79rem; color: #546e7a; padding-left: 12px;">No content found for this material slug.</p>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- Window Status Bar -->
        <div class="window-statusbar" style="padding: 4px 13px;">
            <span>(made with Frutiger Aero vibes & childhood passion) | Made by Jumana - All Rights Reserved</span>
            <span class="status-icons-right">📶 🔊 🪟</span>
        </div>
    </div>

</body>
</html>
