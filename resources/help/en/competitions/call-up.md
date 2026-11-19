---
title: How do I call up players for a match
summary: Pick the match players from the competition squad or the roster, check the badges, then print the convocation sheet.
pages: [games.show]
keywords: [call-up, call up players, match squad, convocation sheet, reason not called, injured, suspended, no licence, unpaid, call-up limit, number called up]
related: [competitions/schedule-match, competitions/create-competition, competitions/roster-coaches, competitions/lineup]
---

The call-up is the list of players invited to the match. For a competition match, you pick from the competition squad. For a friendly, you pick from the team roster. The call-up limit comes from the game format.

:::example
We call up the players for the match “الفريق أ — اتحاد الشباب” in the wilaya league. It is round 4, played as “Football 5”. Its limit is ten players.
:::

:::step Open the “Matches” page
On a phone, first open the menu with the ☰ button. In the menu, click “Matches” under “Competitions & matches”. Can't see it? Click “Competitions & matches” to show it.
::figure comp-schedule-match-list ring=nav-games
:::

:::step Open the match
Under “Upcoming”, click the row “الفريق أ — اتحاد الشباب”. The match page opens on the “Match” tab.
::figure comp-schedule-match-list ring=game-row-4
:::

:::step Open the “Call-up” tab
Click “Call-up”. You see every player who can be called up: each player's number, name and category. A player who left the team's sport does not appear.
::figure comp-call-up-list ring=game-tab-callup
:::

:::step Tick the players you call up
Tick each player you want to call up. The counter above the table shows “6 called up (max 10)”. In our example we call up six players. If the counter goes over the limit, it turns red. The app then refuses to save and says: “Too many players called up for this game format.”. Next to the names, badges only warn you; they do not block the call-up. حمداني رياض has “Injured”. بلعيد عبد الرحمن, قاسمي أمين and عمراني زكرياء have “Unpaid”. بن علي أحمد has “No licence”. Hover over “Unpaid” to see “Owes” followed by the amount. The amount shows for users with the “View” right in “Finance”.
::figure comp-call-up-list ring=game-callup-count,game-callup-table,game-callup-badges-4,game-callup-badges-7,game-callup-badges-3,game-callup-badges-8,game-callup-badges-1
:::

:::step Write why a player is left out
Under “Reason if not called”, a box appears for each unticked player. Type a short reason in it. In our example, for حمداني رياض: “مصاب” (injured). The app keeps the player who is not called up only if you type a reason. If you leave the box empty, no row is kept for him.
::figure comp-call-up-list ring=game-callup-reason-4
:::

:::step Click “Save”
The message “Call-up saved.” appears. Nothing is saved before you click. You need the “Edit” right in “Matches”. A coach limited to their own teams calls up players only for the teams they coach.
::figure comp-call-up-list ring=game-callup-save zoom=game-callup-save
:::

:::step Click “Convocation sheet (PDF)”
The link is above the table, after saving. A sheet opens with the match, the meeting time and the players called up. Print it or send it.
::figure comp-call-up-list ring=game-callup-pdf zoom=game-callup-pdf
:::

:::warning
⚠️ Does a player have events, a rating or a spot in the line-up? If you untick him, the app asks you before saving. You read “Delete data from the report?”. Then: “Everything the report holds about the players who are no longer called up will be deleted: events, rating, note and line-up spot. Players:” and their names. The “Save” button in the window confirms the deletion. “Cancel” keeps the call-up as it is. After you confirm, you read “Call-up saved.”. If he had events, you read “Call-up saved. Report events of players no longer called up were removed.”.
:::
