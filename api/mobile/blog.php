<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/Blog.php';

try {
    mobileApiRequireMethod('GET');
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    $blog = new Blog($db);

    $slug = trim((string)($_GET['post'] ?? ''));
    if ($slug !== '') {
        $post = $blog->getPostBySlug($slug);
        if (!$post || (string)($post['status'] ?? '') !== 'published') {
            mobileApiJson(404, 'error', 'Post not found.');
        }
        $postId = (int)($post['id'] ?? 0);
        mobileApiJson(200, 'success', 'Post loaded.', [
            'post' => $post,
            'comments' => $blog->getApprovedComments($postId),
            'attachments' => $blog->getAttachments($postId),
            'read_only' => true,
        ]);
    }

    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 9)));
    $offset = ($page - 1) * $limit;
    $total = $blog->countPublishedPosts();
    mobileApiJson(200, 'success', 'News posts loaded.', [
        'posts' => $blog->getPublishedPosts($limit, $offset),
        'read_only' => true,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'has_next_page' => ($page * $limit) < $total,
            'has_previous_page' => $page > 1,
        ],
    ]);
} catch (Throwable $e) {
    error_log('Mobile API blog failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to load news right now.');
}
