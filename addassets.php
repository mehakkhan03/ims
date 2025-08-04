<?php
date_default_timezone_set('Asia/Kolkata');
include('header.php');

$mm = new PDO('mysql:host=localhost;dbname=inventory', 'root', '');
$mm->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$today = (new DateTime())->format('Y-m-d');

$result = "";
if (isset($_POST["submit"])) {
    try {
        $mm->beginTransaction();

        for ($count = 0; $count < $_POST["total_row"]; $count++) {
            $aname = trim($_POST["aname"][$count]);
            $atype = trim($_POST["atype"][$count]);
            $aqty = trim($_POST["aqty"][$count]);
            $dop = trim($_POST["dop"][$count]);

            // Server-side validation
            $dopDate = new DateTime($dop, new DateTimeZone('Asia/Kolkata'));
            $todayDate = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
            if ($dopDate > $todayDate) {
                throw new Exception("Date of Purchase cannot be in the future for asset '$aname'.");
            }

            if (!is_numeric($aqty) || $aqty <= 0 || floor($aqty) != $aqty) {
                throw new Exception("Asset Quantity must be a positive integer for asset '$aname'.");
            }

            if (empty($aname)) {
                throw new Exception("Asset Name cannot be empty.");
            }

            if (empty($atype)) {
                throw new Exception("Asset Type must be selected for asset '$aname'.");
            }

            $dopFormatted = $dopDate->format('Y-m-d');

            // Fetch all non-Approved special requests in first-come, first-served order
            $specialRequestStmt = $mm->prepare("
                SELECT sr_id, quantity
                FROM special_request
                WHERE asset_name = :aname AND status != 'Approved'
                ORDER BY sr_id ASC
            ");
            $specialRequestStmt->execute([':aname' => $aname]);
            $specialRequests = $specialRequestStmt->fetchAll(PDO::FETCH_ASSOC);

            $remainingQty = $aqty;

            // Process special requests in order
            foreach ($specialRequests as $request) {
                $requestQty = $request['quantity'];
                $requestId = $request['sr_id'];

                if ($remainingQty >= $requestQty) {
                    // Approve the request
                    $updateSpecialRequestStmt = $mm->prepare("
                        UPDATE special_request
                        SET status = 'Approved'
                        WHERE sr_id = :sr_id
                    ");
                    $updateSpecialRequestStmt->execute([':sr_id' => $requestId]);
                    $remainingQty -= $requestQty;
                }
            }

            // Check if asset exists
            $checkStmt = $mm->prepare("
                SELECT `aid`, `aqty` FROM `asset` 
                WHERE `aname` = :aname");
            $checkStmt->execute([':aname' => $aname]);
            $existingAsset = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingAsset) {
                // Update existing asset
                $newQty = $existingAsset['aqty'] + $remainingQty;
                if ($newQty < 0) {
                    throw new Exception("Resulting asset quantity for '$aname' would be negative ($newQty).");
                }
                $updateStmt = $mm->prepare("
                    UPDATE `asset` 
                    SET `aqty` = :aqty,
                        `atype` = :atype 
                    WHERE `aid` = :aid");
                $updateStmt->execute([
                    ':aqty' => $newQty,
                    ':atype' => $atype,
                    ':aid' => $existingAsset['aid']
                ]);
                $aid = $existingAsset['aid'];
            } else {
                // Insert new asset
                if ($remainingQty < 0) {
                    throw new Exception("Resulting asset quantity for '$aname' would be negative ($remainingQty).");
                }
                $insertStmt = $mm->prepare("
                    INSERT INTO `asset` (`aname`, `atype`, `aqty`) 
                    VALUES (:aname, :atype, :aqty)");
                $insertStmt->execute([
                    ':aname' => $aname,
                    ':atype' => $atype,
                    ':aqty' => $remainingQty
                ]);
                $aid = $mm->lastInsertId();
            }

            // Insert into assets_entry with original aqty
            $statement = $mm->prepare("
                INSERT INTO `assets_entry` (`aid`, `aname`, `atype`, `aqty`, `dop`) 
                VALUES (:aid, :aname, :atype, :aqty, :dop)");
            $statement->execute([
                ':aid' => $aid,
                ':aname' => $aname,
                ':atype' => $atype,
                ':aqty' => $aqty,
                ':dop' => $dopFormatted
            ]);
        }

        $mm->commit();
        $result = '<div class="alert alert-success">Assets Added Successfully</div>';

    } catch (Exception $e) {
        $mm->rollBack();
        $result = '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// Fetch asset table data
$assetStmt = $mm->prepare("SELECT aid, aname, atype, aqty FROM asset ORDER BY aname");
$assetStmt->execute();
$assets = $assetStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .alert { margin: 10px 0; padding: 10px; border-radius: 4px; }
        .alert-success { background-color: #d4edda; color: #155724; }
        .alert-danger { background-color: #f8d7da; color: #721c24; }
        .table-responsive { overflow-x: auto; }
    </style>
</head>
<body>
<div class="app-main" id="main">
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <div class="card card-statistics">
                    <div class="card-header">
                        <div class="card-heading">
                            <h4 class="card-title">Add College Assets Here</h4>
                        </div>
                        <strong><?php echo $result; ?></strong>
                    </div>
                    <div class="card-body">
                        <form action="" name="myform" id="myform" method="post">
                            <table id="outward-table" class="table table-bordered table-responsive">
                                <thead>
                                    <tr>
                                        <th bgcolor="#F0F3F4">S.No.</th>
                                        <th bgcolor="#F0F3F4">Asset Name</th>
                                        <th bgcolor="#F0F3F4">Asset Type</th>
                                        <th bgcolor="#F0F3F4">Asset Quantity</th>
                                        <th bgcolor="#F0F3F4">Date of Purchase</th>
                                        <th bgcolor="#F0F3F4">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>1</td>
                                        <td><input class="form-control input-sm aname" name="aname[]" type="text" required /></td>
                                        <td><select name="atype[]" class="form-control input-sm atype" style="width:150px;" required>
                                                <option value="">Select Any</option>
                                                <option value="Electronics">Electronics</option>
                                                <option value="Furniture">Furniture</option>
                                                <option value="Others">Others</option>
                                            </select></td>
                                        <td><input class="form-control input-sm aqty" name="aqty[]" type="number" min="1" step="1" required /></td>
                                        <td><input class="form-control input-sm dop" name="dop[]" type="date" max="<?php echo $today; ?>" required /></td>
                                        <td><button type="button" name="remove_row" id="1" class="btn btn-danger remove_row">X</button></td>
                                    </tr>
                                </tbody>
                            </table>
                            <br>
                            <div align="right">
                                <button type="button" name="add_row" id="add_row" class="btn btn-success">Add Row</button>
                            </div>
                            <table align="right">
                                <tr>
                                    <td colspan="2" align="center">
                                        <input type="hidden" name="total_row" id="total_row" value="1" />
                                        <input type="submit" name="submit" id="submit" class="btn btn-info" value="Submit" />
                                    </td>
                                </tr>
                            </table>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-12">
                <div class="card card-statistics mt-4">
                    <div class="card-header">
                        <div class="card-heading">
                            <h4 class="card-title">Current Assets</h4>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th bgcolor="#F0F3F4">Asset Name</th>
                                        <th bgcolor="#F0F3F4">Asset Type</th>
                                        <th bgcolor="#F0F3F4">Quantity</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($assets)): ?>
                                        <tr><td colspan="4" class="text-center">No assets found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($assets as $asset): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($asset['aname']); ?></td>
                                                <td><?php echo htmlspecialchars($asset['atype']); ?></td>
                                                <td><?php echo number_format($asset['aqty']); ?></td>
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

<script>
$(document).ready(function() {
    $('.dop').attr('max', '<?php echo $today; ?>');

    var count = 1;
    $(document).on('click', '#add_row', function() {
        count++;
        $('#total_row').val(count);
        var html_code = '';
        html_code += '<tr id="row_id_' + count + '">';
        html_code += '<td>' + count + '</td>';
        html_code += '<td><input type="text" name="aname[]" class="form-control input-sm aname" required /></td>';
        html_code += '<td><select name="atype[]" class="form-control input-sm atype" style="width:150px;" required>';
        html_code += '<option value="">Select Any</option><option value="Electronics">Electronics</option><option value="Furniture">Furniture</option></select></td>';
        html_code += '<td><input type="number" name="aqty[]" class="form-control input-sm aqty" min="1" step="1" required /></td>';
        html_code += '<td><input type="date" name="dop[]" class="form-control input-sm dop" max="<?php echo $today; ?>" required /></td>';
        html_code += '<td><button type="button" name="remove_row" id="' + count + '" class="btn btn-danger remove_row">X</button></td>';
        html_code += '</tr>';
        $('#outward-table tbody').append(html_code);
    });

    $(document).on('click', '.remove_row', function() {
        var row_id = $(this).attr("id");
        $('#row_id_' + row_id).remove();
        count--;
        $('#total_row').val(count);
    });

    $('#myform').on('submit', function(e) {
        var valid = true;
        $('.aname').each(function() {
            if ($(this).val().trim() === '') {
                valid = false;
                alert('Asset Name cannot be empty.');
                $(this).focus();
                return false;
            }
        });
        $('.atype').each(function() {
            if ($(this).val().trim() === '') {
                valid = false;
                alert('Asset Type must be selected.');
                $(this).focus();
                return false;
            }
        });
        $('.aqty').each(function() {
            var qty = $(this).val().trim();
            if (!qty || isNaN(qty) || qty <= 0 || Math.floor(qty) != qty) {
                valid = false;
                alert('Asset Quantity must be a positive integer.');
                $(this).focus();
                return false;
            }
        });
        $('.dop').each(function() {
            var selectedDate = new Date($(this).val());
            var serverDate = new Date('<?php echo $today; ?>');
            if (selectedDate > serverDate) {
                valid = false;
                alert('Date of Purchase cannot be in the future. Please select today or a past date.');
                $(this).focus();
                return false;
            }
            if (!$(this).val()) {
                valid = false;
                alert('Date of Purchase is required.');
                $(this).focus();
                return false;
            }
        });
        if (!valid) {
            e.preventDefault();
        }
    });
});
</script>
</body>
</html>