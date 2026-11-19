---
title: Comment annuler, modifier ou supprimer un match
summary: Changez le statut ou la date d'un match, comprenez ce qu'efface le changement d'un match joué, puis supprimez le match si besoin.
pages: [games.show, games.index]
keywords: [annuler un match, match annulé, reporter un match, match reporté, modifier un match, statut du match, supprimer un match, effacer le score, nouvelle date, programmé]
related: [competitions/schedule-match, competitions/match-report, competitions/lineup]
---

Un match a quatre statuts : « Programmé », « Joué », « Reporté » et « Annulé ». Vous le changez depuis l'onglet « Match ». De là, vous modifiez aussi la date ou supprimez le match.

:::example
Le match « الفريق أ — شباب الحي » a été reporté : il est « Reporté ». Le club décide maintenant de l'annuler : nous passons son statut à « Annulé ». Puis nous voyons ce qui se passe quand on change le statut d'un match joué, « الفريق أ — اتحاد الشباب » (3–1). Enfin, nous voyons la confirmation de suppression du match.
:::

:::step Ouvrez le match
Ouvrez « Matchs » comme dans « Comment programmer un match ». Sous « Passés », cliquez sur la ligne « الفريق أ — شباب الحي ». Elle porte le statut « Reporté ». La page du match s'ouvre sur l'onglet « Match ».
::figure comp-edit-cancel-list ring=game-row-5
:::

:::step Choisissez « Annulé » dans « Statut »
La liste est sous les champs principaux. Ses choix : « Programmé », « Joué », « Reporté » et « Annulé ». Choisissez « Annulé ». Rien ne change avant l'enregistrement.
::figure comp-edit-cancel-status ring=game-status
:::

:::step Cliquez sur « Enregistrer »
Le message « Match modifié. » s'affiche. Il vous faut le droit « Modifier » dans « Matchs ». Un entraîneur limité à ses équipes modifie seulement les matchs des équipes qu'il entraîne. La convocation, la composition et le rapport restent tels quels.
::figure comp-edit-cancel-status ring=game-info-save zoom=game-info-save
:::

:::step Cliquez sur « Supprimer », puis sur « Annuler »
Le bouton est en bas de l'onglet, du côté opposé au bouton « Enregistrer ». Il s'affiche seulement pour qui a le droit « Supprimer » dans « Matchs » et gère l'équipe. Une fenêtre « Confirmer l'action » s'ouvre, avec « Êtes-vous sûr ? ». Le bouton « Supprimer » de la fenêtre supprime le match. Ensuite, vous revenez à « Matchs » et lisez « Match supprimé. ». Dans notre exemple, nous gardons le match annulé : nous cliquons sur « Annuler ».
::figure comp-edit-cancel-status ring=game-delete zoom=game-delete
::figure comp-edit-cancel-delete ring=confirm-cancel
:::

:::step Ouvrez un match joué
Ouvrez « Matchs » comme à l'étape 1. Sous « Passés », cliquez sur la ligne « الفريق أ — اتحاد الشباب ». Elle porte le score 3 – 1. Ne la confondez pas avec la ligne du même nom sous « À venir ». Celle-là est le match de la journée 4.
::figure comp-edit-cancel-list ring=game-row-1
:::

:::step Choisissez « Reporté » dans « Statut »
Les deux zones du score disparaissent avec ce choix. Rien ne change avant l'enregistrement.
::figure comp-edit-cancel-played ring=game-status
:::

:::step Cliquez sur « Enregistrer », puis sur « Annuler »
L'application demande : « Effacer le score ? ». Elle dit : « Le score du match (3 – 1) sera effacé en l’enregistrant avec le statut « Reporté ». ». Le bouton « Effacer le score » de la fenêtre confirme l'effacement. « Annuler » garde le score. Dans notre exemple, nous cliquons sur « Annuler ». L'effacement touche seulement le score. Les événements et les notes restent. Les suspensions de la compétition sont recalculées, car le match n'est plus « Joué ».
::figure comp-edit-cancel-played ring=game-info-save zoom=game-info-save
::figure comp-edit-cancel-erase ring=confirm-cancel
:::

:::warning
⚠️ La suppression est définitive. Elle efface la convocation, la composition, les événements du rapport et les notes des joueurs. Les suspensions de la compétition sont recalculées. Pour garder une trace, choisissez le statut « Annulé » au lieu de supprimer.
:::

:::tip
Pour une nouvelle date au lieu d'une annulation, changez « Date » et « Coup d'envoi », puis choisissez le statut « Programmé ». Cliquez ensuite sur « Enregistrer ». L'application ne change pas le statut toute seule quand la date change.
:::
