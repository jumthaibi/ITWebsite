<?php
require_once 'session.php';
include 'db.php';

// Fetch dynamic index content from database
$stmt = $pdo->prepare("SELECT * FROM content WHERE page_name = 'index'");
$stmt->execute();
$index_content = $stmt->fetch();

$main_title = $index_content['section_title'] ?? "Welcome to my Frutiger Aero Space! 🫧✨";
$main_intro = isset($index_content['section_content']) ? strip_tags($index_content['section_content']) : "A centralized tech & academic hub built for IT students. I chose this specific design because it truly reminds me of my deep passion for computers since childhood and how this journey all began.";
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IT Students Portal | Frutiger Aero Edition</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Varela+Round&display=swap" rel="stylesheet">
</head>
<body>

    <!-- OS Window Container -->
    <div class="os-window app-window classic-window home-window">
        <!-- Window Title Bar -->
        <div class="window-header">
            <div class="window-title">
                <span class="os-icon">🌐</span> IT-Students-Hub.io
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
                <span>🔒 http://it-students-hub.io/portal</span>
            </div>
            <div class="browser-tools">
                <button class="tool-btn">🔍</button>
            </div>
        </div>

        <!-- Browser Tabs -->
        <div class="browser-tabs">
            <div class="tab active"><a href="index.php" style="text-decoration:none; color:inherit;">🏠 home</a></div>
            <div class="tab"><a href="materials/materials.php" style="text-decoration:none; color:inherit;">📚 materials</a></div>
            <div class="tab"><a href="pages/gpa.php" style="text-decoration:none; color:inherit;">📊 gpa calculator</a></div>
            <div class="tab"><a href="pages/games.php" style="text-decoration:none; color:inherit;">🎮 games</a></div>
            <div class="tab"><a href="pages/chat.php" style="text-decoration:none; color:inherit;">💬 chat</a></div>
        </div>

        <!-- Main Window Content (Aero Style) -->
        <div class="window-body" style="padding: 14px; gap: 14px;">
            
            <!-- Sidebar Profile Area -->
            <div class="sidebar" style="padding: 14px; width: 270px;">
                <div class="profile-pic-container" style="width: 75px; height: 75px; margin-bottom: 10px;">
                    <div class="profile-glow"></div>
                    <div class="profile-avatar" style="font-size: 2.1rem;">🌿</div>
                </div>
                <div class="profile-info">
                    <h3 style="margin-bottom: 3px;">Made by Jumana</h3>
                    <p class="status-text" style="font-size: 0.78rem; margin-bottom: 10px;">🌱 Data Science Student at the University of Jordan | coding & chilling</p>
                </div>
                
                <div class="sidebar-links" style="gap: 6px; margin-bottom: 8px;">
                    <a href="https://jjumthaibi.ct.ws/" target="_blank" class="sidebar-link" style="padding: 4px 9px; font-size: 0.81rem;">🌐 Portfolio Website</a>
                    <a href="https://github.com/jumthaibi" target="_blank" class="sidebar-link" style="padding: 4px 9px; font-size: 0.81rem;">🐱 GitHub: jumthaibi</a>
                </div>
            </div>

            <!-- Main Content Area -->
            <div class="main-content" style="gap: 10px;">
                <div class="content-box welcome-box" style="padding: 12px 16px;">
                    <h2 style="font-size: 1.15rem; margin-bottom: 5px;"><?= htmlspecialchars($main_title) ?></h2>
                    <p style="font-size: 0.86rem;">
                        <?= nl2br(htmlspecialchars($main_intro)) ?>
                    </p>
                </div>

                <!-- Portal Sections Overview -->
                <div class="aero-grid" style="grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 0;">
                    <div class="content-box" style="padding: 11px; margin: 0;">
                        <h4 style="margin: 0 0 3px 0; color: #0284c7; font-size: 0.92rem;">📚 Materials</h4>
                        <p style="margin: 0; font-size: 0.79rem; color: #64748b; line-height: 1.35;">Access organized study resources, programming languages, and academic references.</p>
                        <a href="materials/materials.php" class="aero-button primary" style="font-size: 10.5px; padding: 4px 9px; margin-top: 6px; text-decoration:none; display:inline-block;">Explore</a>
                    </div>

                    <div class="content-box" style="padding: 11px; margin: 0;">
                        <h4 style="margin: 0 0 3px 0; color: #0284c7; font-size: 0.92rem;">📊 GPA Calculator</h4>
                        <p style="margin: 0; font-size: 0.79rem; color: #64748b; line-height: 1.35;">Easily calculate, track, and monitor your cumulative university GPA and credit hours.</p>
                        <a href="pages/gpa.php" class="aero-button primary" style="font-size: 10.5px; padding: 4px 9px; margin-top: 6px; text-decoration:none; display:inline-block;">Calculate</a>
                    </div>

                    <div class="content-box" style="padding: 11px; margin: 0;">
                        <h4 style="margin: 0 0 3px 0; color: #0284c7; font-size: 0.92rem;">🎮 Games</h4>
                        <p style="margin: 0; font-size: 0.79rem; color: #64748b; line-height: 1.35;">Take a fun break with casual retro games like Snake, Mini Mario, and interactive challenges.</p>
                        <a href="pages/games.php" class="aero-button secondary" style="font-size: 10.5px; padding: 4px 9px; margin-top: 6px; text-decoration:none; display:inline-block;">Play</a>
                    </div>

                    <div class="content-box" style="padding: 11px; margin: 0;">
                        <h4 style="margin: 0 0 3px 0; color: #0284c7; font-size: 0.92rem;">💬 Chat</h4>
                        <p style="margin: 0; font-size: 0.79rem; color: #64748b; line-height: 1.35;">Connect with fellow students, share insights, and chat with the community.</p>
                        <a href="pages/chat.php" class="aero-button secondary" style="font-size: 10.5px; padding: 4px 9px; margin-top: 6px; text-decoration:none; display:inline-block;">Open Chat</a>
                    </div>
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