<?php
require "nav-head.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in'])) {
    exit("User not logged in.");
}

$user_id = intval($_SESSION['logged_in']);
$message_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$result = false;

if ($message_id > 0) {
    if (!isset($conn)) {
        exit("Database connection not established.");
    }

    $stmt = $conn->prepare("UPDATE loan_messages SET is_read = 1 WHERE id = ? AND user_id = ?");
    if ($stmt) {
        $stmt->bind_param("ii", $message_id, $user_id);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $conn->prepare("SELECT * FROM loan_messages WHERE id = ? AND user_id = ?");
    if ($stmt) {
        $stmt->bind_param("ii", $message_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
    }
}
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card mb-4">
                <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                    <h6>Loan Messages</h6>
                    <button class="btn bg-gradient-success btn-sm" onclick="download_table_as_csv('report')">
                        <i class="fa fa-download me-1"></i> Export CSV
                    </button>
                </div>

                <div class="card-body px-0 pt-0 pb-2">
                    <?php if ($result && $result->num_rows > 0): ?>
                        <div class="table-responsive p-0">
                            <table class="table align-items-center mb-0" id="report">
                                <thead>
                                    <tr>
                                        <th>Processed By</th>
                                        <th>Message Type</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($row = $result->fetch_assoc()): ?>
                                        <tr class="<?php echo $row['is_read'] ? '' : 'unread'; ?>">
                                            <td><?php echo htmlspecialchars($row['sender_name']); ?></td>
                                           <td><?php echo ucwords(str_replace('_', ' ', htmlspecialchars($row['message_type']))); ?></td>
                                            <td><?php echo $row['is_read'] ? 'Read' : 'Unread'; ?></td>
                                            <td><?php echo htmlspecialchars($row['created_at']); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="p-3">
                            <p>No messages found or invalid message ID.</p>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>
</div>

<?php require "footer.php"; ?>
