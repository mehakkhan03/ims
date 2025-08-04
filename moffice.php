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

    // Handle status update
    if (isset($_POST['update_status'])) {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $newStatus = htmlspecialchars($_POST['status'] ?? '');
        $oldStatus = htmlspecialchars($_POST['old_status'] ?? '');

        $conn->begin_transaction();
        
        try {
            $requestQuery = "SELECT aid, aqty, status FROM add_office WHERE id = " . intval($id);
            $requestResult = $conn->query($requestQuery);
            $request = $requestResult->fetch_assoc();
            
            if (!$request) {
                throw new Exception("Request not found.");
            }

            if ($request['status'] !== $oldStatus) {
                throw new Exception("Status mismatch. Please refresh and try again.");
            }

            $updateQuery = "UPDATE `add_office` SET `status` = '" . $conn->real_escape_string($newStatus) . "' WHERE `id` = " . intval($id);
            $statusUpdated = $conn->query($updateQuery);
            
            if ($statusUpdated && $request) {
                if ($newStatus === 'Rejected' && $request['status'] === 'Approved') {
                    $updateAssetQuery = "UPDATE `asset` 
                        SET `aqty` = `aqty` + " . intval($request['aqty']) . " 
                        WHERE `aid` = " . intval($request['aid']);
                    
                    if (!$conn->query($updateAssetQuery)) {
                        throw new Exception("Failed to update asset quantity (add): " . $conn->error);
                    }
                } elseif ($newStatus === 'Approved' && $request['status'] === 'Rejected') {
                    $updateAssetQuery = "UPDATE `asset` 
                        SET `aqty` = `aqty` - " . intval($request['aqty']) . " 
                        WHERE `aid` = " . intval($request['aid']);
                    
                    if (!$conn->query($updateAssetQuery)) {
                        throw new Exception("Failed to update asset quantity (subtract): " . $conn->error);
                    }
                    $checkQuery = "SELECT aqty FROM asset WHERE aid = " . intval($request['aid']);
                    $checkResult = $conn->query($checkQuery);
                    $asset = $checkResult->fetch_assoc();
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

    // Fetch offices
    $officeQuery = "SELECT oid, oname FROM `off`";
    $officeResult = $conn->query($officeQuery);
    $offices = $officeResult->fetch_all(MYSQLI_ASSOC);

    // Fetch all departments for mapping did to dname
    $deptQuery = "SELECT did, dname FROM `dept`";
    $deptResult = $conn->query($deptQuery);
    $departments = $deptResult->fetch_all(MYSQLI_ASSOC);

    // Fetch departments for dropdown (did <= 21)
    $dropdownDeptQuery = "SELECT did, dname FROM `dept` WHERE did <= 21";
    $dropdownDeptResult = $conn->query($dropdownDeptQuery);
    $dropdownDepartments = $dropdownDeptResult->fetch_all(MYSQLI_ASSOC);

    // Fetch assets
    $assetQuery = "SELECT aid, aname FROM `asset`";
    $assetResult = $conn->query($assetQuery);
    $assets = $assetResult->fetch_all(MYSQLI_ASSOC);

    // Handle form submission
    if (isset($_POST["submit"])) {
        $conn->begin_transaction();
        try {
            $currentDate = getdate();
            $totalRows = count($_POST["oid"]);
            for ($count = 0; $count < $totalRows; $count++) {
                $oid = trim($_POST["oid"][$count]);
                $aname = trim($_POST["aname"][$count]);
                $atype = trim($_POST["atype"][$count]);
                $aqty = trim($_POST["aqty"][$count]);

                // Map oid to did or use submitted did
                if ($oid == 1) {
                    $did = 22;
                } elseif ($oid == 3) {
                    $did = 23;
                } elseif ($oid == 2) {
                    $did = trim($_POST["did"][$count] ?? '');
                    if (empty($did)) {
                        throw new Exception("Department must be selected for Office ID 2.");
                    }
                    if ($did > 21) {
                        throw new Exception("Invalid department selected for Office ID 2. Department ID must be 21 or less.");
                    }
                } else {
                    throw new Exception("Invalid office selected (OID: $oid). Only OID 1, 2, or 3 are allowed.");
                }

                $checkQuery = "SELECT `aid`, `aqty` FROM `asset` WHERE `aname` = '" . $conn->real_escape_string($aname) . "'";
                $checkResult = $conn->query($checkQuery);
                $existingAsset = $checkResult->fetch_assoc();

                if ($existingAsset) {
                    $aid = $existingAsset['aid'];
                    if ($existingAsset['aqty'] < $aqty) {
                        throw new Exception("Insufficient quantity for asset '$aname'. Available: " . $existingAsset['aqty']);
                    }
                } else {
                    throw new Exception("Asset '$aname' does not exist in inventory!");
                }

                $updateAssetQuery = "UPDATE `asset` SET `aqty` = `aqty` - " . intval($aqty) . " WHERE `aid` = " . intval($aid);
                $conn->query($updateAssetQuery);

                $insertQuery = "
                    INSERT INTO `add_office` (`oid`, `did`, `aid`, `aqty`, `status`, `date`, `month`, `year`) 
                    VALUES (
                        '" . $conn->real_escape_string($oid) . "',
                        '" . $conn->real_escape_string($did) . "',
                        '" . $conn->real_escape_string($aid) . "',
                        '" . $conn->real_escape_string($aqty) . "',
                        'Approved',
                        '" . $currentDate['mday'] . "',
                        '" . $currentDate['mon'] . "',
                        '" . $currentDate['year'] . "'
                    )";
                $conn->query($insertQuery);
            }

            $conn->commit();
            $display = '<div class="alert alert-success">Assets Added to Offices Successfully</div>';
        } catch (Exception $e) {
            $conn->rollback();
            $display = '<div class="alert alert-danger">Error: ' . $e->getMessage() . '</div>';
        }
    }

    // Fetch assets by status
    $statuses = ['New', 'Approved', 'Hold', 'Rejected'];
    $assetsByStatus = [];
    
    foreach ($statuses as $status) {
        $query = "
            SELECT ao.id, ao.oid, ao.did, ao.aid, ao.aqty, ao.status, ao.date, ao.month, ao.year,
                   o.oname, d.dname, a.aname, a.atype
            FROM add_office ao
            JOIN off o ON ao.oid = o.oid
            JOIN dept d ON ao.did = d.did
            JOIN asset a ON ao.aid = a.aid
            WHERE ao.status = '" . $conn->real_escape_string($status) . "'
            ORDER BY ao.date DESC, ao.month DESC, ao.year DESC
        ";
        $assetsByStatus[$status] = $conn->query($query);
        if (!$assetsByStatus[$status]) {
            $display .= '<div class="alert alert-danger">Query failed for ' . $status . ': ' . $conn->error . '</div>';
        }
    }

} catch (Exception $e) {
    $display = '<div class="alert alert-danger">Error: ' . $e->getMessage() . '</div>';
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Add Office Assets</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="assets/img/dln1.png" type="image/x-icon">
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

        /* Form table inputs */
        .form-control.input-sm {
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            padding: 0.25rem 0.5rem;
        }

        /* Department text styling */
        .dept-text {
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            padding: 0.25rem 0.5rem;
            color: #495057;
            line-height: 1.5;
            vertical-align: middle;
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

            .btn-status, .btn-success, .btn-primary, .btn-info, .btn-danger {
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

            .form-control.input-sm, .dept-text {
                font-size: 0.8rem;
            }
        }

        @media (max-width: 576px) {
            .table {
                font-size: 0.75rem;
            }

            th, td {
                padding: 0.5rem;
            }

            .btn-status, .btn-success, .btn-primary, .btn-info, .btn-danger {
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

            .form-control.input-sm, .dept-text {
                font-size: 0.7rem;
                padding: 0.2rem 0.4rem;
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
                            <h4 class="card-title">Add Assets to Offices</h4>
                        </div>
                        <div class="card-body">
                            <form action="" name="myform" id="myform" method="post">
                                <div class="table-responsive">
                                    <table id="office-table" class="table table-bordered">
                                        <thead>
                                            <tr>
                                                <th bgcolor="#F0F3F4">S.No.</th>
                                                <th bgcolor="#F0F3F4">Office</th>
                                                <th bgcolor="#F0F3F4">Department</th>
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
                                                    <select name="oid[]" class="form-control input-sm oid" required>
                                                        <option value="">Select Office</option>
                                                        <?php foreach ($offices as $office): ?>
                                                            <option value="<?php echo $office['oid']; ?>">
                                                                <?php echo htmlspecialchars($office['oname']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td class="did-cell">
                                                    <span class="dept-text">Select an office first</span>
                                                </td>
                                                <td>
                                                    <select name="aname[]" class="form-control input-sm aname" required>
                                                        <option value="">Select Asset</option>
                                                        <?php foreach ($assets as $asset): ?>
                                                            <option value="<?php echo htmlspecialchars($asset['aname']); ?>">
                                                                <?php echo htmlspecialchars($asset['aname']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td>
                                                    <select name="atype[]" class="form-control input-sm atype" style="width:150px;" required>
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

            <!-- Status Sections -->
            <?php foreach ($statuses as $index => $status): ?>
                <div class="status-section <?php echo $status === 'New' ? 'active' : ''; ?>" 
                     id="<?php echo strtolower($status); ?>-section">
                    <div class="card">
                        <div class="card-header">
                            <h4><?php echo $status; ?> Office Assets Requests</h4>
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
                                            <th>Office</th>
                                            <th>Department</th>
                                            <th>Asset Name</th>
                                            <th>Asset Type</th>
                                            <th>Quantity</th>
                                            <th>Date</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $result = $assetsByStatus[$status];
                                        if ($result && $result->num_rows > 0) {
                                            while ($row = $result->fetch_assoc()) {
                                                echo '<tr>';
                                                echo '<td>' . htmlspecialchars($row['oname']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['dname']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['aname']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['atype']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['aqty']) . '</td>';
                                                echo '<td>' . htmlspecialchars($row['date']) . '/' . htmlspecialchars($row['month']) . '/' . htmlspecialchars($row['year']) . '</td>';
                                                echo '<td>';
                                                echo '<div class="dropdown">';
                                                echo '<button class="btn btn-primary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">' . htmlspecialchars($row['status']) . '</button>';
                                                echo '<ul class="dropdown-menu">';
                                                foreach (['Approved', 'New', 'Hold', 'Rejected'] as $action) {
                                                    echo '<li><form method="POST" style="margin: 0;">';
                                                    echo '<input type="hidden" name="id" value="' . $row['id'] . '">';
                                                    echo '<input type="hidden" name="status" value="' . $action . '">';
                                                    echo '<input type="hidden" name="old_status" value="' . htmlspecialchars($row['status']) . '">';
                                                    echo '<button type="submit" name="update_status" class="dropdown-item">' . $action . '</button>';
                                                    echo '</form></li>';
                                                }
                                                echo '</ul>';
                                                echo '</div>';
                                                echo '</td>';
                                                echo '</tr>';
                                            }
                                        } else {
                                            echo '<tr><td colspan="7" class="text-center">No ' . strtolower($status) . ' assets assigned to offices.</td></tr>';
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
    $(document).ready(function() {
        // Department mapping for oid to did and did to dname
        var deptMapping = {
            '1': { did: '22', dname: '' },
            '3': { did: '23', dname: '' },
            '2': { did: null, dname: null }
        };
        var didToDname = {};
        <?php foreach ($departments as $dept): ?>
            didToDname['<?php echo $dept['did']; ?>'] = '<?php echo addslashes($dept['dname']); ?>';
            <?php if ($dept['did'] == 22): ?>
                deptMapping['1'].dname = '<?php echo addslashes($dept['dname']); ?>';
            <?php elseif ($dept['did'] == 23): ?>
                deptMapping['3'].dname = '<?php echo addslashes($dept['dname']); ?>';
            <?php endif; ?>
        <?php endforeach; ?>

        // Function to update department cell
            function updateDepartmentCell(row) {
                var oidSelect = row.find('.oid');
                var oid = oidSelect.val();
                var didCell = row.find('.did-cell');
                
                // Clear existing content
                didCell.empty();

                if (!oid) {
                    didCell.append('<span class="dept-text">Select an office first</span>');
                    return;
                }

                var rowIndex = row.index();

                if (oid === '2') {
                    var deptSelect = $('<select name="did[' + rowIndex + ']" class="form-control input-sm did" required></select>');
                    deptSelect.append('<option value="">Select Department</option>');
                    <?php foreach ($dropdownDepartments as $dept): ?>
                        deptSelect.append('<option value="<?php echo $dept['did']; ?>"><?php echo htmlspecialchars($dept['dname']); ?></option>');
                    <?php endforeach; ?>
                    didCell.append(deptSelect);
                } else if (deptMapping[oid]) {
                    var dname = deptMapping[oid].dname || 'Unknown Department';
                    var didValue = deptMapping[oid].did || '';
                    didCell.append('<span class="dept-text">' + dname + '</span><input type="hidden" name="did[' + rowIndex + ']" value="' + didValue + '">');
                } else {
                    didCell.append('<span class="dept-text">Invalid Office</span>');
                }
            }

        // Apply to existing row
        updateDepartmentCell($('#office-table tbody tr'));

        // Event listener for office select change
        $(document).on('change', '.oid', function() {
            var row = $(this).closest('tr');
            updateDepartmentCell(row);
        });

        var count = 1;
            $('#add_row').on('click', function() {
                count++;
                $('#total_row').val(count);
                var html_code = '<tr id="row_id_' + count + '">';
                html_code += '<td>' + count + '</td>';
                html_code += '<td><select name="oid[' + (count - 1) + ']" class="form-control input-sm oid" required>';
                html_code += '<option value="">Select Office</option>';
                <?php foreach ($offices as $office): ?>
                    html_code += '<option value="<?php echo $office['oid']; ?>"><?php echo htmlspecialchars($office['oname']); ?></option>';
                <?php endforeach; ?>
                html_code += '</select></td>';
                html_code += '<td class="did-cell"><span class="dept-text">Select an office first</span></td>';
                html_code += '<td><select name="aname[' + (count - 1) + ']" class="form-control input-sm aname" required>';
                html_code += '<option value="">Select Asset</option>';
                <?php foreach ($assets as $asset): ?>
                    html_code += '<option value="<?php echo htmlspecialchars($asset['aname']); ?>"><?php echo htmlspecialchars($asset['aname']); ?></option>';
                <?php endforeach; ?>
                html_code += '</select></td>';
                html_code += '<td><select name="atype[' + (count - 1) + ']" class="form-control input-sm atype" style="width:150px;" required>';
                html_code += '<option value="">Select Any</option><option value="Electronics">Electronics</option><option value="Furniture">Furniture</option></select></td>';
                html_code += '<td><input type="number" name="aqty[' + (count - 1) + ']" class="form-control input-sm aqty" min="1" required /></td>';
                html_code += '<td><button type="button" name="remove_row" class="btn btn-danger remove_row">X</button></td>';
                html_code += '</tr>';
                $('#office-table tbody').append(html_code);
                updateDepartmentCell($('#row_id_' + count));
                updateRemoveButtons();
            });

            function updateRemoveButtons() {
                var rows = $('#office-table tbody tr');
                if (rows.length === 1) {
                    rows.find('.remove_row').prop('disabled', true);
                } else {
                    rows.find('.remove_row').prop('disabled', false);
                }
            }

            $(document).on('click', '.remove_row', function() {
                var row = $(this).closest('tr');
                row.remove();
                count--;
                $('#total_row').val(count);
                updateRemoveButtons();
                updateSerialNumbers();
            });

            function updateSerialNumbers() {
                $('#office-table tbody tr').each(function(index) {
                    $(this).find('td:first').text(index + 1);
                });
            }

            // Initialize remove buttons state on page load
            updateRemoveButtons();

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
            $('.oid').each(function(index) {
                if ($(this).val() === '2' && !$(this).closest('tr').find('.did').val()) {
                    valid = false;
                    alert('Please select a department for Office ID 2.');
                    $(this).closest('tr').find('.did').focus();
                    return false;
                }
            });
            if (!valid) {
                e.preventDefault();
            }
        });
    });

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
                if (td.querySelector('.dropdown-toggle')) {
                    row.push(td.querySelector('.dropdown-toggle').innerText);
                } else {
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
            link.download = `${status}_office_assets_${new Date().toISOString().slice(0,10)}.${format}`;
            link.click();
        } else if (format === 'pdf') {
            const doc = new jsPDF();
            doc.autoTable({
                head: [headers],
                body: data.slice(1),
                styles: { fontSize: 8 },
                headStyles: { fillColor: [41, 128, 185] }
            });
            doc.save(`${status}_office_assets_${new Date().toISOString().slice(0,10)}.pdf`);
        }
    }
    </script>
</body>
</html>

<?php $conn->close(); ?>