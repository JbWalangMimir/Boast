<?php
session_start();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/mail_helper.php';

$message      = '';
$modalMessage = '';
$modalInfo    = '';
$showModal    = false;
$email        = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'register';

    if ($action === 'register') {
        $firstname     = trim($_POST['firstname'] ?? '');
        $middleinitial = trim($_POST['middleinitial'] ?? '');
        $lastname      = trim($_POST['lastname'] ?? '');
        $mobilenumber  = trim($_POST['mobilenumber'] ?? '');
        $occupation    = trim($_POST['status'] ?? '');
        $age           = (int)($_POST['age'] ?? 0);
        $gender        = trim($_POST['gender'] ?? '');
        $email         = trim($_POST['emailaddress'] ?? '');
        $password      = $_POST['password'] ?? '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please enter a valid email.';
        } elseif ($age < 1 || $age > 120) {
            $message = 'Please enter a valid age.';
        } elseif (!in_array($gender, ['Male', 'Female', 'Rather not say'], true)) {
            $message = 'Please select a gender.';
        } elseif ($password === '') {
            $message = 'Please enter a password.';
        } else {
            $check = $conn->prepare('SELECT id, is_verified FROM boastusers WHERE email = ?');
            $check->bind_param('s', $email);
            $check->execute();
            $existing = $check->get_result()->fetch_assoc();

            if ($existing && (int)$existing['is_verified'] === 1) {
                $message = 'That email is already registered.';
            } else {
                $code      = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $tokenHash = hash('sha256', $code);
                $expires   = date('Y-m-d H:i:s', time() + 900);
                $hash      = password_hash($password, PASSWORD_DEFAULT);

                if ($existing) {
                    $stmt = $conn->prepare(
                        'UPDATE boastusers
                         SET firstName = ?, middleInitial = ?, lastName = ?, mobileNumber = ?, occupation = ?,
                             age = ?, gender = ?, password = ?, verification_token = ?, token_expires = ?, code_attempts = 0
                         WHERE id = ?'
                    );
                    $stmt->bind_param('sssssissssi', $firstname, $middleinitial, $lastname, $mobilenumber, $occupation, $age, $gender, $hash, $tokenHash, $expires, $existing['id']);
                } else {
                    $stmt = $conn->prepare(
                        'INSERT INTO boastusers (email, firstName, middleInitial, lastName, mobileNumber, occupation, age, gender, password, verification_token, token_expires, is_verified)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)'
                    );
                    $stmt->bind_param('ssssssissss', $email, $firstname, $middleinitial, $lastname, $mobilenumber, $occupation, $age, $gender, $hash, $tokenHash, $expires);
                }
                $stmt->execute();

                if (sendVerificationCode($email, $firstname, $code)) {
                    $_SESSION['pending_email'] = $email;
                    $showModal = true;
                } else {
                    $message = 'We could not send the verification email. ' . ($GLOBALS['mail_error'] ?? '');
                }
            }
        }
    } else {
        $email     = $_SESSION['pending_email'] ?? '';
        $showModal = true;

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
                $modalInfo = 'A new code has been sent.';
            } else {
                $modalMessage = 'Could not send the email. ' . ($GLOBALS['mail_error'] ?? '');
            }
        } else {
            $input = trim($_POST['code'] ?? '');

            if ((int)$user['code_attempts'] >= 5) {
                $modalMessage = 'Too many attempts. Please request a new code.';
            } elseif (strtotime($user['token_expires']) < time()) {
                $modalMessage = 'That code has expired. Please request a new one.';
            } elseif (!preg_match('/^\d{6}$/', $input)) {
                $modalMessage = 'Please enter the 6-digit code.';
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
    <title>Sign Up - Boast</title>
    <link rel="stylesheet" type="text/css" href="sources/css/signup.css">
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
            <div class="signup-text">
                <h1 class="content-text">Create your Boast Account</h1>
                <p>Register to start management and tracking of your finances.</p>
            </div>
        </div>
        <div class="log-in-section">
            <div class="log-in-form">
                <h1>Boast</h1>
                <h3>Create your account</h3>
                <form method="post">
                    <input type="hidden" name="action" value="register">
                    <?php if ($message): ?><p style="color:red"><?= htmlspecialchars($message) ?></p><?php endif; ?>
                    <div class="name-fields">
                        <div class="name-bundle">
                            <label for="firstname">First Name</label>
                            <input type="text" id="firstname" name="firstname" placeholder="Enter you first name" required>
                        </div>
                        <div class="name-bundle">
                            <label for="middleinitial">Middle Initial</label>
                            <input type="text" id="middleinitial" name="middleinitial" placeholder="Enter your middle initial" required>
                        </div>
                        <div class="name-bundle">
                            <label for="lastname">Last Name</label>
                            <input type="text" id="lastname" name="lastname" placeholder="Enter your last name" required>
                        </div>
                    </div>
                    <div class="optional-fields">
                        <div class="optional-bundle">
                            <label for="mobilenumber">Mobile Number</label>
                            <input type="tel" id="mobilenumber" name="mobilenumber" placeholder="0905 771 7253..." required>
                        </div>
                        <div class="optional-bundle">
                            <label for="status">Status</label>
                            <select id="status" name="status" required>
                                <option value="" disabled selected>Select your status</option>
                                <option value="Student">Student</option>
                                <option value="Employed">Employed</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="optional-fields">
                        <div class="optional-bundle">
                            <label for="age">Age</label>
                            <input type="number" id="age" name="age" min="1" max="120" placeholder="Enter your age" required>
                        </div>
                        <div class="optional-bundle">
                            <label for="gender">Gender</label>
                            <select id="gender" name="gender" required>
                                <option value="" disabled selected>Select your gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Rather not say">Rather not say</option>
                            </select>
                        </div>
                    </div>
                    <div class="required-fields">
                        <div class="required-bundle">
                            <label for="emailaddress">Email Address</label>
                            <input type="email" id="emailaddress" name="emailaddress" placeholder="juandelacruz@gmail.com" required>
                        </div>
                        <div class="required-bundle">
                            <label for="password">Password</label>
                            <div class="password-wrapper">
                                <input type="password" id="user-password" name="password" placeholder="Enter your password" required>
                                <span class="material-symbols-outlined" id="showPassword">visibility</span>
                            </div>
                        </div>
                    </div>
                    <div class="button-field">
                        <div class="sign-up-button">
                            <button type="submit" class="create-account-button">Create Account</button>
                        </div>
                    </div>
                </form>
                <center>
                    <p> Already have an account?
                        <a href="login.php" style="text-decoration:none;">Log In</a>
                    </p>
                </center>
            </div>
        </div>
    </div>

    <?php if ($showModal): ?>
    <div class="modal-overlay">
        <div class="modal-box">
            <h3>Verify your email</h3>
            <p>Enter the 6-digit code sent to <strong><?= htmlspecialchars($email) ?></strong>.</p>
            <?php if ($modalMessage): ?><p style="color:red"><?= htmlspecialchars($modalMessage) ?></p><?php endif; ?>
            <?php if ($modalInfo): ?><p style="color:green"><?= htmlspecialchars($modalInfo) ?></p><?php endif; ?>
            <form method="post">
                <div class="required-bundle">
                    <label for="code">Verification Code</label>
                    <input type="text" id="code" name="code" inputmode="numeric" maxlength="6" pattern="\d{6}" placeholder="123456" autocomplete="one-time-code" required autofocus>
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
    <?php endif; ?>
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