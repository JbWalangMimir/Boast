<?php
session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/db_connect.php';

function homeFor(string $role): string
{
    return $role === 'admin' ? ADMIN_URL . '/dashboard.php' : BASE_URL . '/user.php';
}

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . homeFor($_SESSION['role'] ?? 'user'));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare('SELECT id, firstName, lastName, password, role, status FROM boastusers WHERE email = ?');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if ($user && password_verify($password, $user['password'])) {
        if ($user['status'] !== 'active') {
            $error = 'This account has been deactivated.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id']  = $user['id'];
            $_SESSION['username'] = $user['firstName'] . ' ' . $user['lastName'];
            $_SESSION['role']     = $user['role'];
            header('Location: ' . homeFor($user['role']));
            exit;
        }
    } else {
        $error = 'Invalid email or password.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - Boast</title>
    <link rel="stylesheet" type="text/css" href="sources/css/login.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
</head>
<body>
    <div class="section-container">
        <div class="content-section">
            <button onclick="window.location.href='index.php'" class="b-home">
                <span class="material-symbols-outlined">chevron_backward</span>Back to Home
            </button>
            <div class="login-text">
                <h1 class="content-text">Budget through your goals with Boast</h1>
                <p>Manage your income, expenses and savings efficiently.</p>
            </div>
        </div>
        <div class="log-in-section">
            <div class="log-in-form">
                <h1>Boast</h1>
                <h3>Log in to your account</h3>
                <?php if (isset($_GET['reset'])): ?><p style="color:green">Password updated. You can log in now.</p><?php endif; ?>
<form method="post">
                <form method="post">
                    <?php if ($error): ?><p style="color:red"><?= htmlspecialchars($error) ?></p><?php endif; ?>
                    <div class="email-field">
                        <div class="email-bundle">
                            <label for="email-address">Email address</label>
                            <input type="email" id="email-address" name="email" placeholder="Enter your email address" required>
                        </div>
                    </div>
                    <?php if ($error): ?>
                        <div class="error-field">
                            <p class="error-message"><?= htmlspecialchars($error) ?></p>
                        </div>
                    <?php endif; ?>
                    <div class="password-field">
                        <div class="password-bundle">
                            <label for="user-password">Password</label>
                            <div class="password-wrapper">
                                <input type="password" id="user-password" name="password" placeholder="Enter your password" required>
                                <span class="material-symbols-outlined" id="showPassword">visibility</span>
                                <a href="forgot_password.php" class="forgor">Forgot password?</a>
                            </div>
                        </div>
                    </div>
                    <div class="button-field">
                        <div class="sign-up-button">
                            <button type="submit" class="log-in-button">Log In</button>
                        </div>
                    </div>
                </form>
                <center>
                    <p> No account yet?
                        <a href="signup.php" style="text-decoration:none;">Sign Up</a>
                    </p>
                </center>
            </div>
        </div>
    </div>
</body>

<script>
    const passwordInput = document.getElementById('user-password');
    const togglePassword = document.getElementById('showPassword');

    togglePassword.addEventListener('click', function () {
    const isPassword = passwordInput.getAttribute('type') === 'password';
    passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
    
    this.textContent = isPassword ? 'visibility_off' : 'visibility';
    });
</script>

</html>