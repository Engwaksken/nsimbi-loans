<?php
require "services_check.php";
require"db.php";


/**
 * Calculate the weighted business score
 * 
 * @param float $score Raw score value
 * @param float $scoreWeight Weight factor for the score
 * @return int Calculated weighted score, rounded up to nearest integer
 */
function calculateScore($score, $scoreWeight) {
    $weightedScore = ($score / 10) * ($scoreWeight * 100);
    return $weightedScore ? ceil($weightedScore) : 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = intval($_POST['customer_id']);
    $scores = $_POST['scores'];
    
    // Validate scores
    foreach ($scores as $key => $score) {
        if ($score < 0 || $score > 1) {
            echo json_encode([
                'success' => false,
                'message' => 'All scores must be between 0 and 1'
            ]);
            exit;
        }
    }

    $customer_query = mysqli_query($d_db, "SELECT * FROM customers WHERE id = ". intval( $customer_id ) );
    $customer = mysqli_fetch_assoc($customer_query);
    //Business type
    $business_type = $customer['business_type'];

    $risk_query = mysqli_query($d_db, "SELECT * FROM `sectorRiskScores` WHERE name = '$business_type'");
    $sector_risk = mysqli_fetch_assoc($risk_query);
    //Sector Risk score
    $sector_risk_score = $sector_risk['score'];

    $credit_componsnts_query = mysqli_query($d_db, "SELECT * FROM credit_components WHERE customer_id = ". intval( $customer_id ) );
    $credit_components = mysqli_fetch_assoc($credit_componsnts_query);

    //Eso score
    $eso_score = $credit_components['eso_score'];
    //Sector score
    $sector_score = $credit_components['sector_score'];
    //Mobile money score
    $mobile_money_score = $credit_components['mobile_money_score'];
    //Personal score
    $personal_score = $credit_components['personal_score'];
    //Business score
    $business_score = $credit_components['business_score'];

    $esoScoreWeight = calculateScore( $scores['eso_score'], $eso_score );
    $sectorScoreWeight = calculateScore( $scores['sector_score'], $sector_risk_score );
    $mobileMoneyScoreWeight = calculateScore( $scores['mobile_money_score'], $mobile_money_score);
    $personalScoreWeight = calculateScore($scores['personal_score'], $personal_score );
    $businessScoreWeight = calculateScore( $scores['business_score'], $business_score);

    $creditScore = ($esoScoreWeight + $sectorScoreWeight + $mobileMoneyScoreWeight + $personalScoreWeight + $businessScoreWeight);
    // Update scores
    $sql = "UPDATE credit_components SET 
            eso_score = ?, 
            sector_score = ?, 
            mobile_money_score = ?, 
            personal_score = ?, 
            business_score = ? 
            WHERE customer_id = ?";
            
    $stmt = mysqli_prepare($d_db, $sql);
    mysqli_stmt_bind_param($stmt, "dddddi", 
        $scores['eso_score'],
        $scores['sector_score'],
        $scores['mobile_money_score'],
        $scores['personal_score'],
        $scores['business_score'],
        $customer_id
    );
    
    $result = mysqli_stmt_execute($stmt);

    $sql = "UPDATE customers SET credit_score = ? WHERE id = ?";
    $stmt = mysqli_prepare($d_db, $sql);
    mysqli_stmt_bind_param($stmt, "di", $creditScore, $customer_id);
    $result = mysqli_stmt_execute($stmt);
    
    echo json_encode([
        'success' => $result,
        'message' => $result ? 'Scores updated successfully' : mysqli_error($d_db)
    ]);
}
?>