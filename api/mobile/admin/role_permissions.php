<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/Auth.php';

try {
    $db = mobileApiGetDatabase();
    $actor = mobileApiRequireUser($db);
    if (!mobileApiUserHasRole($actor, 'admin')) {
        mobileApiJson(403, 'error', 'Forbidden.');
    }

    $permissionDefs = [
        'view_customers' => ['label' => 'View Customers', 'description' => 'Access customer list and map'],
        'view_accounting' => ['label' => 'View Accounting', 'description' => 'Access accounting journals'],
        'view_reports' => ['label' => 'View Reports', 'description' => 'Access financial reports'],
        'view_payments' => ['label' => 'View Payments', 'description' => 'View payments and transaction history'],
        'receive_payments' => ['label' => 'Receive Payments', 'description' => 'Record manual payment receipts'],
        'view_invoicing' => ['label' => 'View Invoicing', 'description' => 'Access invoicing workspace'],
        'manage_registration_proformas' => ['label' => 'Registration Proformas', 'description' => 'Create registration proformas'],
        'view_bill_detail' => ['label' => 'View Bill Detail', 'description' => 'View individual bill details'],
        'correct_bills' => ['label' => 'Correct Bill Readings', 'description' => 'Fix wrongly entered meter readings'],
        'manage_demand_notices' => ['label' => 'Demand Notices', 'description' => 'Access and manage demand notices'],
        'manage_approvals' => ['label' => 'Finance Approvals', 'description' => 'Access approval workflows'],
        'handle_support' => ['label' => 'Support Chat & Inquiries', 'description' => 'Access support chat and inquiry inbox'],
        'send_messages' => ['label' => 'Messaging / SMS', 'description' => 'Access messaging and bulk SMS tools'],
        'manage_settings' => ['label' => 'Manage Settings', 'description' => 'Access system settings and secrets'],
    ];
    $roles = ['finance', 'reader', 'support'];
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $rows = $db->query('SELECT role, permission FROM role_permissions');
        $current = [];
        foreach (($rows ? $rows->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
            $current[(string)$row['role']][(string)$row['permission']] = true;
        }
        mobileApiJson(200, 'success', 'Role permissions loaded.', ['roles' => $roles, 'permission_defs' => $permissionDefs, 'current' => $current]);
    }

    if ($method === 'POST') {
        $data = mobileApiReadJson();
        $submitted = (array)($data['permissions'] ?? []);
        $db->beginTransaction();
        try {
            $db->exec('DELETE FROM role_permissions');
            $stmt = $db->prepare('INSERT INTO role_permissions (role, permission) VALUES (:role, :permission)');
            foreach ($roles as $role) {
                foreach (array_keys($permissionDefs) as $perm) {
                    if (!empty($submitted[$role][$perm])) {
                        $stmt->execute([':role' => $role, ':permission' => $perm]);
                    }
                }
            }
            $db->commit();
            Auth::clearPermissionCache();
            mobileApiJson(200, 'success', 'Role permissions saved successfully.');
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    mobileApiJson(405, 'error', 'Method not allowed.');
} catch (Throwable $e) {
    error_log('Mobile API admin role permissions failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to process role permissions right now.');
}