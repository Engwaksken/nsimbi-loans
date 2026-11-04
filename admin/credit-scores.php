<?php require"nav-head.php";
require"services_check.php";

// Fetch customers with credit scores
$customers_query = mysqli_query($d_db, 'SELECT 
    c.id, 
    c.fname, 
    c.lname, 
    c.business_name, 
    c.business_type,
    c.credit_score
    FROM customers c WHERE c.active = 1 ORDER BY c.credit_score DESC');

$customers = [];
while($customer = mysqli_fetch_assoc($customers_query)) {
    $customers[] = $customer;
}
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header pb-0">
                    <h6>Credit Scores</h6>
                </div>
                <div class="card-body px-0 pt-0 pb-2">
                    <div class="table-responsive p-0">
                        <table class="table align-items-center mb-0">
                            <thead>
                                <tr>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Name</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Business Name</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Sector</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Credit Score</th>
                                    <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                foreach($customers as $customer) {
                                    $fullName = htmlspecialchars($customer['fname'] . ' ' . $customer['lname']);
                                    $businessName = htmlspecialchars($customer['business_name']);
                                    $sectorName = htmlspecialchars($customer['business_type']);
                                    $creditScore = number_format($customer['credit_score'], 2);
                                    
                                    echo "<tr>
                                        <td>
                                            <div class='d-flex px-2 py-1'>
                                                <div class='d-flex flex-column justify-content-center'>
                                                    <h6 class='mb-0 text-sm'>$fullName</h6>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <p class='text-xs font-weight-bold mb-0'>$businessName</p>
                                        </td>
                                        <td>
                                            <p class='text-xs font-weight-bold mb-0'>$sectorName</p>
                                        </td>
                                        <td>
                                            <span class='text-xs font-weight-bold mb-0 
                                                " . ($creditScore >= 0.7 ? 'text-success' : ($creditScore >= 0.4 ? 'text-warning' : 'text-danger')) . "'>
                                                $creditScore
                                            </span>
                                        </td>
                                        <td class='align-middle text-center'>
                                            <a href='view_customer.php?id={$customer['id']}' 
                                               class='btn btn-sm bg-gradient-info'>
                                                <i class='fas fa-eye'></i> View
                                            </a>
                                        </td>
                                    </tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize DataTable for better table functionality
    $('.table').DataTable({
        "order": [[3, "desc"]], // Sort by credit score by default
        "pageLength": 25,
        "language": {
            "paginate": {
                "previous": "<",
                "next": ">"
            }
        },
        "dom": '<"top"f>rt<"bottom"lip><"clear">'
    });
});
</script>