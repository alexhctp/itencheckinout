<?php

/**
 * -------------------------------------------------------------------------
 * itencheckinout plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * MIT License
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 by the itencheckinout plugin team.
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * @link      https://github.com/alexhctp/itencheckinout
 * -------------------------------------------------------------------------
 */

use GlpiPlugin\Itencheckinout\Movement;
use GlpiPlugin\Itencheckinout\Service\MovementService;

require_once(__DIR__ . '/../../../inc/includes.php');

// Require authenticated session
Session::checkLoginUser();

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

// Validate input
$action               = trim($_POST['action'] ?? '');
$reservationitems_id  = (int) ($_POST['reservationitems_id'] ?? 0);

$allowed_actions = [Movement::ACTION_CHECKOUT, Movement::ACTION_CHECKIN];

if (!in_array($action, $allowed_actions, true) || $reservationitems_id <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => __('Invalid request parameters.', 'itencheckinout')]);
    exit;
}

// Delegate to service
$service = new MovementService();
$result  = $service->process($action, $reservationitems_id);

http_response_code($result['success'] ? 200 : 422);
header('Content-Type: application/json');
echo json_encode($result);
exit;
