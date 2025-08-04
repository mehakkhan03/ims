<?php
session_start();
$display = "";

// Debug: Log file loading
error_log("Loading dashboard.php at " . date('Y-m-d H:i:s'));

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "inventory";

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    // Sanitize time period
$valid_periods = ['all', 'daily', 'monthly', 'annual'];
$time_period = isset($_GET['time_period']) && in_array($_GET['time_period'], $valid_periods) ? $_GET['time_period'] : 'all';

// New parameters for date selection
$selected_year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$selected_month = isset($_GET['month']) ? intval($_GET['month']) : date('m');
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

$date_filter = "";
if ($time_period === 'daily') {
    // Validate date format YYYY-MM-DD
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
        $date_filter = " AND DATE(CONCAT(year, '-', month, '-', date)) = '" . $conn->real_escape_string($selected_date) . "'";
    } else {
        $date_filter = " AND DATE(CONCAT(year, '-', month, '-', date)) = CURDATE()";
    }
} elseif ($time_period === 'monthly') {
    if ($selected_year > 0 && $selected_month > 0 && $selected_month <= 12) {
        $date_filter = " AND year = " . $selected_year . " AND month = " . $selected_month;
    } else {
        $date_filter = " AND year = YEAR(CURDATE()) AND month = MONTH(CURDATE())";
    }
} elseif ($time_period === 'annual') {
    if ($selected_year > 0) {
        $date_filter = " AND year = " . $selected_year;
    } else {
        $date_filter = " AND year = YEAR(CURDATE())";
    }
}

    // Total Assets In Use
    $totalAssetsInUseQuery = "
        SELECT SUM(quantity) AS total
        FROM (
            SELECT COALESCE(ad.nqty, 0) AS quantity
            FROM add_dept ad
            WHERE ad.status = 'Approved' $date_filter
            UNION ALL
            SELECT COALESCE(al.aqty, 0) AS quantity
            FROM add_lab al
            WHERE al.status = 'Approved' $date_filter
            UNION ALL
            SELECT COALESCE(ao.aqty, 0) AS quantity
            FROM add_office ao
            WHERE ao.status = 'Approved' $date_filter
            UNION ALL
            SELECT COALESCE(ap.aqty, 0) AS quantity
            FROM add_public ap
            WHERE ap.status = 'Approved' $date_filter
            UNION ALL
            SELECT COALESCE(cr.aqty, 0) AS quantity
            FROM classroom cr
            WHERE cr.status = 'Approved' $date_filter
            UNION ALL
            SELECT COALESCE(sr.quantity, 0) AS quantity
            FROM special_request sr
            WHERE sr.status = 'Approved' $date_filter
        ) AS approved_assets
    ";
    error_log("Executing totalAssetsInUseQuery: $totalAssetsInUseQuery");
    $totalAssetsInUseResult = $conn->query($totalAssetsInUseQuery);
    if (!$totalAssetsInUseResult) {
        error_log("Total Assets In Use query error: " . $conn->error);
        throw new Exception("Failed to execute Total Assets In Use query: " . $conn->error);
    }
    $totalAssetsInUse = $totalAssetsInUseResult && $totalAssetsInUseResult->num_rows > 0 ? $totalAssetsInUseResult->fetch_assoc()['total'] : 0;

    // Base Assets
    $baseAssetsQuery = "SELECT COALESCE(SUM(aqty), 0) as total FROM asset";
    $baseAssetsResult = $conn->query($baseAssetsQuery);
    if (!$baseAssetsResult) {
        error_log("Base Assets query error: " . $conn->error);
        throw new Exception("Failed to execute Base Assets query: " . $conn->error);
    }
    $baseAssets = $baseAssetsResult && $baseAssetsResult->num_rows > 0 ? $baseAssetsResult->fetch_assoc()['total'] : 0;

    // Total Assets
    $totalAssets = $baseAssets + $totalAssetsInUse;

    // New Complaints
    $newComplaintsQuery = "SELECT COALESCE(COUNT(*), 0) FROM complain WHERE status = 'Pending' $date_filter";
    $newComplaintsResult = $conn->query($newComplaintsQuery);
    if (!$newComplaintsResult) {
        error_log("New Complaints query error: " . $conn->error);
        throw new Exception("Failed to execute New Complaints query: " . $conn->error);
    }
    $newComplaintsCount = $newComplaintsResult->fetch_row()[0];

    // Hold Special Requests
    $newSpecialRequestsQuery = "SELECT COALESCE(COUNT(*), 0) FROM special_request WHERE status = 'Hold' $date_filter";
    $newSpecialRequestsResult = $conn->query($newSpecialRequestsQuery);
    if (!$newSpecialRequestsResult) {
        error_log("Hold Special Requests query error: " . $conn->error);
        throw new Exception("Failed to execute Hold Special Requests query: " . $conn->error);
    }
    $newSpecialRequestsCount = $newSpecialRequestsResult->fetch_row()[0];

    // Approved Special Requests
    $approvedSpecialRequestsQuery = "SELECT COALESCE(COUNT(*), 0) FROM special_request WHERE status = 'Approved' $date_filter";
    $approvedSpecialRequestsResult = $conn->query($approvedSpecialRequestsQuery);
    if (!$approvedSpecialRequestsResult) {
        error_log("Approved Special Requests query error: " . $conn->error);
        throw new Exception("Failed to execute Approved Special Requests query: " . $conn->error);
    }
    $approvedSpecialRequestsCount = $approvedSpecialRequestsResult->fetch_row()[0];

    // New Requests
    $newRequestsQuery = "
        SELECT COALESCE(COUNT(*), 0) FROM (
            SELECT id FROM add_dept WHERE status = 'New' $date_filter
            UNION ALL
            SELECT id FROM add_lab WHERE status = 'New' $date_filter
            UNION ALL
            SELECT id FROM add_office WHERE status = 'New' $date_filter
            UNION ALL
            SELECT id FROM add_public WHERE status = 'New' $date_filter
            UNION ALL
            SELECT id FROM classroom WHERE status = 'New' $date_filter
        ) AS new_requests
    ";
    $newRequestsResult = $conn->query($newRequestsQuery);
    if (!$newRequestsResult) {
        error_log("New Requests query error: " . $conn->error);
        throw new Exception("Failed to execute New Requests query: " . $conn->error);
    }
    $newRequestsCount = $newRequestsResult->fetch_row()[0];

    // Approved Requests
    $approvedRequestsQuery = "
        SELECT COALESCE(COUNT(*), 0) FROM (
            SELECT id FROM add_dept WHERE status = 'Approved' $date_filter
            UNION ALL
            SELECT id FROM add_lab WHERE status = 'Approved' $date_filter
            UNION ALL
            SELECT id FROM add_office WHERE status = 'Approved' $date_filter
            UNION ALL
            SELECT id FROM add_public WHERE status = 'Approved' $date_filter
            UNION ALL
            SELECT id FROM classroom WHERE status = 'Approved' $date_filter
            UNION ALL
            SELECT sr_id FROM special_request WHERE status = 'Approved' $date_filter
        ) AS approved_requests
    ";
    $approvedRequestsResult = $conn->query($approvedRequestsQuery);
    if (!$newRequestsResult) {
        error_log("Approved Requests query error: " . $conn->error);
        throw new Exception("Failed to execute Approved Requests query: " . $conn->error);
    }
    $approvedRequestsCount = $approvedRequestsResult->fetch_row()[0];

    // Hold Requests
    $holdRequestsQuery = "
        SELECT COALESCE(COUNT(*), 0) FROM (
            SELECT id FROM add_dept WHERE status = 'Hold' $date_filter
            UNION ALL
            SELECT id FROM add_lab WHERE status = 'Hold' $date_filter
            UNION ALL
            SELECT id FROM add_office WHERE status = 'Hold' $date_filter
            UNION ALL
            SELECT id FROM add_public WHERE status = 'Hold' $date_filter
            UNION ALL
            SELECT id FROM classroom WHERE status = 'Hold' $date_filter
            UNION ALL
            SELECT sr_id FROM special_request WHERE status = 'Hold' $date_filter
        ) AS hold_requests
    ";
    $holdRequestsResult = $conn->query($holdRequestsQuery);
    if (!$holdRequestsResult) {
        error_log("Hold Requests query error: " . $conn->error);
        throw new Exception("Failed to execute Hold Requests query: " . $conn->error);
    }
    $holdRequestsCount = $holdRequestsResult->fetch_row()[0];

    // Rejected Requests
    $rejectedRequestsQuery = "
        SELECT COALESCE(COUNT(*), 0) FROM (
            SELECT id FROM add_dept WHERE status = 'Rejected' $date_filter
            UNION ALL
            SELECT id FROM add_lab WHERE status = 'Rejected' $date_filter
            UNION ALL
            SELECT id FROM add_office WHERE status = 'Rejected' $date_filter
            UNION ALL
            SELECT id FROM add_public WHERE status = 'Rejected' $date_filter
            UNION ALL
            SELECT id FROM classroom WHERE status = 'Rejected' $date_filter
        ) AS rejected_requests
    ";
    error_log("Executing rejectedRequestsQuery: $rejectedRequestsQuery");
    $rejectedRequestsResult = $conn->query($rejectedRequestsQuery);
    if (!$rejectedRequestsResult) {
        error_log("Rejected Requests query error: " . $conn->error);
        throw new Exception("Failed to execute Rejected Requests query: " . $conn->error);
    }
    $rejectedRequestsCount = $rejectedRequestsResult->fetch_row()[0];

    // Get all possible assets from asset table to ensure consistency
    $allAssetsQuery = "SELECT aname FROM asset ORDER BY aname";
    $allAssetsResult = $conn->query($allAssetsQuery);
    $all_assets = [];
    while ($row = $allAssetsResult->fetch_assoc()) {
        $all_assets[] = $row['aname'];
    }

    // Chart data for regular requests
    $regularChartQuery = "
        SELECT asset_name, status, SUM(count) as total_count
        FROM (
            SELECT a.aname as asset_name, ad.status, COUNT(*) as count
            FROM add_dept ad
            JOIN asset a ON ad.aid = a.aid
            WHERE 1=1 $date_filter
            GROUP BY a.aname, ad.status
            UNION ALL
            SELECT a.aname as asset_name, al.status, COUNT(*) as count
            FROM add_lab al
            JOIN asset a ON al.aid = a.aid
            WHERE 1=1 $date_filter
            GROUP BY a.aname, al.status
            UNION ALL
            SELECT a.aname as asset_name, ao.status, COUNT(*) as count
            FROM add_office ao
            JOIN asset a ON ao.aid = a.aid
            WHERE 1=1 $date_filter
            GROUP BY a.aname, ao.status
            UNION ALL
            SELECT a.aname as asset_name, ap.status, COUNT(*) as count
            FROM add_public ap
            JOIN asset a ON ap.aid = a.aid
            WHERE 1=1 $date_filter
            GROUP BY a.aname, ap.status
            UNION ALL
            SELECT a.aname as asset_name, cr.status, COUNT(*) as count
            FROM classroom cr
            JOIN asset a ON cr.aid = a.aid
            WHERE 1=1 $date_filter
            GROUP BY a.aname, cr.status
        ) as subquery
        GROUP BY asset_name, status
    ";
    error_log("Executing regularChartQuery: $regularChartQuery");
    $regularChartResult = $conn->query($regularChartQuery);
    if (!$regularChartResult) {
        error_log("Regular Chart query error: " . $conn->error);
        throw new Exception("Failed to execute regular chart query: " . $conn->error);
    }

    // Chart data for special requests
    $specialChartQuery = "
        SELECT a.aname as asset_name, sr.status, COUNT(*) as count
        FROM special_request sr
        JOIN asset a ON LOWER(TRIM(sr.asset_name)) = LOWER(TRIM(a.aname))
        WHERE sr.status IN ('Hold', 'Approved') $date_filter
        GROUP BY a.aname, sr.status
    ";
    error_log("Executing specialChartQuery: $specialChartQuery");
    $specialChartResult = $conn->query($specialChartQuery);
    if (!$specialChartResult) {
        error_log("Special Chart query error: " . $conn->error);
        throw new Exception("Failed to execute special chart query: " . $conn->error);
    }

    // Process regular request data
    $regular_data = [];
    while ($row = $regularChartResult->fetch_assoc()) {
        $regular_data[$row['asset_name']][$row['status']] = (int)$row['total_count'];
    }

    // Process special request data
    $special_data = [];
    while ($row = $specialChartResult->fetch_assoc()) {
        $special_data[$row['asset_name']][$row['status']] = (int)$row['count'];
    }

    // Combine all assets
    $labels = $all_assets; // Use all assets from asset table

    // Define statuses
    $regular_statuses = ['New', 'Hold', 'Rejected', 'Approved'];
    $special_statuses = ['Hold', 'Approved'];
    $all_statuses = array_merge(
        array_map(function($status) { return 'Regular_' . $status; }, $regular_statuses),
        array_map(function($status) { return 'Special_' . $status; }, $special_statuses)
    );

    // Define colors
    $colors = [
        'Regular_New' => 'rgba(54, 162, 235, 0.6)',      // Blue for Regular New
        'Regular_Hold' => 'rgba(255, 206, 86, 0.6)',     // Yellow for Regular Hold
        'Regular_Rejected' => 'rgba(255, 99, 132, 0.6)', // Red for Regular Rejected
        'Regular_Approved' => 'rgba(75, 192, 192, 0.6)', // Teal for Regular Approved
        'Special_Hold' => 'rgba(255, 165, 0, 0.6)',      // Orange for Special Hold
        'Special_Approved' => 'rgba(50, 205, 50, 0.6)',  // Green for Special Approved
    ];

    // Prepare datasets
    $datasets = [];
    // Regular request datasets
    foreach ($regular_statuses as $status) {
        $color = $colors['Regular_' . $status] ?? 'rgba(128, 128, 128, 0.6)';
        $dataset = [
            'label' => 'Regular ' . $status,
            'data' => [],
            'backgroundColor' => $color,
            'borderColor' => str_replace('0.6', '1', $color),
            'borderWidth' => 1,
            'stack' => 'Regular'
        ];
        foreach ($labels as $asset) {
            $dataset['data'][] = isset($regular_data[$asset][$status]) ? $regular_data[$asset][$status] : 0;
        }
        $datasets[] = $dataset;
    }
    // Special request datasets
    foreach ($special_statuses as $status) {
        $color = $colors['Special_' . $status] ?? 'rgba(128, 128, 128, 0.6)';
        $dataset = [
            'label' => 'Special ' . $status,
            'data' => [],
            'backgroundColor' => $color,
            'borderColor' => str_replace('0.6', '1', $color),
            'borderWidth' => 1,
            'stack' => 'Special'
        ];
        foreach ($labels as $asset) {
            $dataset['data'][] = isset($special_data[$asset][$status]) ? $special_data[$asset][$status] : 0;
        }
        $datasets[] = $dataset;
    }
    error_log("Chart datasets: " . json_encode($datasets));

} catch (Exception $e) {
    $display = '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    error_log("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Inventory Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" href="assets/img/dln1.png" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Icon styling for count cards */
        .card-icon { 
            font-size: 1.5rem; 
            margin-right: 10px; 
        }
        /* Center icons in bg-* containers */
        .bg-primary, .bg-secondary, .bg-success, .bg-warning, .bg-danger, .bg-info {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        /* Responsive font sizes */
        .card-title, h1, h3, p {
            font-size: calc(1rem + 0.5vw);
        }
        /* Card padding */
        .card-body {
            padding: 1.25rem;
        }
        /* Quick Actions container */
        .quick-actions {
            padding: 1.25rem; /* M Match card-body padding */
        }
        .quick-actions .btn {
            width: 100%; /* Full-width buttons */
            text-align: center; /* Center t E xt and icon */
            padding: 0.75rem; /* Comfortable button size */
        }
        /* Responsive table */
        .table-responsive {
            overflow-x: auto;
        }
        /* Chart container */
        .chart-container {
            position: relative;
            width: 100%;
            min-height: 300px;
            overflow: visible;
            margin-top: 0.5rem;
        }
        /* Phone screen adjustments (≤576px) */
        @media (max-width: 576px) {
            .card-statistics {
                margin-bottom: 0.5rem;
            }
            .btn {
                font-size: 0.9rem;
                padding: 0.5rem;
            }
            .form-select {
                font-size: 0.9rem;
                padding: 0.25rem;
            }
            .card-header {
                padding: 0.5rem;
            }
            .quick-actions .btn {
                margin-bottom: 0.5rem;
            }
            .container-fluid {
                padding-left: 0.5rem;
                padding-right: 0.5rem;
            }
            .row {
                margin-left: -0.5rem;
                margin-right: -0.5rem;
            }
            .col-12 {
                padding-left: 0.5rem;
                padding-right: 0.5rem;
            }
        }
        /* Tablet screen adjustments (576px–768px) */
        @media (min-width: 576px) and (max-width: 768px) {
            .card-statistics {
                margin-bottom: 0.75rem;
            }
            .card-body {
                padding: 1rem;
            }
            .card-statistics .col {
                padding-left: 0.5rem;
                padding-right: 0.5rem;
            }
            .quick-actions .btn {
                margin-bottom: 0.5rem;
            }
        }
        /* Footer adjustments */
        .footer {
            font-size: 0.9rem;
            padding: 1rem;
        }
    </style>
</head>
<body>
    <?php include('header.php'); ?>

    <div class="app-main" id="main">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12 mb-4">
                    <div class="d-flex flex-column flex-md-row align-items-center">
                        <div class="page-title me-md-4 pe-md-4 border-md-end">
                            <h1>Inventory Dashboard</h1>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($display)) echo $display; ?>

            <!-- First Row: Total Assets, New Complaints, New Requests, H Hold Special Requests -->
            <div class="row">
                <div class="col-md-3">
                    <div class="card card-statistics">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <p class="mb-0">Total Assets (In Use/Total)</p>
                                    <h3 class="mb-0"><?php echo number_format((float)$totalAssetsInUse) . ' / ' . number_format((float)$totalAssets); ?></h3>
                                </div>
                                <div class="bg-primary p-3 rounded">
                                    <i class="fas fa-coins text-white card-icon"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-statistics">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <p class="mb-0">New Complaints</p>
                                    <h3 class="mb-0"><?php echo number_format($newComplaintsCount); ?></h3>
                                </div>
                                <div class="bg-secondary p-3 rounded">
                                    <i class="fas fa-exclamation-triangle text-white card-icon"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-statistics">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <p class="mb-0">New Requests</p>
                                    <h3 class="mb-0"><?php echo number_format($newRequestsCount); ?></h3>
                                </div>
                                <div class="bg-primary p-3 rounded">
                                    <i class="fas fa-bell text-white card-icon"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-statistics">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <p class="mb-0">Hold Special Requests</p>
                                    <h3 class="mb-0"><?php echo number_format($newSpecialRequestsCount); ?></h3>
                                </div>
                                <div class="bg-info p-3 rounded">
                                    <i class="fas fa-file-alt text-white card-icon"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Second Row: Approved Requests, Hold Requests, Rejected Requests, A Approved Special Requests -->
            <div class="row mt-4">
                <div class="col-md-3">
                    <div class="card card-statistics">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <p class="mb-0">Approved Requests</p>
                                    <h3 class="mb-0"><?php echo number_format($approvedRequestsCount); ?></h3>
                                </div>
                                <div class="bg-success p-3 rounded">
                                    <i class="fas fa-check text-white card-icon"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-statistics">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <p class="mb-0">Hold Requests</p>
                                    <h3 class="mb-0"><?php echo number_format($holdRequestsCount); ?></h3>
                                </div>
                                <div class="bg-warning p-3 rounded">
                                    <i class="fas fa-clock text-white card-icon"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-statistics">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <p class="mb-0">Rejected Requests</p>
                                    <h3 class="mb-0"><?php echo number_format($rejectedRequestsCount); ?></h3>
                                </div>
                                <div class="bg-danger p-3 rounded">
                                    <i class="fas fa-times text-white card-icon"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-statistics">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <p class="mb-0">Approved Special Requests</p>
                                    <h3 class="mb-0"><?php echo number_format($approvedSpecialRequestsCount); ?></h3>
                                </div>
                                <div class="bg-success p-3 rounded">
                                    <i class="fas fa-check-circle text-white card-icon"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Graph and Quic K Actions -->
            <div class="row mt-4">
                <div class="col-12 col-lg-8 mb-4 mb-lg-0">
                    <div class="card h-100">
                        <div class="card-header">
                            <div class="d-flex flex-column flex-md-row justify-content-between align-items-center">
                                <h5 class="card-title mb-2 mb-md-0">Request Status by Asset</h5>
<select id="timePeriod" class="form-select w-auto">
    <option value="all" <?php echo $time_period === 'all' ? 'selected' : ''; ?>>All Time</option>
    <option value="daily" <?php echo $time_period === 'daily' ? 'selected' : ''; ?>>Daily</option>
    <option value="monthly" <?php echo $time_period === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
    <option value="annual" <?php echo $time_period === 'annual' ? 'selected' : ''; ?>>Annual</option>
</select>

<!-- Additional date selectors -->
<div id="dateSelectors" class="mt-2">
    <?php if ($time_period === 'annual') : ?>
        <select id="yearSelect" class="form-select w-auto d-inline-block">
            <?php
            $currentYear = date('Y');
            for ($y = $currentYear; $y >= $currentYear - 10; $y--) {
                $selected = ($y == $selected_year) ? 'selected' : '';
                echo "<option value=\"$y\" $selected>$y</option>";
            }
            ?>
        </select>
    <?php elseif ($time_period === 'monthly') : ?>
        <select id="monthSelect" class="form-select w-auto d-inline-block me-2">
            <?php
            $months = [
                1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
                5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
                9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
            ];
            foreach ($months as $num => $name) {
                $selected = ($num == $selected_month) ? 'selected' : '';
                echo "<option value=\"$num\" $selected>$name</option>";
            }
            ?>
        </select>
        <select id="yearSelect" class="form-select w-auto d-inline-block">
            <?php
            $currentYear = date('Y');
            for ($y = $currentYear; $y >= $currentYear - 10; $y--) {
                $selected = ($y == $selected_year) ? 'selected' : '';
                echo "<option value=\"$y\" $selected>$y</option>";
            }
            ?>
        </select>
    <?php elseif ($time_period === 'daily') : ?>
        <input type="date" id="dateSelect" class="form-control w-auto d-inline-block" value="<?php echo htmlspecialchars($selected_date); ?>">
    <?php endif; ?>
</div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="requestChart"></canvas>
                            </div>
                            <div class="mt-3">
                                <button id="downloadTable" class="btn btn-primary">Download Data as CSV</button>
                            </div>
                            <div class="table-responsive mt-3">
                                <table class="table table-bordered">
                                    <thead>
                                        <tr>
                                            <th>Asset</th>
                                            <?php foreach ($all_statuses as $status) : ?>
                                                <th><?php echo htmlspecialchars(str_replace('_', ' ', $status)); ?></th>
                                            <?php endforeach; ?>
                                            <th>Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($labels as $asset) : ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($asset); ?></td>
                                                <?php
                                                $total = 0;
                                                foreach ($regular_statuses as $status) {
                                                    $count = isset($regular_data[$asset][$status]) ? $regular_data[$asset][$status] : 0;
                                                    echo '<td>' . number_format($count) . '</td>';
                                                    $total += $count;
                                                }
                                                foreach ($special_statuses as $status) {
                                                    $count = isset($special_data[$asset][$status]) ? $special_data[$asset][$status] : 0;
                                                    echo '<td>' . number_format($count) . '</td>';
                                                    $total += $count;
                                                }
                                                ?>
                                                <td><?php echo number_format($total); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-4">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="card-title">Quick Actions</h5>
                        </div>
                        <div class="card-body quick-actions">
                            <div class="row">
                                <div class="col-12 mb-3">
                                    <a href="addassets.php" class="btn btn-primary w-100">
                                        <i class="fas fa-plus me-2"></i> Add Assets
                                    </a>
                                </div>
                                <div class="col-12 mb-3">
                                    <a href="update_assets.php" class="btn btn-info w-100">
                                        <i class="fas fa-edit me-2"></i> Update Assets
                                    </a>
                                </div>
                                <div class="col-12 mb-3">
                                    <a href="addinventory.php" class="btn btn-success w-100">
                                        <i class="fas fa-box-open me-2"></i> View Inventory
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer class="footer mt-4">
        <div class="container-fluid">
            <div class="row text-center text-sm-start">
                <div class="col-12 col-sm-6 mb-2 mb-sm-0">
                    <p>© Copyright 2019. All rights reserved.</p>
                </div>
                <div class="col-12 col-sm-6 text-sm-end">
                    <p><a target="_blank" href="https://www.templateshub.net">Templates Hub</a></p>
                </div>
            </div>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js"></script>
    <script src="assets/js/vendors.js"></script>
    <script src="assets/js/app.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Delay chart initialization to ensure DOM is fully rendered
        setTimeout(function() {
            const labels = <?php echo json_encode($labels); ?>;
            const datasets = <?php echo json_encode($datasets); ?>;
            const ctx = document.getElementById('requestChart');

            // Log chart data for debugging
            console.log('Chart labels:', labels);
            console.log('Chart datasets:', datasets);

            if (ctx) {
                const requestChart = new Chart(ctx.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: datasets
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: {
                                stacked: true,
                                title: {
                                    display: true,
                                    text: 'Assets',
                                    font: {
                                        size: function(context) {
                                            return window.innerWidth < 576 ? 12 : 14;
                                        }
                                    }
                                },
                                ticks: {
                                    font: {
                                        size: function(context) {
                                            return window.innerWidth < 576 ? 10 : 12;
                                        }
                                    }
                                },
                                grouped: true
                            },
                            y: {
                                stacked: true,
                                title: {
                                    display: true,
                                    text: 'Number of Requests',
                                    font: {
                                        size: function(context) {
                                            return window.innerWidth < 576 ? 12 : 14;
                                        }
                                    }
                                },
                                ticks: {
                                    font: {
                                        size: function(context) {
                                            return window.innerWidth < 576 ? 10 : 12;
                                        }
                                    }
                                },
                                beginAtZero: true
                            }
                        },
                        plugins: {
                            legend: {
                                display: true,
                                labels: {
                                    font: {
                                        size: function(context) {
                                            return window.innerWidth < 576 ? 10 : 12;
                                        }
                                    }
                                }
                            },
                            tooltip: {
                                enabled: true,
                                callbacks: {
                                    label: function(context) {
                                        const label = context.dataset.label || '';
                                        const value = context.parsed.y || 0;
                                        return `${label}: ${value}`;
                                    }
                                }
                            }
                        },
                        barPercentage: 0.45,
                        categoryPercentage: 0.9,
                        layout: {
                            padding: {
                                top: 10,
                                bottom: 10
                            }
                        }
                    }
                });
            } else {
                console.error('Canvas element not found');
            }

