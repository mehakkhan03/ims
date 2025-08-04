<?php
session_start(); // Start the session

// Database connection
$servername = "localhost";
$dbusername = "root";
$dbpassword = ""; // Add your database password if applicable
$dbname = "inventory";

$conn = new mysqli($servername, $dbusername, $dbpassword, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Initialize error message
$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (!empty($username) && !empty($password)) {
        // Check if username starts with s or t
        $firstChar = strtolower(substr($username, 0, 1));
        if ($firstChar === 's' || $firstChar === 't') {
            // Query stlog table
            $sql = "SELECT * FROM stlog WHERE username = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows == 1) {
                $row = $result->fetch_assoc();

                // Compare password with upass
                if ($password === $row['upass']) {
                    // Set session variables
                    $_SESSION['username'] = $row['username'];
                    $_SESSION['name'] = $row['username']; // Using username as name since no name field specified
                    $_SESSION['email'] = $row['uemail'];
                    $_SESSION['uid'] = $row['uid'];

                    // Redirect based on first character
                    if ($firstChar === 's') {
                        header("Location: complain.php");
                    } else { // t
                        header("Location: deptdash.php");
                    }
                    exit();
                } else {
                    $error = "Invalid username or password.";
                }
            } else {
                $error = "Invalid username or password.";
            }
        } else {
            // Original query for other usernames
            $sql = "SELECT * FROM acreate WHERE ausername = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows == 1) {
                $row = $result->fetch_assoc();

                if ($password === $row['apassword']) {
                    $_SESSION['username'] = $row['ausername'];
                    $_SESSION['name'] = $row['aname'];
                    header("Location: dashboard.php");
                    exit();
                } else {
                    $error = "Invalid username or password.";
                }
            } else {
                $error = "Invalid username or password.";
            }
        }
    } else {
        $error = "All fields are required.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Mentor - Bootstrap 4 Admin Dashboard Template</title>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
    <meta name="description" content="Admin template that can be used to build dashboards for CRM, CMS, etc." />
    <meta name="author" content="Potenza Global Solutions" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <!-- app favicon -->
    <link rel="icon" href="assets/img/dln1.png" type="image/x-icon">
    <!-- google fonts -->
    <link href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700" rel="stylesheet">
    <!-- plugin stylesheets -->
    <link rel="stylesheet" type="text/css" href="assets/css/vendors.css" />
    <!-- app style -->
    <link rel="stylesheet" type="text/css" href="assets/css/style.css" />
</head>

<body class="bg-white">
    <div class="app">
        <div class="app-wrap">
            <div class="app-contant">
                <div class="bg-white">
                    <div class="container-fluid p-0">
                        <div class="row no-gutters">
                            <div class="col-sm-6 col-lg-5 col-xxl-3 align-self-center order-2 order-sm-1">
                                <div class="d-flex align-items-center h-100-vh">
                                    <div class="login p-50">
                                        <img src="assets/img/dlogo.png" style="height:140px; width:130px;">
                                        <h1 class="mb-2">DHSK || IMS</h1>
                                        <p>Please login to your account.</p>
                                        <form action="" method="POST" class="mt-3 mt-sm-5">
                                            <div class="row">
                                                <div class="col-12">
                                                    <div class="form-group">
                                                        <label class="control-label">User Name*</label>
                                                        <input type="text" name="username" class="form-control" placeholder="Username" required>
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <div class="form-group">
                                                        <label class="control-label">Password*</label>
                                                        <input type="password" name="password" class="form-control" placeholder="Password" required>
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <div class="d-block d-sm-flex align-items-center">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" id="gridCheck">
                                                            <label class="form-check-label" for="gridCheck">Remember Me</label>
                                                        </div>
                                                        <a href="javascript:void(0);" class="ml-auto">Forgot Password?</a>
                                                    </div>
                                                </div>
                                                <div class="col-12 mt-3">
                                                    <button type="submit" class="btn btn-primary text-uppercase">Sign In</button>
                                                </div>
                                                <div class="col-12 mt-3">
                                                    <p>Don't have an account? <a href="sregister.php">Student Registration</a></p>
                                                </div>
                                            </div>
                                        </form>
                                        <?php if (!empty($error)) echo "<p style='color:red;'>$error</p>"; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-6 col-xxl-9 col-lg-7 bg-gradient o-hidden order-1 order-sm-2">
                                <div class="row align-items-center h-100">
                                    <div class="col-7 mx-auto">
                                        <img class="img-fluid" src="assets/img/bg/login.svg" alt="">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>