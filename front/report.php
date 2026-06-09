<?php

/**
 * Reservation checkout/check-in report.
 */

use GlpiPlugin\Itencheckinout\Movement;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_once(__DIR__ . '/../../../inc/includes.php');

Session::checkLoginUser();
Session::checkRight('reservation', READ);

$default_start = date('Y-m-01 00:00:00');
$default_end   = date('Y-m-d 23:59:59');

$start_raw = $_GET['start'] ?? $default_start;
$end_raw   = $_GET['end'] ?? $default_end;
$export    = strtolower(trim((string) ($_GET['export'] ?? '')));

$normalizeDate = static function (string $value, string $fallback): string {
    $value = trim($value);
    if ($value === '') {
        return $fallback;
    }

    $value = str_replace('T', ' ', $value);
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $fallback;
    }

    return date('Y-m-d H:i:s', $timestamp);
};

$start = $normalizeDate($start_raw, $default_start);
$end   = $normalizeDate($end_raw, $default_end);

if ($start > $end) {
    [$start, $end] = [$end, $start];
}

$start_for_input = date('Y-m-d\TH:i', strtotime($start));
$end_for_input   = date('Y-m-d\TH:i', strtotime($end));

$res_table = Reservation::getTable();
$ri_table  = ReservationItem::getTable();
$mov_table = Movement::getTable();

global $DB;

// Step 1: collect reservation IDs that match the period either by reservation window
// or by movement action date.
$reservation_ids_in_period = [];

$res_ids_iterator = $DB->request([
    'SELECT' => ["$res_table.id"],
    'FROM'   => $res_table,
    'WHERE'  => [
        "$res_table.begin" => ['<=', $end],
        "$res_table.end"   => ['>=', $start],
    ],
]);
foreach ($res_ids_iterator as $res_id_row) {
    $reservation_ids_in_period[(int) $res_id_row['id']] = (int) $res_id_row['id'];
}

$mov_ids_iterator = $DB->request([
    'SELECT' => ['reservations_id'],
    'FROM'   => $mov_table,
    'WHERE'  => [
        'action' => [Movement::ACTION_CHECKOUT, Movement::ACTION_CHECKIN],
        'AND'    => [
            ['date_action' => ['>=', $start]],
            ['date_action' => ['<=', $end]],
        ],
    ],
]);
foreach ($mov_ids_iterator as $mov_id_row) {
    $reservation_ids_in_period[(int) $mov_id_row['reservations_id']] = (int) $mov_id_row['reservations_id'];
}

$where_res = [];
if (count($reservation_ids_in_period) > 0) {
    $where_res["$res_table.id"] = array_values($reservation_ids_in_period);
} else {
    $where_res[0] = 1;
}

$res_iterator = $DB->request([
    'SELECT' => [
        "$res_table.id",
        "$res_table.begin",
        "$res_table.end",
        "$res_table.users_id",
        "$ri_table.id AS reservationitems_id",
        "$ri_table.itemtype",
        "$ri_table.items_id",
        "$ri_table.entities_id",
    ],
    'FROM'       => $res_table,
    'INNER JOIN' => [
        $ri_table => [
            'FKEY' => [
                $res_table => 'reservationitems_id',
                $ri_table  => 'id',
            ],
        ],
    ],
    'WHERE' => $where_res,
    'ORDER' => [
        "$res_table.begin DESC",
        "$res_table.id DESC",
    ],
]);

// Step 2: batch-fetch movements for all found reservations
$reservations = iterator_to_array($res_iterator);
$reservation_ids = array_column($reservations, 'id');

// Index by reservations_id + action for O(1) lookup
$movements_map = [];
if (!empty($reservation_ids)) {
    $mov_iterator = $DB->request([
        'FROM'  => Movement::getTable(),
        'WHERE' => [
            'reservations_id' => $reservation_ids,
            'action'          => [Movement::ACTION_CHECKOUT, Movement::ACTION_CHECKIN],
            'AND'             => [
                ['date_action' => ['>=', $start]],
                ['date_action' => ['<=', $end]],
            ],
        ],
    ]);
    foreach ($mov_iterator as $mov) {
        $movements_map[(int)$mov['reservations_id']][$mov['action']] = $mov['date_action'];
    }
}

$rows = [];

$no_ticket_label = 'sem ticket associado';
$associated_ticket_by_reservation = [];
$reservation_ticket_map_table = 'glpi_plugin_etlglpitfsworkintens_reservationtickets';

