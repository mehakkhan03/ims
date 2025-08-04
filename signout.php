<?php
session_start();
$_SESSION = array();
session_destroy();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Sign Out</title>
    <meta http-equiv="refresh" content="2;url=index.php">
    <link rel="icon" href="assets/img/dln1.png" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/style.css"> <!-- Adjust path if needed -->
</head>
<body>
    <div class="container">
        <h2>Successfully Signed Out</h2>
        <p>You will be redirected to the login page in 2 seconds...</p>
        <p>If not redirected, <a href="index.php">click here</a>.</p>
    </div>
</body>
</html>