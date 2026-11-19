---
title: How do I delete one or several transactions
summary: Delete a transaction entered twice or by mistake, from its row or by ticking several rows at once.
pages: [transactions.index, transactions.show]
keywords: [delete transaction, cancel transaction, duplicate transaction, duplicate, delete selected, delete several transactions, entry mistake, archive, trash]
related: [finance/record-transaction, finance/find-export-import, subscriptions/fix-payment]
---

Entered a transaction twice or by mistake? Delete it from the “Transactions” list. You don't need to recalculate anything.

:::example
The electricity bill was entered twice, with the same amount. We delete the second row, the duplicate, and keep the first.
:::

:::step Open the “Transactions” page
On a phone, first open the menu with the ☰ button. In the menu, click “Transactions” under “Finance”. Can't see it? Click “Finance” to show it.
::figure fin-tx-list ring=nav-transactions
:::

:::step Type “كهرباء” in the search box
Type the word “كهرباء” (electricity) in the “Search” box. Two “فاتورة الكهرباء” (electricity bill) rows remain, with the same amount and caisse. Only the date differs. The top row is the newer one: it is the duplicate.
::figure fin-tx-search ring=tx-search,tx-row-first
:::

:::step Click the trash on the duplicate row
At the end of the row, click the red button with a trash can. Hover over it to see its name: “Delete”. On a phone, the button is at the bottom of the transaction's card. The “Delete this transaction?” window opens. Can't see the button? You need the “Delete” permission on “Transactions”.
::figure fin-tx-duplicate ring=tx-delete-first zoom=tx-delete-first
:::

:::step Read the message, then click “Delete”
The window says: “It stops counting in the totals and the caisse balance, and stays in the activity history.”. Click the red “Delete” button. The message “Transaction archived successfully.” appears. The transaction disappears, and the full list comes back, with no search. The figures at the top change.
::figure fin-tx-delete-confirm ring=confirm-ok zoom=confirm-ok
:::

:::step Tick several transactions
Want to delete several transactions at once? Click the box at the start of each row to tick it. A coloured bar appears above the list. It shows how many are ticked, such as “1 selected”. The box in the column header ticks every row on the page. The × button in the bar clears the selection.
::figure fin-tx-bulk ring=tx-select-first,tx-bulk-bar
:::

:::step Click “Delete selected”
In the bar, click the button with a trash can. Hover over it to see its name: “Delete selected”. The “Delete the selected entries?” window opens.
::figure fin-tx-bulk ring=tx-bulk-delete zoom=tx-bulk-delete
:::

:::step Click “Delete” to confirm
Click the red “Delete” button in the window. A message such as “1 transaction(s) deleted.” appears.
::figure fin-tx-bulk-confirm ring=confirm-ok zoom=confirm-ok
:::

The limit is 500 transactions at a time. Transactions of a closed season are not deleted.

If some are in the selection, the app skips them. It writes, for example: “3 transaction(s) deleted, 2 skipped (closed fiscal year).”

Just one transaction from a closed season? The app refuses to delete it. It writes: “Season … is closed. Reopen it to change its transactions.”.

:::tip
Is the transaction a subscription payment? Deleting it here does recalculate the player's debt. But it is easier to fix it from the player's page, as in “How do I fix a payment or give a discount”.
:::

:::warning
Deleting can't be undone in the app. The transaction leaves the totals and the caisse balance, and no button brings it back. Its only trace stays in the “activity history”. If you're not sure, fix it by editing instead of deleting.
:::
