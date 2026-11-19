---
title: How do I receive equipment stock
summary: Put bought or donated units into stock, and record their price as an expense when needed.
pages: [equipment.catalogs.show]
keywords: [receive stock, add stock, buy equipment, equipment expense, donation, opening balance, adjustment, where it came from, location, serial number, designation, quantity]
related: [equipment/add-catalog, equipment/equipment-lists, finance/cancel-transaction]
---

Equipment goes into stock from its catalog's page, with the “Receive stock” button. If the club bought it, the app records its price as an expense at the same time.

:::example
The club bought 10 training cones, at 150 per cone. It paid from “Club cash”, and put the cones in “المخزن الرئيسي” (main store).
:::

:::step Open the “Equipments” page
On a phone, first open the menu with the ☰ button. In the menu, click “Equipments” under “Equipment”. Can't see it? Click “Equipment” to show it.
::figure eq-receive-stock-list ring=nav-equipment
:::

:::step Click “قمع تدريب”
In the table, click the name of the catalog “قمع تدريب” (training cone). Its page opens, with the “Items” table of the units in stock.
::figure eq-receive-stock-list ring=eq-catalog-link-2 zoom=eq-catalog-link-2
:::

:::step Click the “Receive stock” button
At the top of the page, click the button with the + sign. When you hold the mouse over it, its name “Receive stock” appears. The “Receive stock — قمع تدريب” window opens. Can't see the button? You need the “Edit” permission in “Equipment”.
::figure eq-receive-stock-button ring=eq-receive zoom=eq-receive
:::

:::step Fill in the quantity, price, date and location
In “Quantity”, type: 10. The “Unit price” comes from the catalog's price: 150. Change it if you paid another price. Set “Date” to the day of purchase. Leave “Condition” on “New”. In “Location”, choose “المخزن الرئيسي”. Under “Branches”, click a branch only if the units are for that branch alone. The cones are for every branch, so do not click any branch.
::figure eq-receive-stock-fields ring=eq-rcv-quantity,eq-rcv-price,eq-rcv-date,eq-rcv-condition,eq-rcv-location,eq-rcv-branches
:::

:::step Tick “Record as an expense” and choose the register
Tick it, because the club paid for the cones. “Cash register paying the expense” appears: choose “Club cash”. The expense is unit price × quantity: 1500. It is recorded as paid on the purchase date, and taken from the register. “Unit price” is empty or 0? The error “Enter the unit price to record the expense.” appears.
::figure eq-receive-stock-expense ring=eq-rcv-expense,eq-rcv-caisse
:::

:::step The club did not pay? Choose “Where it came from”
This step replaces the previous one, for equipment that was not bought. Leave “Record as an expense” unticked. In “Where it came from”, choose “Donation”, “Opening balance” or “Adjustment”. “Opening balance” is for equipment the club had before the app. And “Adjustment” is for correcting the count. The app does not save until you choose a source.
::figure eq-receive-stock-source ring=eq-rcv-expense,eq-rcv-source
:::

:::step Click “Save”
The message “Stock received successfully.” appears. A new row with 10 units appears in the “Items” table. And the number under “Available / Total” goes up.
::figure eq-receive-stock-expense ring=eq-rcv-save zoom=eq-rcv-save
:::

:::tip
Some catalogs have “Track each unit individually”, such as “ساعة توقيت” (stopwatch). There, each unit gets its own row and serial number. “ID / Serial” shows the number of the first unit. In “Designation”, type: ساعة توقيت, and in “Quantity”: 2. The app carries on after the highest existing number: “ساعة توقيت 4” and “ساعة توقيت 5”. You can receive up to 200 units at a time.
:::

::figure eq-receive-stock-serial ring=eq-rcv-serial,eq-rcv-designation,eq-rcv-quantity

:::warning
The expense recorded here is a real expense in “Transactions”, taken from the register. If you delete the units afterwards, the expense stays. To cancel it, delete the transaction itself in “Transactions”.
:::
