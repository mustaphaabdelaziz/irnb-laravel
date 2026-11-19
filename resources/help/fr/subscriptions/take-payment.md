---
title: Comment enregistrer un paiement
summary: Enregistrez ce que le joueur a payé, pour un abonnement ou pour plusieurs abonnements ensemble sur un seul reçu.
module: players
pages: [players.show]
keywords: [paiement, payer, ajouter un paiement, règlement, encaisser, reçu, caisse, abonnement, dette]
related: [players/find-player, subscriptions/fix-payment, subscriptions/previous-debt, subscriptions/receipts]
---

Enregistrez le paiement depuis la page du joueur : pour un abonnement, ou pour plusieurs abonnements ensemble sur un seul reçu.

:::example
**زكرياء عمراني** doit « رحلة صيفية » : 1500 DA. Aujourd'hui, il la paie en espèces, avec « اشتراك سنوي » : 3000 DA. Le total fait 4500 DA.
:::

:::step Ouvrez la page de زكرياء
Sur téléphone, ouvrez d'abord le menu avec le bouton ☰. Dans le menu, cliquez sur « Joueurs », puis sur le nom de زكرياء. Pour le trouver vite, tapez son nom dans la zone de recherche. En haut de sa page, sous « Dettes impayées » : 1 500,00.
::figure payment-button ring=player-debt
:::

:::step Cliquez sur le bouton « Ajouter un paiement »
Sous le montant, cliquez sur le bouton avec le signe $ dans un cercle. Quand vous placez la souris dessus, son nom « Ajouter un paiement » apparaît. La fenêtre « Ajouter un paiement » s'ouvre.
::figure payment-button ring=player-add-payment zoom=player-add-payment
:::

:::step Cochez les deux abonnements dans l'ordre
Laissez « Type de paiement » sur « Abonnement ». Sous « Abonnements à payer », cochez d'abord « اشتراك سنوي ». #1 apparaît à côté. Puis cochez « رحلة صيفية » : #2 apparaît. En dessous s'affiche « Total » : 4 500,00. « اشتراك سنوي » est dans la liste même s'il n'est pas encore assigné à زكرياء : il lui est ajouté à l'enregistrement.
::figure payment-modal ring=payment-subscriptions
:::

:::step Vérifiez « Montant » et « Mode de paiement »
L'application écrit seule le total dans « Montant » : 4500. S'il a payé moins, tapez ce qu'il a payé : le n° 1 est réglé entièrement d'abord, puis le n° 2. Dans « Mode de paiement », laissez « Espèces », ou choisissez « CCP » ou « Baridimob ». Cochez avant de taper le montant : chaque case cochée réécrit « Montant » et la caisse.
::figure payment-modal ring=payment-amount,payment-method
:::

:::step Vérifiez la caisse
Dans « Caisse qui reçoit la recette », l'application a choisi « صندوق أقل من 14 سنة — Football ». Si vous avez mis l'argent dans une autre caisse, choisissez-la ici.
::figure payment-modal ring=payment-register
:::

:::step Cliquez sur « Enregistrer »
Le bouton est en bas de la fenêtre. Pour y descendre, tournez la molette de la souris vers vous, ou glissez deux doigts sur le pavé tactile. Sur téléphone, faites glisser la fenêtre vers le haut avec le doigt. Cliquez sur « Enregistrer ». Le message « Paiement enregistré avec succès. » s'affiche, et « Dettes impayées » passe à 0,00.
::figure payment-modal-bottom ring=payment-save zoom=payment-save
:::

Le paiement prend la date du jour. Dans « Historique des transactions », deux lignes apparaissent, une par abonnement. Le bouton imprimante de l'une ou l'autre imprime un seul reçu avec les deux abonnements.

:::tip
Le joueur a payé plus que le total ? Tapez tout le montant. Une ligne orange s'affiche en dessous : ce qui dépasse est enregistré comme don du joueur.
:::

:::warning
Ne cochez « Exempté » que si le club a dispensé le joueur. La zone « Montant » disparaît alors, aucun paiement n'est enregistré, et il ne doit plus rien sur les abonnements cochés.
:::
