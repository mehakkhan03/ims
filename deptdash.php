<?php
session_start();
$display = "";

// Enable error logging
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', 'C:/wamp64/logs/php_error.log');

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    error_log("Redirecting to index.php: username not set in session");
    header("Location: index.php");
    exit();
}

// Initialize dismissed_notifications array
if (!isset($_SESSION['dismissed_notifications'])) {
    $_SESSION['dismissed_notifications'] = [];
}

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "inventory";

try {
    /** @var mysqli $conn */
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        error_log("Database connection failed: " . $conn->connect_error);
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    // Fetch department name and did for welcome message and filtering
    $welcomeMessage = "Welcome to IMS";
    $loggedInUsername = $_SESSION['username'];
    $welcomeQuery = "SELECT d.dname, d.did 
                     FROM `stlog` s 
                     JOIN `teacher` t ON t.temail = s.uemail 
                     JOIN `dept` d ON d.did = t.did 
                     WHERE s.username = ?";
    $welcomeStmt = $conn->prepare($welcomeQuery);
    if (!$welcomeStmt) {
        error_log("Welcome query preparation failed: " . $conn->error);
        throw new Exception("Query preparation failed");
    }
    $welcomeStmt->bind_param("s", $loggedInUsername);
    $welcomeStmt->execute();
    $welcomeResult = $welcomeStmt->get_result();

    if ($welcomeResult && $welcomeResult->num_rows > 0) {
        $welcomeRow = $welcomeResult->fetch_assoc();
        $welcomeMessage = "Welcome to IMS " . htmlspecialchars($welcomeRow['dname']) . " Department!";
        $departmentId = $welcomeRow['did'];
    } else {
        error_log("No department found for username: $loggedInUsername");
        $display = '<div class="alert alert-danger">Error: User is not associated with a department!</div>';
        $departmentId = null;
    }
    $welcomeStmt->close();

    // Initialize variables to avoid undefined errors
    $deptAssetsInUse = 0;
    $deptApprovedRequestsCount = 0;
    $deptHoldRequestsCount = 0;
    $deptRejectedRequestsCount = 0;
    $deptSpecialRequestsCount = 0;
    $approvedNotifications = [];
    $simpleResult = null;
    $detailedResult = null;

    // Department-specific metrics
    if ($departmentId !== null) {
        // Total Assets In Use (Including special requests)
        $deptAssetsInUseQuery = "
            SELECT SUM(quantity) AS total
            FROM (
                SELECT COALESCE(ad.nqty, 0) AS quantity
                FROM add_dept ad
                WHERE ad.did = ? AND ad.status = 'Approved'
                UNION ALL
                SELECT COALESCE(al.aqty, 0) AS quantity
                FROM add_lab al
                WHERE al.did = ? AND al.status = 'Approved'
                UNION ALL
                SELECT COALESCE(ao.aqty, 0) AS quantity
                FROM add_office ao
                WHERE ao.did = ? AND ao.status = 'Approved'
                UNION ALL
                SELECT COALESCE(c.aqty, 0) AS quantity
                FROM classroom c
                WHERE c.did = ? AND c.status = 'Approved'
                UNION ALL
                SELECT COALESCE(sr.quantity, 0) AS quantity
                FROM special_request sr
                JOIN asset a ON sr.asset_name = a.aname
                WHERE sr.did = ? AND sr.status = 'Approved'
            ) AS dept_approved_assets
        ";
        $deptAssetsInUseStmt = $conn->prepare($deptAssetsInUseQuery);
        if (!$deptAssetsInUseStmt) {
            error_log("Assets in use query preparation failed: " . $conn->error);
        } else {
            $deptAssetsInUseStmt->bind_param("iiiii", $departmentId, $departmentId, $departmentId, $departmentId, $departmentId);
            $deptAssetsInUseStmt->execute();
            $deptAssetsInUseResult = $deptAssetsInUseStmt->get_result();
            $deptAssetsInUse = $deptAssetsInUseResult && $deptAssetsInUseResult->num_rows > 0 ? $deptAssetsInUseResult->fetch_assoc()['total'] : 0;
            $deptAssetsInUseStmt->close();
        }

        // Approved Department Requests
        $deptApprovedRequestsQuery = "
            SELECT COALESCE(COUNT(*), 0) FROM (
                SELECT id FROM add_dept WHERE did = ? AND status = 'Approved'
                UNION ALL
                SELECT id FROM add_lab WHERE did = ? AND status = 'Approved'
                UNION ALL
                SELECT id FROM add_office WHERE did = ? AND status = 'Approved'
                UNION ALL
                SELECT id FROM classroom WHERE did = ? AND status = 'Approved'
            ) AS dept_approved_requests
        ";
        $deptApprovedRequestsStmt = $conn->prepare($deptApprovedRequestsQuery);
        if (!$deptApprovedRequestsStmt) {
            error_log("Approved requests query preparation failed: " . $conn->error);
        } else {
            $deptApprovedRequestsStmt->bind_param("iiii", $departmentId, $departmentId, $departmentId, $departmentId);
            $deptApprovedRequestsStmt->execute();
            $deptApprovedRequestsResult = $deptApprovedRequestsStmt->get_result();
            $deptApprovedRequestsCount = $deptApprovedRequestsResult ? $deptApprovedRequestsResult->fetch_row()[0] : 0;
            $deptApprovedRequestsStmt->close();
        }

        // Hold Department Requests
        $deptHoldRequestsQuery = "
            SELECT COALESCE(COUNT(*), 0) FROM (
                SELECT id FROM add_dept WHERE did = ? AND status = 'Hold'
                UNION ALL
                SELECT id FROM add_lab WHERE did = ? AND status = 'Hold'
                UNION ALL
                SELECT id FROM add_office WHERE did = ? AND status = 'Hold'
                UNION ALL
                SELECT id FROM classroom WHERE did = ? AND status = 'Hold'
            ) AS dept_hold_requests
        ";
        $deptHoldRequestsStmt = $conn->prepare($deptHoldRequestsQuery);
        if (!$deptHoldRequestsStmt) {
            error_log("Hold requests query preparation failed: " . $conn->error);
        } else {
            $deptHoldRequestsStmt->bind_param("iiii", $departmentId, $departmentId, $departmentId, $departmentId);
            $deptHoldRequestsStmt->execute();
            $deptHoldRequestsResult = $deptHoldRequestsStmt->get_result();
            $deptHoldRequestsCount = $deptHoldRequestsResult ? $deptHoldRequestsResult->fetch_row()[0] : 0;
            $deptHoldRequestsStmt->close();
        }

        // Rejected Department Requests
        $deptRejectedRequestsQuery = "
            SELECT COALESCE(COUNT(*), 0) FROM (
                SELECT id FROM add_dept WHERE did = ? AND status = 'Rejected'
                UNION ALL
                SELECT id FROM add_lab WHERE did = ? AND status = 'Rejected'
                UNION ALL
                SELECT id FROM add_office WHERE did = ? AND status = 'Rejected'
                UNION ALL
                SELECT id FROM classroom WHERE did = ? AND status = 'Rejected'
            ) AS dept_rejected_requests
        ";
        $deptRejectedRequestsStmt = $conn->prepare($deptRejectedRequestsQuery);
        if (!$deptRejectedRequestsStmt) {
            error_log("Rejected requests query preparation failed: " . $conn->error);
        } else {
            $deptRejectedRequestsStmt->bind_param("iiii", $departmentId, $departmentId, $departmentId, $departmentId);
            $deptRejectedRequestsStmt->execute();
            $deptRejectedRequestsResult = $deptRejectedRequestsStmt->get_result();
            $deptRejectedRequestsCount = $deptRejectedRequestsResult ? $deptRejectedRequestsResult->fetch_row()[0] : 0;
            $deptRejectedRequestsStmt->close();
        }

        // Special Department Requests (all statuses)
        $deptSpecialRequestsQuery = "
            SELECT COALESCE(COUNT(*), 0) AS total
            FROM special_request
            WHERE did = ?
        ";
        $deptSpecialRequestsStmt = $conn->prepare($deptSpecialRequestsQuery);
        if (!$deptSpecialRequestsStmt) {
            error_log("Special requests query preparation failed: " . $conn->error);
        } else {
            $deptSpecialRequestsStmt->bind_param("i", $departmentId);
            $deptSpecialRequestsStmt->execute();
            $deptSpecialRequestsResult = $deptSpecialRequestsStmt->get_result();
            $deptSpecialRequestsCount = $deptSpecialRequestsResult ? $deptSpecialRequestsResult->fetch_row()[0] : 0;
            $deptSpecialRequestsStmt->close();
        }

        // Fetch Approved Requests for Notifications
        $approvedRequestsQuery = "
            SELECT 
                'Department' AS request_type, id AS request_id, a.aname AS asset_name, ad.nqty AS quantity, NULL AS location_name
            FROM add_dept ad JOIN asset a ON ad.aid = a.aid
            WHERE ad.did = ? AND ad.status = 'Approved'
            UNION ALL
            SELECT 
                'Lab' AS request_type, id AS request_id, a.aname AS asset_name, al.aqty AS quantity, l.lname AS location_name
            FROM add_lab al JOIN asset a ON al.aid = a.aid JOIN lab l ON al.lid = l.lid
            WHERE al.did = ? AND al.status = 'Approved'
            UNION ALL
            SELECT 
                'Office' AS request_type, id AS request_id, a.aname AS asset_name, ao.aqty AS quantity, o.oname AS location_name
            FROM add_office ao JOIN asset a ON ao.aid = a.aid JOIN off o ON ao.oid = o.oid
            WHERE ao.did = ? AND ao.status = 'Approved'
            UNION ALL
            SELECT 
                'Classroom' AS request_type, id AS request_id, a.aname AS asset_name, c.aqty AS quantity, cl_id AS location_name
            FROM classroom c JOIN asset a ON c.aid = a.aid
            WHERE c.did = ? AND c.status = 'Approved'
            UNION ALL
            SELECT 
                'Special Request' AS request_type, sr_id AS request_id, asset_name, quantity, NULL AS location_name
            FROM special_request
            WHERE did = ? AND status = 'Approved'
            ORDER BY request_id DESC
        ";
        $approvedRequestsStmt = $conn->prepare($approvedRequestsQuery);
        if (!$approvedRequestsStmt) {
            error_log("Approved requests notification query preparation failed: " . $conn->error);
        } else {
            $approvedRequestsStmt->bind_param("iiiii", $departmentId, $departmentId, $departmentId, $departmentId, $departmentId);
            $approvedRequestsStmt->execute();
            $approvedRequestsResult = $approvedRequestsStmt->get_result();
            $approvedNotifications = [];
            while ($row = $approvedRequestsResult->fetch_assoc()) {
                // Only include requests that haven't been dismissed
                if (!in_array($row['request_id'] . '_' . $row['request_type'], $_SESSION['dismissed_notifications'])) {
                    $approvedNotifications[] = $row;
                }
            }
            $approvedRequestsStmt->close();
        }

        // Simple Query for Approved Assets
        $simpleQuery = "
            SELECT 
                department_name,
                asset_name,
                SUM(quantity) AS quantity
            FROM (
                SELECT d.dname AS department_name, a.aname AS asset_name, COALESCE(ad.nqty, 0) AS quantity
                FROM add_dept ad JOIN dept d ON ad.did = d.did JOIN asset a ON ad.aid = a.aid
                WHERE ad.status = 'Approved' AND ad.did = ?
                UNION ALL
                SELECT d.dname AS department_name, a.aname AS asset_name, COALESCE(al.aqty, 0) AS quantity
                FROM add_lab al JOIN dept d ON al.did = d.did JOIN asset a ON al.aid = a.aid JOIN lab l ON al.lid = l.lid
                WHERE al.status = 'Approved' AND al.did = ?
                UNION ALL
                SELECT d.dname AS department_name, a.aname AS asset_name, COALESCE(ao.aqty, 0) AS quantity
                FROM add_office ao JOIN dept d ON ao.did = d.did JOIN asset a ON ao.aid = a.aid JOIN off o ON ao.oid = o.oid
                WHERE ao.status = 'Approved' AND ao.did = ?
                UNION ALL
                SELECT d.dname AS department_name, a.aname AS asset_name, COALESCE(c.aqty, 0) AS quantity
                FROM classroom c JOIN dept d ON c.did = d.did JOIN asset a ON c.aid = a.aid
                WHERE c.status = 'Approved' AND c.did = ?
                UNION ALL
                SELECT d.dname AS department_name, sr.asset_name AS asset_name, COALESCE(sr.quantity, 0) AS quantity
                FROM special_request sr JOIN dept d ON sr.did = d.did JOIN asset a ON sr.asset_name = a.aname
                WHERE sr.status = 'Approved' AND sr.did = ?
            ) AS combined_assets
            GROUP BY department_name, asset_name
            ORDER BY department_name, asset_name
        ";
        $simpleStmt = $conn->prepare($simpleQuery);
        if (!$simpleStmt) {
            error_log("Simple query preparation failed: " . $conn->error);
        } else {
            $simpleStmt->bind_param("iiiii", $departmentId, $departmentId, $departmentId, $departmentId, $departmentId);
            $simpleStmt->execute();
            $simpleResult = $simpleStmt->get_result();
            $simpleStmt->close();
        }

        // Detailed Query for Approved Assets
        $detailedQuery = "
            SELECT 
                department_name,
                location_type,
                location_name,
                asset_name,
                SUM(quantity) AS quantity
            FROM (
                SELECT d.dname AS department_name, a.aname AS asset_name, COALESCE(ad.nqty, 0) AS quantity, 
                       'Department' AS location_type, NULL AS location_name
                FROM add_dept ad JOIN dept d ON ad.did = d.did JOIN asset a ON ad.aid = a.aid
                WHERE ad.status = 'Approved' AND ad.did = ?
                UNION ALL
                SELECT d.dname AS department_name, a.aname AS asset_name, COALESCE(al.aqty, 0) AS quantity, 
                       'Lab' AS location_type, l.lname AS location_name
                FROM add_lab al JOIN dept d ON al.did = d.did JOIN asset a ON al.aid = a.aid JOIN lab l ON al.lid = l.lid
                WHERE al.status = 'Approved' AND al.did = ?
                UNION ALL
                SELECT d.dname AS department_name, a.aname AS asset_name, COALESCE(ao.aqty, 0) AS quantity, 
                       'Office' AS location_type, o.oname AS location_name
                FROM add_office ao JOIN dept d ON ao.did = d.did JOIN asset a ON ao.aid = a.aid JOIN off o ON ao.oid = o.oid
                WHERE ao.status = 'Approved' AND ao.did = ?
                UNION ALL
                SELECT d.dname AS department_name, a.aname AS asset_name, COALESCE(c.aqty, 0) AS quantity, 
                       'Classroom' AS location_type, c.cl_id AS location_name
                FROM classroom c JOIN dept d ON c.did = d.did JOIN asset a ON c.aid = a.aid
                WHERE c.status = 'Approved' AND c.did = ?
                UNION ALL
                SELECT d.dname AS department_name, sr.asset_name AS asset_name, COALESCE(sr.quantity, 0) AS quantity, 
                       'Special Request' AS location_type, COALESCE(sr.roomnum, 'N/A') AS location_name
                FROM special_request sr JOIN dept d ON sr.did = d.did JOIN asset a ON sr.asset_name = a.aname
                WHERE sr.status = 'Approved' AND sr.did = ?
            ) AS combined_assets
            GROUP BY department_name, location_type, location_name, asset_name
            ORDER BY department_name, location_type, asset_name
        ";
        $detailedStmt = $conn->prepare($detailedQuery);
        if (!$detailedStmt) {
            error_log("Detailed query preparation failed: " . $conn->error);
        } else {
            $detailedStmt->bind_param("iiiii", $departmentId, $departmentId, $departmentId, $departmentId, $departmentId);
            $detailedStmt->execute();
            $detailedResult = $detailedStmt->get_result();
            $detailedStmt->close();
        }
    }

} catch (Exception $e) {
    error_log("Exception in deptdash.php: " . $e->getMessage());
    $display = '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>

<?php include('theader.php'); ?>

<!DOCTYPE html>
<html>
<head>
    <title>Department Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" href="assets/img/dln1.png" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .container-fluid { padding: 20px; }
        .transparent-alert { background-color: transparent !important; border: none; padding: 10px; }
        .table { margin-top: 20px; }
        .alert { margin-top: 20px; }
        .card { margin-bottom: 20px; }
        .card-icon { font-size: 1.5rem; margin-right: 10px; }

        /* Custom simpler shades for card icon backgrounds */
        .bg-primary-simple { background-color: rgb(61, 140, 251) !important; }
        .bg-success-simple { background-color: rgb(38, 211, 107) !important; }
        .bg-warning-simple { background-color: rgb(239, 222, 64) !important; }
        .bg-danger-simple { background-color: rgb(214, 80, 80) !important; }
        .bg-teal-simple { background-color: rgb(0, 139, 139) !important; }
        .bg-dark-simple { background-color: rgb(52, 58, 64) !important; }

        /* Custom simpler shades for buttons */
        .btn-primary-simple { background-color: blue; border-color: rgb(49, 106, 186); color: #fff; }
        .btn-primary-simple:hover { background-color: blueviolet; border-color: rgb(55, 42, 175); color: #fff; }
        .btn-info-simple { background-color: green; border-color: rgb(16, 134, 184); color: #fff; }
        .btn-info-simple:hover { background-color: #046A38; border-color: #82b9d9; color: #fff; }
        .btn-success-simple { background-color: purple; border-color: #a8d5ba; color: #fff; }
        .btn-success-simple:hover { background-color: plum; border-color: #8fc49f; color: #fff; }
        .btn-warning-simple { background-color: crimson; border-color: #f3e2a9; color: #fff; }
        .btn-warning-simple:hover { background-color: coral; border-color: #e6d78a; color: #fff; }
        .btn-teal-simple { background-color: rgb(0, 139, 139); border-color: rgb(0, 128, 128); color: #fff; }
        .btn-teal-simple:hover { background-color: rgb(0, 108, 108); border-color: rgb(0, 98, 98); color: #fff; }
        .btn-dark-simple { background-color: rgb(52, 58, 64); border-color: rgb(44, 49, 54); color: #fff; }
        .btn-dark-simple:hover { background-color: rgb(33, 37, 41); border-color: rgb(28, 31, 34); color: #fff; }

        /* Required for table toggling */
        #detailedTable { display: none; }

        /* Notification button and badge */
        .notification-btn {
            position: relative;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #000;
            cursor: pointer;
            padding: 0.5rem;
        }
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background-color: #dc3545;
            color: white;
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 0.75rem;
        }
        .notification-item {
            border-bottom: 1px solid #e9ecef;
            padding: 10px 0;
        }
        .notification-item:last-child {
            border-bottom: none;
        }
        .offcanvas-body {
            overflow-y: auto;
        }

        /* Responsive adjustments for notification button */
        @media (max-width: 576px) {
            .notification-btn {
                font-size: 1.2rem;
                padding: 0.3rem;
            }
            .notification-badge {
                top: -3px;
                right: -3px;
                padding: 1px 4px;
                font-size: 0.65rem;
            }
            .page-title {
                margin-right: 1rem;
                flex-grow: 1;
            }
            .d-lg-flex {
                display: flex !important;
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }
            .ms-auto {
                margin-left: auto !important;
            }
        }
    </style>
</head>
<body>
    <div class="app-main" id="main">
        <div class="container-fluid">
            <!-- Welcome Message by Me and Notification Button -->
            <div class="row">
                <div class="col-md-12 m-b-30">
                    <div class="d-block d-lg-flex flex-nowrap align-items-center">
                        <div class="page-title mr-4 pr-4 border-right">
                            <h1><?php echo $welcomeMessage; ?></h1>
                        </div>
                        <div class="ms-auto">
                            <button class="notification-btn" data-bs-toggle="offcanvas" data-bs-target="#notificationPanel" aria-controls="notificationPanel">
                                <i class="fas fa-bell"></i>
                                <?php if (!empty($approvedNotifications)): ?>
                                    <span class="notification-badge"><?php echo count($approvedNotifications); ?></span>
                                <?php endif; ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Notification Panel (Offcanvas) -->
            <div class="offcanvas offcanvas-end" tabindex="-1" id="notificationPanel" aria-labelledby="notificationPanelLabel">
                <div class="offcanvas-header">
                    <h5 class="offcanvas-title" id="notificationPanelLabel">Notifications</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
                </div>
                <div class="offcanvas-body">
                    <?php if (!empty($approvedNotifications)): ?>
                        <?php foreach ($approvedNotifications as $notification): ?>
                            <div class="notification-item alert alert-dismissible fade show" role="alert" 
                                 data-notification-id="<?php echo htmlspecialchars($notification['request_id'] . '_' . $notification['request_type']); ?>">
                                <div class="d-flex align-items-center">
                                    <div class="bg-teal-simple p-2 rounded me-3">
                                        <i class="fas fa-check-circle text-white" style="font-size: 1rem;"></i>
                                    </div>
                                    <div>
                                        <strong>Approved <?php echo htmlspecialchars($notification['request_type']); ?> Request</strong>
                                        <p class="mb-0">
                                            The request for <?php echo htmlspecialchars($notification['quantity']); ?> 
                                            <?php echo htmlspecialchars($notification['asset_name']); ?> 
                                            <?php echo $notification['location_name'] ? 'for ' . htmlspecialchars($notification['location_name']) : ''; ?> 
                                            has been approved and will be processed within 3 business days.
                                        </p>
                                    </div>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted">No new notifications.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Display Messages -->
            <?php if (!empty($display)) echo $display; ?>
            <?php if (!empty($_SESSION['message'])) { echo $_SESSION['message']; unset($_SESSION['message']); } ?>

            <!-- First Row: Total Assets Used, Special Requests, Approved Requests -->
            <div class="row mt-4">
                <div class="col-md-4 col-sm-6 col-12">
                    <div class="card card-statistics">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <p class="mb-0">Total Assets Used</p>
                                    <h3 class="mb-0"><?php echo number_format((float)$deptAssetsInUse); ?></h3>
                                </div>
                                <div class="bg-primary-simple p-3 rounded">
                                    <i class="fas fa-coins text-white" style="font-size: 1.5rem;"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 col-sm-6 col-12">
                    <div class="card card-statistics">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <p class="mb-0">Special Department Requests</p>
                                    <h3 class="mb-0"><?php echo number_format($deptSpecialRequestsCount); ?></h3>
                                </div>
                                <div class="bg-teal-simple p-3 rounded">
                                    <i class="fas fa-file-alt text-white" style="font-size: 1.5rem;"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 col-sm-6 col-12">
                    <div class="card card-statistics">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <p class="mb-0">Approved Department Requests</p>
                                    <h3 class="mb-0"><?php echo number_format($deptApprovedRequestsCount); ?></h3>
                                </div>
                                <div class="bg-success-simple p-3 rounded">
                                    <i class="fas fa-check text-white" style="font-size: 1.5rem;"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Second Row: Hold Requests, Rejected Requests -->
            <div class="row mt-4">
                <div class="col-md-4 col-sm-6 col-12">
                    <div class="card card-statistics">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <p class="mb-0">Hold Department Requests</p>
                                    <h3 class="mb-0"><?php echo number_format($deptHoldRequestsCount); ?></h3>
                                </div>
                                <div class="bg-warning-simple p-3 rounded">
                                    <i class="fas fa-clock text-white" style="font-size: 1.5rem;"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 col-sm-6 col-12">
                    <div class="card card-statistics">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <p class="mb-0">Rejected Department Requests</p>
                                    <h3 class="mb-0"><?php echo number_format($deptRejectedRequestsCount); ?></h3>
                                </div>
                                <div class="bg-danger-simple p-3 rounded">
                                    <i class="fas fa-times text-white" style="font-size: 1.5rem;"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Third Row: Department Overview and Quick Actions -->
            <div class="row mt-4">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">Department Overview</h5>
                            <div class="d-flex gap-2">
                                <button class="btn btn-info-simple btn-sm" onclick="toggleDetailed()">Detailed List</button>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-success-simple btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                                        Download As
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li><a class="dropdown-item" href="#" onclick="exportTo('excel')">Excel</a></li>
                                        <li><a class="dropdown-item" href="#" onclick="exportTo('csv')">CSV</a></li>
                                        <li><a class="dropdown-item" href="#" onclick="exportTo('pdf')">PDF</a></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <!-- Simple Table -->
                            <table id="simpleTable" class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Department Name</th>
                                        <th>Asset Name</th>
                                        <th>Quantity</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    if ($departmentId !== null && $simpleResult && $simpleResult->num_rows > 0) {
                                        while ($row = $simpleResult->fetch_assoc()) {
                                            echo '<tr>';
                                            echo '<td>' . htmlspecialchars($row['department_name']) . '</td>';
                                            echo '<td>' . htmlspecialchars($row['asset_name']) . '</td>';
                                            echo '<td>' . htmlspecialchars($row['quantity']) . '</td>';
                                            echo '</tr>';
                                        }
                                    } else {
                                        echo '<tr><td colspan="3" class="text-center">No approved assets found for your department.</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>

                            <!-- Detailed Table -->
                            <table id="detailedTable" class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Department Name</th>
                                        <th>Location Type</th>
                                        <th>Location Name</th>
                                        <th>Asset Name</th>
                                        <th>Quantity</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    if ($departmentId !== null && $detailedResult && $detailedResult->num_rows > 0) {
                                        while ($row = $detailedResult->fetch_assoc()) {
                                            echo '<tr>';
                                            echo '<td>' . htmlspecialchars($row['department_name']) . '</td>';
                                            echo '<td>' . htmlspecialchars($row['location_type']) . '</td>';
                                            echo '<td>' . ($row['location_name'] ? htmlspecialchars($row['location_name']) : 'N/A') . '</td>';
                                            echo '<td>' . htmlspecialchars($row['asset_name']) . '</td>';
                                            echo '<td>' . htmlspecialchars($row['quantity']) . '</td>';
                                            echo '</tr>';
                                        }
                                    } else {
                                        echo '<tr><td colspan="5" class="text-center">No approved assets found for your department.</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title">Quick Actions</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-12 mb-3">
                                    <a href="reqdept.php" class="btn btn-primary-simple btn-block">
                                        <i class="fas fa-building"></i> Request Department Assets
                                    </a>
                                </div>
                                <div class="col-12 mb-3">
                                    <a href="reqlab.php" class="btn btn-info-simple btn-block">
                                        <i class="fas fa-flask"></i> Request Lab Assets
                                    </a>
                                </div>
                                <div class="col-12 mb-3">
                                    <a href="reqclass.php" class="btn btn-success-simple btn-block">
                                        <i class="fas fa-chalkboard"></i> Request Classroom Assets
                                    </a>
                                </div>
                                <div class="col-12 mb-3">
                                    <a href="reqoff.php" class="btn btn-warning-simple btn-block">
                                        <i class="fas fa-briefcase"></i> Request Office Assets
                                    </a>
                                </div>
                                <div class="col-12 mb-3">
                                    <a href="special_request.php" class="btn btn-teal-simple btn-block">
                                        <i class="fas fa-file-alt"></i> Request Special Assets
                                    </a>
                                </div>
                                <div class="col-12 mb-3">
                                    <button class="btn btn-dark-simple btn-block" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                                        <i class="fas fa-lock"></i> Change Password
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Change Password Modal -->
            <div class="modal fade" id="changePasswordModal" tabindex="-1" aria-labelledby="changePasswordModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="changePasswordModalLabel">Change Password</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <form id="changePasswordForm" action="change_password_handler.php" method="POST">
                                <div class="mb-3">
                                    <label for="currentPassword" class="form-label">Current Password</label>
                                    <input type="password" class="form-control" id="currentPassword" name="current_password" required>
                                </div>
                                <div class="mb-3">
                                    <label for="newPassword" class="form-label">New Password</label>
                                    <input type="password" class="form-control" id="newPassword" name="new_password" required minlength="6">
                                </div>
                                <div class="mb-3">
                                    <label for="confirmPassword" class="form-label">Confirm New Password</label>
                                    <input type="password" class="form-control" id="confirmPassword" name="confirm_password" required minlength="6">
                                </div>
                                <div id="passwordError" class="text-danger d-none">New password and confirm password do not match.</div>
                            </form>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="submit" class="btn btn-primary-simple" form="changePasswordForm">Change Password</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer class="footer bg-light py-3 mt-4">
        <div class="container">
            <div class="row">
                <div class="col-12 col-sm-6 text-center text-sm-start">
                    <p>© Copyright 2019. All rights reserved.</p>
                </div>
                <div class="col-12 col-sm-6 text-center text-sm-end">
                    <p><a target="_blank" href="https://www.templateshub.net">Templates Hub</a></p>
                </div>
            </div>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
    <script src="assets/js/vendors.js"></script>
    <script src="assets/js/app.js"></script>
    <script>
    function toggleDetailed() {
        const simpleTable = document.getElementById('simpleTable');
        const detailedTable = document.getElementById('detailedTable');
        const button = document.querySelector('.btn-info-simple');
        
        if (detailedTable.style.display === 'none') {
            simpleTable.style.display = 'none';
            detailedTable.style.display = 'table';
            button.textContent = 'Simple List';
        } else {
            simpleTable.style.display = 'table';
            detailedTable.style.display = 'none';
            button.textContent = 'Detailed List';
        }
    }

    function exportTo(format) {
        const { jsPDF } = window.jspdf;
        let table = document.querySelector('.table:not([style*="display: none"])');
        let data = [];
        let headers = [];
        
        table.querySelectorAll('thead th').forEach(th => {
            headers.push(th.innerText);
        });
        data.push(headers);
        
        table.querySelectorAll('tbody tr').forEach(tr => {
            let row = [];
            tr.querySelectorAll('td').forEach(td => {
                row.push(td.innerText.trim());
            });
            data.push(row);
        });
        
        if (format === 'excel' || format === 'csv') {
            let csvContent = data.map(row => 
                row.map(cell => `"${cell.replace(/"/g, '""').trim()}"`).join(',')
            ).join('\n');
            
            if (format === 'excel') {
                csvContent = '\uFEFF' + csvContent;
            }
            
            let blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            let link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = `approved_assets_${new Date().toISOString().slice(0,10)}.${format}`;
            link.click();
        } else if (format === 'pdf') {
            const doc = new jsPDF();
            doc.autoTable({
                head: [headers],
                body: data.slice(1),
                styles: { fontSize: 8 },
                headStyles: { fillColor: [4, 106, 56] }
            });
            doc.save(`approved_assets_${new Date().toISOString().slice(0,10)}.pdf`);
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        // Handle notification dismissal
        document.querySelectorAll('.notification-item.alert-dismissible').forEach(alert => {
            alert.querySelector('.btn-close').addEventListener('click', function() {
                const notificationId = alert.getAttribute('data-notification-id');
                if (notificationId) {
                    fetch('dismiss_notification.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `notification_id=${encodeURIComponent(notificationId)}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert.remove();
                            const badge = document.querySelector('.notification-badge');
                            const notificationItems = document.querySelectorAll('.notification-item');
                            if (notificationItems.length > 0) {
                                badge.textContent = notificationItems.length;
                            } else {
                                badge?.remove();
                            }
                        } else {
                            console.error('Failed to dismiss notification:', data.error);
                        }
                    })
                    .catch(error => console.error('Error dismissing notification:', error));
                }
            });
        });

        // Handle password change form validation
        const changePasswordForm = document.getElementById('changePasswordForm');
        const newPassword = document.getElementById('newPassword');
        const confirmPassword = document.getElementById('confirmPassword');
        const passwordError = document.getElementById('passwordError');

        changePasswordForm.addEventListener('submit', function(event) {
            if (newPassword.value !== confirmPassword.value) {
                event.preventDefault();
                passwordError.classList.remove('d-none');
            } else {
                passwordError.classList.add('d-none');
            }
        });
    });
    </script>
</body>
</html>

<?php
// Close the database connection safely
if ($conn instanceof mysqli) {
    $conn->close();
}
?>