---
title: Comment ajouter une ancienne dette
summary: Enregistrez sur le joueur une dette d'avant l'utilisation de l'application, puis encaissez son paiement.
module: players
pages: [players.show]
keywords: [dette antérieure, ancienne dette, dette reportée, saison précédente, avant l'application, dette, ajouter une dette antérieure, arriéré]
related: [subscriptions/take-payment, subscriptions/fix-payment]
---

Avant l'application, certains joueurs devaient de l'argent au club. Enregistrez cette dette sur la page du joueur : elle compte alors dans « Dettes impayées ».

:::example
**أيوب شريف** doit encore 2000 DA de la saison **2023/2024**. Nous enregistrons cette dette, puis nous encaissons son paiement.
:::

:::step Cliquez sur le bouton « Ajouter une dette antérieure »
Sur téléphone, ouvrez d'abord le menu avec le bouton ☰. Ouvrez la page de أيوب, et descendez jusqu'à la carte « Abonnements ». Pour descendre, tournez la molette de la souris vers vous, ou glissez deux doigts sur le pavé tactile. Sur téléphone, faites glisser l'écran vers le haut avec le doigt. En haut de la carte, cliquez sur le bouton blanc avec le signe +. Quand vous placez la souris dessus, son nom « Ajouter une dette antérieure » apparaît. La fenêtre « Ajouter une dette antérieure » s'ouvre.
::figure debt-button ring=player-add-previous-debt zoom=player-add-previous-debt
:::

:::step Tapez la dette
Tapez « دين موسم 2023/2024 » (dette saison 2023/2024) dans « Libellé », et 2000 dans « Montant dû ». Dans « Année », effacez l'année écrite et tapez 2024. Tapez seulement ce qu'il doit encore, pas ce qu'il a déjà payé.
::figure debt-modal ring=debt-label,debt-amount,debt-year
:::

:::step Cliquez sur « Enregistrer »
Le message « Dette antérieure ajoutée. » s'affiche. Dans « Abonnements », la ligne « دين موسم 2023/2024 » apparaît, avec « Dette antérieure » à côté. « Dettes impayées » passe à 2 000,00.
::figure debt-modal ring=debt-save zoom=debt-save
::figure debt-card ring=player-subscriptions-card
:::

:::step Cliquez sur le bouton « Ajouter un paiement »
Le jour où أيوب paie, ouvrez sa page. Sur téléphone, ouvrez d'abord le menu avec le bouton ☰. En haut de la page, sous « Dettes impayées », cliquez sur le bouton avec le signe $ dans un cercle. Quand vous placez la souris dessus, son nom « Ajouter un paiement » apparaît. La fenêtre « Ajouter un paiement » s'ouvre.
::figure debt-pay-button ring=player-add-payment zoom=player-add-payment
:::

:::step Cochez la dette
Laissez « Type de paiement » sur « Abonnement ». Sous « Abonnements à payer », cochez « دين موسم 2023/2024 (2024) ». L'application écrit 2000 dans « Montant ». Vérifiez « Caisse qui reçoit la recette ».
::figure debt-pay-modal ring=payment-subscriptions
:::

:::step Cliquez sur « Enregistrer »
Le bouton est en bas de la fenêtre. Vous ne le voyez pas ? Descendez dans la fenêtre de la même façon. Cliquez sur « Enregistrer ». Le message « Paiement enregistré avec succès. » s'affiche. Dans la ligne de la dette, « Restant » passe à 0,00 et le statut à « Payé ».
::figure debt-pay-modal ring=payment-save zoom=payment-save
:::

:::tip
Vous vous êtes trompé dans la dette ? Cliquez sur le bouton crayon de sa ligne. La fenêtre « Modifier la dette antérieure » s'ouvre : corrigez-y le libellé, le montant ou l'année.
:::

:::tip
Plus rapide : choisissez « Paiement de dette » dans « Type de paiement », et tapez le montant payé. L'application règle d'abord la dette la plus ancienne, puis la suivante. Ce qui dépasse le total des dettes est enregistré comme don.
:::
