<?php
include 'header.php';
echo '<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>';
require_once 'auth.php';

if (!hasPermission('candidates', 'view')) {
    $_SESSION['message'] = "You do not have permission to view this page.";
    $_SESSION['msg_type'] = "danger";
    header("location: dashboard.php");
    exit();
}

// --- Filter & Pagination Logic ---
$name_filter = $_GET['name'] ?? '';
$email_filter = $_GET['email'] ?? '';
$location_filter = $_GET['location'] ?? '';
$recruiter_id_filter = isset($_GET['recruiter_id']) ? (int)$_GET['recruiter_id'] : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

$sql_conditions = [];
$params = [];
$types = '';

if (!empty($name_filter)) { $sql_conditions[] = "c.candidate_name LIKE ?"; $params[] = "%{$name_filter}%"; $types .= 's'; }
if (!empty($email_filter)) { $sql_conditions[] = "c.email LIKE ?"; $params[] = "%{$email_filter}%"; $types .= 's'; }
if (!empty($location_filter)) { $sql_conditions[] = "c.current_location LIKE ?"; $params[] = "%{$location_filter}%"; $types .= 's'; }
if (!empty($recruiter_id_filter)) { $sql_conditions[] = "c.created_by_id = ?"; $params[] = $recruiter_id_filter; $types .= 'i'; }

$where_clause = !empty($sql_conditions) ? " WHERE " . implode(" AND ", $sql_conditions) : '';

// Get total count for pagination
$total_sql = "SELECT COUNT(c.id) as total FROM candidates c" . $where_clause;
$total_stmt = $conn->prepare($total_sql);
if (!empty($params)) { $total_stmt->bind_param($types, ...$params); }
$total_stmt->execute();
$total_results = $total_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_results / $limit);
$total_stmt->close();

// Get candidates for the current page
$candidates_sql = "
    SELECT 
        c.id, c.candidate_name, c.email, c.phone, c.current_location, 
        c.resume_filename, c.created_at, u.full_name as created_by_name
    FROM candidates c
    LEFT JOIN users u ON c.created_by_id = u.id
    $where_clause 
    ORDER BY c.id DESC 
    LIMIT ? OFFSET ?
";
$params[] = $limit; $types .= 'i';
$params[] = $offset; $types .= 'i';

