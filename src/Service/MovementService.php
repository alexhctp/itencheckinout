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

namespace GlpiPlugin\Itencheckinout\Service;

use GlpiPlugin\Itencheckinout\Movement;
use Reservation;
use ReservationItem;
use Session;

/**
 * Core business logic for check-in / check-out actions.
 *
 * All authorization and state-machine validations live here.
 * The endpoint (front/movement.php) is a thin controller that delegates entirely to this service.
 */
class MovementService
{
    /**
     * Entry point: dispatch to checkout or checkin.
     *
     * @return array{success: bool, message: string}
     */
    public function process(string $action, int $reservationitems_id): array
    {
        return match ($action) {
            Movement::ACTION_CHECKOUT => $this->checkout($reservationitems_id),
            Movement::ACTION_CHECKIN  => $this->checkin($reservationitems_id),
            default                   => ['success' => false, 'message' => __('Invalid action.', 'itencheckinout')],
        };
    }

    // -------------------------------------------------------------------------
    // Checkout
    // -------------------------------------------------------------------------

    /**
     * @return array{success: bool, message: string}
     */
    public function checkout(int $reservationitems_id): array
    {
        $users_id = (int) Session::getLoginUserID();

        $reservation = $this->findUserReservation($reservationitems_id, $users_id);
        if ($reservation === null) {
            return [
                'success' => false,
                'message' => __('No reservation was found for the current user and item.', 'itencheckinout'),
            ];
        }

        if (Movement::actionExistsForReservation((int) $reservation['id'], Movement::ACTION_CHECKOUT)) {
            $this->assignTechnicianInCharge((int) $reservation['reservationitems_id'], $users_id);
            return [
                'success' => true,
                'message' => __('Item checkout already recorded.', 'itencheckinout'),
            ];
        }

        $movement = new Movement();
        $result = $movement->add([
            'reservations_id'     => (int) $reservation['id'],
            'reservationitems_id' => $reservationitems_id,
            'action'              => Movement::ACTION_CHECKOUT,
            'users_id_actor'      => $users_id,
            'date_action'         => Session::getCurrentTime(),
            'entities_id'         => (int) $reservation['entities_id'],
        ]);

        if ($result === false) {
            return ['success' => false, 'message' => __('An error occurred while saving the checkout. Please try again.', 'itencheckinout')];
        }

        if (!$this->assignTechnicianInCharge((int) $reservation['reservationitems_id'], $users_id)) {
            return ['success' => true, 'message' => __('Item checked out, but the technician in charge could not be updated.', 'itencheckinout')];
        }

        return ['success' => true, 'message' => __('Item checked out successfully.', 'itencheckinout')];
    }

    // -------------------------------------------------------------------------
    // Checkin
    // -------------------------------------------------------------------------

    /**
     * @return array{success: bool, message: string}
     */
    public function checkin(int $reservationitems_id): array
    {
        $users_id = (int) Session::getLoginUserID();

        $reservation = $this->findUserReservation($reservationitems_id, $users_id);
        if ($reservation === null) {
            return [
                'success' => false,
                'message' => __('No reservation was found for the current user and item.', 'itencheckinout'),
            ];
        }

        if (Movement::actionExistsForReservation((int) $reservation['id'], Movement::ACTION_CHECKIN)) {
            return [
                'success' => true,
                'message' => __('Item checkin already recorded.', 'itencheckinout'),
            ];
        }

        $movement = new Movement();
        $result = $movement->add([
            'reservations_id'     => (int) $reservation['id'],
            'reservationitems_id' => $reservationitems_id,
            'action'              => Movement::ACTION_CHECKIN,
            'users_id_actor'      => $users_id,
            'date_action'         => Session::getCurrentTime(),
            'entities_id'         => (int) $reservation['entities_id'],
        ]);

        if ($result === false) {
            return ['success' => false, 'message' => __('An error occurred while saving the checkin. Please try again.', 'itencheckinout')];
        }

        return ['success' => true, 'message' => __('Item checked in successfully.', 'itencheckinout')];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Find the most recent reservation of the current user for the given item.
     *
     * @return array<string, mixed>|null
     */
    private function findUserReservation(int $reservationitems_id, int $users_id): ?array
    {
        global $DB;

        $ri_table  = ReservationItem::getTable();
        $res_table = Reservation::getTable();

        $iterator = $DB->request([
            'SELECT' => [
                "$res_table.id",
                "$res_table.reservationitems_id",
                "$res_table.users_id",
                "$res_table.begin",
                "$res_table.end",
                "$ri_table.entities_id",
            ],
            'FROM'       => $res_table,
            'INNER JOIN' => [
                $ri_table => [
                    'ON' => [
                        $res_table => 'reservationitems_id',
                        $ri_table  => 'id',
                    ],
                ],
            ],
            'WHERE' => [
                "$res_table.reservationitems_id" => $reservationitems_id,
                "$res_table.users_id"            => $users_id,
            ],
            'ORDER' => ["$res_table.begin DESC"],
            'LIMIT' => 1,
        ]);

        foreach ($iterator as $row) {
            return $row;
        }

        return null;
    }

    /**
     * Update the linked asset technician in charge with the acting user.
     */
    private function assignTechnicianInCharge(int $reservationitems_id, int $users_id): bool
    {
        $reservation_item = new ReservationItem();
        if (!$reservation_item->getFromDB($reservationitems_id)) {
            return false;
        }

        $asset = $reservation_item->getItem();
        if ($asset === false || !$asset->isField('users_id_tech')) {
            return true;
        }

        return $asset->update([
            'id'            => $asset->getID(),
            'users_id_tech' => $users_id,
        ], false);
    }
}
