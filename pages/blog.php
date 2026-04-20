<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Blog.php';

$database = new Database();
$db = $database->getConnection();
$blog = new Blog($db);

$isLoggedIn = !empty($_SESSION['user_id']);
$currentRole = strtolower((string)($_SESSION['user_data']['role'] ?? 'customer'));
$isStaff     = in_array($currentRole, ['admin', 'reader', 'finance', 'support'], true);
$currentUserId = $isLoggedIn ? (int)$_SESSION['user_id'] : null;

// -------------------------------------------------------------------------
// Handle comment submission (POST)
// -------------------------------------------------------------------------
$commentError   = null;
$commentSuccess = null;

// Ensure a CSRF token exists for the comment form (even for guests, tied to the session)
if (empty($_SESSION['blog_comment_csrf'])) {
    $_SESSION['blog_comment_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'comment') {
    // CSRF validation
    if (!hash_equals($_SESSION['blog_comment_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
        $commentError = 'Security validation failed. Please refresh and try again.';
    } else {
    $postId   = (int)($_POST['post_id'] ?? 0);
    $body     = trim($_POST['body'] ?? '');
    $guestName = trim($_POST['guest_name'] ?? '');

    if ($postId <= 0 || $body === '') {
        $commentError = 'Comment cannot be empty.';
    } elseif (!$isLoggedIn && $guestName === '') {
        $commentError = 'Please enter your name.';
    } elseif (mb_strlen($body) > 2000) {
        $commentError = 'Comment is too long (max 2000 characters).';
    } else {
        $blog->addComment($postId, $body, $currentUserId, $guestName);
        $commentSuccess = $isLoggedIn
            ? 'Your comment has been posted.'
            : 'Your comment has been submitted and is awaiting approval.';
        // Redirect back to the post to prevent form resubmission
        $post = $blog->getPostById($postId);
        if ($post) {
            header('Location: /blog?post=' . urlencode($post['slug']) . '#comments');
            exit;
        }
    }
    }
}

// -------------------------------------------------------------------------
// Routing: single post view vs. listing
// -------------------------------------------------------------------------
$slug       = isset($_GET['post']) ? trim($_GET['post']) : '';
$singlePost = null;
$comments   = [];
$attachments = [];

if ($slug !== '') {
    $singlePost = $blog->getPostBySlug($slug);
    if (!$singlePost || ($singlePost['status'] !== 'published' && !$isStaff)) {
        http_response_code(404);
        $singlePost = null;
    } else {
        $comments    = $blog->getApprovedComments((int)$singlePost['id']);
        $attachments = $blog->getAttachments((int)$singlePost['id']);
    }
}

$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 9;
$offset = ($page - 1) * $limit;
$posts  = [];
$total  = 0;

if (!$singlePost) {
    $posts = $blog->getPublishedPosts($limit, $offset);
    $total = $blog->countPublishedPosts();
}

$totalPages = $total > 0 ? (int)ceil($total / $limit) : 1;

$page_title = $singlePost ? htmlspecialchars($singlePost['title'], ENT_QUOTES, 'UTF-8') : 'News & Updates';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="container mt-4 blog-page admin-shell">

<?php if ($singlePost): ?>
<!-- =========================================================
     SINGLE POST VIEW
     ========================================================= -->
<div class="pb-banner mb-4">
    <div class="pb-bg" aria-hidden="true">
        <div class="pb-grid"></div>
        <div class="pb-blob pb-blob--a"></div>
        <div class="pb-blob pb-blob--b"></div>
        <i class="bi bi-newspaper pb-watermark"></i>
    </div>
    <div class="pb-inner">
        <div class="pb-left">
            <div class="pb-eyebrow-row">
                <span class="pb-eyebrow-chip"><a href="/blog" class="text-white text-decoration-none"><i class="bi bi-arrow-left me-1"></i>News &amp; Updates</a></span>
            </div>
            <h2 class="pb-title"><?php echo htmlspecialchars($singlePost['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
            <p class="pb-subtitle">
                <i class="bi bi-person-circle me-1"></i><?php echo htmlspecialchars($singlePost['author_name'] ?? 'Staff', ENT_QUOTES, 'UTF-8'); ?>
                &nbsp;&bull;&nbsp;
                <i class="bi bi-calendar3 me-1"></i><?php echo $singlePost['published_at'] ? date('F j, Y', strtotime($singlePost['published_at'])) : 'Draft'; ?>
                &nbsp;&bull;&nbsp;
                <i class="bi bi-chat-dots me-1"></i><?php echo count($comments); ?> comment<?php echo count($comments) !== 1 ? 's' : ''; ?>
            </p>
        </div>
        <?php if ($isStaff): ?>
        <div class="pb-right">
            <div class="pb-btn-row">
                <a href="/admin/news-updates?edit=<?php echo (int)$singlePost['id']; ?>" class="pb-btn"><i class="bi bi-pencil me-1"></i>Edit Post</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="row justify-content-center mt-4">
        <div class="col-lg-8">
            <?php if ($singlePost['status'] === 'draft'): ?>
            <div class="alert alert-warning"><i class="bi bi-eye-slash me-2"></i>This post is a draft — only staff can see it.</div>
            <?php endif; ?>

            <?php if (!empty($singlePost['cover_image'])): ?>
            <img src="<?php echo htmlspecialchars($singlePost['cover_image'], ENT_QUOTES, 'UTF-8'); ?>"
                 alt="Cover" class="img-fluid rounded mb-4 w-100" style="max-height:420px;object-fit:cover;">
            <?php endif; ?>

            <div class="blog-post-body card card-body border-0 shadow-sm p-4 mb-4">
                <?php echo nl2br(htmlspecialchars($singlePost['body'], ENT_QUOTES, 'UTF-8')); ?>
            </div>

            <?php if (!empty($attachments)): ?>
            <?php
                $attVideos = array_filter($attachments, fn($a) => $a['file_type'] === 'video');
                $attImages = array_filter($attachments, fn($a) => $a['file_type'] === 'image');
                $attDocs   = array_filter($attachments, fn($a) => $a['file_type'] === 'document');
            ?>

            <?php if (!empty($attVideos)): ?>
            <div class="mb-4">
                <h5 class="fw-semibold mb-3"><i class="bi bi-camera-video me-2"></i>Videos</h5>
                <?php foreach ($attVideos as $v): ?>
                <div class="mb-3">
                    <video controls class="w-100 rounded shadow-sm" style="max-height:480px;background:#000;"
                           preload="metadata">
                        <source src="/<?php echo htmlspecialchars($v['file_path'], ENT_QUOTES, 'UTF-8'); ?>"
                                type="<?php echo htmlspecialchars($v['mime_type'], ENT_QUOTES, 'UTF-8'); ?>">
                        Your browser does not support the video tag.
                    </video>
                    <div class="small text-muted mt-1"><?php echo htmlspecialchars($v['file_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($attImages)): ?>
            <div class="mb-4">
                <h5 class="fw-semibold mb-3"><i class="bi bi-images me-2"></i>Images</h5>
                <div class="row g-2">
                <?php foreach ($attImages as $img): ?>
                    <div class="col-6 col-md-4">
                        <a href="/<?php echo htmlspecialchars($img['file_path'], ENT_QUOTES, 'UTF-8'); ?>"
                           target="_blank" rel="noopener">
                            <img src="/<?php echo htmlspecialchars($img['file_path'], ENT_QUOTES, 'UTF-8'); ?>"
                                 alt="<?php echo htmlspecialchars($img['file_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                 class="img-fluid rounded shadow-sm w-100" style="height:160px;object-fit:cover;">
                        </a>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($attDocs)): ?>
            <div class="mb-4">
                <h5 class="fw-semibold mb-3"><i class="bi bi-paperclip me-2"></i>Documents</h5>
                <div class="list-group">
                <?php foreach ($attDocs as $doc): ?>
                    <a href="/<?php echo htmlspecialchars($doc['file_path'], ENT_QUOTES, 'UTF-8'); ?>"
                       target="_blank" rel="noopener"
                       class="list-group-item list-group-item-action d-flex align-items-center gap-3">
                        <i class="bi bi-file-earmark-text fs-4 text-secondary flex-shrink-0"></i>
                        <div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($doc['file_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="small text-muted"><?php echo round($doc['file_size'] / 1024, 1); ?> KB</div>
                        </div>
                        <i class="bi bi-download ms-auto text-muted"></i>
                    </a>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php endif; /* end attachments */ ?>

            <!-- Comments -->
            <div id="comments">
                <h4 class="mb-3"><i class="bi bi-chat-square-text me-2"></i>Comments (<?php echo count($comments); ?>)</h4>

                <?php if ($commentSuccess): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($commentSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>

                <?php if (empty($comments)): ?>
                <p class="text-muted">No comments yet. Be the first to comment!</p>
                <?php else: ?>
                <?php foreach ($comments as $c): ?>
                <div class="d-flex gap-3 mb-3">
                    <div class="flex-shrink-0">
                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center"
                             style="width:40px;height:40px;font-size:.9rem;font-weight:600;">
                            <?php
                                $dname = $c['user_name'] ?? $c['guest_name'] ?? 'Guest';
                                echo htmlspecialchars(mb_strtoupper(mb_substr($dname, 0, 1)), ENT_QUOTES, 'UTF-8');
                            ?>
                        </div>
                    </div>
                    <div class="flex-grow-1">
                        <div class="card card-body py-2 px-3 bg-light border-0">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <strong class="small"><?php echo htmlspecialchars($c['user_name'] ?? $c['guest_name'] ?? 'Guest', ENT_QUOTES, 'UTF-8'); ?></strong>
                                <small class="text-muted"><?php echo date('M j, Y', strtotime($c['created_at'])); ?></small>
                            </div>
                            <p class="mb-0 small"><?php echo nl2br(htmlspecialchars($c['body'], ENT_QUOTES, 'UTF-8')); ?></p>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <!-- Comment form -->
                <div class="card shadow-sm border-0 mt-4">
                    <div class="card-header"><h6 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Leave a Comment</h6></div>
                    <div class="card-body">
                        <?php if ($commentError): ?>
                        <div class="alert alert-danger py-2"><?php echo htmlspecialchars($commentError, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                        <form method="POST" action="/blog#comments">
                            <input type="hidden" name="action" value="comment">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['blog_comment_csrf']); ?>">
                            <input type="hidden" name="post_id" value="<?php echo (int)$singlePost['id']; ?>">
                            <?php if (!$isLoggedIn): ?>
                            <div class="mb-3">
                                <label class="form-label">Your Name <span class="text-danger">*</span></label>
                                <input type="text" name="guest_name" class="form-control" maxlength="120" required
                                       value="<?php echo htmlspecialchars($_POST['guest_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <?php endif; ?>
                            <div class="mb-3">
                                <label class="form-label">Comment <span class="text-danger">*</span></label>
                                <textarea name="body" class="form-control" rows="4" maxlength="2000" required
                                          placeholder="Write your comment here..."><?php echo htmlspecialchars($_POST['body'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-send me-1"></i><?php echo $isLoggedIn ? 'Post Comment' : 'Submit (pending approval)'; ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="mt-4">
                <a href="/blog" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to News</a>
            </div>

        </div>
    </div>

<?php else: ?>
<!-- =========================================================
     POST LISTING VIEW
     ========================================================= -->
<div class="pb-banner mb-4">
    <div class="pb-bg" aria-hidden="true">
        <div class="pb-grid"></div>
        <div class="pb-blob pb-blob--a"></div>
        <div class="pb-blob pb-blob--b"></div>
        <i class="bi bi-newspaper pb-watermark"></i>
    </div>
    <div class="pb-inner">
        <div class="pb-left">
            <div class="pb-eyebrow-row">
                <span class="pb-eyebrow-chip"><i class="bi bi-newspaper"></i> Latest Posts</span>
            </div>
            <h2 class="pb-title">News &amp; Updates</h2>
            <p class="pb-subtitle">The latest news, announcements and tips from our team.</p>
        </div>
        <?php if ($isStaff): ?>
        <div class="pb-right">
            <div class="pb-btn-row">
                <a href="/admin/news-updates" class="pb-btn"><i class="bi bi-pencil-square me-1"></i>Manage Posts</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="mt-4">

    <?php if (empty($posts)): ?>
    <div class="text-center py-5">
        <i class="bi bi-newspaper display-4 text-muted"></i>
        <p class="mt-3 text-muted">No posts published yet. Check back soon!</p>
    </div>
    <?php else: ?>
    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
        <?php foreach ($posts as $p): ?>
        <div class="col">
            <a href="/blog?post=<?php echo urlencode($p['slug']); ?>" class="text-decoration-none text-reset">
                <div class="card h-100 shadow-sm border-0 blog-card">
                    <?php if (!empty($p['cover_image'])): ?>
                    <img src="<?php echo htmlspecialchars($p['cover_image'], ENT_QUOTES, 'UTF-8'); ?>"
                         class="card-img-top" alt="Cover" style="height:180px;object-fit:cover;">
                    <?php else: ?>
                    <div class="card-img-top bg-primary d-flex align-items-center justify-content-center" style="height:180px;">
                        <i class="bi bi-newspaper text-white" style="font-size:3rem;"></i>
                    </div>
                    <?php endif; ?>
                    <div class="card-body">
                        <h5 class="card-title fw-semibold"><?php echo htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8'); ?></h5>
                        <p class="card-text text-muted small"><?php echo htmlspecialchars(mb_substr($p['excerpt'] ?? '', 0, 120), ENT_QUOTES, 'UTF-8'); ?>…</p>
                    </div>
                    <div class="card-footer bg-transparent border-0 d-flex justify-content-between align-items-center small text-muted">
                        <span><i class="bi bi-person me-1"></i><?php echo htmlspecialchars($p['author_name'] ?? 'Staff', ENT_QUOTES, 'UTF-8'); ?></span>
                        <span><i class="bi bi-calendar3 me-1"></i><?php echo $p['published_at'] ? date('M j, Y', strtotime($p['published_at'])) : '—'; ?></span>
                    </div>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <nav class="mt-4" aria-label="Blog pagination">
        <ul class="pagination justify-content-center">
            <?php if ($page > 1): ?>
            <li class="page-item"><a class="page-link" href="/blog?page=<?php echo $page - 1; ?>">&#8249; Prev</a></li>
            <?php endif; ?>
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                <a class="page-link" href="/blog?page=<?php echo $i; ?>"><?php echo $i; ?></a>
            </li>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <li class="page-item"><a class="page-link" href="/blog?page=<?php echo $page + 1; ?>">Next &#8250;</a></li>
            <?php endif; ?>
        </ul>
    </nav>
    <?php endif; ?>

    <?php endif; ?>
</div>

<?php endif; ?>

</div><!-- /.admin-shell container -->

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
