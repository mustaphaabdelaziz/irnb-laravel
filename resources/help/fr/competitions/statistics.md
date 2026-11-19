---
title: Comment lire les statistiques des matchs
summary: Ouvrez les statistiques des joueurs, filtrez-les par équipe ou par compétition, exportez-les, puis consultez celles de l'équipe, de la compétition et du joueur.
pages: [games.stats, teams.show, competitions.show]
keywords: [statistiques, statistiques des matchs, buteurs, passeurs, matchs joués, minutes, cartons, note, homme du match, exporter les statistiques, bilan de l'équipe, fiche du joueur]
related: [competitions/match-report, competitions/suspensions, competitions/create-competition]
---

Les statistiques sont calculées à partir des rapports des matchs « Joué ». Un joueur compte une participation quand il est convoqué et joue dans un match « Joué ». Un match « Reporté » ou « Annulé » n'y entre pas.

:::example
Nous lisons les statistiques de « الفريق أ » (أقل من 17 سنة) pour la saison en cours. L'équipe a joué trois matchs : deux victoires et un nul, 6 buts marqués et 2 encaissés. Nous cherchons le meilleur buteur : c'est قاسمي أمين, avec trois buts.
:::

:::step Ouvrez « Statistiques »
Sur téléphone, ouvrez d'abord le menu avec le bouton ☰. Dans le menu, cliquez sur « Statistiques » sous « Compétitions et matchs ». Vous ne la voyez pas ? Cliquez sur « Compétitions et matchs » pour l'afficher. Il vous faut le droit « Voir » dans « Matchs ». Un entraîneur limité à ses équipes voit les statistiques de toutes les équipes.
::figure comp-statistics-page ring=nav-games-stats
:::

:::step Choisissez l'équipe
En haut de la page, il y a trois listes : la saison, « Équipe » et « Compétition ». La saison commence sur la saison en cours. Cliquez sur « Équipe » et choisissez : الفريق أ · أقل من 17 سنة. « Compétition » montre ensuite les compétitions de cette équipe. Choisissez-en une pour limiter les chiffres à une seule compétition. Changer d'équipe ou de saison vide « Compétition ».
::figure comp-statistics-page ring=stats-team,stats-competition
:::

:::step Lisez les tableaux de classement et le tableau
Les classements : « Buteurs », « Passeurs », « Temps de jeu », « Matchs joués » et « Cartons ». Chaque classement montre cinq noms au plus. Dans notre exemple, le premier des « Buteurs » est قاسمي أمين. Le classement « Meilleure note (3 matchs et +) » reste vide tant qu'aucun joueur n'a été noté dans trois matchs. Les colonnes du tableau : « Joueur », « Matchs », « Titulaire », « Minutes » et « Buts ». Puis « Passes décisives », « Jaunes », « Rouges », « Note » et « Homme du match ». Le tableau est trié par nom.
::figure comp-statistics-team ring=stats-leaders,stats-table
:::

:::step Cliquez sur le bouton « Exporter »
C'est le bouton avec la flèche vers le bas. Quand vous placez la souris dessus, son nom « Exporter » s'affiche. Choisissez « Excel (.xlsx) », « CSV » ou « PDF ». Le fichier suit la saison, l'équipe et la compétition choisies. Ses colonnes sont celles du tableau, sauf « Note ». Il ajoute une colonne « Sets ». Les notes restent dans l'application, car les fichiers circulent.
::figure comp-statistics-export ring=stats-export,stats-export-xlsx,stats-export-csv,stats-export-pdf
:::

:::step Ouvrez « Statistiques » sur la page de l'équipe
Ouvrez la page de « الفريق أ » (أقل من 17 سنة) comme dans « Comment préparer l'effectif et les entraîneurs d'une équipe ». Cliquez sur « Statistiques ». Vous voyez le bilan de l'équipe : trois matchs joués, deux victoires et un nul. Ses buts : 6 marqués et 2 encaissés. À côté, « Forme » : V, puis N, puis V. En dessous, les mêmes classements, et un bouton « Exporter » pour cette équipe. L'onglet ne s'affiche pas sans le droit « Voir » dans « Matchs ».
::figure comp-statistics-team-page ring=team-tab-stats,team-stats-record
:::

:::step Ouvrez « Statistiques » sur la page de la compétition
Ouvrez la page de « البطولة الولائية لأقل من 17 سنة » (championnat de wilaya des moins de 17 ans). Faites-le comme à l'étape 1 de « Comment suivre les suspensions et ajouter une suspension manuelle ». Cliquez sur « Statistiques ». Vous voyez les classements de cette compétition seule. Il n'y a ni tableau ni export.
::figure comp-statistics-competition ring=compn-tab-stats,compn-stats
:::

:::step Ouvrez la partie « Matchs » sur la page d'un joueur
Ouvrez la page de قاسمي أمين comme dans « Comment rechercher un joueur ». Descendez jusqu'à la partie « Matchs ». Les chiffres de la saison sont dans des cases, avec les noms des colonnes du tableau. En dessous, ses matchs : la date, l'adversaire, le score, son rôle et ses minutes. Choisissez une autre saison dans la liste en haut de la partie. Si le joueur est suspendu, un avertissement « Suspendu » s'affiche en haut. La partie ne s'affiche pas sans le droit « Voir » dans « Matchs ».
::figure comp-statistics-player ring=player-matches,player-matches-season
:::

:::tip
Cliquez sur le nom d'un joueur dans les classements ou le tableau pour ouvrir sa page. Vous y trouvez la partie « Matchs ». Les noms sont des liens pour qui a le droit « Voir » dans « Joueurs ». Sans ce droit, ils restent du texte simple.
:::
