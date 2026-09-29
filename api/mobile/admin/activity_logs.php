<?php

require_once __DIR__ . '/../_bootstrap.php';

try {
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    if (!mobileApiUserHasRole($user, 'admin')) {
        mobileApiJson(403, 'error', 'Forbidden.');
    }
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $from = trim((string)($_GET['from'] ?? ''));
        $to = trim((string)($_GET['to'] ?? ''));
        $userIdFilter = (int)($_GET['user_id'] ?? 0);
        $actionFilter = trim((string)($_GET['action'] ?? ''));
        $search = trim((string)($_GET['search'] ?? ''));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [];
        if ($from !== '') {
            $where[] = 'DATE(a.created_at) >= :from';
            $params[':from'] = $from;
        }
        if ($to !== '') {
            $where[] = 'DATE(a.created_at) <= :to';
            $params[':to'] = $to;
        }
        if ($userIdFilter > 0) {
            $where[] = 'a.user_id = :user_id';
            $params[':user_id'] = $userIdFilter;
        }
        if ($actionFilter !== '') {
            $where[] = 'a.action = :action';
            $params[':action'] = $actionFilter;
        }
        if ($search !== '') {
            $where[] = '(a.description LIKE :search OR a.entity_type LIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $countStmt = $db->prepare('SELECT COUNT(*) FROM activity_log a ' . $whereSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $total = (int)$countStmt->fetchColumn();

        $sql = 'SELECT a.*, u.full_name, u.account_number, l.location_label AS lookup_location
            FROM activity_log a
            LEFT JOIN users u ON a.user_id = u.id
            LEFT JOIN activity_ip_lookup l ON l.ip_address = a.ip_address
            ' . $whereSql . '
            ORDER BY a.created_at DESC
            LIMIT :limit OFFSET :offset';
        $stmt = $db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        mobileApiJson(200, 'success', 'Activity logs loaded.', [
            'logs' => $logs,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'has_more' => ($offset + count($logs)) < $total,
            ],
        ]);
    }

    if ($method === 'POST') {
        $data = mobileApiReadJson();
        $action = trim((string)($data['action'] ?? ''));
        if ($action !== 'delete_selected') {
            mobileApiJson(422, 'error', 'Unsupported activity log action.');
        }

        $ids = array_values(array_filter(array_map('intval', (array)($data['log_ids'] ?? [])), static function (int $id): bool {
            return $id > 0;
        }));
        if (empty($ids)) {
            mobileApiJson(422, 'error', 'Select at least one activity log entry to delete.');
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("DELETE FROM activity_log WHERE id IN ({$placeholders})");
        foreach ($ids as $index => $id) {
            $stmt->bindValue($index + 1, $id, PDO::PARAM_INT);
        }
        $stmt->execute();
        mobileApiJson(200, 'success', 'Selected activity log entries deleted.', ['deleted' => (int)$stmt->rowCount()]);
    }

    mobileApiJson(405, 'error', 'Method not allowed.');
} catch (Throwable $e) {
    error_log('Mobile API admin activity logs failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to process activity logs right now.');
}