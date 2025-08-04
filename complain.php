<?php
ob_start();
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'path/to/error.log');

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Check if user is logged in
if (!isset($_SESSION['uid'])) {
    header("Location: index.php");
    exit();
}

// Database connection
$mysqli = new mysqli("localhost", "root", "", "inventory");
if ($mysqli->connect_error) {
    error_log("Database connection failed: " . $mysqli->connect_error);
    die("System error. Please try again later.");
}

// Fetch username from stlog
$user_query = $mysqli->prepare("SELECT username AS uname, uemail FROM stlog WHERE uid = ?");
$user_query->bind_param("i", $_SESSION['uid']);
$user_query->execute();
$user_result = $user_query->get_result();
if ($user_result && $user_result->num_rows > 0) {
    $user_data = $user_result->fetch_assoc();
    $uname = $user_data['uname'] ?? null;
    $uemail = $user_data['uemail'] ?? null;
} else {
    $uname = null;
    $uemail = null;
    error_log("No user found in stlog for uid: " . $_SESSION['uid']);
}
$user_query->close();

// Redirect to login if username is not found
if (!$uname) {
    header("Location: index.php");
    exit();
}

// Check if user is a teacher
$is_teacher = strtolower(substr($uname, 0, 1)) === 't';

// Log for debugging
error_log("Retrieved uname: " . $uname . ", uemail: " . ($uemail ?? 'null') . ", is_teacher: " . ($is_teacher ? 'true' : 'false') . " for uid: " . $_SESSION['uid']);

$result1 = "";

// Get current date components
$currentDate = date('d/m/Y');

// Fetch department data
$dept_query = "SELECT * FROM dept";
$dept_result = $mysqli->query($dept_query);
if (!$dept_result) {
    error_log("Department query failed: " . $mysqli->error);
    $dept_result = false;
}

// Fetch public areas data from 'public' table
$pa_query = "SELECT * FROM public";
$pa_result = $mysqli->query($pa_query);
if (!$pa_result) {
    error_log("Public areas query failed: " . $mysqli->error);
    $pa_result = false;
}

// Check if department has a lab
$has_lab = false;
$selected_did = filter_input(INPUT_POST, 'did', FILTER_VALIDATE_INT);
if ($selected_did && isset($_POST['check_lab'])) {
    $stmt = $mysqli->prepare("SELECT COUNT(*) FROM lab WHERE did = ?");
    $stmt->bind_param("i", $selected_did);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $has_lab = $count > 0;
    $stmt->close();
}