// Time period selector
const timePeriodSelect = document.getElementById('timePeriod');
const yearSelect = document.getElementById('yearSelect');
const monthSelect = document.getElementById('monthSelect');
const dateSelect = document.getElementById('dateSelect');

function buildUrl() {
    let url = '?time_period=' + encodeURIComponent(timePeriodSelect.value);
    if (timePeriodSelect.value === 'annual' && yearSelect) {
        url += '&year=' + encodeURIComponent(yearSelect.value);
    } else if (timePeriodSelect.value === 'monthly' && yearSelect && monthSelect) {
        url += '&year=' + encodeURIComponent(yearSelect.value);
        url += '&month=' + encodeURIComponent(monthSelect.value);
    } else if (timePeriodSelect.value === 'daily' && dateSelect) {
        url += '&date=' + encodeURIComponent(dateSelect.value);
    }
    return url;
}

if (timePeriodSelect) {
    timePeriodSelect.addEventListener('change', function() {
        window.location.href = buildUrl();
    });
}
if (yearSelect) {
    yearSelect.addEventListener('change', function() {
        window.location.href = buildUrl();
    });
}
if (monthSelect) {
    monthSelect.addEventListener('change', function() {
        window.location.href = buildUrl();
    });
}
if (dateSelect) {
    dateSelect.addEventListener('change', function() {
        window.location.href = buildUrl();
    });
}

            // Download table as CSV
            const downloadTableBtn = document.getElementById('downloadTable');
            if (downloadTableBtn) {
                downloadTableBtn.addEventListener('click', function() {
                    const data = [
                        ['Asset', <?php echo "'" . implode("','", array_map(function($status) { return str_replace('_', ' ', $status); }, $all_statuses)) . "'"; ?>, 'Total'],
                        <?php
                        $csv_rows = [];
                        foreach ($labels as $asset) {
                            $row = ["'" . addslashes($asset) . "'"];
                            $total = 0;
                            foreach ($regular_statuses as $status) {
                                $count = isset($regular_data[$asset][$status]) ? $regular_data[$asset][$status] : 0;
                                $row[] = $count;
                                $total += $count;
                            }
                            foreach ($special_statuses as $status) {
                                $count = isset($special_data[$asset][$status]) ? $special_data[$asset][$status] : 0;
                                $row[] = $count;
                                $total += $count;
                            }
                            $row[] = $total;
                            $csv_rows[] = '[' . implode(',', $row) . ']';
                        }
                        echo implode(',', $csv_rows);
                        ?>
                    ];

                    let csvContent = "data:text/csv;charset=utf-8,";
                    data.forEach(row => {
                        csvContent += row.join(",") + "\r\n";
                    });

                    const encodedUri = encodeURI(csvContent);
                    const link = document.createElement("a");
                    link.setAttribute("href", encodedUri);
                    link.setAttribute("download", "asset_request_status_data.csv");
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                });
            }
        }, 100); // 100ms delay to ensure DOM rendering
    });
    </script>
</body>
</html>

<?php $conn->close(); ?>