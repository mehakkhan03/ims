<?php
// Start session
session_start();

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit();
}

// Get current date components
$display = "";
$currentDate = date('Y-m-d');
$currentDay = date('d'); // 28
$currentMonth = date('m'); // 06
$currentYear = date('Y'); // 2025

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
    $conn->autocommit(FALSE); // Enable transactions
} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    die("Database connection error. Please try again later.");
}

// Fetch department name for welcome message
$welcomeMessage = "IMS";
$loggedInUsername = $_SESSION['username'];
try {
    $welcomeStmt = $conn->prepare(
        "SELECT d.dname 
         FROM `stlog` s 
         JOIN `teacher` t ON t.temail = s.uemail 
         JOIN `dept` d ON d.did = t.did 
         WHERE s.username = ?"
    );
    $welcomeStmt->bind_param("s", $loggedInUsername);
    $welcomeStmt->execute();
    $welcomeResult = $welcomeStmt->get_result();
    if ($welcomeResult->num_rows > 0) {
        $welcomeRow = $welcomeResult->fetch_assoc();
        $welcomeMessage = "IMS " . htmlspecialchars($welcomeRow['dname']);
    }
    $welcomeStmt->close();
} catch (Exception $e) {
    error_log("Welcome query error: " . $e->getMessage());
}

// Fetch teacher's did
$departmentId = null;
try {
    $didStmt = $conn->prepare(
        "SELECT t.did 
         FROM `stlog` s 
         JOIN `teacher` t ON t.temail = s.uemail 
         WHERE s.username = ?"
    );
    $didStmt->bind_param("s", $loggedInUsername);
    $didStmt->execute();
    $didResult = $didStmt->get_result();
    if ($didResult->num_rows > 0) {
        $didRow = $didResult->fetch_assoc();
        $departmentId = $didRow['did'];
    } else {
        $display = '<div class="alert alert-danger">Error: User is not associated with a department!</div>';
    }
    $didStmt->close();
} catch (Exception $e) {
    error_log("DID query error: " . $e->getMessage());
    $display = '<div class="alert alert-danger">Error fetching department information.</div>';
}

// Fetch asset names
$assetNamesStmt = $conn->prepare("SELECT aid, aname, atype, aqty FROM `asset`");
if (!$assetNamesStmt || !$assetNamesStmt->execute()) {
    error_log("Asset query failed: " . $conn->error);
    $display = '<div class="alert alert-danger">Failed to load assets. Please try again.</div>';
} else {
    $assetNamesResult = $assetNamesStmt->get_result();
}

