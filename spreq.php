<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Log session data for debugging
error_log("Start of script: uid=" . ($_SESSION['uid'] ?? 'unset') . ", csrf_token=" . ($_SESSION['csrf_token'] ?? 'unset'));

// Check if user is logged in
if (!isset($_SESSION['uid'])) {
    error_log("Redirecting: uid not set");
    header("Location: index.php");
    exit();
}

// Database connection
$mysqli = new mysqli("localhost", "root", "", "inventory");
if ($mysqli->connect_error) {
    error_log("Database connection failed: " . $mysqli->connect_error);
    die("System error. Please try again later.");
}

// Fetch username and email from stlog
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

// Redirect to login if username is not found or not a teacher
if (!$uname || strtolower(substr($uname, 0, 1)) !== 't') {
    error_log("Redirecting: uname=$uname, not a teacher");
    header("Location: index.php");
    exit();
}

// Check if user is a teacher
$is_teacher = strtolower(substr($uname, 0, 1)) === 't';

// Fetch teacher's department ID and name
$dept_query = $mysqli->prepare("
    SELECT d.did, d.dname
    FROM stlog s
    JOIN teacher t ON t.temail = s.uemail
    JOIN dept d ON d.did = t.did
    WHERE s.username = ?
");
$dept_query->bind_param("s", $uname);
$dept_query->execute();
$dept_result = $dept_query->get_result();
if ($dept_result && $dept_result->num_rows > 0) {
    $dept_data = $dept_result->fetch_assoc();
    $teacher_did = $dept_data['did'];
    $teacher_dname = $dept_data['dname'];
} else {
    $teacher_did = null;
    $teacher_dname = null;
    error_log("No department found for teacher: " . $uname);
}
$dept_query->close();

// Handle missing department gracefully
if (!$teacher_did) {
    error_log("No department found for teacher: " . $uname);
    $result1 = '<div class="alert alert-danger">No department found for your account. Please contact support.</div>';
    $teacher_dname = 'N/A';
} else {
    error_log("Retrieved uname: " . $uname . ", uemail: " . ($uemail ?? 'null') . ", is_teacher: " . ($is_teacher ? 'true' : 'false') . ", did: " . ($teacher_did ?? 'null') . " for uid: " . $_SESSION['uid']);
}

// Get data from query parameters (from reqclass.php, etc.)
$is_redirected = isset($_GET['asset_name']) && isset($_GET['asset_type']) && isset($_GET['quantity']) && isset($_GET['locationtype']);
$asset_name = $is_redirected ? urldecode(filter_input(INPUT_GET, 'asset_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS)) : '';
$asset_type = $is_redirected ? urldecode(filter_input(INPUT_GET, 'asset_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS)) : '';
$quantity = $is_redirected ? filter_input(INPUT_GET, 'quantity', FILTER_VALIDATE_INT) : '';
$location_type = $is_redirected ? urldecode(filter_input(INPUT_GET, 'locationtype', FILTER_SANITIZE_FULL_SPECIAL_CHARS)) : '';
$roomnum = $is_redirected && isset($_GET['roomnum']) ? urldecode(filter_input(INPUT_GET, 'roomnum', FILTER_SANITIZE_FULL_SPECIAL_CHARS)) : '';

// Map 'classroom' from reqclass.php to 'class' for form display
$form_location_type = $is_redirected && $location_type === 'classroom' ? 'class' : $location_type;

// Get current date components
$currentDay = date('d');
$currentMonth = date('m');
$currentYear = date('Y');

// Check if department has a lab
$has_lab = false;
if ($teacher_did) {
    $stmt = $mysqli->prepare("SELECT COUNT(*) FROM lab WHERE did = ?");
    $stmt->bind_param("i", $teacher_did);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $has_lab = $count > 0;
    $stmt->close();
}

// Fetch distinct asset types from asset table
$asset_types = [];
$asset_type_query = $mysqli->query("SELECT DISTINCT atype FROM asset ORDER BY atype");
if ($asset_type_query) {
    while ($row = $asset_type_query->fetch_assoc()) {
        $asset_types[] = $row['atype'];
    }
    $asset_type_query->close();
} else {
    error_log("Failed to fetch asset types: " . $mysqli->error);
}

// Handle special request submission
$result1 = "";
if (isset($_POST['submit_request'])) {
    // Log raw POST data for debugging
    error_log("Form submitted with POST data: " . print_r($_POST, true));

    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        error_log("CSRF Token Mismatch: POST=" . ($_POST['csrf_token'] ?? 'unset') . ", SESSION=" . ($_SESSION['csrf_token'] ?? 'unset'));
        $result1 = '<div class="alert alert-danger">Invalid CSRF token. Please try again.</div>';
    } elseif (!$teacher_did) {
        $result1 = '<div class="alert alert-danger">Cannot submit request: no department assigned.</div>';
    } else {
        // Validate and sanitize inputs
        $asset_name = filter_input(INPUT_POST, 'asset_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $asset_type = filter_input(INPUT_POST, 'asset_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);
        $did = $teacher_did;
        $form_location_type = isset($_POST['location_type']) ? htmlspecialchars(trim($_POST['location_type'])) : null;
        // Map 'class' to 'classroom' for database storage
        $locationtype = $form_location_type === 'class' ? 'classroom' : ($form_location_type ?? 'NA');
        $roomnum = $locationtype === 'classroom' ? htmlspecialchars(trim($_POST['classroom_number'] ?? 'NA')) : 'NA';
        $descrip = filter_input(INPUT_POST, 'descrip', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $status = "New";

        // Valid location types (form input values)
        $valid_location_types = ['class', 'lab', 'corridor', 'faculty office'];
        if (!$has_lab) {
            $valid_location_types = array_diff($valid_location_types, ['lab']);
        }

        // Debug output
        error_log("Form Location Type: $form_location_type, Mapped Location Type: $locationtype, Room Number: $roomnum");
        error_log("Asset Name: $asset_name, Type: $asset_type, Quantity: $quantity, DID: $did, LocationType: $locationtype, RoomNum: $roomnum, Description: $descrip");

        // Validate inputs
        if (empty($asset_name)) {
            $result1 = '<div class="alert alert-danger">Please provide asset name.</div>';
            error_log("Validation cutoff: Empty asset name");
        } elseif (empty($asset_type) || !in_array($asset_type, $asset_types)) {
            $result1 = '<div class="alert alert-danger">Please select a valid asset type.</div>';
            error_log("Validation cutoff: Invalid asset type: $asset_type");
        } elseif ($quantity === false || $quantity <= 0) {
            $result1 = '<div class="alert alert-danger">Please enter a valid quantity greater than 0.</div>';
            error_log("Validation cutoff: Invalid quantity");
        } elseif ($form_location_type === null || !in_array($form_location_type, $valid_location_types)) {
            $result1 = '<div class="alert alert-danger">Please select a valid location type.</div>';
            error_log("Validation cutoff: Invalid location type: $form_location_type");
        } elseif ($locationtype === 'classroom' && $roomnum === 'NA') {
            $result1 = '<div class="alert alert-danger">Please enter a classroom number for classroom location.</div>';
            error_log("Validation cutoff: Missing classroom number for classroom location");
        } elseif ($locationtype === 'faculty office' && !$is_teacher) {
            $result1 = '<div class="alert alert-danger">Faculty office is only available for teachers.</div>';
            error_log("Validation cutoff: Faculty office selected by non-teacher");
        } elseif (empty($descrip)) {
            $result1 = '<div class="alert alert-danger">Please provide a description.</div>';
            error_log("Validation cutoff: Empty description");
        } else {
            // Insert special request using current date
            $stmt = $mysqli->prepare("
                INSERT INTO special_request (username, asset_name, asset_type, quantity, did, locationtype, roomnum, descrip, status, date, month, year)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("sssiissssiii", $uname, $asset_name, $asset_type, $quantity, $did, $locationtype, $roomnum, $descrip, $status, $currentDay, $currentMonth, $currentYear);

            // Log values before insertion
            error_log("Preparing to insert: username=$uname, asset_name=$asset_name, asset_type=$asset_type, quantity=$quantity, did=$did, locationtype=$locationtype, roomnum=$roomnum, descrip=$descrip, status=$status, date=$currentDay, month=$currentMonth, year=$currentYear");

            if ($stmt->execute()) {
                $sr_id = $mysqli->insert_id;
                $result1 = '<div class="alert alert-success">Special Request Submitted Successfully (ID: ' . $sr_id . ')</div>';
                error_log("Inserted special request ID: $sr_id with locationtype: $locationtype");
                // Regenerate CSRF token after successful submission
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                // Clear form data after successful submission
                $asset_name = '';
                $asset_type = '';
                $quantity = '';
                $form_location_type = '';
                $roomnum = '';
            } else {
                error_log("Special request submission failed: " . $stmt->error);
                $result1 = '<div class="alert alert-danger">Error submitting request: ' . $stmt->error . '</div>';
            }
            $stmt->close();
        }
    }
}

// Fetch user's special requests
$user_requests_query = $mysqli->prepare("
    SELECT sr.sr_id, sr.asset_name, sr.asset_type, sr.quantity, sr.descrip, sr.status, sr.date, sr.month, sr.year, d.dname, sr.locationtype, sr.roomnum
    FROM special_request sr
    LEFT JOIN dept d ON sr.did = d.did
    WHERE sr.username = ?
    ORDER BY sr.sr_id DESC
");
$user_requests_query->bind_param("s", $uname);
$user_requests_query->execute();
$user_requests_result = $user_requests_query->get_result();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Special Asset Request</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" href="assets/img/dln1.png" type="image/x-icon">
    <style>
        .container-fluid {
            padding: 20px;
        }
        .table {
            margin-top: 20px;
            width: 100%;
            table-layout: auto;
        }
        .table th, .table td {
            white-space: normal;
            word-wrap: break-word;
            vertical-align: middle;
        }
        .table-responsive {
            -webkit-overflow-scrolling: touch;
        }
        .col-id {
            min-width: 60px;
        }
        .col-quantity {
            min-width: 80px;
        }
        .col-roomnum {
            min-width: 100px;
        }
        .col-date {
            min-width: 100px;
        }
        @media (max-width: 768px) {
            .table th, .table td {
                font-size: 14px;
                padding: 8px;
            }
            .col-roomnum, .col-date {
                display: none;
            }
        }
        .table td:nth-child(8) {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .table-hover tbody tr:hover {
            background-color: #f1f1f1;
        }
        .alert {
            margin-top: 20px;
        }
    </style>
</head>
<body>
<div class="app-main" id="main">
    <?php include('theader.php'); ?>
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <!-- Special Request Submission Section -->
                <div class="card card-statistics mt-4">
                    <div class="card-header">
                        <div class="card-heading">
                            <h4 class="card-title">Special Asset Request (Out of Stock)</h4>
                        </div>
                        <strong><?php echo $result1; ?></strong>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" onsubmit="return validateForm()">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="check_lab" value="1">
                            <div class="form-group">
                                <label>Username</label>
                                <input type="text" value="<?php echo htmlspecialchars($uname); ?>" class="form-control" readonly>
                                <input type="hidden" name="uid" value="<?php echo htmlspecialchars($_SESSION['uid']); ?>">
                            </div>
                            <div class="form-group">
                                <label>Department</label>
                                <input type="text" value="<?php echo htmlspecialchars($teacher_dname); ?>" class="form-control" readonly>
                            </div>
                            <div class="form-group">
                                <label>Asset Name</label>
                                <input type="text" name="asset_name" class="form-control" value="<?php echo htmlspecialchars($asset_name); ?>" <?php echo $is_redirected ? 'readonly' : 'required'; ?> placeholder="Enter asset name">
                            </div>
                            <div class="form-group">
                                <label>Asset Type</label>
                                <select name="asset_type" class="form-control" <?php echo $is_redirected ? 'disabled' : 'required'; ?>>
                                    <option value="" disabled <?php echo empty($asset_type) ? 'selected' : ''; ?>>Select Asset Type</option>
                                    <?php foreach ($asset_types as $type): ?>
                                        <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $asset_type === $type ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($type); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($is_redirected): ?>
                                    <input type="hidden" name="asset_type" value="<?php echo htmlspecialchars($asset_type); ?>">
                                <?php endif; ?>
                            </div>
                            <div class="form-group">
                                <label>Quantity</label>
                                <input type="number" name="quantity" class="form-control" min="1" value="<?php echo htmlspecialchars($quantity); ?>" <?php echo $is_redirected ? 'readonly' : 'required'; ?>>
                            </div>
                            <div class="form-group" id="location_type_div">
                                <label>Location Type</label><br>
                                <?php if ($is_redirected): ?>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($form_location_type); ?>" readonly>
                                    <input type="hidden" name="location_type" value="<?php echo htmlspecialchars($form_location_type); ?>">
                                <?php else: ?>
                                    <?php if ($has_lab): ?>
                                        <input type="radio" name="location_type" value="class" id="location_class" required onclick="toggleLocationInput()">
                                        <label for="location_class">Class</label>
                                        <input type="radio" name="location_type" value="lab" id="location_lab" onclick="toggleLocationInput()">
                                        <label for="location_lab">Lab</label>
                                        <input type="radio" name="location_type" value="corridor" id="location_corridor" onclick="toggleLocationInput()">
                                        <label for="location_corridor">Corridor</label>
                                        <input type="radio" name="location_type" value="faculty office" id="location_faculty_office" onclick="toggleLocationInput()">
                                        <label for="location_faculty_office">Faculty Office</label>
                                    <?php else: ?>
                                        <input type="radio" name="location_type" value="class" id="location_class" required onclick="toggleLocationInput()">
                                        <label for="location_class">Class</label>
                                        <input type="radio" name="location_type" value="corridor" id="location_corridor" onclick="toggleLocationInput()">
                                        <label for="location_corridor">Corridor</label>
                                        <input type="radio" name="location_type" value="faculty office" id="location_faculty_office" onclick="toggleLocationInput()">
                                        <label for="location_faculty_office">Faculty Office</label>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <div class="form-group" id="classroom_div" style="display: <?php echo ($form_location_type === 'class' && $roomnum !== '') ? 'block' : 'none'; ?>;">
                                <label>Classroom Number</label>
                                <input type="text" name="classroom_number" class="form-control" value="<?php echo htmlspecialchars($roomnum); ?>" <?php echo ($form_location_type === 'class' && $is_redirected) ? 'readonly required' : ($form_location_type === 'class' ? 'required' : ''); ?> placeholder="Enter classroom number">
                            </div>
                            <div class="form-group">
                                <label>Date</label>
                                <div class="row">
                                    <div class="col-4">
                                        <input type="number" class="form-control" name="day" value="<?php echo $currentDay; ?>" readonly>
                                    </div>
                                    <div class="col-4">
                                        <input type="number" class="form-control" name="month" value="<?php echo $currentMonth; ?>" readonly>
                                    </div>
                                    <div class="col-4">
                                        <input type="number" class="form-control" name="year" value="<?php echo $currentYear; ?>" readonly>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Description of Request</label>
                                <textarea name="descrip" class="form-control" rows="3" placeholder="Describe the asset and its intended use" required><?php echo isset($_POST['descrip']) ? htmlspecialchars($_POST['descrip']) : ''; ?></textarea>
                            </div>
                            <button type="submit" name="submit_request" class="btn btn-danger">Submit Request</button>
                        </form>
                    </div>
                </div>

                <!-- User's Special Requests List -->
                <div class="card card-statistics mt-4">
                    <div class="card-header">
                        <h4>Your Special Requests (User: <?php echo htmlspecialchars($uname); ?>)</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th class="col-id">ID</th>
                                        <th>Asset Name</th>
                                        <th>Asset Type</th>
                                        <th class="col-quantity">Quantity</th>
                                        <th>Department</th>
                                        <th>Location Type</th>
                                        <th class="col-roomnum">Room Number</th>
                                        <th>Description</th>
                                        <th class="col-date">Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    if ($user_requests_result && $user_requests_result->num_rows > 0) {
                                        while ($row = $user_requests_result->fetch_assoc()) {
                                            echo '<tr>';
                                            echo '<td class="col-id">' . htmlspecialchars($row['sr_id']) . '</td>';
                                            echo '<td>' . htmlspecialchars($row['asset_name']) . '</td>';
                                            echo '<td>' . htmlspecialchars($row['asset_type']) . '</td>';
                                            echo '<td class="col-quantity">' . htmlspecialchars($row['quantity']) . '</td>';
                                            echo '<td>' . htmlspecialchars($row['dname'] ?? 'N/A') . '</td>';
                                            echo '<td>' . htmlspecialchars($row['locationtype'] ?? 'N/A') . '</td>';
                                            echo '<td class="col-roomnum">' . htmlspecialchars($row['roomnum'] ?? 'N/A') . '</td>';
                                            echo '<td>' . htmlspecialchars($row['descrip']) . '</td>';
                                            echo '<td class="col-date">' . htmlspecialchars(sprintf("%02d/%02d/%04d", $row['date'], $row['month'], $row['year'])) . '</td>';
                                            echo '<td>' . htmlspecialchars($row['status']) . '</td>';
                                            echo '</tr>';
                                        }
                                    } else {
                                        echo '<tr><td colspan="10" class="text-center">No special requests submitted yet by ' . htmlspecialchars($uname) . '.</td></tr>';
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
    <div class="row">
        <div class="col-12 col-sm-6 text-center text-sm-left">
            <p>© Copyright 2019. All rights reserved.</p>
        </div>
        <div class="col col-sm-6 ml-sm-auto text-center text-sm-right">
            <p><a target="_blank" href="https://www.templateshub.net">Templates Hub</a></p>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/vendors.js"></script>
<script src="assets/js/app.js"></script>
<script>
function toggleLocationInput() {
    var classroomDiv = document.getElementById('classroom_div');
    var locationClassRadio = document.getElementById('location_class');
    var classroomInput = document.getElementsByName('classroom_number')[0];
    
    if (locationClassRadio && locationClassRadio.checked) {
        classroomDiv.style.display = 'block';
        if (!classroomInput.hasAttribute('readonly')) {
            classroomInput.setAttribute('required', 'required');
        }
    } else {
        classroomDiv.style.display = 'none';
        if (classroomInput && !classroomInput.hasAttribute('readonly')) {
            classroomInput.removeAttribute('required');
            classroomInput.value = '';
        }
    }
}

// Run on page load to set initial state
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('input[name="location_type"]').forEach(function(radio) {
        radio.addEventListener('change', toggleLocationInput);
    });
    toggleLocationInput();
});

function validateForm() {
    var assetName = document.querySelector('input[name="asset_name"]').value.trim();
    var quantity = document.querySelector('input[name="quantity"]').value;
    var locationType = document.querySelector('input[name="location_type"]:checked') || document.querySelector('input[name="location_type"][type="hidden"]');
    var roomnum = document.querySelector('input[name="classroom_number"]') ? document.querySelector('input[name="classroom_number"]').value.trim() : '';
    var descrip = document.querySelector('textarea[name="descrip"]').value.trim();

    if (!assetName) {
        alert('Please enter an asset name.');
        return false;
    }
    if (!/^[A-Za-z0-9\s\-]+$/.test(assetName)) {
        alert('Asset name can only contain letters, numbers, spaces, and hyphens.');
        return false;
    }
    if (!quantity || isNaN(quantity) || parseInt(quantity) < 1) {
        alert('Please enter a valid quantity (minimum 1).');
        return false;
    }
    if (!locationType) {
        alert('Please select a location type.');
        return false;
    }
    if (locationType.value === 'class' && !roomnum) {
        alert('Please enter a classroom number.');
        return false;
    }
    if (locationType.value === 'class' && !/^[A-Z0-9\-]+$/i.test(roomnum)) {
        alert('Classroom number can only contain letters, numbers, and hyphens.');
        return false;
    }
    if (!descrip) {
        alert('Please provide a description.');
        return false;
    }
    return true;
}
</script>
</body>
</html>
<?php
if (isset($mysqli)) {
    $mysqli->close();
}
if (isset($user_requests_query)) {
    $user_requests_query->close();
}
?>