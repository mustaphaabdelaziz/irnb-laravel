---
title: Comment saisir le rapport de match
summary: Notez le score de chaque mi-temps, les événements du match et la note des joueurs, puis enregistrez le rapport et imprimez-le.
pages: [games.show]
keywords: [rapport de match, score du match, buts, carton jaune, carton rouge, remplacement, penalty, homme du match, note des joueurs, note de l'entraîneur, suspension, temps de jeu, déroulé]
related: [competitions/lineup, competitions/call-up, competitions/edit-cancel-match, competitions/statistics]
---

Le rapport note ce qui s'est passé pendant le match : le score, les événements et la note des joueurs. Quand vous l'enregistrez, le match devient « Joué ». L'application calcule les minutes des joueurs et recalcule les suspensions de la compétition.

:::example
Nous saisissons le rapport du match « الفريق أ — اتحاد الشباب » de la journée 1. Il s'est terminé 3–1. La première mi-temps 2–1 et la deuxième 1–0. Buts de l'équipe : قاسمي أمين à la minute 12, sur une passe de لعريبي أنس. لعريبي أنس à la minute 19. قاسمي أمين sur penalty à la minute 28. Nous notons aussi un carton jaune pour لعريبي أنس à la minute 18. حمداني رياض est entré à la place de عمراني زكرياء à la minute 30. L'homme du match est قاسمي أمين.
:::

:::step Ouvrez l'onglet « Rapport »
Ouvrez le match comme dans « Comment convoquer les joueurs d'un match ». Cliquez sur « Rapport ». Il faut une convocation enregistrée, sinon vous lisez « Convoquez d'abord les joueurs : ». Le rapport attribue les événements aux joueurs convoqués seulement.
::figure comp-match-report-score ring=game-tab-report
:::

:::step Tapez le score de chaque mi-temps
Dans « Score », il y a deux lignes : « mi-temps 1 » et « mi-temps 2 ». Chaque ligne a deux zones : « Nous » et « Eux ». Tapez 2 et 1 pour la première mi-temps. Tapez 1 et 0 pour la deuxième. L'application calcule seule le « Score final » : 3 et 1. Si vous laissez les mi-temps vides, deux zones s'affichent pour le score final. Si vous laissez tout vide, l'application refuse d'enregistrer et dit : « Saisissez le score par période ou le score final. ».
::figure comp-match-report-score ring=report-score
:::

:::step Ajoutez un but
Cliquez sur « + But ». Une nouvelle ligne apparaît dans « Déroulé ». Dans « Min. », tapez la minute du match depuis le coup d'envoi, de 0 à 40. Ce n'est pas la minute de la mi-temps. Choisissez le type de but : « But », « Penalty », « Coup franc » ou « But contre son camp adverse ». Puis choisissez le « Buteur », et ensuite le « Passeur (facultatif) ». Un but contre son camp de l'adversaire n'a ni buteur ni passeur. Dans notre exemple, la ligne de la minute 12 : « But », buteur قاسمي أمين, passe de لعريبي أنس. La ligne de la minute 28 est de type « Penalty », sans passe. Ajoutez le but de la minute 19 de la même façon. Après l'enregistrement, un avertissement jaune peut s'afficher au-dessus du score. Par exemple « Les buts du déroulé ne correspondent pas à notre score. ». L'avertissement n'empêche pas d'enregistrer.
::figure comp-match-report-timeline ring=report-add-goal,report-event-0,report-event-3
:::

:::step Ajoutez un carton
Cliquez sur « + Sanction ». Une nouvelle ligne apparaît. Choisissez « Carton jaune » ou « Carton rouge », puis le « Joueur ». Tapez la minute dans « Min. ». Dans notre exemple, la ligne de la minute 18 : « Carton jaune » pour لعريبي أنس. La minute est obligatoire pour un carton rouge. Si vous l'oubliez, vous lisez « Une minute est requise ici. ».
::figure comp-match-report-timeline ring=report-add-sanction,report-event-1
:::

:::step Ajoutez un remplacement
Cliquez sur « + Remplacement ». Choisissez l'« Entrant », puis le « Sortant », et tapez la minute. La minute est obligatoire ici. Dans notre exemple, la ligne de la minute 30 : entrant حمداني رياض, sortant عمراني زكرياء. Si l'entrant est titulaire ou a déjà joué, l'application refuse d'enregistrer. Elle dit : « Ce joueur ne peut pas entrer (pas sur le banc, ou déjà joué). ». Le bouton corbeille, appelé « Supprimer », retire n'importe quelle ligne.
::figure comp-match-report-timeline ring=report-add-substitution,report-event-4
:::

:::step Notez les joueurs et choisissez l'homme du match
Dans le tableau « Joueurs », la colonne « Minutes » est calculée par l'application à partir de la composition et des événements. Ne la tapez pas. Dans « Note /10 », tapez un chiffre de 1 à 10. Dans notre exemple, قاسمي أمين a 9. Dans « Note de l'entraîneur », tapez pour بوزيد ياسين : « تمركز جيد في الدفاع » (bon placement en défense). Puis cliquez sur le rond « Homme du match » en face de قاسمي أمين. « Pas d'homme du match » laisse la case vide. Les notes et les remarques de l'entraîneur sont visibles par tous ceux qui ouvrent la page du match. C'est-à-dire tous ceux qui ont le droit « Voir » dans « Matchs ». Elles ne sont jamais imprimées et n'entrent dans aucun fichier.
::figure comp-match-report-grid ring=report-rating-3,report-note-2,report-mvp-3
:::

:::step Cliquez sur « Enregistrer le rapport »
Le message « Rapport de match enregistré. » s'affiche. Rien n'est enregistré avant ce clic. Il vous faut le droit « Modifier » dans « Matchs ». Un entraîneur limité à ses équipes saisit seulement les rapports des équipes qu'il entraîne. L'enregistrement remplace tout le déroulé par ce qui est sur la page. L'application recalcule les suspensions de la compétition.
::figure comp-match-report-save ring=report-save zoom=report-save
:::

:::tip
Après l'enregistrement, le match devient « Joué » : le lien « Rapport de match (PDF) » apparaît à côté de « Enregistrer le rapport ». Il ouvre une feuille avec le score, les buts, les cartons, les remplacements et les joueurs qui ont joué. Elle ne contient ni les notes ni les remarques de l'entraîneur.
:::
