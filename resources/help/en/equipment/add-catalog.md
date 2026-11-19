---
title: How do I add a new type of equipment
summary: Create a catalog card with its name, category and price, then receive its units into stock.
pages: [equipment.catalogs.index, equipment.catalogs.create]
keywords: [add equipment, new equipment, catalog, gear, new catalog, track each unit, serial number, similar name, equipment price]
related: [equipment/equipment-lists, equipment/receive-stock]
---

An equipment catalog is one card for one type of equipment, such as “قمع تدريب” (training cone). The card holds the name, category and price. The units themselves go into stock afterwards.

:::example
The club buys jump ropes for training. It creates the catalog “حبل قفز” (jump rope) in the “معدات التدريب” (training equipment) category, at 400 per rope.
:::

:::step Open the “Equipments” page
On a phone, first open the menu with the ☰ button. In the menu, click “Equipments” under “Equipment”. Can't see it? Click “Equipment” to show it. The page shows every catalog and how many units it has.
::figure eq-add-catalog-list ring=nav-equipment
:::

:::step Click the “Add Equipment” button
At the top of the page, click the button with the + sign. When you hold the mouse over it, its name “Add Equipment” appears. The “Add Equipment” page opens. Can't see the button? You need the “Add” permission in “Equipment”.
::figure eq-add-catalog-list ring=eq-add-catalog zoom=eq-add-catalog
:::

:::step Type the “Name”, choose the “Category” and type the “Price”
In “Name”, type: حبل قفز. In “Category”, choose “معدات التدريب”. The list shows the categories from the “Equipment Categories” page. “Brand” is optional: leave it empty. In “Price”, type the price of one unit: 400. The app suggests it when you receive stock.
::figure eq-add-catalog-form ring=eq-name,eq-category,eq-brand,eq-price
:::

:::step Leave “Track each unit individually” unticked
Ropes are counted: each rope needs no number. Tick it only for expensive equipment, such as “ساعة توقيت” (stopwatch). Then each unit gets a serial number and its own history. Choose carefully: after the first receipt, this option is locked on the catalog's edit page. The message “Tracking mode cannot be changed once the catalog holds stock.” shows there.
::figure eq-add-catalog-form ring=eq-track zoom=eq-track
:::

:::step Click “Save”
The “حبل قفز” page opens with the message “Equipment catalog created successfully.”. The catalog has no units yet. To put the ropes into stock, read the article “How do I receive equipment stock”.
::figure eq-add-catalog-form ring=eq-save zoom=eq-save
:::

:::tip
The app shows a warning under “Name” if a catalog has the same name spelled another way, or a name that contains it. For example, “كره قدم مقاس 5” with ه instead of ة matches “كرة قدم مقاس 5” (size 5 football). “كرة قدم” on its own reminds you of it too. The message “Equipment with a similar name already exists: كرة قدم مقاس 5” appears. The warning does not stop you saving. If it is the same catalog, do not create a second one. Receive the units in the existing catalog.
:::

::figure eq-add-catalog-similar ring=eq-name,eq-similar
