<?php
session_start();
$display = "";
$statuses = ['New', 'Approved', 'Hold', 'Rejected'];

// Set default status if not set
if (!isset($_SESSION['selected_status']) || (isset($_POST['filter_status']) && in_array($_POST['filter_status'], $statuses))) {
    $_SESSION['selected_status'] = isset($_POST['filter_status']) ? $_POST['filter_status'] : 'New';
}

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit();
}

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

    // Handle asset addition
    if (isset($_POST['submit_assets']) && isset($_POST['sr_id'])) {
        $sr_id = $_POST['sr_id'];
        $asset_name = $_POST['asset_name'];
        $asset_type = $_POST['asset_type'];
        $quantity = $_POST['quantity'];
        $dname = $_POST['dname'];
        $date = date('d');
        $month = date('m');
        $year = date('Y');

        // Case-insensitive check for asset existence
        $check_asset = $conn->prepare("SELECT aid, atype, aqty FROM asset WHERE LOWER(aname) = LOWER(?)");
        $check_asset->bind_param("s", $asset_name);
        $check_asset->execute();
        $result = $check_asset->get_result();

        if ($result->num_rows > 0) {
            $asset = $result->fetch_assoc();
            $aid = $asset['aid'];

            // Insert into assets_entry
            $insert_query = $conn->prepare("
                INSERT INTO assets_entry (aid, aname, atype, aqty, dop) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $dop = "$year-$month-$date";
            $insert_query->bind_param("issis", $aid, $asset_name, $asset_type, $quantity, $dop);
            
            if ($insert_query->execute()) {
                // Update status to Approved
                $update_query = $conn->prepare("UPDATE special_request SET status = 'Approved' WHERE sr_id = ?");
                $update_query->bind_param("i", $sr_id);
                $update_query->execute();
                $update_query->close();
                $display = '<div class="alert alert-success">Assets added successfully and status updated to Approved</div>';
            } else {
                $display = '<div class="alert alert-danger">Error adding assets to inventory</div>';
            }
            $insert_query->close();
        } else {
            $display = '<div class="alert alert-danger">Asset not found in inventory</div>';
        }
        $check_asset->close();
    }

    // Handle status update
    if (isset($_POST['update_status']) && isset($_POST['sr_id'])) {
        $sr_id = $_POST['sr_id'];
        $status = $_POST['status'];

        $update_query = $conn->prepare("UPDATE special_request SET status = ? WHERE sr_id = ?");
        $update_query->bind_param("si", $status, $sr_id);
        if ($update_query->execute()) {
            $display = '<div class="alert alert-success">Status updated to ' . htmlspecialchars($status) . '</div>';
        } else {
            $display = '<div class="alert alert-danger">Error updating status</div>';
        }
        $update_query->close();
    }

    // Fetch special requests by the session-stored status
    $requestsByStatus = [];
    $query = "
        SELECT sr.sr_id, sr.username, sr.asset_name, sr.asset_type, sr.quantity, sr.locationtype, 
               sr.roomnum, sr.descrip, sr.status, sr.date, sr.month, sr.year, d.dname 
        FROM special_request sr 
        JOIN dept d ON sr.did = d.did 
        WHERE sr.status = ? 
        ORDER BY sr.year DESC, sr.month DESC, sr.date DESC
    ";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $_SESSION['selected_status']);
    $stmt->execute();
    $requestsByStatus[$_SESSION['selected_status']] = $stmt->get_result();
    $stmt->close();

} catch (Exception $e) {
    $display = '<div class="alert alert-danger">Error: ' . $e->getMessage() . '</div>';
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Special Requests Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
        .btn-action { 
            padding: 2px 8px;
            font-size: 0.85rem;
        }
        .status-select {
            font-size: 0.85rem;
            padding: 2px;
            width: 100px;
        }
        .modal-content {
            border-radius: 8px;
        }
        .modal-header {
            background-color: #17a2b8;
            color: white;
        }

        /* Responsive styles */
        @media (max-width: 768px) {
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            .table th, .table td {
                font-size: 14px;
                padding: 8px;
            }
            .card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            .card-header h4 {
                font-size: 1.2rem;
                margin-bottom: 0;
            }
            .card-header .d-flex {
                flex-direction: column;
                align-items: flex-start;
                width: 100%;
            }
            .btn-group {
                width: 100%;
                margin-bottom: 10px;
            }
            .btn-status, .btn-success, .btn-action {
                font-size: 0.85rem;
                padding: 6px 12px;
                width: 100%;
                text-align: left;
            }
            .status-select {
                width: 100%;
                font-size: 0.85rem;
            }
            .dropdown-menu {
                font-size: 0.9rem;
                width: 100%;
            }
            .footer .row {
                flex-direction: column;
                text-align: center;
            }
            .footer .col-sm-6 {
                margin-bottom: 10px;
            }
            .footer p {
                font-size: 0.9rem;
            }
        }

        @media (max-width: 576px) {
            .table th, .table td {
                font-size: 12px;
                padding: 6px;
            }
            .card-header h4 {
                font-size: 1rem;
            }
            .btn-status, .btn-success, .btn-action {
                font-size: 0.75rem;
                padding: 4px 8px;
            }
            .status-select {
                font-size: 0.75rem;
                padding: 2px;
            }
            .dropdown-menu {
                font-size: 0.85rem;
            }
            .modal-dialog {
                margin: 10px;
            }
            .modal-content {
                font-size: 0.9rem;
            }
        }
        .table-responsive {
            width: 100%;
            margin-bottom: 15px;
        }
        .table td, .table th {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
    </style>
</head>
<body>
    <?php include('header.php'); ?>

    <div class="app-main" id="main">
        <div class="container-fluid">
            <?php if (!empty($display)) echo $display; ?>

            <div class="status-section active" id="<?php echo strtolower($_SESSION['selected_status']); ?>-section">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h4><?php echo htmlspecialchars($_SESSION['selected_status']); ?> Special Requests</h4>
                        <div class="d-flex gap-2 align-items-center">
                            <div class="btn-group">
                                <?php foreach ($statuses as $btnStatus): ?>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="filter_status" value="<?php echo $btnStatus; ?>">
                                        <button type="submit" class="btn btn-status btn-sm <?php echo $btnStatus === $_SESSION['selected_status'] ? 'active' : ''; ?>" 
                                                name="filter_button">
                                            <?php echo $btnStatus; ?>
                                        </button>
                                    </form>
                                <?php endforeach; ?>
                            </div>
                            <div class="btn-group">
                                <button type="button" class="btn btn-success btn-sm dropdown-toggle" 
                                        data-bs-toggle="dropdown">
                                    Download As
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="#" 
                                           onclick="exportTo('excel', '<?php echo strtolower($_SESSION['selected_status']); ?>')">Excel</a></li>
                                    <li><a class="dropdown-item" href="#" 
                                           onclick="exportTo('csv', '<?php echo strtolower($_SESSION['selected_status']); ?>')">CSV</a></li>
                                    <li><a class="dropdown-item" href="#" 
                                           onclick="exportTo('pdf', '<?php echo strtolower($_SESSION['selected_status']); ?>')">PDF</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped" 
                                   id="table-<?php echo strtolower($_SESSION['selected_status']); ?>">
                                <thead>
                                    <tr>
                                        <th>Username</th>
                                        <th>Asset Name</th>
                                        <th>Asset Type</th>
                                        <th>Department</th>
                                        <th>Quantity</th>
                                        <th>Location Type</th>
                                        <th>Room Number</th>
                                        <th>Description</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $result = $requestsByStatus[$_SESSION['selected_status']] ?? null;
                                    if ($result && $result->num_rows > 0) {
                                        while ($row = $result->fetch_assoc()) {
                                            echo '<tr>';
                                            echo '<td>' . htmlspecialchars($row['username']) . '</td>';
                                            echo '<td>' . htmlspecialchars($row['asset_name']) . '</td>';
                                            echo '<td>' . htmlspecialchars($row['asset_type']) . '</td>';
                                            echo '<td>' . htmlspecialchars($row['dname']) . '</td>';
                                            echo '<td>' . htmlspecialchars($row['quantity']) . '</td>';
                                            echo '<td>' . htmlspecialchars($row['locationtype']) . '</td>';
                                            echo '<td>' . htmlspecialchars($row['roomnum']) . '</td>';
                                            echo '<td>' . htmlspecialchars($row['descrip']) . '</td>';
                                            echo '<td>' . htmlspecialchars($row['date']) . '/' . htmlspecialchars($row['month']) . '/' . htmlspecialchars($row['year']) . '</td>';
                                            echo '<td>';
                                            if ($row['status'] === 'Approved') {
                                                echo htmlspecialchars($row['status']);
                                            } else {
                                                echo '<form method="post" style="display:inline;">';
                                                echo '<input type="hidden" name="sr_id" value="' . htmlspecialchars($row['sr_id']) . '">';
                                                echo '<select name="status" class="status-select" onchange="this.form.submit()">';
                                                foreach ($statuses as $status_option) {
                                                    echo '<option value="' . $status_option . '" ' . ($status_option === $row['status'] ? 'selected' : '') . '>' . $status_option . '</option>';
                                                }
                                                echo '</select>';
                                                echo '<input type="hidden" name="update_status" value="1">';
                                                echo '</form>';
                                            }
                                            echo '</td>';
                                            echo '<td>';
                                            if ($row['status'] === 'Approved') {
                                                echo '<button class="btn btn-success btn-action" disabled>Assets Added</button>';
                                            } else {
                                                echo '<button class="btn btn-primary btn-action" data-bs-toggle="modal" data-bs-target="#assetModal' . htmlspecialchars($row['sr_id']) . '">Add Assets</button>';
                                            }
                                            echo '</td>';
                                            echo '</tr>';
                                            ?>
                                            <!-- Modal for adding assets -->
                                            <div class="modal fade" id="assetModal<?php echo htmlspecialchars($row['sr_id']); ?>" tabindex="-1" aria-labelledby="assetModalLabel<?php echo htmlspecialchars($row['sr_id']); ?>" aria-hidden="true">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title" id="assetModalLabel<?php echo htmlspecialchars($row['sr_id']); ?>">Add Assets</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <form method="post">
                                                                <input type="hidden" name="sr_id" value="<?php echo htmlspecialchars($row['sr_id']); ?>">
                                                                <div class="mb-3">
                                                                    <label for="asset_name<?php echo htmlspecialchars($row['sr_id']); ?>" class="form-label">Asset Name</label>
                                                                    <input type="text" class="form-control" id="asset_name<?php echo htmlspecialchars($row['sr_id']); ?>" name="asset_name" value="<?php echo htmlspecialchars($row['asset_name']); ?>" readonly>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label for="asset_type<?php echo htmlspecialchars($row['sr_id']); ?>" class="form-label">Asset Type</label>
                                                                    <input type="text" class="form-control" id="asset_type<?php echo htmlspecialchars($row['sr_id']); ?>" name="asset_type" value="<?php echo htmlspecialchars($row['asset_type']); ?>" readonly>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label for="quantity<?php echo htmlspecialchars($row['sr_id']); ?>" class="form-label">Quantity</label>
                                                                    <input type="number" class="form-control" id="quantity<?php echo htmlspecialchars($row['sr_id']); ?>" name="quantity" value="<?php echo htmlspecialchars($row['quantity']); ?>" readonly>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label for="dname<?php echo htmlspecialchars($row['sr_id']); ?>" class="form-label">Department</label>
                                                                    <input type="text" class="form-control" id="dname<?php echo htmlspecialchars($row['sr_id']); ?>" name="dname" value="<?php echo htmlspecialchars($row['dname']); ?>" readonly>
                                                                </div>
                                                                <button type="submit" name="submit_assets" class="btn btn-primary">Submit</button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php
                                        }
                                    } else {
                                        echo '<tr><td colspan="11" class="text-center">No ' . strtolower($_SESSION['selected_status']) . ' special requests found.</td></tr>';
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
    <script src="assets/js/vendors.js"></script>
    <script src="assets/js/app.js"></script>
    <script>
    document.querySelectorAll('.btn-status').forEach(button => {
        button.addEventListener('click', () => {
            const status = button.form.querySelector('input[name="filter_status"]').value;
            // No need to handle further here as form submission will set session
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
                row.push(td.innerText);
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
            link.download = `${status}_special_requests_${new Date().toISOString().slice(0,10)}.${format}`;
            link.click();
        } else if (format === 'pdf') {
            const doc = new jsPDF();
            doc.autoTable({
                head: [headers],
                body: data.slice(1),
                styles: { fontSize: 8 },
                headStyles: { fillColor: [41, 128, 185] }
            });
            doc.save(`${status}_special_requests_${new Date().toISOString().slice(0,10)}.pdf`);
        }
    }
    </script>
</body>
</html>

<?php $conn->close(); ?>