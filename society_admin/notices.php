<?php
require_once 'includes/auth_check.php';
require_once __DIR__ . '/../include/dbconfig.php';
require_once __DIR__ . '/../include/helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_notice') {
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $type = $_POST['type'] ?? 'general';
        $tower_id = intval($_POST['tower_id'] ?? 0) ?: null;
        $expires_at = $_POST['expires_at'] ?? null;
        if ($expires_at === '') $expires_at = null;

        if ($title !== '' && $content !== '') {
            $stmt = $conn->prepare(
                "INSERT INTO tbl_notice (society_id, tower_id, title, content, type, posted_by, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('iisssis', $society_id, $tower_id, $title, $content, $type, $admin_id, $expires_at);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_success'] = 'Notice posted successfully.';
        } else {
            $_SESSION['flash_error'] = 'Title and content are required.';
        }
    } elseif ($action === 'edit_notice') {
        $notice_id = intval($_POST['notice_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $type = $_POST['type'] ?? 'general';
        $tower_id = intval($_POST['tower_id'] ?? 0) ?: null;
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($notice_id > 0 && $title !== '') {
            $stmt = $conn->prepare(
                "UPDATE tbl_notice SET title = ?, content = ?, type = ?, tower_id = ?, is_active = ? WHERE id = ? AND society_id = ?"
            );
            $stmt->bind_param('sssiiii', $title, $content, $type, $tower_id, $is_active, $notice_id, $society_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_success'] = 'Notice updated.';
        }
    } elseif ($action === 'delete_notice') {
        $notice_id = intval($_POST['notice_id'] ?? 0);
        if ($notice_id > 0) {
            $stmt = $conn->prepare("DELETE FROM tbl_notice WHERE id = ? AND society_id = ?");
            $stmt->bind_param('ii', $notice_id, $society_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_success'] = 'Notice deleted.';
        }
    } elseif ($action === 'add_poll') {
        $question = trim($_POST['question'] ?? '');
        $options = array_filter(array_map('trim', $_POST['options'] ?? []), function($v) { return $v !== ''; });
        $end_date = $_POST['end_date'] ?? '';

        if ($question !== '' && count($options) >= 2 && $end_date !== '') {
            $options_json = json_encode(array_values($options));
            $stmt = $conn->prepare(
                "INSERT INTO tbl_poll (society_id, question, options_json, end_date, created_by)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('isssi', $society_id, $question, $options_json, $end_date, $admin_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_success'] = 'Poll created successfully.';
        } else {
            $_SESSION['flash_error'] = 'Question, at least 2 options, and end date are required.';
        }
    } elseif ($action === 'delete_poll') {
        $poll_id = intval($_POST['poll_id'] ?? 0);
        if ($poll_id > 0) {
            $stmt = $conn->prepare("DELETE FROM tbl_poll WHERE id = ? AND society_id = ?");
            $stmt->bind_param('ii', $poll_id, $society_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_success'] = 'Poll deleted.';
        }
    }

    header('Location: notices');
    exit;
}

// Get towers for dropdown
$stmt = $conn->prepare("SELECT id, name FROM tbl_tower WHERE society_id = ? ORDER BY name");
$stmt->bind_param('i', $society_id);
$stmt->execute();
$towers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get notices
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;

$stmt = $conn->prepare(
    "SELECT n.*, tw.name as tower_name, a.name as posted_by_name
     FROM tbl_notice n
     LEFT JOIN tbl_tower tw ON n.tower_id = tw.id
     LEFT JOIN tbl_admin a ON n.posted_by = a.id
     WHERE n.society_id = ?
     ORDER BY n.created_at DESC
     LIMIT ? OFFSET ?"
);
$stmt->bind_param('iii', $society_id, $per_page, $offset);
$stmt->execute();
$notices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM tbl_notice WHERE society_id = ?");
$stmt->bind_param('i', $society_id);
$stmt->execute();
$total_notices = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();
$total_pages = max(1, ceil($total_notices / $per_page));

// Get polls with vote counts
$stmt = $conn->prepare(
    "SELECT p.*, (SELECT COUNT(*) FROM tbl_poll_vote WHERE poll_id = p.id) as total_votes
     FROM tbl_poll p
     WHERE p.society_id = ?
     ORDER BY p.created_at DESC"
);
$stmt->bind_param('i', $society_id);
$stmt->execute();
$polls = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get vote counts per option for each poll
foreach ($polls as &$poll) {
    $poll['options'] = json_decode($poll['options_json'], true) ?: [];
    $poll['votes'] = [];
    $stmt = $conn->prepare("SELECT option_index, COUNT(*) as cnt FROM tbl_poll_vote WHERE poll_id = ? GROUP BY option_index");
    $stmt->bind_param('i', $poll['id']);
    $stmt->execute();
    $vote_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($vote_result as $v) {
        $poll['votes'][$v['option_index']] = $v['cnt'];
    }
}
unset($poll);

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="fas fa-bullhorn me-2"></i>Notices & Polls</h4>
    <div>
        <button class="btn btn-primary btn-sm me-1" data-bs-toggle="modal" data-bs-target="#addNoticeModal">
            <i class="fas fa-plus me-1"></i>Add Notice
        </button>
        <button class="btn btn-info btn-sm text-white" data-bs-toggle="modal" data-bs-target="#addPollModal">
            <i class="fas fa-poll me-1"></i>Create Poll
        </button>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card table-card">
            <div class="card-header"><h6 class="mb-0">Notices</h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Type</th>
                                <th>Tower</th>
                                <th>Date</th>
                                <th>Active</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($notices)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">No notices</td></tr>
                            <?php else: ?>
                                <?php foreach ($notices as $n): ?>
                                    <tr>
                                        <td class="text-truncate" style="max-width:200px"><?php echo htmlspecialchars($n['title']); ?></td>
                                        <td><span class="badge bg-secondary"><?php echo ucfirst($n['type']); ?></span></td>
                                        <td><?php echo $n['tower_name'] ? htmlspecialchars($n['tower_name']) : 'All'; ?></td>
                                        <td><?php echo formatDate($n['created_at']); ?></td>
                                        <td><?php echo $n['is_active'] ? '<i class="fas fa-check-circle text-success"></i>' : '<i class="fas fa-times-circle text-muted"></i>'; ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary edit-notice"
                                                    data-id="<?php echo $n['id']; ?>"
                                                    data-title="<?php echo htmlspecialchars($n['title']); ?>"
                                                    data-content="<?php echo htmlspecialchars($n['content']); ?>"
                                                    data-type="<?php echo $n['type']; ?>"
                                                    data-tower="<?php echo $n['tower_id'] ?? ''; ?>"
                                                    data-active="<?php echo $n['is_active']; ?>">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="delete_notice">
                                                <input type="hidden" name="notice_id" value="<?php echo $n['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                                        onclick="return confirm('Delete this notice?')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($total_pages > 1): ?>
                <div class="card-footer">
                    <nav>
                        <ul class="pagination pagination-sm justify-content-center mb-0">
                            <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                                <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $p; ?>"><?php echo $p; ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card table-card">
            <div class="card-header"><h6 class="mb-0">Polls</h6></div>
            <div class="card-body">
                <?php if (empty($polls)): ?>
                    <p class="text-muted text-center">No polls created yet.</p>
                <?php else: ?>
                    <?php foreach ($polls as $poll): ?>
                        <div class="border rounded p-3 mb-3">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <strong><?php echo htmlspecialchars($poll['question']); ?></strong>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="delete_poll">
                                    <input type="hidden" name="poll_id" value="<?php echo $poll['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-link text-danger p-0"
                                            onclick="return confirm('Delete this poll?')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                            <small class="text-muted">Ends: <?php echo formatDate($poll['end_date']); ?> | Votes: <?php echo $poll['total_votes']; ?></small>
                            <?php if ($poll['total_votes'] > 0): ?>
                                <div class="mt-2">
                                    <?php foreach ($poll['options'] as $idx => $opt): ?>
                                        <?php
                                        $vote_count = $poll['votes'][$idx] ?? 0;
                                        $pct = $poll['total_votes'] > 0 ? round(($vote_count / $poll['total_votes']) * 100) : 0;
                                        ?>
                                        <div class="mb-1">
                                            <div class="d-flex justify-content-between small">
                                                <span><?php echo htmlspecialchars($opt); ?></span>
                                                <span><?php echo $vote_count; ?> (<?php echo $pct; ?>%)</span>
                                            </div>
                                            <div class="progress" style="height:6px">
                                                <div class="progress-bar" style="width:<?php echo $pct; ?>%"></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="mt-2">
                                    <?php foreach ($poll['options'] as $opt): ?>
                                        <div class="small text-muted">- <?php echo htmlspecialchars($opt); ?></div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Add Notice Modal -->
<div class="modal fade" id="addNoticeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_notice">
                <div class="modal-header">
                    <h5 class="modal-title">Add Notice</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Title</label>
                        <input type="text" name="title" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Content</label>
                        <textarea name="content" class="form-control" rows="5" required></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Type</label>
                            <select name="type" class="form-select">
                                <option value="general">General</option>
                                <option value="emergency">Emergency</option>
                                <option value="event">Event</option>
                                <option value="maintenance">Maintenance</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Tower (optional)</label>
                            <select name="tower_id" class="form-select">
                                <option value="">All Towers</option>
                                <?php foreach ($towers as $t): ?>
                                    <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Expires At</label>
                            <input type="datetime-local" name="expires_at" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Post Notice</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Notice Modal -->
<div class="modal fade" id="editNoticeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="edit_notice">
                <input type="hidden" name="notice_id" id="edit_notice_id">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Notice</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Title</label>
                        <input type="text" name="title" id="edit_notice_title" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Content</label>
                        <textarea name="content" id="edit_notice_content" class="form-control" rows="5" required></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Type</label>
                            <select name="type" id="edit_notice_type" class="form-select">
                                <option value="general">General</option>
                                <option value="emergency">Emergency</option>
                                <option value="event">Event</option>
                                <option value="maintenance">Maintenance</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Tower</label>
                            <select name="tower_id" id="edit_notice_tower" class="form-select">
                                <option value="">All Towers</option>
                                <?php foreach ($towers as $t): ?>
                                    <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" name="is_active" id="edit_notice_active" class="form-check-input" value="1">
                                <label class="form-check-label">Active</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Poll Modal -->
<div class="modal fade" id="addPollModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_poll">
                <div class="modal-header">
                    <h5 class="modal-title">Create Poll</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Question</label>
                        <input type="text" name="question" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Options</label>
                        <div id="pollOptions">
                            <div class="input-group mb-2">
                                <input type="text" name="options[]" class="form-control" placeholder="Option 1" required>
                            </div>
                            <div class="input-group mb-2">
                                <input type="text" name="options[]" class="form-control" placeholder="Option 2" required>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="addOptionBtn">
                            <i class="fas fa-plus me-1"></i>Add Option
                        </button>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">End Date</label>
                        <input type="datetime-local" name="end_date" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Poll</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
<script>
$(document).ready(function() {
    var optCount = 2;
    $('#addOptionBtn').on('click', function() {
        optCount++;
        $('#pollOptions').append(
            '<div class="input-group mb-2">' +
            '<input type="text" name="options[]" class="form-control" placeholder="Option ' + optCount + '">' +
            '<button type="button" class="btn btn-outline-danger remove-option"><i class="fas fa-times"></i></button>' +
            '</div>'
        );
    });

    $(document).on('click', '.remove-option', function() {
        $(this).closest('.input-group').remove();
    });

    $('.edit-notice').on('click', function() {
        $('#edit_notice_id').val($(this).data('id'));
        $('#edit_notice_title').val($(this).data('title'));
        $('#edit_notice_content').val($(this).data('content'));
        $('#edit_notice_type').val($(this).data('type'));
        $('#edit_notice_tower').val($(this).data('tower'));
        if ($(this).data('active') == 1) {
            $('#edit_notice_active').prop('checked', true);
        } else {
            $('#edit_notice_active').prop('checked', false);
        }
        new bootstrap.Modal($('#editNoticeModal')).show();
    });
});
</script>
