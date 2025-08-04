<?php
// get_asset_details.php
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

$assetName = $_GET['aname'];

// Fetch asset details
$query = "SELECT atype, aqty FROM assets_entry WHERE aname = '$assetName'";
$result = $conn->query($query);

$assetTypes = [];
$aqty = 0;

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $assetTypes[] = ['atype' => $row['atype']];
        $aqty = $row['aqty']; // Assuming aqty is the same for all rows
    }
}

echo json_encode(['assetTypes' => $assetTypes, 'aqty' => $aqty]);

$conn->close();
?>