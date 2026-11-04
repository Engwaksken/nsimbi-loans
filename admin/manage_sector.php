<?php
require "nav-head.php";
// Get request method and data
$method = $_SERVER['REQUEST_METHOD'];
$json = file_get_contents('php://input');
$data = json_decode($json, true);

switch($method) {
    case 'POST':
        // Create new sector
        $name = mysqli_real_escape_string($conn, $data['name']);
        $score = floatval($data['score']);
        
        $sql = "INSERT INTO sectorRiskScores (name, score) VALUES ('$name', '$score')";
        $result = mysqli_query($conn, $sql);
        
        echo json_encode([
            'success' => $result,
            'message' => $result ? 'Sector created successfully' : mysqli_error($conn)
        ]);
        break;

    case 'PUT':
        // Update existing sector
        $id = intval($data['id']);
        $name = mysqli_real_escape_string($conn, $data['name']);
        $score = floatval($data['score']);
        
        $sql = "UPDATE sectorRiskScores SET name = '$name', score = '$score' WHERE id = $id";
        $result = mysqli_query($conn, $sql);
        
        echo json_encode([
            'success' => $result,
            'message' => $result ? 'Sector updated successfully' : mysqli_error($conn)
        ]);
        break;

    case 'DELETE':
        // Delete sector
        $id = intval($_GET['id']);
        
        $sql = "DELETE FROM sectorRiskScores WHERE id = $id";
        $result = mysqli_query($conn, $sql);
        
        echo json_encode([
            'success' => $result,
            'message' => $result ? 'Sector deleted successfully' : mysqli_error($conn)
        ]);
        break;
}
?>