<?php

class Blog {
    private $conn;
    private $postTable   = 'blog_posts';
    private $commentTable = 'blog_comments';

    public function __construct($db) {
        $this->conn = $db;
        $this->ensureTables();
    }

    // -------------------------------------------------------------------------
    // Schema bootstrap (idempotent)
    // -------------------------------------------------------------------------
    private function ensureTables(): void {
        $this->conn->exec("CREATE TABLE IF NOT EXISTS {$this->postTable} (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            title         VARCHAR(255) NOT NULL,
            slug          VARCHAR(255) NOT NULL,
            body          LONGTEXT NOT NULL,
            excerpt       TEXT NULL,
            cover_image   VARCHAR(512) NULL,
            status        ENUM('draft','published') NOT NULL DEFAULT 'draft',
            author_id     INT NOT NULL,
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at    TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            published_at  TIMESTAMP NULL,
            UNIQUE KEY uq_slug (slug),
            INDEX idx_status_pub (status, published_at),
            INDEX idx_author (author_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->conn->exec("CREATE TABLE IF NOT EXISTS {$this->commentTable} (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            post_id     INT NOT NULL,
            user_id     INT NULL,
            guest_name  VARCHAR(120) NULL,
            body        TEXT NOT NULL,
            status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_post_status (post_id, status),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    // -------------------------------------------------------------------------
    // Posts
    // -------------------------------------------------------------------------
    public function createPost(int $authorId, string $title, string $body, string $status = 'draft', string $excerpt = '', string $coverImage = ''): int {
        $slug = $this->makeUniqueSlug($title);
        $excerpt = trim($excerpt) !== '' ? trim($excerpt) : mb_substr(strip_tags($body), 0, 200);
        $publishedAt = $status === 'published' ? date('Y-m-d H:i:s') : null;

        $stmt = $this->conn->prepare("INSERT INTO {$this->postTable}
            (author_id, title, slug, body, excerpt, cover_image, status, published_at)
            VALUES (:author_id, :title, :slug, :body, :excerpt, :cover_image, :status, :published_at)");
        $stmt->execute([
            ':author_id'   => $authorId,
            ':title'       => trim($title),
            ':slug'        => $slug,
            ':body'        => $body,
            ':excerpt'     => $excerpt,
            ':cover_image' => trim($coverImage),
            ':status'      => $status,
            ':published_at'=> $publishedAt,
        ]);
        return (int)$this->conn->lastInsertId();
    }

    public function updatePost(int $postId, string $title, string $body, string $status, string $excerpt = '', string $coverImage = ''): bool {
        $post = $this->getPostById($postId);
        if (!$post) return false;

        $excerpt = trim($excerpt) !== '' ? trim($excerpt) : mb_substr(strip_tags($body), 0, 200);
        // Set published_at only on first publish transition
        $publishedAt = $post['published_at'];
        if ($status === 'published' && $publishedAt === null) {
            $publishedAt = date('Y-m-d H:i:s');
        }

        // Re-slug only if title changed
        $slug = (trim($title) !== $post['title'])
            ? $this->makeUniqueSlug($title, $postId)
            : $post['slug'];

        $stmt = $this->conn->prepare("UPDATE {$this->postTable}
            SET title=:title, slug=:slug, body=:body, excerpt=:excerpt,
                cover_image=:cover_image, status=:status, published_at=:published_at
            WHERE id=:id");
        return $stmt->execute([
            ':title'        => trim($title),
            ':slug'         => $slug,
            ':body'         => $body,
            ':excerpt'      => $excerpt,
            ':cover_image'  => trim($coverImage),
            ':status'       => $status,
            ':published_at' => $publishedAt,
            ':id'           => $postId,
        ]);
    }

    public function deletePost(int $postId): bool {
        $this->conn->prepare("DELETE FROM {$this->commentTable} WHERE post_id=:id")->execute([':id' => $postId]);
        $stmt = $this->conn->prepare("DELETE FROM {$this->postTable} WHERE id=:id");
        return $stmt->execute([':id' => $postId]);
    }

    public function getPostById(int $postId): ?array {
        $stmt = $this->conn->prepare("SELECT p.*, u.full_name AS author_name, u.account_number AS author_account
            FROM {$this->postTable} p
            LEFT JOIN users u ON u.id = p.author_id
            WHERE p.id = :id LIMIT 1");
        $stmt->execute([':id' => $postId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getPostBySlug(string $slug): ?array {
        $stmt = $this->conn->prepare("SELECT p.*, u.full_name AS author_name
            FROM {$this->postTable} p
            LEFT JOIN users u ON u.id = p.author_id
            WHERE p.slug = :slug LIMIT 1");
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getPublishedPosts(int $limit = 20, int $offset = 0): array {
        $limit  = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $stmt = $this->conn->prepare("SELECT p.*, u.full_name AS author_name
            FROM {$this->postTable} p
            LEFT JOIN users u ON u.id = p.author_id
            WHERE p.status = 'published'
            ORDER BY p.published_at DESC
            LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function countPublishedPosts(): int {
        $stmt = $this->conn->query("SELECT COUNT(*) FROM {$this->postTable} WHERE status='published'");
        return (int)($stmt ? $stmt->fetchColumn() : 0);
    }

    public function getAllPosts(int $limit = 50, int $offset = 0): array {
        $limit  = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $stmt = $this->conn->prepare("SELECT p.*, u.full_name AS author_name
            FROM {$this->postTable} p
            LEFT JOIN users u ON u.id = p.author_id
            ORDER BY p.created_at DESC
            LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // -------------------------------------------------------------------------
    // Comments
    // -------------------------------------------------------------------------
    public function addComment(int $postId, string $body, ?int $userId = null, string $guestName = ''): int {
        // Logged-in users get auto-approved; guests need moderation
        $status = $userId !== null ? 'approved' : 'pending';
        $stmt = $this->conn->prepare("INSERT INTO {$this->commentTable}
            (post_id, user_id, guest_name, body, status)
            VALUES (:post_id, :user_id, :guest_name, :body, :status)");
        $stmt->execute([
            ':post_id'    => $postId,
            ':user_id'    => $userId,
            ':guest_name' => trim($guestName),
            ':body'       => trim($body),
            ':status'     => $status,
        ]);
        return (int)$this->conn->lastInsertId();
    }

    public function getApprovedComments(int $postId): array {
        $stmt = $this->conn->prepare("SELECT c.*, u.full_name AS user_name
            FROM {$this->commentTable} c
            LEFT JOIN users u ON u.id = c.user_id
            WHERE c.post_id = :post_id AND c.status = 'approved'
            ORDER BY c.created_at ASC");
        $stmt->execute([':post_id' => $postId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getAllComments(int $limit = 100): array {
        $limit = max(1, min(500, $limit));
        $stmt = $this->conn->query("SELECT c.*, u.full_name AS user_name, p.title AS post_title
            FROM {$this->commentTable} c
            LEFT JOIN users u ON u.id = c.user_id
            LEFT JOIN {$this->postTable} p ON p.id = c.post_id
            ORDER BY c.created_at DESC
            LIMIT {$limit}");
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    public function getPendingComments(): array {
        $stmt = $this->conn->query("SELECT c.*, u.full_name AS user_name, p.title AS post_title
            FROM {$this->commentTable} c
            LEFT JOIN users u ON u.id = c.user_id
            LEFT JOIN {$this->postTable} p ON p.id = c.post_id
            WHERE c.status = 'pending'
            ORDER BY c.created_at ASC");
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    public function updateCommentStatus(int $commentId, string $status): bool {
        if (!in_array($status, ['approved', 'rejected', 'pending'], true)) return false;
        $stmt = $this->conn->prepare("UPDATE {$this->commentTable} SET status=:status WHERE id=:id");
        return $stmt->execute([':status' => $status, ':id' => $commentId]);
    }

    public function deleteComment(int $commentId): bool {
        $stmt = $this->conn->prepare("DELETE FROM {$this->commentTable} WHERE id=:id");
        return $stmt->execute([':id' => $commentId]);
    }

    public function countCommentsByPost(int $postId): int {
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM {$this->commentTable} WHERE post_id=:id AND status='approved'");
        $stmt->execute([':id' => $postId]);
        return (int)$stmt->fetchColumn();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------
    private function makeUniqueSlug(string $title, int $excludeId = 0): string {
        $base = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($title)));
        $base = trim($base, '-');
        $base = mb_substr($base, 0, 180);
        $slug = $base;
        $i = 2;
        while (true) {
            $stmt = $this->conn->prepare("SELECT id FROM {$this->postTable} WHERE slug=:slug LIMIT 1");
            $stmt->execute([':slug' => $slug]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing || (int)$existing['id'] === $excludeId) break;
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
