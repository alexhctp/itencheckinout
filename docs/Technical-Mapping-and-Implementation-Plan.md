# Technical Mapping and Implementation Plan

## 1) Technical mapping of the target screen

Target screen:
- `/front/reservationitem.php`

Execution flow:
1. `/front/reservationitem.php` checks rights and calls `ReservationItem->display($_GET)`.
2. The list of reservable items is built by `ReservationItem::showListSimple()`.
3. Table is rendered via `components/datatable.html.twig` with columns:
   - item
   - location
   - comment
   - entity (when multi-entity mode)
   - calendar

Core file references:
- `/front/reservationitem.php`
- `/src/ReservationItem.php` (method `showListSimple()`)
- `/templates/pages/tools/find_available_reservation.html.twig`

Important finding:
- There is no direct per-row plugin hook in `ReservationItem::showListSimple()`.
- Therefore, to add per-row Checkout/Checkin buttons without changing core, the safest MVP path is:
  - inject UI actions with plugin JS (loaded globally, activated only on this page), and
  - process actions in plugin backend endpoints with full server-side validation.

## 2) Relevant GLPI plugin extension points

Confirmed useful hooks in GLPI 11:
- `Glpi\\Plugin\\Hooks::ADD_JAVASCRIPT`
- `Glpi\\Plugin\\Hooks::ADD_CSS`
- `Glpi\\Plugin\\Hooks::POST_INIT` (optional)
- `csrf_compliant`

Reference implementations:
- `/marketplace/tag/setup.php`
- `/marketplace/purchaserequest/setup.php`

## 3) Data model mapping for reservation rules

### Core GLPI Reservation Schema (Confirmed)
**glpi_reservationitems** (ReservationItem master):
- `id` INT PK
- `itemtype` VARCHAR (type of item: 'Computer', 'Monitor', etc)
- `items_id` INT (FK to specific item in its table)
- `comment` TEXT
- `is_active` TINYINT (0=unavailable, 1=available)
- `entities_id` INT
- `is_recursive` TINYINT
- `date_creation`, `date_mod`, `date_deleted`

**glpi_reservations** (Reservation instance):
- `id` INT PK
- `reservationitems_id` INT (FK ReservationItem)
- `users_id` INT (user who made the reservation)
- `begin` DATETIME (reservation start window)
- `end` DATETIME (reservation end window)
- `comment` TEXT
- `group` INT (for periodic reservations)
- `date_creation`, `date_mod`

### MVP Business Rule Mapping
- **Checkout eligible**: Exists active Reservation with NOW in [begin, end] AND is_active=1
- **Checkin eligible**: Exists prior checkout movement AND no prior checkin movement
- **Timezone handling**: Use `Session::getCurrentTime()` (respects GLPI timezone config)
- **Tolerance logic**: If NOW > end + tolerance_minutes (configurable), reject checkout with clear message

### Plugin Persistence Model
**glpi_plugin_itencheckinout_movements** (new table):
- `id` INT PK
- `reservations_id` INT NOT NULL (FK glpi_reservations)
- `reservationitems_id` INT NOT NULL (denormalized for quick lookup)
- `action` ENUM('checkout','checkin') NOT NULL
- `users_id_actor` INT NOT NULL (who performed the action)
- `date_action` DATETIME NOT NULL (when action was performed, uses GLPI timezone)
- `entities_id` INT NOT NULL
- `date_creation` DATETIME
- `date_mod` DATETIME

**Indexes**:
- PK on `id`
- Index on (`reservations_id`, `action`) for quick state machine validation
- Index on (`reservationitems_id`) for listing movements per item

## 4) Authorization model for MVP

### Actor Rules (Decided)
**Actor A**: Owner of the reservation
- Check: `reservation.users_id == Session::getLoginUserID()`

**Actor B**: Manager/Equipment Keeper
- Check: User is member of GLPI group `equipment_keeper`
- Lookup: Find group by name, then check group membership in `glpi_groups_users`
- See setup details in: [Setup-equipment_keeper-Group.md](Setup-equipment_keeper-Group.md)

### Implementation Logic
Both actor types are valid for checkout/checkin. No role conflict.

```php
// Pseudocode for authorization check
$is_owner = $reservation->users_id === Session::getLoginUserID();

$equipment_keeper_group = Group::find(['name' => 'equipment_keeper']);
$is_manager = $equipment_keeper_group 
    ? current_user_is_member_of($equipment_keeper_group['id']) 
    : false;

$is_authorized = $is_owner || $is_manager;
```

### Multi-entity considerations
- Managers in `equipment_keeper` group can checkout/checkin items from any entity they have access to (respecting entity hierarchy).
- Users should only see reservations and items from their accessible entities.
- Plugin must check `Session::haveAccessToEntity()` after authorization.

## 5) Implementation architecture (MVP-safe)

### 5.1 UI layer (plugin JS)
- Register JS in plugin setup via `ADD_JAVASCRIPT`.
- JS runs only when URL path is `/front/reservationitem.php`.
- JS finds reservation table rows and injects an additional "Actions" column with two buttons:
  - Checkout
  - Checkin
- Buttons call plugin endpoint using POST + CSRF token.
- Display only final success/error message (as requested).

