---
title: Comment répartir une catégorie en groupes
summary: Répartissez les joueurs d'une catégorie en groupes : chaque groupe a ses séances, sa liste de joueurs et sa feuille.
pages: [attendance.settings, attendance.grid]
keywords: [groupes, groupe, groupes d'entraînement, répartir la catégorie, joueurs du groupe, changer de groupe, déplacer un joueur, feuille du groupe]
related: [attendance/timetable, attendance/month-grid, players/branch-registrations]
---

Une catégorie s'entraîne à deux horaires différents ? Répartissez-la en groupes. La séance d'un groupe n'affiche que ses joueurs.

:::example
La catégorie « أصاغر » en « Natation » s'entraîne le samedi en deux groupes. « الفوج 1 » de 09:30 à 11:00, et « الفوج 2 » de 11:00 à 12:30. Plus tard, **سارة بن يوسف** passe de « الفوج 1 » à « الفوج 2 ».
:::

:::step Ouvrez les « Réglages des présences »
Sur la page « Présence », cliquez sur le bouton avec trois lignes portant chacune un petit rond. Son nom « Réglages des présences » s'affiche quand vous passez la souris dessus. Vous ne le voyez pas ? Il vous faut le droit « Modifier » dans « Présence ».
::figure att-calendar ring=att-settings zoom=att-settings
:::

:::step Ajoutez les deux groupes
Dans la carte « Groupes », choisissez « Natation » dans « Section », et « أصاغر » dans « Catégorie ». Tapez « الفوج 1 » dans « Nom du groupe ». Cliquez sur « Ajouter ». Le message « Groupe enregistré. » s'affiche. Ajoutez « الفوج 2 » de la même façon.
::figure set-group-typed ring=set-group-form,set-group-add
:::

:::step Cochez les joueurs de chaque groupe
Sur la ligne de « الفوج 1 », cliquez sur le bouton avec le dessin de deux personnes. Son nom « Joueurs du groupe » s'affiche quand vous passez la souris dessus. La liste des joueurs de la catégorie s'affiche. Cochez سارة, إيناس et يوسف. La première fois, le champ de date est vide : laissez-le ainsi. Cliquez sur « Enregistrer ». Le message « Joueurs du groupe enregistrés. » s'affiche. Faites de même pour « الفوج 2 ».
::figure set-group-list ring=set-group-players zoom=set-group-players
::figure set-group-dialog ring=group-players-list,group-players-save
:::

:::step Choisissez le groupe sur la ligne de l'horaire
Dans la carte « Horaire hebdomadaire », choisissez « Natation » puis « أصاغر ». Le champ « Groupe » apparaît : choisissez « الفوج 1 ». Dans « Jour », choisissez « samedi ». Tapez 09:30 dans « Début », et 11:00 dans « Fin ». Cliquez sur « Ajouter ». Ajoutez la ligne de « الفوج 2 », de 11:00 à 12:30.
::figure set-schedule-group ring=set-schedule-group,set-schedule-add
:::

:::step Ouvrez la feuille de chaque groupe
Sur la page « Présence », choisissez « Natation » puis « أصاغر », et ouvrez la « Grille du mois ». Au-dessus de la grille, une barre porte le nom de chaque groupe. Cliquez sur « الفوج 1 » : la grille n'affiche que ses séances et ses joueurs. Le bouton imprimante « Imprimer la feuille » imprime la feuille de ce groupe, avec son nom en haut.
::figure att-grid-groups ring=grid-groups,grid-print
:::

:::step Changez un joueur de groupe
Ouvrez la page de سارة, et cliquez sur le bouton « Modifier le joueur ». Dans la carte « Natation », choisissez « الفوج 2 » dans « Groupe ». Le champ « Changements appliqués à partir du » apparaît avec la date du jour : choisissez le jour du changement. Cliquez sur « Enregistrer les modifications ».
::figure player-edit-group ring=player-group,player-group-from
:::

Avant le jour du changement, سارة reste sur la liste de « الفوج 1 » dans les séances dont les présences ne sont pas enregistrées. À partir de ce jour, elle apparaît avec « الفوج 2 ». Une séance dont les présences sont enregistrées ne change jamais.

Vous vous êtes trompé de groupe dès le début ? Effacez la date de « Changements appliqués à partir du » avant d'enregistrer : le changement s'applique depuis le début.

Un joueur de la catégorie n'est dans aucun groupe ? Il n'apparaît pas dans les séances des groupes. La carte « Groupes » affiche un badge « Sans groupe » avec leur nombre : cliquez dessus pour voir les noms. Vous pouvez aussi choisir le « Groupe » dans la carte de la section, à l'inscription du joueur.

Une ligne de l'horaire laissée sur « Toute la catégorie » affiche tous les joueurs de la catégorie dans ses séances. Dans la « Grille du mois », ces séances sont sous « Toute la catégorie ».

:::tip
Un joueur vient avec l'autre groupe pour rattraper une séance ? Sur la page de la séance, cherchez-le dans le champ de recherche sous la liste et ajoutez-le. Il reste dans son groupe.
:::

:::warning
Un groupe utilisé par une ligne de l'« Horaire hebdomadaire » ou par une séance ne peut pas être supprimé : le bouton de suppression reste grisé. Pour changer son nom, cliquez sur le bouton crayon.
:::
