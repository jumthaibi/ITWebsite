<?php
require_once '../db.php';
require_once '../auth.php';
require_once 'profile_helpers.php';

$auth_error = '';
$auth_mode = 'login';
$auth_open = false;
$requested_game = $_GET['play'] ?? '';
$valid_games = ['snake', 'memory', 'tictactoe', 'mario', 'invaders', 'bird'];
$game_best_modes = [
    'snake' => 'higher',
    'memory' => 'lower',
    'tictactoe' => 'higher',
    'mario' => 'higher',
    'invaders' => 'higher',
    'invaders_level' => 'higher',
    'bird' => 'higher',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth_action'])) {
    $auth_mode = $_POST['auth_action'] === 'register' ? 'register' : 'login';
    $auth_open = true;
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $play_after_auth = $_POST['play_game'] ?? '';
    if (in_array($play_after_auth, $valid_games, true)) {
        $requested_game = $play_after_auth;
    }

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
                // Regular game accounts are always non-admin accounts.
                $stmt = $pdo->prepare('INSERT INTO users (username, password, is_admin) VALUES (?, ?, 0)');
                $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
                $userId = (int) $pdo->lastInsertId();
                $_SESSION['user_id'] = $userId;
                $_SESSION['username'] = $username;
                $_SESSION['games_user_id'] = $userId;
                $_SESSION['games_username'] = $username;
                $_SESSION['games_is_admin'] = 0;
                $_SESSION['is_admin'] = 0;
                $redirect = in_array($play_after_auth, $valid_games, true) ? '?play=' . urlencode($play_after_auth) : '';
                header('Location: games.php' . $redirect);
                exit();
            }
        } elseif ($user && password_verify($password, (string) $user['password'])) {
            sign_in_user($user);
            $redirect = in_array($play_after_auth, $valid_games, true) ? '?play=' . urlencode($play_after_auth) : '';
            header('Location: games.php' . $redirect);
            exit();
        } else {
            $auth_error = 'That username and password do not match.';
        }
    }
}

$games_username = $_SESSION['games_username'] ?? '';
$games_user_id = (int) ($_SESSION['games_user_id'] ?? 0);
$games_logged_in = $games_username !== '';
$profile_schema_ready = ensureProfileSchema($pdo);
$games_profile = $profile_schema_ready ? getUserProfile($pdo, $games_user_id) : null;
$games_profile_image = $games_profile['profile_image'] ?? '';
// Database changes belong in migrations, never in page requests.
$game_scores_ready = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['score_action'] ?? '') === 'save_score') {
    header('Content-Type: application/json; charset=utf-8');
    $game = $_POST['game'] ?? '';
    $score = filter_var($_POST['score'] ?? null, FILTER_VALIDATE_INT);

    if (!$games_user_id || !isset($game_best_modes[$game])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => 'Sign in to save a personal best.']);
        exit();
    }
    if ($score === false || $score < 0 || $score > 1000000) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'Invalid score.']);
        exit();
    }
    if (!$game_scores_ready) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'message' => 'The score table is not available yet.']);
        exit();
    }

    $comparison = $game_best_modes[$game] === 'lower'
        ? 'LEAST(best_score, VALUES(best_score))'
        : 'GREATEST(best_score, VALUES(best_score))';
    $stmt = $pdo->prepare(
        "INSERT INTO game_scores (user_id, game_key, best_score)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE best_score = {$comparison}, updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute([$games_user_id, $game, $score]);
    $stmt = $pdo->prepare('SELECT best_score FROM game_scores WHERE user_id = ? AND game_key = ? LIMIT 1');
    $stmt->execute([$games_user_id, $game]);
    echo json_encode(['ok' => true, 'game' => $game, 'best_score' => (int) $stmt->fetchColumn()]);
    exit();
}

$personal_bests = [];
if ($games_user_id && $game_scores_ready) {
    $stmt = $pdo->prepare('SELECT game_key, best_score FROM game_scores WHERE user_id = ?');
    $stmt->execute([$games_user_id]);
    foreach ($stmt->fetchAll() as $row) {
        $personal_bests[$row['game_key']] = (int) $row['best_score'];
    }
}

