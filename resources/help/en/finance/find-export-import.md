---
title: How do I search, export and import transactions
summary: Find a transaction and filter the list, then export it to Excel, or import an old ledger from a ready-made template.
pages: [transactions.index]
keywords: [search, transactions, export, import, Excel, CSV, template, filter, season, clear filters, old ledger, electricity]
related: [finance/record-transaction]
---

The “Transactions” list searches, filters and exports what you see. It also imports transactions from an Excel or CSV file.

:::example
We look for the electricity bills of the current season and export them to Excel. Then we import last year's ledger from the template.
:::

:::step Open the “Transactions” page
On a phone, first open the menu with the ☰ button. In the menu, click “Transactions” under “Finance”. Can't see it? Click “Finance” to show it.
::figure fin-tx-list ring=nav-transactions
:::

:::step Type “كهرباء” in the search box
Below the statistics, type the word “كهرباء” (electricity) in the “Search” box. The list changes on its own after a moment. The app searches the title, the description, the category name and the player name. Two rows remain, “فاتورة الكهرباء” (electricity bill), and the figures at the top change with them.
::figure fin-tx-search ring=tx-search,tx-row-first
:::

:::step Narrow the list by type, caisse and season
The list shows the current season only. For another season, open the season list and choose it, or choose “All seasons”. The “Type” and “All cash registers” lists narrow it further.
::figure fin-tx-list ring=tx-filter-type,tx-filter-caisse,tx-filter-season
:::

:::step Click the “More actions” button
At the top of the page, click the button with three dots. Hover over it to see its name: “More actions”. A menu opens.
::figure fin-tx-list ring=tx-more zoom=tx-more
:::

:::step Choose “Excel (.xlsx)” under “Export”
A file downloads with everything the list shows now, not just the first page. It follows the search, the season and the dates. “CSV” gives a text file.
::figure fin-tx-menu ring=tx-export
:::

:::step Download the “Template” and fill it in
To import, choose “Excel (.xlsx)” under “Template”. Fill in the file, one row per transaction. Leave the headings as they are: Date, Title, Type, Category, Amount, Status, Payment method, Cash register, Description. Write the date like this: 2026-01-15 or 15/01/2026.
::figure fin-tx-menu ring=tx-template
:::

:::step Click “Import” and choose the file
Click “Import” in the same menu. A file picker opens: choose your file. The message “… transactions imported successfully.” appears, with the number of imported transactions in place of the dots. Importing needs the “Add” permission on “Transactions”.
::figure fin-tx-menu ring=tx-import
:::

A row names a caisse that doesn't exist? It is recorded in the default caisse. A row dated in a closed season is not imported. Below the success message, a second message appears. It shows only the first 10 problems, one problem per line. For example: “Row 3: cash register "…" not found, the default register was used.”. A row with an empty or zero amount is skipped, and the message doesn't mention it.

When you choose a filter, a red button with a funnel appears. Hover over it to see its name: “Clear filters”. It clears the search and the filters, and takes the list back to the current season.

:::warning
Import doesn't check for duplicates. Importing the same file twice records every row twice. Import each file only once.
:::
