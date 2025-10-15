<?php
require_once 'config.php';
require_once 'auth.php';

// Check permissions BEFORE including the header
if (!hasPermission('job_orders', 'view')) {
    $_SESSION['message'] = "You do not have permission to view this page.";
    $_SESSION['msg_type'] = "danger";
    header("location: dashboard.php");
    exit();
}

include 'header.php';

// Get all active jobs for the dropdown selector
$active_jobs = $conn->query("SELECT j.id, j.job_title, c.client_name FROM job_orders j JOIN clients c ON j.client_id = c.id WHERE j.status = 'Active' ORDER BY c.client_name, j.job_title")->fetch_all(MYSQLI_ASSOC);

// More robust logic for selecting a job
$selected_job_id = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;
if ($selected_job_id === 0 && !empty($active_jobs)) {
    $selected_job_id = $active_jobs[0]['id'];
}

$all_submissions = [];
if ($selected_job_id > 0) {
    // Fetch all submission data for the selected job
    $submissions_stmt = $conn->prepare("
        SELECT 
            s.id as submission_id, s.status, 
            c.candidate_name, c.email,
            u.full_name as sourced_by_recruiter,
            i.id as interview_id, i.interview_datetime, i.interview_round
        FROM submissions s 
        JOIN candidates c ON s.candidate_id = c.id
        JOIN users u ON s.recruiter_id = u.id
        LEFT JOIN interviews i ON s.id = i.submission_id
        WHERE s.job_id = ?
    ");
    $submissions_stmt->bind_param("i", $selected_job_id);
    $submissions_stmt->execute();
    $all_submissions = $submissions_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// UPDATED: Removed 'Sourced' from the columns for the Kanban board
$pipeline_stages = [
    'Submitted to client' => [],
    'L1 interview' => [],
    'L2 interview' => [],
    'Customer Interview' => [],
    'Offered' => [],
    'Joined' => []
];

// Distribute the fetched candidates into the correct stage arrays
foreach ($all_submissions as $submission) {
    if (array_key_exists($submission['status'], $pipeline_stages)) {
        $pipeline_stages[$submission['status']][] = $submission;
    }
}
?>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
<link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">

<style>
    .pipeline-board { display: flex; overflow-x: auto; padding-bottom: 20px; }
    .pipeline-column { flex: 0 0 300px; width: 300px; margin-right: 15px; background-color: #f8f9fa; border-radius: 8px; padding: 10px; border: 1px solid #e9ecef;}
    .pipeline-column h5 { font-size: 1rem; font-weight: bold; padding-bottom: 10px; border-bottom: 2px solid #dee2e6; margin-bottom: 15px; }
    .candidate-card { padding: 15px; background-color: #fff; border: 1px solid #dee2e6; border-radius: 5px; margin-bottom: 10px; cursor: grab; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
    .candidate-card strong { display: block; margin-bottom: 5px; }
    .candidate-card small { color: #6c757d; }
    .card-actions { margin-top: 10px; text-align: right; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1><i class="bi bi-kanban-fill me-2"></i>Recruitment Pipeline</h1>
    <div>
        <a href="view_job.php?id=<?php echo $selected_job_id; ?>" class="btn btn-outline-secondary <?php echo ($selected_job_id == 0) ? 'disabled' : ''; ?>">
            <i class="bi bi-file-earmark-text"></i> View Job Details
        </a>
        <a href="job_orders.php" class="btn btn-secondary">
             <i class="bi bi-list-ul"></i> Back to Job List
        </a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body row align-items-center">
        <label class="col-md-2 col-form-label"><strong>Viewing Pipeline For:</strong></label>
        <div class="col-md-10">
            <select id="job-selector" class="form-select">
                <?php if (empty($active_jobs)) : ?>
                    <option>No active jobs found</option>
                <?php else: ?>
                    <?php foreach ($active_jobs as $job): ?>
                        <option value="<?php echo $job['id']; ?>" <?php echo ($job['id'] == $selected_job_id) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($job['job_title'] . ' (' . $job['client_name'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>
    </div>
</div>

<div id="status-message-container" style="display:none;" class="alert alert-success mb-3"></div>

<div class="pipeline-board">
    <?php foreach ($pipeline_stages as $stage_name => $candidates): ?>
        <div class="pipeline-column" data-status="<?php echo htmlspecialchars($stage_name); ?>">
            <h5><?php echo htmlspecialchars($stage_name); ?> <span class="badge bg-secondary rounded-pill"><?php echo count($candidates); ?></span></h5>
            <div class="card-list">
                <?php if (empty($candidates) && $selected_job_id == 0): ?>
                    <p class="text-center text-muted small mt-3">Please select a job to view its pipeline.</p>
                <?php endif; ?>
                <?php foreach ($candidates as $candidate): ?>
                    <div class="candidate-card" data-submission-id="<?php echo $candidate['submission_id']; ?>">
                        <strong><?php echo htmlspecialchars($candidate['candidate_name']); ?></strong>
                        <small><?php echo htmlspecialchars($candidate['email']); ?></small>
                        <?php if (!empty($candidate['interview_datetime'])): ?>
                            <div class="mt-2 p-1 bg-light border rounded small">
                                <i class="bi bi-calendar-check text-success"></i> <?php echo date('d-M h:i A', strtotime($candidate['interview_datetime'])); ?>
                            </div>
                        <?php endif; ?>
                        <div class="card-actions">
                            <?php if (strpos($stage_name, 'interview') !== false && hasPermission('job_orders', 'edit')): ?>
                                <a href="edit_interview.php?id=<?php echo $candidate['interview_id']; ?>" class="btn btn-xs btn-info" title="Edit Interview"><i class="bi bi-pencil-square"></i></a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<script>
$(document).ready(function() {
    $('#job-selector').on('change', function() {
        const selectedJobId = $(this).val();
        if(selectedJobId) {
            window.location.href = 'pipeline.php?job_id=' + selectedJobId;
        }
    });

    $('.card-list').sortable({
        connectWith: '.card-list',
        placeholder: "ui-state-highlight",
        cursor: 'grabbing',
        stop: function(event, ui) {
            const submissionId = ui.item.data('submission-id');
            const newStatus = ui.item.closest('.pipeline-column').data('status');
            $('#status-message-container').html(`Updating status for ${ui.item.find('strong').text()} to "${newStatus}"...`).fadeIn();
            $.ajax({
                type: 'POST',
                url: 'update_submission_status.php',
                data: { submission_id: submissionId, status: newStatus },
                success: function(response) { $('#status-message-container').html(`Status updated successfully!`).delay(2000).fadeOut(); },
                error: function() { $('#status-message-container').addClass('alert-danger').html('Error updating status. Please refresh.').delay(3000).fadeOut(); }
            });
        }
    }).disableSelection();
});
</script>

<?php include 'footer.php'; ?>