<?php
$display = "";

// Get current date components
$currentDate = date('Y-m-d');
$currentDay = date('d');
$currentMonth = date('m');
$currentYear = date('Y');

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "inventory";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch asset names from the asset table
$assetNamesQuery = "SELECT aid, aname, atype, aqty FROM `asset`";
$assetNamesResult = $conn->query($assetNamesQuery);

// Fetch public area names from the public table
$publicQuery = "SELECT pa_id, pa_name FROM `public`";
$publicResult = $conn->query($publicQuery);

// Handle form submission (if POST request)
if (isset($_POST['insert'])) {
    $publicAreaId = $_POST['publicAreaName'];
    $assetIds = $_POST['assetNames'];
    $quantities = $_POST['nqty'];
    $days = $_POST['day'];
    $months = $_POST['month'];
    $years = $_POST['year'];

    // Check if public area exists
    $publicCheckQuery = "SELECT pa_id FROM `public` WHERE pa_id = " . intval($publicAreaId);
    $publicCheckResult = $conn->query($publicCheckQuery);
    if ($publicCheckResult->num_rows == 0) {
        $display = '<div class="alert alert-danger">Invalid public area selected!</div>';
        $publicAreaId = null;
    }

    if ($publicAreaId) {
        // Check for duplicate asset IDs
        if (count($assetIds) !== count(array_unique($assetIds))) {
            $display = '<div class="alert alert-danger">Duplicate asset names detected! Please select unique asset names.</div>';
        } else {
            // Process each asset entry
            foreach ($assetIds as $index => $assetId) {
                $quantity = $quantities[$index];
                $day = $days[$index];
                $month = $months[$index];
                $year = $years[$index];

                // Validate date
                if ($day < 1 || $day > 31 || $month < 1 || $month > 12 || $year < 2000) {
                    $display = '<div class="alert alert-danger">Invalid date values for asset!</div>';
                    continue;
                }

                // Fetch asset details from asset table
                $assetQuery = "SELECT aname, atype, aqty FROM `asset` WHERE aid = " . intval($assetId);
                $assetResult = $conn->query($assetQuery);
                if ($assetResult->num_rows > 0) {
                    $assetRow = $assetResult->fetch_assoc();
                    $assetName = $assetRow['aname'];
                    $totalQuantity = $assetRow['aqty'];

                    // Check quantity availability
                    if ($quantity > $totalQuantity) {
                        $display = '<div class="alert alert-danger">Requested quantity for ' . $assetName . ' exceeds available stock!</div>';
                        continue;
                    } elseif ($quantity < 1) {
                        $display = '<div class="alert alert-danger">Requested quantity for ' . $assetName . ' must be at least 1!</div>';
                        continue;
                    }

                    // Insert into add_public table with status 'Pending' and date
                    $insertQuery = "INSERT INTO `add_public` (`pa_id`, `aid`, `aqty`, `status`, `date`, `month`, `year`) VALUES (
                        " . intval($publicAreaId) . ",
                        " . intval($assetId) . ",
                        " . intval($quantity) . ",
                        'Approved',
                        " . intval($day) . ",
                        " . intval($month) . ",
                        " . intval($year) . "
                    )";

                    if ($conn->query($insertQuery)) {
                        // Update the asset table
                        $updateQuery = "UPDATE `asset` SET aqty = aqty - " . intval($quantity) . " WHERE aid = " . intval($assetId);
                        if ($conn->query($updateQuery)) {
                            $display = '<div class="alert alert-success">Asset request submitted successfully!</div>';
                        } else {
                            $display = '<div class="alert alert-danger">Failed to update stock: ' . $conn->error . '</div>';
                        }
                    } else {
                        $display = '<div class="alert alert-danger">Something went wrong: ' . $conn->error . '</div>';
                    }
                } else {
                    $display = '<div class="alert alert-danger">Invalid asset selected!</div>';
                }
            }
        }
    }
}
?>

<?php include('theader.php'); ?>

<div class="app-main" id="main">
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <div class="card card-statistics">
                    <div class="card-header">
                        <div class="card-heading">
                            <h4 class="card-title">Request Assets for Public Area</h4>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php echo $display; ?>
                        <form action="" name="myform" id="myform" method="post" onsubmit="return validateForm()">
                            <!-- Public Area Selection -->
                            <div class="form-group">
                                <label for="publicAreaName">Public Area Name</label>
                                <select name="publicAreaName" class="form-control publicAreaName" required>
                                    <option value="" disabled selected>Select a Public Area</option>
                                    <?php while ($row = $publicResult->fetch_assoc()): ?>
                                        <option value="<?php echo $row['pa_id']; ?>">
                                            <?php echo $row['pa_name']; ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
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
                                                <option value="<?php echo $row['aid']; ?>" data-aqty="<?php echo $row['aqty']; ?>">
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
                                        <label for="quantity">Quantity Requested</label>
                                        <input type="number" class="form-control nqty" name="nqty[]" required>
                                        <input type="number" class="form-control lqty" name="lqty[]" readonly>
                                    </div>
                                    <div class="form-group">
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
                            <button type="submit" class="btn btn-primary" name="insert" id="insert">Submit Request</button>
                        </form>
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
        calculateRemainingQuantity(form);
    } else {
        form.querySelector('.totalQuantity').value = '';
        form.querySelector('.lqty').value = '';
    }
}

function calculateRemainingQuantity(form) {
    var nqty = parseInt(form.querySelector('.nqty').value) || 0;
    var totalQty = parseInt(form.querySelector('.totalQuantity').value) || 0;
    form.querySelector('.lqty').value = totalQty - nqty;
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

document.addEventListener('input', function(event) {
    if (event.target.classList.contains('nqty')) {
        var form = event.target.closest('.assetForm');
        calculateRemainingQuantity(form);
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
    var publicAreaName = document.querySelector('.publicAreaName').value;
    if (!publicAreaName) {
        alert('Please select a public area.');
        return false;
    }

    var forms = document.querySelectorAll('.assetForm');
    for (var i = 0; i < forms.length; i++) {
        var form = forms[i];
        var assetId = form.querySelector('.assetNames').value;
        var nqty = form.querySelector('.nqty').value;
        var totalQty = form.querySelector('.totalQuantity').value;
        var day = parseInt(form.querySelector('input[name="day[]"]').value);
        var month = parseInt(form.querySelector('input[name="month[]"]').value);
        var year = parseInt(form.querySelector('input[name="year[]"]').value);
        
        if (!assetId || !nqty) {
            alert('Please fill all fields in all entries.');
            return false;
        }
        if (parseInt(nqty) > parseInt(totalQty)) {
            alert('Requested quantity cannot exceed available stock.');
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