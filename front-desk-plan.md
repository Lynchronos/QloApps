# Front Desk Module (qlofrontdesk) — Implementation Plan

## 1. Decisions Locked (from PRD review + user Q&A)

| Decision | Choice |
|---|---|
| Module folder | `modules/qlofrontdesk/` |
| Tech stack | **Existing stack**: PHP (PrestaShop 1.6/QloApps core), MySQL (via `Db`/`ObjectModel`), Smarty 3 admin templates, jQuery 1.11 + jQuery UI (draggable/droppable/sortable bundled), Bootstrap admin theme, TCPDF for PDFs. **Not** the PRD's Astro/Vue/Laravel/Postgres. |
| Data model | **Reuse + extend** — read/write existing `htl_branch_info` (properties), `htl_room_type` + products (room types), `htl_room_information` (rooms), `htl_booking_detail` (bookings), PrestaShop `orders`/`order_detail`/`order_payment` (billing), `customer` (guests). Only PRD-only fields live in new `_DB_PREFIX_.qlofd_*` extension tables. |
| Core tables | **Extension tables only** — no alterations to core `htl_*` tables. |
| Walk-in booking | Reuse Customer + cart → `validateOrder()` → `htl_booking_detail` chain (same as `AdminOrdersController` manual order). |
| Grid | New separate page `index.php?controller=AdminFrontDesk` (rooms as rows, dates as columns, booking bars); "Book Now" stays untouched. |
| Menu | Top-level root tab "Front Desk" (positioned first) + one "Front Desk Settings" child tab. |
| Scope | **All PRD features** incl. the 3 AI-assisted features as rule-based/cheap implementations. |

## 2. Tech-Stack Conventions to Follow (verified in codebase)

- Main class `modules/<name>/<name>.php` with `install()`, `uninstall()`, `callInstallTab()`, `installTab($class_name, $tab_name, $parent)`, `registerModuleHooks()` (see `wkhotelroom.php`).
- `define.php` includes `hotelreservationsystem/define.php` (hotel module installed first) + own `classes/*.php`.
- Admin controllers: `controllers/admin/AdminFrontDeskController.php` extends `ModuleAdminController` (see `AdminHotelRoomsBookingController`). Tabs are DB-backed; class autoloaded from module.
- Templates: `views/templates/admin/<controller_lower>/...`; fetch via `$this->context->smarty->fetch(...)` or `helpers/view/view.tpl` pattern. All UI strings via `{l s='...' mod='qlofrontdesk'}` / `$this->l()`.
- JS/CSS: `views/js/admin/*.js`, `views/css/admin/*.css`; loaded in `setMedia()` with `$this->addJs(...)`, `$this->addCSS(...)`; JS defs via `MediaCore::addJsDef(...)`.
- AJAX: `public function ajaxProcess<X>()` + `$this->ajaxDie(json_encode(...))`; POST via `Tools::getValue`; validate with `Validate::*`, `pSQL()`, `(int)`.
- Access control: `HotelBranchInformation::filterDataByHotelAccess($data, $employee->id_profile, 'id')` and `getProfileAccessedHotels()`.
- Actor: `$this->context->employee`; audit writes `id_employee`.
- PDF: `classes/pdf/HTMLTemplate.php` + `PDFGenerator` (TCPDF at `tools/tcpdf`). Follow `HTMLTemplatePaymentReceipt` pattern; create a module-owned subclass for folio receipts (no core edits).
- Cron: `qlocrontaskmanager` discovers modules implementing `hookRegisterCronTasks()`; tasks = `['name','description','cron','callback']`; QloCronTaskManager dispatches `$module->{$callback}()`.
- Cache: clear `cache/class_index.php` + `cache/smarty/compile` + `cache/smarty/cache` after adding classes/templates/overrides.

## 3. New Database Tables (all prefixed with `_DB_PREFIX_` + `qlofd_`)

Created in `classes/QlofdDb.php::getModuleSql()` (shape mirrors `HotelReservationSystemDb`).