// Fetch department requests for the user's did
$deptRequestsStmt = $conn->prepare("
    SELECT ad.id, ad.aid, ad.nqty, ad.date, ad.month, ad.year, ad.status, a.aname 
    FROM add_dept ad 
    JOIN asset a ON ad.aid = a.aid 
    WHERE ad.did = ? 
    ORDER BY ad.year DESC, ad.month DESC, ad.date DESC
");
$deptRequestsStmt->bind_param("i", $departmentId);
$deptRequestsStmt->execute();
$deptRequestsResult = $deptRequestsStmt->get_result();
$deptRequestsStmt->close();

// Handle form submission
if (isset($_POST['insert']) && $departmentId !== null) {
    try {
        $assetIds = array_map('intval', $_POST['assetNames']);
        $quantities = array_map('intval', $_POST['nqty']);
        $days = array_map('intval', $_POST['day']);
        $months = array_map('intval', $_POST['month']);
        $years = array_map('intval', $_POST['year']);

        // Check for duplicate asset IDs
        if (count($assetIds) !== count(array_unique($assetIds))) {
            throw new Exception("Duplicate asset names detected! Please select unique asset names.");
        }

        // Process each asset
        foreach ($assetIds as $index => $assetId) {
            $quantity = $quantities[$index];
            $day = $days[$index];
            $month = $months[$index];
            $year = $years[$index];

            // Validate date
            if ($day < 1 || $day > 31 || $month < 1 || $month > 12 || $year < 2000) {
                throw new Exception("Invalid date values for asset!");
            }

            // Fetch asset details
            $assetStmt = $conn->prepare("SELECT aname, atype, aqty FROM `asset` WHERE aid = ?");
            $assetStmt->bind_param("i", $assetId);
            if (!$assetStmt->execute()) {
                throw new Exception("Asset query failed: " . $conn->error);
            }
            $assetResult = $assetStmt->get_result();
            if ($assetResult->num_rows === 0) {
                throw new Exception("Invalid asset selected!");
            }

            $assetRow = $assetResult->fetch_assoc();
            $assetName = $assetRow['aname'];
            $assetType = $assetRow['atype'];
            $totalQuantity = $assetRow['aqty'];
            $assetStmt->close();

            // Check quantities against stock
            if ($quantity < 1) {
                throw new Exception("Requested quantity must be at least 1!");
            }

            if ($totalQuantity == 0) {
                // No stock available, redirect for full quantity
                $conn->commit();
                $display = '<div class="alert alert-info">No stock available for ' . htmlspecialchars($assetName) . '. Redirecting for special request of ' . $quantity . ' unit(s).</div>';
                $display .= '<script>setTimeout(function() { window.location.href = "spreq.php?asset_name=' . urlencode($assetName) . '&asset_type=' . urlencode($assetType) . '&quantity=' . $quantity . '&locationtype=corridor"; }, 2000);</script>';
                echo $display;
                $conn->close();
                exit();
            } elseif ($quantity > $totalQuantity && $totalQuantity > 0) {
                // Place request for available quantity and redirect for shortfall
                $status = "New";
                $insertStmt = $conn->prepare(
                    "INSERT INTO `add_dept` (`did`, `aid`, `nqty`, `date`, `month`, `year`, `status`) 
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $insertStmt->bind_param("iiiiiss", $departmentId, $assetId, $totalQuantity, $day, $month, $year, $status);
                if (!$insertStmt->execute()) {
                    throw new Exception("Failed to add available assets: " . $conn->error);
                }
                $insertStmt->close();

                // Update asset stock
                $updateStmt = $conn->prepare("UPDATE `asset` SET aqty = 0 WHERE aid = ?");
                $updateStmt->bind_param("i", $assetId);
                if (!$updateStmt->execute()) {
                    throw new Exception("Failed to update stock: " . $conn->error);
                }
                $updateStmt->close();

                // Redirect for shortfall
                $shortfall = $quantity - $totalQuantity;
                $conn->commit();
                $display = '<div class="alert alert-success">Request for ' . $totalQuantity . ' ' . htmlspecialchars($assetName) . ' added successfully. Redirecting for special request of ' . $shortfall . ' unit(s).</div>';
                $display .= '<script>setTimeout(function() { window.location.href = "spreq.php?asset_name=' . urlencode($assetName) . '&asset_type=' . urlencode($assetType) . '&quantity=' . $shortfall . '&locationtype=corridor"; }, 2000);</script>';
                echo $display;
                $conn->close();
                exit();
            } else {
                // Process full quantity if available
                $status = "New";
                $insertStmt = $conn->prepare(
                    "INSERT INTO `add_dept` (`did`, `aid`, `nqty`, `date`, `month`, `year`, `status`) 
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $insertStmt->bind_param("iiiiiss", $departmentId, $assetId, $quantity, $day, $month, $year, $status);
                if (!$insertStmt->execute()) {
                    throw new Exception("Failed to add asset: " . $conn->error);
                }
                $insertStmt->close();

                // Update asset stock
                $updateStmt = $conn->prepare("UPDATE `asset` SET aqty = aqty - ? WHERE aid = ?");
                $updateStmt->bind_param("ii", $quantity, $assetId);
                if (!$updateStmt->execute()) {
                    throw new Exception("Failed to update stock: " . $conn->error);
                }
                $updateStmt->close();
            }
        }

        $conn->commit();
        $display = '<div class="alert alert-success">Assets added to Department Successfully and Stock Updated!</div>';
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Form submission error: " . $e->getMessage());
        $display = '<div class="alert alert-danger">' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// Close asset statement
if (isset($assetNamesStmt)) {
    $assetNamesStmt->close();
}
$conn->close();
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
.hidden {
    display: none;
}
</style>

<!-- begin app-main -->
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
                        <div class="card-heading">
                            <h4 class="card-title">Request Assets for Department</h4>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php echo $display; ?>
                        <form action="reqdept.php" name="myform" id="myform" method="post" onsubmit="return validateForm()">
                            <!-- Asset Entries Container -->
                            <div id="assetFormContainer">
                                <div class="assetForm">
                                    <button type="button" class="deleteEntry" onclick="deleteEntry(this)" style="float: right; background-color: transparent; border: none;">x</button>
                                    <div class="form-group">
                                        <label for="assetName">Asset Name</label>
                                        <select class="form-control assetNames" name="assetNames[]" required>
                                            <option value="" disabled selected>Select Asset Name</option>
                                            <?php 
                                            $assetNamesResult->data_seek(0);
                                            while ($row = $assetNamesResult->fetch_assoc()): 
                                            ?>
                                                <option value="<?php echo $row['aid']; ?>" data-aqty="<?php echo $row['aqty']; ?>" data-atype="<?php echo htmlspecialchars($row['atype']); ?>">
                                                    <?php echo $row['aname']; ?> (Available: <?php echo $row['aqty']; ?>)
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="quantity">Total Quantity in Stock</label>
                                        <input type="number" class="form-control totalQuantity" name="quantities[]" readonly value="">
                                    </div>
                                    <div class="form-group">
                                        <label for="quantity">Quantity Needed</label>
                                        <input type="number" class="form-control nqty" name="nqty[]" required>
                                        <input type="number" class="form-control lqty hidden" name="lqty[]" readonly>
                                    </div>
                                    <div class="form-group hidden">
                                        <label>Date</label>
                                        <div class="row">
                                            <div class="col-4">
                                                <input type="number" class="form-control" name="day[]" placeholder="Day" min="1" max="31" value="<?php echo $currentDay; ?>" required>
                                            </div>
                                            <div class="col-4">
                                                <input type="number" class="form-control" name="month[]" placeholder="Month" min="1" max="12" value="<?php echo $currentMonth; ?>" required>
                                            </div>
                                            <div class="col-4">
                                                <input type="number" class="form-control" name="year[]" placeholder="Year" min="2000" max="9999" value="<?php echo $currentYear; ?>" required>
                                            </div>
                                        </div>
                                    </div>
                                    <hr>
                                </div>
                            </div>

                            <!-- Buttons -->
                            <button type="button" class="btn btn-secondary" id="addMore">Add More Assets</button>
                            <button type="submit" class="btn btn-primary" name="insert" id="insert">Submit</button>
                        </form>
                    </div>
                </div>
                <div class="card card-statistics mt-4">
                    <div class="card-header">
                        <div class="card-heading">
                            <h4 class="card-title">Department Asset Requests</h4>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th bgcolor="#F0F3F4">Request ID</th>
                                        <th bgcolor="#F0F3F4">Asset Name</th>
                                        <th bgcolor="#F0F3F4">Quantity</th>
                                        <th bgcolor="#F0F3F4">Date</th>
                                        <th bgcolor="#F0F3F4">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($deptRequestsResult->num_rows === 0): ?>
                                        <tr><td colspan="5" class="text-center">No requests found for your department.</td></tr>
                                    <?php else: ?>
                                        <?php while ($request = $deptRequestsResult->fetch_assoc()): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($request['id']); ?></td>
                                                <td><?php echo htmlspecialchars($request['aname']); ?></td>
                                                <td><?php echo number_format($request['nqty']); ?></td>
                                                <td><?php echo htmlspecialchars(sprintf("%02d/%02d/%04d", $request['date'], $request['month'], $request['year'])); ?></td>
                                                <td><?php echo htmlspecialchars($request['status']); ?></td>
                                            </tr>
                                        <?php endwhile; ?>
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

document.addEventListener('DOMContentLoaded', function() {
    // Initialize totalQuantity for the first form
    var firstForm = document.querySelector('.assetForm');
    if (firstForm) {
        var assetId = firstForm.querySelector('.assetNames').value;
        fetchAssetDetails(assetId, firstForm);
    }
});

document.addEventListener('change', function(event) {
    if (event.target.classList.contains('assetNames')) {
        var assetId = event.target.value;
        var form = event.target.closest('.assetForm');
        
        // Remove previous selection from selectedAssets if exists
        var prevAssetId = selectedAssets.find(id => id !== assetId && form.querySelector('.assetNames').value === id);
        if (prevAssetId) {
            selectedAssets = selectedAssets.filter(id => id !== prevAssetId);
        }
        
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
    newAssetForm.querySelector('.lqty').value = '';
    newAssetForm.querySelector('input[name="day[]"]').value = '<?php echo $currentDay; ?>';
    newAssetForm.querySelector('input[name="month[]"]').value = '<?php echo $currentMonth; ?>';
    newAssetForm.querySelector('input[name="year[]"]').value = '<?php echo $currentYear; ?>';
    
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
        var day = parseInt(form.querySelector('input[name="day[]"]').value);
        var month = parseInt(form.querySelector('input[name="month[]"]').value);
        var year = parseInt(form.querySelector('input[name="year[]"]').value);
        
        if (!assetId) {
            alert('Please select an asset for all entries.');
            return false;
        }
        if (!nqty || isNaN(nqty) || parseInt(nqty) < 1) {
            alert('Please enter a valid quantity (minimum 1) for all assets.');
            return false;
        }
        if (isNaN(day) || day < 1 || day > 31) {
            alert('Please enter a valid day (1-31) for all assets.');
            return false;
        }
        if (isNaN(month) || month < 1 || month > 12) {
            alert('Please enter a valid month (1-12) for all assets.');
            return false;
        }
        if (isNaN(year) || year < 2000) {
            alert('Please enter a valid year (2000 or later) for all assets.');
            return false;
        }
    }
    return true;
}
</script>

<!-- footer -->
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