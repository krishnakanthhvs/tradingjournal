<?php
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';

app_start_protected();
$userId = get_current_user_id();

// Fetch current user info
$stmt = $mysqli->prepare("
    SELECT name, username, email, phone, username_locked, pending_email
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$stmt->bind_result($name, $username, $email, $phone, $usernameLocked, $pendingEmail);
$stmt->fetch();
$stmt->close();

// Messages per section
$userMsg   = '';
$passMsg   = '';
$emailMsg  = '';
$phoneMsg  = '';
$userErr   = '';
$passErr   = '';
$emailErr  = '';
$phoneErr  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ---- 1. Change username (only once) ----
    if ($action === 'change_username') {
        if ($usernameLocked) {
            $userErr = 'Username change is already locked.';
        } else {
            $newUsername = trim($_POST['new_username'] ?? '');
            if ($newUsername === '') {
                $userErr = 'Username cannot be empty.';
            } elseif ($newUsername === $username) {
                $userErr = 'New username must be different from current username.';
            } else {
                // Check if username taken
                $check = $mysqli->prepare("SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1");
                $check->bind_param('si', $newUsername, $userId);
                $check->execute();
                $check->store_result();

                if ($check->num_rows > 0) {
                    $userErr = 'That username is already taken.';
                } else {
                    $upd = $mysqli->prepare("
                        UPDATE users
                        SET username = ?, username_locked = 1
                        WHERE id = ?
                        LIMIT 1
                    ");
                    $upd->bind_param('si', $newUsername, $userId);
                    if ($upd->execute()) {
                        $userMsg = 'Username updated successfully. It is now locked.';
                        $username = $newUsername;
                        $usernameLocked = 1;
                        $_SESSION['username'] = $newUsername;
                    } else {
                        $userErr = 'Failed to update username.';
                    }
                    $upd->close();
                }
                $check->close();
            }
        }
    }

    // ---- 2. Change password ----
    if ($action === 'change_password') {
        $current = trim($_POST['current_password'] ?? '');
        $new     = trim($_POST['new_password'] ?? '');
        $confirm = trim($_POST['confirm_password'] ?? '');

        if ($current === '' || $new === '' || $confirm === '') {
            $passErr = 'All password fields are required.';
        } elseif ($new !== $confirm) {
            $passErr = 'New password and confirmation do not match.';
        } else {
            // Fetch current password hash
            $ps = $mysqli->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
            $ps->bind_param('i', $userId);
            $ps->execute();
            $ps->bind_result($dbPassword);
            if ($ps->fetch()) {
                $ps->close();

                $valid = false;
                if (strpos($dbPassword, '$2y$') === 0) {
                    $valid = password_verify($current, $dbPassword);
                } else {
                    $valid = ($current === $dbPassword);
                }

                if (!$valid) {
                    $passErr = 'Current password is incorrect.';
                } else {
                    $newHash = password_hash($new, PASSWORD_BCRYPT);
                    $up = $mysqli->prepare("UPDATE users SET password = ? WHERE id = ? LIMIT 1");
                    $up->bind_param('si', $newHash, $userId);
                    if ($up->execute()) {
                        $passMsg = 'Password updated successfully.';
                    } else {
                        $passErr = 'Failed to update password.';
                    }
                    $up->close();
                }
            } else {
                $ps->close();
                $passErr = 'Could not verify your current password.';
            }
        }
    }

    // ---- 3. Change email (with confirmation) ----
    if ($action === 'change_email') {
        $newEmail = trim($_POST['new_email'] ?? '');
        if ($newEmail === '') {
            $emailErr = 'Email cannot be empty.';
        } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $emailErr = 'Invalid email format.';
        } elseif ($newEmail === $email) {
            $emailErr = 'New email is same as current email.';
        } else {
            // Check uniqueness
            $chk = $mysqli->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
            $chk->bind_param('si', $newEmail, $userId);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) {
                $emailErr = 'That email is already in use.';
            } else {
                $token = bin2hex(random_bytes(32));

                $up = $mysqli->prepare("
                    UPDATE users
                    SET pending_email = ?, email_change_token = ?
                    WHERE id = ?
                    LIMIT 1
                ");
                $up->bind_param('ssi', $newEmail, $token, $userId);
                if ($up->execute()) {
                    $emailMsg = 'Confirmation link has been sent to the new email (simulation).';

                    // In real app, send mail here.
                    // $verifyUrl = 'https://yourdomain.com/verify_email_change.php?token=' . urlencode($token);
                    // mail($newEmail, 'Confirm your new email', "Click here to confirm: $verifyUrl");

                    $pendingEmail = $newEmail;
                } else {
                    $emailErr = 'Failed to initiate email change.';
                }
                $up->close();
            }
            $chk->close();
        }
    }

    // ---- 4. Change phone number ----
    if ($action === 'change_phone') {
        $newPhone = trim($_POST['phone'] ?? '');
        if ($newPhone === '') {
            $phoneErr = 'Phone number cannot be empty.';
        } else {
            $up = $mysqli->prepare("UPDATE users SET phone = ? WHERE id = ? LIMIT 1");
            $up->bind_param('si', $newPhone, $userId);
            if ($up->execute()) {
                $phoneMsg = 'Phone number updated.';
                $phone = $newPhone;
            } else {
                $phoneErr = 'Failed to update phone number.';
            }
            $up->close();
        }
    }
}
?>
<div class="content">
    <?php require_once __DIR__ . '/inc/topbar.php'; ?>
    <main class="main">

        <!-- Username section -->
        <div class="card">
            <h2 class="card-title">Username</h2>
            <p class="text-muted mt-1">
                You can change your username only once.
            </p>

            <?php if ($userMsg): ?>
                <div class="alert" style="background:#dcfce7;color:#166534;margin-top:0.5rem;"><?php echo htmlspecialchars($userMsg); ?></div>
            <?php endif; ?>
            <?php if ($userErr): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($userErr); ?></div>
            <?php endif; ?>

            <form method="post" class="mt-1">
                <input type="hidden" name="action" value="change_username">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Current username</label>
                        <input type="text" value="<?php echo htmlspecialchars($username); ?>" disabled>
                    </div>
                    <div class="form-group">
                        <label for="new_username">New username</label>
                        <input
                            type="text"
                            name="new_username"
                            id="new_username"
                            <?php echo $usernameLocked ? 'disabled' : ''; ?>
                        >
                    </div>
                </div>
                <div class="form-actions">
                    <button class="btn" type="submit" <?php echo $usernameLocked ? 'disabled' : ''; ?>>
                        Save Username
                    </button>
                    <?php if ($usernameLocked): ?>
                        <span class="text-muted" style="margin-left:0.5rem;font-size:0.8rem;">
                            Username is locked and cannot be changed again.
                        </span>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="columns-2" style="margin-top:1rem;">

            <!-- Password card -->
            <div class="card">
                <h2 class="card-title">Change Password</h2>
                <?php if ($passMsg): ?>
                    <div class="alert" style="background:#dcfce7;color:#166534;margin-top:0.5rem;"><?php echo htmlspecialchars($passMsg); ?></div>
                <?php endif; ?>
                <?php if ($passErr): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($passErr); ?></div>
                <?php endif; ?>

                <form method="post" class="mt-1">
                    <input type="hidden" name="action" value="change_password">
                    <div class="form-group">
                        <label for="current_password">Current password</label>
                        <input type="password" name="current_password" id="current_password" required>
                    </div>
                    <div class="form-group">
                        <label for="new_password">New password</label>
                        <input type="password" name="new_password" id="new_password" required>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm new password</label>
                        <input type="password" name="confirm_password" id="confirm_password" required>
                    </div>
                    <div class="form-actions">
                        <button class="btn" type="submit">Update Password</button>
                    </div>
                </form>
            </div>

            <!-- Email / phone card -->
            <div class="card">
                <h2 class="card-title">Contact Details</h2>

                <!-- Email -->
                <h3 class="card-title" style="font-size:0.95rem;margin-top:0.25rem;">Email</h3>
                <p class="text-muted mt-1">
                    New email will be updated only after you click the confirmation link.
                </p>
                <?php if ($emailMsg): ?>
                    <div class="alert" style="background:#dcfce7;color:#166534;margin-top:0.5rem;"><?php echo htmlspecialchars($emailMsg); ?></div>
                <?php endif; ?>
                <?php if ($emailErr): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($emailErr); ?></div>
                <?php endif; ?>
                <form method="post" class="mt-1">
                    <input type="hidden" name="action" value="change_email">
                    <div class="form-group">
                        <label>Current email</label>
                        <input type="email" value="<?php echo htmlspecialchars($email); ?>" disabled>
                    </div>
                    <div class="form-group">
                        <label for="new_email">New email</label>
                        <input type="email" name="new_email" id="new_email">
                    </div>
                    <?php if (!empty($pendingEmail)): ?>
                        <p class="text-muted mt-1">
                            Pending confirmation for: <strong><?php echo htmlspecialchars($pendingEmail); ?></strong>
                        </p>
                    <?php endif; ?>
                    <div class="form-actions">
                        <button class="btn" type="submit">Request Email Change</button>
                    </div>
                </form>

                <hr style="border:none;border-top:1px solid var(--border-subtle);margin:1rem 0;">

                <!-- Phone -->
                <h3 class="card-title" style="font-size:0.95rem;">Phone</h3>
                <?php if ($phoneMsg): ?>
                    <div class="alert" style="background:#dcfce7;color:#166534;margin-top:0.5rem;"><?php echo htmlspecialchars($phoneMsg); ?></div>
                <?php endif; ?>
                <?php if ($phoneErr): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($phoneErr); ?></div>
                <?php endif; ?>
                <form method="post" class="mt-1">
                    <input type="hidden" name="action" value="change_phone">
                    <div class="form-group">
                        <label for="phone">Phone number</label>
                        <input type="text" name="phone" id="phone" value="<?php echo htmlspecialchars($phone); ?>">
                    </div>
                    <div class="form-actions">
                        <button class="btn" type="submit">Update Phone</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
    <?php require_once __DIR__ . '/inc/footer.php'; ?>
</div>