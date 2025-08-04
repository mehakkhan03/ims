<?php
// Prevent multiple inclusions
if (defined('THEADER_PHP_INCLUDED')) {
    return;
}
define('THEADER_PHP_INCLUDED', true);

// Database connection for sidebar logic
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "inventory";

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch teacher's did
    $loggedInUsername = $_SESSION['username'] ?? null;
    $showLabLink = false;
    if ($loggedInUsername) {
        $teacherStmt = $conn->prepare("
            SELECT t.did 
            FROM `stlog` s 
            JOIN `teacher` t ON t.temail = s.uemail 
            WHERE s.username = :username");
        $teacherStmt->execute([':username' => $loggedInUsername]);
        $teacherRow = $teacherStmt->fetch(PDO::FETCH_ASSOC);

        if ($teacherRow) {
            $teacherDid = $teacherRow['did'];

            // Check if teacher's did exists in lab table
            $labStmt = $conn->prepare("
                SELECT COUNT(*) 
                FROM `lab` 
                WHERE `did` = :did");
            $labStmt->execute([':did' => $teacherDid]);
            $labCount = $labStmt->fetchColumn();

            if ($labCount > 0) {
                $showLabLink = true;
            }
        }
    }
} catch (Exception $e) {
    echo '<p>Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    $showLabLink = false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>DHSK College || IMS</title>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
    <meta name="description" content="Inventory Management System for DHSK College" />
    <meta name="author" content="DHSK College" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <!-- app favicon -->
    <link rel="icon" href="assets/img/dln1.png" type="image/x-icon">
    <!-- google fonts -->
    <link href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700" rel="stylesheet">
    <!-- plugin stylesheets -->
    <link rel="stylesheet" type="text/css" href="assets/css/vendors.css" />
    <!-- app style -->
    <link rel="stylesheet" type="text/css" href="assets/css/style.css" />
    <!-- Inline CSS for sliding effect -->
    <style>
        /* Sidebar styles */
        .app-navbar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100%;
            width: 250px;
            background-color: #fff;
            z-index: 1000;
            transform: translateX(-100%);
            transition: transform 0.3s ease-in-out;
        }
        .app-navbar.active {
            transform: translateX(0);
        }
        .app-main {
            margin-left: 0;
            transition: none;
        }
        @media (min-width: 769px) {
            .app-navbar {
                transform: translateX(0);
            }
        }
        .loader {
            z-index: 2000;
        }
        .navbar-collapse {
            position: fixed;
            top: 60px;
            left: 0;
            width: 100%;
            background-color: #fff;
            z-index: 999;
            transform: translateX(-100%);
            transition: transform 0.3s ease-in-out;
        }
        .navbar-collapse.show {
            transform: translateX(0);
        }
    </style>
</head>

<body>
    <!-- begin app -->
    <div class="app">
        <!-- begin app-wrap -->
        <div class="app-wrap">
            <!-- begin pre-loader -->
            <div class="loader">
                <div class="h-100 d-flex justify-content-center">
                    <div class="align-self-center">
                        <img src="assets/img/loader/loader.svg" alt="loader">
                    </div>
                </div>
            </div>
            <!-- end pre-loader -->
            <!-- begin app-header -->
            <header class="app-header top-bar">
                <!-- begin navbar -->
                <nav class="navbar navbar-expand-md">
                    <!-- begin navbar-header -->
                    <div class="navbar-header d-flex align-items-center">
                        <a href="javascript:void(0)" class="mobile-toggle"><i class="ti ti-align-right"></i></a>
                        <a class="navbar-brand" href="index.html">
                            <img src="assets/img/logob.png" class="img-fluid logo-desktop" alt="DHSK College Logo" />
                            <img src="assets/img/dln.png" class="img-fluid logo-mobile" alt="DHSK College Logo" />
                        </a>
                    </div>
                    <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
                        <i class="ti ti-align-left"></i>
                    </button>
                </nav>
            </header>
            <!-- end app-header -->
            <!-- begin app-container -->
            <div class="app-container">
                <!-- begin app-navbar -->
                <aside class="app-navbar">
                    <!-- begin sidebar-nav -->
                    <div class="sidebar-nav scrollbar scroll_light">
                        <ul class="metismenu" id="sidebarNav">
                            <li class="nav-static-title">Navigation</li>
                            <li><a href="deptdash.php" aria-expanded="false"><i class="nav-icon fa fa-tachometer"></i><span class="nav-title">Department Dashboard</span></a></li>
                            <li><a href="reqdept.php" aria-expanded="false"><i class="nav-icon fa fa-lightbulb-o"></i><span class="nav-title">Request Department Assets</span></a></li>
                            <li><a href="reqoff.php" aria-expanded="false"><i class="nav-icon fa fa-briefcase"></i><span class="nav-title">Request Office Assets</span></a></li>
                            <li><a href="reqclass.php" aria-expanded="false"><i class="nav-icon fa fa-pencil-square-o"></i><span class="nav-title">Request Classroom Assets</span></a></li>
                            <?php if ($showLabLink): ?>
                                <li><a href="reqlab.php" aria-expanded="false"><i class="nav-icon fa fa-laptop"></i><span class="nav-title">Request Lab Assets</span></a></li>
                            <?php endif; ?>
                            <li><a href="spreq.php" aria-expanded="false"><i class="nav-icon fa fa-star-o"></i><span class="nav-title">Special Asset Requests</span></a></li>
                            <li><a href="complain.php" aria-expanded="false"><i class="nav-icon fa fa-exclamation-triangle"></i><span class="nav-title">Complaint Box</span></a> 
                            <li><a href="signout.php" aria-expanded="false"><i class="nav-icon fa fa-sign-out"></i><span class="nav-title">Sign Out</span></a></li>
                        </ul>
                    </div>
                    <!-- end sidebar-nav -->
                </aside>
                <!-- end app-navbar -->
                <!-- begin app-main (closed in calling script) -->

<script src="assets/js/vendors.js"></script>
<script src="assets/js/app.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const mobileToggle = document.querySelector('.mobile-toggle');
    const navbarToggler = document.querySelector('.navbar-toggler');
    const sidebar = document.querySelector('.app-navbar');
    const navbarCollapse = document.querySelector('#navbarSupportedContent');
    const sidebarToggle = document.querySelector('.sidebar-toggle');

    // Toggle sidebar sliding
    const toggleSidebar = () => {
        console.log("Sidebar toggled");
        sidebar.classList.toggle('active');
    };

    // Toggle navbar collapse
    const toggleNavbar = () => {
        console.log("Navbar toggled");
        navbarCollapse.classList.toggle('show');
    };

    // Attach event listeners
    if (mobileToggle) {
        mobileToggle.addEventListener('click', toggleSidebar);
    }
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', toggleSidebar);
    }
    if (navbarToggler) {
        navbarToggler.addEventListener('click', toggleNavbar);
    }

    // Close sidebar and navbar on outside click (mobile)
    document.addEventListener('click', (e) => {
        if (window.innerWidth <= 768) {
            if (sidebar && !sidebar.contains(e.target) && 
                mobileToggle && !mobileToggle.contains(e.target) && 
                sidebarToggle && !sidebarToggle.contains(e.target) && 
                sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
            }
            if (navbarCollapse && !navbarCollapse.contains(e.target) && 
                navbarToggler && !navbarToggler.contains(e.target) && 
                navbarCollapse.classList.contains('show')) {
                navbarCollapse.classList.remove('show');
            }
        }
    });

    // Hide loader after page load
    const loader = document.querySelector('.loader');
    window.addEventListener('load', () => {
        if (loader) {
            loader.style.display = 'none';
        }
    });

    // Adjust sidebar state on resize
    window.addEventListener('resize', () => {
        if (window.innerWidth > 768) {
            if (sidebar) {
                sidebar.classList.remove('active');
            }
            if (navbarCollapse) {
                navbarCollapse.classList.remove('show');
            }
        }
    });
});
</script>
</body>
</html>