<?php
session_start();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/mail_helper.php';

$email = $_SESSION['pending_email'] ?? '';
if ($email === '') {
    header('Location: signup.php');
    exit;
}

$message = '';
$info    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'verify';

    $stmt = $conn->prepare('SELECT id, firstName, verification_token, token_expires, code_attempts FROM boastusers WHERE email = ? AND is_verified = 0');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) {
        unset($_SESSION['pending_email']);
        header('Location: login.php');
        exit;
    }

    if ($action === 'resend') {
        $code      = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $tokenHash = hash('sha256', $code);
        $expires   = date('Y-m-d H:i:s', time() + 900);

        $upd = $conn->prepare('UPDATE boastusers SET verification_token = ?, token_expires = ?, code_attempts = 0 WHERE id = ?');
        $upd->bind_param('ssi', $tokenHash, $expires, $user['id']);
        $upd->execute();

        if (sendVerificationCode($email, $user['firstName'], $code)) {
            $info = 'A new code has been sent.';
        } else {
            $message = 'Could not send the email. Please try again.';
        }
    } else {
        $input = trim($_POST['code'] ?? '');

        if ((int)$user['code_attempts'] >= 5) {
            $message = 'Too many attempts. Please request a new code.';
        } elseif (strtotime($user['token_expires']) < time()) {
            $message = 'That code has expired. Please request a new one.';
        } elseif (!preg_match('/^\d{6}$/', $input)) {
            $message = 'Please enter the 6-digit code.';
        } elseif (hash_equals($user['verification_token'], hash('sha256', $input))) {
            $upd = $conn->prepare('UPDATE boastusers SET is_verified = 1, verification_token = NULL, token_expires = NULL, code_attempts = 0 WHERE id = ?');
            $upd->bind_param('i', $user['id']);
            $upd->execute();
            unset($_SESSION['pending_email']);
            header('Location: login.php');
            exit;
        } else {
            $upd = $conn->prepare('UPDATE boastusers SET code_attempts = code_attempts + 1 WHERE id = ?');
            $upd->bind_param('i', $user['id']);
            $upd->execute();
            $message = 'Incorrect code.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email - Boast</title>
    <link rel="stylesheet" type="text/css" href="sources/css/signup.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
</head>
<body>
    <div class="section-container">
        <div class="log-in-section">
            <div class="log-in-form">
                <h1>Boast</h1>
                <h3>Verify your email</h3>
                <p>Enter the 6-digit code sent to <strong><?= htmlspecialchars($email) ?></strong>.</p>
                <?php if ($message): ?><p style="color:red"><?= htmlspecialchars($message) ?></p><?php endif; ?>
                <?php if ($info): ?><p style="color:green"><?= htmlspecialchars($info) ?></p><?php endif; ?>
                <form method="post">
                    <div class="required-bundle">
                        <label for="code">Verification Code</label>
                        <input type="text" id="code" name="code" inputmode="numeric" maxlength="6" pattern="\d{6}" placeholder="123456" autocomplete="one-time-code" required>
                    </div>
                    <div class="button-field">
                        <div class="sign-up-button">
                            <button type="submit" name="action" value="verify" class="create-account-button">Verify</button>
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
    </div>
</body>
</html>