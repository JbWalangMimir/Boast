<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About - Boast</title>
    <link rel="stylesheet" type="text/css" href="sources/css/about.css">
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

    <div class="about-desc">
        <div class="about-container">
            <div class="website-about">
                <h2 class="website-aboutM">About</h2>
                <h1 class="logo-title-web"><img src="sources/images/Boast.png" alt="Boast">Boast</h1>
                <p class="website-aboutm">BOAST was created to help students and individuals to manage their finances in a simple and organized way. The system was designed to address the common challenges of tracking income, recording daily expenses, and staying within a budget using basic calculations. BOAST provides a platform where users can set budget limits, monitor their remaining balance, and view financial summaries. By organizing financial information in one place, the system aims to promote responsible spending, improve money management, and help users make smarter financial decisions.</p>
            </div>
        </div>
    </div>

    <!--<div class="utilities">
        <div class="built-with">
            <h1>Built With:</h1>
        </div>
    </div>-->

    <div class="team-section">
        <div class="team-text">
            <h1>Meet the team behind Boast</h1>
            <p>
                We are a team of Bachelor of Science in Computer Science (BSCS) students from Section 2A, dedicated to developing a practical and accessible budgeting system. Through BOAST, we aim to make tracking income, monitoring expenses, and managing savings simpler, helping users build better financial habits and make smarter financial decisions.
            </p>
        </div>

        <div class="team-cards">
            <div class="team person1">
                <div class="team-picture1">
                    <img src="sources/images/k.jpg" class="team-picture person1">
                </div>
                <b>Fermin, K</b>
                <div class="role-text">UI/UX Designer</div>
                <hr>
                <div class="role-desc">
                    Plans the website's layout, colors, typography, and navigation to make <b>Boast</b> visually appealing, accessible, and easy to use.
                </div>
            </div>
            <div class="team person2">
                <div class="team-picture1">
                    <img src="sources/images/heart.jpg" class="team-picture person2">
                </div>
                <b class="person name">Combinido, Heart</b>
                <div class="role-text">Frontend Developer</div>
                <hr>
                <div class="role-desc">
                    Designs and develops the website's user interface, including the homepage, dashboard, navigation menu, and budgeting pages.
                </div>
            </div>
            <div class="team person4">
                <div class="team-picture1">
                    <img src="sources/images/ako3.jpe" class="team-picture person4">
                </div>
                <b class="person name">Bontogon, James</b>
                <div class="role-text">Lead Developer</div>
                <hr>
                <div class="role-desc">
                    Oversees the project, coordinates team activities, monitors progress, and ensures that <b>Boast</b> meets its objectives and requirements.
                </div>
            </div>
            <div class="team person3">
                <div class="team-picture1">
                    <img src="sources/images/axcel.jpg" class="team-picture person3">
                </div>
                <b class="person name">Ferreras, Axcel</b>
                <div class="role-text">QA Lead</div>
                <hr>
                <div class="role-desc">
                    Oversees the testing lifecycle and ensuring that software releases meet high standards of reliability, functionality, and performance.
                </div>
            </div>
            <div class="team person5">
                <div class="team-picture1">
                    <img src="sources/images/lex.jpg" class="team-picture person5">
                </div>
                <b class="person name">De Guzman, Alexia</b>
                <div class="role-text">System Architect</div>
                <hr>
                <div class="role-desc">
                    Ensuring system scalability, security, and seamless integration of all platform modules.
                </div>
            </div>
        </div>
    </div>
</body>
</html>