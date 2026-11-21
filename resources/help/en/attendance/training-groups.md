---
title: How do I split a category into training groups
summary: Split a category's players into groups. Each group has its own sessions, its own list of players and its own sheet.
pages: [attendance.settings, attendance.grid]
keywords: [groups, group, training groups, split a category, group players, change group, move a player, group sheet]
related: [attendance/timetable, attendance/month-grid, players/branch-registrations]
---

Does one category train at two different times? Split it into groups. A group's session lists only its players.

:::example
The “أصاغر” category in “Swimming” trains on Saturday in two groups. “الفوج 1” from 09:30 to 11:00, and “الفوج 2” from 11:00 to 12:30. Later, **سارة بن يوسف** moves from “الفوج 1” to “الفوج 2”.
:::

:::step Open “Attendance settings”
On the “Attendance” page, click the button with three lines, each with a small circle on it. Its name, “Attendance settings”, shows when you hover the mouse over it. You don't see it? You need the “Edit” permission for “Attendance”.
::figure att-calendar ring=att-settings zoom=att-settings
:::

:::step Add the two groups
In the “Groups” card, choose “Swimming” in “Branch”, and “أصاغر” in “Category”. Type “الفوج 1” in “Group name”. Click “Add”. The message “Group saved.” appears. Add “الفوج 2” the same way.
::figure set-group-typed ring=set-group-form,set-group-add
:::

:::step Tick the players of each group
On the “الفوج 1” row, click the button with a drawing of two people. Its name, “Group players”, shows when you hover the mouse over it. The list of the category's players appears. Tick سارة, إيناس and يوسف. The first time, the date field is empty: leave it empty. Click “Save”. The message “Group players saved.” appears. Do the same for “الفوج 2”.
::figure set-group-list ring=set-group-players zoom=set-group-players
::figure set-group-dialog ring=group-players-list,group-players-save
:::

:::step Choose the group on the schedule line
In the “Weekly schedule” card, choose “Swimming”, then “أصاغر”. The “Group” field appears: choose “الفوج 1”. In “Day”, choose “Saturday”. Type 09:30 in “Start”, and 11:00 in “End”. Click “Add”. Add the “الفوج 2” line, from 11:00 to 12:30.
::figure set-schedule-group ring=set-schedule-group,set-schedule-add
:::

:::step Open each group's sheet
On the “Attendance” page, choose “Swimming”, then “أصاغر”. At the top of the page, click the button with three lines. Its name, “Month grid”, shows when you hover the mouse over it. Above the grid, a bar shows the name of each group. Click “الفوج 1”: the grid shows only its sessions and its players. The printer button, “Print sheet”, prints this group's sheet, with its name at the top.
::figure att-grid-groups ring=grid-groups,grid-print
:::

:::step Move a player to another group
Open سارة's page, and click the “Edit Player” button. In the “Swimming” card, choose “الفوج 2” in “Group”. The “Changes apply from” field appears with today's date: choose the day of the move. Click “Save Changes”.
::figure player-edit-group ring=player-group,player-group-from
:::

Before the day of the move, سارة stays on the “الفوج 1” list in the sessions whose attendance is not saved yet. From that day on, she appears with “الفوج 2”. A session whose attendance is saved never changes.

Was the group wrong from the start? Clear the “Changes apply from” date before saving: the change applies from the beginning.

Is a player of the category in no group? They do not appear in group sessions. The “Groups” card shows a “Without group” badge with how many they are: click it to see the names. You can also choose the “Group” in the branch card when you register the player.

A schedule line left on “Whole category” lists every player of the category in its sessions. In the “Month grid”, these sessions are under “Whole category”.

:::tip
A player comes with the other group to make up a session? On the session's page, find them in the search field under the list and add them. They stay in their own group.
:::

:::warning
A group used by a “Weekly schedule” line or by a session cannot be deleted: the delete button stays greyed out. To change its name, click the pencil button.
:::
