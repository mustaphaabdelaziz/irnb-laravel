---
title: How do I record damaged, under-repair or lost equipment
summary: Separate damaged units from good ones, follow repairs, and record what was lost and what was found.
pages: [equipment.catalogs.show, equipment.items.history]
keywords: [damaged, broken, reclassify, repair, send to repair, mark fixed, lost, missing, restore, unit history, equipment condition]
related: [equipment/return-equipment, equipment/lend-assign, equipment/receive-stock]
---

All of this is done in the “Items” table on the catalog's page. How do you open the catalog's page? See the article “How do I lend equipment or assign it to a player”.

:::example
Two of the 40 “قمع تدريب” (training cones) broke. “ساعة توقيت 2” (stopwatch 2) stopped working and went to the repairer. Today it came back working. “ساعة توقيت 3” was lost, then the coach found it.
:::

:::step Click the “Reclassify units” button
On the “قمع تدريب” page, click the triangle button on the cones' row. When you hold the mouse over it, its name “Reclassify units” appears. A window with the same name opens. Can't see the button? You need the “Edit” permission in “Equipment”. Every button in this article needs it, except “History”. The button is also missing on a row with only one unit, or with no available unit.
::figure eq-damage-split-button ring=eq-split-4 zoom=eq-split-4
:::

:::step Type the “Quantity” and choose the “Condition”
In “Quantity”, type the number of damaged units: 2. The app does not accept more than is available on the row. Lent units do not count, because they are not in your hands. “Condition” starts on “Damaged”: leave it. In “Notes”, type the reason, for example: انكسرا في التدريب (broke in training).
::figure eq-damage-split-form ring=eq-split-quantity,eq-split-condition,eq-split-notes
:::

:::step Click “Save”
The message “Units reclassified successfully.” appears. The two cones move to a new row with the condition “Damaged”. The first row keeps 38 cones in “New” condition. The new row does not count as available, and the app will not lend it. Send it to repair, or delete it if you threw it away.
::figure eq-damage-split-form ring=eq-split-save zoom=eq-split-save
:::

:::step Click “Send to Repair”, then confirm
On the “ساعة توقيت” page, click the wrench button on the unit's row. When you hold the mouse over it, its name “Send to Repair” appears. The button only shows while the row is “Available” and all its units are in stock. Units from the row are out? Record their return first. In the picture, “ساعة توقيت 2” has no such button, because it is already “Under Repair”. After you click, the “Confirm Action” window asks: “Send this equipment to repair?”. Click “Confirm”. The message “Equipment sent to repair.” appears. The unit becomes “Under Repair” and cannot be lent. At the top of the page, “Under Repair” shows with the number of units next to it. These units do not count in “Rented”.
::figure eq-damage-units ring=eq-repair-7 zoom=eq-repair-7
::figure eq-damage-repair-confirm ring=confirm-ok zoom=confirm-ok
:::

:::step Click “Mark fixed”, then confirm
“ساعة توقيت 2” came back from the repairer. On its row, click the tick button. When you hold the mouse over it, its name “Mark fixed” appears. The window asks: “Mark this item as repaired and available again?”. Under the question, the “Condition” field starts on “Good”. Change it if the unit came back in another condition, then click “Confirm”. The message “Equipment repair completed.” appears. The unit becomes “Available”, with the condition you chose.
::figure eq-damage-units ring=eq-fixed-8 zoom=eq-fixed-8
:::

:::step A unit is lost? Click “Lost”, then confirm
You cannot find “ساعة توقيت 3”. On its row, click the button with a × in a circle. When you hold the mouse over it, its name “Lost” appears. The window asks: “Mark this equipment as lost?”. Click “Confirm”. The message “Equipment marked as lost.” appears. The unit becomes “Lost”. If it was lent, its loan is closed.
::figure eq-damage-units ring=eq-lost-9 zoom=eq-lost-9
:::

:::step Found it? Click “Restore”, then confirm
The coach found “ساعة توقيت 3”. On its row, click the circular arrow button. When you hold the mouse over it, its name “Restore” appears. The window asks: “Restore this item to Available?”. Click “Confirm”. The message “Equipment restored to available.” appears. The unit is “Available” again.
::figure eq-damage-restore ring=eq-restore-9 zoom=eq-restore-9
:::

:::warning
“Send to Repair” and “Lost” change the whole row, not part of it. On a row of 20 balls, all twenty balls become “Lost”. And every loan on the row is closed, like the two balls lent to أحمد بن علي. Only 3 balls are lost? First reclassify 3 with the “Reclassify units” button. Then click “Lost” on the new row. Lent units cannot be reclassified: record their return first. And “Restore” does not reopen the loans that “Lost” closed.
:::

:::tip
To see everything that happened to a unit, click the calendar button on its row. When you hold the mouse over it, its name “History” appears. The unit's page opens: under “History” is every event with its date, newest first. For “ساعة توقيت 2”, for example: “Sent to repair”, and before it “Received”. Under “Sent to repair”, “Previous status: Available” is its status before the repair. Under a loan with a period, the “Due Date” shows. Nothing shows under an assignment or a loan with no period. And under each return, the “Return date” shows, even for a partial return.
:::

::figure eq-damage-units ring=eq-history-8 zoom=eq-history-8

::figure eq-damage-history
