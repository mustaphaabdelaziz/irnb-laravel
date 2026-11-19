---
title: Comment voir l'application avec les yeux d'un autre utilisateur et suivre son activité
summary: Connectez-vous en tant qu'un utilisateur pour voir ce qu'il voit, puis consultez ce qu'il a fait dans la saison.
module: users
pages: [users.index, users.activity.index, users.activity.show]
keywords: [se connecter en tant que, connexion en tant que, usurpation, autre utilisateur, revenir à mon compte, activité, activité utilisateur, journal, par, mon activité]
related: [settings/create-user, settings/manage-users, settings/roles-rights]
---

« Se connecter en tant que » vous montre l'application exactement comme l'utilisateur la voit. Il faut le droit « Modifier » dans « Utilisateurs ». Vous ne pouvez pas vous connecter avec le compte d'un super administrateur, ni avec un compte désactivé. Ni avec un compte qui a des droits que vous n'avez pas, sauf si vous êtes super administrateur. La page « Activité » demande le droit « Voir » dans « Utilisateurs ». Chaque utilisateur voit sa propre activité sous « Mon activité », dans le menu de son nom.

:::example
L'entraîneur سمير بوعزة dit qu'il ne trouve pas la page des abonnements. Vous vous connectez avec son compte pour voir ce qu'il voit, puis vous revenez à votre compte. Ensuite, vous consultez son activité de cette saison.
:::

:::step Ouvrez la page « Utilisateurs »
Sur téléphone, ouvrez d'abord le menu avec le bouton ☰. Dans le menu, cliquez sur « Utilisateurs » sous « Système ». Vous ne la voyez pas ? Cliquez sur « Système » pour l'afficher.
::figure set-users ring=nav-users
:::

:::step Connectez-vous avec le compte de سمير
Dans la ligne de سمير, cliquez sur le bouton avec une flèche qui entre dans un carré. Quand vous placez la souris dessus, son nom « Se connecter en tant que » apparaît. L'application ne demande pas de confirmation. La page d'accueil s'affiche, avec le message « Vous êtes maintenant connecté en tant que cet utilisateur. ». Le bouton n'apparaît pas dans votre ligne, ni pour un super administrateur, ni pour un compte désactivé.
::figure set-impersonate-button ring=users-impersonate-samir zoom=users-impersonate-samir
:::

:::step Cliquez sur « Revenir à mon compte »
En haut de chaque page, il y a une bande orange. Elle dit que vous naviguez en tant que سمير, et rappelle votre compte d'origine. Avant de revenir, ouvrez « Gestion des joueurs » dans le menu : vous ne voyez pas « Abonnements », car le rôle de سمير ne l'autorise pas. Cliquez sur « Revenir à mon compte » dans la bande. Vous revenez à la page « Utilisateurs », avec le message « Retour à votre propre compte. ».
::figure set-impersonating ring=impersonate-banner,impersonate-leave zoom=impersonate-leave
:::

:::step Ouvrez la page « Activité »
Sur téléphone, ouvrez d'abord le menu avec le bouton ☰. Dans le menu, cliquez sur « Activité » sous « Système ». Vous ne la voyez pas ? Cliquez sur « Système » pour l'afficher. La page s'ouvre sur « Ce mois ».
::figure set-activity-nav ring=nav-activity
:::

:::step Choisissez « Cette saison »
Choisissez « Cette saison » dans la liste « Période ». Sous la liste, il y a quatre cartes : « Total », « Semaines actives », « Moyenne par jour actif » et « Jour le plus chargé ».
::figure set-activity ring=activity-period
::figure set-activity ring=activity-tiles
:::

:::step Ouvrez la page de سمير
Sous les graphiques, un tableau montre chaque utilisateur et son activité par domaine. Cliquez sur le nom de سمير dans le tableau.
::figure set-activity-table ring=activity-row-3
:::

:::step Cliquez sur un nombre pour voir le détail
La page de سمير a une carte par domaine, avec un nombre pour chaque action. Cliquez sur le premier nombre. La liste des actions s'affiche en dessous, avec leurs dates. Elle porte le titre de l'action.
::figure set-activity-user-count ring=activity-count-first zoom=activity-count-first
:::

:::tip
Ce que vous faites connecté avec le compte d'un autre est enregistré au nom de cet utilisateur, avec « par » et votre nom à côté. Sur l'image, une des actions de نادية a été saisie de cette façon.
:::

::figure set-activity-entries ring=activity-entries
