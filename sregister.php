<?php
session_start();

// Enable error logging
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', 'C:/wamp64/logs/php_errors.log');

// Generate CSRF token if not set
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "inventory";

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    error_log("Connection failed: " . $e->getMessage());
    die("Connection failed. Please try again later.");
}

// Initialize variables
$password_errors = [];
$password_success = "";
$errors = [];
$success = "";
$generated_username = "";

// Validate CSRF token
function validate_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Handle password submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['set_password'])) {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        $password_errors[] = "Invalid CSRF token.";
    } else {
        $username = trim($_POST['username']);
        $upass = trim($_POST['upass']);
        $confirm_upass = trim($_POST['confirm_upass']);

        // Validate username
        if (empty($username)) {
            $password_errors[] = "Username is required.";
        } else {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM stlog WHERE username = :username");
            $stmt->execute(['username' => $username]);
            if ($stmt->fetchColumn() == 0) {
                $password_errors[] = "Invalid username.";
            }
        }

        // Validate password
        if (empty($upass)) {
            $password_errors[] = "Password is required.";
        } elseif (strlen($upass) < 8) {
            $password_errors[] = "Password must be at least 8 characters long.";
        } elseif (strlen($upass) > 50) {
            $password_errors[] = "Password cannot exceed 50 characters.";
        } elseif (!preg_match("/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/", $upass)) {
            $password_errors[] = "Password must include uppercase, lowercase, number, and special character.";
        } elseif ($upass !== $confirm_upass) {
            $password_errors[] = "Passwords do not match.";
        }

        // Update password in stlog table
        if (empty($password_errors)) {
            try {
                $stmt = $conn->prepare("UPDATE stlog SET upass = :upass WHERE username = :username");
                $stmt->execute(['upass' => $upass, 'username' => $username]);
                $password_success = "Password set successfully!";
                $generated_username = $username;
            } catch(PDOException $e) {
                error_log("Password update failed: " . $e->getMessage());
                $password_errors[] = "Failed to set password. Please try again.";
            }
        }
    }
}

