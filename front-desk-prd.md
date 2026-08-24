# Front Desk Module — PRD & Implementation Plan

**Project:** Custom Hotel Management System — Front Desk module
**Scope:** 2 properties (40 rooms + 20 rooms), staff extranet
**Stack:** Astro + Vue + Tailwind (frontend) · Laravel (API/backend) · Postgres (DB)
**Status:** Draft v1

---

## 1. Purpose

Replace the hotel group's current Exely.com front desk workflow (slow, unintuitive, requires a separate Excel sheet for cash/billing) with a faster, simpler, purpose-built front desk that:

- Feels fast (optimistic UI, no full-page reloads for routine actions)
- Puts billing/folio in the same screen as the booking (kills the Excel step)
- Uses lightweight, resource-cheap automation (rule-based / small-model scoring — not a general-purpose LLM command interface) for the three agreed AI-assisted features
- Scales to two properties from one login without feeling like enterprise software

## 2. Non-Goals (explicitly cut, per review)

| Cut | Reason |
|---|---|
| Natural-language AI command bar | Hallucination risk on low-parameter models; VPS resources not budgeted for it |
| WhatsApp integration in front desk | Out of scope for this module |
| Voice-to-note at checkout | Out of scope for this module |
| Separate "Reserved" vs "Confirmed Reserved" statuses | Collapsed into one `reserved` status for direct/offline bookings; only OTA-sourced bookings carry a pending-confirmation flag |
| Global "customer name display format" config screen | Hardcode first name + last initial |
| Dedicated "Stop Room Move" config/admin UI | Implemented as a simple boolean + lock icon on the booking, no config screen |
| Month-view pagination at launch | Scrollable 14–21 day rolling window only; month view is a post-launch nice-to-have |

## 3. Data Model

Keep these as **separate entities** — this is the most important structural fix vs. the QloApps reference design, which conflates room condition with booking status.

```
properties (id, name, address, timezone)
room_types (id, property_id, name, base_rate, capacity)
rooms (id, property_id, room_type_id, number, floor, housekeeping_status, is_ooo, ooo_reason)
bookings (id, property_id, room_id, guest_id, channel, check_in, check_out,
          status, payment_status, rate, is_group_booking, is_locked_assignment, notes)
guests (id, name, email, phone, id_document_ref)
folios (id, booking_id, line_items[], balance, currency)
housekeeping_log (id, room_id, status, changed_by, changed_at)
audit_log (id, entity_type, entity_id, action, before, after, actor_id, timestamp)
```

- `rooms.housekeeping_status`: `clean | dirty | inspected | do_not_disturb`
- `rooms.is_ooo`: boolean, independent of any booking — a room can be OOO with zero reservations against it
- `bookings.status`: `reserved | checked_in | checked_out | cancelled | no_show`
- `bookings.payment_status`: `pending | partial | paid`

## 4. Core Front Desk Grid

- Rooms as rows, dates as columns, rolling 14–21 day scrollable window (no month pagination at launch)
- Per-property view, filtered by staff's permitted properties
- Color-coded by `bookings.status`; housekeeping status shown as a small icon/badge on the room row, independent of the booking bar
- OOO rooms shown as a distinct row treatment (hatched/greyed), not a booking-colored bar
- Drag-and-drop:
  - Create booking by dragging across empty cells
  - Change dates by dragging the booking bar
  - Reallocate/swap rooms by dragging between rows
  - All drag actions write to `audit_log` (entity, before/after, actor, timestamp) and support one-step undo (last N actions, session-scoped)
- Hover tooltip: duration, status, booking ID, payment status, guest name, group/payment indicators, notes
- Filters: booking status, property, room type, booking ID/reference, guest name/email

## 5. Folio / Billing Panel (new vs. QloApps reference — highest priority addition)

Opens inline (side panel or expandable row) when a booking is selected. This is the direct replacement for the Excel cash workflow, so it needs to be first-class, not an afterthought:

- Line items (room charge, taxes, extras) with add/edit
- Deposit and prepayment tracking, running balance
- Payment collection (mark paid/partial, method, reference)
- Currency: local currency (UZS) as primary, with rate shown for OTA bookings settled in USD/EUR if applicable
- Print/export a simple receipt (PDF)

