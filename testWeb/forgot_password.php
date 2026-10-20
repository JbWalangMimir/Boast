<?php
session_start();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/mail_helper.php';

$message      = '';
$modalMessage = '';
$modalInfo    = '';
$showModal    = false;
$email        = '';

function issueResetCode(mysqli $conn, array $user, string $email): bool
{
    $code      = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $tokenHash = hash('sha256', $code);
    $expires   = date('Y-m-d H:i:s', time() + 900);

    $upd = $conn->prepare('UPDATE boastusers SET reset_token = ?, reset_expires = ?, reset_attempts = 0 WHERE id = ?');
    $upd->bind_param('ssi', $tokenHash, $expires, $user['id']);
    $upd->execute();

    return sendResetCode($email, $user['firstName'], $code);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    unset($_SESSION['reset_email']);
} else {
    $action = $_POST['action'] ?? 'request';

    if ($action === 'request') {
        $email = trim($_POST['emailaddress'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please enter a valid email.';
        } else {
            $stmt = $conn->prepare('SELECT id, firstName FROM boastusers WHERE email = ? AND is_verified = 1');
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            if ($user && !issueResetCode($conn, $user, $email)) {
                $message = 'We could not send the email. ' . ($GLOBALS['mail_error'] ?? '');
            } else {
                $_SESSION['reset_email'] = $email;
                $showModal = true;
            }
        }
    } else {
        $email     = $_SESSION['reset_email'] ?? '';
        $showModal = true;

        if ($email === '') {
            header('Location: forgot_password.php');
            exit;
        }

        $stmt = $conn->prepare('SELECT id, firstName, reset_token, reset_expires, reset_attempts FROM boastusers WHERE email = ? AND is_verified = 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if ($action === 'resend') {
            if ($user) {
                if (issueResetCode($conn, $user, $email)) {
                    $modalInfo = 'A new code has been sent.';
                } else {
                    $modalMessage = 'Could not send the email. ' . ($GLOBALS['mail_error'] ?? '');
                }
            } else {
                $modalInfo = 'A new code has been sent.';
            }
        } else {
            $input    = trim($_POST['code'] ?? '');
            $password = $_POST['password'] ?? '';
            $confirm  = $_POST['confirm'] ?? '';

            if (!$user || $user['reset_token'] === null) {
                $modalMessage = 'Incorrect code.';
            } elseif ((int)$user['reset_attempts'] >= 5) {
                $modalMessage = 'Too many attempts. Please request a new code.';
            } elseif (strtotime($user['reset_expires']) < time()) {
                $modalMessage = 'That code has expired. Please request a new one.';
            } elseif (!preg_match('/^\d{6}$/', $input)) {
                $modalMessage = 'Please enter the 6-digit code.';
            } elseif (strlen($password) < 8) {
                $modalMessage = 'Password must be at least 8 characters.';
            } elseif ($password !== $confirm) {
                $modalMessage = 'Passwords do not match.';
            } elseif (hash_equals($user['reset_token'], hash('sha256', $input))) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $upd  = $conn->prepare('UPDATE boastusers SET password = ?, reset_token = NULL, reset_expires = NULL, reset_attempts = 0 WHERE id = ?');
                $upd->bind_param('si', $hash, $user['id']);
                $upd->execute();
                unset($_SESSION['reset_email']);
                header('Location: login.php?reset=1');
                exit;
            } else {
                $upd = $conn->prepare('UPDATE boastusers SET reset_attempts = reset_attempts + 1 WHERE id = ?');
                $upd->bind_param('i', $user['id']);
                $upd->execute();
                $modalMessage = 'Incorrect code.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Boast</title>
    <link rel="stylesheet" type="text/css" href="sources/css/signup.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
</head>
<body>
    <div class="section-container">
        <div class="log-in-section">
            <div class="log-in-form forgot">
                <h1>Boast</h1>
                <h3>Forgot your password?</h3>
                <p>Enter your email and we'll send you a 6-digit reset code.</p>
                <form method="post">
                    <input type="hidden" name="action" value="request">
                    <?php if ($message): ?><p style="color:red"><?= htmlspecialchars($message) ?></p><?php endif; ?>
                    <div class="required-bundle">
                        <label for="emailaddress">Email Address</label>
                        <input type="email" id="emailaddress" name="emailaddress" placeholder="juandelacruz@gmail.com" value="<?= htmlspecialchars($email) ?>" required>
                    </div>
                    <div class="button-field">
                        <div class="sign-up-button">
                            <button type="submit" class="create-account-button">Send Code</button>
                        </div>
                    </div>
                </form>
                <center>
                    <p><a href="login.php" class="bck-lgin">Back to Log In</a></p>
                </center>
            </div>
        </div>
    </div>

    <?php if ($showModal): ?>
    <div class="modal-overlay">
        <div class="modal-box">
            <h3>Reset your password</h3>
            <p>If an account exists for <strong><?= htmlspecialchars($email) ?></strong>, a 6-digit code was sent. Enter it below with your new password.</p>
            <?php if ($modalMessage): ?><p style="color:red"><?= htmlspecialchars($modalMessage) ?></p><?php endif; ?>
            <?php if ($modalInfo): ?><p style="color:green"><?= htmlspecialchars($modalInfo) ?></p><?php endif; ?>
            <form method="post">
                <input type="hidden" name="action" value="reset">
                <div class="required-bundle">
                    <label for="code">Reset Code</label>
                    <input type="text" id="code" name="code" inputmode="numeric" maxlength="6" pattern="\d{6}" placeholder="123456" autocomplete="one-time-code" required autofocus>
                </div>
                <div class="required-bundle">
                    <label for="password">New Password</label>
                    <input type="password" id="password" name="password" minlength="8" placeholder="At least 8 characters" required>
                </div>
                <div class="required-bundle">
                    <label for="confirm">Confirm Password</label>
                    <input type="password" id="confirm" name="confirm" minlength="8" placeholder="Re-enter your new password" required>
                </div>
                <div class="button-field">
                    <div class="sign-up-button">
                        <button type="submit" class="create-account-button">Reset Password</button>
                    </div>
                </div>
            </form>
            <form method="post">
                <center>
                    <p>Didn't get it?
                        <button type="submit" name="action" value="resend" style="background:none;border:none;color:#0066cc;cursor:pointer;font:inherit;padding:0;">Resend code</button>
                    </p>
                </center>
            </form>
        </div>
    </div>
    <?php endif; ?>
</body>
</html>