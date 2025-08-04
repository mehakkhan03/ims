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
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch public areas
    $publicStmt = $conn->query("SELECT pa_id, pa_name FROM `public`");
    $publicAreas = $publicStmt->fetchAll(PDO::FETCH_ASSOC);

    // Handle form submission
    if (isset($_POST["submit"])) {
        $conn->beginTransaction();
        try {
            $currentDate = getdate();
            for ($count = 0; $count < $_POST["total_row"]; $count++) {
                $paId = trim($_POST["pa_id"][$count]);
                $aname = trim($_POST["aname"][$count]);
                $atype = trim($_POST["atype"][$count]);
                $aqty = trim($_POST["aqty"][$count]);

                // Check if asset exists and has sufficient quantity
                $checkStmt = $conn->prepare("
                    SELECT `aid`, `aqty` FROM `asset` 
                    WHERE `aname` = :aname");
                $checkStmt->execute([':aname' => $aname]);
                $existingAsset = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if ($existingAsset) {
                    $aid = $existingAsset['aid'];
                    if ($existingAsset['aqty'] < $aqty) {
                        throw new Exception("Insufficient quantity for asset '$aname'. Available: " . $existingAsset['aqty']);
                    }
                } else {
                    throw new Exception("Asset '$aname' does not exist in inventory!");
                }

                // Reduce asset quantity in asset table
                $updateAssetStmt = $conn->prepare("
                    UPDATE `asset` 
                    SET `aqty` = `aqty` - :aqty 
                    WHERE `aid` = :aid");
                $updateAssetStmt->execute([':aqty' => $aqty, ':aid' => $aid]);

                // Insert into add_public table
                $insertStmt = $conn->prepare("
                    INSERT INTO `add_public` (`pa_id`, `aid`, `aqty`, `status`, `date`, `month`, `year`) 
                    VALUES (:pa_id, :aid, :aqty, 'Approved', :date, :month, :year)");
                $insertStmt->execute([
                    ':pa_id' => $paId,
                    ':aid' => $aid,
                    ':aqty' => $aqty,
                    ':date' => $currentDate['mday'],
                    ':month' => $currentDate['mon'],
                    ':year' => $currentDate['year']
                ]);
            }

            $conn->commit();
            $display = '<div class="alert alert-success">Assets Added to Public Areas Successfully</div>';
        } catch (Exception $e) {
            $conn->rollBack();
            $display = '<div class="alert alert-danger">Error: ' . $e->getMessage() . '</div>';
        }
    }

    // Fetch all public area assets (no status filtering)
    $query = "
        SELECT ap.id, ap.pa_id, ap.aid, ap.aqty, ap.status, ap.date, ap.month, ap.year,
               p.pa_name, a.aname, a.atype
        FROM add_public ap
        JOIN public p ON ap.pa_id = p.pa_id
        JOIN asset a ON ap.aid = a.aid
        ORDER BY ap.date DESC, ap.month DESC, ap.year DESC
    ";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $allAssets = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $display = '<div class="alert alert-danger">Error: ' . $e->getMessage() . '</div>';
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Add Public Area Assets</title>
    <link rel="shortcut icon" href="assets/img/dln.png" type="image/png">
    <link rel="icon" href="assets/img/dln1.png" type="image/x-icon">
    <link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
    <link rel="manifest" href="assets/manifest.json">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
            padding: clamp(0.75rem, 2vw, 1rem);
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

        /* Form controls */
        .form-control, .form-select {
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            padding: 0.375rem 0.75rem;
        }

        /* Buttons */
        .btn {
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            padding: 0.4rem 0.8rem;
        }
        .btn-success, .btn-info, .btn-danger {
            white-space: nowrap;
        }

        /* Table input widths */
        .pa_id, .aname, .atype, .aqty {
            width: 100%;
        }

        /* Remove row button */
        .remove_row {
            padding: 0.3rem 0.6rem;
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

            .form-control, .form-select {
                font-size: 0.8rem;
                padding: 0.3rem 0.6rem;
            }

            .btn {
                font-size: 0.8rem;
                padding: 0.3rem 0.6rem;
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

            .form-control, .form-select {
                font-size: 0.7rem;
                padding: 0.25rem 0.5rem;
            }

            .btn {
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

            /* Stack action buttons */
            #public-table td:last-child {
                text-align: center;
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

            <!-- Add Assets Form -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card card-statistics">
                        <div class="card-header">
                            <h4 class="card-title">Add Assets to Public Areas</h4>
                        </div>
                        <div class="card-body">
                            <form action="" name="myform" id="myform" method="post">
                                <div class="table-responsive">
                                    <table id="public-table" class="table table-bordered">
                                        <thead>
                                            <tr>
                                                <th bgcolor="#F0F3F4">S.No.</th>
                                                <th bgcolor="#F0F3F4">Public Area</th>
                                                <th bgcolor="#F0F3F4">Asset Name</th>
                                                <th bgcolor="#F0F3F4">Asset Type</th>
                                                <th bgcolor="#F0F3F4">Quantity</th>
                                                <th bgcolor="#F0F3F4">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td>1</td>
                                                <td>
                                                    <select name="pa_id[]" class="form-control input-sm pa_id" required>
                                                        <option value="">Select Public Area</option>
                                                        <?php foreach ($publicAreas as $area): ?>
                                                            <option value="<?php echo $area['pa_id']; ?>">
                                                                <?php echo htmlspecialchars($area['pa_name']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td><input class="form-control input-sm aname" name="aname[]" type="text" required /></td>
                                                <td>
                                                    <select name="atype[]" class="form-control input-sm atype" required>
                                                        <option value="">Select Any</option>
                                                        <option value="Electronics">Electronics</option>
                                                        <option value="Furniture">Furniture</option>
                                                    </select>
                                                </td>
                                                <td><input class="form-control input-sm aqty" name="aqty[]" type="number" min="1" required /></td>
                                                <td><button type="button" name="remove_row" class="btn btn-danger remove_row" disabled>X</button></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <br>
                                <div class="d-flex justify-content-end gap-2">
                                    <button type="button" name="add_row" id="add_row" class="btn btn-success">Add Row</button>
                                    <input type="hidden" name="total_row" id="total_row" value="1" />
                                    <input type="submit" name="submit" id="submit" class="btn btn-info" value="Submit" />
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- All Assets Table -->
            <div class="card">
                <div class="card-header">
                    <h4>All Public Area Assets</h4>
                    <div class="button-group">
                        <div class="btn-group">
                            <button type="button" class="btn btn-success btn-sm dropdown-toggle" 
                                    data-bs-toggle="dropdown">
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
                    <div class="table-responsive">
                        <table class="table table-striped" id="table-all">
                            <thead>
                                <tr>
                                    <th>Public Area</th>
                                    <th>Asset Name</th>
                                    <th>Asset Type</th>
                                    <th>Quantity</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                if (!empty($allAssets)) {
                                    foreach ($allAssets as $row) {
                                        echo '<tr>';
                                        echo '<td>' . htmlspecialchars($row['pa_name']) . '</td>';
                                        echo '<td>' . htmlspecialchars($row['aname']) . '</td>';
                                        echo '<td>' . htmlspecialchars($row['atype']) . '</td>';
                                        echo '<td>' . htmlspecialchars($row['aqty']) . '</td>';
                                        echo '<td>' . htmlspecialchars($row['date']) . '/' . htmlspecialchars($row['month']) . '/' . htmlspecialchars($row['year']) . '</td>';
                                        echo '</tr>';
                                    }
                                } else {
                                    echo '<tr><td colspan="5" class="text-center">No assets requested.</td></tr>';
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer class="footer bg-light py-3 mt-4">
        <div class="container">
            <div class="row">
                <div class="col-12 col-sm-6 text-center text-sm-start">
                    <p>© Copyright 2019. All syndicaterights reserved.</p>
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
    $(document).ready(function() {
        var count = 1;
        $('#add_row').on('click', function() {
            count++;
            $('#total_row').val(count);
            var html_code = '<tr id="row_id_' + count + '">';
            html_code += '<td>' + count + '</td>';
            html_code += '<td><select name="pa_id[]" class="form-control input-sm pa_id" required>';
            html_code += '<option value="">Select Public Area</option>';
            <?php foreach ($publicAreas as $area): ?>
                html_code += '<option value="<?php echo $area['pa_id']; ?>"><?php echo htmlspecialchars($area['pa_name']); ?></option>';
            <?php endforeach; ?>
            html_code += '</select></td>';
            html_code += '<td><input type="text" name="aname[]" class="form-control input-sm aname" required /></td>';
            html_code += '<td><select name="atype[]" class="form-control input-sm atype" required>';
            html_code += '<option value="">Select Any</option><option value="Electronics">Electronics</option><option value="Furniture">Furniture</option></select></td>';
            html_code += '<td><input type="number" name="aqty[]" class="form-control input-sm aqty" min="1" required /></td>';
            html_code += '<td><button type="button" name="remove_row" id="' + count + '" class="btn btn-danger remove_row">X</button></td>';
            html_code += '</tr>';
            $('#public-table tbody').append(html_code);
        });

        $(document).on('click', '.remove_row', function() {
            var row_id = $(this).attr("id");
            $('#row_id_' + row_id).remove();
            count--;
            $('#total_row').val(count);
        });

        $('#myform').on('submit', function(e) {
            var valid = true;
            $('.aqty').each(function() {
                if ($(this).val() <= 0) {
                    valid = false;
                    alert('Quantity must be greater than 0.');
                    $(this).focus();
                    return false;
                }
            });
            if (!valid) {
                e.preventDefault();
            }
        });
    });

    function exportTo(format) {
        const { jsPDF } = window.jspdf;
        const table = document.querySelector('#table-all');
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
            link.download = `all_public_assets_${new Date().toISOString().slice(0,10)}.${format}`;
            link.click();
        } else if (format === 'pdf') {
            const doc = new jsPDF();
            doc.autoTable({
                head: [headers],
                body: data.slice(1),
                styles: { fontSize: 8 },
                headStyles: { fillColor: [41, 128, 185] }
            });
            doc.save(`all_public_assets_${new Date().toISOString().slice(0,10)}.pdf`);
        }
    }
    </script>
</body>
</html>