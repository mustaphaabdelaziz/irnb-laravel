---
title: How do I move money between caisses
summary: Move an amount from one caisse to another, such as handing a category caisse's money over to the branch safe.
module: finance
pages: [finance.registers.index]
keywords: [move money, transfer, transfer between caisses, safe, caisse, hand over money, money moves, cash registers, from to]
related: [finance/caisses, finance/record-transaction]
---

Money passed from one hand to another? Record it in “Caisses”, and both caisses' balances change.

:::example
The “أقل من 14 سنة” (under 14) coach hands the 1000 DZD left in “صندوق أقل من 14 سنة” over to the football safe.
:::

:::step Open the “Caisses” page
On a phone, first open the menu with the ☰ button. In the menu, click “Caisses” under “Finance”. Can't see it? Click “Finance” to show it. The page shows one card per branch, with its safe and its caisses.
::figure fin-registers ring=nav-registers
:::

:::step Click the “Move money” button
At the top of the page, click the white button with an arrow. Hover over it to see its name: “Move money”. The “Move money” window opens. Can't see the button? You need the “Add” permission on “Finance”. It also doesn't show if you have only one caisse.
::figure fin-registers ring=reg-move zoom=reg-move
:::

:::step Choose the caisse in “From” and the caisse in “To”
In “From”, choose “صندوق أقل من 14 سنة — Football”. Next to each name you see how much money it holds. In “To”, choose “Football safe”. Can't find the caisse you want in “To”? Money can't go to the same caisse, or to “Opening Fund”.
::figure fin-move-modal ring=move-from,move-to
:::

:::step Type the “Amount” and “Notes”
In “Amount”, type 1000. In “Notes”, type: تسليم مداخيل الاشتراكات. “Date” stays on today. Notes are optional, but they help whoever reads “Money moves” after you.
::figure fin-move-modal ring=move-amount,move-notes
:::

:::step Check “After the move”
Below the fields, the “After the move” box appears. It shows each caisse's balance before and after the move. “صندوق أقل من 14 سنة” goes from 1 000,00 to 0,00. Check the figures before you save. Is the amount more than the balance? The message “Not enough money available here.” appears, and you can't save.
::figure fin-move-modal ring=move-after
:::

:::step Click “Move money” to save
The button is at the bottom of the window, with the same name. The message “Money transferred successfully.” appears. “صندوق أقل من 14 سنة” drops to 0,00. And “Cash kept in the safe” goes up by 1 000,00 in the football card. “Branch total” doesn't change, because the caisse is inside the safe.
::figure fin-move-modal ring=move-save zoom=move-save
:::

Scroll to the bottom of the page to find “Money moves”. This table shows the date, from and to, the amount, the notes, and who recorded the move. The newest move is at the top of the table. To scroll down, roll the mouse wheel towards you, or slide two fingers on the touchpad. On a phone, swipe the screen up with your finger.
::figure fin-registers-history ring=reg-history

:::tip
A move only changes where the money is. It doesn't add to the season's income or expenses, because the club earned or spent nothing. Don't record it in “Transactions”.
:::
