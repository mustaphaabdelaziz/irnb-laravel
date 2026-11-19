---
title: How do I change attendance codes, discipline rules and alerts
summary: Change the statuses' letters, colours and points, set the discipline rules and alerts, and add the club's own codes.
pages: [attendance.settings]
keywords: [attendance codes, code, colour, points, discipline rules, alert, alerts, unexcused streak, late, custom codes, travelling, hide code, attendance settings]
related: [attendance/month-grid, attendance/timetable]
---

Codes are the letters the coach writes on the sheet and in “Month grid”: “P” present, “R” late… Change them in “Attendance settings”.

:::example
The club's coaches write “ت” for late instead of “R”. The club wants an alert after 3 unexcused absences in a row. It also adds a new code “س” for a **travelling** player, counted as “Absent (excused)”.
:::

:::step Open the “Attendance settings” page
Open it from the “Attendance” page, as in “How do I set the weekly schedule and closures”. You need the “Edit” permission for “Attendance”.
::figure att-calendar ring=att-settings zoom=att-settings
:::

:::step Type “ت” as the late code
Scroll down to the “Codes and colours” card with the mouse wheel or the touchpad. On a phone, swipe the screen up with your finger. On the “Late” row, delete R from the “Code” field, and type ت. A code has 1 to 3 letters, and Arabic letters are allowed.
::figure set-codes ring=set-code-late zoom=set-code-late
:::

:::step Type 3 in the unexcused-streak alert
Under the “Codes and colours” card, look for “Alerts”. In the field “Alert after this many unexcused absences in a row”, type 3. It already holds 3: this is the default number. Zero turns the alert off.
::figure set-rules ring=set-alerts
:::

:::step Click “Save” under “Player statuses on the list”
The page has other “Save” buttons. Click the one under the “Player statuses on the list” card. The message “Attendance settings saved.” shows. This button saves the codes, colours, points, rules, alerts and player statuses together.
::figure set-rules ring=set-save zoom=set-save
:::

:::step Fill in the custom code row
In the “Custom codes” card, type س in “Code”. In “Name (Arabic)”, type “مسافر” (travelling). In “Counts as”, choose “Absent (excused)”. “Colour” already has a purple: change it if you like.
::figure set-custom ring=set-custom-form
:::

:::step Click “Add code”
In the “Custom codes” card, click “Add code”. The message “Attendance settings saved.” shows. “س” appears in the table under the fields, with the state “Shown”. And “مسافر” appears with the status buttons on the session page.
::figure set-custom ring=set-custom-add zoom=set-custom-add
:::

After saving, the coach types “ت15” in “Month grid” for 15 minutes late. Changing a code does not change saved attendance.

A duplicate code is not saved, and the message “Another status already uses this code.” shows. In “Codes and colours”, it shows under the status name. In “Custom codes”, it shows under the fields.

Each status row also has “Colour” and “Points”. Points go from −5 to 5, in quarter points. They count only for the player ranking and the alerts. Status counts in reports do not change.

“Discipline rules” is next to “Alerts”, and already holds 3 and 30. The first: every 3 late arrivals count in the points as one unexcused absence. The second: arriving more than 30 minutes late counts in the points as an unexcused absence. Zero turns the rule off.

“Counts as” has four values: “Present”, “Absent (excused)”, “Absent (unexcused)”, “Not counted”. “Not counted” leaves the session out of the player's figures.

:::warning
A custom code used in saved attendance cannot be deleted. You can only hide it with the box button on its row. Its name, “Hide”, shows when you hover the mouse over it.
:::