if (!$games_logged_in && !in_array($requested_game, $valid_games, true)) {
    $requested_game = '';
}
// The arcade is account-gated: show the access dialog as soon as a guest
// arrives, rather than making them discover the login button first.
if (!$games_logged_in) {
    $auth_open = true;
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pixel Game Zone | IT Students Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Press+Start+2P&family=Varela+Round&display=swap" rel="stylesheet">
</head>
<body>

    <div class="os-window app-window games-window">
        <!-- Window Title Bar -->
        <div class="window-header">
            <div class="window-title">
                <span class="os-icon">🌐</span> IT-Students-Hub.io / games
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
                <button class="arrow-btn" type="button" aria-label="Go back">⬅</button>
                <button class="arrow-btn" type="button" aria-label="Go forward">➡</button>
            </div>
            <div class="address-bar">
                <span>🔒 http://it-students-hub.io/portal/pages/games.php</span>
            </div>
            <div class="browser-tools">
                <button class="tool-btn" type="button" aria-label="Search">🔍</button>
            </div>
        </div>

        <!-- Browser Tabs -->
        <div class="browser-tabs">
            <div class="tab"><a href="../index.php" style="text-decoration:none; color:inherit;">🏠 home</a></div>
            <div class="tab"><a href="../materials/materials.php" style="text-decoration:none; color:inherit;">📚 materials</a></div>
            <div class="tab"><a href="gpa.php" style="text-decoration:none; color:inherit;">📊 gpa calculator</a></div>
            <div class="tab active"><a href="games.php" style="text-decoration:none; color:inherit;">🎮 games</a></div>
            <div class="tab"><a href="chat.php" style="text-decoration:none; color:inherit;">💬 chat</a></div>
            <div class="navbar-account">
                <?php if ($games_logged_in): ?>
                    <div class="games-user-chip">
                        <button class="profile-trigger games-profile-trigger" type="button" data-profile-user-id="<?= (int) $games_user_id ?>" aria-label="Open your profile">
                            <span class="profile-trigger-avatar">
                                <?php if ($games_profile_image): ?>
                                    <img class="profile-avatar-image" src="<?= htmlspecialchars(profileImageToDataUri((string) $games_profile_image)) ?>" alt="">
                                <?php else: ?>
                                    👤
                                <?php endif; ?>
                            </span>
                            <strong><?= htmlspecialchars($games_username) ?></strong>
                        </button>
                        <a class="games-logout" href="../logout.php?next=pages/games.php" aria-label="Log out" title="Log out">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M10 5H6.75A1.75 1.75 0 0 0 5 6.75v10.5A1.75 1.75 0 0 0 6.75 19H10"></path>
                                <path d="M12 12h7"></path>
                                <path d="m16 8 4 4-4 4"></path>
                            </svg>
                            <span>Log out</span>
                        </a>
                    </div>
                <?php else: ?>
                    <button class="games-login-link" id="openAuth" type="button">🔐 sign in</button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Games Area -->
        <div class="window-body games-body<?= !$games_logged_in ? ' is-locked' : '' ?>">
            <main class="main-content games-main">
                <section class="games-grid" aria-label="Available games">
                    <article class="game-card game-card-snake">
                        <div class="game-art" aria-hidden="true"><span class="pixel-art">🐍</span><span class="game-art-sparkle">✦</span></div>
                        <span class="card-header-tag">ARCADE CLASSIC</span>
                        <h3>Pixel Snake</h3>
                        <p>Guide the hungry snake, grab glowing fruit, and try not to bump into yourself.</p>
                        <div class="game-card-footer"><span class="game-meta">⌨️ keys / touch</span><button class="aero-button primary play-game" type="button" data-game="snake">Play game</button></div>
                    </article>

                    <article class="game-card game-card-memory">
                        <div class="game-art" aria-hidden="true"><span class="pixel-art">🧠</span><span class="game-art-sparkle">✦</span></div>
                        <span class="card-header-tag">BRAIN BREAK</span>
                        <h3>Memory Bubbles</h3>
                        <p>Flip the bubbles and find all eight matching pairs in as few moves as possible.</p>
                        <div class="game-card-footer"><span class="game-meta">🫧 16 cards</span><button class="aero-button primary play-game" type="button" data-game="memory">Play game</button></div>
                    </article>

                    <article class="game-card game-card-tictactoe">
                        <div class="game-art" aria-hidden="true"><span class="pixel-art">⭕</span><span class="game-art-sparkle">✦</span></div>
                        <span class="card-header-tag">TINY DUEL</span>
                        <h3>Cloud Tic-Tac-Toe</h3>
                        <p>Challenge the friendly cloud bot. Get three of your symbols in a row to win.</p>
                        <div class="game-card-footer"><span class="game-meta">👤 vs bot</span><button class="aero-button primary play-game" type="button" data-game="tictactoe">Play game</button></div>
                    </article>

                    <article class="game-card game-card-mario">
                        <div class="game-art" aria-hidden="true"><span class="pixel-art">🍄</span><span class="game-art-sparkle">✦</span></div>
                        <span class="card-header-tag">PIXEL RUNNER</span>
                        <h3>Pixel Mario</h3>
                        <p>Jump over pipes, collect coins, and keep the tiny hero running through the pixel sky.</p>
                        <div class="game-card-footer"><span class="game-meta">␣ jump / touch</span><button class="aero-button primary play-game" type="button" data-game="mario">Play game</button></div>
                    </article>

                    <article class="game-card game-card-invaders">
                        <div class="game-art" aria-hidden="true"><span class="pixel-art">👾</span><span class="game-art-sparkle">✦</span></div>
                        <span class="card-header-tag">ARCADE BLAST</span>
                        <h3>Aqua Invaders</h3>
                        <p>Move your little starship, fire bubbles, and clear the pixel invaders from the sky.</p>
                        <div class="game-card-footer"><span class="game-meta">← → / fire</span><button class="aero-button primary play-game" type="button" data-game="invaders">Play game</button></div>
                    </article>

                    <article class="game-card game-card-bird">
                        <div class="game-art" aria-hidden="true"><span class="pixel-art">🐦</span><span class="game-art-sparkle">✦</span></div>
                        <span class="card-header-tag">SKY ADVENTURE</span>
                        <h3>Flappy Bird</h3>
                        <p>Flap through the cloud gates, collect golden stars, and fly as far as you can.</p>
                        <div class="game-card-footer"><span class="game-meta">⬆ flap / touch</span><button class="aero-button primary play-game" type="button" data-game="bird">Play game</button></div>
                    </article>
                </section>
            </main>
        </div>

        <div class="window-statusbar">
            <span>🎮 six tiny games loaded | made with Frutiger Aero vibes & childhood passion</span>
            <span class="status-icons-right">📶 🔊 🪟</span>
        </div>
    </div>

    <?php include 'profile_modal.php'; ?>
    <script>
        window.profileConfig = {
            endpoint: 'profile.php',
            currentUserId: <?= (int) $games_user_id ?>
        };
    </script>
    <script src="../assets/js/profile.js"></script>

    <!-- Game access dialog: guests see this before entering the arcade. -->
    <div class="auth-modal <?= $auth_open ? 'open' : '' ?>" id="authModal" aria-hidden="<?= $auth_open ? 'false' : 'true' ?>">
        <div class="os-window auth-dialog-window chat-simple-dialog" role="dialog" aria-modal="true" aria-labelledby="authTitle">
            <div class="window-header">
                <div class="window-title"><span class="os-icon">🎮</span> Pixel Arcade Sign In</div>
                <div class="window-controls">
                    <span class="control-btn minimize"></span>
                    <span class="control-btn maximize"></span>
                    <button class="control-btn close auth-window-close" id="closeAuth" type="button" aria-label="Close sign in dialog"></button>
                </div>
            </div>
            <div class="browser-toolbar">
                <div class="address-bar"><span>Arcade Sign In Dialog</span></div>
            </div>
            <div class="window-body auth-dialog-body">
                <div class="content-box auth-panel">
                    <span class="card-header-tag">PIXEL ARCADE ACCESS</span>
                    <h2 id="authTitle">Enter the Pixel Arcade 🎮</h2>
                    <p class="auth-description">Create an account, or sign in if you already have one.</p>
                    <div class="auth-tabs">
                        <button type="button" class="<?= $auth_mode === 'login' ? 'active' : '' ?>" data-auth-mode="login">Sign in</button>
                        <button type="button" class="<?= $auth_mode === 'register' ? 'active' : '' ?>" data-auth-mode="register">Create account</button>
                    </div>
                    <?php if ($auth_error): ?>
                        <div class="auth-error" role="alert">⚠️ <?= htmlspecialchars($auth_error) ?></div>
                    <?php endif; ?>
                    <form class="auth-form <?= $auth_mode === 'register' ? 'is-register' : '' ?>" id="authForm" method="post" action="games.php">
                        <input type="hidden" name="auth_action" id="authAction" value="<?= $auth_mode ?>">
                        <input type="hidden" name="play_game" id="authPlayGame" value="<?= htmlspecialchars($requested_game) ?>">
                        <label for="authUsername">Username</label>
                        <input id="authUsername" name="username" type="text" minlength="3" maxlength="30" pattern="[A-Za-z0-9_.-]{3,30}" autocomplete="username" placeholder="e.g. pixel_student" required>
                        <small>3–30 letters, numbers, dots, dashes, or underscores.</small>
                        <label for="authPassword">Password</label>
                        <input id="authPassword" name="password" type="password" minlength="4" autocomplete="<?= $auth_mode === 'register' ? 'new-password' : 'current-password' ?>" placeholder="Your password" required>
                        <button class="aero-button primary auth-submit" type="submit"><?= $auth_mode === 'register' ? 'Create account & play' : 'Sign in & play' ?></button>
                    </form>
                </div>
            </div>
            <div class="window-statusbar"><span>Frutiger Aero Account System</span><span>Ready</span></div>
        </div>
    </div>

    <!-- Frutiger Aero confirmation used before ending the arcade session. -->
    <div class="auth-modal games-alert-modal" id="gamesLogoutModal" aria-hidden="true">
        <div class="os-window auth-dialog-window games-alert-window chat-simple-dialog" role="alertdialog" aria-modal="true" aria-labelledby="gamesLogoutTitle">
            <div class="window-header">
                <div class="window-title"><span class="os-icon">⚠️</span> System Notice</div>
                <div class="window-controls">
                    <span class="control-btn minimize"></span>
                    <span class="control-btn maximize"></span>
                    <button class="control-btn close auth-window-close" id="closeGamesLogout" type="button" aria-label="Close logout confirmation"></button>
                </div>
            </div>
            <div class="browser-toolbar">
                <div class="address-bar"><span>System Alert Dialog</span></div>
            </div>
            <div class="window-body auth-dialog-body">
                <div class="content-box auth-panel games-alert-panel">
                    <span class="card-header-tag">ACCOUNT NOTICE</span>
                    <h2 id="gamesLogoutTitle">Logout Confirmation</h2>
                    <p class="auth-description">Are you sure you want to end your arcade session? You can sign in again anytime, but your games will be locked until you do.</p>
                    <div class="games-alert-actions">
                        <button class="aero-button primary" id="confirmGamesLogout" type="button">Log out</button>
                        <button class="aero-button secondary" id="cancelGamesLogout" type="button">Stay signed in</button>
                    </div>
                </div>
            </div>
            <div class="window-statusbar"><span>Frutiger Aero Alert System</span><span>Ready</span></div>
        </div>
    </div>

    <!-- One reusable game window keeps the page tidy while every card stays playable. -->
    <div class="game-modal" id="gameModal" aria-hidden="true">
        <div class="game-modal-panel" id="gameModalPanel" role="dialog" aria-modal="true" aria-labelledby="gameModalTitle">
            <div class="game-modal-header">
                <div>
                    <span class="card-header-tag" id="gameModalTag">PIXEL ARCADE</span>
                    <h2 id="gameModalTitle">Game</h2>
                    <p id="gameModalSubtitle">Ready?</p>
                </div>
                <div class="game-modal-tools">
                    <button class="sound-toggle" id="soundToggle" type="button" aria-label="Mute sound effects">🔊</button>
                    <button class="game-modal-close" id="closeGame" type="button" aria-label="Close game">×</button>
                </div>
            </div>
            <div class="game-stage" id="gameStage"></div>
        </div>
    </div>

    <script>
        window.gamesLoggedIn = <?= $games_logged_in ? 'true' : 'false' ?>;
        window.requestedGame = <?= json_encode(in_array($requested_game, $valid_games, true) ? $requested_game : '') ?>;
        window.authOpen = <?= $auth_open ? 'true' : 'false' ?>;
        window.personalBests = <?= json_encode($personal_bests, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        (() => {
            const modal = document.getElementById('gameModal');
            const stage = document.getElementById('gameStage');
            const modalTitle = document.getElementById('gameModalTitle');
            const modalSubtitle = document.getElementById('gameModalSubtitle');
            const modalTag = document.getElementById('gameModalTag');
            const modalPanel = document.getElementById('gameModalPanel');
            const closeButton = document.getElementById('closeGame');
            const authModal = document.getElementById('authModal');
            const authForm = document.getElementById('authForm');
            const authAction = document.getElementById('authAction');
            const authPlayGame = document.getElementById('authPlayGame');
            const gamesLogoutLink = document.querySelector('.games-logout');
            const gamesLogoutModal = document.getElementById('gamesLogoutModal');
            const confirmGamesLogout = document.getElementById('confirmGamesLogout');
            const cancelGamesLogout = document.getElementById('cancelGamesLogout');
            const closeGamesLogout = document.getElementById('closeGamesLogout');
            const soundToggle = document.getElementById('soundToggle');
            let logoutUrl = '';
            let activeCleanup = () => {};
            let activeGame = null;

            const gameInfo = {
                snake: { title: 'Pixel Snake', tag: 'ARCADE CLASSIC', subtitle: 'Use the arrow keys, WASD, or the touch buttons to collect fruit.' },
                memory: { title: 'Memory Bubbles', tag: 'BRAIN BREAK', subtitle: 'Find every matching pair. The fewer moves, the better.' },
                tictactoe: { title: 'Cloud Tic-Tac-Toe', tag: 'TINY DUEL', subtitle: 'You are X. Make a line before the cloud bot does.' },
                mario: { title: 'Pixel Mario', tag: 'PIXEL RUNNER', subtitle: 'Jump over pipes and collect coins. Press Space or tap Jump.' },
                invaders: { title: 'Aqua Invaders', tag: 'ARCADE BLAST', subtitle: 'Move your starship and fire bubbles at the invaders.' },
                bird: { title: 'Flappy Bird', tag: 'SKY ADVENTURE', subtitle: 'Flap through the gates and collect golden stars.' }
            };

            const sound = {
                muted: localStorage.getItem('itHubGameSoundMuted') === '1',
                context: null,
                ensure() {
                    if (!window.AudioContext && !window.webkitAudioContext) return;
                    if (!this.context) this.context = new (window.AudioContext || window.webkitAudioContext)();
                    if (this.context.state === 'suspended') this.context.resume();
                },
                tone(frequency, duration = .08, type = 'square', volume = .035, delay = 0) {
                    if (this.muted) return;
                    this.ensure();
                    if (!this.context) return;
                    const oscillator = this.context.createOscillator();
                    const gain = this.context.createGain();
                    oscillator.type = type;
                    oscillator.frequency.value = frequency;
                    gain.gain.setValueAtTime(volume, this.context.currentTime + delay);
                    gain.gain.exponentialRampToValueAtTime(.001, this.context.currentTime + delay + duration);
                    oscillator.connect(gain);
                    gain.connect(this.context.destination);
                    oscillator.start(this.context.currentTime + delay);
                    oscillator.stop(this.context.currentTime + delay + duration);
                },
                click() { this.tone(520, .045, 'square', .025); },
                collect() { this.tone(760, .07, 'triangle', .035); this.tone(1040, .09, 'triangle', .03, .055); },
                match() { this.tone(660, .08, 'sine', .03); this.tone(880, .12, 'sine', .03, .08); },
                miss() { this.tone(210, .1, 'sine', .025); },
                pop() { this.tone(300, .05, 'sine', .025); this.tone(520, .06, 'sine', .02, .04); },
                jump() { this.tone(420, .08, 'square', .03); this.tone(620, .1, 'square', .025, .06); },
                shoot() { this.tone(240, .07, 'sawtooth', .025); },
                hit() { this.tone(150, .11, 'square', .03); },
                win() { [660, 830, 1040].forEach((note, index) => this.tone(note, .12, 'triangle', .035, index * .1)); },
                lose() { [300, 220].forEach((note, index) => this.tone(note, .15, 'sawtooth', .025, index * .12)); }
            };

            function updateSoundButton() {
                soundToggle.textContent = sound.muted ? '🔇' : '🔊';
                soundToggle.setAttribute('aria-label', sound.muted ? 'Turn sound effects on' : 'Mute sound effects');
            }

            function setStage(html) {
                stage.innerHTML = html;
            }

            function setScore(value) {
                const score = document.getElementById('gameScore');
                if (score) score.textContent = value;
            }

            function getBest(game) {
                return Object.prototype.hasOwnProperty.call(window.personalBests, game)
                    ? Number(window.personalBests[game])
                    : null;
            }

            function saveBest(game, score, mode = 'higher') {
                const numericScore = Number(score);
                if (!Number.isFinite(numericScore)) return;
                const currentBest = getBest(game);
                const isBetter = currentBest === null || (mode === 'lower' ? numericScore < currentBest : numericScore > currentBest);
                if (!isBetter) return;
                window.personalBests[game] = numericScore;
                fetch('games.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                    body: new URLSearchParams({ score_action: 'save_score', game, score: String(numericScore) })
                }).then((response) => response.json()).then((result) => {
                    if (result.ok && result.game) window.personalBests[result.game] = Number(result.best_score);
                }).catch(() => {
                    // The page still shows the new result; the next completed run retries it.
                });
            }

            function bestLabel(game, emptyText = '—') {
                const best = getBest(game);
                return best === null ? emptyText : String(best);
            }

            function openGame(name) {
                if (!gameInfo[name]) return;
                if (!window.gamesLoggedIn) {
                    authPlayGame.value = name;
                    authModal.classList.add('open');
                    authModal.setAttribute('aria-hidden', 'false');
                    document.body.classList.add('modal-open');
                    document.getElementById('authUsername').focus();
                    return;
                }
                activeCleanup();
                activeGame = name;
                const info = gameInfo[name];
                modalTitle.textContent = info.title;
                modalTag.textContent = info.tag;
                modalSubtitle.textContent = info.subtitle;
                modalPanel.className = `game-modal-panel game-panel-${name}`;
                modal.classList.add('open');
                modal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('modal-open');
                const gameStart = {
                    snake: startSnake,
                    memory: startMemory,
                    tictactoe: startTicTacToe,
                    mario: startPixelMario,
                    invaders: startAquaInvaders,
                    bird: startCloudFlyer
                }[name];
                activeCleanup = gameStart();
            }

            function closeGame() {
                activeCleanup();
                activeCleanup = () => {};
                activeGame = null;
                modal.classList.remove('open');
                modal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('modal-open');
                stage.innerHTML = '';
            }

            function closeAuth() {
                authModal.classList.remove('open');
                authModal.setAttribute('aria-hidden', 'true');
                syncModalLock();
            }

            function syncModalLock() {
                const hasOpenOverlay = authModal.classList.contains('open')
                    || gamesLogoutModal.classList.contains('open')
                    || modal.classList.contains('open');
                document.body.classList.toggle('modal-open', hasOpenOverlay);
            }

            function openLogoutAlert(event) {
                event.preventDefault();
                logoutUrl = gamesLogoutLink.href;
                gamesLogoutModal.classList.add('open');
                gamesLogoutModal.setAttribute('aria-hidden', 'false');
                syncModalLock();
                cancelGamesLogout.focus();
            }

            function closeLogoutAlert() {
                gamesLogoutModal.classList.remove('open');
                gamesLogoutModal.setAttribute('aria-hidden', 'true');
                syncModalLock();
            }

            function startSnake() {
                setStage(`
                    <div class="game-score-row"><span>🍎 fruit: <strong id="gameScore">0</strong></span><span>best run: <strong id="snakeBest">${bestLabel('snake')}</strong></span></div>
                    <div class="snake-board-wrap"><canvas id="snakeCanvas" width="300" height="300" aria-label="Snake game board"></canvas></div>
                    <p class="game-message" id="snakeMessage">Press start when you are ready.</p>
                    <div class="game-actions"><button class="aero-button primary" id="snakeStart" type="button">▶ Start snake</button><button class="aero-button secondary" id="snakePause" type="button" disabled>Ⅱ Pause</button></div>
                    <div class="direction-pad" aria-label="Snake touch controls">
                        <div><button type="button" data-snake-dir="up">▲</button><button type="button" data-snake-dir="left">◀</button><button type="button" data-snake-dir="down">▼</button><button type="button" data-snake-dir="right">▶</button></div>
                    </div>
                `);
                const canvas = document.getElementById('snakeCanvas');
                const ctx = canvas.getContext('2d');
                const size = 20;
                let snake = [{ x: 7, y: 7 }, { x: 6, y: 7 }, { x: 5, y: 7 }];
                let food = { x: 11, y: 7 };
                let direction = { x: 1, y: 0 };
                let nextDirection = { x: 1, y: 0 };
                let score = 0;
                let timer = null;
                let running = false;
                let paused = false;
                const message = document.getElementById('snakeMessage');

                function draw() {
                    ctx.fillStyle = '#dff8ff';
                    ctx.fillRect(0, 0, canvas.width, canvas.height);
                    ctx.strokeStyle = 'rgba(42, 143, 197, .14)';
                    for (let i = 0; i <= 15; i++) {
                        ctx.beginPath(); ctx.moveTo(i * size, 0); ctx.lineTo(i * size, 300); ctx.stroke();
                        ctx.beginPath(); ctx.moveTo(0, i * size); ctx.lineTo(300, i * size); ctx.stroke();
                    }
                    ctx.fillStyle = '#ffb52e';
                    ctx.fillRect(food.x * size + 4, food.y * size + 4, size - 8, size - 8);
                    ctx.fillStyle = '#68c9ef';
                    snake.forEach((part, index) => {
                        ctx.fillStyle = index === 0 ? '#1976d2' : '#50b9e8';
                        ctx.fillRect(part.x * size + 2, part.y * size + 2, size - 4, size - 4);
                        ctx.fillStyle = '#ffffff';
                        if (index === 0) {
                            ctx.fillRect(part.x * size + 6, part.y * size + 6, 3, 3);
                            ctx.fillRect(part.x * size + 13, part.y * size + 6, 3, 3);
                        }
                    });
                }

                function placeFood() {
                    do {
                        food = { x: Math.floor(Math.random() * 15), y: Math.floor(Math.random() * 15) };
                    } while (snake.some((part) => part.x === food.x && part.y === food.y));
                }

                function finish() {
                    clearInterval(timer);
                    running = false;
                    sound.lose();
                    document.getElementById('snakePause').disabled = true;
                    message.textContent = `Game over! You collected ${score} fruit. Press restart to try again.`;
                    saveBest('snake', score);
                    document.getElementById('snakeBest').textContent = bestLabel('snake');
                }

                function tick() {
                    if (!running || paused) return;
                    direction = nextDirection;
                    const head = { x: snake[0].x + direction.x, y: snake[0].y + direction.y };
                    if (head.x < 0 || head.x >= 15 || head.y < 0 || head.y >= 15 || snake.some((part) => part.x === head.x && part.y === head.y)) {
                        finish(); return;
                    }
                    snake.unshift(head);
                    const ateFood = head.x === food.x && head.y === food.y;
                    if (ateFood) { score++; setScore(score); placeFood(); sound.collect(); }
                    else snake.pop();
                    draw();
                }

                function changeDirection(name) {
                    const directions = { up: { x: 0, y: -1 }, down: { x: 0, y: 1 }, left: { x: -1, y: 0 }, right: { x: 1, y: 0 } };
                    const chosen = directions[name];
                    if (chosen && (chosen.x !== -direction.x || chosen.y !== -direction.y)) nextDirection = chosen;
                }

                function reset() {
                    clearInterval(timer);
                    sound.click();
                    snake = [{ x: 7, y: 7 }, { x: 6, y: 7 }, { x: 5, y: 7 }];
                    direction = { x: 1, y: 0 }; nextDirection = { x: 1, y: 0 }; score = 0; setScore(0); placeFood(); draw();
                    running = true; paused = false; message.textContent = 'Collect the fruit and keep moving!'; document.getElementById('snakePause').disabled = false;
                    timer = setInterval(tick, 120);
                }

                const keyHandler = (event) => {
                    const keyMap = { ArrowUp: 'up', w: 'up', W: 'up', ArrowDown: 'down', s: 'down', S: 'down', ArrowLeft: 'left', a: 'left', A: 'left', ArrowRight: 'right', d: 'right', D: 'right' };
                    if (event.key === ' ' && !event.repeat) { event.preventDefault(); reset(); return; }
                    if (keyMap[event.key]) { event.preventDefault(); changeDirection(keyMap[event.key]); }
                };
                document.addEventListener('keydown', keyHandler);
                document.getElementById('snakeStart').addEventListener('click', reset);
                document.getElementById('snakePause').addEventListener('click', () => {
                    paused = !paused;
                    document.getElementById('snakePause').textContent = paused ? '▶ Resume' : 'Ⅱ Pause';
                    message.textContent = paused ? 'Paused — take a tiny breath.' : 'Collect the fruit and keep moving!';
                });
                document.querySelectorAll('[data-snake-dir]').forEach((button) => button.addEventListener('click', () => changeDirection(button.dataset.snakeDir)));
                draw();
                return () => { clearInterval(timer); document.removeEventListener('keydown', keyHandler); };
            }

            function startMemory() {
                const symbols = ['🌊', '⭐', '🌿', '☀️', '🫧', '🐚', '🍀', '💎'];
                const cards = [...symbols, ...symbols].sort(() => Math.random() - 0.5);
                setStage(`
                    <div class="game-score-row"><span>moves: <strong id="gameScore">0</strong></span><span>pairs: <strong id="memoryPairs">0</strong>/8</span><span>best moves: <strong id="memoryBest">${bestLabel('memory')}</strong></span></div>
                    <div class="memory-grid" id="memoryGrid"></div>
                    <p class="game-message" id="memoryMessage">Find the matching bubbles.</p>
                    <div class="game-actions"><button class="aero-button secondary" id="memoryReset" type="button">↻ Shuffle again</button></div>
                `);
                const grid = document.getElementById('memoryGrid');
                const message = document.getElementById('memoryMessage');
                let first = null; let second = null; let lock = false; let moves = 0; let pairs = 0; const timeouts = [];
                cards.forEach((symbol, index) => {
                    const button = document.createElement('button');
                    button.className = 'memory-card';
                    button.type = 'button';
                    button.dataset.symbol = symbol;
                    button.dataset.index = index;
                    button.innerHTML = '<span>?</span>';
                    grid.appendChild(button);
                });
                function flip(button, show) { button.classList.toggle('flipped', show); button.querySelector('span').textContent = show ? button.dataset.symbol : '?'; }
                function choose(button) {
                    if (lock || button.classList.contains('flipped') || button.classList.contains('matched')) return;
                    sound.click();
                    flip(button, true);
                    if (!first) { first = button; return; }
                    second = button; lock = true; moves++; setScore(moves);
                    if (first.dataset.symbol === second.dataset.symbol) {
                        first.classList.add('matched'); second.classList.add('matched'); pairs++; sound.match(); document.getElementById('memoryPairs').textContent = pairs; first = null; second = null; lock = false;
                        if (pairs === 8) {
                            saveBest('memory', moves, 'lower');
                            document.getElementById('memoryBest').textContent = bestLabel('memory');
                            message.textContent = `All pairs found in ${moves} moves! Sparkly work! ✨`;
                            sound.win();
                        }
                    } else {
                        message.textContent = 'Not a match — try another pair.'; sound.miss();
                        timeouts.push(setTimeout(() => { flip(first, false); flip(second, false); first = null; second = null; lock = false; }, 720));
                    }
                }
                grid.addEventListener('click', (event) => { const card = event.target.closest('.memory-card'); if (card) choose(card); });
                document.getElementById('memoryReset').addEventListener('click', () => { activeCleanup(); activeCleanup = startMemory(); });
                return () => { timeouts.forEach(clearTimeout); };
            }

            function startTicTacToe() {
                setStage(`
                    <div class="game-score-row"><span>you: X</span><span>personal wins: <strong id="ticBest">${bestLabel('tictactoe', '0')}</strong></span></div>
                    <div class="tic-board" id="ticBoard" aria-label="Tic tac toe board"></div>
                    <p class="game-message" id="ticMessage">Your turn — choose a square.</p>
                    <div class="game-actions"><button class="aero-button secondary" id="ticReset" type="button">↻ New round</button></div>
                `);
                const board = document.getElementById('ticBoard');
                const message = document.getElementById('ticMessage');
                let cells = Array(9).fill('');
                let over = false; let aiTimer = null;
                const wins = [[0,1,2],[3,4,5],[6,7,8],[0,3,6],[1,4,7],[2,5,8],[0,4,8],[2,4,6]];
                function winner(state) { return wins.find(([a,b,c]) => state[a] && state[a] === state[b] && state[a] === state[c]); }
                function render() {
                    board.innerHTML = '';
                    cells.forEach((value, index) => {
                        const button = document.createElement('button'); button.type = 'button'; button.className = `tic-cell ${value ? 'filled' : ''}`; button.textContent = value; button.dataset.index = index; board.appendChild(button);
                    });
                }
                function endGame(result) {
                    over = true;
                    message.textContent = result === 'draw' ? 'A cloud-covered draw! Play again?' : `${result === 'X' ? 'You win' : 'The cloud bot wins'}!`;
                    if (result === 'X') {
                        const wins = (getBest('tictactoe') || 0) + 1;
                        saveBest('tictactoe', wins);
                        document.getElementById('ticBest').textContent = bestLabel('tictactoe', '0');
                        setScore('★');
                        sound.win();
                    } else if (result === 'O') sound.lose();
                }
                function botMove() {
                    if (over) return;
                    const move = findBestBotMove();
                    if (move !== undefined) { cells[move] = 'O'; sound.click(); }
                    render();
                    const result = winner(cells); if (result) endGame('O'); else if (!cells.includes('')) endGame('draw'); else message.textContent = 'Your turn — choose a square.';
                }
                function minimax(state, depth, maximizing) {
                    const result = winner(state);
                    if (result === 'O') return 10 - depth;
                    if (result === 'X') return depth - 10;
                    if (!state.includes('')) return 0;

                    const empty = state.map((value, index) => value ? null : index).filter((index) => index !== null);
                    const scores = empty.map((index) => {
                        state[index] = maximizing ? 'O' : 'X';
                        const score = minimax(state, depth + 1, !maximizing);
                        state[index] = '';
                        return score;
                    });
                    return maximizing ? Math.max(...scores) : Math.min(...scores);
                }
                function findBestBotMove() {
                    const empty = cells.map((value, index) => value ? null : index).filter((index) => index !== null);
                    let bestScore = -Infinity;
                    let bestMove;
                    empty.forEach((index) => {
                        cells[index] = 'O';
                        const score = minimax(cells, 0, false);
                        cells[index] = '';
                        if (score > bestScore) {
                            bestScore = score;
                            bestMove = index;
                        }
                    });
                    return bestMove;
                }
                function playerMove(index) {
                    if (over || cells[index]) return;
                    cells[index] = 'X'; sound.click(); render();
                    const result = winner(cells); if (result) { endGame('X'); return; }
                    if (!cells.includes('')) { endGame('draw'); return; }
                    message.textContent = 'The cloud is calculating…'; aiTimer = setTimeout(botMove, 420);
                }
                board.addEventListener('click', (event) => { const cell = event.target.closest('.tic-cell'); if (cell) playerMove(Number(cell.dataset.index)); });
                document.getElementById('ticReset').addEventListener('click', () => { activeCleanup(); activeCleanup = startTicTacToe(); });
                render();
                return () => clearTimeout(aiTimer);
            }

            function startPixelMario() {
                setStage(`
                    <div class="game-score-row"><span>coins: <strong id="gameScore">0</strong></span><span>distance: <strong id="marioDistance">0</strong>m</span><span>best coins: <strong id="marioBest">${bestLabel('mario')}</strong></span></div>
                    <div class="mario-field"><canvas id="marioCanvas" width="480" height="220" aria-label="Pixel Mario game board"></canvas></div>
                    <p class="game-message" id="marioMessage">Endless run — jump twice to clear the pipes and grab the coins!</p>
                    <div class="game-actions mario-actions"><button class="aero-button primary" id="marioStart" type="button">▶ Start run</button><button class="aero-button secondary" id="marioJump" type="button">⬆ Jump ×2</button></div>
                `);
                const canvas = document.getElementById('marioCanvas');
                const ctx = canvas.getContext('2d');
                const message = document.getElementById('marioMessage');
                const player = { x: 58, y: 158, width: 22, height: 30, velocity: 0, grounded: true, jumpCount: 0 };
                let obstacles = []; let coins = []; let score = 0; let distance = 0; let running = false; let animation; let last = 0; let spawnClock = 0; let coinClock = 0;

                function drawPixelHero() {
                    ctx.fillStyle = '#e53935'; ctx.fillRect(player.x + 5, player.y, 13, 7); ctx.fillRect(player.x + 2, player.y + 5, 20, 7);
                    ctx.fillStyle = '#f4b183'; ctx.fillRect(player.x + 5, player.y + 12, 13, 8);
                    ctx.fillStyle = '#2d70c9'; ctx.fillRect(player.x + 3, player.y + 20, 17, 8); ctx.fillRect(player.x + 1, player.y + 27, 8, 3); ctx.fillRect(player.x + 15, player.y + 27, 8, 3);
                    ctx.fillStyle = '#4a2d20'; ctx.fillRect(player.x + 18, player.y + 14, 5, 4);
                }
                function draw() {
                    const sky = ctx.createLinearGradient(0, 0, 0, 220); sky.addColorStop(0, '#8de5ff'); sky.addColorStop(1, '#e7ffff'); ctx.fillStyle = sky; ctx.fillRect(0, 0, 480, 220);
                    ctx.fillStyle = 'rgba(255,255,255,.8)'; ctx.fillRect(65, 35, 54, 10); ctx.fillRect(78, 27, 29, 12); ctx.fillRect(315, 58, 72, 10); ctx.fillRect(331, 47, 36, 14);
                    ctx.fillStyle = '#83d386'; ctx.fillRect(0, 188, 480, 32); ctx.fillStyle = '#46a96d'; ctx.fillRect(0, 188, 480, 4);
                    obstacles.forEach((pipe) => { ctx.fillStyle = '#38ad66'; ctx.fillRect(pipe.x, pipe.y, pipe.width, 188 - pipe.y); ctx.fillStyle = '#79df86'; ctx.fillRect(pipe.x - 4, pipe.y, pipe.width + 8, 9); ctx.fillStyle = 'rgba(255,255,255,.4)'; ctx.fillRect(pipe.x + 5, pipe.y + 13, 4, 188 - pipe.y); });
                    coins.forEach((coin) => { ctx.fillStyle = '#ffc62f'; ctx.fillRect(coin.x, coin.y, 12, 16); ctx.fillStyle = '#fff1a8'; ctx.fillRect(coin.x + 3, coin.y + 2, 3, 11); });
                    drawPixelHero();
                }
                function hit(a, b) { return a.x < b.x + b.width && a.x + a.width > b.x && a.y < b.y + b.height && a.y + a.height > b.y; }
                function jump() { if (!running || player.jumpCount >= 2) return; player.velocity = -10.5; player.grounded = false; player.jumpCount++; sound.jump(); }
                function finish() { running = false; cancelAnimationFrame(animation); saveBest('mario', score); document.getElementById('marioBest').textContent = bestLabel('mario'); message.textContent = `Bonk! You reached ${Math.floor(distance)}m and collected ${score} coin${score === 1 ? '' : 's'}. Press restart to try again.`; sound.lose(); }
                function loop(time) {
                    if (!running) return;
                    const delta = Math.min((time - last) / 16.67 || 1, 2); last = time; distance += .12 * delta;
                    document.getElementById('marioDistance').textContent = Math.floor(distance);
                    player.velocity += .6 * delta; player.y += player.velocity * delta;
                    if (player.y + player.height >= 188) { player.y = 188 - player.height; player.velocity = 0; player.grounded = true; player.jumpCount = 0; }
                    spawnClock += delta; coinClock += delta;
                    if (spawnClock > 95) { spawnClock = 0; const height = 25 + Math.random() * 28; obstacles.push({ x: 490, y: 188 - height, width: 23, height }); }
                    if (coinClock > 50) { coinClock = 0; coins.push({ x: 490, y: 105 + Math.random() * 55, width: 12, height: 16 }); }
                    obstacles.forEach((pipe) => pipe.x -= 4.2 * delta); coins.forEach((coin) => coin.x -= 4.2 * delta);
                    obstacles = obstacles.filter((pipe) => pipe.x > -40); coins = coins.filter((coin) => { if (hit(player, coin)) { score++; setScore(score); sound.collect(); return false; } return coin.x > -30; });
                    if (obstacles.some((pipe) => hit(player, pipe))) { finish(); return; }
                    draw(); animation = requestAnimationFrame(loop);
                }
                function start() { cancelAnimationFrame(animation); sound.click(); score = 0; distance = 0; setScore(0); document.getElementById('marioDistance').textContent = '0'; obstacles = []; coins = []; player.y = 158; player.velocity = 0; player.grounded = true; player.jumpCount = 0; running = true; last = 0; spawnClock = 0; coinClock = 0; message.textContent = 'Endless run — keep jumping until you hit a pipe!'; document.getElementById('marioStart').textContent = '↻ Restart'; draw(); animation = requestAnimationFrame(loop); }
                const keyHandler = (event) => {
                    if (event.key === ' ') {
                        event.preventDefault();
                        if (!running && !event.repeat) start();
                        else if (running) jump();
                        return;
                    }
                    if (event.key === 'ArrowUp' || event.key.toLowerCase() === 'w') { event.preventDefault(); jump(); }
                };
                document.addEventListener('keydown', keyHandler); document.getElementById('marioStart').addEventListener('click', start); document.getElementById('marioJump').addEventListener('click', jump);
                draw();
                return () => { cancelAnimationFrame(animation); document.removeEventListener('keydown', keyHandler); };
            }

            function startAquaInvaders() {
                setStage(`
                    <div class="game-score-row"><span>level: <strong id="invaderLevel">1</strong>/10</span><span>hits: <strong id="gameScore">0</strong></span><span>invaders left: <strong id="invaderCount">6</strong></span><span>best hits: <strong id="invaderBest">${bestLabel('invaders')}</strong></span><span>highest level: <strong id="invaderBestLevel">${bestLabel('invaders_level', '0')}</strong>/10</span></div>
                    <div class="invaders-field"><canvas id="invadersCanvas" width="420" height="260" aria-label="Aqua Invaders game board"></canvas></div>
                    <p class="game-message" id="invadersMessage">Clear the sky before the invaders reach your ship.</p>
                    <div class="game-actions invaders-actions"><button class="aero-button secondary" type="button" data-invader-dir="left">◀</button><button class="aero-button primary" id="invadersStart" type="button">▶ Start</button><button class="aero-button secondary" type="button" data-invader-fire="1">● Fire</button><button class="aero-button secondary" type="button" data-invader-dir="right">▶</button></div>
                `);
                const canvas = document.getElementById('invadersCanvas'); const ctx = canvas.getContext('2d'); const message = document.getElementById('invadersMessage'); const ship = { x: 195, y: 232, width: 30, height: 16 }; let aliens = []; let bullets = []; let score = 0; let level = 1; let highestLevelPassed = 0; let running = false; let animation; let last = 0; let alienDirection = 1; let moveClock = 0; let fireClock = 0;
                const invaderDifficulty = [
                    { moveInterval: 31, moveStep: 5.8, edgeDrop: 8 },
                    { moveInterval: 29, moveStep: 6.5, edgeDrop: 8 },
                    { moveInterval: 27, moveStep: 7.2, edgeDrop: 9 },
                    { moveInterval: 25, moveStep: 7.9, edgeDrop: 9 },
                    { moveInterval: 23, moveStep: 8.6, edgeDrop: 10 },
                    { moveInterval: 21, moveStep: 9.3, edgeDrop: 10 },
                    { moveInterval: 19, moveStep: 10.0, edgeDrop: 11 },
                    { moveInterval: 18, moveStep: 10.7, edgeDrop: 11 },
                    { moveInterval: 17, moveStep: 11.4, edgeDrop: 12 },
                    { moveInterval: 16, moveStep: 12.0, edgeDrop: 12 }
                ];
                function setupAliens() {
                    aliens = [];
                    const waveLayouts = [
                        { rows: 2, columns: 3 },
                        { rows: 2, columns: 4 },
                        { rows: 2, columns: 5 },
                        { rows: 2, columns: 6 },
                        { rows: 2, columns: 7 },
                        { rows: 2, columns: 8 },
                        { rows: 2, columns: 9 },
                        { rows: 2, columns: 10 },
                        { rows: 2, columns: 11 },
                        { rows: 3, columns: 8 }
                    ];
                    const { rows, columns } = waveLayouts[level - 1];
                    const spacing = columns <= 4 ? 78 : columns === 5 ? 66 : columns === 6 ? 58 : columns === 7 ? 50 : columns === 8 ? 43 : columns === 9 ? 38 : columns === 10 ? 34 : 30;
                    const startX = (420 - ((columns - 1) * spacing + 25)) / 2;
                    for (let row = 0; row < rows; row++) {
                        for (let column = 0; column < columns; column++) {
                            aliens.push({ x: startX + column * spacing, y: 38 + row * 30, width: 25, height: 17, row });
                        }
                    }
                }
                function draw() {
                    const sky = ctx.createLinearGradient(0, 0, 0, 260); sky.addColorStop(0, '#173b75'); sky.addColorStop(1, '#47b8d0'); ctx.fillStyle = sky; ctx.fillRect(0, 0, 420, 260);
                    ctx.fillStyle = 'rgba(255,255,255,.7)'; [[28,30],[350,24],[280,105],[100,145]].forEach(([x,y]) => { ctx.fillRect(x, y, 2, 2); ctx.fillRect(x + 6, y + 3, 2, 2); });
                    aliens.forEach((alien) => { ctx.fillStyle = alien.row === 0 ? '#ff80d4' : alien.row === 1 ? '#8eea9b' : '#ffc95c'; ctx.fillRect(alien.x + 4, alien.y, 17, 4); ctx.fillRect(alien.x, alien.y + 4, 25, 9); ctx.fillRect(alien.x + 4, alien.y + 13, 5, 4); ctx.fillRect(alien.x + 16, alien.y + 13, 5, 4); ctx.fillStyle = '#254d91'; ctx.fillRect(alien.x + 6, alien.y + 6, 3, 3); ctx.fillRect(alien.x + 16, alien.y + 6, 3, 3); });
                    bullets.forEach((bullet) => { ctx.fillStyle = bullet.enemy ? '#ffb52e' : '#eaffff'; ctx.fillRect(bullet.x, bullet.y, 3, 9); });
                    ctx.fillStyle = '#d8ffff'; ctx.fillRect(ship.x + 12, ship.y, 7, 4); ctx.fillRect(ship.x + 5, ship.y + 4, 21, 7); ctx.fillRect(ship.x, ship.y + 11, 30, 5); ctx.fillStyle = '#7af4e0'; ctx.fillRect(ship.x + 13, ship.y + 4, 5, 4);
                }
                function fire() { if (!running || fireClock > 0) return; bullets.push({ x: ship.x + 14, y: ship.y - 8, width: 3, height: 9 }); fireClock = 10; sound.shoot(); }
                function move(amount) { if (!running) return; ship.x = Math.max(5, Math.min(385, ship.x + amount)); }
                function finish(won) { running = false; cancelAnimationFrame(animation); saveBest('invaders', score); saveBest('invaders_level', highestLevelPassed); document.getElementById('invaderBest').textContent = bestLabel('invaders'); document.getElementById('invaderBestLevel').textContent = bestLabel('invaders_level', '0'); message.textContent = won ? 'The sky is clear! You saved the aqua world. ✨' : `The invaders got too close on level ${level}. Press restart to defend the sky.`; won ? sound.win() : sound.lose(); }
                function loop(time) {
                    if (!running) return;
                    const delta = Math.min((time - last) / 16.67 || 1, 2); last = time; moveClock += delta; fireClock = Math.max(0, fireClock - delta);
                    const { moveInterval, moveStep, edgeDrop } = invaderDifficulty[level - 1];
                    if (moveClock > moveInterval) { moveClock = 0; const edge = aliens.some((alien) => alien.x < 12 || alien.x > 383); if (edge) { alienDirection *= -1; aliens.forEach((alien) => alien.y += edgeDrop); } else aliens.forEach((alien) => alien.x += alienDirection * moveStep); }
                    bullets.forEach((bullet) => { bullet.y += bullet.enemy ? 2.2 * delta : -6 * delta; });
                    bullets = bullets.filter((bullet) => bullet.y > -15 && bullet.y < 270);
                    bullets.filter((bullet) => !bullet.enemy).forEach((bullet) => { const target = aliens.find((alien) => hit(bullet, alien)); if (target) { aliens = aliens.filter((alien) => alien !== target); bullet.y = -30; score++; setScore(score); sound.hit(); } });
                    if (aliens.some((alien) => alien.y + alien.height > 220)) { finish(false); return; }
                    document.getElementById('invaderCount').textContent = aliens.length;
                    if (!aliens.length) {
                        if (level >= 10) { highestLevelPassed = 10; finish(true); return; }
                        highestLevelPassed = Math.max(highestLevelPassed, level);
                        saveBest('invaders_level', highestLevelPassed);
                        document.getElementById('invaderBestLevel').textContent = bestLabel('invaders_level', '0');
                        level++;
                        setupAliens();
                        alienDirection = level % 2 === 0 ? -1 : 1;
                        moveClock = 0;
                        document.getElementById('invaderLevel').textContent = level;
                        document.getElementById('invaderCount').textContent = aliens.length;
                        message.textContent = `Level ${level}! The aqua invaders are getting faster.`;
                        sound.match();
                    }
                    draw(); animation = requestAnimationFrame(loop);
                }
                function hit(a, b) { return a.x < b.x + b.width && a.x + a.width > b.x && a.y < b.y + b.height && a.y + a.height > b.y; }
                function start() { cancelAnimationFrame(animation); sound.click(); level = 1; highestLevelPassed = 0; setupAliens(); bullets = []; score = 0; setScore(0); document.getElementById('invaderLevel').textContent = level; document.getElementById('invaderCount').textContent = aliens.length; ship.x = 195; alienDirection = 1; moveClock = 0; fireClock = 0; running = true; last = 0; message.textContent = 'Level 1 of 10 — 6 invaders incoming!'; document.getElementById('invadersStart').textContent = '↻ Restart'; draw(); animation = requestAnimationFrame(loop); }
                const keyHandler = (event) => {
                    if (event.key === ' ') {
                        event.preventDefault();
                        if (!running && !event.repeat) start();
                        else if (running) fire();
                        return;
                    }
                    if (event.key === 'ArrowLeft' || event.key.toLowerCase() === 'a') { event.preventDefault(); move(-12); }
                    if (event.key === 'ArrowRight' || event.key.toLowerCase() === 'd') { event.preventDefault(); move(12); }
                };
                document.addEventListener('keydown', keyHandler); document.getElementById('invadersStart').addEventListener('click', start); document.querySelectorAll('[data-invader-dir]').forEach((button) => button.addEventListener('click', () => move(button.dataset.invaderDir === 'left' ? -18 : 18))); document.querySelector('[data-invader-fire]').addEventListener('click', fire);
                setupAliens(); draw();
                return () => { cancelAnimationFrame(animation); document.removeEventListener('keydown', keyHandler); };
            }

            function startCloudFlyer() {
                setStage(`
                    <div class="game-score-row"><span>stars: <strong id="gameScore">0</strong></span><span>best flight: <strong id="birdBest">${bestLabel('bird')}</strong></span></div>
                    <div class="bird-field"><canvas id="birdCanvas" width="420" height="260" aria-label="Flappy Bird game board"></canvas></div>
                    <p class="game-message" id="birdMessage">Press start, then flap through the cloud gates.</p>
                    <div class="game-actions"><button class="aero-button primary" id="birdStart" type="button">▶ Start flying</button><button class="aero-button secondary" id="birdFlap" type="button">⬆ Flap</button></div>
                `);
                const canvas = document.getElementById('birdCanvas');
                const ctx = canvas.getContext('2d');
                const message = document.getElementById('birdMessage');
                const bird = { x: 88, y: 120, width: 23, height: 18, velocity: 0 };
                let gates = [];
                let stars = [];
                let score = 0;
                let running = false;
                let animation = null;
                let last = 0;
                let spawnClock = 0;
                let hasFlapped = false;

                function draw() {
                    const sky = ctx.createLinearGradient(0, 0, 0, 260);
                    sky.addColorStop(0, '#72c9f0');
                    sky.addColorStop(1, '#e7fbff');
                    ctx.fillStyle = sky;
                    ctx.fillRect(0, 0, 420, 260);
                    ctx.fillStyle = 'rgba(255,255,255,.82)';
                    [[35, 45, 58], [250, 28, 70], [310, 105, 48]].forEach(([x, y, width]) => {
                        ctx.fillRect(x, y, width, 9);
                        ctx.fillRect(x + 12, y - 8, width - 22, 9);
                    });
                    ctx.fillStyle = '#7ecb8c';
                    ctx.fillRect(0, 242, 420, 18);
                    ctx.fillStyle = '#4fae75';
                    ctx.fillRect(0, 242, 420, 4);
                    gates.forEach((gate) => {
                        ctx.fillStyle = '#46aa79';
                        ctx.fillRect(gate.x, 0, gate.width, gate.gapTop);
                        ctx.fillRect(gate.x, gate.gapBottom, gate.width, 242 - gate.gapBottom);
                        ctx.fillStyle = '#8ce09b';
                        ctx.fillRect(gate.x - 4, gate.gapTop - 9, gate.width + 8, 9);
                        ctx.fillRect(gate.x - 4, gate.gapBottom, gate.width + 8, 9);
                        ctx.fillStyle = 'rgba(255,255,255,.42)';
                        ctx.fillRect(gate.x + 8, 8, 4, Math.max(0, gate.gapTop - 18));
                        ctx.fillRect(gate.x + 8, gate.gapBottom + 17, 4, Math.max(0, 242 - gate.gapBottom - 25));
                    });
                    stars.forEach((star) => {
                        ctx.fillStyle = '#ffd447';
                        ctx.beginPath();
                        for (let point = 0; point < 10; point++) {
                            const radius = point % 2 === 0 ? 8 : 3;
                            const angle = -Math.PI / 2 + point * Math.PI / 5;
                            const x = star.x + Math.cos(angle) * radius;
                            const y = star.y + Math.sin(angle) * radius;
                            point === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
                        }
                        ctx.closePath();
                        ctx.fill();
                    });
                    ctx.fillStyle = '#ffd447';
                    ctx.fillRect(bird.x + 5, bird.y + 2, 17, 11);
                    ctx.fillStyle = '#fff4a8';
                    ctx.fillRect(bird.x + 8, bird.y + 5, 6, 5);
                    ctx.fillStyle = '#f29d45';
                    ctx.fillRect(bird.x + 21, bird.y + 7, 8, 5);
                    ctx.fillStyle = '#1b5884';
                    ctx.fillRect(bird.x + 16, bird.y + 4, 3, 3);
                    ctx.fillStyle = '#eaa63d';
                    ctx.fillRect(bird.x + 2, bird.y + 13, 7, 4);
                }

                function overlaps(first, second) {
                    return first.x < second.x + second.width && first.x + first.width > second.x
                        && first.y < second.y + second.height && first.y + first.height > second.y;
                }

                function flap() {
                    if (!running) return;
                    hasFlapped = true;
                    bird.velocity = -5.5;
                    sound.jump();
                }

                function finish() {
                    running = false;
                    cancelAnimationFrame(animation);
                    saveBest('bird', score);
                    document.getElementById('birdBest').textContent = bestLabel('bird');
                    message.textContent = `Flight over! You collected ${score} star${score === 1 ? '' : 's'}. Press restart to try again.`;
                    sound.lose();
                }

                function loop(time) {
                    if (!running) return;
                    const delta = Math.min((time - last) / 16.67 || 1, 2);
                    last = time;
                    if (hasFlapped) {
                        bird.velocity += .28 * delta;
                        bird.y += bird.velocity * delta;
                    } else {
                        bird.velocity = 0;
                        bird.y = 120;
                    }
                    spawnClock += delta;
                    if (spawnClock > 130) {
                        spawnClock = 0;
                        const gapTop = 35 + Math.random() * 85;
                        const gapHeight = 112;
                        gates.push({ x: 430, width: 35, gapTop, gapBottom: gapTop + gapHeight, passed: false });
                        stars.push({ x: 448, y: gapTop + gapHeight / 2, width: 15, height: 15, collected: false });
                    }
                    gates.forEach((gate) => {
                        gate.x -= 1.4 * delta;
                        if (!gate.passed && gate.x + gate.width < bird.x) {
                            gate.passed = true;
                        }
                    });
                    stars.forEach((star) => { star.x -= 1.4 * delta; });
                    gates = gates.filter((gate) => gate.x > -50);
                    stars = stars.filter((star) => {
                        if (!star.collected && overlaps(bird, star)) {
                            star.collected = true;
                            score++;
                            setScore(score);
                            sound.collect();
                            return false;
                        }
                        return star.x > -30;
                    });
                    const hitGate = gates.some((gate) => overlaps(bird, { x: gate.x, y: 0, width: gate.width, height: gate.gapTop })
                        || overlaps(bird, { x: gate.x, y: gate.gapBottom, width: gate.width, height: 242 - gate.gapBottom }));
                    if (bird.y < 0 || bird.y + bird.height > 242 || hitGate) {
                        finish();
                        return;
                    }
                    draw();
                    animation = requestAnimationFrame(loop);
                }

                function start() {
                    cancelAnimationFrame(animation);
                    sound.click();
                    bird.y = 120;
                    bird.velocity = 0;
                    gates = [];
                    stars = [];
                    score = 0;
                    setScore(0);
                    running = true;
                    last = 0;
                    spawnClock = 0;
                    hasFlapped = false;
                    message.textContent = 'The bird is cruising — press Space or Flap when you are ready.';
                    document.getElementById('birdStart').textContent = '↻ Restart';
                    draw();
                    animation = requestAnimationFrame(loop);
                }

                const keyHandler = (event) => {
                    if (event.key === ' ') {
                        event.preventDefault();
                        if (!running && !event.repeat) start();
                        else if (running) flap();
                        return;
                    }
                    if (event.key === 'ArrowUp' || event.key.toLowerCase() === 'w') { event.preventDefault(); flap(); }
                };
                document.addEventListener('keydown', keyHandler);
                document.getElementById('birdStart').addEventListener('click', start);
                document.getElementById('birdFlap').addEventListener('click', flap);
                canvas.addEventListener('pointerdown', flap);
                draw();
                return () => { cancelAnimationFrame(animation); document.removeEventListener('keydown', keyHandler); };
            }

            document.querySelectorAll('.play-game').forEach((button) => button.addEventListener('click', () => openGame(button.dataset.game)));
            document.getElementById('openAuth')?.addEventListener('click', () => {
                authPlayGame.value = '';
                authModal.classList.add('open');
                authModal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('modal-open');
                document.getElementById('authUsername').focus();
            });
            document.querySelectorAll('[data-auth-mode]').forEach((button) => button.addEventListener('click', () => {
                const mode = button.dataset.authMode;
                authAction.value = mode;
                document.querySelectorAll('[data-auth-mode]').forEach((tab) => tab.classList.toggle('active', tab === button));
                authForm.classList.toggle('is-register', mode === 'register');
                authForm.querySelector('.auth-submit').textContent = mode === 'register' ? 'Create account & play' : 'Sign in & play';
                document.getElementById('authPassword').autocomplete = mode === 'register' ? 'new-password' : 'current-password';
            }));
            gamesLogoutLink?.addEventListener('click', openLogoutAlert);
            confirmGamesLogout.addEventListener('click', () => {
                if (logoutUrl) window.location.href = logoutUrl;
            });
            cancelGamesLogout.addEventListener('click', closeLogoutAlert);
            closeGamesLogout.addEventListener('click', closeLogoutAlert);
            gamesLogoutModal.addEventListener('click', (event) => {
                if (event.target === gamesLogoutModal) closeLogoutAlert();
            });
            document.getElementById('closeAuth').addEventListener('click', closeAuth);
            authModal.addEventListener('click', (event) => { if (event.target === authModal) closeAuth(); });
            closeButton.addEventListener('click', closeGame);
            modal.addEventListener('click', (event) => { if (event.target === modal) closeGame(); });
            soundToggle.addEventListener('click', () => { sound.muted = !sound.muted; localStorage.setItem('itHubGameSoundMuted', sound.muted ? '1' : '0'); updateSoundButton(); });
            updateSoundButton();
            if (window.authOpen) {
                document.body.classList.add('modal-open');
                requestAnimationFrame(() => document.getElementById('authUsername').focus());
            }
            document.addEventListener('keydown', (event) => {
                if (event.key !== 'Escape') return;
                if (gamesLogoutModal.classList.contains('open')) closeLogoutAlert();
                else if (activeGame) closeGame();
                else if (authModal.classList.contains('open')) closeAuth();
            });
            if (window.gamesLoggedIn && window.requestedGame) openGame(window.requestedGame);
        })();
    </script>
</body>
</html>