// Handle registration form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['set_password'])) {
    if (!validate_csrf_token($_POST['csrf_token'])) {
        $errors[] = "Invalid CSRF token.";
    } else {
        $sname = trim($_POST['sname']);
        $did = $_POST['did'];
        $course_id = $_POST['course_id'];
        $semester = $_POST['semester'];
        $rollNumber = trim($_POST['rollNumber']);
        $phone = trim($_POST['phone']);
        $email = trim($_POST['email']);

        // Validate full name
        if (empty($sname)) {
            $errors[] = "Full name is required.";
        } elseif (strlen($sname) < 2 || strlen($sname) > 50) {
            $errors[] = "Full name must be 2-50 characters long.";
        } elseif (!preg_match("/^[a-zA-Z\s'-]+$/", $sname)) {
            $errors[] = "Full name can only contain letters, spaces, hyphens, or apostrophes.";
        }

        // Validate department
        if (empty($did)) {
            $errors[] = "Department is required.";
        } else {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM dept WHERE did = :did");
            $stmt->execute(['did' => $did]);
            if ($stmt->fetchColumn() == 0) {
                $errors[] = "Invalid department selected.";
            }
        }

        // Validate course
        if (empty($course_id)) {
            $errors[] = "Course is required.";
        } else {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM course WHERE course_id = :course_id");
            $stmt->execute(['course_id' => $course_id]);
            if ($stmt->fetchColumn() == 0) {
                $errors[] = "Invalid course selected.";
            }
        }

        // Validate semester
        if (empty($semester)) {
            $errors[] = "Semester is required.";
        } elseif (!is_numeric($semester) || $semester < 1 || $semester > 8) {
            $errors[] = "Semester must be a number between 1 and 8.";
        }

        // Validate roll number
        if (empty($rollNumber)) {
            $errors[] = "Roll number is required.";
        } elseif (strlen($rollNumber) > 20) {
            $errors[] = "Roll number cannot exceed 20 characters.";
        } elseif (!preg_match("/^[a-zA-Z0-9]+$/", $rollNumber)) {
            $errors[] = "Roll number can only contain letters and numbers.";
        }

        // Validate phone number
        if (empty($phone)) {
            $errors[] = "Phone number is required.";
        } elseif (!preg_match("/^\d{10}$/", $phone)) {
            $errors[] = "Phone number must be exactly 10 digits.";
        }

        // Validate email
        if (empty($email)) {
            $errors[] = "Email is required.";
        } elseif (strlen($email) > 100) {
            $errors[] = "Email cannot exceed 100 characters.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format.";
        }

        // Check for duplicate roll number or email
        if (empty($errors)) {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM students WHERE rollNumber = :rollNumber OR email = :email");
            $stmt->execute(['rollNumber' => $rollNumber, 'email' => $email]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = "Roll number or email already registered.";
            }
        }

        // Generate and validate username
        if (empty($errors)) {
            $formatted_semester = sprintf("%02d", $semester);
            $generated_username = "s" . $did . $course_id . $formatted_semester . $rollNumber . "25";

            // Check username length (assuming stlog.username is VARCHAR(50))
            if (strlen($generated_username) > 50) {
                $errors[] = "Generated username is too long. Please use a shorter roll number.";
            }

            // Check for duplicate username
            $stmt = $conn->prepare("SELECT COUNT(*) FROM stlog WHERE username = :username");
            $stmt->execute(['username' => $generated_username]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = "Generated username is already taken. Please contact support.";
            }
        }

        // Insert into database
        if (empty($errors)) {
            try {
                $conn->beginTransaction();

                // Insert into students table
                $stmt = $conn->prepare("INSERT INTO students (sname, did, course_id, semester, rollNumber, phone, email) 
                                      VALUES (:sname, :did, :course_id, :semester, :rollNumber, :phone, :email)");
                $stmt->execute([
                    'sname' => $sname,
                    'did' => $did,
                    'course_id' => $course_id,
                    'semester' => $semester,
                    'rollNumber' => $rollNumber,
                    'phone' => $phone,
                    'email' => $email
                ]);

                // Insert into stlog table
                $stmt = $conn->prepare("INSERT INTO stlog (username, uemail, uphone, upass) 
                                      VALUES (:username, :uemail, :uphone, '')");
                $stmt->execute([
                    'username' => $generated_username,
                    'uemail' => $email,
                    'uphone' => $phone
                ]);

                $conn->commit();
                $success = "Registration successful! Your username is: " . htmlspecialchars($generated_username);
            } catch(PDOException $e) {
                $conn->rollBack();
                error_log("Registration failed: " . $e->getMessage());
                $errors[] = "Registration failed. Please try again.";
            }
        }
    }
}

// Fetch departments and courses
$departments = $conn->query("SELECT did, dname FROM dept")->fetchAll(PDO::FETCH_ASSOC);
$courses = $conn->query("SELECT course_id, course_name FROM course")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Student Registration Portal</title>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
    <meta name="description" content="Student registration portal for university enrollment" />
    <meta name="author" content="Student Portal" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <link rel="shortcut icon" href="assets/img/favicon.ico">
    <link href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="assets/css/vendors.css" />
    <link rel="stylesheet" type="text/css" href="assets/css/style.css" />
    <style>
        @media (max-width: 767px) {
            .logo-img {
                height: 100px !important;
                width: 90px !important;
            }
            .register {
                padding: 2rem !important;
            }
        }
    </style>
</head>
<body class="bg-white">
    <div class="app">
        <div class="app-wrap">
            <div class="loader">
                <div class="h-100 d-flex justify-content-center">
                    <div class="align-self-center">
                        <img src="assets/img/loader/loader.svg" alt="loader">
                    </div>
                </div>
            </div>

            <div class="app-contant">
                <div class="bg-white">
                    <div class="container-fluid p-0">
                        <div class="row no-gutters flex-column flex-md-row">
                            <div class="col-12 col-md-6 align-self-center order-2 order-md-1">
                                <div class="d-flex align-items-center h-100-vh">
                                    <div class="register p-5 w-100">
                                        <div class="d-flex flex-column flex-md-row align-items-center">
                                            <div class="col-auto">
                                                <img src="assets/img/dlogo.png" class="logo-img" style="height:140px; width:130px;" alt="DHSK Logo">
                                            </div>
                                            <div class="col-auto d-flex flex-column mt-3 mt-md-0">
                                                <h1 class="mb-2">DHSK || IMS</h1>
                                                <h2 class="mb-2">Student Portal</h2>
                                            </div>
                                        </div>
                                        <p class="mt-3">Welcome, Please register as a student.</p>
                                        <?php if (!empty($errors)): ?>
                                            <div class="alert alert-danger">
                                                <?php foreach ($errors as $error): ?>
                                                    <p><?php echo htmlspecialchars($error); ?></p>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($success && empty($password_success)): ?>
                                            <div class="alert alert-success">
                                                <p><?php echo htmlspecialchars($success); ?></p>
                                            </div>
                                        <?php endif; ?>
                                        <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="POST" class="mt-2 mt-sm-5">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <div class="row">
                                                <div class="col-12">
                                                    <div class="form-group">
                                                        <label class="control-label">Full Name*</label>
                                                        <input type="text" name="sname" class="form-control" placeholder="Full Name" value="<?php echo isset($_POST['sname']) ? htmlspecialchars($_POST['sname']) : ''; ?>" required />
                                                    </div>
                                                </div>
                                                <div class="col-12 col-md-6">
                                                    <div class="form-group">
                                                        <label class="control-label">Department*</label>
                                                        <select name="did" class="form-control" required>
                                                            <option value="" disabled selected>Select Department</option>
                                                            <?php foreach ($departments as $dept): ?>
                                                                <option value="<?php echo $dept['did']; ?>" <?php echo isset($_POST['did']) && $_POST['did'] == $dept['did'] ? 'selected' : ''; ?>>
                                                                    <?php echo htmlspecialchars($dept['dname']); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-12 col-md-6">
                                                    <div class="form-group">
                                                        <label class="control-label">Course*</label>
                                                        <select name="course_id" class="form-control" required>
                                                            <option value="" disabled selected>Select Course</option>
                                                            <?php foreach ($courses as $course): ?>
                                                                <option value="<?php echo $course['course_id']; ?>" <?php echo isset($_POST['course_id']) && $_POST['course_id'] == $course['course_id'] ? 'selected' : ''; ?>>
                                                                    <?php echo htmlspecialchars($course['course_name']); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-12 col-md-6">
                                                    <div class="form-group">
                                                        <label class="control-label">Semester*</label>
                                                        <input type="number" name="semester" class="form-control" placeholder="Semester" min="1" max="8" value="<?php echo isset($_POST['semester']) ? htmlspecialchars($_POST['semester']) : ''; ?>" required />
                                                    </div>
                                                </div>
                                                <div class="col-12 col-md-6">
                                                    <div class="form-group">
                                                        <label class="control-label">Roll Number*</label>
                                                        <input type="text" name="rollNumber" class="form-control" placeholder="Roll Number" value="<?php echo isset($_POST['rollNumber']) ? htmlspecialchars($_POST['rollNumber']) : ''; ?>" required />
                                                    </div>
                                                </div>
                                                <div class="col-12 col-md-6">
                                                    <div class="form-group">
                                                        <label class="control-label">Phone*</label>
                                                        <input type="tel" name="phone" class="form-control" placeholder="Enter 10-digit phone number" 
                                                               maxlength="10" pattern="\d{10}" title="Phone number must be exactly 10 digits" 
                                                               onkeypress="return (event.charCode !=8 && event.charCode ==0 || (event.charCode >= 48 && event.charCode <= 57))" 
                                                               value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>" required />
                                                    </div>
                                                </div>
                                                <div class="col-12 col-md-6">
                                                    <div class="form-group">
                                                        <label class="control-label">Email*</label>
                                                        <input type="email" name="email" class="form-control" placeholder="Email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required />
                                                    </div>
                                                </div>
                                                <div class="col-12 mt-3">
                                                    <button type="submit" class="btn btn-primary text-uppercase">Register</button>
                                                </div>
                                                <div class="col-12 mt-3">
                                                    <p>Already registered? <a href="index.php">Sign In</a></p>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12 col-md-6 bg-gradient o-hidden order-1 order-md-2">
                                <div class="row align-items-center h-100">
                                    <div class="col-12 col-md-6 mx-auto">
                                        <img class="img-fluid registration-img" src="assets/img/bg/login.svg" alt="Student Registration">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Password Modal -->
            <div class="modal fade" id="passwordModal" tabindex="-1" role="dialog" aria-labelledby="passwordModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="passwordModalLabel">Set Your Password</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">×</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <?php if (!empty($password_errors)): ?>
                                <div class="alert alert-danger">
                                    <?php foreach ($password_errors as $error): ?>
                                        <p><?php echo htmlspecialchars($error); ?></p>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($password_success): ?>
                                <div class="alert alert-success">
                                    <p><?php echo htmlspecialchars($password_success); ?></p>
                                </div>
                            <?php endif; ?>
                            <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="POST">
                                <input type="hidden" name="set_password" value="1">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="username" value="<?php echo htmlspecialchars($generated_username); ?>">
                                <div class="form-group">
                                    <label for="upass">Password</label>
                                    <input type="password" name="upass" id="upass" class="form-control" placeholder="Enter password" required>
                                </div>
                                <div class="form-group">
                                    <label for="confirm_upass">Confirm Password</label>
                                    <input type="password" name="confirm_upass" id="confirm_upass" class="form-control" placeholder="Confirm password" required>
                                </div>
                                <button type="submit" class="btn btn-primary">Set Password</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Username Reminder Modal -->
            <div class="modal fade" id="usernameReminderModal" tabindex="-1" role="dialog" aria-labelledby="usernameReminderModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="usernameReminderModalLabel">Username Reminder</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">×</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <p>Your username is: <strong><?php echo htmlspecialchars($generated_username); ?></strong>. Please note it down.</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-primary" data-dismiss="modal">OK</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/js/vendors.js"></script>
    <script src="assets/js/app.js"></script>
    <script>
        // Trigger password modal after successful registration
        <?php if ($success && empty($password_success)): ?>
            $(document).ready(function() {
                $('#passwordModal').modal('show');
            });
        <?php endif; ?>

        // Trigger username reminder modal after successful password set
        <?php if ($password_success): ?>
            $(document).ready(function() {
                $('#passwordModal').modal('hide');
                $('#usernameReminderModal').modal('show');
            });
        <?php endif; ?>
    </script>
</body>
</html>