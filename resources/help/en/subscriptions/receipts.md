---
title: How do I print a receipt and verify a paper receipt
summary: Print a payment receipt in 80 mm or A4, and check that a paper receipt really comes from the club.
module: transactions
pages: [players.show, transactions.show, receipts.verify]
keywords: [receipt, print, ticket, 80mm, A4, duplicate, original, verification code, QR, verify receipt, fake receipt]
related: [subscriptions/take-payment, subscriptions/fix-payment]
---

Every receipt the app prints carries a 12-digit code. With this code you can check later that the receipt is genuine.

:::example
**أحمد بن علي** paid “اشتراك سنوي”: 3000 DZD. We print him a receipt, then we verify it.
:::

:::step Click the printer button in the payment's row
On a phone, first open the menu with the ☰ button. Open أحمد's page, and scroll down to “Transaction History”. To scroll down, turn the mouse wheel toward you, or slide two fingers on the touchpad. On a phone, slide the screen up with your finger. On a phone, slide the table to the left with your finger to see the buttons. In the payment's row, 3 000,00, click the printer button. The ticket (80mm) opens in a new window. Print it from there.
::figure receipt-ticket-button ring=player-history-ticket zoom=player-history-ticket
:::

:::step Open the payment's page
Want an A4 receipt? Open the payment's page. On a phone, first open the menu with the ☰ button. In the menu, click “Transactions” under “Finance”. You don't see it? Click “Finance” to show it. Type “أحمد” in the search box. Click the title of his payment.
::figure receipt-tx-index ring=nav-transactions,tx-search,tx-row-first
:::

:::step Click the “Print A4 receipt” button
At the top of the page are the two print buttons: the one with a printer for the ticket, and the one with a sheet of paper for the A4 receipt. When you hover over a button, its name appears. Click “Print A4 receipt”. The receipt opens in a new window.
::figure receipt-tx-show ring=tx-print-a4 zoom=tx-print-a4
:::

:::step Open “Verify a receipt”
On a phone, first open the menu with the ☰ button. In the menu, click “Verify a receipt” under “Finance”. You don't see it? Click “Finance” to show it.
::figure receipt-verify ring=nav-receipts-verify
:::

:::step Type the receipt's code
At the bottom of the receipt is a QR code. Under the words “Verification code” are 12 digits. Type them in the “Verification code” box. Spaces between the digits do no harm. Have a QR reader? Scan the code with it, and it types the digits by itself.
::figure receipt-verify-code ring=receipt-code
:::

:::step Click “Check”
The button is next to the box. The result appears under it.
::figure receipt-verify-code ring=receipt-check zoom=receipt-check
:::

:::step Read the result
أحمد's ticket is “Valid”: it was printed by the app and matches the records. Under “This paper”: “ORIGINAL · 80mm ticket”. Under “Amount on the paper”: 3 000,00 DZD. And under “Printed receipts”, every copy printed for this payment.
::figure receipt-verify-result ring=receipt-result
:::

At the top of each receipt, the word “ORIGINAL” marks the first print. Every print after it says “DUPLICATE No. 2”, then 3, and so on. أحمد's ticket is the original, so the A4 receipt says “DUPLICATE No. 2”. If you reopen the same receipt within a minute, no new copy is counted. On the payment's page, under “Printed receipts”, you find every copy and its code.

The other results:

- “Modified after printing”: the payment changed after printing. The “Changed since printing” table shows what changed.
- If the payment was corrected after printing, “Cancelled” or “Modified after printing” may show. Print a new receipt.
- “Deleted”: the payment is no longer in the app. The receipt's details, as printed, show under it.
- “Unknown code”: no receipt was printed with this code. Read the digits again. If they are right, the receipt is not from the club.

:::tip
Your keyboard types digits like this: ١٢٣? No problem, the app reads them just like 123.
:::
