<?php
require_once "db.php";

// Get JSON data from request body
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if ($data) {
    // Sanitize and validate input
    $id = intval($data['id']);
    $name = mysqli_real_escape_string($d_db, $data['name']);
    $weight = floatval($data['weight']);
    
    // Validate weight range
    if ($weight < 0 || $weight > 1) {
        echo json_encode(['success' => false, 'message' => 'Weight must be between 0 and 1']);
        exit;
    }

    // Build and execute update query
    $sql = "UPDATE scoreWeights SET 
            weight = '$weight', 
            name = '$name' 
            WHERE id = $id";
            
    if (mysqli_query($d_db, $sql)) {
        echo json_encode([
            'success' => true,
            'message' => 'Weight updated successfully'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . mysqli_error($d_db)
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid input data'
    ]);
}
?>