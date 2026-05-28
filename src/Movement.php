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

/**
 * Movement record for check-in / check-out actions.
 *
 * Maps to table: glpi_plugin_itencheckinout_movements
 */
class Movement extends CommonDBTM
{
    public const ACTION_CHECKOUT = 'checkout';
    public const ACTION_CHECKIN  = 'checkin';

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
}