if (!empty($reservation_ids) && $DB->tableExists($reservation_ticket_map_table)) {
    $mapped_ticket_rows = $DB->request([
        'SELECT' => ['reservations_id', 'tickets_id'],
        'FROM'   => $reservation_ticket_map_table,
        'WHERE'  => [
            'reservations_id' => $reservation_ids,
        ],
    ]);

    $ticket_ids = [];
    $reservation_to_ticket = [];
    foreach ($mapped_ticket_rows as $mapped_ticket_row) {
        $mapped_reservation_id = (int) ($mapped_ticket_row['reservations_id'] ?? 0);
        $mapped_ticket_id = (int) ($mapped_ticket_row['tickets_id'] ?? 0);
        if ($mapped_reservation_id <= 0 || $mapped_ticket_id <= 0) {
            continue;
        }

        $reservation_to_ticket[$mapped_reservation_id] = $mapped_ticket_id;
        $ticket_ids[$mapped_ticket_id] = $mapped_ticket_id;
    }

    if (count($ticket_ids) > 0) {
        $ticket_titles_by_id = [];
        $ticket_rows = $DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => Ticket::getTable(),
            'WHERE'  => [
                'id' => array_values($ticket_ids),
            ],
        ]);

        foreach ($ticket_rows as $ticket_row) {
            $ticket_id = (int) ($ticket_row['id'] ?? 0);
            if ($ticket_id <= 0) {
                continue;
            }
            $ticket_titles_by_id[$ticket_id] = trim((string) ($ticket_row['name'] ?? ''));
        }

        foreach ($reservation_to_ticket as $mapped_reservation_id => $mapped_ticket_id) {
            $ticket_title = $ticket_titles_by_id[$mapped_ticket_id] ?? '';
            if ($ticket_title !== '') {
                $associated_ticket_by_reservation[$mapped_reservation_id] = $ticket_title;
            }
        }
    }
}

foreach ($reservations as $row) {
    $res_id      = (int) $row['id'];
    $checkout_at = $movements_map[$res_id][Movement::ACTION_CHECKOUT] ?? '';
    $checkin_at  = $movements_map[$res_id][Movement::ACTION_CHECKIN]  ?? '';
    $associated_ticket = $associated_ticket_by_reservation[$res_id] ?? $no_ticket_label;

    $item_label = $row['itemtype'] . ' #' . $row['items_id'];
    $item = getItemForItemtype($row['itemtype']);
    if ($item && $item->getFromDB((int) $row['items_id'])) {
        $item_label = sprintf('%s - %s', $item::getTypeName(1), $item->getName());
    }

    $rows[] = [
        'reservation_id' => $res_id,
        'item_label'     => $item_label,
        'reserved_by'    => getUserName((int) $row['users_id']),
        'period'         => Html::convDateTime($row['begin']) . ' - ' . Html::convDateTime($row['end']),
        'checkout_at'    => $checkout_at ? Html::convDateTime($checkout_at) : '-',
        'checkin_at'     => $checkin_at ? Html::convDateTime($checkin_at) : '-',
        'withdrawn'      => $checkout_at ? __('Yes') : __('No'),
        'returned'       => $checkin_at ? __('Yes') : __('No'),
        'loaned'         => ($checkout_at && !$checkin_at) ? __('Yes') : __('No'),
        'associated_ticket' => $associated_ticket,
    ];
}

$headers = [
    __('Reservation'),
    _n('Item', 'Items', 1),
    __('Reserved by'),
    __('Reservation period', 'itencheckinout'),
    __('Checkout at', 'itencheckinout'),
    __('Checkin at', 'itencheckinout'),
    __('Item withdrawn', 'itencheckinout'),
    __('Item returned', 'itencheckinout'),
    __('Currently loaned', 'itencheckinout'),
    __('Associated ticket', 'itencheckinout'),
];

