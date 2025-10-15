<?php
require_once 'config.php';
require_once 'auth.php';

// THE FIX: Ensure the session is started BEFORE checking permissions
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Now, perform the permission check
if (!hasPermission('job_orders', 'view')) {
    $_SESSION['message'] = "You do not have permission to view this page.";
    $_SESSION['msg_type'] = "danger";
    header("location: dashboard.php");
    exit();
}

// The rest of the page can now load
include 'header.php';
echo '<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>';


// --- Helper function for status badges ---
function render_status_badge($status) {
    $badge_class = 'bg-secondary';
    switch ($status) {
        case 'Active': $badge_class = 'bg-success'; break;
        case 'Hold': $badge_class = 'bg-warning text-dark'; break;
        case 'Closed': $badge_class = 'bg-danger'; break;
    }
    return "<span class='badge " . $badge_class . "'>" . htmlspecialchars($status) . "</span>";
}

// --- Filter & Pagination Logic ---
$job_title_filter = $_GET['job_title'] ?? '';
$client_id_filter = $_GET['client_id'] ?? '';
$status_filter = $_GET['status'] ?? 'Active';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

$sql_conditions = [];
$params = [];
$types = '';

if (!empty($job_title_filter)) { $sql_conditions[] = "j.job_title LIKE ?"; $params[] = "%{$job_title_filter}%"; $types .= 's'; }
if (!empty($client_id_filter)) { $sql_conditions[] = "j.client_id = ?"; $params[] = $client_id_filter; $types .= 'i'; }
if ($status_filter && $status_filter !== 'All') { $sql_conditions[] = "j.status = ?"; $params[] = $status_filter; $types .= 's'; }

$where_clause = !empty($sql_conditions) ? " WHERE " . implode(" AND ", $sql_conditions) : '';

// Get total count for pagination
$total_sql = "SELECT COUNT(j.id) as total FROM job_orders j" . $where_clause;
$total_stmt = $conn->prepare($total_sql);
if (!empty($params)) { $total_stmt->bind_param($types, ...$params); }
$total_stmt->execute();
$total_results = $total_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_results / $limit);
$total_stmt->close();

// Get job orders for the current page
$jobs_sql = "SELECT j.id, j.job_title, j.job_location_city, c.client_name, j.status, j.created_at 
             FROM job_orders j 
             LEFT JOIN clients c ON j.client_id = c.id" . $where_clause . " 
             ORDER BY j.created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit; $types .= 'i';
$params[] = $offset; $types .= 'i';

$jobs_stmt = $conn->prepare($jobs_sql);
$jobs_stmt->bind_param($types, ...$params);
$jobs_stmt->execute();
$jobs_result = $jobs_stmt->get_result();

