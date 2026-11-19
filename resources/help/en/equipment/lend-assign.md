---
title: How do I lend equipment or assign it to a player
summary: Hand units from stock to a player or to someone outside the club, and set when they come back.
pages: [equipment.catalogs.show]
keywords: [lend, loan, rental, assignment, assign, rent, hand out equipment, player, external person, loan period, return date, overdue]
related: [equipment/return-equipment, equipment/damage-repair-lost, equipment/receive-stock]
---

Equipment leaves stock with the “Rent” button on its catalog's page. Despite its name, the button takes no money. And the app records no transaction.

:::example
أمين قاسمي borrows two “كرة قدم مقاس 5” (size 5 football) balls for 7 days.
:::

:::step Open the “Equipments” page
On a phone, first open the menu with the ☰ button. In the menu, click “Equipments” under “Equipment”. Can't see it? Click “Equipment” to show it.
::figure eq-lend-assign-list ring=nav-equipment
:::

:::step Click “كرة قدم مقاس 5”
In the table, click the catalog's name. Its page opens, with the “Items” table of the units in stock.
::figure eq-lend-assign-list ring=eq-catalog-link-1 zoom=eq-catalog-link-1
:::

:::step Click the “Rent” button on the balls' row
On the balls' row, click the paper-plane button. When you hold the mouse over it, its name “Rent” appears. The “Rent — كرة قدم مقاس 5” window opens. Can't see the button? You need the “Edit” permission in “Equipment”. The button is also missing on a row with no available unit left.
::figure eq-lend-assign-catalog ring=eq-rent-3 zoom=eq-rent-3
:::

:::step Choose “Rental” or “Assignment”
“Rental” is for equipment that comes back after a while. Choose it for the two balls. “Assignment” is for equipment the player works with, with no return date. When you choose it, the message “Given to a person to work with. No due date.” appears. An assignment is for players only, and never becomes “Overdue”.
::figure eq-lend-assign-type ring=eq-rent-rental,eq-rent-assignment
:::

:::step Choose the player
Check that “Player” is selected. Click “Select Player”, and type: أمين قاسمي. Then click his name in the list. Do the balls belong to a branch that is not the player's? The message “This equipment belongs to another branch.” appears. It is only a warning, and does not stop the loan.
::figure eq-lend-assign-player ring=eq-rent-player-btn,eq-rent-player
:::

:::step Type the “Quantity” and the “Expected rent period”
In “Quantity”, type: 2. Below it is the number of available units: “18 available”. The app does not accept more than is available. “Quantity” only shows on a row with several units. The “Rental Date” is today: change it if you handed over the balls before today. In “Expected rent period”, type the number of days: 7. The “Return date” shows below it. Left it empty? The loan never becomes “Overdue”.
::figure eq-lend-assign-fields ring=eq-rent-quantity,eq-rent-date,eq-rent-days
:::

:::step Click “Rent”
The message “Equipment issued successfully.” appears. أمين قاسمي shows in the “Held by” column, with the number of balls next to him. The loan also shows on the “Equipment out” page. If the balls are not back before the “Return date”, it shows “Overdue” there.
::figure eq-lend-assign-fields ring=eq-rent-save zoom=eq-rent-save
:::

:::tip
Lending to someone outside the club? Click “External person” instead of “Player”. Then type the “Full name” and the “Phone”. The app does not save without the name. Switching between “Player” and “External person” clears what you chose or typed. “External person” only shows with “Rental”, not with “Assignment”.
:::

::figure eq-lend-assign-external ring=eq-rent-external-btn,eq-rent-name,eq-rent-phone
