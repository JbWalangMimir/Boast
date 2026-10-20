<?php
$conn = new mysqli("localhost", "root", "", "boastdb");
$status = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $full_name = trim($_POST["full_name"] ?? "");
    $role      = trim($_POST["role"] ?? "");
    $phone     = trim($_POST["phone"] ?? "");
    $email     = trim($_POST["email"] ?? "");
    $message   = trim($_POST["message"] ?? "");

    if ($full_name && $role && $email && $message && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $conn->prepare("INSERT INTO contact_messages (full_name, role, phone, email, message) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $full_name, $role, $phone, $email, $message);
        $status = $stmt->execute() ? "success" : "error";
        $stmt->close();
    } else {
        $status = "invalid";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact - Boast</title>
    <link rel="stylesheet" type="text/css" href="sources/css/contact.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
</head>
<body>
    <nav class="navbar">
        <div class="logo-container">
            <a href="index.php"><img src="sources/images/Boast.png" alt="Boast" class="logo-website"></a>
            <span class="site-name"><a href="index.php">Boast</a></span>
        </div>
        <ul class="nav-links">
            <li><a href="index.php" class="l1">Home</a></li>
            <li><a href="about.php" class="l2">About</a></li>
            <li><a href="contact.php" class="l3">Contact Us</a></li>
            <li><button onclick="window.location.href='signup.php'" class="b-sign-up">Sign Up</button></li>
            <li><button onclick="window.location.href='login.php'" class="b-login">Log In</button></li>
        </ul>
    </nav>

    <main class="contact-wrapper">
        <form class="contact-form" method="POST" action="contact.php">
            <h1 class="contact-title">Contact Us</h1>

            <?php if ($status === "success"): ?>
                <div class="alert alert-success">Your message has been sent. Thank you!</div>
            <?php elseif ($status === "error"): ?>
                <div class="alert alert-error">Something went wrong. Please try again.</div>
            <?php elseif ($status === "invalid"): ?>
                <div class="alert alert-error">Please fill in all required fields with valid information.</div>
            <?php endif; ?>

            <section class="form-card">
                <div class="card-header">
                    <h2>Your Details</h2>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="full_name">Full Name</label>
                        <input type="text" id="full_name" name="full_name" placeholder="Your full name" required>
                    </div>

                    <div class="form-group">
                        <label for="role">Status</label>
                        <select id="role" name="role" required>
                            <option value="" disabled selected>Select your status</option>
                            <option value="Student">Student</option>
                            <option value="Teacher">Employed</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="tel" id="phone" name="phone" placeholder="e.g. 09xxxxxxxxx">
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" placeholder="your@email.com" required>
                    </div>
                </div>
            </section>

            <section class="form-card">
                <div class="card-header">
                    <h2>Your Message</h2>
                </div>

                <div class="form-group">
                    <label for="message">Message / Suggestions</label>
                    <textarea id="message" name="message" rows="6" placeholder="Share your experience or suggestions for Boast..." required></textarea>
                </div>
            </section>

            <button type="submit" class="submit-btn">Send Message</button>
        </form>
    </main>
</body>
</html>