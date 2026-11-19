---
title: Comment désactiver un compte, le supprimer ou réinitialiser son mot de passe
summary: Désactivez le compte de qui a quitté le club, donnez un nouveau mot de passe à qui l'a oublié, et supprimez le compte si besoin.
module: users
pages: [users.index, users.edit]
keywords: [désactiver un compte, compte désactivé, activer un compte, supprimer un utilisateur, mot de passe oublié, réinitialiser le mot de passe, inactif, en attente, approuver, utilisateurs]
related: [settings/create-user, getting-started/login, settings/roles-rights, settings/log-in-as-activity]
---

Le tableau montre tous les comptes, 25 par page. Depuis la ligne d'un compte, vous le désactivez, vous réinitialisez son mot de passe ou vous le supprimez. Un ancien compte qui attend l'approbation porte « En attente » et un bouton « Approuver ».

:::example
L'entraîneuse ليلى بن صالح a quitté le club, et son compte est désactivé. La comptable نادية خليفي a oublié son mot de passe : vous lui en donnez un nouveau.
:::

:::step Ouvrez la page « Utilisateurs »
Sur téléphone, ouvrez d'abord le menu avec le bouton ☰. Dans le menu, cliquez sur « Utilisateurs » sous « Système ». Vous ne la voyez pas ? Cliquez sur « Système » pour l'afficher. Il vous faut le droit « Voir » dans « Utilisateurs ».
::figure set-users ring=nav-users
:::

:::step Cherchez le compte
Tapez le nom ou le nom d'utilisateur dans « Rechercher ». Ou choisissez « Inactif » dans « Activité » pour ne voir que les comptes désactivés.
::figure set-users ring=users-search,users-filter-activity
:::

:::step Désactivez ou activez le compte
Dans la ligne du compte, cliquez sur le bouton avec un cercle barré. Quand vous placez la souris dessus, son nom « Désactiver » apparaît. Le compte change tout de suite, sans question. Le message « Utilisateur désactivé. » s'affiche. Son titulaire est déconnecté de l'application. S'il essaie de se connecter, il voit le message « Ce compte est désactivé. Contactez le responsable du club. ». Pour le réactiver, cliquez sur le même bouton, qui s'appelle maintenant « Activer ». Il vous faut le droit « Modifier » dans « Utilisateurs ». Un responsable ordinaire ne peut pas désactiver un super administrateur.
::figure set-users-rows ring=users-toggle-samir zoom=users-toggle-samir
:::

:::step Cliquez sur le bouton clé de نادية
Dans la ligne de نادية, cliquez sur le bouton clé. Quand vous placez la souris dessus, son nom « Réinitialiser le mot de passe » apparaît. Une fenêtre du même nom s'ouvre. Ce bouton est réservé au super administrateur, et il n'apparaît pas dans votre propre ligne. Il vous faut aussi le droit « Modifier » dans « Utilisateurs ».
::figure set-users-rows ring=users-reset-nadia zoom=users-reset-nadia
:::

:::step Tapez deux fois le nouveau mot de passe
Tapez-le dans « Nouveau mot de passe », puis retapez-le dans « Confirmer le mot de passe ». Le mot de passe compte au moins 8 caractères.
::figure set-user-reset-open ring=users-reset-new,users-reset-confirm
:::

:::step Cliquez sur « Enregistrer »
Le message « Mot de passe réinitialisé avec succès. » s'affiche. La fenêtre déconnecte نادية des sessions « Se souvenir de moi ». Elle se connecte tout de suite avec le nouveau mot de passe. Donnez-le-lui vous-même.
::figure set-user-reset ring=users-reset-save zoom=users-reset-save
:::

:::step Supprimez le compte
Dans la ligne du compte, cliquez sur le bouton rouge avec une corbeille. Quand vous placez la souris dessus, son nom « Supprimer » apparaît. La fenêtre « Confirmer l'action » s'ouvre, avec « Êtes-vous sûr ? ». Cliquez sur « Supprimer » dans la fenêtre pour confirmer. Il vous faut le droit « Supprimer » dans « Utilisateurs ».
::figure set-users-rows ring=users-delete-layla zoom=users-delete-layla
:::

:::warning
La suppression est définitive. Préférez « Désactiver » pour qui a quitté le club : tout l'historique de la personne est gardé. Vous ne pouvez pas supprimer votre propre compte, ni le dernier super administrateur. Et un responsable ordinaire ne peut pas supprimer un super administrateur.
:::
