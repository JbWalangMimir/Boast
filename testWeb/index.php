<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Boast</title>
    <link rel="icon" type="image/png" href="sources/images/BoastN.png">
    <link rel="stylesheet" type="text/css" href="sources/css/index.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
</head>
<body>
    <nav class="navbar">
        <div class="logo-container">
            <a href="index.php"> <img src="sources/images/Boast.png" alt="Boast" class="logo-website" > </a>
            <span class="site-name"><a href="index.php">Boast</span>
        </div>
        <ul class="nav-links">
            <li><a href="index.php" class="l1">Home</a></li>
            <li><a href="about.php" class="l2">About</a></li>
            <li><a href="contact.php" class="l3">Contact Us</a></li>
            <li><button onclick="window.location.href='signup.php'" class="b-sign-up">Sign Up</button></li>
            <li><button onclick="window.location.href='login.php'" class="b-login">Log In</button></li>
        </ul>
    </nav>
    
    <div class="main-desc">
        <div class="desc-container">
            <div class="website-desc"> 
                <h1 class="logo-title-web"><img src="sources/images/BoastN.png">Boast</h1>
                <h1 class="website-descM">Personal Budgeting System</h1>
                <h2 class="website-descm">Take control of your finances with ease. Track your income, manage your expenses, and stay on top of your budget with BOAST, your personal budgeting system.</h2>
                <button onclick="window.location.href='user.php'" class="b-user">Start Budgeting</button>
                <button onclick="document.querySelector('#learn-more').scrollIntoView({behavior: 'smooth', block: 'center'})" class="b-user">Learn More</button>
            </div>
        </div>
    </div>
    <div class="brief-desc" id="brief-desc">
        <div class="b-desc-container">
            <div class="img1-container">
                <img src="sources/images/index-img2.png" class="b-img1">
            </div>
            <div class="text1-container" id="text1-container">
                <h1 class="learn-more" id="learn-more">Track your Income and Expenses with just one click</h1>
                <p class="b-content1">Easily record your income and expenses with BOAST. Keep track of the money you receive and the money you spend, organize your transactions, and monitor your financial activity in one convenient place. Stay informed about where your money comes from and where it goes.
                </p>
            </div>
        </div>
        <div class="b-desc-container">
            <div class="text1-container">
                <h1 class="b-text1">Manage your finances and track your savings</h1>
                <p class="b-content1">Stay on top of your budget with BOAST. Organize your funds, allocate money for different needs, and monitor your savings as they grow. Keep your spending within your budget and work toward your financial goals with better money management.
                </p>
            </div>
            <div class="img1-container">
                <img src="sources/images/index-img3.png" class="b-img1">
            </div>
            
        </div>
        <div class="b-desc-container">
            <div class="img1-container">
                <img src="sources/images/index-img4.png" class="b-img1">
            </div>
            <div class="text1-container" id="text1-container">
                <h1 class="learn-more" id="learn-more">Gain Insights and Make Smarter Financial Decisions</h1>
                <p class="b-content1">Get a clearer understanding of your financial habits through BOAST's reports. Review your income, expenses, savings, and spending trends to identify patterns, evaluate your financial progress, and make informed decisions to manage your money more effectively.
                </p>
            </div>
        </div>
    </div>

    <footer>
        
    </footer>
    
</body>

<?php
  
?>

</html>