if ($export === 'csv' || $export === 'xlsx') {
    $filename_suffix = date('Ymd_His');

    if ($export === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="itencheckinout_report_' . $filename_suffix . '.csv"');

        $out = fopen('php://output', 'wb');
        // UTF-8 BOM for Excel compatibility
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers, ';');
        foreach ($rows as $r) {
            fputcsv($out, [
                '#' . $r['reservation_id'],
                $r['item_label'],
                $r['reserved_by'],
                $r['period'],
                $r['checkout_at'],
                $r['checkin_at'],
                $r['withdrawn'],
                $r['returned'],
                $r['loaned'],
                $r['associated_ticket'],
            ], ';');
        }
        fclose($out);
        exit;
    }

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Report');

    $col = 1;
    foreach ($headers as $label) {
        $sheet->setCellValueByColumnAndRow($col, 1, $label);
        $col++;
    }

    $row_num = 2;
    foreach ($rows as $r) {
        $sheet->setCellValueByColumnAndRow(1, $row_num, '#' . $r['reservation_id']);
        $sheet->setCellValueByColumnAndRow(2, $row_num, $r['item_label']);
        $sheet->setCellValueByColumnAndRow(3, $row_num, $r['reserved_by']);
        $sheet->setCellValueByColumnAndRow(4, $row_num, $r['period']);
        $sheet->setCellValueByColumnAndRow(5, $row_num, $r['checkout_at']);
        $sheet->setCellValueByColumnAndRow(6, $row_num, $r['checkin_at']);
        $sheet->setCellValueByColumnAndRow(7, $row_num, $r['withdrawn']);
        $sheet->setCellValueByColumnAndRow(8, $row_num, $r['returned']);
        $sheet->setCellValueByColumnAndRow(9, $row_num, $r['loaned']);
        $sheet->setCellValueByColumnAndRow(10, $row_num, $r['associated_ticket']);
        $row_num++;
    }

    foreach (range('A', 'J') as $column) {
        $sheet->getColumnDimension($column)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="itencheckinout_report_' . $filename_suffix . '.xlsx"');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

$title = __('Reservation movement report', 'itencheckinout');
Html::header($title, '', 'tools', 'reservationitem');

echo "<div class='card mb-3'>";
echo "<div class='card-header'><h3 class='card-title mb-0'>" . htmlescape($title) . "</h3></div>";
echo "<div class='card-body'>";

echo "<form method='get' action='' class='row g-3 align-items-end mb-3'>";
echo "  <div class='col-12 col-md-4'>";
echo "    <label class='form-label' for='start_date'>" . htmlescape(__('Start date')) . "</label>";
echo "    <input class='form-control' type='datetime-local' id='start_date' name='start' value='" . htmlescape($start_for_input) . "'>";
echo "  </div>";
echo "  <div class='col-12 col-md-4'>";
echo "    <label class='form-label' for='end_date'>" . htmlescape(__('End date')) . "</label>";
echo "    <input class='form-control' type='datetime-local' id='end_date' name='end' value='" . htmlescape($end_for_input) . "'>";
echo "  </div>";
echo "  <div class='col-12 col-md-4'>";
echo "    <div class='d-flex gap-2'>";
echo "      <button class='btn btn-primary' type='submit'><i class='ti ti-search me-1'></i>" . htmlescape(_x('button', 'Search')) . "</button>";
echo "      <button class='btn btn-outline-secondary' type='submit' name='export' value='csv'><i class='ti ti-file-type-csv me-1'></i>CSV</button>";
echo "      <button class='btn btn-outline-secondary' type='submit' name='export' value='xlsx'><i class='ti ti-file-spreadsheet me-1'></i>XLSX</button>";
echo "    </div>";
echo "  </div>";
echo "</form>";

echo "<div class='table-responsive'>";
echo "<table class='table table-striped table-hover table-sm'>";
echo "  <thead>";
echo "    <tr>";
echo "      <th>" . htmlescape(__('Reservation')) . "</th>";
echo "      <th>" . htmlescape(_n('Item', 'Items', 1)) . "</th>";
echo "      <th>" . htmlescape(__('Reserved by')) . "</th>";
echo "      <th>" . htmlescape(__('Reservation period', 'itencheckinout')) . "</th>";
echo "      <th>" . htmlescape(__('Checkout at', 'itencheckinout')) . "</th>";
echo "      <th>" . htmlescape(__('Checkin at', 'itencheckinout')) . "</th>";
echo "      <th>" . htmlescape(__('Item withdrawn', 'itencheckinout')) . "</th>";
echo "      <th>" . htmlescape(__('Item returned', 'itencheckinout')) . "</th>";
echo "      <th>" . htmlescape(__('Currently loaned', 'itencheckinout')) . "</th>";
echo "      <th>" . htmlescape(__('Associated ticket', 'itencheckinout')) . "</th>";
echo "    </tr>";
echo "  </thead>";
echo "  <tbody>";

foreach ($rows as $r) {
    echo "    <tr>";
    echo "      <td>#" . (int) $r['reservation_id'] . "</td>";
    echo "      <td>" . htmlescape($r['item_label']) . "</td>";
    echo "      <td>" . htmlescape($r['reserved_by']) . "</td>";
    echo "      <td>" . htmlescape($r['period']) . "</td>";
    echo "      <td>" . htmlescape($r['checkout_at']) . "</td>";
    echo "      <td>" . htmlescape($r['checkin_at']) . "</td>";
    echo "      <td>" . htmlescape($r['withdrawn']) . "</td>";
    echo "      <td>" . htmlescape($r['returned']) . "</td>";
    echo "      <td>" . htmlescape($r['loaned']) . "</td>";
    echo "      <td>" . htmlescape($r['associated_ticket']) . "</td>";
    echo "    </tr>";
}

if (count($rows) === 0) {
    echo "    <tr><td colspan='10' class='text-center text-muted py-4'>" . htmlescape(__('No item found')) . "</td></tr>";
}

echo "  </tbody>";
echo "</table>";
echo "</div>";

echo "</div>";
echo "</div>";

Html::footer();
