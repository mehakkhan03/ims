<?php
session_start();
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "inventory";

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    if (!isset($_SESSION['username'])) {
        header("Location: index.php");
        exit();
    }

    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $username = $_SESSION['username'];

    // Validate new password and confirm password match (server-side)
    if ($new_password !== $confirm_password) {
        $_SESSION['message'] = '<div class="alert alert-danger">New password and confirm password do not match.</div>';
        header("Location: deptdash.php");
        exit();
    }

    // Fetch current password
    $stmt = $conn->prepare("SELECT upass FROM stlog WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    // Verify current password (plain text)
    if ($current_password === $user['upass']) {
        $update_stmt = $conn->prepare("UPDATE stlog SET upass = ? WHERE username = ?");
        $update_stmt->bind_param("ss", $new_password, $username);
        if ($update_stmt->execute()) {
            $_SESSION['message'] = '<div class="alert alert-success">Password updated successfully!</div>';
        } else {
            $_SESSION['message'] = '<div class="alert alert-danger">Failed to update password.</div>';
        }
        $update_stmt->close();
    } else {
        $_SESSION['message'] = '<div class="alert alert-danger">Current password is incorrect.</div>';
    }

    $conn->close();
    header("Location: deptdash.php");
    exit();

} catch (Exception $e) {
    error_log("Exception in change_password_handler.php: " . $e->getMessage());
    $_SESSION['message'] = '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    header("Location: deptdash.php");
    exit();
}
?>