<?php
session_start();
require_once __DIR__ . '/inc/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $stmt = $mysqli->prepare('SELECT id, name, username, password, is_verified FROM users WHERE username = ? LIMIT 1');
        if (!$stmt) {
            die('Prepare failed: ' . $mysqli->error);
        }

        $stmt->bind_param('s', $username);
        $stmt->execute();
        $stmt->bind_result($id, $dbName, $dbUsername, $dbPassword, $isVerified);

        if ($stmt->fetch()) {
            // Password check
            $valid = false;
            if (strpos($dbPassword, '$2y$') === 0) {
                $valid = password_verify($password, $dbPassword);
            } else {
                $valid = ($password === $dbPassword);
            }

            if ($valid) {
                if ((int)$isVerified !== 1) {
                    $error = 'Please verify your email before logging in.';
                } else {
                    $_SESSION['user_id']  = $id;
                    $_SESSION['username'] = $dbUsername;
                    $_SESSION['name']     = $dbName;

                    header('Location: dashboard.php');
                    exit;
                }
            } else {
                $error = 'Invalid credentials.';
            }
        } else {
            $error = 'Invalid credentials.';
        }

        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - Trading Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/img/favicon.png" sizes="32x32">

    <!-- Main styles (add login CSS in this file, below) -->
    <link rel="stylesheet" href="assets/css/style.css">

    <!-- Font Awesome for the logo icon -->
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
          crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>
<body>
<div class="login-page">
    <div class="login-container">

        <!-- LEFT: Trading illustration -->
        <div class="login-illustration-col">
            <div class="login-illustration-wrapper">
                <h2 class="login-illustration-title">Track & Analyse Your Trades</h2>
                <p class="login-illustration-text">
                    Visualize performance, refine your strategy, and grow as a trader with your personal trading journal.
                </p>
                <!-- Trading-related image (no broken path) -->
                <img
                    src="https://images.pexels.com/photos/7567443/pexels-photo-7567443.jpeg?auto=compress&cs=tinysrgb&w=800"
                    alt="Trading dashboard illustration"
                    class="login-illustration-img">
            </div>
        </div>

        <!-- RIGHT: Login form -->
        <div class="login-form-col">
            <div class="login-logo">
                <img src="assets/img/logo_without_bg.png" alt="Trading Journal" class="login-logo-img">
            </div>

            <h2 class="login-title">Login</h2>
            <p class="login-subtitle">
            </p>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" name="username" id="username"
                           placeholder="Enter Username" required autofocus>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" name="password" id="password"
                           placeholder="Enter 6 character or more" required>
                </div>

                <div class="login-row">
                    <label class="remember-me">
                        <input type="checkbox" name="remember">
                        <span>Remember me</span>
                    </label>
                    <a href="#" class="forgot-link">Forgot Password?</a>
                </div>

                <button class="btn btn-login-primary" type="submit">LOGIN</button>
            </form>
        </div>

    </div>
</div>
</body>
</html>