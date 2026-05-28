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

namespace GlpiPlugin\Itencheckinout;

use CommonDBTM;
use Reservation;
use Session;

/**
 * Movement record for check-in / check-out actions.
 *
 * Maps to table: glpi_plugin_itencheckinout_movements
 */
class Movement extends CommonDBTM
{
    public const ACTION_CHECKOUT = 'checkout';
    public const ACTION_CHECKIN  = 'checkin';
    public const STATUS_IN_USE   = 'In use';
    public const STATUS_RESERVED = 'Reserved';
    public const STATUS_AVAILABLE = 'Available';

    public static $rightname = 'reservation';

    public static function getTypeName($nb = 0): string
    {
        return _n('Movement', 'Movements', $nb, 'itencheckinout');
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_itencheckinout_movements';
    }

    /**
     * Find the last movement for a given reservation and action type.
     */
    public static function getLastForReservation(int $reservations_id, string $action): ?array
    {
        global $DB;

        $iterator = $DB->request([
            'FROM'    => static::getTable(),
            'WHERE'   => [
                'reservations_id' => $reservations_id,
                'action'          => $action,
            ],
            'ORDER'   => ['date_action DESC'],
            'LIMIT'   => 1,
        ]);

        foreach ($iterator as $row) {
            return $row;
        }

        return null;
    }

    /**
     * Check whether a given action was already recorded for a reservation.
     */
    public static function actionExistsForReservation(int $reservations_id, string $action): bool
    {
        global $DB;

        $result = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => static::getTable(),
            'WHERE' => [
                'reservations_id' => $reservations_id,
                'action'          => $action,
            ],
        ])->current();

        return (int) $result['cpt'] > 0;
    }

    /**
     * List all movements for a given reservation item, ordered by date.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getForReservationItem(int $reservationitems_id): array
    {
        global $DB;

        $rows = [];
        $iterator = $DB->request([
            'FROM'  => static::getTable(),
            'WHERE' => ['reservationitems_id' => $reservationitems_id],
            'ORDER' => ['date_action ASC'],
        ]);

        foreach ($iterator as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Find the latest movement recorded for a reservation item.
     */
    public static function getLastForReservationItem(int $reservationitems_id): ?array
    {
        global $DB;

        $iterator = $DB->request([
            'FROM'  => static::getTable(),
            'WHERE' => ['reservationitems_id' => $reservationitems_id],
            'ORDER' => ['date_action DESC', 'id DESC'],
            'LIMIT' => 1,
        ]);

        foreach ($iterator as $row) {
            return $row;
        }

        return null;
    }

    /**
     * Resolve item status label from latest movement.
     *
     * Rules:
     * - In use: there is at least one current/future reservation with checkout and no checkin.
     * - Reserved: there is at least one current/future reservation without checkout.
     * - Available: there are no current/future reservations.
     */
    public static function getStatusForReservationItem(int $reservationitems_id): string
    {
        $reservations = static::getCurrentAndFutureReservationsForItem($reservationitems_id);
        if (count($reservations) === 0) {
            return self::STATUS_AVAILABLE;
        }

        foreach ($reservations as $reservation) {
            $reservation_id = (int) $reservation['id'];
            $has_checkout = static::actionExistsForReservation($reservation_id, self::ACTION_CHECKOUT);
            $has_checkin  = static::actionExistsForReservation($reservation_id, self::ACTION_CHECKIN);
            if ($has_checkout && !$has_checkin) {
                return self::STATUS_IN_USE;
            }
        }

        foreach ($reservations as $reservation) {
            $reservation_id = (int) $reservation['id'];
            $has_checkout = static::actionExistsForReservation($reservation_id, self::ACTION_CHECKOUT);
            if (!$has_checkout) {
                return self::STATUS_RESERVED;
            }
        }

        return self::STATUS_AVAILABLE;
    }

    /**
     * Returns current and future reservations for an item.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function getCurrentAndFutureReservationsForItem(int $reservationitems_id): array
    {
        global $DB;

        $rows = [];
        $table = Reservation::getTable();
        $now = Session::getCurrentTime();

        $iterator = $DB->request([
            'SELECT' => [
                "$table.id",
                "$table.begin",
                "$table.end",
            ],
            'FROM'   => $table,
            'WHERE'  => [
                "$table.reservationitems_id" => $reservationitems_id,
                "$table.end"                 => ['>=', $now],
            ],
            'ORDER' => ["$table.begin ASC", "$table.id ASC"],
        ]);

        foreach ($iterator as $row) {
            $rows[] = $row;
        }

        return $rows;
    }
}
