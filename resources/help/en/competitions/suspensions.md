---
title: How do I track suspensions and add a manual suspension
summary: Set the competition's suspension rules, check the automatic bans from the reports, and add or delete a manual suspension.
module: competitions
pages: [competitions.show]
keywords: [suspension, suspensions, ban, suspended, red card, yellow cards, automatic ban, manual suspension, suspension rules, date range, number of matches, all competitions, delete suspension, disciplinary committee]
related: [competitions/match-report, competitions/call-up, competitions/create-competition]
---

A suspension keeps a player out of the competition's matches. The app works it out by itself from the match reports. You can also add a suspension by your own decision.

:::example
In “البطولة الولائية لأقل من 17 سنة” (the wilaya under-17 league), a red card means a one-match ban. Three yellow cards also mean a one-match ban. بلعيد عبد الرحمن got a red card in round 2, so he missed round 3. لعريبي أنس has two yellow cards, so he is not banned. Then we add a one-match manual suspension for him, and delete it.
:::

:::step Open the competition
Open “Competitions” as in “How do I create a competition and set its squad”. Click the name “البطولة الولائية لأقل من 17 سنة”. Its page opens on the “Squad” tab.
::figure comp-suspensions-list ring=compn-link-1
:::

:::step Open the “Settings” tab
Click “Settings”. Under “Suspension rules” there are three boxes. The first, “Matches banned after a red card”, is 1 here. The second, “Yellow cards before a ban”, is 3 here. The third, “Matches banned for yellow cards”, is 1 here. After each ban for yellow cards, the yellow count starts again from zero. Type 0 in the first to turn off bans for red cards. Leave the second empty to turn off bans for yellow cards. Below them you read: “Suspensions are worked out automatically from match reports using these rules.”.
::figure comp-suspensions-rules ring=compn-tab-rules,compn-rule-red,compn-rule-yellow,compn-rule-yellow-ban,compn-rules-help
:::

:::step Click “Save”
Click it after any change in the three boxes. The message “Competition updated.” appears. The app works out the automatic bans again from the reports. In our example we changed nothing, so there is no need to save. You need the “Edit” right in “Competitions”. A coach limited to their own teams saves rules only for the competitions of the teams they coach.
::figure comp-suspensions-rules ring=compn-rules-save zoom=compn-rules-save
:::

:::step Open the “Suspensions” tab
Click “Suspensions”. The table columns: “Player”, “Cause”, “Ban” and “Status”. In our example there is the row for بلعيد عبد الرحمن. Its cause is “red card”, with the match date and the opponent's name. Its ban is “Matches served: 1/1”. Its status is “Served”. The status “Active” means some matches are still left. The status “Not started” is for a ban that starts on a date still to come. The ban is served in “Played” matches after the match of the card. A postponed, cancelled or friendly match does not count. Whatever is left of the ban moves to the team's official matches in the next seasons. If a player has more than one ban in the same scope, they are served one after the other.
::figure comp-suspensions-bans ring=compn-tab-suspensions,compn-ban-auto,compn-ban-col-length,compn-ban-col-status
:::

:::step Fill in the “Add a manual suspension” form
The form is below the table. In “Player”, choose لعريبي أنس. Leave “Applies to” on “This competition”. “All competitions” applies it to every official match of the player's teams. Leave “Type” on “Number of matches”, and leave “Matches” at 1. Leave “From” empty, so the count starts today. A line reminds you: “Leave empty to start today.”. The first match counted is the one after today, not a match on the same day. In “Reason”, type: “قرار لجنة الانضباط” (disciplinary committee decision). With “This competition”, you see only the players in the competition squad. With “All competitions”, you see the players on the team's roster this season. For a ban by dates, choose “Date range” in “Type”. “Matches” disappears and “Until” appears. The “From” and “Until” boxes are required, and the ban includes both days. With “This competition”, it covers only the competition's matches. With “All competitions”, it covers every match, even friendlies.
::figure comp-suspensions-form ring=compn-ban-player,compn-ban-count,compn-ban-from-hint,compn-ban-reason
::figure comp-suspensions-dates ring=compn-ban-kind,compn-ban-from,compn-ban-to
:::

:::step Click “Add”
The message “Suspension added.” appears. لعريبي أنس shows at the top of the table with the status “Active”. His ban starts from round 4. You need the “Edit” right in “Competitions”. A coach limited to their own teams adds suspensions only for the teams they coach.
::figure comp-suspensions-form ring=compn-ban-add zoom=compn-ban-add
:::

:::step Click the bin button
At the end of the manual suspension's row there is a bin button. When you hover over it, its name “Delete” shows. A “Confirm Action” window opens, with “Remove this suspension?”. Click “Delete” in the window. The message “Suspension removed.” appears. You need the “Edit” right in “Competitions”. A coach limited to their own teams deletes suspensions only for the teams they coach. In our example we delete the suspension of لعريبي أنس.
::figure comp-suspensions-added ring=compn-ban-delete zoom=compn-ban-delete
::figure comp-suspensions-delete-confirm ring=confirm-ok
:::

:::warning
⚠️ Deleting a suspension is final. An automatic ban row has no delete button. It changes when you edit the match report, or when you change the suspension rules.
:::

:::tip
An active suspension shows a “Suspended” badge in the match call-up. It only warns you; it does not block the call-up.
:::
