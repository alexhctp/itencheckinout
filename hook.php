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

/**
 * Plugin install process
 */
function plugin_itencheckinout_install(): bool
{
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

    if (!$DB->tableExists('glpi_plugin_itencheckinout_movements')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_itencheckinout_movements` (
                `id`                   int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `reservations_id`      int {$default_key_sign} NOT NULL DEFAULT '0',
                `reservationitems_id`  int {$default_key_sign} NOT NULL DEFAULT '0',
                `action`               varchar(20) NOT NULL DEFAULT '',
                `users_id_actor`       int {$default_key_sign} NOT NULL DEFAULT '0',
                `date_action`          datetime NOT NULL DEFAULT '1970-01-01 00:00:01',
                `entities_id`          int {$default_key_sign} NOT NULL DEFAULT '0',
                `date_creation`        datetime DEFAULT NULL,
                `date_mod`             datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `reservations_id`         (`reservations_id`),
                KEY `reservationitems_id`      (`reservationitems_id`),
                KEY `date_action`             (`date_action`),
                UNIQUE KEY `uniq_action_resa` (`reservations_id`, `action`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset}
              COLLATE={$default_collation} ROW_FORMAT=DYNAMIC
        ");
    }

    if (!$DB->tableExists('glpi_plugin_itencheckinout_configs')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_itencheckinout_configs` (
                `id`                          int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `tolerance_minutes_after_end` int NOT NULL DEFAULT '" . PLUGIN_ITENCHECKINOUT_DEFAULT_TOLERANCE_MINUTES . "',
                `date_creation`               datetime DEFAULT NULL,
                `date_mod`                    datetime DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset}
              COLLATE={$default_collation} ROW_FORMAT=DYNAMIC
        ");

        // Insert default config row
        $DB->insert('glpi_plugin_itencheckinout_configs', [
            'tolerance_minutes_after_end' => PLUGIN_ITENCHECKINOUT_DEFAULT_TOLERANCE_MINUTES,
            'date_creation'              => date('Y-m-d H:i:s'),
            'date_mod'                   => date('Y-m-d H:i:s'),
        ]);
    }

    return true;
}

/**
 * Plugin uninstall process
 * Data is preserved on uninstall; tables are only dropped on plugin clean.
 */
function plugin_itencheckinout_uninstall(): bool
{
    return true;
}
