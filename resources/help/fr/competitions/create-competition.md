---
title: Comment créer une compétition et fixer sa liste de joueurs
summary: Créez un championnat ou une coupe pour une équipe sur une saison, puis décochez ceux qui n'y joueront pas.
module: competitions
pages: [competitions.index, competitions.show]
keywords: [compétition, compétitions, nouvelle compétition, championnat, coupe, tournoi, amical, type de compétition, liste de la compétition, règles de suspension, réglages, format de jeu]
related: [competitions/schedule-match, competitions/call-up, competitions/roster-coaches, competitions/suspensions]
---

Une compétition est un championnat, une coupe ou un tournoi joué par une seule équipe sur une seule saison. Elle a une liste de joueurs qui commence avec tout l'effectif de l'équipe. Les matchs de la compétition convoquent depuis cette liste.

:::example
Nous créons « كأس الولاية لأقل من 17 سنة » (coupe de wilaya des moins de 17 ans) pour l'équipe « الفريق أ » (أقل من 17 سنة). Puis nous vérifions sa liste, avec les sept joueurs de l'équipe.
:::

:::step Ouvrez la page « Compétitions »
Sur téléphone, ouvrez d'abord le menu avec le bouton ☰. Dans le menu, cliquez sur « Compétitions » sous « Compétitions et matchs ». Vous ne la voyez pas ? Cliquez sur « Compétitions et matchs » pour l'afficher. La compétition est créée dans la saison affichée en haut de la page, par exemple 2026/27.
::figure comp-create-competition-list ring=nav-competitions
:::

:::step Cliquez sur « Nouvelle compétition »
Un formulaire apparaît au-dessus du tableau. Il vous faut le droit « Ajouter » dans « Compétitions ». Un entraîneur limité à ses équipes voit le bouton s'il entraîne une équipe cette saison. Il ne choisit que parmi ses équipes.
::figure comp-create-competition-list ring=compn-new zoom=compn-new
:::

:::step Remplissez le formulaire
Dans « Équipe », choisissez : الفريق أ · أقل من 17 سنة. Dans « Nom », tapez : كأس الولاية لأقل من 17 سنة. « Type » propose quatre choix : « Championnat », « Coupe », « Tournoi » et « Amical ». Choisissez : Coupe. Laissez « Format de jeu » sur « Format de l'équipe ». La compétition se joue alors au format de l'équipe, ici « Football à 5 ». Choisissez un autre format si vous voulez. Seuls les formats du sport de l'équipe s'affichent.
::figure comp-create-competition-form ring=compn-team,compn-name,compn-type,compn-format
:::

:::step Cliquez sur « Ajouter »
Le bouton reste grisé tant que vous n'avez pas choisi d'équipe. Le message « Compétition créée. » s'affiche, et la page de la compétition s'ouvre sur l'onglet « Liste ». Sa liste commence avec tous les joueurs de l'effectif de l'équipe cette saison.
::figure comp-create-competition-form ring=compn-add zoom=compn-add
:::

:::step Décochez ceux qui ne joueront pas
La liste montre les joueurs de l'effectif de l'équipe cette saison, avec leurs numéros et leurs badges. Au départ, chaque joueur est coché. Décochez ceux qui ne joueront pas dans la compétition. Dans notre exemple, tout le monde joue : nous laissons les cases cochées. Un joueur absent de l'effectif n'apparaît pas ici. Ajoutez-le d'abord depuis la page de l'équipe. Un joueur qui sort de l'effectif sort aussi de la liste. L'image montre une compétition existante, avec une liste complète.
::figure comp-create-competition-squad ring=compn-squad-list
:::

:::step Cliquez sur « Enregistrer »
Le message « Liste enregistrée. » s'affiche. Cliquez sur ce bouton après tout changement de coches : le changement n'est pas gardé avant. Dans notre exemple, nous n'avons rien changé : inutile d'enregistrer. Il vous faut le droit « Modifier » dans « Compétitions ». Un entraîneur limité à ses équipes enregistre seulement les listes des compétitions des équipes qu'il entraîne.
::figure comp-create-competition-squad ring=compn-squad-save zoom=compn-squad-save
:::

:::step Ouvrez l'onglet « Réglages »
Cliquez sur « Réglages ». Sous « Règles de suspension », il y a trois zones. La première : « Matchs de suspension après un rouge ». La deuxième : « Cartons jaunes avant suspension ». La troisième : « Matchs de suspension pour les jaunes ». Une nouvelle compétition commence avec un match de suspension après un rouge, et sans suspension pour les jaunes. L'application calcule les suspensions à partir des feuilles de match. Les détails sont dans l'article sur les suspensions. Dans notre exemple, nous les laissons telles quelles. L'image montre une compétition existante, avec trois jaunes avant suspension.
::figure comp-create-competition-rules ring=compn-tab-rules,compn-rule-red,compn-rule-yellow,compn-rule-yellow-ban
:::
