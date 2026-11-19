---
title: How do I create a role and set its rights
summary: Create a new role with specific rights, and give a user one extra right on top of their role.
module: superadmin
pages: [roles.index, users.edit]
keywords: [role, roles, rights, permissions, advanced overrides, coach, accountant, storekeeper, only the teams they coach]
related: [settings/create-user, settings/manage-users, settings/log-in-as-activity]
---

A role is a set of rights. Each user has one role. You choose it in the “Role” box on the user's edit page. The “Roles” page is for the super admin only; nobody else sees it.

:::example
You want a new role named “أمين المخزن” (storekeeper). It sees equipment and the stocktake, and can add and edit in them. Then coach سمير بوعزة needs to see “Subscriptions”, so you give him that right alone.
:::

:::step Open the “Roles” page
On a phone, first open the menu with the ☰ button. In the menu, click “Roles” under “System”. You don't see it? Click “System” to show it. The page shows one card per role, with its number of users under it. The basic roles carry the “System” badge.
::figure set-roles ring=nav-roles
:::

:::step Click the “Add” button
At the top of the page, click the button with the plus sign. When you hover over it, its name “Add” appears. The “Role” window opens.
::figure set-roles ring=roles-add zoom=roles-add
:::

:::step Type the name and choose the rights
Type “أمين المخزن” in the “العربية” (Arabic) box. A name in one language is enough; the other two boxes are for English and French. Scroll inside the window down to the “Equipment” and “Inventory” rows. Use the mouse wheel, or two fingers on the touchpad. On a phone, slide the screen up with your finger. Tick “View”, “Add” and “Edit” in both rows. Leave “Delete” empty.
::figure set-role-name ring=role-name-ar
::figure set-role-rows ring=perm-row-equipment,perm-row-inventory
:::

:::step Click “Save Changes”
Under the table is the box “Only the teams they coach”, for coaches: it limits their edits to their own teams. Leave it empty here. The message “Role created successfully.” appears, and the “أمين المخزن” card is added to the page.
::figure set-role-save ring=role-own-teams,role-save zoom=role-save
:::

:::step Open سمير's edit page
On the “Users” page, click the pencil button in سمير's row. When you hover over it, its name “Edit” appears. The user's edit page opens.
::figure set-user-edit-button ring=users-edit-samir zoom=users-edit-samir
:::

:::step Click “Advanced overrides”
You find it under the “Role” box. The same rights table appears, and the link is now named “Hide advanced”. This link is for the super admin only. It only adds rights; it never takes a right away from the role.
::figure set-user-advanced ring=ue-overrides-toggle
:::

:::step Give him “View” in “Subscriptions” and save
Tick “View” in the “Subscriptions” row. The ticked boxes are given on top of the role. Then click “Save Changes” at the bottom of the page, and the message “User updated successfully.” appears.
::figure set-user-overrides ring=perm-row-subscriptions
::figure set-user-overrides-save ring=ue-save zoom=ue-save
:::

:::tip
A new role starts with no rights at all. So do the parts that later versions of the app add: they stay empty in your roles until you set them.
:::

:::warning
Deleting a role cannot be undone. The trash button on the role card asks “Are you sure you want to delete?”. Users of the deleted role are left with no role. Roles with the “System” badge cannot be deleted.
:::
