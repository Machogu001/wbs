<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/SupportChat.php';

try {
    $db = mobileApiGetDatabase();
    $user = mobileApiRequireUser($db);
    $chat = new SupportChat($db);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $isStaff = mobileApiUserHasRole($user, ['admin', 'support', 'finance'])
        || mobileApiUserHasAnyPermission($db, $user, ['handle_support', 'send_messages']);
    $userId = (int)($user['id'] ?? 0);

    if ($method === 'GET') {
        $selectedThreadId = (int)($_GET['thread_id'] ?? 0);
        $threads = [];
        $thread = null;

        if ($isStaff) {
            $threads = $chat->getThreadsForAdmin(50);
            if ($selectedThreadId <= 0 && !empty($threads)) {
                $selectedThreadId = (int)($threads[0]['id'] ?? 0);
            }
            if ($selectedThreadId > 0) {
                $thread = $chat->getThreadById($selectedThreadId);
            }
        } else {
            $thread = $chat->getOrCreateThreadForUser($userId);
            $selectedThreadId = (int)($thread['id'] ?? 0);
        }

        $messages = $selectedThreadId > 0 ? $chat->getMessages($selectedThreadId) : [];
        $typing = $selectedThreadId > 0 ? $chat->getTypingStatus($selectedThreadId) : ['user' => false, 'admin' => false];
        $availableAgents = $chat->getAvailableAgents();
        $recentAgents = $chat->getLastSeenAgents(5);
        $currentUserAvailable = $isStaff && $userId > 0 ? $chat->isAgentAvailable($userId) : false;

        mobileApiJson(200, 'success', 'Support chat loaded.', [
            'mode' => $isStaff ? 'staff' : 'customer',
            'thread' => $thread,
            'threads' => $threads,
            'messages' => $messages,
            'typing' => $typing,
            'available_agents' => $availableAgents,
            'recent_agents' => $recentAgents,
            'current_user_available' => $currentUserAvailable,
        ]);
    }

    if ($method === 'POST') {
        $data = mobileApiReadJson();
        $action = (string)($data['action'] ?? '');

        if ($action === 'send_message') {
            $threadId = (int)($data['thread_id'] ?? 0);
            $message = trim((string)($data['message'] ?? ''));
            if ($threadId <= 0 || $message === '') {
                mobileApiJson(422, 'error', 'Thread and message are required.');
            }
            if (!$isStaff && !$chat->isThreadOwnedByUser($threadId, $userId)) {
                mobileApiJson(403, 'error', 'Forbidden.');
            }

            $senderType = $isStaff ? 'admin' : 'user';
            $senderId = $isStaff ? $userId : null;
            if (!$chat->addMessage($threadId, $senderType, $senderId, $message)) {
                mobileApiJson(500, 'error', 'Could not send message.');
            }
            $newMessage = null;
            try {
                $lastId = (int)$db->lastInsertId();
                if ($lastId > 0) {
                    $newMessage = $chat->getMessageById($lastId);
                }
            } catch (Throwable $e) {
            }
            mobileApiJson(200, 'success', 'Message sent.', [
                'message_data' => $newMessage,
            ]);
        }

        if ($action === 'close_thread') {
            if (!$isStaff) {
                mobileApiJson(403, 'error', 'Forbidden.');
            }
            $threadId = (int)($data['thread_id'] ?? 0);
            if ($threadId <= 0 || !$chat->closeThread($threadId)) {
                mobileApiJson(422, 'error', 'Could not close thread.');
            }
            mobileApiJson(200, 'success', 'Thread closed.');
        }

        if ($action === 'mark_read') {
            $threadId = (int)($data['thread_id'] ?? 0);
            if ($threadId <= 0) {
                mobileApiJson(422, 'error', 'A valid thread is required.');
            }
            if (!$isStaff && !$chat->isThreadOwnedByUser($threadId, $userId)) {
                mobileApiJson(403, 'error', 'Forbidden.');
            }
            if (!$chat->markMessagesRead($threadId, $isStaff ? 'admin' : 'user')) {
                mobileApiJson(500, 'error', 'Could not mark messages as read.');
            }
            mobileApiJson(200, 'success', 'Thread marked as read.');
        }

        if ($action === 'set_availability') {
            if (!$isStaff) {
                mobileApiJson(403, 'error', 'Forbidden.');
            }
            $available = !empty($data['available']);
            if (!$chat->setAgentAvailability($userId, $available)) {
                mobileApiJson(500, 'error', 'Could not update availability.');
            }
            mobileApiJson(200, 'success', 'Availability updated.', [
                'available' => $available,
            ]);
        }

        mobileApiJson(422, 'error', 'Unsupported support chat action.');
    }

    mobileApiJson(405, 'error', 'Method not allowed.');
} catch (Throwable $e) {
    error_log('Mobile API support chat failed: ' . $e->getMessage());
    mobileApiJson(500, 'error', 'Unable to process support chat right now.');
}