<?php
header("Content-Type: application/json");
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/User.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    if(!$db) {
        throw new Exception("Database connection failed");
    }

    $auth = new Auth($db);
    if(!$auth->isLoggedIn() || (!$auth->isAdmin() && !$auth->hasPermission('view_invoicing') && !$auth->hasPermission('correct_bills') && !$auth->hasPermission('view_payments') && !$auth->hasPermission('view_customers'))) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Forbidden"]);
        exit;
    }

    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    if($q === '') {
        echo json_encode(["status" => "success", "data" => []]);
        exit;
    }

    $userService = new User($db);
    $results = $userService->searchByNameOrAccount($q, 10);

    $queryLower = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);

    $data = array_map(function($row) use ($queryLower) {
        $meters = [];
        $meterNumbers = [];
        if (!empty($row['meter_details'])) {
            $segments = array_values(array_filter(explode('||', (string)$row['meter_details'])));
            foreach ($segments as $segment) {
                $parts = explode('::', $segment, 2);
                $meterNumber = trim((string)($parts[0] ?? ''));
                $meterLabel = trim((string)($parts[1] ?? ''));
                if ($meterNumber === '') {
                    continue;
                }
                $meters[] = [
                    'number' => $meterNumber,
                    'label' => $meterLabel,
                    'is_primary' => empty($meterNumbers),
                ];
                $meterNumbers[] = $meterNumber;
            }
        } elseif (!empty($row['meter_number'])) {
            $meterNumbers = [(string)$row['meter_number']];
            $meters[] = [
                'number' => (string)$row['meter_number'],
                'label' => '',
                'is_primary' => true,
            ];
        }

        $selectionValue = (string)$row['account_number'];
        $matchedMeter = null;
        foreach ($meters as $meter) {
            $numberLower = function_exists('mb_strtolower') ? mb_strtolower((string)$meter['number'], 'UTF-8') : strtolower((string)$meter['number']);
            $labelLower = function_exists('mb_strtolower') ? mb_strtolower((string)($meter['label'] ?? ''), 'UTF-8') : strtolower((string)($meter['label'] ?? ''));
            if (($queryLower !== '' && strpos($numberLower, $queryLower) !== false) || ($queryLower !== '' && $labelLower !== '' && strpos($labelLower, $queryLower) !== false)) {
                $selectionValue = (string)$meter['number'];
                $matchedMeter = $meter;
                break;
            }
        }

        $meterSummary = array_map(function ($meter) {
            return $meter['number'] . (!empty($meter['label']) ? ' (' . $meter['label'] . ')' : '');
        }, $meters);

        $suggestionText = (string)$row['full_name'] . ' - ' . (string)$row['account_number'];
        if ($matchedMeter) {
            $suggestionText .= ' (Matched meter: ' . $matchedMeter['number'] . (!empty($matchedMeter['label']) ? ' - ' . $matchedMeter['label'] : '') . ')';
        } elseif (!empty($meterSummary)) {
            $suggestionText .= ' (Meters: ' . implode(', ', $meterSummary) . ')';
        }

        return [
            "id" => $row['id'],
            "account_number" => $row['account_number'],
            "full_name" => $row['full_name'],
            "meter_number" => $row['meter_number'],
            "meter_numbers" => $meterNumbers,
            "meters" => $meters,
            "selection_value" => $selectionValue,
            "suggestion_text" => $suggestionText,
            "matched_meter_number" => $matchedMeter['number'] ?? null
        ];
    }, $results);

    echo json_encode(["status" => "success", "data" => $data]);
} catch(Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
