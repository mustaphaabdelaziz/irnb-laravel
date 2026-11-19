---
title: Comment programmer un match
summary: Ajoutez un match avec son adversaire, sa date et son terrain, et décidez si l'entraînement de ce jour-là est annulé.
pages: [games.index, games.show]
keywords: [match, matchs, nouveau match, programmer un match, amical, domicile, extérieur, terrain neutre, heure du rendez-vous, lieu du rendez-vous, journée, annuler l'entraînement, calendrier des matchs]
related: [competitions/call-up, competitions/create-competition, competitions/opponents-venues]
---

Chaque match oppose une équipe à un adversaire. Vous le programmez avec sa date et son terrain, puis vous convoquez ses joueurs. En le programmant, vous pouvez aussi annuler l'entraînement de l'équipe ce jour-là.

:::example
Nous programmons un match amical de l'équipe « الفريق أ » (أقل من 17 سنة) contre « نجم الساحل ». Il se joue mercredi prochain à 18:00 au « ملعب حي النصر ». Le mercredi est un jour d'entraînement de cette équipe.
:::

:::step Ouvrez la page « Matchs »
Sur téléphone, ouvrez d'abord le menu avec le bouton ☰. Dans le menu, cliquez sur « Matchs » sous « Compétitions et matchs ». Vous ne la voyez pas ? Cliquez sur « Compétitions et matchs » pour l'afficher. La page montre « À venir », puis « Passés ».
::figure comp-schedule-match-list ring=nav-games
:::

:::step Cliquez sur « Nouveau match »
Un formulaire apparaît au-dessus des deux listes. Il vous faut le droit « Ajouter » dans « Matchs ». Un entraîneur limité à ses équipes programme seulement les matchs des équipes qu'il entraîne.
::figure comp-schedule-match-list ring=game-new zoom=game-new
:::

:::step Choisissez l'équipe et l'adversaire
Dans « Équipe », choisissez : الفريق أ · أقل من 17 سنة. Laissez « Compétition » sur « Amical (sans compétition) ». Pour un match de compétition, choisissez la compétition ici. Seules les compétitions de l'équipe choisie s'affichent. Dans « Adversaire », choisissez : نجم الساحل. Laissez « Domicile / extérieur » sur « domicile ». Ses trois choix : « domicile », « extérieur » et « terrain neutre ».
::figure comp-schedule-match-who ring=game-team,game-competition,game-opponent,game-side
:::

:::step Indiquez le jour, l'heure et le terrain
Dans « Date », choisissez mercredi prochain. Dans « Coup d'envoi », tapez 18:00. Dans « Lieu », choisissez : ملعب حي النصر. La zone « Journée / tour » sert aux matchs de championnat : laissez-la vide. Le nom de la journée est un texte libre, comme « Journée 7 ».
::figure comp-schedule-match-when ring=game-date,game-kickoff,game-venue
:::

:::step Indiquez l'heure et le lieu du rendez-vous
Dans « Heure du rendez-vous », tapez 17:00. Dans « Lieu du rendez-vous », tapez : « مقر النادي » (siège du club). Ils figurent sur la feuille de convocation. Les deux zones sont facultatives.
::figure comp-schedule-match-meeting ring=game-meeting-time,game-meeting-place
:::

:::step Laissez la case d'annulation de l'entraînement telle quelle
La case « Annuler l'entraînement de l'équipe ce jour-là » est cochée au départ. À l'enregistrement, l'application annule la séance de l'équipe prévue ce jour-là. Elle apparaît dans « Présence » avec le texte « Annulée : Match contre نجم الساحل ». Cela touche seulement les séances de la catégorie et du sport de l'équipe. Dans notre exemple, nous la laissons, car le mercredi est un jour d'entraînement. Décochez-la si vous voulez garder la séance.
::figure comp-schedule-match-meeting ring=game-cancel-training
:::

:::step Cliquez sur « Ajouter »
Le bouton reste grisé tant que vous n'avez pas choisi l'équipe et l'adversaire. Si une séance est annulée, le message « Match programmé et entraînement du jour annulé. » s'affiche. Sinon, c'est « Match programmé. ». Dans notre exemple, c'est le premier. Puis la page du match s'ouvre. De là, vous convoquez les joueurs.
::figure comp-schedule-match-meeting ring=game-add zoom=game-add
:::

::figure comp-schedule-match-list ring=game-view-toggle

:::tip
Le bouton « Calendrier » en haut de « Matchs » affiche le mois. Les matchs y apparaissent jour par jour. Choisissez une équipe pour voir aussi ses séances d'entraînement. Le bouton « Liste » vous ramène aux deux listes.
:::
