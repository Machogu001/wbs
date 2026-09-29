<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/Blog.php';
require_once __DIR__ . '/../../../includes/ActivityLog.php';

try {
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    $role = strtolower((string)($user['role'] ?? 'customer'));
    if (!in_array($role, ['admin', 'reader', 'finance', 'support'], true)) {
        mobileApiJson(403, 'error', 'Forbidden.');
    }
    $canWrite = in_array($role, ['admin', 'support'], true);
    $blog = new Blog($db);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $editId = (int)($_GET['edit'] ?? 0);
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
        $posts = $blog->getAllPosts($limit);
        $payloadPosts = array_map(static function (array $post) use ($blog): array {
            $post['attachments'] = $blog->getAttachments((int)$post['id']);
            return $post;
        }, $posts);
        mobileApiJson(200, 'success', 'Blog management data loaded.', [
            'can_write' => $canWrite,
            'posts' => $payloadPosts,
            'pending_comments' => $blog->getPendingComments(),
            'edit_post' => $editId > 0 ? $blog->getPostById($editId) : null,
        ]);
    }

    if ($method === 'POST') {
        if (!$canWrite) {
            mobileApiJson(403, 'error', 'Forbidden.');
        }
        $data = mobileApiReadJson();
        $action = trim((string)($data['action'] ?? ''));
        $logger = new ActivityLog($db);

        if ($action === 'save_post') {
            $postId = (int)($data['post_id'] ?? 0);
            $title = trim((string)($data['title'] ?? ''));
            $body = trim((string)($data['body'] ?? ''));
            $status = in_array((string)($data['status'] ?? ''), ['draft', 'published'], true) ? (string)$data['status'] : 'draft';
            $excerpt = trim((string)($data['excerpt'] ?? ''));
            $coverImage = trim((string)($data['cover_image'] ?? ''));
            if ($title === '' || $body === '') {
                mobileApiJson(422, 'error', 'Title and body are required.');
            }
            if ($postId > 0) {
                $blog->updatePost($postId, $title, $body, $status, $excerpt, $coverImage);
                $logger->log((int)$user['id'], 'update_blog_post', 'blog_post', $postId, 'Updated: ' . $title);
                mobileApiJson(200, 'success', 'Post updated.');
            }
            $newPostId = $blog->createPost((int)$user['id'], $title, $body, $status, $excerpt, $coverImage);
            $logger->log((int)$user['id'], 'create_blog_post', 'blog_post', $newPostId, 'Created: ' . $title);
            mobileApiJson(201, 'success', 'Post created.', ['post_id' => $newPostId]);
        }

        if ($action === 'delete_post') {
            $postId = (int)($data['post_id'] ?? 0);
            if ($postId <= 0) {
                mobileApiJson(422, 'error', 'A valid post ID is required.');
            }
            $blog->deletePost($postId);
            $logger->log((int)$user['id'], 'delete_blog_post', 'blog_post', $postId, 'Deleted post #' . $postId);
            mobileApiJson(200, 'success', 'Post deleted.');
        }

        if ($action === 'comment_status') {
            $commentId = (int)($data['comment_id'] ?? 0);
            $newStatus = trim((string)($data['status'] ?? ''));
            if ($commentId <= 0 || !in_array($newStatus, ['approved', 'rejected'], true)) {
                mobileApiJson(422, 'error', 'A valid comment ID and status are required.');
            }
            $blog->updateCommentStatus($commentId, $newStatus);
            mobileApiJson(200, 'success', 'Comment updated.');
        }

        if ($action === 'delete_comment') {
            $commentId = (int)($data['comment_id'] ?? 0);
            if ($commentId <= 0) {
                mobileApiJson(422, 'error', 'A valid comment ID is required.');
            }
            $blog->deleteComment($commentId);
            mobileApiJson(200, 'success', 'Comment deleted.');
        }

        mobileApiJson(422, 'error', 'Unsupported blog action.');
    }

    mobileApiJson(405, 'error', 'Method not allowed.');
} catch (Throwable $e) {
    error_log('Mobile API admin blog failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to process blog management right now.');
}