---
title: Comment supprimer une ou plusieurs transactions
summary: Supprimez une transaction saisie deux fois ou par erreur, depuis sa ligne ou en cochant plusieurs lignes à la fois.
pages: [transactions.index, transactions.show]
keywords: [supprimer une transaction, annuler une transaction, transaction en double, doublon, supprimer la sélection, supprimer plusieurs transactions, erreur de saisie, archiver, corbeille]
related: [finance/record-transaction, finance/find-export-import, subscriptions/fix-payment]
---

Vous avez saisi une transaction deux fois ou par erreur ? Supprimez-la depuis la liste « Transactions ». Vous n'avez rien à recalculer.

:::example
La facture d'électricité a été saisie deux fois, avec le même montant. Nous supprimons la deuxième ligne, le doublon, et gardons la première.
:::

:::step Ouvrez la page « Transactions »
Sur un téléphone, ouvrez d'abord le menu avec le bouton ☰. Dans le menu, cliquez sur « Transactions » sous « Finances ». Vous ne la voyez pas ? Cliquez sur « Finances » pour l'afficher.
::figure fin-tx-list ring=nav-transactions
:::

:::step Tapez « كهرباء » dans la zone de recherche
Tapez le mot « كهرباء » (électricité) dans la zone « Rechercher ». Il reste deux lignes « فاتورة الكهرباء » (facture d'électricité), avec le même montant et la même caisse. Seule la date les distingue. La ligne du haut est la plus récente : c'est le doublon.
::figure fin-tx-search ring=tx-search,tx-row-first
:::

:::step Cliquez sur la corbeille de la ligne en double
Au bout de la ligne, cliquez sur le bouton rouge avec une corbeille. Passez la souris dessus : son nom « Supprimer » s'affiche. Sur un téléphone, le bouton est en bas de la carte de la transaction. La fenêtre « Supprimer cette transaction ? » s'ouvre. Vous ne voyez pas le bouton ? Il vous faut la permission « Supprimer » sur « Transactions ».
::figure fin-tx-duplicate ring=tx-delete-first zoom=tx-delete-first
:::

:::step Lisez le message, puis cliquez sur « Supprimer »
La fenêtre dit : « Elle ne compte plus dans les totaux ni dans le solde de la caisse, et reste dans l'historique d'activité. ». Cliquez sur le bouton rouge « Supprimer ». Le message « Transaction archivée avec succès. » s'affiche. La transaction disparaît, et la liste revient complète, sans recherche. Les chiffres du haut changent.
::figure fin-tx-delete-confirm ring=confirm-ok zoom=confirm-ok
:::

:::step Cochez plusieurs transactions
Vous voulez supprimer plusieurs transactions à la fois ? Cliquez sur la case au début de chaque ligne pour la cocher. Une barre colorée apparaît au-dessus de la liste. Elle montre le nombre coché, comme « 1 sélectionné(s) ». La case dans l'en-tête de la colonne coche toutes les lignes de la page. Le bouton × de la barre annule la sélection.
::figure fin-tx-bulk ring=tx-select-first,tx-bulk-bar
:::

:::step Cliquez sur « Supprimer la sélection »
Dans la barre, cliquez sur le bouton avec une corbeille. Passez la souris dessus : son nom « Supprimer la sélection » s'affiche. La fenêtre « Supprimer les opérations sélectionnées ? » s'ouvre.
::figure fin-tx-bulk ring=tx-bulk-delete zoom=tx-bulk-delete
:::

:::step Cliquez sur « Supprimer » pour confirmer
Cliquez sur le bouton rouge « Supprimer » dans la fenêtre. Un message comme « 1 transaction(s) supprimée(s). » s'affiche.
::figure fin-tx-bulk-confirm ring=confirm-ok zoom=confirm-ok
:::

Le maximum est de 500 transactions à la fois. Les transactions d'une saison clôturée ne sont pas supprimées.

S'il y en a dans la sélection, l'application les ignore. Elle écrit par exemple : « 3 transaction(s) supprimée(s), 2 ignorée(s) (exercice clôturé). »

Une seule transaction d'une saison clôturée ? L'application refuse de la supprimer. Elle écrit : « La saison … est clôturée. Rouvrez-la pour modifier ses transactions. ».

:::tip
La transaction est le paiement d'un abonnement ? La supprimer ici recalcule bien la dette du joueur. Mais le plus simple est de la corriger depuis la page du joueur, comme dans « Comment corriger un paiement ou accorder une remise ».
:::

:::warning
La suppression ne peut pas être annulée dans l'application. La transaction sort des totaux et du solde de la caisse, et aucun bouton ne la fait revenir. Il n'en reste une trace que dans l'« historique d'activité ». Si vous n'êtes pas sûr, corrigez-la avec la modification plutôt que de la supprimer.
:::
