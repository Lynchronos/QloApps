# QloFrontDesk

Front Desk operations module for QloApps Hotel PMS: a room-by-date operations grid for
front-desk staff.

## Features

- **Room timeline grid** — rooms as rows, dates as columns, draggable booking bars.
- **Booking operations** — create walk-in bookings (cart → `validateOrder`), move rooms,
  swap rooms, change dates, check-in / check-out / cancel / no-show, lock assignment,
  booking notes.
- **Folio / billing** — room charges (read-only), booking demands, custom charge/credit
  lines, payments (recorded to `order_payment`), running balance, payment status pill,
  TCPDF receipt.
- **Housekeeping** — quick room status badges (clean / dirty / inspected / do-not-disturb)
  with an audit log; checked-out rooms auto-flagged dirty by cron.
- **Out-of-order** — drag-select a date range in OOO mode to block a room
  (`htl_room_disable_dates`).
- **Rule-based helpers** — no-show risk scoring, rate-parity alerts vs imported OTA CSV,
  auto room-assignment suggestion.
- **Undo** — session-scoped undo (Ctrl+Z) for the last N operations.
- **Audit log** — every mutating action recorded with employee.

## Installation

1. Requires **hotelreservationsystem** (and `qlocrontaskmanager` for the scheduled jobs).
2. Copy `qlofrontdesk` into the `modules/` directory.
3. Install via *Modules → Modules → Installed modules*.
4. Clear caches:
   ```bash
   rm -rf cache/smarty/compile/* cache/smarty/cache/*
   rm -f cache/class_index.php
   ```
5. A root *Front Desk* tab appears first in the admin menu, with a *Front Desk Settings*
   child tab.

## Scheduled jobs (QloCronTaskManager)

| Task | Schedule | Callback |
|---|---|---|
| Rate parity check | every 3 hours | `runRateParityJob` |
| No-show risk refresh | daily 03:00 | `runNoShowRiskJob` |
| Flag checked-out rooms dirty | daily 12:00 | `runHousekeepingDirtyJob` |

## OTA rate CSV import

In *Front Desk Settings* upload a CSV with columns:

```
id_hotel,id_product,date,currency,ota_name,rate,commission_pct
1,4,2026-09-01,USD,Booking.com,89.00,15
```

Run parity comparisons manually (`Run parity check now`) or via the cron job.

## Database

Extension tables only — no `htl_*` table is modified:

- `qlofd_booking_extra`
- `qlofd_housekeeping_log`
- `qlofd_audit_log`
- `qlofd_folio_line`
- `qlofd_folio_payment`
- `qlofd_ota_rate`
- `qlofd_parity_alert`

## Notes

- All UI strings are translatable through `{l s='...' mod='qlofrontdesk'}` /
  `$this->l()`.
- Guests reuse the core `customer` store; no separate guest store is created.
- No core file is modified by this module.