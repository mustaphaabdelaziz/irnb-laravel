# Thermal payment receipt (80mm) + receipt verification — design

**Date:** 2026-10-22
**Status:** Approved (owner decisions below)

## Need

Print a payment ticket on the club's thermal printer ("80x60" roll = 80mm wide, 60mm roll
diameter) with: player name, payment date, club logo, branch, the subscription paid, a QR code,
and everything needed to investigate a paper receipt later.

## Owner decisions

| Question | Decision |
|---|---|
| Paper | 80mm roll, page height follows content (not a fixed 60mm label) |
| QR content | Verification code + in-app "Verify a receipt" page (works offline) |
| Branch shown | Player's branch(es) |
| Reprints / A4 | Every print logged; 1st = ORIGINAL, then DUPLICATA n°X; A4 receipt kept |

## Design

- `receipt_prints` table: one row per paper (thermal or A4) with a random 12-digit `code`,
  `copy_number` (shared across formats), `printed_by_user_id`, and a `snapshot` of what the paper
  states. `transaction_id` is `nullOnDelete`; `transaction_number` keeps the id so the evidence
  outlives the transaction.
- QR holds the 12 digits only. A USB scanner "types" the QR; on AZERTY / Arabic keyboards letters
  and punctuation come out wrong, digits can be mapped back (`ReceiptPrinter::normalizeCode`).
  The code is a lookup key, not an HMAC, so it survives an APP_KEY change (desktop reinstall).
- Reopening the same receipt within 60s (viewer reload, double click) reuses the row.
- "Remaining" on the ticket = net owed − payments up to and including this one (date, then id),
  so reprints match the original.
- Verify page (`/receipts/verify`, transactions → view): valid / modified (field-by-field diff:
  amount, date, type, status, method, player, subscription line, cancellation) / cancelled /
  deleted (snapshot shown) / unknown code. Lists every copy printed.
- Transaction page: ticket button (primary), A4 button, printed-copies history.
  Player page payment rows: ticket button.
- `PdfService::renderRoll`: two-pass mPDF render (measure on a tall page, re-render cut to
  height, auto page break off). Ticket view is black-only (thermal heads print no grey).
- Finance reset wipes `receipt_prints` with the transactions.
- Receipts (A4 and ticket) now follow the app locale for direction (RTL only in Arabic).
