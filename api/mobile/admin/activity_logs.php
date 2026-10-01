<?php

require_once __DIR__ . '/../_bootstrap.php';

// Mirrors pages/admin/activity_log.php: the same filters (dates, admin, action, search,
// network owner, ASN) plus a channel filter, and the same derived columns (location,
// network owner, gadget type) so app and website activity look identical.
function mobileActivityMetadata($raw): array
{
    if (is_array($raw)) {
        return $raw;
    }
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function mobileActivityIsGenericLocation(string $label): bool
{
    return in_array(strtolower(trim($label)), ['', 'public network', 'private/local network', 'server/unknown origin', 'unknown'], true);
}

function mobileActivityLocation(string $ip, array $metadata, string $lookupLocation): string
{
    $metaLocation = is_string($metadata['location'] ?? null) ? trim($metadata['location']) : '';
    $lookupLocation = trim($lookupLocation);
    if ($metaLocation !== '' && !mobileActivityIsGenericLocation($metaLocation)) {
        return $metaLocation;
    }
    if ($lookupLocation !== '' && !mobileActivityIsGenericLocation($lookupLocation)) {
        return $lookupLocation;
    }
    if ($metaLocation !== '') {
        return $metaLocation;
    }
    if (stripos((string)($metadata['execution_context'] ?? ''), 'cli') !== false) {
        return 'Server (CLI)';
    }
    $ip = trim($ip);
    if ($ip === '') {
        return 'Server/Unknown Origin';
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return 'Private/Local Network';
    }
    return 'Public Network';
}

function mobileActivityGadget(array $metadata): string
{
    $device = !empty($metadata['device_type']) ? (string)$metadata['device_type'] : 'Unknown';
    $browser = !empty($metadata['browser']) ? (string)$metadata['browser'] : '';
    $os = !empty($metadata['os']) ? (string)$metadata['os'] : '';
    $context = strtolower((string)($metadata['execution_context'] ?? ''));
    if ($context !== '' && strpos($context, 'cli') !== false && $device === 'Unknown' && $browser === '' && $os === '') {
        return 'Server Task / CLI';
    }
    $parts = array_values(array_filter([$device !== 'Unknown' ? $device : '', $browser, $os], static fn($part) => $part !== ''));
    return $parts ? implode(' / ', $parts) : '-';
}

function mobileActivityNetworkOwner(array $metadata): string
{
    $org = (string)($metadata['network_org'] ?? '');
    $asn = (string)($metadata['asn'] ?? '');
    if ($org === '' && $asn === '') {
        return '-';
    }
    if ($org !== '' && $asn !== '') {
        return $org . ' (' . $asn . ')';
    }
    return $org !== '' ? $org : $asn;
}

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
        $networkOwnerFilter = trim((string)($_GET['network_owner'] ?? ''));
        $asnFilter = trim((string)($_GET['asn'] ?? ''));
        $channelFilter = trim((string)($_GET['channel'] ?? ''));
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
        if ($networkOwnerFilter !== '') {
            $where[] = 'a.metadata LIKE :network_owner';
            $params[':network_owner'] = '%"network_org":"' . str_replace(['%', '_'], ['\\%', '\\_'], $networkOwnerFilter) . '%';
        }
        if ($asnFilter !== '') {
            $where[] = 'a.metadata LIKE :asn';
            $params[':asn'] = '%"asn":"' . str_replace(['%', '_'], ['\\%', '\\_'], $asnFilter) . '%';
        }
        if ($channelFilter === 'mobile_app') {
            $where[] = 'a.metadata LIKE :channel';
            $params[':channel'] = '%"channel":"mobile\\_app"%';
        } elseif ($channelFilter === 'website') {
            $where[] = '(a.metadata IS NULL OR a.metadata NOT LIKE :channel)';
            $params[':channel'] = '%"channel":"mobile\\_app"%';
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $countStmt = $db->prepare('SELECT COUNT(*) FROM activity_log a ' . $whereSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $total = (int)$countStmt->fetchColumn();

        $sql = 'SELECT a.*, u.full_name, u.account_number, u.role AS user_role, l.location_label AS lookup_location
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
        $logs = array_map(static function (array $row): array {
            $metadata = mobileActivityMetadata($row['metadata'] ?? null);
            $row['metadata'] = $metadata;
            $row['location'] = mobileActivityLocation((string)($row['ip_address'] ?? ''), $metadata, (string)($row['lookup_location'] ?? ''));
            $row['network_owner'] = mobileActivityNetworkOwner($metadata);
            $row['gadget'] = mobileActivityGadget($metadata);
            $row['channel'] = ($metadata['channel'] ?? '') === 'mobile_app' ? 'mobile_app' : 'website';
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

        $admins = $db->query("SELECT id, full_name, account_number FROM users WHERE role = 'admin' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $owners = [];
        $asns = [];
        $metaStmt = $db->query("SELECT metadata FROM activity_log WHERE metadata IS NOT NULL AND metadata <> '' ORDER BY created_at DESC LIMIT 1500");
        foreach (($metaStmt ? $metaStmt->fetchAll(PDO::FETCH_ASSOC) : []) as $metaRow) {
            $meta = mobileActivityMetadata($metaRow['metadata'] ?? '');
            $owner = trim((string)($meta['network_org'] ?? ''));
            $asn = trim((string)($meta['asn'] ?? ''));
            if ($owner !== '') {
                $owners[$owner] = true;
            }
            if ($asn !== '') {
                $asns[$asn] = true;
            }
        }
        $owners = array_keys($owners);
        $asns = array_keys($asns);
        sort($owners, SORT_NATURAL | SORT_FLAG_CASE);
        sort($asns, SORT_NATURAL | SORT_FLAG_CASE);

        mobileApiJson(200, 'success', 'Activity logs loaded.', [
            'logs' => $logs,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => max(1, (int)ceil($total / $limit)),
                'has_more' => ($offset + count($logs)) < $total,
            ],
            'filters' => [
                'admins' => array_map(static fn(array $admin): array => [
                    'id' => (int)$admin['id'],
                    'label' => trim((string)$admin['full_name'] . ' (' . (string)$admin['account_number'] . ')'),
                ], $admins),
                'network_owners' => $owners,
                'asns' => $asns,
                'channels' => [
                    ['value' => '', 'label' => 'All channels'],
                    ['value' => 'mobile_app', 'label' => 'Mobile app'],
                    ['value' => 'website', 'label' => 'Website'],
                ],
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
