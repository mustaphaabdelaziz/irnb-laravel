---
title: Comment savoir qui a enregistré une transaction et qui l'a modifiée
summary: Ouvrez la page de la transaction pour voir qui l'a enregistrée et quand, puis chaque modification, annulation ou impression.
module: transactions
pages: [transactions.show, transactions.index]
keywords: [qui a enregistré, historique de la transaction, suivi, modifier une transaction, date et heure, qui a modifié, qui a annulé, correction de paiement, contrôle]
related: [finance/record-transaction, finance/cancel-transaction, subscriptions/receipts]
---

Chaque transaction garde son histoire : qui l'a enregistrée, quand, et ce qui a changé ensuite.

:::example
**أحمد بن علي** a payé « اشتراك سنوي » : 3000 DA. Nous ouvrons la page de son paiement pour voir qui l'a enregistré.
:::

:::step Ouvrez la page « Transactions »
Sur téléphone, ouvrez d'abord le menu avec le bouton ☰. Dans le menu, cliquez sur « Transactions » sous « Finances ». Vous ne la voyez pas ? Cliquez sur « Finances » pour l'afficher. Tapez « أحمد » dans la zone de recherche.
::figure receipt-tx-index ring=nav-transactions,tx-search,tx-row-first
:::

:::step Cliquez sur le titre de la transaction
Cliquez sur le titre du paiement de أحمد. La page de la transaction s'ouvre.
::figure fin-tx-history ring=tx-trace
:::

:::step Lisez la ligne « Enregistrée par : »
La ligne est en bas de la première carte. Elle donne le nom de l'utilisateur, puis la date et l'heure. Si la transaction a été modifiée, « Dernière modification : » s'affiche à côté.
::figure fin-tx-history ring=tx-trace zoom=tx-trace
:::

:::step Lisez « Historique de la transaction »
Descendez jusqu'à la carte « Historique de la transaction ». Chaque ligne est un événement : enregistrement, modification, annulation ou impression d'un reçu. Chaque ligne porte le nom, la date et l'heure. Une modification affiche l'ancienne valeur barrée, puis la nouvelle.
::figure fin-tx-history ring=tx-prints
:::

La transaction a été modifiée avant l'ajout de cet historique ? La ligne « Modifiée » s'affiche sans détail.

Le paiement d'un joueur a été corrigé ? L'ancien paiement porte un lien vers le nouveau, et le nouveau vers l'ancien.

:::tip
Vous voyez « en tant que » après un nom ? Un administrateur s'est connecté avec le compte de cet utilisateur. Le premier nom est celui qui a fait l'action.
:::
