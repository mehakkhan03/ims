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

// Get current date components
$currentDate = date('Y-m-d');
$currentDay = date('d'); // e.g., 28
$currentMonth = date('m'); // e.g., 06
$currentYear = date('Y'); // e.g., 2025

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch welcome message
    $welcomeMessage = "IMS";
    $loggedInUsername = $_SESSION['username'];
    $welcomeStmt = $conn->prepare("
        SELECT d.dname 
        FROM `stlog` s 
        JOIN `teacher` t ON t.temail = s.uemail 
        JOIN `dept` d ON d.did = t.did 
        WHERE s.username = :username");
    $welcomeStmt->execute([':username' => $loggedInUsername]);
    if ($welcomeStmt->rowCount() > 0) {
        $welcomeRow = $welcomeStmt->fetch(PDO::FETCH_ASSOC);
        $welcomeMessage = "IMS " . htmlspecialchars($welcomeRow['dname']);
    }

    // Fetch user's did
    $didStmt = $conn->prepare("
        SELECT t.did 
        FROM `stlog` s 
        JOIN `teacher` t ON t.temail = s.uemail 
        WHERE s.username = :username");
    $didStmt->execute([':username' => $loggedInUsername]);
    if ($didStmt->rowCount() > 0) {
        $didRow = $didStmt->fetch(PDO::FETCH_ASSOC);
        $departmentId = $didRow['did'];
    } else {
        throw new Exception("User is not associated with a department!");
    }

    // Fetch asset names
    $assetStmt = $conn->prepare("SELECT aid, aname, atype, aqty FROM `asset`");
    $assetStmt->execute();
    $assetNamesResult = $assetStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch Faculty Office oid
    $officeStmt = $conn->prepare("SELECT oid FROM `off` WHERE oname = :oname");
    $officeStmt->execute([':oname' => 'Faculty Office']);
    if ($officeStmt->rowCount() > 0) {
        $officeRow = $officeStmt->fetch(PDO::FETCH_ASSOC);
        $facultyOfficeId = $officeRow['oid'];
    } else {
        throw new Exception("Faculty Office not found in database!");
    }

    // Fetch office requests for the user's did and Faculty Office oid
    $officeRequestsStmt = $conn->prepare("
        SELECT ao.id, ao.aid, ao.aqty, ao.date, ao.month, ao.year, ao.status, a.aname 
        FROM add_office ao 
        JOIN asset a ON ao.aid = a.aid 
        WHERE ao.did = :did AND ao.oid = :oid 
        ORDER BY ao.year DESC, ao.month DESC, ao.date DESC
    ");
    $officeRequestsStmt->execute([':did' => $departmentId, ':oid' => $facultyOfficeId]);
    $officeRequestsResult = $officeRequestsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Handle form submission
    if (isset($_POST['insert'])) {
        $assetIds = array_map('intval', $_POST['assetNames'] ?? []);
        $quantities = array_map('intval', $_POST['nqty'] ?? []);
        $days = array_map('intval', $_POST['day'] ?? []);
        $months = array_map('intval', $_POST['month'] ?? []);
        $years = array_map('intval', $_POST['year'] ?? []);

        if (count($assetIds) !== count(array_unique($assetIds))) {
            $display = '<div class="alert alert-danger">Duplicate asset names detected! Please select unique asset names.</div>';
        } else {
            try {
                $conn->beginTransaction();
                $insertedRecords = [];

                foreach ($assetIds as $index => $assetId) {
                    $quantity = $quantities[$index];
                    $day = $days[$index];
                    $month = $months[$index];
                    $year = $years[$index];

                    if ($day < 1 || $day > 31 || $month < 1 || $month > 12 || $year < 2000) {
                        throw new Exception("Invalid date values for asset!");
                    }

                    if ($quantity < 1) {
                        throw new Exception("Requested quantity must be at least 1!");
                    }

                    // Fetch asset details
                    $assetStmt = $conn->prepare("SELECT aname, atype, aqty FROM `asset` WHERE aid = :aid");
                    $assetStmt->execute([':aid' => $assetId]);
                    if ($assetStmt->rowCount() === 0) {
                        throw new Exception("Invalid asset selected!");
                    }
                    $assetRow = $assetStmt->fetch(PDO::FETCH_ASSOC);
                    $assetName = $assetRow['aname'];
                    $assetType = $assetRow['atype'];
                    $totalQuantity = $assetRow['aqty'];

                    if ($totalQuantity == 0) {
                        // No stock available, redirect for full quantity without insertion
                        $conn->commit();
                        $display = '<div class="alert alert-info">No stock available for ' . htmlspecialchars($assetName) . '. Redirecting for special request of ' . $quantity . ' unit(s).</div>';
                        $display .= '<script>setTimeout(function() { window.location.href = "spreq.php?asset_name=' . urlencode($assetName) . '&asset_type=' . urlencode($assetType) . '&quantity=' . $quantity . '&locationtype=faculty office&roomnum=' . urlencode($facultyOfficeId) . '&day=' . $currentDay . '&month=' . $currentMonth . '&year=' . $currentYear . '"; }, 2000);</script>';
                        echo $display;
                        $conn = null;
                        exit();
                    } elseif ($quantity > $totalQuantity && $totalQuantity > 0) {
                        // Place request for available quantity and redirect for shortfall
                        $insertStmt = $conn->prepare("
                            INSERT INTO `add_office` (`oid`, `did`, `aid`, `aqty`, `date`, `month`, `year`, `status`) 
                            VALUES (:oid, :did, :aid, :aqty, :date, :month, :year, 'New')");
                        $insertStmt->execute([
                            ':oid' => $facultyOfficeId,
                            ':did' => $departmentId,
                            ':aid' => $assetId,
                            ':aqty' => $totalQuantity,
                            ':date' => $day,
                            ':month' => $month,
                            ':year' => $year
                        ]);

                        // Update asset stock to 0
                        $updateStmt = $conn->prepare("UPDATE `asset` SET aqty = 0 WHERE aid = :aid");
                        $updateStmt->execute([':aid' => $assetId]);

                        // Redirect for shortfall
                        $shortfall = $quantity - $totalQuantity;
                        $conn->commit();
                        $display = '<div class="alert alert-success">Request for ' . $totalQuantity . ' ' . htmlspecialchars($assetName) . ' added successfully. Redirecting for special request of ' . $shortfall . ' unit(s).</div>';
                        $display .= '<script>setTimeout(function() { window.location.href = "spreq.php?asset_name=' . urlencode($assetName) . '&asset_type=' . urlencode($assetType) . '&quantity=' . $shortfall . '&locationtype=faculty office&roomnum=' . urlencode($facultyOfficeId) . '&day=' . $currentDay . '&month=' . $currentMonth . '&year=' . $currentYear . '"; }, 2000);</script>';
                        echo $display;
                        $conn = null;
                        exit();
                    } else {
                        // Process full quantity if available
                        $insertStmt = $conn->prepare("
                            INSERT INTO `add_office` (`oid`, `did`, `aid`, `aqty`, `date`, `month`, `year`, `status`) 
                            VALUES (:oid, :did, :aid, :aqty, :date, :month, :year, 'New')");
                        $insertStmt->execute([
                            ':oid' => $facultyOfficeId,
                            ':did' => $departmentId,
                            ':aid' => $assetId,
                            ':aqty' => $quantity,
                            ':date' => $day,
                            ':month' => $month,
                            ':year' => $year
                        ]);

                        $insertedRecords[] = ['aid' => $assetId, 'quantity' => $quantity];
                    }
                }

                // Update stock for full quantity requests
                foreach ($insertedRecords as $record) {
                    $updateStmt = $conn->prepare("UPDATE `asset` SET aqty = aqty - :quantity WHERE aid = :aid");
                    $updateStmt->execute([
                        ':quantity' => $record['quantity'],
                        ':aid' => $record['aid']
                    ]);
                }

                $conn->commit();
                $display = '<div class="alert alert-success">Assets added to Faculty Office Successfully and Stock Updated!</div>';
            } catch (Exception $e) {
                $conn->rollBack();
                $display = '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }
    }
} catch (Exception $e) {
    $display = '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
$conn = null;
?>

<!DOCTYPE html>
<html>
<head>
    <title>Request Faculty Office Assets</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" href="assets/img/dln1.png" type="image/x-icon">
    <style>
        .container-fluid { padding: 20px; }
        .form-section { margin-bottom: 30px; }
        .alert { margin-top: 20px; padding: 10px; border-radius: 4px; }
        .alert-success { background-color: #d4edda; color: #155724; }
        .alert-danger { background-color: #f8d7da; color: #721c24; }
        .alert-info { background-color: #cce5ff; color: #004085; }
        .asset-row { margin-bottom: 10px; }
        .remove-asset {
            background: transparent;
            border: none;
            color: #dc3545;
            font-size: 14px;
            padding: 0;
            width: 20px;
            height: 20px;
            line-height: 20px;
        }
        .remove-col { text-align: right; }
        .transparent-alert {
            background-color: transparent !important;
            border: 0 !important;
            padding: 10px;
        }
    </style>
</head>
<body>
    <?php include('theader.php'); ?>

    <div class="app-main" id="main">
        <div class="container-fluid">
            <!-- Welcome Message -->
            <div class="alert alert-info transparent-alert">
                <h4><?php echo $welcomeMessage; ?></h4>
            </div>

            <!-- Display Messages -->
            <?php if (!empty($display)) echo $display; ?>

            <!-- Form Section -->
            <div class="row form-section">
                <div class="col-md-12">
                    <form method="POST" class="card p-4" onsubmit="return validateForm()">
                        <h3>Add Assets to Faculty Office</h3>
                        <div class="mb-3">
                            <label class="form-label">Office:</label>
                            <input type="hidden" name="officeName" value="<?php echo $facultyOfficeId; ?>">
                            <input type="text" class="form-control" value="Faculty Office" readonly>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Assets:</label>
                            <div id="asset-fields">
                                <div class="row asset-row">
                                    <div class="col-md-5">
                                        <select name="assetNames[]" class="form-select assetNames" required>
                                            <option value="">Select Asset</option>
                                            <?php foreach ($assetNamesResult as $row): ?>
                                                <option value="<?php echo $row['aid']; ?>" data-aqty="<?php echo $row['aqty']; ?>" data-atype="<?php echo htmlspecialchars($row['atype']); ?>">
                                                    <?php echo htmlspecialchars($row['aname']) . ' (Available: ' . $row['aqty'] . ')'; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-5">
                                        <input type="number" name="nqty[]" class="form-control nqty" placeholder="Quantity" min="1" required>
                                    </div>
                                    <!-- Hidden date fields -->
                                    <input type="hidden" name="day[]" value="<?php echo $currentDay; ?>">
                                    <input type="hidden" name="month[]" value="<?php echo $currentMonth; ?>">
                                    <input type="hidden" name="year[]" value="<?php echo $currentYear; ?>">
                                    <div class="col-md-2 remove-col">
                                        <button type="button" class="remove-asset">X</button>
                                    </div>
                                </div>
                            </div>
                            <button type="button" id="add-asset" class="btn btn-secondary mt-2">Add More Assets</button>
                        </div>

                        <button type="submit" name="insert" class="btn btn-primary">Add Assets</button>
                    </form>
                </div>
            </div>

            <!-- Office Requests Table -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card card-statistics mt-4">
                        <div class="card-header">
                            <h4 class="card-title">Faculty Office Asset Requests</h4>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead>
                                        <tr>
                                            <th bgcolor="#F0F3F4">Request ID</th>
                                            <th bgcolor="#F0F3F4">Asset Name</th>
                                            <th bgcolor="#F0F3F4">Quantity</th>
                                            <th bgcolor="#F0F3F4">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($officeRequestsResult)): ?>
                                            <tr><td colspan="4" class="text-center">No requests found for Faculty Office.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($officeRequestsResult as $request): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($request['id']); ?></td>
                                                    <td><?php echo htmlspecialchars($request['aname']); ?></td>
                                                    <td><?php echo number_format($request['aqty']); ?></td>
                                                    <td><?php echo htmlspecialchars($request['status']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
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
    <script src="assets/js/vendors.js"></script>
    <script src="assets/js/app.js"></script>
    <script>
        let selectedAssets = [];

        document.getElementById('add-asset').addEventListener('click', function() {
            const assetFields = document.getElementById('asset-fields');
            const newRow = document.createElement('div');
            newRow.className = 'row asset-row';
            newRow.innerHTML = `
                <div class="col-md-5">
                    <select name="assetNames[]" class="form-select assetNames" required>
                        <option value="">Select Asset</option>
                        <?php foreach ($assetNamesResult as $row): ?>
                            <option value="<?php echo $row['aid']; ?>" data-aqty="<?php echo $row['aqty']; ?>" data-atype="<?php echo htmlspecialchars($row['atype']); ?>">
                                <?php echo htmlspecialchars($row['aname']) . ' (Available: ' . $row['aqty'] . ')'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <input type="number" name="nqty[]" class="form-control nqty" placeholder="Quantity" min="1" required>
                </div>
                <!-- Hidden date fields -->
                <input type="hidden" name="day[]" value="<?php echo $currentDay; ?>">
                <input type="hidden" name="month[]" value="<?php echo $currentMonth; ?>">
                <input type="hidden" name="year[]" value="<?php echo $currentYear; ?>">
                <div class="col-md-2 remove-col">
                    <button type="button" class="remove-asset">X</button>
                </div>
            `;
            assetFields.appendChild(newRow);
            updateAssetDropdowns();
        });

        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('remove-asset')) {
                const assetRows = document.querySelectorAll('.asset-row');
                if (assetRows.length > 1) {
                    const row = e.target.closest('.asset-row');
                    const assetId = row.querySelector('.assetNames').value;
                    selectedAssets = selectedAssets.filter(id => id !== assetId);
                    row.remove();
                    updateAssetDropdowns();
                }
            }
        });

        document.addEventListener('change', function(event) {
            if (event.target.classList.contains('assetNames')) {
                const assetId = event.target.value;
                if (assetId && !selectedAssets.includes(assetId)) {
                    selectedAssets.push(assetId);
                }
                updateAssetDropdowns();
            }
        });

        function updateAssetDropdowns() {
            const assetDropdowns = document.querySelectorAll('.assetNames');
            assetDropdowns.forEach(dropdown => {
                const options = dropdown.querySelectorAll('option');
                options.forEach(option => {
                    if (option.value && selectedAssets.includes(option.value)) {
                        option.style.display = 'none';
                    } else {
                        option.style.display = '';
                    }
                });
            });
        }

        function validateForm() {
            const forms = document.querySelectorAll('.asset-row');
            if (forms.length === 0) {
                alert('Please add at least one asset.');
                return false;
            }

            for (let i = 0; i < forms.length; i++) {
                const form = forms[i];
                const assetId = form.querySelector('.assetNames').value;
                const nqty = form.querySelector('.nqty').value;

                if (!assetId) {
                    alert('Please select an asset for all entries.');
                    return false;
                }
                if (!nqty || isNaN(nqty) || parseInt(nqty) < 1) {
                    alert('Please enter a valid quantity (minimum 1) for all assets.');
                    return false;
                }
            }
            return true;
        }
    </script>
</body>
</html>