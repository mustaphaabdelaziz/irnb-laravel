---
title: How do I see who recorded a transaction and who edited it
summary: Open the transaction's page to see who recorded it and when, then every edit, cancellation or print after that.
module: transactions
pages: [transactions.show, transactions.index]
keywords: [who recorded, transaction history, tracking, edit a transaction, date and time, who edited, who cancelled, payment correction, review]
related: [finance/record-transaction, finance/cancel-transaction, subscriptions/receipts]
---

Every transaction keeps its story: who recorded it, when, and what changed afterwards.

:::example
**أحمد بن علي** paid “اشتراك سنوي”: 3000 DZD. We open his payment's page to see who recorded it.
:::

:::step Open the “Transactions” page
On a phone, first open the menu with the ☰ button. In the menu, click “Transactions” under “Finance”. You don't see it? Click “Finance” to show it. Type “أحمد” in the search box.
::figure receipt-tx-index ring=nav-transactions,tx-search,tx-row-first
:::

:::step Click the transaction's title
Click the title of أحمد's payment. The transaction's page opens.
::figure fin-tx-history ring=tx-trace
:::

:::step Read the “Recorded by:” line
The line is at the bottom of the first card. It gives the user's name, then the date and time. If the transaction was edited, “Last modified:” shows next to it.
::figure fin-tx-history ring=tx-trace zoom=tx-trace
:::

:::step Read “Transaction history”
Scroll down to the “Transaction history” card. Each line is one event: recording, edit, cancellation or receipt print. Each line carries the name, the date and the time. An edit shows the old value struck through, then the new one.
::figure fin-tx-history ring=tx-prints
:::

Was the transaction edited before this history existed? The “Edited” line shows without detail.

Was a player's payment corrected? The old payment carries a link to the new one, and the new one to the old.

:::tip
You see “as” after a name? An administrator was logged in with that user's account. The first name is the one who did the action.
:::
