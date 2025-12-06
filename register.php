<?php
session_start();
require_once __DIR__ . '/inc/db.php';

$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm  = trim($_POST['confirm'] ?? '');

    if ($username === '' || $email === '' || $password === '' || $confirm === '') {
        $error = 'All fields are required.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $token = bin2hex(random_bytes(16));

        $stmt = $mysqli->prepare('INSERT INTO users (username, email, password, verification_token, is_verified) VALUES (?, ?, ?, ?, 0)');
        if (!$stmt) {
            die('Prepare failed: ' . $mysqli->error);
        }
        $stmt->bind_param('ssss', $username, $email, $hash, $token);

        if ($stmt->execute()) {
            $verifyUrl = sprintf('%s://%s%s/verify.php?token=%s',
                isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http',
                $_SERVER['HTTP_HOST'],
                rtrim(dirname($_SERVER['PHP_SELF']), '/\'),
                $token
            );
            $notice = 'Registration successful! Use this verification link: ' . $verifyUrl;
        } else {
            if ($mysqli->errno === 1062) {
                $error = 'Username or email already exists.';
            } else {
                $error = 'Error creating account: ' . $stmt->error;
            }
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register - Trading Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="login-wrapper">
    <div class="login-card">
        <h1>Create Account</h1>
        <p>Register and verify your email before login.</p>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($notice): ?>
            <div class="alert" style="background:#ecfdf5;color:#166534;">
                <?php echo htmlspecialchars($notice); ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <div class="form-group" style="margin-bottom: 0.6rem;">
                <label for="username">Username</label>
                <input type="text" name="username" id="username" required>
            </div>
            <div class="form-group" style="margin-bottom: 0.6rem;">
                <label for="email">Email</label>
                <input type="email" name="email" id="email" required>
            </div>
            <div class="form-group" style="margin-bottom: 0.6rem;">
                <label for="password">Password</label>
                <input type="password" name="password" id="password" required>
            </div>
            <div class="form-group" style="margin-bottom: 0.8rem;">
                <label for="confirm">Confirm Password</label>
                <input type="password" name="confirm" id="confirm" required>
            </div>
            <button class="btn" type="submit">Register</button>
        </form>
        <p class="mt-2 text-muted">Already have an account? <a href="login.php" style="color:#93c5fd;">Login</a></p>
    </div>
</div>
</body>
</html>
