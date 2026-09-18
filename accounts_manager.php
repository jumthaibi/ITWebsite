<?php
require_once 'db.php';
require_once 'auth.php';

// The accounts screen is an admin-only view. Passwords are never displayed
// or stored in recoverable form.
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $stmt = $pdo->prepare('SELECT id, username, password, is_admin FROM users WHERE username = ? AND is_admin = 1 LIMIT 1');
    $stmt->execute([$username]);
    $manager_row = $stmt->fetch();
    $manager_authenticated = $manager_row && password_verify($password, (string) $manager_row['password']);

    if ($manager_authenticated) {
        sign_in_user($manager_row);
        $_SESSION['accounts_manager_logged'] = true;
        header("Location: accounts_manager.php");
        exit();
    } else {
        $login_error = "Invalid username or password for Accounts Manager.";
    }
}

// Check if logged in as Accounts Manager
$is_logged_in = current_user_is_admin();

// Handle Account Actions (Add Admin id=1, Add User id=0, Edit Accounts, Edit Own Profile)
$success_msg = '';
$error_msg = '';

if ($is_logged_in && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_account') {
        $new_user = trim($_POST['new_username'] ?? '');
        $new_pass = trim($_POST['new_password'] ?? '');
        // Manager can only create ADMIN accounts here. Regular users register
        // themselves on the site — the manager never provisions them.
        $account_type = 1;

        if (valid_username($new_user) && strlen($new_pass) >= 8) {
            $hashed_pass = password_hash($new_pass, PASSWORD_DEFAULT);
            try {
                $stmt = $pdo->prepare("INSERT INTO users (username, password, is_admin) VALUES (?, ?, ?)");
                $stmt->execute([$new_user, $hashed_pass, $account_type]);
                $success_msg = "Account successfully created!";
            } catch (PDOException $e) {
                $error_msg = "That account could not be created. The username may already be in use.";
            }
        } else {
            $error_msg = "Use a valid username and a password of at least 8 characters.";
        }
    }

    elseif ($action === 'edit_account') {
        $edit_id = intval($_POST['edit_id']);
        $edit_user = trim($_POST['edit_username'] ?? '');
        // Roles are FIXED: every account in this list is an admin (manager is id 2).
        // No demotion to regular user — removal of users happens in its own section.
        $edit_type = 1;
        $edit_pass = trim($_POST['edit_password'] ?? '');

        if (!valid_username($edit_user)) {
            $error_msg = "Use 3–30 letters, numbers, dots, dashes, or underscores.";
        } else try {
            if (!empty($edit_pass)) {
                $hashed_pass = password_hash($edit_pass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET username = ?, password = ?, is_admin = ? WHERE id = ?");
                $stmt->execute([$edit_user, $hashed_pass, $edit_type, $edit_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET username = ?, is_admin = ? WHERE id = ?");
                $stmt->execute([$edit_user, $edit_type, $edit_id]);
            }
            $success_msg = "Account updated successfully!";
        } catch (PDOException $e) {
            $error_msg = "That account could not be updated. The username may already be in use.";
        }
    }

    elseif ($action === 'delete_account') {
        $delete_id = intval($_POST['delete_id'] ?? 0);

        if ($delete_id === current_user_id()) {
            $error_msg = "You cannot delete the account you are currently using.";
        } elseif ($delete_id > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$delete_id]);
                $success_msg = "Account deleted successfully!";
            } catch (PDOException $e) {
                $error_msg = "That account could not be deleted.";
            }
        } else {
            $error_msg = "Invalid account selected for deletion.";
        }
    }

    elseif ($action === 'update_profile') {
        $profile_user = trim($_POST['profile_username'] ?? '');
        $profile_pass = trim($_POST['profile_password'] ?? '');

        if (valid_username($profile_user)) {
            try {
                if (!empty($profile_pass)) {
                    $hashed_pass = password_hash($profile_pass, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET username = ?, password = ? WHERE id = ?");
                    $stmt->execute([$profile_user, $hashed_pass, current_user_id()]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET username = ? WHERE id = ?");
                    $stmt->execute([$profile_user, current_user_id()]);
                }
                $_SESSION['username'] = $profile_user;
                $success_msg = "Your manager profile was updated successfully!";
            } catch (PDOException $e) {
                    $error_msg = "That profile could not be updated. The username may already be in use.";
            }
        } else {
            $error_msg = "Use 3–30 letters, numbers, dots, dashes, or underscores.";
        }
    }
}

// Fetch all accounts and manager's own info if logged in
$accounts = [];
$regular_users = [];
$manager_info = null;
if ($is_logged_in) {
    try {
        $stmt = $pdo->query("SELECT id, username, is_admin, bio, created_at FROM users WHERE is_admin = 1 ORDER BY id ASC");
        $accounts = $stmt->fetchAll();

        // Regular (non-admin) accounts — listed ONLY in the Remove Users section.
        $stmt_users = $pdo->query("SELECT id, username FROM users WHERE is_admin = 0 ORDER BY username ASC");
        $regular_users = $stmt_users->fetchAll();

        $stmt_mgr = $pdo->prepare("SELECT id, username, is_admin, bio, created_at FROM users WHERE id = ? LIMIT 1");
        $stmt_mgr->execute([current_user_id()]);
        $manager_info = $stmt_mgr->fetch();
    } catch (PDOException $e) {
        $accounts = [];
        $regular_users = [];
    }
}

$active_tab = $_GET['tab'] ?? 'dashboard';
$allowed_tabs = ['dashboard', 'my_profile', 'add_account', 'manage_accounts', 'remove_users'];
if (!in_array($active_tab, $allowed_tabs, true)) {
    $active_tab = 'dashboard';
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounts Manager | Frutiger Aero Edition</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Varela+Round&display=swap" rel="stylesheet">
    <style>
        .admin-window .admin-body > :not(.auth-modal) .form-group { margin-bottom: 12px; }
        .admin-window .admin-body > :not(.auth-modal) label { display: block; margin-bottom: 4px; font-weight: bold; color: #0b3d63; font-size: 0.85rem; }
        .admin-window .admin-body > :not(.auth-modal) input[type="text"], .admin-window .admin-body > :not(.auth-modal) input[type="password"], .admin-window .admin-body > :not(.auth-modal) textarea, .admin-window .admin-body > :not(.auth-modal) select { width: 100%; padding: 8px 10px; border: 1px solid #8ab8e6; border-radius: 6px; box-sizing: border-box; font-family: 'Varela Round', sans-serif; font-size: 0.85rem; background: #ffffff; color: #333; }
        .alert-success { background: rgba(76, 175, 80, 0.2); color: #2e7d32; padding: 10px; margin-bottom: 12px; border-radius: 6px; border: 1px solid rgba(76, 175, 80, 0.4); font-size: 0.85rem; }
        .alert-danger { background: rgba(229, 57, 53, 0.2); color: #c62828; padding: 10px; margin-bottom: 12px; border-radius: 6px; border: 1px solid rgba(229, 57, 53, 0.4); font-size: 0.85rem; }
        .admin-window table { width: 100%; border-collapse: collapse; margin-top: 6px; margin-bottom: 15px; font-size: 0.82rem; }
        .admin-window table, .admin-window th, .admin-window td { border: 1px solid #b3d7ff; padding: 8px; text-align: left; }
        .admin-window th { background: #e2f0ff; color: #0b3d63; }
        .admin-window td { background: rgba(255,255,255,0.6); color: #37474f; }
        .admin-section { margin-top: 0; }
        .browser-tabs { flex-wrap: wrap; overflow-x: visible; overflow-y: visible; }
        .browser-tabs .tab { white-space: nowrap; flex-shrink: 0; }
        .password-pill { background: #e2f0ff; border: 1px solid #b3d7ff; border-radius: 4px; padding: 2px 6px; font-family: monospace; font-size: 0.8rem; color: #0b3d63; display: inline-block; }
        .actions-cell { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .actions-cell > * { min-width: 0; }

        /* ---- Mobile suitability: stack tables into cards, show all tabs ---- */
        @media (max-width: 700px) {
            .browser-tabs {
                flex-wrap: wrap;
                justify-content: center;
            }
            .browser-tabs .tab {
                flex: 1 1 auto;
                text-align: center;
                font-size: 0.72rem;
                padding: 6px 4px;
                white-space: nowrap;
            }
            .browser-tabs .tab a { display: block; }
            .admin-window table, .admin-window thead, .admin-window tbody, .admin-window tr, .admin-window th, .admin-window td { display: block; width: 100%; box-sizing: border-box; }
            .admin-window thead { display: none; }
            .admin-window tr { margin-bottom: 10px; border: 1px solid #b3d7ff; border-radius: 10px; overflow: hidden; background: rgba(255,255,255,0.75); }
            .admin-window td { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 6px; border-bottom: 1px dashed #d5e8fb; }
            .admin-window td:last-child { border-bottom: none; }
            .admin-window td::before { content: attr(data-label); font-weight: bold; color: #0b3d63; flex-shrink: 0; }
            .admin-window td input[type="text"] { flex: 1 1 auto; width: auto; min-width: 0; }
            .admin-window td.actions-cell::before { width: 100%; }
            .window-body.admin-body { padding: 10px !important; }
            .content-box.admin-welcome-card { flex-direction: column !important; align-items: stretch !important; gap: 8px; text-align: center; }
            .content-box.admin-welcome-card .admin-welcome-actions { display: flex; justify-content: center; gap: 8px; }
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
                    <p class="auth-description" id="aero-alert-msg">Are you sure you want to proceed with this modification?</p>
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
                <span class="os-icon">🔐</span> Accounts Manager - Standalone Portal
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
                <span>🔒 http://it-students-hub.io/accounts_manager.php</span>
            </div>
            <div class="browser-tools">
                <button class="tool-btn">🔍</button>
            </div>
        </div>

        <!-- Browser Tabs -->
        <div class="browser-tabs">
            <?php if ($is_logged_in): ?>
                <div class="tab <?= ($active_tab == 'dashboard') ? 'active' : '' ?>"><a href="accounts_manager.php?tab=dashboard" style="text-decoration:none; color:inherit;">🏠 dashboard overview</a></div>
                <div class="tab <?= ($active_tab == 'my_profile') ? 'active' : '' ?>"><a href="accounts_manager.php?tab=my_profile" style="text-decoration:none; color:inherit;">👤 my profile</a></div>
                <div class="tab <?= ($active_tab == 'add_account') ? 'active' : '' ?>"><a href="accounts_manager.php?tab=add_account" style="text-decoration:none; color:inherit;">➕ provision account</a></div>
                <div class="tab <?= ($active_tab == 'manage_accounts') ? 'active' : '' ?>"><a href="accounts_manager.php?tab=manage_accounts" style="text-decoration:none; color:inherit;">📋 manager & admins</a></div>
                <div class="tab <?= ($active_tab == 'remove_users') ? 'active' : '' ?>"><a href="accounts_manager.php?tab=remove_users" style="text-decoration:none; color:inherit;">🗑 remove users</a></div>
            <?php else: ?>
                <div class="tab active"><a href="accounts_manager.php" style="text-decoration:none; color:inherit;">🔐 manager login</a></div>
            <?php endif; ?>
        </div>

        <!-- Main Window Content -->
        <div class="window-body admin-body" style="padding: 14px; gap: 14px; flex-direction: column; align-items: stretch; display: flex;">
            
            <?php if (!$is_logged_in): ?>
                <!-- Match the chat page's sign-in dialog exactly. -->
                <div class="auth-modal open" id="adminAuthModal" aria-hidden="false">
                    <div class="os-window auth-dialog-window chat-simple-dialog" role="dialog" aria-modal="true" aria-labelledby="adminAuthTitle">
                        <div class="window-header">
                            <div class="window-title"><span class="os-icon">🔐</span> Accounts Manager Portal</div>
                            <div class="window-controls">
                                <span class="control-btn minimize"></span>
                                <span class="control-btn maximize"></span>
                                <span class="control-btn close"></span>
                            </div>
                        </div>
                        <div class="browser-toolbar"><div class="address-bar"><span>Manager Authentication</span></div></div>
                        <div class="window-body auth-dialog-body">
                            <div class="content-box auth-panel">
                                <span class="card-header-tag">RESTRICTED ISOLATED PORTAL</span>
                                <h2 id="adminAuthTitle">Authenticate as Manager 🔐</h2>
                                <p class="auth-description">Log in to manage credentials completely segregated from regular admin pages.</p>
                                <?php if (!empty($login_error)): ?>
                                    <div class="auth-error" role="alert">⚠️ <?= htmlspecialchars($login_error) ?></div>
                                <?php endif; ?>
                                <form class="auth-form" method="POST" action="accounts_manager.php">
                                    <input type="hidden" name="login" value="1">
                                    <label for="adminAuthUsername">Username</label>
                                    <input id="adminAuthUsername" name="username" type="text" autocomplete="username" placeholder="Username" required>
                                    <label for="adminAuthPassword">Password</label>
                                    <input id="adminAuthPassword" name="password" type="password" autocomplete="current-password" placeholder="Password" required>
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
                        <h2 style="font-size: 1.1rem; color: #01579b; margin-bottom: 2px;">Welcome, manager ✨</h2>
                        <p style="font-size: 0.8rem; color: #546e7a; margin: 0;">Total Control over User & Admin credentials in a standalone portal.</p>
                    </div>
                    <div class="admin-welcome-actions">
                        <a href="index.php" class="aero-button secondary" style="text-decoration: none; padding: 6px 12px; font-size: 0.8rem; display: inline-block; margin-right: 8px;">🏠 Home</a>
                        <a href="logout.php?next=accounts_manager.php" id="logout-trigger" class="aero-button" style="background: linear-gradient(to bottom, #ffcdd2, #e57373); color: #b71c1c; border-color: #e53935; text-decoration: none; padding: 6px 12px; font-size: 0.8rem; display: inline-block;">Log Out Manager</a>
                    </div>
                </div>

                <?php if (!empty($success_msg)): ?>
                    <div class="alert-success" style="flex-shrink: 0;"><?= htmlspecialchars($success_msg) ?></div>
                <?php endif; ?>
                <?php if (!empty($error_msg)): ?>
                    <div class="alert-danger" style="flex-shrink: 0;"><?= htmlspecialchars($error_msg) ?></div>
                <?php endif; ?>

                <?php if ($active_tab == 'dashboard'): ?>
                    <div class="content-box admin-section" style="margin: 0;">
                        <h3 style="color: #01579b; font-size: 1rem; margin-bottom: 10px;">📊 Standalone Accounts Command Center</h3>
                        <p style="font-size: 0.85rem; color: #37474f; line-height: 1.5;">
                            This standalone portal is completely isolated from standard admin management views. Use the tabs above to navigate:
                        </p>
                        <ul class="admin-overview-points" style="font-size: 0.85rem; color: #37474f; margin-top: 10px; line-height: 1.6;">
                            <li><strong>My Profile:</strong> Review your manager account details and update your username or password.</li>
                            <li><strong>Provision Account:</strong> Create new administrator accounts only (roles are fixed — regular users register themselves).</li>
                            <li><strong>Manager & Admins:</strong> Review admin accounts only (regular users stay private), rename them, and reset passwords on the fly. Roles are fixed and never change here.</li>
                            <li><strong>Remove Users:</strong> A dedicated section to delete regular user accounts when needed, with username search.</li>
                        </ul>
                    </div>

                <?php elseif ($active_tab == 'my_profile'): ?>
                    <div class="content-box admin-section" style="margin: 0;">
                        <h3 style="color: #01579b; font-size: 1rem; margin-bottom: 10px;">👤 Review & Edit My Manager Profile</h3>
                        <div style="background: rgba(240, 248, 255, 0.7); border: 1px solid #b3d7ff; padding: 12px; border-radius: 8px; margin-bottom: 15px;">
                            <form method="POST" class="aero-monitored-form">
                                <input type="hidden" name="action" value="update_profile">
                                <div class="form-group">
                                    <label>Manager Account ID</label>
                                    <input type="text" value="<?= $manager_info['id'] ?? 2 ?>" disabled style="background: #e9ecef;">
                                </div>
                                <div class="form-group">
                                    <label>Role</label>
                                    <input type="text" name="profile_username" value="<?= htmlspecialchars($manager_info['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Username</label>
                                    <input type="text" name="profile_username" value="<?= htmlspecialchars($manager_info['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Current Password</label>
                                    <div style="padding: 8px 10px; background: #fff; border: 1px solid #8ab8e6; border-radius: 6px;">
                                        <span class="password-pill">Hidden — reset to change</span>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>New Password (Leave blank to keep current password)</label>
                                    <input type="text" name="profile_password" placeholder="Enter new password if changing..." autocomplete="off">
                                </div>
                                <button type="submit" class="aero-button primary save-trigger" style="font-size: 0.85rem; padding: 8px 15px;">Save Profile Changes</button>
                            </form>
                        </div>
                    </div>

                <?php elseif ($active_tab == 'add_account'): ?>
                    <div class="content-box admin-section" style="margin: 0;">
                        <h3 style="color: #01579b; font-size: 1rem; margin-bottom: 10px;">➕ Provision New System Account</h3>
                        <div style="background: rgba(240, 248, 255, 0.7); border: 1px solid #b3d7ff; padding: 12px; border-radius: 8px; margin-bottom: 15px;">
                            <form method="POST" class="aero-monitored-form">
                                <input type="hidden" name="action" value="add_account">
                                <div class="form-group">
                                    <label>Username</label>
                                    <input type="text" name="new_username" placeholder="Username..." required>
                                </div>
                                <div class="form-group">
                                    <label>Password</label>
                                    <input type="text" name="new_password" placeholder="Password..." required autocomplete="off">
                                </div>
                                <div class="form-group">
                                    <label>Role Level</label>
                                    <input type="text" value="Admin Account" disabled style="background: #e9ecef;">
                                    <p style="font-size: 0.75rem; color: #546e7a; margin: 4px 0 0;">Manager accounts can only be admins. Regular users register themselves on the site.</p>
                                </div>
                                <button type="submit" class="aero-button primary save-trigger" style="font-size: 0.85rem; padding: 8px 15px;">Create Account</button>
                            </form>
                        </div>
                    </div>

                <?php elseif ($active_tab == 'manage_accounts'): ?>
                    <div class="content-box admin-section" style="margin: 0;">
                        <h3 style="color: #01579b; font-size: 1rem; margin-bottom: 10px;">📋 Manager & Admin Accounts</h3>

                        <?php foreach ($accounts as $acc): ?>
                            <!-- Hidden submit targets: a <form> can't legally live inside a <tr>,
                                 so the row inputs reference these via the form="" attribute. -->
                            <form id="edit-form-<?= $acc['id'] ?>" method="POST" class="aero-monitored-form">
                                <input type="hidden" name="action" value="edit_account">
                                <input type="hidden" name="edit_id" value="<?= $acc['id'] ?>">
                            </form>
                            <?php if ($acc['id'] != 2): ?>
                            <form id="delete-form-<?= $acc['id'] ?>" method="POST">
                                <input type="hidden" name="action" value="delete_account">
                                <input type="hidden" name="delete_id" value="<?= $acc['id'] ?>">
                            </form>
                            <?php endif; ?>
                        <?php endforeach; ?>

                        <div style="overflow-x: auto;">
                            <table class="accounts-table">
                                <thead>
                                    <tr>
                                        <th>No.</th>
                                        <th>Username</th>
                                        <th>Role</th>
                                        <th>Actions & Credentials</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $rowNo = 1; foreach ($accounts as $acc): ?>
                                    <tr>
                                        <td data-label="No."><strong><?= $rowNo++ ?></strong></td>
                                        <td data-label="Username">
                                            <input type="text" name="edit_username" form="edit-form-<?= $acc['id'] ?>" value="<?= htmlspecialchars($acc['username']) ?>" required style="padding: 5px 8px;">
                                        </td>
                                        <td data-label="Role">
                                            <?php if ($acc['id'] == 2): ?>
                                                <span class="password-pill">Manager</span>
                                            <?php else: ?>
                                                <span class="password-pill">Admin</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Actions" class="actions-cell">
                                        <span class="password-pill">Hidden — reset to change</span>
                                            <input type="text" name="edit_password" form="edit-form-<?= $acc['id'] ?>" placeholder="New pass..." autocomplete="off" style="padding: 5px 8px; width: 120px;">
                                            <button type="submit" form="edit-form-<?= $acc['id'] ?>" class="aero-button primary save-trigger" style="padding: 5px 10px; font-size: 0.75rem;">Save</button>
                                            <?php if ($acc['id'] != 2): ?>
                                                <button type="submit" form="delete-form-<?= $acc['id'] ?>" class="aero-button delete-trigger" style="background: linear-gradient(to bottom, #ffcdd2, #e57373); color: #b71c1c; border-color: #e53935; padding: 5px 10px; font-size: 0.75rem;">Delete</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php elseif ($active_tab == 'remove_users'): ?>
                    <div class="content-box admin-section" style="margin: 0;">
                        <h3 style="color: #01579b; font-size: 1rem; margin-bottom: 10px;">🗑 Remove User Accounts</h3>
                        <p style="font-size: 0.8rem; color: #546e7a; margin-top: 0;">
                            Only regular user accounts are listed here. Admin & manager accounts live in "Manager & Admins" and are never removed from this section.
                        </p>

                        <div style="background: rgba(240, 248, 255, 0.7); border: 1px solid #b3d7ff; padding: 12px; border-radius: 8px; margin-bottom: 15px;">
                            <div class="form-group" style="margin-bottom: 10px;">
                                <label for="user-search">🔍 Search by username</label>
                                <input type="text" id="user-search" oninput="filterUserRows()" placeholder="Type a username to filter..." autocomplete="off">
                            </div>

                            <?php if (empty($regular_users)): ?>
                                <p style="font-size: 0.85rem; color: #37474f; margin: 0;">No regular user accounts exist. Nothing to remove. 🎉</p>
                            <?php else: ?>
                                <?php foreach ($regular_users as $ru): ?>
                                    <form id="remove-user-form-<?= $ru['id'] ?>" method="POST">
                                        <input type="hidden" name="action" value="delete_account">
                                        <input type="hidden" name="delete_id" value="<?= $ru['id'] ?>">
                                    </form>
                                <?php endforeach; ?>

                                <div style="overflow-x: auto;">
                                    <table class="accounts-table remove-users-table">
                                        <thead>
                                            <tr>
                                                <th>Username</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="remove-users-body">
                                            <?php foreach ($regular_users as $ru): ?>
                                            <tr data-username="<?= htmlspecialchars(strtolower($ru['username'])) ?>">
                                                <td data-label="Username"><strong><?= htmlspecialchars($ru['username']) ?></strong></td>
                                                <td data-label="Action" class="actions-cell">
                                                    <button type="submit" form="remove-user-form-<?= $ru['id'] ?>" class="aero-button delete-trigger" style="background: linear-gradient(to bottom, #ffcdd2, #e57373); color: #b71c1c; border-color: #e53935; padding: 5px 10px; font-size: 0.75rem;">Delete</button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <p id="no-user-results" style="font-size: 0.85rem; color: #546e7a; display: none; margin: 8px 0 0;">No usernames match that search.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

            <?php endif; ?>

        </div>

        <!-- Window Status Bar -->
        <div class="window-statusbar" style="padding: 4px 13px;">
            <span>(Standalone Accounts Manager Portal) | Made by Jumana - All Rights Reserved</span>
            <span class="status-icons-right">📶 🔊 🪟</span>
        </div>
    </div>

    <script>
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
            // Username search filter for the Remove Users section
            window.filterUserRows = function() {
                const q = (document.getElementById('user-search')?.value || '').trim().toLowerCase();
                let visible = 0;
                document.querySelectorAll('#remove-users-body tr').forEach(tr => {
                    const match = (tr.getAttribute('data-username') || '').includes(q);
                    tr.style.display = match ? '' : 'none';
                    if (match) visible++;
                });
                const emptyMsg = document.getElementById('no-user-results');
                if (emptyMsg) emptyMsg.style.display = visible === 0 ? 'block' : 'none';
            };

            // Intercept save / submit actions
            document.querySelectorAll('.aero-monitored-form').forEach(form => {
                const saveBtn = form.querySelector('.save-trigger');
                if (saveBtn) {
                    saveBtn.addEventListener('click', function(e) {
                        if (form.checkValidity()) {
                            e.preventDefault();
                            showAeroAlert(
                                'Save Edits Notice',
                                'You are about to save changes to account credentials. Do you want to proceed?',
                                function() { form.submit(); }
                            );
                        }
                    });
                }
            });

            // Intercept delete actions
            document.querySelectorAll('.delete-trigger').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const deleteForm = document.getElementById(this.getAttribute('form'));
                    showAeroAlert(
                        'Delete Account',
                        'This will permanently remove this account. This cannot be undone. Continue?',
                        function() { if (deleteForm) deleteForm.submit(); }
                    );
                });
            });

            // Intercept logout action
            const logoutBtn = document.getElementById('logout-trigger');
            if (logoutBtn) {
                logoutBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const logoutUrl = this.getAttribute('href');
                    showAeroAlert(
                        'Logout Confirmation',
                        'Are you sure you want to end your manager session and log out?',
                        function() { window.location.href = logoutUrl; }
                    );
                });
            }
        });
    </script>
</body>
</html>