```sql
qlofd_booking_extra        -- one row per htl_booking_detail.id
  (id PK, id_htl_booking INT, is_locked_assignment TINYINT(1) DEFAULT 0,
   is_group_booking TINYINT(1) DEFAULT 0, payment_status ENUM('pending','partial','paid') DEFAULT 'pending',
   is_no_show TINYINT(1) DEFAULT 0, no_show_risk_score DECIMAL(4,3), no_show_risk_label ENUM('low','medium','high'),
   no_show_risk_updated_at DATETIME, date_add, date_upd,
   UNIQUE(id_htl_booking))

qlofd_housekeeping_log
  (id PK, id_room, status ENUM('clean','dirty','inspected','do_not_disturb'),
   note TEXT, id_employee, date_add)

qlofd_audit_log
  (id PK, entity_type VARCHAR(32), entity_id INT, action VARCHAR(64),
   before_data TEXT, after_data TEXT, id_employee, date_add)

qlofd_folio_line
  (id PK, id_htl_booking, label VARCHAR(255), qty INT, unit_price_tax_excl DECIMAL(20,6),
   unit_price_tax_incl DECIMAL(20,6), type ENUM('charge','credit') DEFAULT 'charge',
   id_employee, date_add, date_upd)

qlofd_folio_payment        -- linked to booking
  (id PK, id_htl_booking, id_order_payment INT, amount DECIMAL(20,6), payment_method VARCHAR(128),
   reference VARCHAR(128), id_currency INT, id_employee, date_add)

qlofd_ota_rate             -- OTA rate feed storage for parity (source TBD)
  (id PK, id_hotel, id_product, date DATE, currency VARCHAR(8), ota_name VARCHAR(128),
   rate DECIMAL(20,6), commission_pct DECIMAL(5,2), date_add, date_upd,
   UNIQUE(id_hotel, id_product, date, ota_name))

qlofd_parity_alert
  (id PK, id_hotel, id_product, date DATE, own_rate DECIMAL(20,6), ota_rate DECIMAL(20,6),
   ota_name VARCHAR(128), issue_type ENUM('direct_not_competitive','ota_more_profitable'),
   resolved TINYINT(1) DEFAULT 0, date_add)
```

Rules:
- Current housekeeping status = latest row per room in `qlofd_housekeeping_log`.
- Own rate = live product/feature pricing (`HotelRoomTypeFeaturePricing::getRoomTypeTotalPrice`), never a copy.
- Undo: **session-scoped** (per employee), no table — `QlofdUndo` stores last N actions in PHP session, replayable via audit-log inverse.
- No SQL `DROP`/`TRUNCATE` in module code paths except uninstall's own `qlofd_*` tables.

## 4. Files To Create

```
modules/qlofrontdesk/
├── qlofrontdesk.php                 # main module: install/uninstall/tabs/hooks/cron
├── define.php                       # includes hotelreservationsystem/define.php + own classes
├── index.php
├── config.xml                       # module metadata
├── README.md (optional)
├── classes/
│   ├── QlofdDb.php                  # DDL + createTables/dropTables
│   ├── QlofdBookingExtra.php        # ObjectModel over qlofd_booking_extra
│   ├── QlofdHousekeepingLog.php     # ObjectModel + helpers
│   ├── QlofdAuditLog.php            # ObjectModel + record()
│   ├── QlofdFolioLine.php           # ObjectModel
│   ├── QlofdFolioPayment.php        # ObjectModel
│   ├── QlofdFolioService.php        # folio calc: lines+order charges+payments → balance
│   ├── QlofdGrid.php                # grid data builder for AdminFrontDesk
│   ├── QlofdBookingFlow.php         # create/move/swap/status ops; wraps core + audit
│   ├── QlofdNoShowRisk.php          # scoring heuristics (batch + on-demand)
│   ├── QlofdRateParity.php          # parity job + alert management
│   ├── QlofdRoomAssignment.php      # greedy suggestion algorithm
│   ├── QlofdUndo.php                # session-scoped undo queue
│   ├── QlofdReceiptPdf.php          # PDF receipt (HTMLTemplate subclass)
│   └── index.php
├── controllers/admin/
│   ├── AdminFrontDeskController.php         # main grid + all ajax
│   ├── AdminFrontDeskSettingsController.php # config
│   └── index.php
├── views/
│   ├── templates/admin/front_desk/
│   │   ├── view.tpl
│   │   ├── helpers/view/view.tpl
│   │   ├── _partials/booking-bar.tpl
│   │   ├── _partials/booking-modal.tpl
│   │   ├── _partials/folio-panel.tpl
│   │   ├── _partials/housekeeping-pop.tpl
│   │   └── index.php
│   ├── templates/admin/front_desk_settings/settings.tpl (or HelperForm)
│   ├── js/admin/front_desk.js
│   ├── js/admin/front_desk_settings.js (if needed)
│   ├── css/admin/front_desk.css
│   └── index.php
└── upgrade/ (files as needed for later versions)
```

Error pages: mirror `index.php` stubs in each dir (standard QloApps convention).

## 5. AdminFrontDeskController Behavior

