---
title: How do I record income or an expense
summary: Record an amount that came into or went out of the club: title, category, amount and caisse, then save.
pages: [transactions.index, transactions.create, transactions.show]
keywords: [transaction, expense, income, revenue, expenses, add transaction, record expense, caisse, cash register, category, purchase]
related: [finance/cancel-transaction, subscriptions/take-payment, subscriptions/receipts]
---

Record in “Transactions” every amount that came into or went out of the club: what, how much, and from which caisse.

:::example
The coach buys goal nets. Title “شراء شباك المرمى” (goal nets purchase), category “Equipment”, amount 15000 DZD. He pays cash from the football safe.
:::

:::step Open the “Transactions” page
On a phone, first open the menu with the ☰ button. In the menu, click “Transactions” under “Finance”. Can't see it? Click “Finance” to show it.
::figure fin-tx-list ring=nav-transactions
:::

:::step Click the “Add Transaction” button
At the top of the page, click the button with the + sign. Hover over it to see its name: “Add Transaction”. The “Add Transaction” page opens. Can't see the button? You need the “Add” permission on “Transactions”.
::figure fin-tx-list ring=tx-add zoom=tx-add
:::

:::step Choose “Expense” in “Type”
“Type” starts on “Income”. Choose “Expense” before any other field. “Category” then shows only expense categories.
::figure fin-tx-form ring=txf-type
:::

:::step Type the title, choose the category and type the amount
In “Title”, type: شراء شباك المرمى. In “Category”, choose “Equipment”. In “Amount”, type 15000. The title is required, 150 characters at most.
::figure fin-tx-form ring=txf-title,txf-category,txf-amount
:::

:::step Choose the caisse and the payment method
In “Cash register paying the expense”, choose “Football safe”. Leave “Payment Method” on “Cash”. “Date” stays on today, and “Status” stays “Paid”.
::figure fin-tx-form ring=txf-caisse,txf-method
:::

:::step Click “Save”
The button is at the bottom of the page. To scroll down to it, roll the mouse wheel towards you, or slide two fingers on the touchpad. On a phone, swipe the screen up with your finger. Click “Save”. The transaction's page opens, and the message “Transaction created successfully.” appears. The amount −15 000,00 is in red, because it is an expense.
::figure fin-tx-form-bottom ring=txf-save zoom=txf-save
::figure fin-tx-saved ring=tx-show-amount
:::

At the top of the transaction's page are two print buttons: the one with a printer for the ticket, and the one with a sheet for the A4 receipt. Hover over a button to see its name. In “Transactions”, the transaction shows on a new row at the top of the list.

Is the date in a closed season? The app refuses to save and writes: “Season … is closed. Reopen it to change its transactions.”. Change the date, or ask the person in charge to reopen the season.

:::tip
Did the money come from a player? Choose them in the “Player” field, below “Status”. The caisse may then switch to the player's caisse, if they have their own. But don't record their subscription here: take the payment from the player's page. Their subscription is then settled and their receipt printed.
:::
