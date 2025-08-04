<?php
session_start();

header('Content-Type: application/json');

if (isset($_POST['notification_id'])) {
    $notificationId = $_POST['notification_id'];
    
    // Initialize dismissed_notifications array if not set
    if (!isset($_SESSION['dismissed_notifications'])) {
        $_SESSION['dismissed_notifications'] = [];
    }
    
    // Add the notification ID to the dismissed list if not already present
    if (!in_array($notificationId, $_SESSION['dismissed_notifications'])) {
        $_SESSION['dismissed_notifications'][] = $notificationId;
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => true]); // Already dismissed, no action needed
    }
} else {
    echo json_encode(['success' => false, 'error' => 'No notification ID provided']);
}
?>