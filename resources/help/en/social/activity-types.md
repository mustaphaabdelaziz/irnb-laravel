---
title: How do I add an activity type and set its order
summary: Add activity types and change their order: the literary report prints each type as a chapter, in this order.
module: activity_types
pages: [activity-types.index]
keywords: [activity type, activity types, report chapters, chapter order, add type, delete type, edit type, literary report]
related: [social/record-activity, social/create-literary-report]
---

Every activity has a type, such as “الحملات التطوعية” (volunteer campaigns). Each type is a chapter of the literary report, printed by its number “#”. Types with no activity in the report's period are not printed.

:::example
We add the type “الأيام التحسيسية” (awareness days). Then we give it the number 6, so it prints right after the first five chapters. The first is “تنظيمات النادي” and the last is “إعلانات النادي”.
:::

:::step Open the “Activity types” page
On a phone, first open the menu with the ☰ button. In the menu, click “Activity types” under “App Configuration”. You don't see it? Click “App Configuration” to show it. The types show in a table, each with its number under “#”. There are 11 in the example.
::figure soc-activity-types-list ring=nav-activity-types
:::

:::step Type the “Name”, then click “Add”
In “Name”, type: الأيام التحسيسية. Then click “Add”. The message “Type added” shows. The new type takes the number after the last one, here 12. A name cannot repeat. You need the “Add” permission for “Activity types”.
::figure soc-activity-types-add ring=atype-name,atype-add
:::

:::step Click the type's pencil button
On the type's row, click the pencil button. Its name, “Edit”, shows when you hover the mouse over it. The picture shows the “حملة تطوعية” row as an example. You need the “Edit” permission for “Activity types”.
::figure soc-activity-types-rows ring=atype-edit-demo zoom=atype-edit-demo
:::

:::step Change “#” and the name, then click “Save”
The number and the name become fields on the row. In our example, type 6 in the number field. Then click “Save” on the row. The message “Type updated” shows. When two numbers are equal, the two types are sorted by name. “Cancel” closes the row without changes. The picture shows the “حملة تطوعية” row as an example, because the new type was not saved.
::figure soc-activity-types-edit ring=atype-order-edit,atype-name-edit,atype-save
:::

:::step Read the “Activities” column
The “Activities” column counts each type's activities. The bin button of a type in use is faded and cannot be clicked, as long as the number in “Activities” is above zero.
::figure soc-activity-types-list ring=atype-count-col,atype-delete-used
:::

:::step Delete a type with no activity
On a type with no activities, such as “احتفال / مناسبة”, click the bin button. Its name, “Delete”, shows when you hover the mouse over it. The app asks “Are you sure?”. Once you confirm, the message “Type deleted” shows. You need the “Delete” permission for “Activity types”.
::figure soc-activity-types-rows ring=atype-delete-free zoom=atype-delete-free
:::

:::warning
⚠️ Deleting a type is final and cannot be undone.
:::