### 5.1 Page render (grid)
- `init()`: default employee login (inherited); guard `tabAccess['view']`.
- `setMedia()`: module css/js + jQuery UI draggable/droppable + (optionally FullCalendar only if reused).
- `renderView()`: build grid model via `QlofdGrid`:
  1. window dates: `date_from = requested or today`, `date_to = date_from + (QLOFD_WINDOW_DAYS-1)`.
  2. hotels accessible to profile; selected hotel or first; room types; all active rooms of hotel (+ rooms with `id_status` temp-inactive shown as hatched via disable dates).
  3. bookings overlapping window from `htl_booking_detail` + join with `orders`/`customers` for guest name/email/payment totals; join `qlofd_booking_extra`.
  4. housekeeping current status per room; OOO bars from `htl_room_disable_dates` overlaps.
  5. group indicator: ≥ threshold same `id_order`/guest overlapping window; payment pill: paid vs total → pending/partial/paid.
  6. no-show risk label for arrivals within next query-72h (from `QlofdNoShowRisk::scoreForBooking` on demand or stored).
- Assemble JSON map: `rooms[]` (id, num, type, floor, housekeeping), `dates[]`, `booking_bars[]` (id_htl_booking, id_room, from, to, status, customer, amount, flags, notes), `ooo[]`, `room_types[]`.
- Filters (server-side re-render on submit): hotel, room type, status filter.

### 5.2 AJAX endpoints (all `ajaxProcess*`)
| Action | Description |
|---|---|
| `getGridData` | JSON for window: bars/ooo/housekeeping (used for optimistic refresh) |
| `createBooking` | drag-across-empty cells → modal (guest search/create, dates, room type+rate, discount, deposit) → reuse cart → `validateOrder` → create `htl_booking_detail` + `qlofd_booking_extra` + audit |
| `updateBookingDates` | drag bar to new range → change `date_from/date_to` (reuse existing date-change cost logic) + audit |
| `moveBookingRoom` | drag to another row → set `id_room`/`room_num` on `htl_booking_detail` + audit |
| `swapBooking` | drag bar onto another → reuse `HotelBookingDetail::swapBooking` + audit |
| `setBookingStatus` | check-in/check-out/cancel/no-show (reuse rules from `AdminOrdersController::changeRoomStatus` + `HotelBookingDetail` statuses) + audit |
| `toggleLockAssignment` | toggles `qlofd_booking_extra.is_locked_assignment`; server rejects drag for locked bookings |
| `setHousekeeping` | POST status → insert `qlofd_housekeeping_log`; update badge |
| `toggleOoo` | insert/delete `htl_room_disable_dates` (reason) → hatched row |
| `getFolio` / `folioAddLine` / `folioUpdateLine` / `folioDeleteLine` | CRUD on `qlofd_folio_line`, recalc balance via service |
| `addPayment` | insert `qlofd_folio_payment` + `order_payment`; update order totals |
| `printReceipt` | generate receipt PDF → stream download |
| `undo` | pop session queue, revert last action |
| `roomAssignSuggest` | return suggested room for modal |

All mutating endpoints: `tabAccess['edit']` check → transaction where needed → write audit → push undo.

## 6. Folio / Billing Panel (inline side panel)

- Opens when booking bar selected (inline side panel within grid page, or expandable row).
- Summary: guest, dates, room, rate → line items:
  - room charge auto (from order detail × nights, read-only)
  - extras read from `htl_booking_demands` (existing) if present; our own from `qlofd_folio_line`
  - taxes shown separately
- Payments: list payments from `qlofd_folio_payment` (and order_payment) + methods; "Add Payment" form: amount, method, reference; updates order totals.
- Running balance: charche-items +/- credits - payments, shown with currency.
- Payment status pill (pending/partial/paid) refreshed from order totals.
- Receipt: `QlofdReceiptPdf` - standalone HTMLTemplate subclass + PDFGenerator; filename `receipt-<booking_ref>-<id>.pdf`.
- Currency: shop default; if order currency differs, show rate.
- UI: uses existing admin theme styles; modal or push panel; JS real-time total react + AJAX persist.

## 7. Rule-Based "AI" Features (cheap, no trains)

### 7.1 Rate-parity (cron every 3h via `runRateParityJob`)
- Read `qlofd_ota_rate` for window ±14d per hotel/room type; compare with own live rate from existing pricing (`HotelRoomTypeFeaturePricing`).
- Flag when own ≥ ota×(1+margin%) (direct_not_competitive) or ota net (ota×(1−commission%)) less profitable.
- Create `qlofd_parity_alert` rows; badge on room-type rows + notification list in page; resolved toggle.

### 7.2 No-show risk (cron daily; `runNoShowRiskJob`)
- Features: lead time (creation→arrival), payment status, channel (channel manager order?), repeat guest (order history), same-day booking, group size ≥2.
- Weighted score 0..1 → label low/med/high; thresholds config.
- Tooltip badge on booking bars where arrival ≤72h; on-demand `scoreForBooking(id)` updates.

### 7.3 Auto room-assignment (synchronous)
- `QlofdRoomAssignment::suggest(...)`: candidates = active rooms free in window; score = -(fragmentation of remaining free blocks) + small bias; pick max; returns suggested room to pre-fill modal, user can override via grid.

