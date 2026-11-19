---
title: How do I add an equipment category or a storage location
summary: Set up the lists of equipment categories and storage locations before you add the equipment itself.
module: equipment_categories
pages: [equipment-categories.index, storage-locations.index]
keywords: [equipment category, equipment categories, category code, storage location, storage locations, store room, add category, delete category, edit category, serial number]
related: [equipment/add-catalog, equipment/receive-stock]
---

Every equipment catalog has a category, such as “كرات” (balls) or “ملابس” (clothing). And every unit in stock has a location, such as “المخزن الرئيسي” (main store). You add the categories and locations once, then choose them from the lists.

:::example
The club buys bags for the teams. It adds the category “حقائب” (bags) with the code BAG. And it stores them in a new location named “مخزن القاعة” (hall store).
:::

:::step Open the “Equipment Categories” page
On a phone, first open the menu with the ☰ button. In the menu, click “Equipment Categories” under “App Configuration”. Can't see it? Click “App Configuration” to show it. The page shows the categories, and the number of “Equipments” in each category.
::figure eq-equipment-lists-categories ring=nav-equipment-categories
:::

:::step Type the “Name”, “Code” and “Description”
In “Name”, type: حقائب. In “Code”, type: BAG. “Description” is optional; type for example: حقائب الفرق (team bags). The code is Latin letters or digits, up to 10. The app saves it in capital letters. Can't see the fields? You need the “Add” permission in “Equipment Categories”.
::figure eq-equipment-lists-category-typed ring=eqcat-name,eqcat-code,eqcat-description
:::

:::step Click “Add”
The message “Category created successfully.” appears. The category shows in the table. Left the “Code” empty? The app takes the first 4 Latin letters or digits of the name. A name in Arabic only is not enough: the app asks you to type the code yourself. The code goes into the serial number of every unit tracked on its own. Changing it later does not change the numbers of existing units.
::figure eq-equipment-lists-category-typed ring=eqcat-add zoom=eqcat-add
:::

:::step Open the “Storage Locations” page
On a phone, first open the menu with the ☰ button. In the menu, click “Storage Locations” under “App Configuration”. You find it under “Equipment Categories”. The page shows the existing locations, such as “المخزن الرئيسي”.
::figure eq-equipment-lists-places ring=nav-storage-locations
:::

:::step Type the location's name
In “Name”, type: مخزن القاعة. The app does not accept the name of a location that already exists. Can't see the field? You need the “Add” permission in “Storage Locations”.
::figure eq-equipment-lists-place-typed ring=loc-name
:::

:::step Click “Add”
The message “Storage location created successfully.” appears. The location shows in the list. You choose it in “Location” when you receive stock.
::figure eq-equipment-lists-place-typed ring=loc-add zoom=loc-add
:::

:::tip
To fix the name of a category or location, click the pencil button on its row. When you hold the mouse over it, its name “Edit” appears. The button needs the “Edit” permission in “Equipment Categories” or “Storage Locations”. The row's fields become editable. Fix the name, then click “Save” on the same row. The new name applies to all the equipment linked to it. But a category in use cannot be deleted: its bin button does not work. When you hold the mouse over it, “Category is used by equipment and cannot be deleted” shows.
:::
