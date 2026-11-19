---
title: How do I enter the match report
summary: Record the score of each half, the match events and the player ratings, then save the report and print it.
pages: [games.show]
keywords: [match report, match score, goals, yellow card, red card, substitution, penalty, man of the match, player ratings, coach note, suspension, minutes played, timeline]
related: [competitions/lineup, competitions/call-up, competitions/edit-cancel-match, competitions/statistics]
---

The report records what happened in the match: the score, the events and the player ratings. When you save it, the match becomes “Played”. The app works out the players' minutes and works out the competition's suspensions again.

:::example
We enter the report of the match “الفريق أ — اتحاد الشباب” in round 1. It ended 3–1. The first half was 2–1 and the second 1–0. The team's goals: قاسمي أمين in minute 12, from a pass by لعريبي أنس. لعريبي أنس in minute 19. قاسمي أمين from a penalty in minute 28. We also record a yellow card for لعريبي أنس in minute 18. حمداني رياض came on for عمراني زكرياء in minute 30. The man of the match is قاسمي أمين.
:::

:::step Open the “Report” tab
Open the match as in “How do I call up players for a match”. Click “Report”. A saved call-up is needed, otherwise you read “Call players up first:”. The report gives events to called-up players only.
::figure comp-match-report-score ring=game-tab-report
:::

:::step Type the score of each half
In “Score” there are two rows: “half 1” and “half 2”. Each row has two boxes: “Us” and “Them”. Type 2 and 1 for the first half. Type 1 and 0 for the second. The app works out the “Final score” by itself: 3 and 1. If you leave the halves empty, two boxes show for the final score. If you leave everything empty, the app refuses to save and says: “Enter the score per period or the final score.”.
::figure comp-match-report-score ring=report-score
:::

:::step Add a goal
Click “+ Goal”. A new row appears in “Timeline”. In “Min.”, type the match minute counted from kick-off, from 0 to 40. It is not the minute of the half. Choose the type of goal: “Goal”, “Penalty”, “Free kick” or “Opponent's own goal”. Then choose the “Scorer”, and after that the “Assist (optional)”. An opponent's own goal has no scorer and no assist. In our example, the minute 12 row: “Goal”, scorer قاسمي أمين, assist from لعريبي أنس. The minute 28 row has the type “Penalty”, with no assist. Add the minute 19 goal the same way. After saving, a yellow warning may show above the score. For example “The goals in the timeline don't add up to our score.”. The warning does not block saving.
::figure comp-match-report-timeline ring=report-add-goal,report-event-0,report-event-3
:::

:::step Add a card
Click “+ Sanction”. A new row appears. Choose “Yellow card” or “Red card”, then the “Player”. Type the minute in “Min.”. In our example, the minute 18 row: “Yellow card” for لعريبي أنس. The minute is required for a red card. If you forget it, you read “A minute is required here.”.
::figure comp-match-report-timeline ring=report-add-sanction,report-event-1
:::

:::step Add a substitution
Click “+ Substitution”. Choose the “Player on”, then the “Player off”, and type the minute. The minute is required here. In our example, the minute 30 row: player on حمداني رياض, player off عمراني زكرياء. If the player coming on is a starter or has already played, the app refuses to save. It says: “This player can't come on (not on the bench, or already played).”. The bin button, called “Delete”, removes any row.
::figure comp-match-report-timeline ring=report-add-substitution,report-event-4
:::

:::step Rate the players and pick the man of the match
In the “Players” table, the app works out the “Minutes” column from the line-up and the events. Do not type it. In “Rating /10”, type a number from 1 to 10. In our example, قاسمي أمين gets 9. In “Coach note”, type for بوزيد ياسين: “تمركز جيد في الدفاع” (good positioning in defence). Then click the “MOTM” circle next to قاسمي أمين. “No man of the match” leaves it empty. Ratings and coach notes are visible to everyone who opens the match page. That means everyone with the “View” right in “Matches”. They are never printed and never go into any file.
::figure comp-match-report-grid ring=report-rating-3,report-note-2,report-mvp-3
:::

:::step Click “Save report”
The message “Match report saved.” appears. Nothing is saved before you click. You need the “Edit” right in “Matches”. A coach limited to their own teams enters reports only for the teams they coach. Saving replaces the whole timeline with what is on the page. The app works out the competition's suspensions again.
::figure comp-match-report-save ring=report-save zoom=report-save
:::

:::tip
After saving, the match becomes “Played”, so the “Match report (PDF)” link appears next to “Save report”. It opens a sheet with the score, the goals, the cards, the substitutions and the players who played. It does not show the ratings or the coach notes.
:::
