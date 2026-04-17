<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Blog.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';

$database = new Database();
$db       = $database->getConnection();
$auth     = new Auth($db);

if (!$auth->isLoggedIn()) {
    header('Location: /login');
    exit;
}

$currentRole = strtolower((string)($_SESSION['user_data']['role'] ?? 'customer'));
$isStaff     = in_array($currentRole, ['admin', 'reader', 'finance', 'support'], true);
$canWrite    = in_array($currentRole, ['admin', 'support'], true);

if (!$isStaff) {
    header('Location: /dashboard');
    exit;
}

$is_admin_page = true;
$blog = new Blog($db);

// -------------------------------------------------------------------------
// Flash message helpers
// -------------------------------------------------------------------------
$flash     = $_SESSION['flash_message'] ?? null;
$flashType = $_SESSION['flash_type'] ?? 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

function blogFlash(string $msg, string $type = 'success'): void {
    $_SESSION['flash_message'] = $msg;
    $_SESSION['flash_type']    = $type;
}

// -------------------------------------------------------------------------
// Handle POST actions
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canWrite) {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_post') {
        $postId   = (int)($_POST['post_id'] ?? 0);
        $title    = trim($_POST['title'] ?? '');
        $body     = trim($_POST['body'] ?? '');
        $status   = in_array($_POST['status'] ?? '', ['draft','published']) ? $_POST['status'] : 'draft';
        $excerpt  = trim($_POST['excerpt'] ?? '');
        $coverImg = trim($_POST['cover_image'] ?? '');

        if ($title === '' || $body === '') {
            blogFlash('Title and body are required.', 'danger');
        } elseif ($postId > 0) {
            $blog->updatePost($postId, $title, $body, $status, $excerpt, $coverImg);
            (new ActivityLog($db))->log($_SESSION['user_id'], 'update_blog_post', 'blog_post', $postId, 'Updated: ' . $title);
            blogFlash('Post updated.');
        } else {
            $newId = $blog->createPost((int)$_SESSION['user_id'], $title, $body, $status, $excerpt, $coverImg);
            (new ActivityLog($db))->log($_SESSION['user_id'], 'create_blog_post', 'blog_post', $newId, 'Created: ' . $title);
            blogFlash('Post created.');
        }
        header('Location: /admin/blog');
        exit;

    } elseif ($action === 'delete_post') {
        $postId = (int)($_POST['post_id'] ?? 0);
        if ($postId > 0) {
            $blog->deletePost($postId);
            (new ActivityLog($db))->log($_SESSION['user_id'], 'delete_blog_post', 'blog_post', $postId, 'Deleted post #' . $postId);
            blogFlash('Post deleted.');
        }
        header('Location: /admin/blog');
        exit;

    } elseif ($action === 'comment_status') {
        $commentId = (int)($_POST['comment_id'] ?? 0);
        $newStatus = $_POST['status'] ?? '';
        if ($commentId > 0 && in_array($newStatus, ['approved','rejected'], true)) {
            $blog->updateCommentStatus($commentId, $newStatus);
            blogFlash('Comment ' . $newStatus . '.');
        }
        header('Location: /admin/blog#comments');
        exit;

    } elseif ($action === 'delete_comment') {
        $commentId = (int)($_POST['comment_id'] ?? 0);
        if ($commentId > 0) {
            $blog->deleteComment($commentId);
            blogFlash('Comment deleted.');
        }
        header('Location: /admin/blog#comments');
        exit;
    }
}

// -------------------------------------------------------------------------
// Load data for page
// -------------------------------------------------------------------------
$editPost = null;
if (isset($_GET['edit'])) {
    $editPost = $blog->getPostById((int)$_GET['edit']);
}

$posts           = $blog->getAllPosts(50);
$pendingComments = $blog->getPendingComments();

$page_title = 'Blog Management';
$is_admin_page = true;
include __DIR__ . '/../../templates/header.php';
?>

<div class="container-fluid mt-4 admin-shell">
    <div class="pb-banner pb-banner--cobalt mb-4">
        <div class="pb-bg" aria-hidden="true">
            <div class="pb-grid"></div>
            <div class="pb-blob pb-blob--a"></div>
            <div class="pb-blob pb-blob--b"></div>
            <i class="bi bi-newspaper pb-watermark"></i>
        </div>
        <div class="pb-inner">
            <div class="pb-left">
                <div class="pb-eyebrow-row">
                    <span class="pb-eyebrow-chip"><i class="bi bi-newspaper"></i> Content Management</span>
                </div>
                <h2 class="pb-title">Blog Management</h2>
                <p class="pb-subtitle">Create, edit, and moderate blog posts and comments.</p>
            </div>
        </div>
    </div>

<div class="container-fluid px-3">

<?php if ($flash): ?>
<div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show">
    <?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($canWrite): ?>
