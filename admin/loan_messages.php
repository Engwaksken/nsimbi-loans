<?php
require "nav-head.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn)) {
    exit("Database connection not established.");
}

function h($v){
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// ===== STATS =====
$statsSql = "
SELECT
COUNT(*) total_messages,
SUM(is_read=1) total_read,
SUM(is_read=0) total_unread,
COUNT(DISTINCT sender_name) total_senders
FROM loan_messages
";

$stats = $conn->query($statsSql)->fetch_assoc();
?>

<div class="container-fluid py-4">

<!-- ===== STATS ===== -->
<div class="stats-row mb-4">

    <?php
    $cards = [
        ['TOTAL MESSAGES', $stats['total_messages'], 'primary', 'fa-envelope'],
        ['READ', $stats['total_read'], 'success', 'fa-envelope-open'],
        ['UNREAD', $stats['total_unread'], 'warning', 'fa-bell'],
        ['SENDERS', $stats['total_senders'], 'dark', 'fa-users']
    ];

    foreach($cards as $c): ?>
    <div class="stats-col">
        <div class="stat-card border-<?= $c[2] ?>-band">
            <div class="stat-flex">
                <div>
                    <div class="stat-label"><?= $c[0] ?></div>
                    <div class="stat-value"><?= number_format($c[1]) ?></div>
                </div>
                <div class="stat-icon bg-<?= $c[2] ?>">
                    <i class="fas <?= $c[3] ?>"></i>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

</div>

<!-- ===== NOTIFICATIONS ===== -->
<div class="card notifications-card">

    <div class="notifications-header">
       
        <input type="text"
               id="liveSearch"
               class="form-control form-control-sm search-box"
               placeholder="Search notifications...">
    </div>

    <div class="card-body p-0">

        <div class="table-responsive">
            <table class="table mb-0">
              <thead>
<tr>
    <th>#</th>
    <th>Sender</th>
    <th>Type</th>
    <th>Status</th>
    <th>Date</th>
    <th class="text-center">Action</th>
</tr>
</thead>
                <tbody id="notificationTable">
                    <!-- AJAX CONTENT -->
                </tbody>
            </table>
        </div>

        <div id="paginationArea" class="p-3 text-center"></div>

    </div>
</div>

</div>
<script>
let currentPage = 1;
let searchQuery = '';

function loadNotifications(page = 1) {

    currentPage = page;

    fetch(`fetch_notifications.php?page=${page}&search=${encodeURIComponent(searchQuery)}`)
        .then(res => res.json())
        .then(data => {

            document.getElementById('notificationTable').innerHTML = data.rows;
            document.getElementById('paginationArea').innerHTML = data.pagination;

            attachEvents();
        });
}

// ===== MARK AS READ FUNCTION =====
function markAsRead(row){

    const id = row.dataset.id;

    fetch('mark_read.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'id=' + id
    })
    .then(() => {

        row.classList.remove('unread-row');

        // update status badge
        row.querySelector('td:nth-child(4)').innerHTML =
            "<span class='badge badge-read'>Read</span>";

        // update action column
        row.querySelector('td:nth-child(6)').innerHTML =
            "<span class='text-muted small'>Done</span>";
    });
}

// ===== ATTACH EVENTS =====
function attachEvents(){

    // ROW CLICK
    document.querySelectorAll('.notification-row').forEach(row => {

        row.addEventListener('click', function(e){

            // prevent click if button clicked
            if (e.target.closest('.mark-read-btn')) return;

            if (!this.classList.contains('unread-row')) return;

            markAsRead(this);
        });

    });

    // BUTTON CLICK
    document.querySelectorAll('.mark-read-btn').forEach(btn => {

        btn.addEventListener('click', function(e){

            e.stopPropagation(); // VERY IMPORTANT

            const row = this.closest('tr');

            markAsRead(row);
        });

    });
}

// ===== LIVE SEARCH =====
let timer;
document.getElementById('liveSearch').addEventListener('keyup', function(){

    clearTimeout(timer);

    timer = setTimeout(() => {
        searchQuery = this.value;
        loadNotifications(1);
    }, 300);

});

// INITIAL LOAD
loadNotifications();
</script>

<?php require "footer.php"; ?>