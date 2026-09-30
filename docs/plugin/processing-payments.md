# Processing payments

Since there's no payment processor, nothing tells the plugin when a transfer actually arrives — you have to look at your bank statement yourself. Doing that one payment at a time from the [payment history](/plugin/payment-statuses) works, but it doesn't scale much past a handful of payments a week. **Process payments** (**QR Payments → Process payments**) speeds that up: upload your bank's own export, and the plugin matches each line against your payments and tells you what to do with it.

<!-- ![The Process payments page with a bank export uploaded, showing the parsed table with the "Matching payment" and "Actions" columns](../public/images/process-payments-overview.png) -->
_Screenshot: the Process payments page after uploading a file — the full table, including the "View" and "Auto process" buttons above it and a handful of rows in the "Matching payment" column._

## Uploading a statement

The page starts with a single file field. It accepts both:

- **CSV** exports (any delimiter — comma, semicolon or tab; the plugin sniffs it automatically), and
- **Excel** exports (`.xlsx` / `.xls`).

Nothing about the column layout needs to match in advance — upload the export exactly as your bank gives it to you, extra columns and all.

<!-- ![The empty upload field and "Start processing" button at the top of the Process payments page](../public/images/process-payments-upload.png) -->
_Screenshot: the page before anything's been uploaded — just the file field and the "Start processing" button._

## How columns are detected

After upload, the plugin looks through the file's columns for three things:

| Column | How it's found |
| --- | --- |
| **Reference** | Content match first — a column containing your own structured payment references (`RF18…`, generated for every payment) is a near-certain signal. Falling back to header names like *referentie*, *mededeling*, *communication*, *description*. |
| **Amount** | Header names like *bedrag*, *amount*, *montant*, *value*, *credit*, *debit* first, falling back to a column where most cells look like a plain monetary value. |
| **Date** | Header names like *datum*, *boekdatum*, *valutadatum*, falling back to a column of date-shaped values. Only used to tell two otherwise-identical transactions apart (see [Re-uploading a statement](#re-uploading-a-statement-is-safe) below) — never shown or required. |

None of this needs an exact column name — it's a best guess from the file's actual content, and it's only ever used to *pre-select* things for you, never to silently assume something it isn't sure about.

## Choosing which columns to show

Bank exports usually carry more columns than you care to look at. The **View** button (with the sliders icon, next to Auto process) opens a panel to control that:

- A checkbox list of every column in the file — check the ones you want visible, drag to reorder them.
- **Matching payments only** — a lightswitch that hides every row without a matched payment, so you're only looking at the transactions that concern you.
- **Save view** persists both of those for this exact file (by its filename) — upload the same export again later and your choices come back automatically.

<!-- ![The "View" panel open, showing the draggable column checklist and the "Matching payments only" lightswitch above it](../public/images/process-payments-view-menu.png) -->
_Screenshot: the View disclosure menu open, with a few columns checked/unchecked and the lightswitch visible above the list._

**Start over** clears the current upload and returns to the empty file field — nothing you've already recorded is affected, and the file's saved view is still there if you upload it again.

## The "Matching payment" column

For every row the plugin could match to one of your payments, this column shows two things stacked compactly:

- **Now** — the payment's current status and how much of it has been paid so far.
- Where something can be sensibly suggested, a second line: the suggested action, an arrow, and what the status would become if you took it — for example `Add payment → Partially paid`, or `Mark as paid → Paid`.

The second line is left out — deliberately, not just blank — whenever there's nothing confident to suggest: no amount column, a cell that doesn't parse as a plain number, or a payment that's already settled and this amount doesn't look like a duplicate refund. A row that already reflects an import you ran earlier just shows **Already processed**, both here and in place of the action buttons.

## Taking action

Two ways to actually record something:

- **Per row**, using the same **Mark as paid** / **Add payment** / **Refund** / **Cancel** buttons as the payment's own edit page — see [Payment statuses](/plugin/payment-statuses) for what each one does.
- **Auto process** (top of the page) applies every row's suggested action in one go, after a confirmation prompt. Rows with nothing to suggest, or already processed, are left alone.

## Re-uploading a statement is safe

You'll often download overlapping exports — this week's, then next month a full statement that includes those same transactions again. Auto process won't record the same transaction twice: every applied entry is fingerprinted from its reference, amount and (when detected) date, so a transaction it's already seen is recognized and skipped rather than treated as a fresh, unexpected incoming payment.

That fingerprinting is also what makes rows split across two lines behave correctly — say a customer pays a large order in two transfers. Processing both in the same run accounts for the first one before deciding what the second suggests, so you get `Partially paid` then `Paid` rather than two independent, conflicting suggestions.
