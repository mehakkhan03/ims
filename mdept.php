<?php
session_start();
$display = "";

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

    // Validate user exists in acreate (optional, for added security)
    $loggedInUsername = $_SESSION['username'];
    $userQuery = "SELECT id FROM `acreate` WHERE ausername = ?";
    $stmt = $conn->prepare($userQuery);
    $stmt->bind_param("s", $loggedInUsername);
    $stmt->execute();
    $userResult = $stmt->get_result();
    $stmt->close();

    if (!$userResult || $userResult->num_rows === 0) {
        $display = '<div class="alert alert-danger">Error: User not found.</div>';
        session_destroy();
        header("Location: index.php");
        exit();
    }

    // Handle status update
    if (isset($_POST['update_status'])) {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $newStatus = htmlspecialchars($_POST['status'] ?? '');
        
        $conn->begin_transaction();
        
        try {
            $requestQuery = "SELECT aid, nqty, status FROM add_dept WHERE id = ?";
            $stmt = $conn->prepare($requestQuery);
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $requestResult = $stmt->get_result();
            $request = $requestResult->fetch_assoc();
            $stmt->close();
            
            if (!$request) {
                throw new Exception("Request not found.");
            }

            $updateQuery = "UPDATE `add_dept` SET `status` = ? WHERE `id` = ?";
            $stmt = $conn->prepare($updateQuery);
            $stmt->bind_param("si", $newStatus, $id);
            $statusUpdated = $stmt->execute();
            $stmt->close();
            
            if ($statusUpdated && $request) {
                if ($newStatus === 'Rejected' && $request['status'] === 'Approved') {
                    $updateAssetQuery = "UPDATE `asset` 
                        SET `aqty` = `aqty` + ? 
                        WHERE `aid` = ?";
                    $stmt = $conn->prepare($updateAssetQuery);
                    $stmt->bind_param("ii", $request['nqty'], $request['aid']);
                    if (!$stmt->execute()) {
                        throw new Exception("Failed to update asset quantity (add): " . $conn->error);
                    }
                    $stmt->close();
                } elseif ($newStatus === 'Approved' && $request['status'] === 'Rejected') {
                    $updateAssetQuery = "UPDATE `asset` 
                        SET `aqty` = `aqty` - ? 
                        WHERE `aid` = ?";
                    $stmt = $conn->prepare($updateAssetQuery);
                    $stmt->bind_param("ii", $request['nqty'], $request['aid']);
                    if (!$stmt->execute()) {
                        throw new Exception("Failed to update asset quantity (subtract): " . $conn->error);
                    }
                    $stmt->close();
                    
                    $checkQuery = "SELECT aqty FROM asset WHERE aid = ?";
                    $stmt = $conn->prepare($checkQuery);
                    $stmt->bind_param("i", $request['aid']);
                    $stmt->execute();
                    $checkResult = $stmt->get_result();
                    $asset = $checkResult->fetch_assoc();
                    $stmt->close();
                    if ($asset['aqty'] < 0) {
                        throw new Exception("Insufficient asset quantity available");
                    }
                }
            }
            
            $conn->commit();
            $display = '<div class="alert alert-success">Status updated successfully to ' . htmlspecialchars($newStatus) . '!</div>';
            
        } catch (Exception $e) {
            $conn->rollback();
            $display = '<div class="alert alert-danger">Error: ' . $e->getMessage() . '</div>';
        }
    }

    // Fetch all assets by status
    $statuses = ['New', 'Approved', 'Hold', 'Rejected'];
    $assetsByStatus = [];
    
    foreach ($statuses as $status) {
        $query = "
            SELECT ad.id, ad.nqty, ad.date, ad.month, ad.year, ad.status, a.aname, a.atype, d.dname 
            FROM add_dept ad 
            JOIN asset a ON ad.aid = a.aid 
            JOIN dept d ON ad.did = d.did
            WHERE ad.status = ?
            ORDER BY ad.date, ad.month, ad.year
        ";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $status);
        $stmt->execute();
        $assetsByStatus[$status] = $stmt->get_result();
        $stmt->close();
    }

} catch (Exception $e) {
    $display = '<div class="alert alert-danger">Error: ' . $e->getMessage() . '</div>';
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Department Assets Requests</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" href="assets/img/dln1.png" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Fluid container with responsive padding */
        .container-fluid {
            padding: 2vw 3vw;
            max-width: 100%;
        }

        /* Responsive table margin */
        .table {
            margin-top: 1.5rem;
            width: 100%;
        }

        /* Responsive alert margin */
        .alert {
            margin-top: 1.5rem;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        /* Dropdown menu width */
        .dropdown-menu {
            min-width: 100px;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        /* Card spacing */
        .card {
            margin-bottom: 1.5rem;
        }

        /* Status section visibility */
        .status-section {
            display: none;
        }
        .status-section.active {
            display: block;
        }

        /* Responsive button styles */
        .btn-status {
            margin-right: 0.3rem;
            margin-bottom: 0.3rem;
            padding: 0.4rem 0.8rem;
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            background-color: #17a2b8;
            border-color: #17a2b8;
            color: #fff;
            white-space: nowrap;
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

        /* Ensure buttons wrap on small screens */
        .btn-group {
            flex-wrap: wrap;
        }

        /* Responsive card header */
        .card-header {
            padding: 0.75rem 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .card-header h4 {
            font-size: clamp(1.2rem, 3vw, 1.5rem);
            margin-bottom: 0;
            flex-grow: 1;
        }
        .card-header .button-group {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            justify-content: end;
        }

        /* Responsive footer */
        .footer {
            padding: 1.5rem 0;
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
        }

        /* Media queries for smaller screens */
        @media (max-width: 768px) {
            .container-fluid {
                padding: 3vw 4vw;
            }

            .table {
                font-size: 0.85rem;
            }

            .btn-status, .btn-success, .btn-primary {
                padding: 0.3rem 0.6rem;
                font-size: 0.8rem;
            }

            .card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .card-header .button-group {
                width: 100%;
                justify-content: flex-end;
            }
        }

        @media (max-width: 576px) {
            .table {
                font-size: 0.75rem;
            }

            th, td {
                padding: 0.5rem;
            }

            .btn-status, .btn-success, .btn-primary {
                font-size: 0.7rem;
                padding: 0.25rem 0.5rem;
                margin-right: 0;
                margin-bottom: 0;
            }

            .dropdown-menu {
                min-width: 80px;
            }

            .card-header h4 {
                font-size: 1.1rem;
            }
        }
    </style>
</head>
<body>
    <?php include('header.php'); ?>

    <div class="app-main" id="main">
        <div class="container-fluid">
            <!-- Display Messages -->
            <?php if (!empty($display)) echo $display; ?>

            <!-- Status Sections -->
            <?php foreach ($statuses as $index => $status): ?>
                <div class="status-section <?php echo $status === 'New' ? 'active' : ''; ?>" 
                     id="<?php echo strtolower($status); ?>-section">
                    <div class="card">
                        <div class="card-header">
                            <h4><?php echo $status; ?> Department Assets Requests</h4>
                            <div class="button-group">
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
                            <div class="table-responsive">
                                <table class="table table-striped" 
                                       id="table-<?php echo strtolower($status); ?>">
                                    <thead>
                                        <tr>
                                            <th>Asset Name</th>
                                            <th>Asset Type</th>
                                            <th>Department Name</th>
                                            <th>Requested Quantity</th>
                                            <th>Date</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        if (isset($assetsByStatus[$status]) && $assetsByStatus[$status]->num_rows > 0) {
                                            while ($row = $assetsByStatus[$status]->fetch_assoc()) {
                                                echo '<tr>';
                                                echo '<td>' . htmlspecialchars($row['aname']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['atype']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['dname']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['nqty']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['date']) . '/' . htmlspecialchars($row['month']) . '/' . htmlspecialchars($row['year']) . '</td>';
                                                echo '<td>';
                                                echo '<div class="dropdown">';
                                                echo '<button class="btn btn-primary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">' . htmlspecialchars($row['status']) . '</button>';
                                                echo '<ul class="dropdown-menu">';
                                                foreach (['Approved', 'New', 'Hold', 'Rejected'] as $action) {
                                                    echo '<li><form method="POST" style="margin: 0;">';
                                                    echo '<input type="hidden" name="id" value="' . $row['id'] . '">';
                                                    echo '<input type="hidden" name="status" value="' . $action . '">';
                                                    echo '<button type="submit" name="update_status" class="dropdown-item">' . $action . '</button>';
                                                    echo '</form></li>';
                                                }
                                                echo '</ul>';
                                                echo '</div>';
                                                echo '</td>';
                                                echo '</tr>';
                                            }
                                        } else {
                                            echo '<tr><td colspan="6" class="text-center">No ' . strtolower($status) . ' assets requested.</td></tr>';
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
    <script src="assets/js/vendors.js"></script>
    <script src="assets/js/app.js"></script>
    <script>
    document.querySelectorAll('.btn-status').forEach(button => {
        button.addEventListener('click', () => {
            const status = button.getAttribute('data-status');
            
            // Hide all sections and deactivate all buttons
            document.querySelectorAll('.status-section').forEach(section => {
                section.classList.remove('active');
            });
            document.querySelectorAll('.btn-status').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected section and activate button
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
                if (!td.querySelector('.dropdown')) { // Exclude Action column
                    row.push(td.innerText);
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
            link.download = `${status}_assets_${new Date().toISOString().slice(0,10)}.${format}`;
            link.click();
        } else if (format === 'pdf') {
            const doc = new jsPDF();
            doc.autoTable({
                head: [headers.slice(0, -1)], // Exclude Action column
                body: data.slice(1).map(row => row.slice(0, -1)),
                styles: { fontSize: 8 },
                headStyles: { fillColor: [41, 128, 185] }
            });
            doc.save(`${status}_assets_${new Date().toISOString().slice(0,10)}.pdf`);
        }
    }
    </script>
</body>
</html>

<?php $conn->close(); ?>