<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Debug session data
error_log("Session data at start: " . print_r($_SESSION, true));

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    error_log("Redirecting to index.php: username not set");
    header("Location: index.php");
    exit();
}

// Initialize variables
$display = "";
$currentDate = date('Y-m-d');
$currentDay = date('d'); // 28
$currentMonth = date('m'); // 06
$currentYear = date('Y'); // 2025

// Database connection
require_once 'config.php'; // Assuming config.php contains DB credentials
try {
    $conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    $conn->autocommit(FALSE); // Start transaction
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    die("Database connection error. Please try again later.");
}

// Fetch welcome message with department name
$welcomeMessage = "IMS";
$loggedInUsername = $_SESSION['username'];
try {
    $welcomeQuery = $conn->prepare(
        "SELECT d.dname 
         FROM `stlog` s 
         JOIN `teacher` t ON t.temail = s.uemail 
         JOIN `dept` d ON d.did = t.did 
         WHERE s.username = ?"
    );
    $welcomeQuery->bind_param("s", $loggedInUsername);
    $welcomeQuery->execute();
    $welcomeResult = $welcomeQuery->get_result();
    if ($welcomeResult->num_rows > 0) {
        $welcomeRow = $welcomeResult->fetch_assoc();
        $welcomeMessage = "IMS " . htmlspecialchars($welcomeRow['dname']);
    }
    $welcomeQuery->close();
} catch (Exception $e) {
    error_log("Welcome query error: " . $e->getMessage());
}

// Fetch teacher's did
$teacherDid = null;
try {
    $didQuery = $conn->prepare(
        "SELECT t.did 
         FROM `stlog` s 
         JOIN `teacher` t ON t.temail = s.uemail 
         WHERE s.username = ?"
    );
    $didQuery->bind_param("s", $loggedInUsername);
    $didQuery->execute();
    $didResult = $didQuery->get_result();
    if ($didResult->num_rows > 0) {
        $didRow = $didResult->fetch_assoc();
        $teacherDid = $didRow['did'];
    } else {
        $display = '<div class="alert alert-danger">Error: User is not associated with a department!</div>';
    }
    $didQuery->close();
} catch (Exception $e) {
    error_log("DID query error: " . $e->getMessage());
    $display = '<div class="alert alert-danger">Error fetching department information.</div>';
}

// Fetch asset names
$assetNamesQuery = $conn->prepare("SELECT aid, aname, atype, aqty FROM `asset`");
if (!$assetNamesQuery || !$assetNamesQuery->execute()) {
    error_log("Asset query failed: " . $conn->error);
    $display = '<div class="alert alert-danger">Failed to load assets. Please try again.</div>';
} else {
    $assetNamesResult = $assetNamesQuery->get_result();
}

