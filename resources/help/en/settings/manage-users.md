---
title: How do I disable an account, delete it or reset its password
summary: Disable the account of someone who left the club, reset a forgotten password, and delete the account if needed.
module: users
pages: [users.index, users.edit]
keywords: [disable account, disabled account, enable account, delete user, forgot password, reset password, inactive, pending, approve, users]
related: [settings/create-user, getting-started/login, settings/roles-rights, settings/log-in-as-activity]
---

The table shows all accounts, 25 per page. From an account's row, you disable it, reset its password or delete it. An old account waiting for approval shows “Pending” and an “Approve” button.

:::example
Coach ليلى بن صالح left the club, and her account is disabled. Accountant نادية خليفي forgot her password, so you give her a new one.
:::

:::step Open the “Users” page
On a phone, first open the menu with the ☰ button. In the menu, click “Users” under “System”. You don't see it? Click “System” to show it. You need the “View” right in “Users”.
::figure set-users ring=nav-users
:::

:::step Find the account
Type the name or the username in “Search”. Or choose “Inactive” in “Activity” to see only the disabled accounts.
::figure set-users ring=users-search,users-filter-activity
:::

:::step Disable or enable the account
In the account's row, click the button with a crossed-out circle. When you hover over it, its name “Disable” appears. The account changes at once, with no question. The message “User disabled.” appears. Its owner is logged out of the app. If they try to log in, they see the message “This account is disabled. Contact the club administrator.”. To bring it back, click the same button, now named “Enable”. You need the “Edit” right in “Users”. A regular administrator cannot disable a super admin.
::figure set-users-rows ring=users-toggle-samir zoom=users-toggle-samir
:::

:::step Click the key button of نادية
In نادية's row, click the key button. When you hover over it, its name “Reset Password” appears. A window with the same name opens. This button is for the super admin only, and it does not show in your own row. You also need the “Edit” right in “Users”.
::figure set-users-rows ring=users-reset-nadia zoom=users-reset-nadia
:::

:::step Type the new password twice
Type it in “New Password”, then type it again in “Confirm Password”. The password has at least 8 characters.
::figure set-user-reset-open ring=users-reset-new,users-reset-confirm
:::

:::step Click “Save”
The message “Password reset successfully.” appears. The window logs نادية out of her “Remember me” sessions. She can log in with the new password at once. Give it to her yourself.
::figure set-user-reset ring=users-reset-save zoom=users-reset-save
:::

:::step Delete the account
In the account's row, click the red button with a trash can. When you hover over it, its name “Delete” appears. The “Confirm Action” window opens, with “Are you sure?”. Click “Delete” in the window to confirm. You need the “Delete” right in “Users”.
::figure set-users-rows ring=users-delete-layla zoom=users-delete-layla
:::

:::warning
Deleting cannot be undone. Prefer “Disable” for someone who left: the person's whole history is kept. You cannot delete your own account, nor the last super admin. And a regular administrator cannot delete a super admin.
:::
