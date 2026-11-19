---
title: How do I import equipment from an Excel file and export it
summary: Bring in a list of catalogs or units from an Excel or CSV file, and export equipment lists to a file.
pages: [equipment.catalogs.index, equipment.catalogs.show]
keywords: [import, export, Excel, CSV, xlsx, template, download template, file, equipment list, import units, record as expense, purchase price]
related: [equipment/add-catalog, equipment/receive-stock, finance/cancel-transaction]
---

The club has an equipment list in an Excel file? Do not type it in one catalog at a time. Import it all at once from the “Equipments” page. And export the list to a file whenever you like.

:::example
The club's file has three catalogs: “حبل قفز” (jump rope), “سلم رشاقة” (agility ladder) and “كرة قدم مقاس 5” (size 5 football). All three are in an existing category, and the third already exists in the app. After the import, the club exports the full list to Excel.
:::

:::step Open the “Equipments” page
On a phone, first open the menu with the ☰ button. In the menu, click “Equipments” under “Equipment”. Can't see it? Click “Equipment” to show it.
::figure eq-import-export-list ring=nav-equipment
:::

:::step Click the “Import” button
At the top of the page, click the up-arrow button. When you hold the mouse over it, its name “Import” appears. The “Import — Equipments” window opens. Can't see the button? You need the “Edit” permission in “Equipment”.
::figure eq-import-export-list ring=eq-import zoom=eq-import
:::

:::step Download the template and fill it in
In the window, click the down-arrow button. When you hold the mouse over it, its name “Download template” appears. Choose “Excel (.xlsx)”, and the file downloads. Its columns: “Name”, “Category”, “Brand”, “Purchase price” and “Description”. Leave the headings as they are, and delete the example row. Then type one row per catalog. Type the “Category” exactly as on the “Equipment Categories” page, letter for letter. And type the price in digits only, like 400.
::figure eq-import-export-template ring=eq-import-template,eq-import-template-xlsx zoom=eq-import-template-xlsx
:::

:::step Choose the filled-in file
In the window, click the file-choice button. Its name is in your browser's language. The computer's file window opens: choose your file. The file name appears next to the button.
::figure eq-import-export-file ring=eq-import-file
:::

:::step Click “Import”
The button does not work until you choose a file. The message “Equipment imported (count: 2).” appears. Only “حبل قفز” and “سلم رشاقة” went in. Below it, a second message has one line for each problem in your file. Here: “Row 4: "كرة قدم مقاس 5" already exists — skipped.”. The number is the row number in your file. A row whose category does not exist is not imported: the message says “unknown category”. But a similar name, like “كره قدم مقاس 5” with ه instead of ة, is imported as a new catalog. The message points it out with the words “imported anyway”. Both messages stay about 8 seconds, and the second shows only the first 10 problems.
::figure eq-import-export-file ring=eq-import-submit zoom=eq-import-submit
:::

:::step Click the “Export” button
On the “Equipments” page, click the down-arrow button. When you hold the mouse over it, its name “Export” appears. Choose “Excel (.xlsx)” or “CSV”, and the file downloads. It holds every catalog, whatever the search or chosen category. The “View” permission in “Equipment” is enough to export.
::figure eq-import-export-export ring=eq-export,eq-export-xlsx,eq-export-csv
:::

Each catalog also has its own import and export for its units. At the top of the catalog's page, such as “ساعة توقيت” (stopwatch), is the up-arrow button. When you hold the mouse over it, its name “Import” appears. The “Import — ساعة توقيت” window works like the catalogs one. But its template has other columns: “Designation”, “Purchase date (YYYY-MM-DD)”, “Condition”, “Location”, “Purchase price” and “Notes”. Each row becomes one unit with a new serial number. The example row is not imported if it stays in the file. Type the “Location” as it is in “Storage Locations”: a location not in the list is kept as typed, and the message points it out. And the down-arrow button next to it, “Export”, downloads the catalog's units.

::figure eq-import-export-units-button ring=eq-units-import,eq-units-export

:::warning
The units import window has a “Record as an expense” box, unticked at first. Tick it only if the club paid for the units. Then you choose the “Cash register paying the expense”. Every row with a “Purchase price” becomes an expense paid from it. One expense per row, on the purchase date written in it. Importing the same file twice doubles the units and the expenses. To cancel an expense, delete its transaction in “Transactions”.
:::

::figure eq-import-export-units-expense ring=eq-units-import-expense,eq-units-import-caisse
