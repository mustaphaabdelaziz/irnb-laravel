---
title: Comment corriger un paiement ou accorder une remise
summary: Corrigez le montant d'un paiement mal saisi, ou retirez un pourcentage ou un montant de l'abonnement d'un joueur.
module: players
pages: [players.show]
keywords: [corriger un paiement, modifier un paiement, erreur de montant, remise, réduction, pourcentage, net à payer, annuler un paiement]
related: [subscriptions/take-payment, subscriptions/previous-debt, subscriptions/receipts]
---

Vous vous êtes trompé dans le montant d'un paiement ? Corrigez-le depuis la page du joueur. Depuis la même page, vous accordez une remise sur un abonnement.

:::example
**أمين قاسمي** a payé 1500 DA, mais seulement 1000 a été saisi. Et **إسلام مرابط** reçoit une remise de 10 % sur « اشتراك سنوي », soit 3000 DA.
:::

:::step Cliquez sur le bouton crayon dans la ligne du paiement
Ouvrez la page de أمين depuis « Joueurs ». Sur téléphone, ouvrez d'abord le menu avec le bouton ☰. Descendez jusqu'à « Historique des transactions ». Pour descendre, tournez la molette de la souris vers vous, ou glissez deux doigts sur le pavé tactile. Sur téléphone, faites glisser l'écran vers le haut avec le doigt. Sur téléphone, faites glisser le tableau vers la gauche avec le doigt pour voir les boutons. Dans la ligne 1 000,00, cliquez sur le bouton crayon. Quand vous placez la souris dessus, son nom « Modifier » apparaît. La fenêtre « Modifier le paiement » s'ouvre.
::figure fix-history ring=player-history-edit zoom=player-history-edit
:::

:::step Tapez le bon montant
Dans la zone « Montant », effacez 1000 et tapez 1500. Laissez les autres zones telles quelles. En haut de la fenêtre : « Ceci annule le paiement d'origine et en enregistre un nouveau. ».
::figure fix-edit-modal ring=payment-edit-amount
:::

:::step Cliquez sur « Enregistrer »
Le message « Paiement mis à jour avec succès. » s'affiche. Dans la ligne « اشتراك سنوي », « Montant payé » passe à 1 500,00, et « Restant » à 1 500,00. Le nouveau paiement garde la date de l'ancien.
::figure fix-edit-modal ring=payment-edit-save zoom=payment-edit-save
:::

:::step Cliquez sur le bouton crayon dans la ligne de l'abonnement
Sur téléphone, ouvrez d'abord le menu avec le bouton ☰. Ouvrez la page de إسلام depuis « Joueurs ». Descendez jusqu'à « Abonnements » de la même façon. Sur téléphone, faites glisser le tableau vers la gauche avec le doigt pour voir les boutons. Dans la ligne « اشتراك سنوي », cliquez sur le bouton crayon. La fenêtre « Modifier l'abonnement » s'ouvre.
::figure fix-sub-card ring=player-sub-edit zoom=player-sub-edit
:::

:::step Choisissez la remise
Dans « Remise », choisissez « Pourcentage (%) ». Une zone « % » apparaît à côté : tapez 10. En dessous, une ligne montre « Net à payer » : 2 700,00, soit 3000 moins 300.
::figure fix-sub-modal ring=sub-discount zoom=sub-net-owed
:::

:::step Cliquez sur « Enregistrer »
Le message « Abonnement mis à jour avec succès. » s'affiche. Dans la ligne « اشتراك سنوي », sous « Remise », s'affichent le pourcentage 10% et le montant 300,00. Et « Restant » passe à 2 700,00.
::figure fix-sub-modal ring=sub-edit-save zoom=sub-edit-save
:::

Si un reçu a été imprimé pour أمين avant la correction, il s'affiche maintenant « Annulé » quand on le vérifie. Imprimez-lui un nouveau reçu.

On ne peut ni corriger ni annuler un paiement d'une saison clôturée. Un message s'affiche : « La saison … est clôturée. Rouvrez-la pour modifier ses transactions. ».

:::tip
Une remise en montant ? Choisissez « Montant fixe », et tapez le montant dans la zone « Montant ». Le pourcentage ne dépasse pas 100. Pour enlever la remise, choisissez « Aucune remise ».
:::

:::warning
Le bouton corbeille dans la ligne du paiement l'annule. Il ne compte plus ensuite, et il ne revient pas sur la page du joueur. Il n'en reste une trace que dans le journal d'activité. Pour corriger un montant, utilisez le bouton crayon.
:::