// Handle complaint submission
if (isset($_POST['submit_complaint'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        error_log("CSRF Token Mismatch: POST=" . ($_POST['csrf_token'] ?? 'unset') . ", SESSION=" . $_SESSION['csrf_token']);
        die("Invalid CSRF token");
    }

    // Validate and sanitize inputs
    $complaint_type = htmlspecialchars($_POST['complaint_type'] ?? '');
    $did = filter_input(INPUT_POST, 'did', FILTER_VALIDATE_INT) ?: "NA";
    $pa_id = filter_input(INPUT_POST, 'pa_id', FILTER_VALIDATE_INT) ?: "NA";
    $aid = filter_input(INPUT_POST, 'aid', FILTER_VALIDATE_INT);
    $descrip = filter_input(INPUT_POST, 'descrip', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $uid = $_SESSION['uid'];
    $status = "Pending";
    $locationtype = ($complaint_type === 'department') ? htmlspecialchars($_POST['location_type'] ?? 'NA') : "public area";
    $roomnum = ($complaint_type === 'department' && $_POST['location_type'] === 'class') ? htmlspecialchars($_POST['classroom_number'] ?? 'NA') : "NA";

    // Parse date input in DD/MM/YYYY format
    $date_input = trim($_POST['date'] ?? '');
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/', $date_input, $matches)) {
        $day = (int) $matches[1];
        $month = (int) $matches[2];
        $year = (int) $matches[3];
        if ($year < 100) {
            $year += ($year < 50) ? 2000 : 1900;
        }
    } else {
        $day = $month = $year = false;
    }

    // Debug output
    error_log("Parsed date: Day=$day, Month=$month, Year=$year from input '$date_input'");
    error_log("Complaint Type: $complaint_type, DID: $did, PA_ID: $pa_id, LocationType: $locationtype, RoomNum: $roomnum");

    // Validate inputs
    if ($complaint_type === 'department' && $did === "NA") {
        $result1 = '<div class="alert alert-danger">Please select a department</div>';
    } elseif ($complaint_type === 'department' && $locationtype === 'class' && $roomnum === 'NA') {
        $result1 = '<div class="alert alert-danger">Please enter a classroom number for class location</div>';
    } elseif ($complaint_type === 'department' && $locationtype === 'faculty office' && !$is_teacher) {
        $result1 = '<div class="alert alert-danger">Faculty office is only available for teachers</div>';
    } elseif ($complaint_type === 'public_area' && $pa_id === "NA") {
        $result1 = '<div class="alert alert-danger">Please select a public area</div>';
    } elseif (!$aid || empty($descrip)) {
        $result1 = '<div class="alert alert-danger">Please select an asset and provide a description</div>';
    } elseif ($day === false || $month === false || $year === false || !checkdate($month, $day, $year)) {
        $result1 = '<div class="alert alert-danger">Please enter a valid date in DD/MM/YYYY format (e.g., 08/04/2025)</div>';
    } else {
        // Set NA values based on complaint type
        if ($complaint_type === 'department') {
            $pa_id = "NA";
        } else {
            $did = "NA";
        }

        // Insert complaint
        $stmt = $mysqli->prepare("INSERT INTO complain (did, username, descrip, aid, pa_id, status, locationtype, roomnum, date, month, year) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssissssiii", $did, $uname, $descrip, $aid, $pa_id, $status, $locationtype, $roomnum, $day, $month, $year);

        if ($stmt->execute()) {
            $co_id = $mysqli->insert_id;
            $result1 = '<div class="alert alert-success">Complaint Submitted Successfully (ID: ' . $co_id . ')</div>';
        } else {
            error_log("Complaint submission failed: " . $stmt->error);
            $result1 = '<div class="alert alert-danger">Error submitting complaint: ' . $stmt->error . '</div>';
        }
        $stmt->close();
    }
}

// Fetch M's complaints
$user_complaints_query = $mysqli->prepare("
    SELECT c.co_id, c.descrip, c.status, c.date, c.month, c.year, 
           a.aname, d.dname, p.pa_name, c.locationtype, c.roomnum
    FROM complain c
    LEFT JOIN asset a ON c.aid = a.aid
    LEFT JOIN dept d ON c.did = d.did
    LEFT JOIN public p ON c.pa_id = p.pa_id
    WHERE c.username = ?
    ORDER BY c.co_id DESC
");
$user_complaints_query->bind_param("s", $uname);
$user_complaints_query->execute();
$user_complaints_result = $user_complaints_query->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Defective Asset</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="icon" href="assets/img/dln1.png" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            font-family: Arial, sans-serif;
        }
        .container-fluid {
            padding: 15px;
        }
        .card {
            margin-bottom: 20px;
            border-radius: 8px;
        }
        .card-header {
            background-color: #f8f9fa;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            margin-bottom: 5px;
            font-weight: 500;
        }
        .form-control, .btn {
            border-radius: 5px;
        }
        .btn-danger {
            background-color: #dc3545;
            border-color: #dc3545;
        }
        .table-responsive {
            margin-top: 20px;
        }
        .table th, .table td {
            vertical-align: middle;
        }
        .alert {
            margin-top: 15px;
        }
        .footer {
            padding: 10px 0;
            background-color: #f8f9fa;
            margin-top: 20px;
        }
        .form-group input[type="radio"] {
            margin-right: 10px;
        }
        .form-group label[for] {
            margin-right: 20px;
        }
        @media (max-width: 768px) {
            .container-fluid {
                padding: 10px;
            }
            .card-header h4 {
                font-size: 1.2rem;
            }
            .form-group label {
                font-size: 0.9rem;
            }
            .form-control, .btn {
                font-size: 0.9rem;
            }
            .table th, .table td {
                font-size: 0.85rem;
                padding: 8px;
            }
            .table-responsive {
                font-size: 0.85rem;
            }
            .form-group input[type="radio"] + label {
                display: block;
                margin-bottom: 10px;
            }
            .footer {
                text-align: center;
            }
            .footer .col-sm-6 {
                margin-bottom: 10px;
            }
        }
        @media (max-width: 576px) {
            .btn-danger {
                width: 100%;
            }
            .card {
                margin-bottom: 15px;
            }
            .table th, .table td {
                font-size: 0.8rem;
            }
        }
    </style>
</head>
<body>
<div class="app-main" id="main">
    <?php
    if (strtolower(substr($uname, 0, 1)) === 's') {
        include('sheader.php');
    } else {
        include('theader.php');
    }
    ?>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card card-statistics">
                    <div class="card-header">
                        <h4 class="card-title">Report Defective Asset</h4>
                        <strong><?php echo $result1; ?></strong>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <div class="form-group">
                                <label for="username">Username</label>
                                <input type="text" id="username" value="<?php echo htmlspecialchars($uname); ?>" class="form-control" readonly>
                                <input type="hidden" name="uid" value="<?php echo htmlspecialchars($_SESSION['uid']); ?>">
                            </div>
                            <div class="form-group">
                                <label>Complaint Type</label><br>
                                <input type="radio" name="complaint_type" value="department" id="complaint_department" required onclick="toggleSelection()">
                                <label for="complaint_department">Department</label>
                                <input type="radio" name="complaint_type" value="public_area" id="complaint_public_area" onclick="toggleSelection()">
                                <label for="complaint_public_area">Public Area</label>
                            </div>
                            <div class="form-group" id="department_div" style="display: none;">
                                <label for="did">Department</label>
                                <select name="did" id="did" class="form-control" onchange="this.form.submit(); this.form.elements['check_lab'].value = '1';">
                                    <option value="">Select Department</option>
                                    <?php 
                                    if ($dept_result && $dept_result->num_rows > 0) {
                                        while ($dept_row = $dept_result->fetch_assoc()) { 
                                    ?>
                                        <option value="<?php echo $dept_row['did']; ?>" <?php echo $selected_did == $dept_row['did'] ? 'selected' : ''; ?>>
                                            <?php echo $dept_row['dname']; ?>
                                        </option>
                                    <?php 
                                        }
                                    } else {
                                        echo '<option value="">No departments found</option>';
                                    }
                                    ?>
                                </select>
                                <input type="hidden" name="check_lab" value="0">
                            </div>
                            <?php if ($selected_did && isset($_POST['check_lab'])): ?>
                                <?php if ($has_lab): ?>
                                    <div class="form-group" id="location_type_div">
                                        <label>Location Type</label><br>
                                        <input type="radio" name="location_type" value="class" id="location_class" required onclick="toggleLocationInput()">
                                        <label for="location_class">Class</label>
                                        <input type="radio" name="location_type" value="lab" id="location_lab" onclick="toggleLocationInput()">
                                        <label for="location_lab">Lab</label>
                                        <input type="radio" name="location_type" value="corridor" id="location_corridor" onclick="toggleLocationInput()">
                                        <label for="location_corridor">Corridor</label>
                                        <?php if ($is_teacher): ?>
                                            <input type="radio" name="location_type" value="faculty office" id="location_faculty_office" onclick="toggleLocationInput()">
                                            <label for="location_faculty_office">Faculty Office</label>
                                        <?php endif; ?>
                                    </div>
                                    <div class="form-group" id="classroom_div" style="display: none;">
                                        <label for="classroom_number">Classroom Number</label>
                                        <input type="text" name="classroom_number" id="classroom_number" class="form-control" placeholder="Enter classroom number">
                                    </div>
                                <?php else: ?>
                                    <div class="form-group" id="location_type_div">
                                        <label>Location Type</label><br>
                                        <input type="radio" name="location_type" value="class" id="location_class" required onclick="toggleLocationInput()">
                                        <label for="location_class">Class</label>
                                        <input type="radio" name="location_type" value="corridor" id="location_corridor" onclick="toggleLocationInput()">
                                        <label for="location_corridor">Corridor</label>
                                        <?php if ($is_teacher): ?>
                                            <input type="radio" name="location_type" value="faculty office" id="location_faculty_office" onclick="toggleLocationInput()">
                                            <label for="location_faculty_office">Faculty Office</label>
                                        <?php endif; ?>
                                    </div>
                                    <div class="form-group" id="classroom_div" style="display: none;">
                                        <label for="classroom_number">Classroom Number</label>
                                        <input type="text" name="classroom_number" id="classroom_number" class="form-control" placeholder="Enter classroom number">
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                            <div class="form-group" id="public_area_div" style="display: none;">
                                <label for="pa_id">Public Area</label>
                                <select name="pa_id" id="pa_id" class="form-control">
                                    <option value="">Select Public Area</option>
                                    <?php 
                                    if ($pa_result && $pa_result->num_rows > 0) {
                                        while ($pa_row = $pa_result->fetch_assoc()) { 
                                    ?>
                                        <option value="<?php echo $pa_row['pa_id']; ?>">
                                            <?php echo $pa_row['pa_name']; ?>
                                        </option>
                                    <?php 
                                        }
                                    } else {
                                        echo '<option value="">No public areas found</option>';
                                    }
                                    ?>
                                </select>
                                <input type="hidden" name="location_type" value="public area" id="location_type_hidden">
                            </div>
                            <div class="form-group">
                                <label for="aid">Asset</label>
                                <select name="aid" id="aid" class="form-control" required>
                                    <option value="">Select Asset</option>
                                    <?php 
                                    $assets_query = "SELECT * FROM asset";
                                    $assets_result = $mysqli->query($assets_query);
                                    if (!$assets_result) {
                                        error_log("Assets query failed: " . $mysqli->error);
                                        $result1 = '<div class="alert alert-danger">Error loading assets. Please try again.</div>';
                                    }
                                    if ($assets_result && $assets_result->num_rows > 0) {
                                        while ($asset_row = $assets_result->fetch_assoc()) { 
                                    ?>
                                        <option value="<?php echo $asset_row['aid']; ?>">
                                            <?php echo $asset_row['aname'] . " (" . $asset_row['atype'] . ")"; ?>
                                        </option>
                                    <?php 
                                        }
                                    } else {
                                        echo '<option value="">No assets found</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="date">Date (DD/MM/YYYY)</label>
                                <input type="text" class="form-control" name="date" id="date" placeholder="DD/MM/YYYY (e.g., 08/04/2025)" value="<?php echo htmlspecialchars($currentDate); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="descrip">Description of Defect</label>
                                <textarea name="descrip" id="descrip" class="form-control" rows="4" required></textarea>
                            </div>
                            <button type="submit" name="submit_complaint" class="btn btn-danger">Submit Complaint</button>
                        </form>
                    </div>
                </div>
                <div class="card card-statistics">
                    <div class="card-header">
                        <h4>Your Complaints (User: <?php echo htmlspecialchars($uname); ?>)</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Asset</th>
                                        <th>Location</th>
                                        <th>Location Type</th>
                                        <th>Room Number</th>
                                        <th>Description</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    if ($user_complaints_result && $user_complaints_result->num_rows > 0) {
                                        while ($row = $user_complaints_result->fetch_assoc()) {
                                            echo '<tr>';
                                            echo '<td>' . htmlspecialchars($row['co_id']) . '</td>';
                                            echo '<td>' . htmlspecialchars($row['aname'] ?? 'N/A') . '</td>';
                                            echo '<td>' . htmlspecialchars($row['dname'] ?? $row['pa_name'] ?? 'N/A') . '</td>';
                                            echo '<td>' . htmlspecialchars($row['locationtype'] ?? 'N/A') . '</td>';
                                            echo '<td>' . htmlspecialchars($row['roomnum'] ?? 'N/A') . '</td>';
                                            echo '<td>' . htmlspecialchars($row['descrip']) . '</td>';
                                            echo '<td>' . htmlspecialchars(sprintf("%02d/%02d/%04d", $row['date'], $row['month'], $row['year'])) . '</td>';
                                            echo '<td>' . htmlspecialchars($row['status']) . '</td>';
                                            echo '</tr>';
                                        }
                                    } else {
                                        echo '<tr><td colspan="8" class="text-center">No complaints submitted yet by ' . htmlspecialchars($uname) . '.</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<footer class="footer">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-12 col-sm-6 text-center text-sm-start">
                <p>© Copyright 2019. All rights reserved.</p>
            </div>
            <div class="col-12 col-sm-6 text-center text-sm-end">
                <p><a target="_blank" href="https://www.templateshub.net">Templates Hub</a></p>
            </div>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/vendors.js"></script>
<script src="assets/js/app.js"></script>
<script>
const shouldHideLocationDivs = <?php echo (!isset($selected_did) || $selected_did === false || !isset($_POST['check_lab'])) ? 'true' : 'false'; ?>;

try {
    function toggleSelection() {
        const departmentDiv = document.getElementById('department_div');
        const publicAreaDiv = document.getElementById('public_area_div');
        const locationTypeDiv = document.getElementById('location_type_div');
        const classroomDiv = document.getElementById('classroom_div');
        const departmentRadio = document.getElementById('complaint_department');
        const locationTypeHidden = document.getElementById('location_type_hidden');
        
        if (departmentRadio.checked) {
            departmentDiv.style.display = 'block';
            publicAreaDiv.style.display = 'none';
            if (shouldHideLocationDivs) {
                if (locationTypeDiv) locationTypeDiv.style.display = 'none';
                if (classroomDiv) classroomDiv.style.display = 'none';
            }
            document.getElementsByName('did')[0].setAttribute('required', 'required');
            document.getElementsByName('pa_id')[0].removeAttribute('required');
            locationTypeHidden.name = 'location_type_hidden';
        } else {
            departmentDiv.style.display = 'none';
            publicAreaDiv.style.display = 'block';
            if (locationTypeDiv) locationTypeDiv.style.display = 'none';
            if (classroomDiv) classroomDiv.style.display = 'none';
            document.getElementsByName('pa_id')[0].setAttribute('required', 'required');
            document.getElementsByName('did')[0].removeAttribute('required');
            locationTypeHidden.name = 'location_type';
            document.getElementsByName('location_type').forEach(radio => radio.checked = false);
            if (document.getElementsByName('classroom_number')[0]) {
                document.getElementsByName('classroom_number')[0].value = '';
            }
        }
    }

    function toggleLocationInput() {
        const classroomDiv = document.getElementById('classroom_div');
        const locationClassRadio = document.getElementById('location_class');
        
        if (locationClassRadio && locationClassRadio.checked) {
            classroomDiv.style.display = 'block';
            document.getElementsByName('classroom_number')[0].setAttribute('required', 'required');
        } else {
            classroomDiv.style.display = 'none';
            document.getElementsByName('classroom_number')[0].removeAttribute('required');
            document.getElementsByName('classroom_number')[0].value = '';
        }
    }
} catch (e) {
    console.error('JavaScript Error:', e);
}
</script>
</body>
</html>
<?php
if (isset($mysqli)) {
    $mysqli->close();
}
if (isset($user_complaints_query)) {
    $user_complaints_query->close();
}
ob_end_flush();
?>