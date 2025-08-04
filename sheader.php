<!DOCTYPE html>
<html lang="en">
<head>
    <title>DHSK College || IMS</title>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
    <meta name="description" content="Admin template that can be used to build dashboards for CRM, CMS, etc." />
    <meta name="author" content="Potenza Global Solutions" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <!-- app favicon -->
    <link rel="shortcut icon" href="assets/img/favicon.ico">
    <!-- google fonts -->
    <link href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700" rel="stylesheet">
    <!-- plugin stylesheets -->
    <link rel="stylesheet" type="text/css" href="assets/css/vendors.css" />
    <!-- app style -->
    <link rel="stylesheet" type="text/css" href="assets/css/style.css" />
    <style>
        /* Responsive Navbar */
        .navbar-header {
            width: 100%;
            justify-content: space-between;
        }
        .navbar-brand img {
            max-height: 40px; /* Scale logo */
            width: auto;
        }
        .navbar-toggler {
            border: none;
            padding: 0.5rem;
        }
        .navbar-toggler i {
            font-size: 1.5rem;
        }

        /* Responsive Sidebar */
        .app-navbar {
            position: fixed;
            top: 0;
            left: -260px; /* Hidden by default on mobile */
            width: 260px;
            height: 100%;
            background: #fff;
            transition: left 0.3s ease;
            z-index: 1000;
        }
        .app-navbar.active {
            left: 0; /* Show sidebar on mobile */
        }
        .sidebar-nav {
            padding: 1rem;
        }
        .metismenu li a {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            font-size: 1rem;
            color: #333;
        }
        .metismenu li a i {
            margin-right: 0.5rem;
        }

        /* Main content adjustment */
        .app-main {
            margin-left: 0;
            transition: margin-left 0.3s ease;
        }
        .app-main.sidebar-open {
            margin-left: 260px; /* Adjust for sidebar on desktop */
        }

        /* Mobile toggle button */
        .mobile-toggle {
            display: none;
        }

        /* Media Queries */
        @media (max-width: 768px) {
            .navbar-brand .logo-desktop {
                display: none;
            }
            .navbar-brand .logo-mobile {
                display: block;
            }
            .mobile-toggle {
                display: block;
                font-size: 1.5rem;
                padding: 0.5rem;
            }
            .app-main {
                margin-left: 0 !important;
            }
            .navbar-collapse {
                position: fixed;
                top: 60px;
                left: -100%;
                width: 100%;
                height: calc(100vh - 60px);
                background: #fff;
                transition: left 0.3s ease;
                z-index: 999;
            }
            .navbar-collapse.show {
                left: 0;
            }
        }

        @media (min-width: 769px) {
            .app-navbar {
                left: 0; /* Always visible on desktop */
            }
            .navbar-brand .logo-mobile {
                display: none;
            }
            .navbar-brand .logo-desktop {
                display: block;
            }
        }

        /* Loader */
        .loader {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.8);
            z-index: 2000;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .loader img {
            max-width: 100px;
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
                        <a href="javascript:void(0)" class="mobile-toggle"><i class="ti ti-menu"></i></a>
                        <a class="navbar-brand" href="index.html">
                            <img src="assets/img/logob.png" class="img-fluid logo-desktop" alt="logo" />
                            <img src="assets/img/dln.png" class="img-fluid logo-mobile" alt="logo" />
                        </a>
                    </div>
                    <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
                        <i class="ti ti-menu"></i>
                    </button>
                    <!-- end navbar-header -->
                    <!-- begin navigation -->
                    <div class="collapse navbar-collapse" id="navbarSupportedContent">
                        <ul class="navbar-nav ml-auto">
                            <li class="nav-item">
                                <a class="nav-link" href="signout.php">Sign Out</a>
                            </li>
                        </ul>
                    </div>
                    <!-- end navigation -->
                </nav>
                <!-- end navbar -->
            </header>
            <!-- end app-header -->
            <!-- begin app-container -->
            <div class="app-container">
                <!-- begin app-navbar -->
                <aside class="app-navbar">
                    <!-- begin sidebar-nav -->
                    <div class="sidebar-nav scrollbar scroll_light">
                        <ul class="metismenu" id="sidebarNav">
                            <li><a href="complain.php" aria-expanded="false"><i class="nav-icon fa fa-exclamation-triangle"></i><span class="nav-title">Complaint Box</span></a></li>
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
    const mainContent = document.querySelector('.app-main');
    const navbarCollapse = document.querySelector('#navbarSupportedContent');

    // Toggle sidebar on mobile
    mobileToggle.addEventListener('click', () => {
        sidebar.classList.toggle('active');
        mainContent.classList.toggle('sidebar-open');
    });

    // Toggle navbar collapse
    navbarToggler.addEventListener('click', () => {
        navbarCollapse.classList.toggle('show');
    });

    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', (e) => {
        if (window.innerWidth <= 768 && 
            !sidebar.contains(e.target) && 
            !mobileToggle.contains(e.target) && 
            sidebar.classList.contains('active')) {
            sidebar.classList.remove('active');
            mainContent.classList.remove('sidebar-open');
        }
    });

    // Hide loader after page load
    const loader = document.querySelector('.loader');
    window.addEventListener('load', () => {
        loader.style.display = 'none';
    });
});
</script>
</body>
</html>