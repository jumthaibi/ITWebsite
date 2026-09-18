<?php
require_once '../session.php';
include '../db.php';

$stmt = $pdo->query("SELECT * FROM materials");
$materials = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Study Materials | Frutiger Aero Edition</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Varela+Round&display=swap" rel="stylesheet">
</head>
<body>

    <!-- OS Window Container -->
    <div class="os-window app-window classic-window materials-window">
        <!-- Window Title Bar -->
        <div class="window-header">
            <div class="window-title">
                <span class="os-icon">🌐</span> IT-Students-Hub.io / materials
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
                <span>🔒 http://it-students-hub.io/materials/materials.php</span>
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

        <!-- Main Window Content (Aero Style) -->
        <div class="window-body" style="padding: 14px; gap: 14px;">

            <!-- Main Content Area -->
            <div class="main-content" style="gap: 10px;">
                <div class="content-box welcome-box" style="padding: 12px 16px;">
                    <h2 style="font-size: 1.15rem; margin-bottom: 5px;">Study Materials Repository 📚✨</h2>
                    <p style="font-size: 0.86rem;">
                        Browse through our collection of programming resources, guides, and course references below.
                    </p>
                </div>

                <!-- Portal Sections Overview (Dynamic Cards from Database) -->
                <div class="aero-grid" style="grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 10px; margin-top: 0;">
                    <?php foreach ($materials as $mat): ?>
                        <div class="content-box" style="padding: 11px; margin: 0; display: flex; flex-direction: column; justify-content: space-between;">
                            <div>
                                <h4 style="margin: 0 0 3px 0; color: #0284c7; font-size: 0.92rem;"><?= htmlspecialchars($mat['title']) ?></h4>
                                <p style="margin: 0; font-size: 0.79rem; color: #64748b; line-height: 1.35;"><?= htmlspecialchars($mat['description']) ?></p>
                            </div>
                            <a href="view-material.php?slug=<?= urlencode($mat['slug']) ?>" class="aero-button primary" style="font-size: 10.5px; padding: 4px 9px; margin-top: 10px; text-decoration:none; display:inline-block; align-self: flex-start;">View Material</a>
                        </div>
                    <?php endforeach; ?>
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