## 8. Permissions & Data Scoping

- Tab access per profile (standard `tabAccess`).
- Property scoping: only hotels accessible to employee profile (via `filterDataByHotelAccess`).
- All SQL: `_DB_PREFIX_`, `pSQL`, `(int)`. No concatenation of raw user input.
- Guests use core `customer` + addresses; never create separate guest store.
- No core file modifications — zero edits outside `modules/qlofrontdesk/`.

## 9. Settings (AdminFrontDeskSettingsController)

Store via `Configuration` keys `QLOFD_*`:
- `QLOFD_WINDOW_DAYS` (default 14, min 7, max 21)
- `QLOFD_GROUP_BOOKING_THRESHOLD` (default 2)
- `QLOFD_PARITY_MARGIN_PCT` (default 5)
- `QLOFD_PARITY_COMMISSION_MIN/MAX` (default 15/25)
- `QLOFD_NO_SHOW_*` weights/thresholds (lead-time, no-payment, channel, first-time, same-day, group)
- `QLOFD_UNDO_LIMIT` (default 10)
- `QLOFD_CURRENCY_CODE` (optional; else default shop)
- OTA rate CSV import (upload → map → store `qlofd_ota_rate`) in settings page.

## 10. i18n & Style

- All UI strings through `$this->l()` / `{l s=... mod='qlofrontdesk'}`.
- Use existing admin theme classes (btn/panel/form-control), match naming style of `HotelReservationAdmin.css`.

## 11. Open Questions (non-blocking; logged as TODOs)

- OTA rate feed source (channel manager decision in flight) → CSV import first; pluggable provider later.
- Multi-currency (USD/EUR for guests vs UZS local) - existing currency system; final accounting practice TBD.
- Historical data for no-show training - heuristic first; optional LR later if data grows.

## 12. Risks / Guards

- Do NOT modify core files of hotelreservationsystem or core classes; module additive only.
- Drag on mobile: jQuery UI is mouse-first; add tap-to-select fallback in polish phase.
- Backdate/overbooking/refund guards: reuse existing validations in core flow (`changeRoomStatus`, `getAvailableRoomsForReallocation`, etc.).
- Clear caches (`cache/class_index.php`, smarty compiles) after install/upgrade.
- Never expose secrets or raw SQL of user input.
- Tests: run `php -l` over all module files, install on fresh DB, exercise each feature.

## 13. Build Order (implementation steps)

1. **Scaffold** module skeleton: folder, `qlofrontdesk.php`, `define.php`, `config.xml`, install/uninstall tabs (+ parent root), hooks: `displayBackOfficeHeader` (CSS), register cron tasks via `hookRegisterCronTasks`. Install via back office; verify tab appears.
2. **DB layer**: `QlofdDb` createTables; ObjectModels for all `qlofd_*` tables; clear cache.
3. **Grid core**: `QlofdGrid` queries + controller `view.tpl` with bars, housekeeping badge, OOO hatch, tooltips, filters, legend.
4. **Interactions**: booking bar drag (move/swap/dates), drag-across-create modal (guest search/create, rate, deposit), status buttons (check-in/check-out/cancel/no-show), lock flag, note editor. Wire `QlofdBookingFlow` + audit + undo (session).
5. **Folio panel**: `folio-panel.tpl`, add/edit lines, payments, balance, receipt PDF, payment status pill refresh.
6. **Housekeeping + OOO**: badge popover quick statuses, OOO toggle with reason, disable-dates mapping, log.
7. **Rule-based features**: no-show scoring (cron + endpoint), rate-parity (CSV import + cron + alerts + badge), auto-assign suggestion, settings controller.
8. **Undo polish + keyboard shortcuts**: Ctrl+Z, Ctrl+N (new booking), etc.
9. **Polish**: mobile tap fallback, loading/empty states, cache clearing, README, `php - l` lint pass.
10. **Testing**: fresh DB install + module CSV import; create sample hotels/rooms/bookings via admin UI/manual SQL (dev only, no DROP of existing); verify grid/bars/folio/payments/receipt; run cron dispatches; QA list in AGENTS.md style.

## Definition of Done

- Module installs without errors; back-office shows "Front Desk" tab; grid renders live bookings.
- Create/drag/swap/status changes work end-to-end to `htl_booking_detail` via order flow; audit logged; undo restores.
- Folio panel shows charges/payments/balance; payment recorded to `order_payment`; receipt PDF downloads.
- Housekeeping badge + OOO hatch update and persist; audit trail visible in a log view.
- Rate-parity + no-show + auto-assign respond as rules; cron tasks appear in QloCronTaskManager.
- `git status` clean except `modules/qlofrontdesk/**` and this plan file (i.e., no core edits).