// Fetch classroom requests for the user's did
$classroomRequestsStmt = $conn->prepare("
    SELECT ac.id, ac.cl_id, ac.aid, ac.aqty, ac.date, ac.month, ac.year, ac.status, a.aname 
    FROM classroom ac 
    JOIN asset a ON ac.aid = a.aid 
    WHERE ac.did = ? 
    ORDER BY ac.year DESC, ac.month DESC, ac.date DESC
");
$classroomRequestsStmt->bind_param("i", $teacherDid);
$classroomRequestsStmt->execute();
$classroomRequestsResult = $classroomRequestsStmt->get_result();
$classroomRequestsStmt->close();

// Handle form submission
if (isset($_POST['insert']) && $teacherDid !== null) {
    try {
        $classroomNo = trim($_POST['classroomNo']);
        if (!preg_match('/^[A-Z0-9\-]+$/i', $classroomNo)) {
            throw new Exception("Invalid classroom number format");
        }

        $assetIds = array_map('intval', $_POST['assetNames']);
        $quantities = array_map('intval', $_POST['nqty']);

        // Check for duplicate asset IDs
        if (count($assetIds) !== count(array_unique($assetIds))) {
            throw new Exception("Duplicate asset names detected! Please select unique asset names.");
        }

        // Process each asset
        foreach ($assetIds as $index => $assetId) {
            $quantity = $quantities[$index];

            // Fetch asset details
            $assetQuery = $conn->prepare("SELECT aname, atype, aqty FROM `asset` WHERE aid = ?");
            $assetQuery->bind_param("i", $assetId);
            if (!$assetQuery->execute()) {
                throw new Exception("Asset query failed: " . $conn->error);
            }
            $assetResult = $assetQuery->get_result();
            if ($assetResult->num_rows === 0) {
                throw new Exception("Invalid asset selected!");
            }

            $assetRow = $assetResult->fetch_assoc();
            $assetName = $assetRow['aname'];
            $assetType = $assetRow['atype'];
            $totalQuantity = $assetRow['aqty'];
            $assetQuery->close();

            error_log("Processing asset: aid=$assetId, aname=$assetName, aqty=$totalQuantity, requested=$quantity");

            if ($quantity < 1) {
                throw new Exception("Requested quantity must be at least 1!");
            }

            // Check if classroom No exists, insert only if not present
            $classroomExists = false;
            $classroomCheck = $conn->prepare("SELECT id FROM `classroom` WHERE cl_id = ? AND did = ?");
            $classroomCheck->bind_param("si", $classroomNo, $teacherDid);
            $classroomCheck->execute();
            $classroomCheckResult = $classroomCheck->get_result();
            if ($classroomCheckResult->num_rows > 0) {
                $classroomExists = true;
            }
            $classroomCheck->close();

            if (!$classroomExists) {
                $insertClassroomQuery = $conn->prepare("INSERT INTO `classroom` (cl_id, did) VALUES (?, ?)");
                $insertClassroomQuery->bind_param("si", $classroomNo, $teacherDid);
                if (!$insertClassroomQuery->execute()) {
                    throw new Exception("Failed to create classroom: " . $conn->error);
                }
                $insertClassroomQuery->close();
            }

            if ($totalQuantity == 0) {
                // No stock available, redirect for full quantity without insertion
                $conn->commit();
                $display = '<div class="alert alert-info">No stock available for ' . htmlspecialchars($assetName) . '. Redirecting for special request of ' . $quantity . ' unit(s).</div>';
                $display .= '<script>setTimeout(function() { window.location.href = "spreq.php?asset_name=' . urlencode($assetName) . '&asset_type=' . urlencode($assetType) . '&quantity=' . $quantity . '&locationtype=classroom&roomnum=' . urlencode($classroomNo) . '&day=' . $currentDay . '&month=' . $currentMonth . '&year=' . $currentYear . '"; }, 2000);</script>';
                echo $display;
                $conn->close();
                exit();
            } elseif ($quantity > $totalQuantity) {
                // Place request for available quantity and redirect for shortfall
                $insertQuery = $conn->prepare(
                    "INSERT INTO `classroom` (cl_id, did, aid, aqty, date, month, year, status) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'New')"
                );
                $insertQuery->bind_param("siiiiii", $classroomNo, $teacherDid, $assetId, $totalQuantity, $currentDay, $currentMonth, $currentYear);
                if (!$insertQuery->execute()) {
                    throw new Exception("Failed to add available assets: " . $conn->error);
                }
                $insertQuery->close();

                // Update asset stock
                $updateQuery = $conn->prepare("UPDATE `asset` SET aqty = 0 WHERE aid = ?");
                $updateQuery->bind_param("i", $assetId);
                if (!$updateQuery->execute()) {
                    throw new Exception("Failed to update stock: " . $conn->error);
                }
                $updateQuery->close();

                // Redirect for shortfall
                $shortfall = $quantity - $totalQuantity;
                $conn->commit();
                $display = '<div class="alert alert-success">Request for ' . $totalQuantity . ' ' . htmlspecialchars($assetName) . ' added successfully for classroom ' . htmlspecialchars($classroomNo) . '. Redirecting for special request of ' . $shortfall . ' unit(s).</div>';
                $display .= '<script>setTimeout(function() { window.location.href = "spreq.php?asset_name=' . urlencode($assetName) . '&asset_type=' . urlencode($assetType) . '&quantity=' . $shortfall . '&locationtype=classroom&roomnum=' . urlencode($classroomNo) . '&day=' . $currentDay . '&month=' . $currentMonth . '&year=' . $currentYear . '"; }, 2000);</script>';
                echo $display;
                $conn->close();
                exit();
            } else {
                // Insert full quantity request
                $insertQuery = $conn->prepare(
                    "INSERT INTO `classroom` (cl_id, did, aid, aqty, date, month, year, status) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'New')"
                );
                $insertQuery->bind_param("siiiiii", $classroomNo, $teacherDid, $assetId, $quantity, $currentDay, $currentMonth, $currentYear);
                if (!$insertQuery->execute()) {
                    throw new Exception("Failed to add asset: " . $conn->error);
                }
                $insertQuery->close();

                // Update asset stock
                $updateQuery = $conn->prepare("UPDATE `asset` SET aqty = aqty - ? WHERE aid = ?");
                $updateQuery->bind_param("ii", $quantity, $assetId);
                if (!$updateQuery->execute()) {
                    throw new Exception("Failed to update stock: " . $conn->error);
                }
                $updateQuery->close();
            }
        }

        $conn->commit();
        $display = '<div class="alert alert-success">Assets added to Classroom Successfully and Stock Updated!</div>';
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Form submission error: " . $e->getMessage());
        $display = '<div class="alert alert-danger">' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// Close asset query
if (isset($assetNamesQuery)) {
    $assetNamesQuery->close();
}
$conn->close();
?>

<?php include('theader.php'); ?>

<!-- Add custom CSS for transparent welcome message -->
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
                            <h4 class="card-title">Request Assets for Classroom</h4>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php echo $display; ?>
                        <form action="" name="myform" id="myform" method="post" onsubmit="return validateForm()">
                            <!-- Classroom Number Input -->
                            <div class="form-group">
                                <label for="classroomNo">Classroom Number</label>
                                <input type="text" name="classroomNo" class="form-control classroomNo" required placeholder="Enter Classroom Number">
                            </div>

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

                            <!-- Buttons -->
                            <button type="button" class="btn btn-secondary" id="addMore">Add More Assets</button>
                            <button type="submit" class="btn btn-primary" name="insert" id="insert">Submit</button>
                        </form>
                    </div>
                </div>
                <div class="card card-statistics mt-4">
                    <div class="card-header">
                        <div class="card-heading">
                            <h4 class="card-title">Classroom Asset Requests</h4>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th bgcolor="#F0F3F4">Request ID</th>
                                        <th bgcolor="#F0F3F4">Classroom Number</th>
                                        <th bgcolor="#F0F3F4">Asset Name</th>
                                        <th bgcolor="#F0F3F4">Quantity</th>
                                        <th bgcolor="#F0F3F4">Date</th>
                                        <th bgcolor="#F0F3F4">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($classroomRequestsResult->num_rows === 0): ?>
                                        <tr><td colspan="6" class="text-center">No requests found for your department's classrooms.</td></tr>
                                    <?php else: ?>
                                        <?php while ($request = $classroomRequestsResult->fetch_assoc()): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($request['id']); ?></td>
                                                <td><?php echo htmlspecialchars($request['cl_id']); ?></td>
                                                <td><?php echo htmlspecialchars($request['aname']); ?></td>
                                                <td><?php echo number_format($request['aqty']); ?></td>
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
    var classroomNo = document.querySelector('.classroomNo').value.trim();
    if (!classroomNo) {
        alert('Please enter a classroom number.');
        return false;
    }
    if (!/^[A-Z0-9\-]+$/i.test(classroomNo)) {
        alert('Classroom number can only contain letters, numbers, and hyphens.');
        return false;
    }

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