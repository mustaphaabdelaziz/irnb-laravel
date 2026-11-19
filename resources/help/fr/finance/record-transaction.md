---
title: Comment enregistrer une recette ou une dépense
summary: Enregistrez un montant entré dans le club ou sorti du club : l'intitulé, la catégorie, le montant et la caisse, puis enregistrez.
pages: [transactions.index, transactions.create, transactions.show]
keywords: [transaction, dépense, recette, revenu, dépenses, revenus, ajouter une transaction, enregistrer une dépense, caisse, catégorie, achat]
related: [finance/cancel-transaction, subscriptions/take-payment, subscriptions/receipts]
---

Enregistrez dans « Transactions » chaque montant entré dans le club ou sorti du club : quoi, combien, et de quelle caisse.

:::example
L'entraîneur achète des filets de but. Titre « شراء شباك المرمى » (achat de filets de but), catégorie « Équipement », montant 15000 DA. Il paie en espèces depuis le coffre du football.
:::

:::step Ouvrez la page « Transactions »
Sur un téléphone, ouvrez d'abord le menu avec le bouton ☰. Dans le menu, cliquez sur « Transactions » sous « Finances ». Vous ne la voyez pas ? Cliquez sur « Finances » pour l'afficher.
::figure fin-tx-list ring=nav-transactions
:::

:::step Cliquez sur le bouton « Ajouter une transaction »
En haut de la page, cliquez sur le bouton avec le signe +. Passez la souris dessus : son nom « Ajouter une transaction » s'affiche. La page « Ajouter une transaction » s'ouvre. Vous ne voyez pas le bouton ? Il vous faut la permission « Ajouter » sur « Transactions ».
::figure fin-tx-list ring=tx-add zoom=tx-add
:::

:::step Choisissez « Dépense » dans « Type »
« Type » commence sur « Revenus ». Choisissez « Dépense » avant tout autre champ. « Catégorie » ne montre alors que les catégories de dépenses.
::figure fin-tx-form ring=txf-type
:::

:::step Tapez le titre, choisissez la catégorie et tapez le montant
Dans « Titre », tapez : شراء شباك المرمى. Dans « Catégorie », choisissez « Équipement ». Dans « Montant », tapez 15000. Le titre est obligatoire, 150 caractères au plus.
::figure fin-tx-form ring=txf-title,txf-category,txf-amount
:::

:::step Choisissez la caisse et le mode de paiement
Dans « Caisse qui paie la dépense », choisissez « Coffre — Football ». Laissez « Mode de paiement » sur « Espèces ». « Date » reste sur aujourd'hui, et « Statut » reste « Payé ».
::figure fin-tx-form ring=txf-caisse,txf-method
:::

:::step Cliquez sur « Enregistrer »
Le bouton est en bas de la page. Pour descendre jusqu'à lui, tournez la molette de la souris vers vous, ou glissez deux doigts sur le pavé tactile. Sur un téléphone, faites glisser l'écran vers le haut avec le doigt. Cliquez sur « Enregistrer ». La page de la transaction s'ouvre, et le message « Transaction créée avec succès. » s'affiche. Le montant −15 000,00 est en rouge, car c'est une dépense.
::figure fin-tx-form-bottom ring=txf-save zoom=txf-save
::figure fin-tx-saved ring=tx-show-amount
:::

En haut de la page de la transaction, il y a deux boutons d'impression : celui avec une imprimante pour le ticket, et celui avec une feuille pour le reçu A4. Passez la souris sur un bouton pour voir son nom. Dans « Transactions », la transaction apparaît sur une nouvelle ligne en haut de la liste.

La date tombe dans une saison clôturée ? L'application refuse d'enregistrer et écrit : « La saison … est clôturée. Rouvrez-la pour modifier ses transactions. ». Changez la date, ou demandez au responsable de rouvrir la saison.

:::tip
L'argent vient d'un joueur ? Choisissez-le dans le champ « Joueur », sous « Statut ». La caisse peut alors passer à celle du joueur, s'il a une caisse à lui. Mais n'enregistrez pas son abonnement ici : encaissez le paiement depuis la page du joueur. Son abonnement est alors réglé et son reçu imprimé.
:::
