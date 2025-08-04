<?php
// Start session and enable error reporting for debugging
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log to confirm file execution and session data
error_log("Executing mcomplaint.php version: 2025-05-08");
error_log("Session data: " . json_encode($_SESSION));

// Handle logout
if (isset($_POST['logout'])) {
    error_log("User logging out. Destroying session.");
    session_unset();
    session_destroy();
    header("Location: index.php");
    exit();
}

// Initialize display message
$display = "";

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "inventory";

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    $conn->set_charset("utf8mb4");

    // Initialize user variables
    $uname = null;
    $uemail = null;

    // Check if user is logged in
    if (isset($_SESSION['username']) && !empty(trim($_SESSION['username']))) {
        // Sanitize username
        $session_username = trim($_SESSION['username']);
        error_log("Processing for username: '$session_username'");

        // Fetch username from acreate table (case-insensitive)
        $user_query = $conn->prepare("SELECT ausername AS uname, aemail FROM acreate WHERE LOWER(ausername) = LOWER(?)");
        $user_query->bind_param("s", $session_username);
        $user_query->execute();
        if ($user_query->error) {
            error_log("User query error: " . $user_query->error);
            throw new Exception("Failed to fetch user data");
        }
        $user_result = $user_query->get_result();
        if ($user_result && $user_result->num_rows > 0) {
            $user_data = $user_result->fetch_assoc();
            $uname = $user_data['uname'] ?? null;
            $uemail = $user_data['aemail'] ?? null;
            error_log("User found: uname='$uname', aemail='" . ($uemail ?? 'null') . "'");
        } else {
            error_log("No user found in acreate for username: '$session_username'");
            $display = '<div class="alert alert-warning">User \'' . htmlspecialchars($session_username) . '\' not found in database. Please log out and log in again or contact support.</div>';
        }
        $user_query->close();
    } else {
        error_log("No valid session username found. Allowing access with limited functionality.");
        $display = '<div class="alert alert-warning">You are not logged in. Please log in for full functionality.</div>';
    }

    // Handle status update
    if (isset($_POST['update_status'])) {
        if (!$uname) {
            $display = '<div class="alert alert-danger">You must be logged in to update complaint status.</div>';
        } else {
            $co_id = filter_input(INPUT_POST, 'co_id', FILTER_VALIDATE_INT);
            $newStatus = htmlspecialchars($_POST['status'] ?? '');
            if ($co_id && in_array($newStatus, ['Pending', 'In Progress', 'Resolved'])) {
                $stmt = $conn->prepare("UPDATE complain SET status = ? WHERE co_id = ?");
                $stmt->bind_param("si", $newStatus, $co_id);
                if ($stmt->execute()) {
                    $display = '<div class="alert alert-success">Status updated successfully to ' . htmlspecialchars($newStatus) . '!</div>';
                } else {
                    $display = '<div class="alert alert-danger">Failed to update status: ' . $conn->error . '</div>';
                }
                $stmt->close();
            } else {
                $display = '<div class="alert alert-danger">Invalid complaint ID or status</div>';
            }
        }
    }

    // Fetch complaints by status
    $statuses = ['Pending', 'In Progress', 'Resolved'];
    $complaintsByStatus = [];
    
    foreach ($statuses as $status) {
        $query = "
            SELECT c.co_id, c.did, c.aid, c.username, c.descrip, c.status, c.pa_id, c.locationtype, c.roomnum,
                   c.date, c.month, c.year,
                   d.dname, a.aname, p.pa_name,
                   sl.username AS sl_username, sl.uemail,
                   s.sid, s.sname AS sname, s.did AS s_did, s.semester,
                   t.tid, t.tname, t.did AS t_did
            FROM complain c
            LEFT JOIN dept d ON c.did = d.did
            LEFT JOIN asset a ON c.aid = a.aid
            LEFT JOIN public p ON c.pa_id = p.pa_id
            LEFT JOIN stlog sl ON c.username = sl.username
            LEFT JOIN students s ON sl.uemail = s.email
            LEFT JOIN teacher t ON sl.uemail = t.temail
            WHERE c.status = ?
            ORDER BY c.date, c.month, c.year
        ";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $status);
        $stmt->execute();
        if ($stmt->error) {
            error_log("Complaints query error for status $status: " . $stmt->error);
            $display .= '<div class="alert alert-danger">Query failed for ' . $status . ': ' . $conn->error . '</div>';
            $complaintsByStatus[$status] = null;
        } else {
            $complaintsByStatus[$status] = $stmt->get_result();
            $complaint_count = $complaintsByStatus[$status] ? $complaintsByStatus[$status]->num_rows : 0;
            error_log("Status: $status, Complaints found: $complaint_count");
            if ($complaint_count > 0) {
                while ($row = $complaintsByStatus[$status]->fetch_assoc()) {
                    error_log("Complaint ID: {$row['co_id']}, Username: {$row['username']}, Status: {$row['status']}, DID: {$row['did']}, PA_ID: {$row['pa_id']}");
                }
                $complaintsByStatus[$status]->data_seek(0);
            }
        }
        $stmt->close();
    }

} catch (Exception $e) {
    $display = '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    error_log("Exception: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Complaints Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" href="assets/img/dln1.png" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .container-fluid { padding: 20px; }
        .table { margin-top: 20px; }
        .alert { margin-top: 20px; }
        .dropdown-menu { min-width: 100px; }
        .card { margin-bottom: 20px; }
        .status-section { display: none; }
        .status-section.active { display: block; }
        .btn-status { 
            margin-right: 5px; 
            background-color: #17a2b8; 
            border-color: #17a2b8; 
            color: #fff; 
        }
        .btn-status:hover { 
            background-color: #138496; 
            border-color: #117a8b; 
            color: #fff; 
        }
        .btn-status.active { 
            background-color: #005566; 
            border-color: #005566; 
            color: #fff; 
        }
    </style>
</head>
<body>
    <?php include('header.php'); ?>

    <div class="app-main" id="main">
        <div class="container-fluid">
            <!-- Logout Button -->
            <div class="d-flex justify-content-end mb-3">
                <form method="POST" action="mcomplaint.php">
                    <button type="submit" name="logout" class="btn btn-danger">Logout</button>
                </form>
            </div>

            <!-- Display Messages -->
            <?php if (!empty($display)) echo $display; ?>

            <!-- Status Sections -->
            <?php 
            error_log("Rendering complaint tables");
            foreach ($statuses as $index => $status): ?>
                <div class="status-section <?php echo $status === 'Pending' ? 'active' : ''; ?>" 
                     id="<?php echo strtolower($status); ?>-section">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h4><?php echo $status; ?> Complaints</h4>
                            <div class="d-flex gap-2 align-items-center">
                                <div class="btn-group">
                                    <?php foreach ($statuses as $btnStatus): ?>
                                        <button class="btn btn-status btn-sm <?php echo $btnStatus === $status ? 'active' : ''; ?>" 
                                                data-status="<?php echo strtolower($btnStatus); ?>">
                                            <?php echo $btnStatus; ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-success btn-sm dropdown-toggle" 
                                            data-bs-toggle="dropdown">
                                        Download As
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li><a class="dropdown-item" href="#" 
                                               onclick="exportTo('excel', '<?php echo strtolower($status); ?>')">Excel</a></li>
                                        <li><a class="dropdown-item" href="#" 
                                               onclick="exportTo('csv', '<?php echo strtolower($status); ?>')">CSV</a></li>
                                        <li><a class="dropdown-item" href="#" 
                                               onclick="exportTo('pdf', '<?php echo strtolower($status); ?>')">PDF</a></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <table class="table table-striped" 
                                   id="table-<?php echo strtolower($status); ?>">
                                <thead>
                                    <tr>
                                        <th>Target</th>
                                        <th>Location Type</th>
                                        <th>Room Number</th>
                                        <th>Asset/Public Area</th>
                                        <th>User Details</th>
                                        <th>Description</th>
                                        <th>Date</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    if (isset($complaintsByStatus[$status]) && $complaintsByStatus[$status] && $complaintsByStatus[$status]->num_rows > 0) {
                                        while ($row = $complaintsByStatus[$status]->fetch_assoc()) {
                                            error_log("Rendering complaint: co_id={$row['co_id']}, username={$row['username']}, did={$row['did']}, pa_id={$row['pa_id']}, status={$row['status']}");
                                            echo '<tr>';
                                            $target = $row['dname'] ? htmlspecialchars($row['dname']) : htmlspecialchars($row['pa_name'] ?? 'N/A');
                                            echo '<td>' . $target . '</td>';
                                            $locationType = htmlspecialchars($row['locationtype'] ?? 'N/A');
                                            echo '<td>' . $locationType . '</td>';
                                            $roomNum = htmlspecialchars($row['roomnum'] ?? 'N/A');
                                            echo '<td>' . $roomNum . '</td>';
                                            $asset = $row['aname'] ? htmlspecialchars($row['aname']) : ($row['pa_name'] ? htmlspecialchars($row['pa_name']) : 'N/A');
                                            echo '<td>' . $asset . '</td>';
                                            
                                            $userDetails = "Username: " . htmlspecialchars($row['username'] ?? 'N/A') . "<br>";
                                            if (isset($row['sname']) && !is_null($row['sname'])) {
                                                $s_did = $row['s_did'] ?? null;
                                                $deptName = 'N/A';
                                                if ($s_did) {
                                                    $deptStmt = $conn->prepare("SELECT dname FROM dept WHERE did = ?");
                                                    $deptStmt->bind_param("i", $s_did);
                                                    $deptStmt->execute();
                                                    $deptResult = $deptStmt->get_result();
                                                    if ($deptRow = $deptResult->fetch_assoc()) {
                                                        $deptName = htmlspecialchars($deptRow['dname']);
                                                    }
                                                    $deptStmt->close();
                                                }
                                                $userDetails .= "Name: " . htmlspecialchars($row['sname'] ?? 'N/A') . "<br>";
                                                $userDetails .= "Department: " . $deptName . "<br>";
                                                $userDetails .= "Semester: " . htmlspecialchars($row['semester'] ?? 'N/A') . "<br>";
                                            } elseif (isset($row['tname']) && !is_null($row['tname'])) {
                                                $t_did = $row['t_did'] ?? null;
                                                $deptName = 'N/A';
                                                if ($t_did) {
                                                    $deptStmt = $conn->prepare("SELECT dname FROM dept WHERE did = ?");
                                                    $deptStmt->bind_param("i", $t_did);
                                                    $deptStmt->execute();
                                                    $deptResult = $deptStmt->get_result();
                                                    if ($deptRow = $deptResult->fetch_assoc()) {
                                                        $deptName = htmlspecialchars($deptRow['dname']);
                                                    }
                                                    $deptStmt->close();
                                                }
                                                $userDetails .= "Name: " . htmlspecialchars($row['tname'] ?? 'N/A') . "<br>";
                                                $userDetails .= "Department: " . $deptName . "<br>";
                                            } else {
                                                $userDetails .= "Role: Unknown<br>";
                                            }
                                            echo '<td>' . $userDetails . '</td>';
                                            
                                            echo '<td>' . htmlspecialchars($row['descrip'] ?? 'N/A') . '</td>';
                                            echo '<td>' . htmlspecialchars($row['date'] ?? 'N/A') . '/' . htmlspecialchars($row['month'] ?? 'N/A') . '/' . htmlspecialchars($row['year'] ?? 'N/A') . '</td>';
                                            echo '<td>';
                                            if ($uname) {
                                                echo '<div class="dropdown">';
                                                echo '<button class="btn btn-primary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">' . htmlspecialchars($row['status'] ?? 'Update Status') . '</button>';
                                                echo '<ul class="dropdown-menu">';
                                                foreach (['Pending', 'In Progress', 'Resolved'] as $action) {
                                                    echo '<li><form method="POST" action="mcomplaint.php">';
                                                    echo '<input type="hidden" name="co_id" value="' . $row['co_id'] . '">';
                                                    echo '<input type="hidden" name="status" value="' . $action . '">';
                                                    echo '<button type="submit" name="update_status" class="dropdown-item">' . $action . '</button>';
                                                    echo '</form></li>';
                                                }
                                                echo '</ul>';
                                                echo '</div>';
                                            } else {
                                                echo '<span class="text-muted">Login required</span>';
                                            }
                                            echo '</td>';
                                            echo '</tr>';
                                        }
                                    } else {
                                        echo '<tr><td colspan="8" class="text-center">No ' . strtolower($status) . ' complaints found.</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
    <script src="assets/js/vendors.js"></script>
    <script src="assets/js/app.js"></script>
    <script>
    document.querySelectorAll('.btn-status').forEach(button => {
        button.addEventListener('click', () => {
            const status = button.getAttribute('data-status');
            document.querySelectorAll('.status-section').forEach(section => {
                section.classList.remove('active');
            });
            document.querySelectorAll('.btn-status').forEach(btn => {
                btn.classList.remove('active');
            });
            document.getElementById(`${status}-section`).classList.add('active');
            document.querySelectorAll(`.btn-status[data-status="${status}"]`).forEach(btn => {
                btn.classList.add('active');
            });
        });
    });

    function exportTo(format, status) {
        const { jsPDF } = window.jspdf;
        const table = document.querySelector(`#table-${status}`);
        const rows = table.querySelectorAll('tr');
        let data = [];
        let headers = [];
        table.querySelectorAll('thead th').forEach(th => {
            headers.push(th.innerText);
        });
        data.push(headers);
        table.querySelectorAll('tbody tr').forEach(tr => {
            let row = [];
            tr.querySelectorAll('td').forEach(td => {
                if (!td.querySelector('.dropdown')) {
                    const text = td.innerHTML.replace(/<br\s*\/?>/gi, ', ').replace(/<[^>]+>/g, '').trim();
                    row.push(text);
                }
            });
            data.push(row);
        });
        if (format === 'excel' || format === 'csv') {
            let csvContent = data.map(row => 
                row.map(cell => `"${cell.replace(/"/g, '""')}"`).join(',')
            ).join('\n');
            if (format === 'excel') {
                csvContent = '\uFEFF' + csvContent;
            }
            let blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            let link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = `${status}_complaints_${new Date().toISOString().slice(0,10)}.${format}`;
            link.click();
        } else if (format === 'pdf') {
            const doc = new jsPDF();
            doc.autoTable({
                head: [headers.slice(0, -1)],
                body: data.slice(1).map(row => row.slice(0, -1)),
                styles: { fontSize: 8 },
                headStyles: { fillColor: [41, 128, 185] }
            });
            doc.save(`${status}_complaints_${new Date().toISOString().slice(0,10)}.pdf`);
        }
    }
    </script>
</body>
</html>

<?php 
if (isset($conn)) {
    $conn->close();
}
?>