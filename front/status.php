<?php

/**
 * Return status for reservation items listed in reservationitem.php.
 *
 * Response format:
 * {
 *   "success": true,
 *   "statuses": {
 *      "123": "In use",
 *      "124": "Available"
 *   }
 * }
 */

use GlpiPlugin\Itencheckinout\Movement;

require_once(__DIR__ . '/../../../inc/includes.php');

Session::checkLoginUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

$item_ids = $_POST['reservationitems_ids'] ?? [];
if (!is_array($item_ids)) {
    $item_ids = [];
}

$statuses = [];
foreach ($item_ids as $item_id) {
    $id = (int) $item_id;
    if ($id <= 0) {
        continue;
    }
    $statuses[(string) $id] = Movement::getStatusForReservationItem($id);
}

header('Content-Type: application/json');
echo json_encode([
    'success'  => true,
    'statuses' => $statuses,
]);
exit;