### 5.2 Backend layer (plugin endpoint)
- Create endpoint in plugin front path, for example:
  - `/plugins/itencheckinout/front/movement.php`
- Input:
  - `reservationitems_id`
  - `action` (checkout|checkin)
- Server flow:
  1. Session and right checks.
  2. Resolve active reservation candidate(s) for current user/time window logic.
  3. Authorize actor by owner-or-manager rule.
  4. Validate action transition:
     - checkout allowed only in reservation window and if no prior checkout
     - checkin allowed only if prior checkout exists and no prior checkin
  5. Insert movement log row.
  6. Return JSON result or redirect message (MVP can use redirect flash message).

### 5.3 Domain service layer (recommended)
- Add service class in plugin src, example:
  - `GlpiPlugin\\ItenCheckInOut\\Service\\MovementService`
- Keep all business checks centralized there.
- Endpoint remains thin controller.

## 6) Ordered implementation plan

Phase 1: Plugin bootstrap for integration
1. Update `setup.php` to:
   - enable csrf compliant
   - register JS hook (`ADD_JAVASCRIPT`)
2. Add minimal composer autoload for plugin namespace.

Phase 2: Database and install lifecycle
1. Implement table creation on plugin install in `hook.php`.
2. Implement clean drop strategy on uninstall (or keep data by policy).
3. Add migration-safe checks (create-if-not-exists).

Phase 3: Backend action endpoint
1. Create `front/movement.php`.
2. Implement POST handling for checkout/checkin.
3. Add server-side validations (window, actor, state machine).
4. Return user-facing success/error message.

Phase 4: UI action injection
1. Create plugin JS file (ex: `public/js/reservationitem-actions.js`).
2. Detect target table and append action buttons per row.
3. Extract row `reservationitems_id` from checkbox/input naming.
4. Submit secure requests to endpoint.

Phase 5: Hardening and tests
1. Add PHP unit/integration tests for service validation rules.
2. Validate duplicate-click behavior and idempotency.
3. Validate multi-entity behavior.
4. Validate unauthorized user denial path.

## 7) Main technical risks and mitigations

Risk A: DOM structure changes in future GLPI patch.
- Mitigation: selector strategy with fallbacks and defensive checks.

Risk B: Ambiguous active reservation when multiple reservations overlap due periodic groups.
- Mitigation: deterministic selection order (closest begin <= now), and explicit error if ambiguous.

Risk C: "manager/validator" definition not finalized.
- Mitigation: MVP uses reservation UPDATE right; later replace with plugin profile rights.

Risk D: race condition on quick double click.
- Mitigation: unique index + transactional insert + idempotent check.

## 8) Decisions Consolidated (v1.1)

### Decision 1: Manager/Validator Identification
**Decided**: User membership in GLPI group `equipment_keeper`.
- Group must be created manually in GLPI admin.
- Users assigned to this group gain checkout/checkin rights for all items.
- See details in: [Setup-equipment_keeper-Group.md](Setup-equipment_keeper-Group.md)

### Decision 2: Reservation-to-Item Relationship
**Decided**: Schema mapping confirmed:
- `glpi_reservationitems.id` = reservable item instance
- `glpi_reservationitems.itemtype` + `.items_id` = concrete asset (Computer, Monitor, etc)
- `glpi_reservations.reservationitems_id` = FK to reservation item
- `glpi_reservations.{users_id, begin, end}` = reservation details
- MVP flow: For each ReservationItem ID, find active Reservations where NOW ∈ [begin, end]

### Decision 3: Timezone Policy
**Decided**: Use GLPI environment timezone via `Session::getCurrentTime()`.
- Respects server/config timezone settings automatically.
- Refinement (UTC offset override) possible in v1.2+.

### Decision 4: Expired Reservation Without Checkout
**Decided**: Parametrizable tolerance window.
- New config parameter: `tolerance_minutes_after_end` (example: 60 minutes)
- After `end + tolerance`, checkout is rejected with error message
- Equipment automatically released for next reservation after tolerance expires
- Messaging: "Item checkout period has expired. This equipment is now available for the next reservation."

## 9) Implementation order adjusted

### Clarifications on remaining open points:
1. **Endpoint response style**: JSON response with browser flash message (via Session::addMessageAfterRedirect).
2. **Uninstall policy**: Keep movement history on uninstall; only drop on clean.
3. **No active reservation scenario**: Return 409 (Conflict) with message: "No active reservation found for this item in the current time window."

### Sprint-0 (fast start, refined):
1. Phase 1: Setup hooks + csrf compliance (setup.php)
2. Phase 2: Install table creation + bootstrap schema (hook.php)
3. Phase 3: Backend MovementService business logic (src/Service/MovementService.php)
4. Phase 4: Endpoint front/movement.php (POST handler)
5. Phase 5: Debug page to manually test checkout/checkin (front/debug.php - removable later)
6. Phase 6: JS row injection (public/js/reservationitem-actions.js)
7. Phase 7: Unit tests for service layer
8. Phase 8: Manual E2E test with real data

This order validates business rules early before UI injection.

### Risk C Mitigation Update
Risk C mentioned in section 7 is now RESOLVED by decision 1. Using group-based authorization instead of profile rights.
