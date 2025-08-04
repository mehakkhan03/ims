<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: index.php?message=Please+log+in");
    exit();
}

// Debug: Confirm execution
error_log("Loading addteacher.php");

// Include header
include_once('header.php');

// Database connection
try {
    $mm = new PDO('mysql:host=localhost;dbname=inventory', 'root', '');
    $mm->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . htmlspecialchars($e->getMessage()));
}

// Fetch departments with did and dname
$deptStmt = $mm->prepare("SELECT `did`, `dname` FROM `dept` WHERE did < 22");
$deptStmt->execute();
$departments = $deptStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all teachers with their details and login info
$teacherStmt = $mm->prepare("
    SELECT t.tname, t.did, d.dname, t.tphone, t.temail, s.username, s.upass 
    FROM `teacher` t
    LEFT JOIN `dept` d ON t.did = d.did
    LEFT JOIN `stlog` s ON t.temail = s.uemail
    ORDER BY t.tname
");
$teacherStmt->execute();
$teachers = $teacherStmt->fetchAll(PDO::FETCH_ASSOC);

$result = "";
$showPasswordPopup = false;
$newUsername = "";
$generatedPassword = "";

if (isset($_POST["submit"])) {
    try {
        $mm->beginTransaction();

        // Sanitize and trim inputs
        $tname = trim($_POST["tname"]);
        $did = trim($_POST["department"]);
        $tphone = trim($_POST["tphone"]);
        $temail = trim($_POST["temail"]);

        // Validate inputs
        if (empty($tname)) {
            throw new Exception("Name cannot be empty.");
        }
        if (empty($did)) {
            throw new Exception("Please select a department.");
        }
        $tphone = (string)trim($_POST["tphone"]);
        if (!preg_match('/^[0-9]{10}$/', $tphone)) {
            throw new Exception("Phone number must be exactly 10 digits (0-9).");
        }
        if (!filter_var($temail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email address.");
        }

        // Check if email already exists
        $checkEmailStmt = $mm->prepare("SELECT COUNT(*) FROM `teacher` WHERE `temail` = :temail");
        $checkEmailStmt->execute([':temail' => $temail]);
        $emailExists = $checkEmailStmt->fetchColumn();

        if ($emailExists > 0) {
            $mm->rollBack();
            $result = '<div class="alert alert-danger">Duplicate email entry found: ' . htmlspecialchars($temail) . '. Please use a unique email address.</div>';
        } else {
            // Insert into teacher table
            $insertStmt = $mm->prepare("
                INSERT INTO `teacher` (`tname`, `did`, `tphone`, `temail`) 
                VALUES (:tname, :did, :tphone, :temail)");
            $insertStmt->execute([
                ':tname' => $tname,
                ':did' => $did,
                ':tphone' => $tphone,
                ':temail' => $temail
            ]);

            // Generate username (t + did + random 4 digits)
            $randomNum = sprintf("%04d", rand(0, 9999));
            $username = "t" . $did . $randomNum;

            // Get department name for password generation
            $deptNameStmt = $mm->prepare("SELECT `dname` FROM `dept` WHERE `did` = :did");
            $deptNameStmt->execute([':did' => $did]);
            $deptName = $deptNameStmt->fetchColumn();
            
            // Generate password: first 4 letters of department (lowercase) + 3 random digits + 1 special character
            $deptPrefix = strtolower(substr($deptName, 0, 4));
            $randomDigits = sprintf("%03d", rand(0, 999));
            $specialChars = ['!', '@', '#', '$', '%', '&'];
            $randomSpecialChar = $specialChars[array_rand($specialChars)];
            $generatedPassword = $deptPrefix . $randomDigits . $randomSpecialChar;

            // Insert into stlog table with generated password
            $loginStmt = $mm->prepare("
                INSERT INTO `stlog` (`username`, `uemail`, `uphone`, `upass`) 
                VALUES (:username, :uemail, :uphone, :upass)");
            $loginStmt->execute([
                ':username' => $username,
                ':uemail' => $temail,
                ':uphone' => $tphone,
                ':upass' => $generatedPassword
            ]);

            // Log successful addition
            error_log("Teacher added: $tname, Username: $username, Email: $temail, Phone: $tphone, Password: $generatedPassword");

            $newUsername = $username;
            $showPasswordPopup = true;
            $mm->commit();
            $result = '<div class="alert alert-success">Teacher Added Successfully.</div>';
        }
    } catch (Exception $e) {
        $mm->rollBack();
        $result = '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .password-popup {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            padding: 20px;
            border: 1px solid #ccc;
            box-shadow: 0 0 10px rgba(0,0,0,0.3);
            z-index: 1000;
        }
        .overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 999;
        }
    </style>
</head>
<body>
<div class="app-main" id="main">
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <div class="card card-statistics">
                    <div class="card-header">
                        <div class="card-heading">
                            <h4 class="card-title">Add Teacher</h4>
                        </div>
                        <strong><?php echo $result; ?></strong>
                    </div>
                    <div class="card-body">
                        <form action="" name="myform" id="myform" method="post">
                            <table class="table table-bordered table-responsive">
                                <tr>
                                    <th bgcolor="#F0F3F4">Name</th>
                                    <th bgcolor="#F0F3F4">Department</th>
                                    <th bgcolor="#F0F3F4">Phone</th>
                                    <th bgcolor="#F0F3F4">Email</th>
                                </tr>
                                <tr>
                                    <td><input class="form-control input-sm tname" name="tname" type="text" required /></td>
                                    <td>
                                        <select name="department" class="form-control input-sm department" style="width:150px;" required>
                                            <option value="">Select</option>
                                            <?php foreach ($departments as $dept) { ?>
                                                <option value="<?php echo htmlspecialchars($dept['did']); ?>">
                                                    <?php echo htmlspecialchars($dept['dname']); ?>
                                                </option>
                                            <?php } ?>
                                        </select>
                                    </td>
                                    <td><input class="form-control input-sm tphone" name="tphone" type="text" maxlength="10" required /></td>
                                    <td><input class="form-control input-sm temail" name="temail" type="email" required /></td>
                                </tr>
                            </table>
                            <div style="text-align: right; padding-top: 10px;">
                                <input type="submit" name="submit" id="submit" class="btn btn-info" value="Submit" />
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Teachers List Section -->
        <div class="row">
            <div class="col-md-12">
                <div class="card card-statistics">
                    <div class="card-header">
                        <div class="card-heading">
                            <h4 class="card-title">Teachers List</h4>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($teachers)) { ?>
                            <p>No teachers found.</p>
                        <?php } else { ?>
                            <table class="table table-bordered table-responsive">
                                <thead>
                                    <tr>
                                        <th bgcolor="#F0F3F4">Name</th>
                                        <th bgcolor="#F0F3F4">Department</th>
                                        <th bgcolor="#F0F3F4">Phone</th>
                                        <th bgcolor="#F0F3F4">Email</th>
                                        <th bgcolor="#F0F3F4">Username</th>
                                        <th bgcolor="#F0F3F4">Password</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($teachers as $teacher) { ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($teacher['tname']); ?></td>
                                            <td><?php echo htmlspecialchars($teacher['dname']); ?></td>
                                            <td><?php echo htmlspecialchars($teacher['tphone']); ?></td>
                                            <td><?php echo htmlspecialchars($teacher['temail']); ?></td>
                                            <td><?php echo htmlspecialchars($teacher['username']); ?></td>
                                            <td><?php echo htmlspecialchars($teacher['upass'] ?: 'Not Set'); ?></td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="overlay" id="overlay"></div>
<div class="password-popup" id="passwordPopup">
    <h3>Credentials for <?php echo htmlspecialchars($newUsername); ?></h3>
    <p><strong>Username:</strong> <?php echo htmlspecialchars($newUsername); ?></p>
    <p><strong>Password:</strong> <?php echo htmlspecialchars($generatedPassword); ?></p>
    <button onclick="$('#passwordPopup').hide(); $('#overlay').hide();" class="btn btn-primary">Close</button>
</div>

<footer class="footer">
    <div class="row">
        <div class="col-12 col-sm-6 text-center text-sm-left">
            <p>© Copyright 2019. All rights reserved.</p>
        </div>
        <div class="col col-sm-6 ml-sm-auto text-center text-sm-right">
            <p><a target="_blank" href="https://www.templateshub.net">Templates Hub</a></p>
        </div>
    </div>
</footer>

<script src="assets/js/vendors.js"></script>
<script src="assets/js/app.js"></script>

<script>
$(document).ready(function() {
    <?php if ($showPasswordPopup) { ?>
        $('#overlay').show();
        $('#passwordPopup').show();
    <?php } ?>

    $('#myform').on('submit', function(e) {
        var valid = true;

        if ($('.tname').val().trim() === '') {
            valid = false;
            alert('Name cannot be empty.');
            $('.tname').focus();
        } else if ($('.department').val().trim() === '') {
            valid = false;
            alert('Please select a department.');
            $('.department').focus();
        } else if ($('.tphone').val().trim() === '') {
            valid = false;
            alert('Phone number cannot be empty.');
            $('.tphone').focus();
        } else if (!/^\d{10}$/.test($('.tphone').val().trim())) {
            valid = false;
            alert('Phone number must be exactly 10 digits.');
            $('.tphone').focus();
        } else if ($('.temail').val().trim() === '' || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test($('.temail').val())) {
            valid = false;
            alert('Please enter a valid email address.');
            $('.temail').focus();
        }

        if (!valid) {
            e.preventDefault();
        }
    });

    $('.tphone').on('blur', function() {
        var phone = $(this).val().trim();
        if (phone && !/^\d{10}$/.test(phone)) {
            alert('Phone number must be exactly 10 digits.');
            $(this).focus();
        }
    });
});
</script>
</body>
</html>