<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('America/New_York'); // Replace with your desired timezone

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

$display = "";
$labId = null;
$labDisplay = "";
$departmentId = null;

// Get current date components in server's timezone
$currentDate = new DateTime('now', new DateTimeZone('America/New_York'));
$currentDay = $currentDate->format('d');
$currentMonth = $currentDate->format('m');
$currentYear = $currentDate->format('Y');
$maxDate = $currentDate->format('Y-m-d');

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "inventory";

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch welcome message
    $welcomeMessage = "IMS";
    $loggedInUsername = $_SESSION['username'];
    $welcomeStmt = $conn->prepare("
        SELECT d.dname 
        FROM stlog s 
        JOIN teacher t ON t.temail = s.uemail 
        JOIN dept d ON d.did = t.did 
        WHERE s.username = :username
    ");
    $welcomeStmt->execute([':username' => $loggedInUsername]);
    if ($welcomeStmt->rowCount() > 0) {
        $welcomeRow = $welcomeStmt->fetch(PDO::FETCH_ASSOC);
        $welcomeMessage = "IMS " . htmlspecialchars($welcomeRow['dname']);
    }

    // Fetch teacher's did
    $userStmt = $conn->prepare("
        SELECT t.did
        FROM teacher t
        JOIN stlog s ON s.uemail = t.temail
        WHERE s.username = :username
    ");
    $userStmt->execute([':username' => $loggedInUsername]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $display = '<div class="alert alert-danger">Teacher not found!</div>';
        $teacherDid = null;
    } else {
        $teacherDid = $user['did'];
    }

    // Fetch lab for teacher's did
    if ($teacherDid) {
        $labStmt = $conn->prepare("SELECT lid, lname, did FROM lab WHERE did = :did LIMIT 1");
        $labStmt->execute([':did' => $teacherDid]);
        $labResult = $labStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($labResult) {
            $lab = $labResult[0];
            $labId = $lab['lid'];
            $departmentId = $lab['did'];
            $labDisplay = htmlspecialchars($lab['lname'] . ' (DID: ' . $lab['did'] . ')');
        } else {
            $display = '<div class="alert alert-danger">No lab found for your department!</div>';
        }
    } else {
        $display = '<div class="alert alert-danger">Invalid department!</div>';
    }

    // Fetch asset names
    $assetStmt = $conn->prepare("SELECT aid, aname, atype, aqty FROM asset");
    $assetStmt->execute();
    $assetNamesResult = $assetStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch lab requests for the user's did and lab lid
    $labRequestsStmt = $conn->prepare("
        SELECT al.id, al.lid, al.aid, al.aqty, al.date, al.month, al.year, al.status, a.aname, l.lname 
        FROM add_lab al 
        JOIN asset a ON al.aid = a.aid 
        JOIN lab l ON al.lid = l.lid 
        WHERE al.did = :did AND al.lid = :lid 
        ORDER BY al.year DESC, al.month DESC, al.date DESC
    ");
    $labRequestsStmt->execute([':did' => $teacherDid, ':lid' => $labId]);
    $labRequestsResult = $labRequestsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Handle form submission
    if (isset($_POST['insert']) && $labId) {
        $assetIds = array_map('intval', $_POST['assetNames'] ?? []);
        $quantities = array_map('intval', $_POST['nqty'] ?? []);
        $days = array_map('intval', $_POST['day'] ?? []);
        $months = array_map('intval', $_POST['month'] ?? []);
        $years = array_map('intval', $_POST['year'] ?? []);

        if (count($assetIds) !== count(array_unique($assetIds))) {
            $display = '<div class="alert alert-danger">Duplicate asset names detected!</div>';
        } else {
            try {
                $conn->beginTransaction();
                $insertedRecords = [];

                foreach ($assetIds as $index => $assetId) {
                    $quantity = $quantities[$index];
                    $day = $days[$index];
                    $month = $months[$index];
                    $year = $years[$index];

                    // Validate date
                    try {
                        $inputDate = new DateTime("$year-$month-$day", new DateTimeZone('America/New_York'));
                        if ($inputDate > $currentDate) {
                            throw new Exception('Date cannot be in the future!');
                        }
                    } catch (Exception $e) {
                        throw new Exception('Invalid date for asset!');
                    }

                    if ($day < 1 || $day > 31 || $month < 1 || $month > 12 || $year < 2000) {
                        throw new Exception('Invalid date values!');
                    }

                    if ($quantity < 1) {
                        throw new Exception('Quantity must be at least 1!');
                    }

                    // Fetch asset details
                    $assetStmt = $conn->prepare("SELECT aname, atype, aqty FROM asset WHERE aid = :aid");
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
                        $display .= '<script>setTimeout(function() { window.location.href = "spreq.php?asset_name=' . urlencode($assetName) . '&asset_type=' . urlencode($assetType) . '&quantity=' . $quantity . '&locationtype=lab&roomnum=' . urlencode($labId) . '&day=' . $currentDay . '&month=' . $currentMonth . '&year=' . $currentYear . '"; }, 2000);</script>';
                        echo $display;
                        $conn = null;
                        exit();
                    } elseif ($quantity > $totalQuantity && $totalQuantity > 0) {
                        // Place request for available quantity and redirect for shortfall
                        $insertStmt = $conn->prepare("
                            INSERT INTO add_lab (lid, did, aid, aqty, date, month, year, status)
                            VALUES (:lid, :did, :aid, :aqty, :date, :month, :year, 'New')
                        ");
                        $insertStmt->execute([
                            ':lid' => $labId,
                            ':did' => $departmentId,
                            ':aid' => $assetId,
                            ':aqty' => $totalQuantity,
                            ':date' => $day,
                            ':month' => $month,
                            ':year' => $year
                        ]);

                        // Update asset stock to 0
                        $updateStmt = $conn->prepare("UPDATE asset SET aqty = 0 WHERE aid = :aid");
                        $updateStmt->execute([':aid' => $assetId]);

                        // Redirect for shortfall
                        $shortfall = $quantity - $totalQuantity;
                        $conn->commit();
                        $display = '<div class="alert alert-success">Request for ' . $totalQuantity . ' ' . htmlspecialchars($assetName) . ' added successfully. Redirecting for special request of ' . $shortfall . ' unit(s).</div>';
                        $display .= '<script>setTimeout(function() { window.location.href = "spreq.php?asset_name=' . urlencode($assetName) . '&asset_type=' . urlencode($assetType) . '&quantity=' . $shortfall . '&locationtype=lab&roomnum=' . urlencode($labId) . '&day=' . $currentDay . '&month=' . $currentMonth . '&year=' . $currentYear . '"; }, 2000);</script>';
                        echo $display;
                        $conn = null;
                        exit();
                    } else {
                        // Process full quantity if available
                        $insertStmt = $conn->prepare("
                            INSERT INTO add_lab (lid, did, aid, aqty, date, month, year, status)
                            VALUES (:lid, :did, :aid, :aqty, :date, :month, :year, 'New')
                        ");
                        $insertStmt->execute([
                            ':lid' => $labId,
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
                    $updateStmt = $conn->prepare("UPDATE asset SET aqty = aqty - :quantity WHERE aid = :aid");
                    $updateStmt->execute([
                        ':quantity' => $record['quantity'],
                        ':aid' => $record['aid']
                    ]);
                }

                $conn->commit();
                $display = '<div class="alert alert-success">Assets added to Lab Successfully and Stock Updated!</div>';
            } catch (Exception $e) {
                $conn->rollBack();
                $display = '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }
    }
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $display = '<div class="alert alert-danger">Database error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

$conn = null;
?>

<?php include('theader.php'); ?>

<!-- Add custom CSS for transparent welcome message and alerts -->
<style>
.transparent-alert {
    background-color: transparent !important;
    border: none;
    padding: 10px;
}
.alert {
    margin: 10px 0;
    padding: 10px;
    border-radius: 4px;
}
.alert-success {
    background-color: #d4edda;
    color: #155724;
}
.alert-danger {
    background-color: #f8d7da;
    color: #721c24;
}
.alert-info {
    background-color: #cce5ff;
    color: #004085;
}
</style>

<div class="app-main" id="main">
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <!-- Display Welcome Message -->
                <div class="alert alert-info transparent-alert">
                    <h4><?php echo $welcomeMessage; ?></h4>
                </div>
                <div class="card card-statistics">
                    <div class="card-header">
                        <h4 class="card-title">Add Multiple Assets to Lab</h4>
                    </div>
                    <div class="card-body">
                        <?php echo $display; ?>
                        <form action="" name="myform" id="myform" method="post" onsubmit="return validateForm()" <?php if (!$labId) echo 'style="display:none;"'; ?>>
                            <div class="form-group">
                                <label for="labName">Lab Name</label>
                                <p class="form-control-static"><?php echo $labDisplay; ?></p>
                                <input type="hidden" name="labId" value="<?php echo $labId; ?>">
                                <input type="hidden" name="departmentId" value="<?php echo $departmentId; ?>">
                            </div>

                            <div id="assetFormContainer">
                                <div class="assetForm">
                                    <button type="button" class="deleteEntry" onclick="deleteEntry(this)" style="float: right; background-color: transparent; border: none;">x</button>
                                    <div class="form-group">
                                        <label for="assetName">Asset Name</label>
                                        <select class="form-control assetNames" name="assetNames[]" required>
                                            <option value="" disabled selected>Select Asset Name</option>
                                            <?php foreach ($assetNamesResult as $row): ?>
                                                <option value="<?php echo $row['aid']; ?>" data-aqty="<?php echo $row['aqty']; ?>" data-atype="<?php echo htmlspecialchars($row['atype']); ?>">
                                                    <?php echo htmlspecialchars($row['aname'] . ' (Available: ' . $row['aqty'] . ')'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="quantity">Total Quantity in Stock</label>
                                        <input type="number" class="form-control totalQuantity" name="quantities[]" readonly>
                                    </div>
                                    <div class="form-group">
                                        <label for="quantity">Quantity Needed</label>
                                        <input type="number" class="form-control nqty" name="nqty[]" required>
                                    </div>
                                    <!-- Hidden date fields -->
                                    <input type="hidden" name="day[]" value="<?php echo $currentDay; ?>">
                                    <input type="hidden" name="month[]" value="<?php echo $currentMonth; ?>">
                                    <input type="hidden" name="year[]" value="<?php echo $currentYear; ?>">
                                    <hr>
                                </div>
                            </div>

                            <button type="button" class="btn btn-secondary" id="addMore">Add More Assets</button>
                            <button type="submit" class="btn btn-primary" name="insert" id="insert">Submit</button>
                        </form>
                    </div>
                </div>
                <div class="card card-statistics mt-4">
                    <div class="card-header">
                        <h4 class="card-title">Lab Asset Requests</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th bgcolor="#F0F3F4">Request ID</th>
                                        <th bgcolor="#F0F3F4">Lab Name</th>
                                        <th bgcolor="#F0F3F4">Asset Name</th>
                                        <th bgcolor="#F0F3F4">Quantity</th>
                                        <th bgcolor="#F0F3F4">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($labRequestsResult)): ?>
                                        <tr><td colspan="5" class="text-center">No requests found for this lab.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($labRequestsResult as $request): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($request['id']); ?></td>
                                                <td><?php echo htmlspecialchars($request['lname']); ?></td>
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

<script>
let selectedAssets = [];

function deleteEntry(button) {
    var entry = button.closest('.assetForm');
    var assetId = entry.querySelector('.assetNames').value;
    selectedAssets = selectedAssets.filter(id => id !== assetId);
    entry.remove();
    updateAssetDropdowns();
}

function fetchAssetDetails(assetId, form) {
    if (assetId) {
        var assetOption = form.querySelector('.assetNames option[value="' + assetId + '"]');
        var totalQuantity = parseInt(assetOption.getAttribute('data-aqty')) || 0;
        form.querySelector('.totalQuantity').value = totalQuantity;
    } else {
        form.querySelector('.totalQuantity').value = '';
    }
}

function updateAssetDropdowns() {
    var assetDropdowns = document.querySelectorAll('.assetNames');
    assetDropdowns.forEach(function(dropdown) {
        var options = dropdown.querySelectorAll('option');
        options.forEach(function(option) {
            if (option.value && selectedAssets.includes(option.value)) {
                option.style.display = 'none';
            } else {
                option.style.display = '';
            }
        });
    });
}

document.addEventListener('change', function(event) {
    if (event.target.classList.contains('assetNames')) {
        var assetId = event.target.value;
        var form = event.target.closest('.assetForm');
        
        if (assetId && !selectedAssets.includes(assetId)) {
            selectedAssets.push(assetId);
        }
        
        fetchAssetDetails(assetId, form);
        updateAssetDropdowns();
    }
});

document.getElementById("addMore").addEventListener("click", function() {
    var newAssetForm = document.querySelector(".assetForm").cloneNode(true);
    document.getElementById("assetFormContainer").appendChild(newAssetForm);
    
    var newSelect = newAssetForm.querySelector('.assetNames');
    newSelect.value = '';
    newAssetForm.querySelector('.totalQuantity').value = '';
    newAssetForm.querySelector('.nqty').value = '';
    
    updateAssetDropdowns();
});

function validateForm() {
    var forms = document.querySelectorAll('.assetForm');
    if (forms.length === 0) {
        alert('Please add at least one asset.');
        return false;
    }

    for (var i = 0; i < forms.length; i++) {
        var form = forms[i];
        var assetId = form.querySelector('.assetNames').value;
        var nqty = form.querySelector('.nqty').value;
        
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

<script src="assets/js/vendors.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>