## 6. Booking Statuses & Indicators (simplified set)

**Statuses:** `Reserved` · `Checked-in` · `Checked-out` · `Cancelled` · `No-show`
(OOO is a room attribute, not a status — see §3)

**Indicators (badges on the booking bar):**
- Group Booking (N+ rooms, same guest, same date range — threshold hardcoded to 2, no admin config needed at launch)
- Payment Pending
- Locked Assignment (replaces "Stop Room Move" — boolean + icon, no separate config screen)

## 7. AI-Assisted Features (lightweight, resource-conscious implementations)

All three are scoped to avoid LLM inference calls on the hot path — rule-based or small statistical models only, consistent with the VPS budget constraint.

### 7.1 Rate-Parity Alerts
- **What:** Compare the property's own website rate against synced OTA rates (from the channel manager feed) per room type/date; flag when direct rate is not competitive, or when an OTA booking's effective net (after 15–25% commission) is less profitable than an equivalent direct booking would have been.
- **Implementation:** Scheduled job (cron, e.g. every few hours) — simple threshold comparison against existing rate/inventory tables. No ML model needed.
- **Surface:** Badge on the room-type row in the grid + a daily summary notification to the manager.

### 7.2 No-Show Risk Scoring
- **What:** Score each upcoming reservation's no-show likelihood to help staff prioritize follow-up calls/confirmations.
- **Implementation:** Simple logistic regression or weighted heuristic over cheap, already-available features: booking lead time, payment status, channel (OTA vs. direct), history of the guest (repeat vs. first-time), same-day vs. advance booking. Trainable on your own historical booking data once you have enough volume; falls back to a rule-based heuristic (e.g., "OTA + no payment + booked >30 days out + first-time guest = higher risk") until then. Runs as a lightweight batch job, not real-time inference.
- **Surface:** Risk badge (low/med/high) on the booking tooltip for arrivals in the next 48–72 hours.

### 7.3 Auto Room-Assignment Suggestions
- **What:** When a new booking is created without a specific room chosen, suggest the room that best preserves flexibility for the rest of the week (avoids fragmenting remaining inventory) rather than just picking the first available.
- **Implementation:** Greedy/constraint-based algorithm over existing availability data — no model required. Given a stay's date range and room type, score each candidate room by how much it reduces future assignment flexibility (e.g., prefer rooms that don't split an otherwise-contiguous open block). Runs synchronously on booking creation; cheap to compute.
- **Surface:** Suggested room pre-filled when staff create a booking, with one-click override via the normal grid.

## 8. Performance Requirements (addressing the "Exely feels slow" complaint)

- Optimistic UI: drag-and-drop and status changes update the grid instantly client-side; sync to Postgres via the Laravel API in the background; roll back only on conflict/error
- Load only the visible date window's booking data — no full-month fetch on page load
- Keyboard shortcuts for routine actions (check-in, check-out, add note) to avoid modal-heavy workflows
- Mobile-responsive grid (staff walking the floor, not just at a terminal)

## 9. Build Phases

**Phase 1 — Core grid**
- Rooms × dates grid, drag-and-drop create/move/swap
- Housekeeping status as separate room attribute
- Simplified status set + audit log with undo

**Phase 2 — Billing**
- Folio panel inline with booking (replaces Excel)
- Payment collection, receipt export

**Phase 3 — AI-assisted features**
- Rate-parity alert job
- No-show risk scoring (heuristic first, model later)
- Auto room-assignment suggestion on booking creation

**Phase 4 — Polish**
- Mobile responsiveness pass
- Keyboard shortcuts
- Month view (if still wanted post-launch)

## 10. Open Questions

- Historical booking data volume/format available from Exely for training the no-show model (export needed?)
- Which channel manager feed (Beds24 / Channex / QloApps API) will supply the OTA rate data for §7.1 — depends on the channel manager decision already in progress
- Multi-currency handling scope for folios (USD/EUR OTA settlement vs. UZS local) — confirm with hotel group's actual accounting practice
