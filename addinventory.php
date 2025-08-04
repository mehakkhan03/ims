<?php
session_start();
$display = "";

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

    // Simple query
    $simpleQuery = "
        SELECT 
            department_name,
            asset_name,
            SUM(quantity) AS quantity
        FROM (
            SELECT d.dname AS department_name, a.aname AS asset_name, ad.nqty AS quantity
            FROM add_dept ad JOIN dept d ON ad.did = d.did JOIN asset a ON ad.aid = a.aid
            WHERE ad.status = 'Approved'
            UNION ALL
            SELECT d.dname AS department_name, a.aname AS asset_name, al.aqty AS quantity
            FROM add_lab al JOIN dept d ON al.did = d.did JOIN asset a ON al.aid = a.aid JOIN lab l ON al.lid = l.lid
            WHERE al.status = 'Approved'
            UNION ALL
            SELECT d.dname AS department_name, a.aname AS asset_name, ao.aqty AS quantity
            FROM add_office ao JOIN dept d ON ao.did = d.did JOIN asset a ON ao.aid = a.aid JOIN off o ON ao.oid = o.oid
            WHERE ao.status = 'Approved'
            UNION ALL
            SELECT d.dname AS department_name, a.aname AS asset_name, c.aqty AS quantity
            FROM classroom c JOIN dept d ON c.did = d.did JOIN asset a ON c.aid = a.aid
            WHERE c.status = 'Approved'
            UNION ALL
            SELECT d.dname AS department_name, sr.asset_name AS asset_name, sr.quantity AS quantity
            FROM special_request sr JOIN dept d ON sr.did = d.did
            WHERE sr.status = 'Approved'
        ) AS combined_assets
        GROUP BY department_name, asset_name
        ORDER BY department_name, asset_name
    ";
    $simpleResult = $conn->query($simpleQuery);
    if (!$simpleResult) {
        error_log("Simple query error: " . $conn->error);
        throw new Exception("Failed to execute simple query.");
    }

    // Detailed query
    $detailedQuery = "
        SELECT 
            department_name,
            location_type,
            location_name,
            asset_name,
            asset_type,
            SUM(quantity) AS quantity
        FROM (
            SELECT d.dname AS department_name, a.aname AS asset_name, a.atype AS asset_type, 
                   ad.nqty AS quantity, 'Department' AS location_type, NULL AS location_name
            FROM add_dept ad JOIN dept d ON ad.did = d.did JOIN asset a ON ad.aid = a.aid
            WHERE ad.status = 'Approved'
            UNION ALL
            SELECT d.dname AS department_name, a.aname AS asset_name, a.atype AS asset_type, 
                   al.aqty AS quantity, 'Lab' AS location_type, l.lname AS location_name
            FROM add_lab al JOIN dept d ON al.did = d.did JOIN asset a ON al.aid = a.aid JOIN lab l ON al.lid = l.lid
            WHERE al.status = 'Approved'
            UNION ALL
            SELECT d.dname AS department_name, a.aname AS asset_name, a.atype AS asset_type, 
                   ao.aqty AS quantity, 'Office' AS location_type, o.oname AS location_name
            FROM add_office ao JOIN dept d ON ao.did = d.did JOIN asset a ON ao.aid = a.aid JOIN off o ON ao.oid = o.oid
            WHERE ao.status = 'Approved'
            UNION ALL
            SELECT d.dname AS department_name, a.aname AS asset_name, a.atype AS asset_type, 
                   c.aqty AS quantity, 'Classroom' AS location_type, c.cl_id AS location_name
            FROM classroom c JOIN dept d ON c.did = d.did JOIN asset a ON c.aid = a.aid
            WHERE c.status = 'Approved'
            UNION ALL
            SELECT d.dname AS department_name, sr.asset_name AS asset_name, sr.asset_type AS asset_type, 
                   sr.quantity AS quantity, sr.locationtype AS location_type, sr.roomnum AS location_name
            FROM special_request sr JOIN dept d ON sr.did = d.did
            WHERE sr.status = 'Approved'
        ) AS combined_assets
        GROUP BY department_name, location_type, location_name, asset_name, asset_type
        ORDER BY department_name, location_type, asset_name
    ";
    $detailedResult = $conn->query($detailedQuery);
    if (!$detailedResult) {
        error_log("Detailed query error: " . $conn->error);
        throw new Exception("Failed to execute detailed query.");
    }

} catch (Exception $e) {
    $display = '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    error_log("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Approved Department Assets List</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" href="assets/img/dln1.png" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .container-fluid { padding: 20px; }
        .table { margin-top: 20px; }
        .alert { margin-top: 20px; }
        #detailedTable { display: none; }

        /* Responsive styles */
        @media (max-width: 768px) {
            /* Make tables responsive */
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            /* Adjust table cells for smaller screens */
            .table th, .table td {
                font-size: 14px;
                padding: 8px;
            }

            /* Stack header buttons vertically */
            .card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .card-header h4 {
                font-size: 1.2rem;
                margin-bottom: 0;
            }

            /* Adjust search input group */
            .input-group {
                max-width: 100%;
                flex-direction: column;
                gap: 10px;
            }

            .input-group .form-control,
            .input-group .btn {
                width: 100%;
            }

            /* Adjust button sizes */
            .btn-sm {
                font-size: 0.85rem;
                padding: 6px 12px;
            }

            /* Stack footer columns */
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
            /* Further reduce font sizes */
            .table th, .table td {
                font-size: 12px;
                padding: 6px;
            }

            .card-header h4 {
                font-size: 1rem;
            }

            .btn-sm {
                font-size: 0.75rem;
                padding: 4px 8px;
            }

            /* Adjust dropdown menu */
            .dropdown-menu {
                font-size: 0.85rem;
            }
        }

        /* Ensure table is scrollable horizontally */
        .table-responsive {
            width: 100%;
            margin-bottom: 15px;
        }

        /* Prevent text overflow in table cells */
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

            <div class="row">
                <div class="col-md-12">
                    <div class="card">   
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h4>Approved Department Assets</h4>
                            <div>
                                <button class="btn btn-sm btn-info me-2" onclick="toggleDetailed()">Detailed List</button>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-sm btn-primary dropdown-toggle" data-bs-toggle="dropdown">
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
                            <div class="input-group mb-3" style="max-width: 300px;">
                                <input type="text" id="searchInput" class="form-control" placeholder="Search assets...">
                                <button class="btn btn-outline-secondary" type="button" onclick="searchTable()">Search</button>
                            </div>

                            <!-- Simple Table -->
                            <div class="table-responsive">
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
                                        if ($simpleResult && $simpleResult->num_rows > 0) {
                                            while ($row = $simpleResult->fetch_assoc()) {
                                                echo '<tr>';
                                                echo '<td>' . htmlspecialchars($row['department_name']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['asset_name']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['quantity']) . '</td>';
                                                echo '</tr>';
                                            }
                                        } else {
                                            echo '<tr><td colspan="3" class="text-center">No approved assets found.</td></tr>';
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Detailed Table -->
                            <div class="table-responsive">
                                <table id="detailedTable" class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Department Name</th>
                                            <th>Location Type</th>
                                            <th>Location Name</th>
                                            <th>Asset Name</th>
                                            <th>Asset Type</th>
                                            <th>Quantity</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        if ($detailedResult && $detailedResult->num_rows > 0) {
                                            while ($row = $detailedResult->fetch_assoc()) {
                                                echo '<tr>';
                                                echo '<td>' . htmlspecialchars($row['department_name']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['location_type']) . '</td>';
                                                echo '<td>' . ($row['location_name'] ? htmlspecialchars($row['location_name']) : 'N/A') . '</td>';
                                                echo '<td>' . htmlspecialchars($row['asset_name']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['asset_type']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['quantity']) . '</td>';
                                                echo '</tr>';
                                            }
                                        } else {
                                            echo '<tr><td colspan="6" class="text-center">No approved assets found.</td></tr>';
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
    function toggleDetailed() {
        const simpleTable = document.getElementById('simpleTable');
        const detailedTable = document.getElementById('detailedTable');
        const button = document.querySelector('.btn-info');
        
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
        let rows = table.querySelectorAll('tr');
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
            link.download = `assets_${new Date().toISOString().slice(0,10)}.${format}`;
            link.click();
        } else if (format === 'pdf') {
            const doc = new jsPDF();
            doc.autoTable({
                head: [headers],
                body: data.slice(1),
                styles: { fontSize: 8 },
                headStyles: { fillColor: [41, 128, 185] }
            });
            doc.save(`assets_${new Date().toISOString().slice(0,10)}.pdf`);
        }
    }

    function searchTable() {
        const input = document.getElementById('searchInput');
        const filter = input.value.toLowerCase();
        const tables = [document.getElementById('simpleTable'), document.getElementById('detailedTable')];
        
        tables.forEach(table => {
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            
            for (let i = 0; i < rows.length; i++) {
                let rowText = rows[i].textContent || rows[i].innerText;
                if (rowText.toLowerCase().indexOf(filter) > -1) {
                    rows[i].style.display = '';
                } else {
                    rows[i].style.display = 'none';
                }
            }
        });
    }

    document.getElementById('searchInput').addEventListener('keyup', function(event) {
        if (event.key === 'Enter') {
            searchTable();
        }
    });
    </script>
</body>
</html>

<?php $conn->close(); ?>