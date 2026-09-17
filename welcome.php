<?php

// welcome.php
session_start();

if (isset($_SESSION['username']) && !empty($_SESSION['username'])) {
    header("Location: user/user_dashboard.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>FS Consolidator System</title>

    <link rel="stylesheet" href="login.css?v=<?= time(); ?>">
    <link rel="icon" href="images/MLW%20Logo.png" type="image/png">
    <link rel="stylesheet" href="welcome.css?v=<?= time(); ?>">


    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">


</head>

<body>

    <main class="landing-container">

        <!-- Decorative background -->
        <div class="background-shape shape-one"></div>
        <div class="background-shape shape-two"></div>
        <div class="background-shape shape-three"></div>

        <section class="hero-card">

            <!-- LEFT CONTENT -->
            <div class="hero-content">

                <div class="brand">
                    <img
                        src="images/MLW%20Logo.png"
                        alt="MLFSI Logo"
                        class="brand-logo"
                    >

                    <div class="brand-name">
                        M Lhuillier Financial Services Inc.<br>
                        Financial Systems
                    </div>
                </div>

                <div class="eyebrow">
                    <i class="fas fa-chart-line"></i>
                    Financial Report Platform
                </div>

                <h1>
                    Financial Statement
                    <span>Consolidator</span>
                </h1>

                <p class="hero-subtitle">
                    A centralized platform for consolidating financial
                    statement data, preparing comparative reports, and
                    generating management reports.
                </p>

                <a href="login.php" class="big-login-btn">
                    <i class="fas fa-arrow-right"></i>
                    <span>Sign in to the System</span>
                </a>

                <p class="secondary-text">
                    <i class="fas fa-lock"></i>
                    Authorized users only
                </p>

                <div class="features">

                    <div class="feature-item">
                        <i class="fas fa-chart-column"></i>
                        <span>Comparative Reports</span>
                    </div>

                    <div class="feature-item">
                        <i class="fas fa-file-excel"></i>
                        <span>Report Export</span>
                    </div>

                    <div class="feature-item">
                        <i class="fas fa-user-shield"></i>
                        <span>Access Control</span>
                    </div>

                </div>

            </div>

            <!-- RIGHT PANEL -->
            <aside class="hero-panel">

                <div class="panel-content">

                    <div class="panel-icon">
                        <i class="fa-regular fa-building"></i>
                    </div>

                    <h2>
                        Centralized Financial Reporting
                    </h2>

                    <p>
                        Upload, consolidate financial information and
                        generate standardized comparative reports
                        through one system.
                    </p>

                    <div class="security-box">
                        <i class="fas fa-shield-halved"></i>

                        <span>
                            Your account access is managed by the
                            system administrator.
                        </span>
                    </div>

                </div>

            </aside>

        </section>

        <footer class="landing-footer">
            <p>
                © <?= date("Y") ?> Financial Statement Consolidator System
                · MLFSI
            </p>
            <p>
                All rights reserved.
            </p>
        </footer>

    </main>

</body>
</html>