<!-- ===== New / Edit Post Form ===== -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><?php echo $editPost ? '<i class="bi bi-pencil me-2"></i>Edit Post' : '<i class="bi bi-plus-circle me-2"></i>New Post'; ?></h5>
        <?php if ($editPost): ?>
        <a href="/admin/blog" class="btn btn-sm btn-outline-secondary">Cancel Edit</a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <form method="POST" action="/admin/blog">
            <input type="hidden" name="action" value="save_post">
            <?php if ($editPost): ?>
            <input type="hidden" name="post_id" value="<?php echo (int)$editPost['id']; ?>">
            <?php endif; ?>
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control" maxlength="255" required
                           value="<?php echo htmlspecialchars($editPost['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Body <span class="text-danger">*</span></label>
                    <textarea name="body" class="form-control" rows="10" required><?php echo htmlspecialchars($editPost['body'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div class="col-md-8">
                    <label class="form-label fw-semibold">Excerpt <small class="text-muted">(auto-generated if blank)</small></label>
                    <input type="text" name="excerpt" class="form-control" maxlength="300"
                           value="<?php echo htmlspecialchars($editPost['excerpt'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Status</label>
                    <select name="status" class="form-select">
                        <option value="draft" <?php echo ($editPost['status'] ?? 'draft') === 'draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="published" <?php echo ($editPost['status'] ?? '') === 'published' ? 'selected' : ''; ?>>Published</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Cover Image URL <small class="text-muted">(optional)</small></label>
                    <input type="url" name="cover_image" class="form-control" maxlength="512"
                           value="<?php echo htmlspecialchars($editPost['cover_image'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                           placeholder="https://...">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i><?php echo $editPost ? 'Update Post' : 'Create Post'; ?>
                    </button>
                    <?php if ($editPost): ?>
                    <a href="/blog?post=<?php echo urlencode($editPost['slug']); ?>" target="_blank" class="btn btn-outline-secondary ms-2">
                        <i class="bi bi-eye me-1"></i>View Post
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ===== All Posts Table ===== -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header"><h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>All Posts (<?php echo count($posts); ?>)</h5></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Title</th>
                    <th>Author</th>
                    <th>Status</th>
                    <th>Published</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($posts)): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">No posts yet.</td></tr>
            <?php else: ?>
            <?php foreach ($posts as $p): ?>
            <tr>
                <td>
                    <a href="/blog?post=<?php echo urlencode($p['slug']); ?>" target="_blank" class="fw-semibold text-decoration-none">
                        <?php echo htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                </td>
                <td class="text-muted small"><?php echo htmlspecialchars($p['author_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                <td>
                    <span class="badge bg-<?php echo $p['status'] === 'published' ? 'success' : 'secondary'; ?>">
                        <?php echo ucfirst($p['status']); ?>
                    </span>
                </td>
                <td class="text-muted small"><?php echo $p['published_at'] ? date('M j, Y', strtotime($p['published_at'])) : '—'; ?></td>
                <td class="text-end">
                    <?php if ($canWrite): ?>
                    <a href="/admin/blog?edit=<?php echo (int)$p['id']; ?>" class="btn btn-sm btn-outline-primary me-1">
                        <i class="bi bi-pencil"></i>
                    </a>
                    <form method="POST" action="/admin/blog" class="d-inline"
                          onsubmit="return confirm('Delete this post and all its comments?')">
                        <input type="hidden" name="action" value="delete_post">
                        <input type="hidden" name="post_id" value="<?php echo (int)$p['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ===== Pending Comments ===== -->
<div class="card shadow-sm border-0" id="comments">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-chat-dots me-2"></i>Pending Comments
            <?php if (count($pendingComments) > 0): ?>
            <span class="badge bg-warning text-dark ms-1"><?php echo count($pendingComments); ?></span>
            <?php endif; ?>
        </h5>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Post</th>
                    <th>Author</th>
                    <th>Comment</th>
                    <th>Date</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($pendingComments)): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">No pending comments.</td></tr>
            <?php else: ?>
            <?php foreach ($pendingComments as $c): ?>
            <tr>
                <td class="small text-muted"><?php echo htmlspecialchars($c['post_title'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="small"><?php echo htmlspecialchars($c['user_name'] ?? $c['guest_name'] ?? 'Guest', ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="small"><?php echo htmlspecialchars(mb_substr($c['body'], 0, 100), ENT_QUOTES, 'UTF-8'); ?><?php echo mb_strlen($c['body']) > 100 ? '…' : ''; ?></td>
                <td class="small text-muted"><?php echo date('M j, Y', strtotime($c['created_at'])); ?></td>
                <td class="text-end">
                    <?php if ($canWrite): ?>
                    <form method="POST" action="/admin/blog#comments" class="d-inline">
                        <input type="hidden" name="action" value="comment_status">
                        <input type="hidden" name="comment_id" value="<?php echo (int)$c['id']; ?>">
                        <input type="hidden" name="status" value="approved">
                        <button type="submit" class="btn btn-sm btn-success me-1"><i class="bi bi-check-lg"></i> Approve</button>
                    </form>
                    <form method="POST" action="/admin/blog#comments" class="d-inline">
                        <input type="hidden" name="action" value="delete_comment">
                        <input type="hidden" name="comment_id" value="<?php echo (int)$c['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div>
</div>

<?php require_once __DIR__ . '/../../templates/footer.php'; ?>