$clients = $conn->query("SELECT id, client_name FROM clients ORDER BY client_name")->fetch_all(MYSQLI_ASSOC);
?>
<style>
    .status-select-wrapper { position: relative; display: inline-block; }
    .status-select-wrapper .badge { position: absolute; top: 50%; left: 0.5rem; transform: translateY(-50%); pointer-events: none; }
    .status-select-wrapper select.form-select-sm { padding-left: 5rem; width: 150px; }
    .table-row-flash { animation: flash 1.5s ease-out; }
    @keyframes flash {
        0% { background-color: transparent; }
        20% { background-color: rgba(var(--bs-warning-rgb), 0.4); }
        100% { background-color: transparent; }
    }
    .job-table tbody tr:nth-child(odd) { background-color: #f8f9fa; }
    .job-table tbody tr:nth-child(even) { background-color: #e9ecef; }
    .job-table tbody tr:hover { background-color: #d1e7ff !important; }
    .card { border: none; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15); }
    .card-header { background: linear-gradient(135deg, #f8f9fc 0%, #e2e6f0 100%); border-bottom: 1px solid #e3e6f0; font-weight: 600; }
    .table th { border-top: none; font-weight: 600; color: #4e73df; background-color: #f8f9fc; }
    .job-title-link { color: #2e59d9; font-weight: 600; transition: color 0.2s; }
    .job-title-link:hover { color: #1c3ca0; text-decoration: underline; }
    .pagination .page-item.active .page-link { background-color: #4e73df; border-color: #4e73df; }
    .btn-primary { background: linear-gradient(135deg, #4e73df 0%, #224abe 100%); border: none; }
    .btn-primary:hover { background: linear-gradient(135deg, #3a5ccc 0%, #1a3cb0 100%); }
</style>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0 text-gray-800"><i class="bi bi-briefcase-fill me-2 text-primary"></i>Job Orders</h1>
        <?php if (hasPermission('job_orders', 'create')): ?>
            <a href="add_job_order.php" class="btn btn-primary shadow-sm"><i class="bi bi-plus-circle me-1"></i> Add New Job</a>
        <?php endif; ?>
    </div>

    <div class="card mb-4 shadow-sm">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"><i class="bi bi-filter me-2"></i>Filter Job Orders</h6>
        </div>
        <div class="card-body">
            <form action="job_orders.php" method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label for="job_title" class="form-label fw-semibold">Job Title</label>
                    <input type="text" id="job_title" name="job_title" class="form-control border-0 shadow-sm" placeholder="e.g., Software Engineer" value="<?php echo htmlspecialchars($job_title_filter); ?>">
                </div>
                <div class="col-md-3">
                    <label for="client_id" class="form-label fw-semibold">Client</label>
                    <select id="client_id" name="client_id" class="form-select border-0 shadow-sm">
                        <option value="">All Clients</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?php echo $client['id']; ?>" <?php echo ($client_id_filter == $client['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($client['client_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="status" class="form-label fw-semibold">Status</label>
                    <select id="status" name="status" class="form-select border-0 shadow-sm">
                        <option value="Active" <?php echo ($status_filter == 'Active') ? 'selected' : ''; ?>>Active</option>
                        <option value="Hold" <?php echo ($status_filter == 'Hold') ? 'selected' : ''; ?>>Hold</option>
                        <option value="Closed" <?php echo ($status_filter == 'Closed') ? 'selected' : ''; ?>>Closed</option>
                        <option value="All" <?php echo ($status_filter == 'All') ? 'selected' : ''; ?>>All</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100 shadow-sm"><i class="bi bi-search me-1"></i> Search</button>
                    <a href="job_orders.php" class="btn btn-outline-secondary w-100 shadow-sm"><i class="bi bi-arrow-counterclockwise"></i></a>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle job-table mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">Job Title</th>
                            <th>Client</th>
                            <th>Location</th>
                            <th>Created On</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($jobs_result->num_rows > 0): ?>
                            <?php while ($row = $jobs_result->fetch_assoc()): ?>
                                <tr id="job-row-<?php echo $row['id']; ?>">
                                    <td class="ps-4">
                                        <strong>
                                            <a href="view_job.php?id=<?php echo $row['id']; ?>" class="job-title-link text-decoration-none">
                                                <?php echo htmlspecialchars($row['job_title']); ?>
                                            </a>
                                        </strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['client_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($row['job_location_city']); ?></td>
                                    <td><?php echo date('d-M-Y', strtotime($row['created_at'])); ?></td>
                                    <td>
                                        <?php if (hasPermission('job_orders', 'edit')): ?>
                                            <div class="status-select-wrapper">
                                                <?php echo render_status_badge($row['status']); ?>
                                                <select class="form-select form-select-sm job-status-dropdown border-0 shadow-sm" data-job-id="<?php echo $row['id']; ?>" aria-label="Update job status">
                                                    <option value="Active" <?php echo ($row['status'] == 'Active') ? 'selected' : ''; ?>>Active</option>
                                                    <option value="Hold" <?php echo ($row['status'] == 'Hold') ? 'selected' : ''; ?>>Hold</option>
                                                    <option value="Closed" <?php echo ($row['status'] == 'Closed') ? 'selected' : ''; ?>>Closed</option>
                                                </select>
                                            </div>
                                        <?php else: ?>
                                            <?php echo render_status_badge($row['status']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group shadow-sm">
                                            <a href="view_job.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-secondary border-0" title="View"><i class="bi bi-eye"></i></a>
                                            <a href="pipeline.php?job_id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-primary border-0" title="Pipeline"><i class="bi bi-kanban"></i></a>
                                            <?php if (hasPermission('job_orders', 'edit')): ?>
                                                <a href="edit_job_order.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-info border-0" title="Edit"><i class="bi bi-pencil"></i></a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-5">
                                    <i class="bi bi-inbox fs-1 text-muted opacity-50"></i>
                                    <p class="mt-3 mb-0 text-muted">No job orders found.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($total_pages > 1): ?>
        <nav class="mt-4">
            <ul class="pagination justify-content-center shadow-sm">
                <?php for ($i = 1; $i <= $total_pages; $i++):
                    $query_params = $_GET; $query_params['page'] = $i;
                    $href = '?' . http_build_query($query_params);
                ?>
                    <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                        <a class="page-link border-0 shadow-sm" href="<?php echo $href; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<script>
$(document).ready(function() {
    $('.job-status-dropdown').on('change', function() {
        const selectElement = $(this);
        const jobId = selectElement.data('job-id');
        const newStatus = selectElement.val();
        const row = $('#job-row-' + jobId);
        const badgeWrapper = selectElement.closest('.status-select-wrapper');

        $.ajax({
            type: 'POST',
            url: 'update_job_status.php',
            data: { job_id: jobId, status: newStatus },
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    badgeWrapper.find('.badge').replaceWith(response.badge_html);
                    row.addClass('table-row-flash');
                    setTimeout(() => row.removeClass('table-row-flash'), 1500);
                } else {
                    alert('Error: ' + (response.message || 'Could not update status.'));
                }
            },
            error: function() {
                alert('An unexpected error occurred. Please check the Network tab for details.');
            }
        });
    });
});
</script>

<?php include 'footer.php'; ?>