<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/InternalComms.php';
require_once __DIR__ . '/../../../includes/SupportChat.php';

try {
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    mobileApiRequireStaffPermission($db, $user, ['send_messages', 'handle_support']);
    $comms = new InternalComms($db);
    $chat = new SupportChat($db);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $userId = (int)($user['id'] ?? 0);

    if ($method === 'GET') {
        $sinceId = (int)($_GET['since_id'] ?? 0);
        $internalMessages = $comms->getInternalMessages($sinceId > 0 ? $sinceId : null, 120);
        mobileApiJson(200, 'success', 'Messaging center loaded.', [
            'internal_messages' => $internalMessages,
            'recent_broadcasts' => $comms->getRecentBroadcasts(15),
            'client_templates' => $comms->getCustomTemplates('clients', 200),
            'staff_templates' => $comms->getCustomTemplates('staff', 200),
            'clients' => $comms->getActiveClients(),
            'staff' => $comms->getActiveStaff(),
            'availability' => [
                'current_user_available' => $chat->isAgentAvailable($userId),
                'agents' => $chat->getAvailableAgents(),
                'recent_agents' => $chat->getLastSeenAgents(5),
            ],
        ]);
    }

    if ($method === 'POST') {
        $data = mobileApiReadJson();
        $action = (string)($data['action'] ?? '');

        if ($action === 'send_broadcast') {
            $subject = trim((string)($data['subject'] ?? ''));
            $message = trim((string)($data['message'] ?? ''));
            $recipientGroup = (string)($data['recipient_group'] ?? 'clients');
            $audience = (string)($data['audience'] ?? 'all_clients');
            $selectedIds = array_values(array_filter(array_map('intval', (array)($data['selected_ids'] ?? [])), static function ($id): bool {
                return $id > 0;
            }));

            if ($audience === 'selected_staff' || $audience === 'all_staff') {
                $recipientGroup = 'staff';
            } elseif ($audience === 'selected_clients' || $audience === 'all_clients') {
                $recipientGroup = 'clients';
            }

            $sendToAll = in_array($audience, ['all_clients', 'all_staff'], true);
            $result = $comms->sendSmsBroadcast($userId, $subject, $message, $recipientGroup, $sendToAll, $selectedIds);
            if (empty($result['success'])) {
                mobileApiJson(422, 'error', (string)($result['message'] ?? 'Could not send broadcast.'));
            }
            mobileApiJson(200, 'success', (string)($result['message'] ?? 'Broadcast sent.'), $result);
        }

        if ($action === 'save_template') {
            $result = $comms->saveCustomTemplate(
                $userId,
                (string)($data['recipient_group'] ?? 'clients'),
                trim((string)($data['template_title'] ?? '')),
                trim((string)($data['subject'] ?? '')),
                trim((string)($data['message'] ?? ''))
            );
            if (empty($result['success'])) {
                mobileApiJson(422, 'error', (string)($result['message'] ?? 'Could not save template.'));
            }
            mobileApiJson(200, 'success', (string)($result['message'] ?? 'Template saved.'), $result);
        }

        if ($action === 'delete_template') {
            $result = $comms->deleteCustomTemplate((int)($data['template_id'] ?? 0), $userId);
            if (empty($result['success'])) {
                mobileApiJson(422, 'error', (string)($result['message'] ?? 'Could not delete template.'));
            }
            mobileApiJson(200, 'success', (string)($result['message'] ?? 'Template deleted.'));
        }

        if ($action === 'send_internal_message') {
            $message = trim((string)($data['message'] ?? ''));
            if ($message === '' || !$comms->addInternalMessage($userId, $message)) {
                mobileApiJson(422, 'error', 'Message is required.');
            }
            $latest = $comms->getInternalMessages(null, 1);
            mobileApiJson(200, 'success', 'Internal message sent.', [
                'message_data' => !empty($latest) ? $latest[0] : null,
            ]);
        }

        if ($action === 'set_availability') {
            $available = !empty($data['available']);
            if (!$chat->setAgentAvailability($userId, $available)) {
                mobileApiJson(500, 'error', 'Could not update availability.');
            }
            mobileApiJson(200, 'success', 'Availability updated.', [
                'available' => $available,
            ]);
        }

        mobileApiJson(422, 'error', 'Unsupported messaging action.');
    }

    mobileApiJson(405, 'error', 'Method not allowed.');
} catch (Throwable $e) {
    error_log('Mobile API admin messaging failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to process messaging right now.');
}