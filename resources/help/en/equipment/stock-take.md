---
title: How do I do an equipment stock-take
summary: Count the equipment in the stores, record what you found and what is missing, then complete the stock-take and print its report.
module: inventory
pages: [inventory.index, inventory.show]
keywords: [stock-take, inventory, monthly inventory, yearly inventory, start inventory, count sheet, save counts, complete inventory, found, missing, participants, discrepancy]
related: [equipment/damage-repair-lost, equipment/inventory-report, equipment/return-equipment, equipment/receive-stock]
---

A stock-take compares what is in the app with what is really in the stores. The app puts on the count sheet everything that should be in the stores. And you type how many you found.

:::example
It is the start of the month. The club counts its equipment, helped by the player أمين قاسمي. It found everything except two of the forty cones, and “ساعة توقيت 3” (stopwatch 3). And “مرمى 1” (goal 1) is not on the count sheet, because it is lent to مراد بلقاسم.
:::

:::step Open the “Inventory” page
On a phone, first open the menu with the ☰ button. In the menu, click “Inventory” under “Equipment”. Can't see it? Click “Equipment” to show it. Still not there? You need the “View” permission in “Inventory”. The page shows every stock-take, newest first.
::figure eq-stock-take-list ring=nav-stocktake
:::

:::step Click the “Start Inventory” button
Under the cards, click the button with the + sign. When you hold the mouse over it, its name “Start Inventory” appears. A small card opens below it. Can't see the button? You need the “Add” permission in “Inventory”.
::figure eq-stock-take-list ring=stk-start zoom=stk-start
:::

:::step Choose the “Type” and type the “Date” and “Note”
“Type” starts on “Yearly”: choose “Monthly”. The other types are “Quarterly” and “Ad-hoc”. The “Date” is today: leave it. In “Note”, type: جرد شهري (monthly stock-take). The note is optional.
::figure eq-stock-take-form ring=stk-type,stk-date,stk-notes
:::

:::step Click “Start Inventory” in the card
Click the button at the bottom of the card. The new stock-take's page opens, with a message giving its reference, for example “Inventory INV-2026-03 started.”. The reference looks like this: INV-YEAR-NUMBER. The number follows the highest number used this year, such as INV-2026-03. A number can be missing after a stock-take was deleted. The page shows the count sheet, split by storage location. It has one row per numbered unit, and one per group of units. Lent equipment is not on the sheet, so you do not see “مرمى متنقل” (portable goal), whose number ends in 00001. For a group partly lent out, only the rest is expected. For example, on the “كرة قدم مقاس 5” (size 5 football) row you read “2 on loan”. The two balls are with أحمد بن علي, so the expected count is 18. Lost equipment is not on the sheet either. But equipment “Under Repair” is on it, like the “ساعة توقيت” whose number ends in 00002. A small label “Under Repair” shows next to its number.
::figure eq-stock-take-form ring=stk-submit zoom=stk-submit
:::

:::step Type how many you found
Every row starts full, as if you found everything. On the “ساعة توقيت” row whose number ends in 00003, click “Missing”. A row with no number, such as “قمع تدريب” (training cone), holds a group of units. It has a field between “Found” and “of 40”. Delete 40 and type 38. If you type more than the number after “of”, the app brings it back to that number. Found more? Add the extra with the “Receive stock” button on its catalog's page. In the “Note” field at the end of the row, type: ضاع قمعان في التدريب (two cones lost in training). The note is optional. Found equipment in another condition or another place? Change them in the two lists before “Note”. On a group row, the two lists change the whole group. Only some of them changed condition? After the stock-take, use “Reclassify units” on its catalog's page. Can't type? You need the “Edit” permission in “Inventory”. Made a mistake? The button with a tick in a circle puts every row back to full. When you hold the mouse over it, its name “Mark all found” appears.
::figure eq-stock-take-lines ring=stk-missing-9,stk-qty-4,stk-note-4,stk-condition-7,stk-location-7 zoom=stk-qty-4
::figure eq-stock-take-session ring=stk-mark-all zoom=stk-mark-all
:::

:::step Click “Save Counts”
Above the count sheet, click “Save Counts”. The message “Counts saved.” appears. The equipment does not change yet, so you can finish counting later. Do not leave the page before saving, or what you typed is lost.
::figure eq-stock-take-session ring=stk-save-counts zoom=stk-save-counts
:::

:::step Click “Complete Inventory”, then confirm
Finished counting? Click “Complete Inventory” next to “Save Counts”. A small browser window asks: “Complete the inventory?”. Click its OK button. The app saves the counts, then completes the stock-take. A message shows with its reference, for example “Inventory INV-2026-03 completed.”. Only the missing units become “Lost” on their catalog's page. “ساعة توقيت 3” becomes “Lost”. And on the “قمع تدريب” page, the two cones split off into a “Lost” row. The other 38 cones stay as they are. The condition and place you changed are saved. The cards count units: “Found” 113 and “Missing” 3. Under the numbers, the “Report · Discrepancy” card shows the rows that differ. On the cones' row you read 38 / 40 and your note.
::figure eq-stock-take-session ring=stk-complete zoom=stk-complete
:::

:::warning
“Complete Inventory” cannot be undone. Every missing unit becomes “Lost” on its catalog's page. Deleting the stock-take afterwards does not bring it back. Deleting is done with the bin button on the “Inventory” page, with the “Delete” permission. Found a unit after the stock-take? Click “Restore” on its catalog's page.
:::

::figure eq-stock-take-list ring=stk-delete-2 zoom=stk-delete-2

:::tip
The “Participants” card at the top of the page records who helped you count. Participants are players only, chosen from “Add participant”. In the picture, ياسين بوزيد was chosen, to be added next to أمين قاسمي. You add the name with the + button. When you hold the mouse over it, its name “Add” appears. No change is saved until you click “Save” in the card.
:::

::figure eq-stock-take-participant ring=stk-participant-pick,stk-participant-add,stk-participant-save

To print the stock-take report, click the printer button at the top of the page. When you hold the mouse over it, its name “Report” appears. A PDF file opens in a new page. It shows the expected, found and missing counts, and the differences row by row. On a group row, the count is written like this: 38 / 40, with the note. Conditions are written in the app's language, such as “Good” and “Damaged”. Print it after “Complete Inventory”: before that, found and missing stay at zero. The down-arrow button next to it is named “Export”. It downloads the count sheet as “Excel (.xlsx)” or “CSV”. Both buttons only need the “View” permission in “Inventory”.

::figure eq-stock-take-session ring=stk-report,stk-export
