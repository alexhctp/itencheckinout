/**
 * itencheckinout plugin for GLPI
 *
 * Injects Checkout / Check-in action buttons into each row of the
 * reservable-items list (front/reservationitem.php).
 *
 * Strategy:
 *   - Detect the target table via the #nosearch container.
 *   - Append "STATUS" and "Actions" column headers once.
 *   - For every data row, read the reservation-item ID from the
 *     checkbox name (format: item[{id}]).
 *   - Add two buttons per row that POST to the plugin movement endpoint.
 *   - Fetch current status for all listed items from plugin endpoint.
 *   - Display an inline alert with the JSON response message.
 */
(function ($) {
    'use strict';

    // Only run on the reservationitem list page
    if (!window.location.pathname.endsWith('/front/reservationitem.php')) {
        return;
    }

    /**
     * Endpoint for checkout/checkin actions.
     * CFG_GLPI.root_doc is injected by GLPI in the page header.
     */
    const ENDPOINT = (window.CFG_GLPI?.root_doc ?? '') + '/plugins/itencheckinout/front/movement.php';
    const STATUS_ENDPOINT = (window.CFG_GLPI?.root_doc ?? '') + '/plugins/itencheckinout/front/status.php';

    /**
     * Read the CSRF token from the GLPI meta tag injected by Html::header().
     */
    function getCsrfToken() {
        const meta = document.querySelector('meta[name="_glpi_csrf_token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    /**
     * Show a dismissible Bootstrap alert above the reservations table.
     * Removes any previous alert first.
     */
    function showAlert(message, isSuccess) {
        $('#itencheckinout-alert').remove();
        const type  = isSuccess ? 'success' : 'danger';
        const icon  = isSuccess ? 'ti ti-circle-check' : 'ti ti-alert-circle';
        const $alert = $(
            `<div id="itencheckinout-alert" class="alert alert-${type} alert-dismissible d-flex align-items-center mt-2 mb-2" role="alert">
                <i class="${icon} me-2 fs-5"></i>
                <span>${$('<span>').text(message).html()}</span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>`
        );
        $('#nosearch').before($alert);
        // Auto-dismiss after 6s
        setTimeout(() => $alert.alert('close'), 6000);
    }

    /**
     * Perform a POST request to the movement endpoint and display the result.
     *
     * @param {string} action            - 'checkout' or 'checkin'
     * @param {number} reservationitemsId
     * @param {jQuery} $btn              - the clicked button (for loading state)
     */
    function doAction(action, reservationitemsId, $btn) {
        const $row = $btn.closest('tr');
        $row.find('.itencheckinout-btn').prop('disabled', true);

        $.ajax({
            url:    ENDPOINT,
            method: 'POST',
            data: {
                action:               action,
                reservationitems_id:  reservationitemsId,
                _glpi_csrf_token:     getCsrfToken(),
            },
            dataType: 'json',
        })
        .done(function (response) {
            showAlert(response.message ?? '', response.success === true);
            if (response.success === true) {
                const statusText = response.status || (action === 'checkout' ? 'In use' : 'Available');
                setRowStatus($row, statusText);
            }
        })
        .fail(function (xhr) {
            let msg = '';
            try {
                msg = JSON.parse(xhr.responseText).message ?? '';
            } catch (_) {
                msg = xhr.statusText || 'Unknown error';
            }
            showAlert(msg || 'An unexpected error occurred.', false);
        })
        .always(function () {
            $row.find('.itencheckinout-btn').prop('disabled', false);
        });
    }

    /**
     * Extract the reservationitems_id from a checkbox named "item[{id}]".
     *
     * @param {HTMLElement} row
     * @returns {number|null}
     */
    function extractItemId(row) {
        const checkbox = row.querySelector('input[type="checkbox"][name^="item["]');
        if (!checkbox) return null;
        const match = checkbox.name.match(/^item\[(\d+)\]$/);
        return match ? parseInt(match[1], 10) : null;
    }

    /**
     * Build the two action buttons for a row.
     */
    function buildButtons(itemId) {
        return $(`
            <div class="d-flex gap-1 flex-nowrap">
                <button type="button"
                    class="btn btn-sm btn-warning itencheckinout-btn itencheckinout-checkout"
                    data-action="checkout"
                    data-item-id="${itemId}"
                    title="${window.itencheckinout_i18n?.checkout ?? 'Checkout'}">
                    <i class="ti ti-logout me-1"></i>${window.itencheckinout_i18n?.checkout ?? 'Checkout'}
                </button>
                <button type="button"
                    class="btn btn-sm btn-success itencheckinout-btn itencheckinout-checkin"
                    data-action="checkin"
                    data-item-id="${itemId}"
                    title="${window.itencheckinout_i18n?.checkin ?? 'Check-in'}">
                    <i class="ti ti-login me-1"></i>${window.itencheckinout_i18n?.checkin ?? 'Check-in'}
                </button>
            </div>
        `);
    }

    function getStatusBadge(statusText) {
        const normalized = (statusText || '').toLowerCase();
        if (normalized === 'in use') {
            return '<span class="badge bg-warning text-dark">In use</span>';
        }
        if (normalized === 'reserved') {
            return '<span class="badge bg-info text-dark">Reserved</span>';
        }
        return '<span class="badge bg-success">Available</span>';
    }

    function setRowStatus($row, statusText) {
        const $cell = $row.find('td.itencheckinout-status-cell');
        if (!$cell.length) {
            return;
        }
        $cell.html(getStatusBadge(statusText));
        $cell.attr('data-status', statusText);
    }

    function loadInitialStatuses($table) {
        const itemIds = [];
        $table.find('tbody tr').each(function () {
            const id = extractItemId(this);
            if (id !== null) {
                itemIds.push(id);
            }
        });

        if (!itemIds.length) {
            return;
        }

        $.ajax({
            url: STATUS_ENDPOINT,
            method: 'POST',
            dataType: 'json',
            data: {
                reservationitems_ids: itemIds,
            },
        }).done(function (response) {
            if (!response || response.success !== true || !response.statuses) {
                return;
            }
            $table.find('tbody tr').each(function () {
                const id = extractItemId(this);
                if (id === null) {
                    return;
                }
                const status = response.statuses[String(id)] || 'Available';
                setRowStatus($(this), status);
            });
        });
    }

    function startAutoRefresh($table) {
        const existingTimer = $table.data('itencheckinout-refresh-timer');
        if (existingTimer) {
            return;
        }

        const timer = setInterval(function () {
            loadInitialStatuses($table);
        }, 15000);

        $table.data('itencheckinout-refresh-timer', timer);
    }

    /**
     * Main injection function.
     * Waits for the #nosearch datatable to appear (GLPI may render it late).
     */
    function injectButtons() {
        const $nosearch = $('#nosearch');
        if (!$nosearch.length) return;

        const $table = $nosearch.find('table').first();
        if (!$table.length || $table.data('itencheckinout-injected')) return;

        // Mark as processed to prevent double injection
        $table.data('itencheckinout-injected', true);

        // Add header cells in order: STATUS then ACTIONS
        $table.find('thead tr')
            .append('<th>' + (window.itencheckinout_i18n?.status ?? 'STATUS') + '</th>')
            .append('<th>' + (window.itencheckinout_i18n?.actions ?? 'Actions') + '</th>');

        // Add cell per data row
        $table.find('tbody tr').each(function () {
            const itemId = extractItemId(this);
            if (itemId === null) {
                $(this).append('<td class="itencheckinout-status-cell" data-status=""></td>');
                $(this).append('<td></td>');
                return;
            }

            const $statusCell = $('<td class="itencheckinout-status-cell" data-status="Available">')
                .html(getStatusBadge('Available'));
            const $cell = $('<td>');
            $cell.append(buildButtons(itemId));

            // Order: status then actions
            $(this).append($statusCell);
            $(this).append($cell);
        });

        // Delegate click events on the table (handles dynamic rows if any)
        $table.on('click', '.itencheckinout-btn', function () {
            const $btn   = $(this);
            const action = $btn.data('action');
            const itemId = $btn.data('item-id');
            doAction(action, itemId, $btn);
        });

        loadInitialStatuses($table);
        startAutoRefresh($table);
    }

    // Run on DOM ready; also observe mutations for dynamically rendered tables
    $(function () {
        injectButtons();

        // Observe #nosearch being added/changed by GLPI's dynamic rendering
        const observer = new MutationObserver(function () {
            injectButtons();
        });
        observer.observe(document.body, { childList: true, subtree: true });
    });

}(jQuery));
