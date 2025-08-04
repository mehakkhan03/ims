<?php 
ob_start();
include('header.php');

$result1 = "";
$mysqli = new mysqli("localhost", "root", "", "inventory");

// Check connection
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

// Handle update
if (isset($_POST['update'])) {
    try {
        $id = mysqli_real_escape_string($mysqli, $_POST['id']);
        $aname = mysqli_real_escape_string($mysqli, $_POST['aname']);
        $atype = mysqli_real_escape_string($mysqli, $_POST['atype']);
        $new_aqty = mysqli_real_escape_string($mysqli, $_POST['aqty']);
        $dop = mysqli_real_escape_string($mysqli, $_POST['dop']);

        // Start transaction
        $mysqli->begin_transaction();

        // Update assets_entry table
        $updateQuery = "UPDATE assets_entry 
                       SET aname='$aname', 
                           atype='$atype', 
                           aqty='$new_aqty', 
                           dop='$dop' 
                       WHERE id='$id'";
        
        if (!mysqli_query($mysqli, $updateQuery)) {
            throw new Exception("Error updating asset entry: " . mysqli_error($mysqli));
        }

        // Get the current aid from assets_entry
        $current_query = "SELECT aid FROM assets_entry WHERE id='$id'";
        $current_result = mysqli_query($mysqli, $current_query);
        
        if ($current_result && mysqli_num_rows($current_result) > 0) {
            $current_row = mysqli_fetch_assoc($current_result);
            $aid = $current_row['aid'];

            // Update assets table if aid exists
            if ($aid) {
                // Calculate sum of aqty for this aid
                $sum_query = "SELECT SUM(aqty) as total_aqty FROM assets_entry WHERE aid='$aid'";
                $sum_result = mysqli_query($mysqli, $sum_query);
                
                if ($sum_result && mysqli_num_rows($sum_result) > 0) {
                    $sum_row = mysqli_fetch_assoc($sum_result);
                    $new_assets_aqty = $sum_row['total_aqty'];

                    // Update assets table
                    $updateAssetsQuery = "UPDATE asset
                                        SET aqty='$new_assets_aqty'
                                        WHERE aid='$aid'";
                    
                    if (!mysqli_query($mysqli, $updateAssetsQuery)) {
                        throw new Exception("Error updating assets table: " . mysqli_error($mysqli));
                    }
                }
            }
        }

        // If everything succeeded, commit transaction
        $mysqli->commit();
        ob_end_clean();
        header("Location: " . basename(__FILE__) . "?success=1");
        exit();

    } catch (Exception $e) {
        // Rollback transaction on error
        $mysqli->rollback();
        $result1 = '<div class="alert alert-danger">' . $e->getMessage() . '</div>';
    }
    
    // Server-side date validation
    $submittedDate = new DateTime($dop);
    $currentDate = new DateTime();
    if ($submittedDate > $currentDate) {
        $result1 = '<div class="alert alert-danger">Error: Date of Purchase cannot be in the future</div>';
        return;
    }
}

// Check success parameter after potential redirect
$show_success = (isset($_GET['success']) && $_GET['success'] == '1');

// Fetch assets_entry data
$query = "SELECT * FROM assets_entry";
$result = mysqli_query($mysqli, $query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update College Assets</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Responsive styles */
        @media (max-width: 768px) {
            /* Make table responsive */
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            /* Adjust table cells for smaller screens */
            .table th, .table td {
                font-size: 14px;
                padding: 8px;
            }

            /* Stack form inputs in table cells */
            .table td input.form-control,
            .table td select.form-control {
                width: 100%;
                min-width: 120px;
            }

            /* Adjust button size */
            .btn-primary {
                padding: 6px 12px;
                font-size: 14px;
            }

            /* Ensure card header text is readable */
            .card-header h4 {
                font-size: 1.2rem;
            }

            /* Adjust footer text */
            .footer p {
                font-size: 0.9rem;
            }

            /* Stack footer columns */
            .footer .row {
                flex-direction: column;
                text-align: center;
            }

            .footer .col-sm-6 {
                margin-bottom: 10px;
            }
        }

        @media (max-width: 576px) {
            /* Further reduce font sizes for very small screens */
            .table th, .table td {
                font-size: 12px;
                padding: 6px;
            }

            .card-header h4 {
                font-size: 1rem;
            }

            /* Make inputs and selects more compact */
            .table td input.form-control,
            .table td select.form-control {
                font-size: 12px;
                padding: 4px;
            }

            .btn-primary {
                padding: 4px 8px;
                font-size: 12px;
            }
        }

        /* Ensure table is scrollable horizontally on small screens */
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

        /* Ensure form controls don't break layout */
        .form-control {
            max-width: 100%;
        }
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
                            <h4 class="card-title">Update College Assets Here</h4>
                        </div>
                        <?php if (!empty($result1)) { ?>
                            <strong><?php echo $result1; ?></strong>
                        <?php } ?>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>S.No.</th>
                                        <th>Assets Name</th>
                                        <th>Assets Type</th>
                                        <th>Assets Quantity</th>
                                        <th>Date of Purchase</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $count = 1; while ($row = mysqli_fetch_assoc($result)) { ?>
                                        <tr>
                                            <form method="POST" action="">
                                                <td><?php echo $count++; ?></td>
                                                <td><input type="text" name="aname" value="<?php echo htmlspecialchars($row['aname']); ?>" class="form-control"></td>
                                                <td>
                                                    <select name="atype" class="form-control">
                                                        <option value="Electronics" <?php if ($row['atype'] == 'Electronics') echo 'selected'; ?>>Electronics</option>
                                                        <option value="Furniture" <?php if ($row['atype'] == 'Furniture') echo 'selected'; ?>>Furniture</option>
                                                    </select>
                                                </td>
                                                <td><input type="text" name="aqty" value="<?php echo htmlspecialchars($row['aqty']); ?>" class="form-control"></td>
                                                <td><input type="date" name="dop" value="<?php echo htmlspecialchars($row['dop']); ?>" class="form-control dop" max="<?php echo date('Y-m-d'); ?>"></td>
                                                <td>
                                                    <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                                    <button type="submit" name="update" class="btn btn-primary">Update</button>
                                                </td>
                                            </form>
                                        </tr>
                                    <?php } ?>
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
    // Form submission validation
    $('form').on('submit', function(e) {
        var valid = true;
        $('.dop').each(function() {
            var selectedDate = new Date($(this).val());
            var currentDate = New Date();
            currentDate.setHours(0, 0, 0, 0);
            if (selectedDate > currentDate) {
                valid = false;
                alert('Date of Purchase cannot be in the future. Please select today or a past date.');
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
<?php if ($show_success) { ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof bootstrap !== 'undefined' && bootstrap.Alert) {
                let alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-success alert-dismissible fade show';
                alertDiv.role = 'alert';
                alertDiv.innerHTML = 'Update Successful' +
                    '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
                document.querySelector('.card-header').appendChild(alertDiv);
                
                setTimeout(() => {
                    alertDiv.remove();
                    // Remove success parameter from URL without page reload
                    window.history.replaceState({}, document.title, window.location.pathname);
                }, 3000);
            } else {
                alert('Update Successful');
                window.history.replaceState({}, document.title, window.location.pathname);
            }
        });
    </script>
<?php } ?>
</body>
</html>
<?php ob_end_flush(); ?>