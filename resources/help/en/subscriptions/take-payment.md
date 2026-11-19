---
title: How do I record a payment
summary: Record what a player paid, for one subscription or for several subscriptions together on one receipt.
module: players
pages: [players.show]
keywords: [payment, pay, add payment, settle, take money, receipt, cash register, subscription, debt]
related: [players/find-player, subscriptions/fix-payment, subscriptions/previous-debt, subscriptions/receipts]
---

Record the payment from the player's page: for one subscription, or for several subscriptions together on one receipt.

:::example
**زكرياء عمراني** owes “رحلة صيفية”: 1500 DZD. Today he pays it in cash, together with “اشتراك سنوي”: 3000 DZD. The total is 4500 DZD.
:::

:::step Open زكرياء's page
On a phone, first open the menu with the ☰ button. In the menu, click “Players”, then click زكرياء's name. To find him fast, type his name in the search box. At the top of his page, under “Outstanding Debt”: 1 500,00.
::figure payment-button ring=player-debt
:::

:::step Click the “Add Payment” button
Under the amount, click the button with a $ sign in a circle. When you hover over it, its name “Add Payment” appears. The “Add Payment” window opens.
::figure payment-button ring=player-add-payment zoom=player-add-payment
:::

:::step Tick the two subscriptions in order
Leave “Payment category” on “Subscription”. Under “Subscriptions to pay”, tick “اشتراك سنوي” first. #1 appears next to it. Then tick “رحلة صيفية”, and #2 appears. Under them, “Total” shows: 4 500,00. “اشتراك سنوي” is in the list even though it is not assigned to زكرياء yet: it is added to him when you save.
::figure payment-modal ring=payment-subscriptions
:::

:::step Check the “Amount” and “Payment Method”
The app writes the total in “Amount” by itself: 4500. If he paid less, type what he paid: number 1 is paid in full first, then number 2. In “Payment Method”, leave “Cash”, or choose “CCP” or “BaridMob”. Tick before you type the amount: each tick rewrites “Amount” and the cash register.
::figure payment-modal ring=payment-amount,payment-method
:::

:::step Check the cash register
In “Cash register receiving the income”, the app chose “صندوق أقل من 14 سنة — Football”. If you put the money in another register, choose it here.
::figure payment-modal ring=payment-register
:::

:::step Click “Save”
The button is at the end of the window. To scroll down to it, turn the mouse wheel toward you, or slide two fingers on the touchpad. On a phone, slide the window up with your finger. Click “Save”. The message “Payment recorded successfully.” appears, and “Outstanding Debt” becomes 0,00.
::figure payment-modal-bottom ring=payment-save zoom=payment-save
:::

The payment takes today's date. In “Transaction History”, two rows appear, one per subscription. The printer button in either of them prints one receipt with both subscriptions.

:::tip
The player paid more than the total? Type the whole amount. An orange line appears under it: the extra is recorded as a donation from the player.
:::

:::warning
Only tick “Exempt” if the club exempted the player. Then the “Amount” box disappears, no payment is recorded, and he owes nothing more on the ticked subscriptions.
:::