$stmt = $conn->prepare($candidates_sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$candidates_result = $stmt->get_result();

// Fetch data for modals/filters
$active_jobs_for_modal = $conn->query("SELECT j.id, j.job_title, c.client_name FROM job_orders j JOIN clients c ON j.client_id = c.id WHERE j.status = 'Active' ORDER BY c.client_name, j.job_title")->fetch_all(MYSQLI_ASSOC);
$recruiters_for_filter = $conn->query("SELECT id, full_name FROM users WHERE role IN ('recruiter', 'team_lead', 'admin') ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
?>
<style>
    .candidate-avatar { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1rem; font-weight: 600; color: white; }
    .list-group-item .text-muted { font-size: 0.85rem; }
    .list-group-item .meta-info { font-size: 0.8rem; color: #6c757d; }
    .list-group-item.selected { background-color: #e9ecef; }
    .bulk-actions-bar { display: none; position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); z-index: 1050; box-shadow: 0 5px 15px rgba(0,0,0,0.2); border-radius: 8px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800"><i class="bi bi-people-fill me-2"></i>Candidates</h1>
    <?php if (hasPermission('candidates', 'create')): ?>
        <a href="add_candidate.php" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i> Add New Candidate</a>
    <?php endif; ?>
</div>

<div class="card mb-4">
    <div class="card-header"><a class="text-decoration-none text-dark" data-bs-toggle="collapse" href="#filterCollapse" role="button"><i class="bi bi-filter me-2"></i>Filter Candidates</a></div>
    <div class="collapse show" id="filterCollapse">
        <div class="card-body">
            <form action="candidates.php" method="GET" class="row g-3 align-items-end">
                <div class="col-md-2"><label class="form-label">Name</label><input type="text" name="name" class="form-control" placeholder="By name..." value="<?php echo htmlspecialchars($name_filter); ?>"></div>
                <div class="col-md-2"><label class="form-label">Email</label><input type="text" name="email" class="form-control" placeholder="By email..." value="<?php echo htmlspecialchars($email_filter); ?>"></div>
                <div class="col-md-3"><label class="form-label">Location</label><input type="text" name="location" class="form-control" placeholder="By location..." value="<?php echo htmlspecialchars($location_filter); ?>"></div>
                <div class="col-md-3">
                    <label class="form-label">Uploaded By</label>
                    <select name="recruiter_id" class="form-select">
                        <option value="">All Recruiters</option>
                        <?php foreach ($recruiters_for_filter as $recruiter): ?>
                            <option value="<?php echo $recruiter['id']; ?>" <?php if ($recruiter_id_filter == $recruiter['id']) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($recruiter['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex"><button type="submit" class="btn btn-primary w-100 me-2">Filter</button><a href="candidates.php" class="btn btn-outline-secondary w-100">Clear</a></div>
            </form>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <form id="bulk-assign-form" action="bulk_assign_to_job.php" method="POST">
            <div class="list-group list-group-flush">
                <div class="list-group-item bg-light">
                    <div class="row align-items-center fw-bold text-muted">
                        <div class="col-auto"><input class="form-check-input" type="checkbox" id="select-all-candidates"></div>
                        <div class="col-md-4">Candidate</div>
                        <div class="col-md-4 d-none d-md-block">Details</div>
                        <div class="col-md-3 text-end d-none d-md-block">Actions</div>
                    </div>
                </div>
                <?php if ($candidates_result->num_rows > 0): ?>
                    <?php while ($row = $candidates_result->fetch_assoc()): ?>
                        <div class="list-group-item p-3">
                            <div class="row align-items-center">
                                <div class="col-auto"><input class="form-check-input candidate-checkbox" type="checkbox" name="candidate_ids[]" value="<?php echo $row['id']; ?>"></div>
                                <div class="col-md-4 mb-2 mb-md-0">
                                    <div class="d-flex align-items-center">
                                        <div class="candidate-avatar me-3" style="background-color: #<?php echo substr(md5($row['candidate_name']), 0, 6); ?>;"><?php echo strtoupper(substr($row['candidate_name'], 0, 2)); ?></div>
                                        <div>
                                            <!-- ✅ Added link to candidate_details.php -->
                                            <h6 class="mb-0">
                                                <a href="candidate_details.php?id=<?php echo $row['id']; ?>" class="text-decoration-none">
                                                    <?php echo htmlspecialchars($row['candidate_name']); ?>
                                                </a>
                                            </h6>
                                            <a href="mailto:<?php echo htmlspecialchars($row['email']); ?>" class="text-muted text-decoration-none"><?php echo htmlspecialchars($row['email']); ?></a>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-2 mb-md-0">
                                    <div class="text-muted"><i class="bi bi-telephone-fill me-1"></i> <?php echo htmlspecialchars($row['phone']); ?></div>
                                    <div class="meta-info mt-1"><i class="bi bi-person-up me-1"></i> Uploaded by <?php echo htmlspecialchars($row['created_by_name'] ?? 'N/A'); ?></div>
                                </div>
                                <div class="col-md-3 text-md-end">
                                    <div class="btn-group">
                                        <button type="button" class="btn btn-sm btn-outline-secondary view-resume-btn" data-candidate-id="<?php echo $row['id']; ?>"><i class="bi bi-file-earmark-text"></i> Resume</button>
                                        <button type="button" class="btn btn-sm btn-outline-success assign-job-btn" data-bs-toggle="modal" data-bs-target="#assignJobModal" data-candidate-id="<?php echo $row['id']; ?>" data-candidate-name="<?php echo htmlspecialchars($row['candidate_name']); ?>"><i class="bi bi-plus-lg"></i> Assign</button>
                                        <?php if (hasPermission('candidates', 'edit')): ?>
                                            <a href="edit_candidate.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i> Edit</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="list-group-item"><div class="text-center py-5"><i class="bi bi-person-x fs-1 text-muted"></i><h4 class="mt-3">No Candidates Found</h4><p class="text-muted">No candidates match your filter criteria.</p><a href="add_candidate.php" class="btn btn-primary mt-2">Add the First Candidate</a></div></div>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if ($total_pages > 1): ?>
    <nav class="mt-4"><ul class="pagination justify-content-center">
        <?php for ($i = 1; $i <= $total_pages; $i++):
            $_GET['page'] = $i;
            $href = '?' . http_build_query($_GET);
        ?>
            <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>"><a class="page-link" href="<?php echo $href; ?>"><?php echo $i; ?></a></li>
        <?php endfor; ?>
    </ul></nav>
<?php endif; ?>

<div class="bulk-actions-bar card p-2">
    <div class="d-flex align-items-center">
        <span class="me-3"><strong id="selected-count">0</strong> candidates selected</span>
        <button class="btn btn-success" id="bulk-assign-btn" data-bs-toggle="modal" data-bs-target="#bulkAssignJobModal"><i class="bi bi-plus-lg"></i> Bulk Assign to Job</button>
    </div>
</div>

<!-- Resume Viewer Modal -->
<div id="resumeViewerModal" class="modal fade" tabindex="-1">
    <div class="modal-dialog modal-xl" style="height: 95vh;">
        <div class="modal-content" style="height: 100%;">
            <div class="modal-header">
                <h5 class="modal-title" id="modalCandidateName">Resume</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalResumeBody" style="padding: 0; overflow: hidden;"></div>
        </div>
    </div>
</div>

<!-- Assign Job Modal -->
<div class="modal fade" id="assignJobModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Assign Candidate to Job</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="assignJobForm">
                <div class="modal-body">
                    <input type="hidden" name="candidate_id" id="assign_candidate_id">
                    <p>Assigning candidate: <strong id="assign_candidate_name"></strong></p>
                    <div class="mb-3">
                        <label for="assign_job_id" class="form-label">Select an Active Job <span class="text-danger">*</span></label>
                        <select name="job_id" id="assign_job_id" class="form-select" required>
                            <option value="">-- Select Job --</option>
                            <?php foreach ($active_jobs_for_modal as $job): ?>
                                <option value="<?php echo $job['id']; ?>">
                                    <?php echo htmlspecialchars($job['job_title'] . " (" . $job['client_name'] . ")"); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Assign Candidate</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Assign Job Modal -->
<div class="modal fade" id="bulkAssignJobModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Bulk Assign Candidates</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>You are about to assign <strong id="bulk-assign-count">0</strong> selected candidates to the following job:</p>
                <div class="mb-3">
                    <label for="bulk_assign_job_id" class="form-label">Select an Active Job <span class="text-danger">*</span></label>
                    <select id="bulk_assign_job_id" class="form-select" required>
                        <option value="">-- Select Job --</option>
                        <?php foreach ($active_jobs_for_modal as $job): ?>
                            <option value="<?php echo $job['id']; ?>">
                                <?php echo htmlspecialchars($job['job_title'] . " (" . $job['client_name'] . ")"); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="confirm-bulk-assign" class="btn btn-primary">Confirm & Assign</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const resumeModal = new bootstrap.Modal(document.getElementById('resumeViewerModal'));
    
    // Handler for the "View Resume" button
    $(document).on('click', '.view-resume-btn', function(e) {
        e.preventDefault();
        const candidateId = $(this).data('candidate-id');
        const modalBody = $('#modalResumeBody');
        const modalTitle = $('#modalCandidateName');
        
        modalTitle.text('Loading Resume...');
        modalBody.html('<div class="text-center p-5"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2">Loading resume...</p></div>');
        resumeModal.show();
        
        $.ajax({
            url: 'api_get_resume_content.php', 
            type: 'GET', 
            data: { candidate_id: candidateId }, 
            dataType: 'json',
            success: function(response) {
                console.log('Resume API response:', response);
                
                if (response.status === 'success') {
                    modalTitle.text(response.name + "'s Resume");
                    
                    // Use local file path instead of full URL
                    const localFilePath = 'uploads/' + response.filename;
                    const fileExtension = response.filename.split('.').pop().toLowerCase();
                    let viewerHtml;

                    if (fileExtension === 'pdf') {
                        viewerHtml = `<iframe src="${localFilePath}" style="width: 100%; height: 100%; border: none;"></iframe>`;
                    } else if (fileExtension === 'doc' || fileExtension === 'docx') {
                        // For Word documents, show download option
                        viewerHtml = `
                            <div class="text-center p-5">
                                <i class="bi bi-file-earmark-word fs-1 text-primary"></i>
                                <h5 class="mt-3">Word Document</h5>
                                <p class="text-muted">Word documents cannot be previewed in browser.</p>
                                <a href="${localFilePath}" class="btn btn-primary mt-2" download>
                                    <i class="bi bi-download"></i> Download Resume
                                </a>
                            </div>
                        `;
                    } else {
                        // For other file types
                        viewerHtml = `
                            <div class="text-center p-5">
                                <i class="bi bi-file-earmark-text fs-1 text-warning"></i>
                                <h5 class="mt-3">File Preview Not Available</h5>
                                <p class="text-muted">This file type cannot be previewed in the browser.</p>
                                <a href="${localFilePath}" class="btn btn-primary mt-2" download>
                                    <i class="bi bi-download"></i> Download Resume
                                </a>
                            </div>
                        `;
                    }
                    modalBody.html(viewerHtml);
                } else {
                    modalBody.html('<div class="text-center p-5"><i class="bi bi-exclamation-triangle fs-1 text-danger"></i><h5 class="mt-3">Resume Not Available</h5><p class="text-muted">' + response.message + '</p></div>');
                }
            },
            error: function(xhr, status, error) {
                console.log('Resume API error:', error);
                modalBody.html('<div class="text-center p-5"><i class="bi bi-exclamation-triangle fs-1 text-danger"></i><h5 class="mt-3">Failed to Load Resume</h5><p class="text-muted">Unable to load resume content. Please try again.</p></div>');
            }
        });
    });

    // Handler for the "Assign to Job" button
    $(document).on('click', '.assign-job-btn', function() {
        $('#assign_candidate_id').val($(this).data('candidate-id'));
        $('#assign_candidate_name').text($(this).data('candidate-name'));
    });

    // Handle assign job form submission with AJAX
    $('#assignJobForm').on('submit', function(e) {
        e.preventDefault();
        
        const candidateId = $('#assign_candidate_id').val();
        const jobId = $('#assign_job_id').val();
        const candidateName = $('#assign_candidate_name').text();
        
        if (!jobId) {
            alert('Please select a job first.');
            return;
        }
        
        // Show loading state
        const submitBtn = $(this).find('button[type="submit"]');
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status"></span> Assigning...');
        
        $.ajax({
            url: 'assign_candidate_to_job.php',
            type: 'POST',
            data: {
                candidate_id: candidateId,
                job_id: jobId,
                assign_job: true
            },
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    // Show success message
                    const modal = bootstrap.Modal.getInstance(document.getElementById('assignJobModal'));
                    modal.hide();
                    
                    // Show success alert
                    showAlert(response.message, 'success');
                    
                    // Reset form
                    $('#assignJobForm')[0].reset();
                } else {
                    showAlert(response.message, 'danger');
                }
            },
            error: function(xhr, status, error) {
                showAlert('Error assigning candidate: ' + error, 'danger');
            },
            complete: function() {
                submitBtn.prop('disabled', false).html('Assign Candidate');
            }
        });
    });

    // Helper function to show alerts
    function showAlert(message, type) {
        const alertDiv = $('<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">' +
            message +
            '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' +
            '</div>');
        
        $('.card:first').before(alertDiv);
        
        // Auto remove after 5 seconds
        setTimeout(function() {
            alertDiv.alert('close');
        }, 5000);
    }

    // --- SCRIPT FOR BULK ACTIONS ---
    const selectAllCheckbox = document.getElementById('select-all-candidates');
    const candidateCheckboxes = document.querySelectorAll('.candidate-checkbox');
    const bulkActionsBar = document.querySelector('.bulk-actions-bar');
    const selectedCountEl = document.getElementById('selected-count');
    const bulkAssignCountEl = document.getElementById('bulk-assign-count');
    const confirmBulkAssignBtn = document.getElementById('confirm-bulk-assign');
    const bulkAssignForm = document.getElementById('bulk-assign-form');
    
    function updateBulkActionsUI() {
        const selectedCheckboxes = document.querySelectorAll('.candidate-checkbox:checked');
        const count = selectedCheckboxes.length;
        if (count > 0) {
            selectedCountEl.textContent = count;
            bulkAssignCountEl.textContent = count;
            bulkActionsBar.style.display = 'block';
        } else {
            bulkActionsBar.style.display = 'none';
        }
    }

    selectAllCheckbox.addEventListener('change', function() {
        candidateCheckboxes.forEach(checkbox => {
            checkbox.checked = this.checked;
        });
        updateBulkActionsUI();
    });

    candidateCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateBulkActionsUI);
    });

    confirmBulkAssignBtn.addEventListener('click', function() {
        const jobId = document.getElementById('bulk_assign_job_id').value;
        if (!jobId) {
            alert('Please select a job first.');
            return;
        }

        // Create hidden input for job ID and submit form
        const jobInput = document.createElement('input');
        jobInput.type = 'hidden';
        jobInput.name = 'job_id';
        jobInput.value = jobId;
        bulkAssignForm.appendChild(jobInput);
        
        bulkAssignForm.submit();
    });
});
</script>

<?php include 'footer.php'; ?>