---
title: Comment créer un rôle et choisir ses droits
summary: Créez un nouveau rôle avec des droits précis, et donnez à un utilisateur un droit en plus de son rôle.
module: superadmin
pages: [roles.index, users.edit]
keywords: [rôle, rôles, droits, permissions, dérogations avancées, entraîneur, comptable, magasinier, seulement les équipes qu'il entraîne]
related: [settings/create-user, settings/manage-users, settings/log-in-as-activity]
---

Un rôle est un ensemble de droits. Chaque utilisateur a un seul rôle. Vous le choisissez dans la zone « Rôle » de la page de modification de l'utilisateur. La page « Rôles » est réservée au super administrateur, personne d'autre ne la voit.

:::example
Vous voulez un nouveau rôle nommé « أمين المخزن » (magasinier). Il voit le matériel et l'inventaire, et peut y ajouter et modifier. Ensuite, l'entraîneur سمير بوعزة a besoin de voir « Abonnements » : vous lui donnez ce seul droit.
:::

:::step Ouvrez la page « Rôles »
Sur téléphone, ouvrez d'abord le menu avec le bouton ☰. Dans le menu, cliquez sur « Rôles » sous « Système ». Vous ne la voyez pas ? Cliquez sur « Système » pour l'afficher. La page montre une carte par rôle, avec le nombre de ses utilisateurs en dessous. Les rôles de base portent la marque « Système ».
::figure set-roles ring=nav-roles
:::

:::step Cliquez sur le bouton « Ajouter »
En haut de la page, cliquez sur le bouton avec le signe plus. Quand vous placez la souris dessus, son nom « Ajouter » apparaît. La fenêtre « Rôle » s'ouvre.
::figure set-roles ring=roles-add zoom=roles-add
:::

:::step Tapez le nom et choisissez les droits
Tapez « أمين المخزن » dans la zone « العربية » (arabe). Un nom dans une seule langue suffit ; les deux autres zones sont pour l'anglais et le français. Faites défiler la fenêtre jusqu'aux lignes « Équipement » et « Inventaire ». Utilisez la molette de la souris, ou deux doigts sur le pavé tactile. Sur téléphone, faites glisser l'écran vers le haut avec le doigt. Cochez « Voir », « Ajouter » et « Modifier » dans les deux lignes. Laissez « Supprimer » vide.
::figure set-role-name ring=role-name-ar
::figure set-role-rows ring=perm-row-equipment,perm-row-inventory
:::

:::step Cliquez sur « Enregistrer les modifications »
Sous le tableau, il y a la case « Seulement les équipes qu'il entraîne », pour les entraîneurs : elle limite leurs modifications à leurs équipes. Laissez-la vide ici. Le message « Rôle créé avec succès. » s'affiche, et la carte « أمين المخزن » s'ajoute à la page.
::figure set-role-save ring=role-own-teams,role-save zoom=role-save
:::

:::step Ouvrez la page de modification de سمير
Dans la page « Utilisateurs », cliquez sur le bouton crayon dans la ligne de سمير. Quand vous placez la souris dessus, son nom « Modifier » apparaît. La page de modification de l'utilisateur s'ouvre.
::figure set-user-edit-button ring=users-edit-samir zoom=users-edit-samir
:::

:::step Cliquez sur « Dérogations avancées »
Vous le trouvez sous la zone « Rôle ». Le même tableau des droits s'affiche, et le lien s'appelle maintenant « Masquer avancé ». Ce lien est réservé au super administrateur. Il ajoute seulement des droits, il n'en retire aucun au rôle.
::figure set-user-advanced ring=ue-overrides-toggle
:::

:::step Donnez-lui « Voir » dans « Abonnements » et enregistrez
Cochez « Voir » dans la ligne « Abonnements ». Les cases cochées sont données en plus du rôle. Puis cliquez sur « Enregistrer les modifications » en bas de la page : le message « Utilisateur mis à jour avec succès. » s'affiche.
::figure set-user-overrides ring=perm-row-subscriptions
::figure set-user-overrides-save ring=ue-save zoom=ue-save
:::

:::tip
Un nouveau rôle commence sans aucun droit. De même pour les parties qu'ajoutent les nouvelles versions de l'application : elles restent vides dans vos rôles jusqu'à ce que vous les choisissiez.
:::

:::warning
La suppression d'un rôle est définitive. Le bouton corbeille de la carte du rôle demande « Êtes-vous sûr de vouloir supprimer? ». Les utilisateurs du rôle supprimé restent sans rôle. Les rôles qui portent la marque « Système » ne se suppriment pas.
:::
