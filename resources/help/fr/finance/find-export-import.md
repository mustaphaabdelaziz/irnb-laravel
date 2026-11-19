---
title: Comment rechercher, exporter et importer des transactions
summary: Cherchez une transaction et filtrez la liste, puis exportez-la vers Excel, ou importez un ancien registre depuis un modèle prêt.
pages: [transactions.index]
keywords: [rechercher, transactions, exporter, importer, Excel, CSV, modèle, filtre, saison, effacer les filtres, ancien registre, électricité]
related: [finance/record-transaction]
---

La liste « Transactions » recherche, filtre et exporte ce que vous voyez. Elle importe aussi des transactions depuis un fichier Excel ou CSV.

:::example
Nous cherchons les factures d'électricité de la saison actuelle et les exportons vers Excel. Puis nous importons le registre de l'ancienne année à partir du modèle.
:::

:::step Ouvrez la page « Transactions »
Sur un téléphone, ouvrez d'abord le menu avec le bouton ☰. Dans le menu, cliquez sur « Transactions » sous « Finances ». Vous ne la voyez pas ? Cliquez sur « Finances » pour l'afficher.
::figure fin-tx-list ring=nav-transactions
:::

:::step Tapez « كهرباء » dans la zone de recherche
Sous les statistiques, tapez le mot « كهرباء » (électricité) dans la zone « Rechercher ». La liste change toute seule après un instant. L'application cherche dans l'intitulé, la description, le nom de la catégorie et le nom du joueur. Il reste deux lignes, « فاتورة الكهرباء » (facture d'électricité), et les chiffres du haut changent avec elles.
::figure fin-tx-search ring=tx-search,tx-row-first
:::

:::step Réduisez la liste par type, caisse et saison
La liste montre seulement la saison actuelle. Pour une autre saison, ouvrez la liste des saisons et choisissez-la, ou choisissez « Toutes les saisons ». Les listes « Type » et « Toutes les caisses » réduisent encore la liste.
::figure fin-tx-list ring=tx-filter-type,tx-filter-caisse,tx-filter-season
:::

:::step Cliquez sur le bouton « Plus d'actions »
En haut de la page, cliquez sur le bouton avec trois points. Passez la souris dessus : son nom « Plus d'actions » s'affiche. Un menu s'ouvre.
::figure fin-tx-list ring=tx-more zoom=tx-more
:::

:::step Choisissez « Excel (.xlsx) » sous « Exporter »
Un fichier se télécharge avec tout ce que la liste montre maintenant, pas seulement la première page. Il tient compte de la recherche, de la saison et des dates. « CSV » donne un fichier texte.
::figure fin-tx-menu ring=tx-export
:::

:::step Téléchargez le « Modèle » et remplissez-le
Pour importer, choisissez « Excel (.xlsx) » sous « Modèle ». Remplissez le fichier, une ligne par transaction. Laissez les titres tels quels : Date, Intitulé, Type, Catégorie, Montant, Statut, Mode de paiement, Caisse, Description. Écrivez la date ainsi : 2026-01-15 ou 15/01/2026.
::figure fin-tx-menu ring=tx-template
:::

:::step Cliquez sur « Importer » et choisissez le fichier
Cliquez sur « Importer » dans le même menu. Une fenêtre de choix de fichier s'ouvre : choisissez votre fichier. Le message « … transactions importées avec succès. » s'affiche ; à la place des points, le nombre de transactions importées. L'import demande la permission « Ajouter » sur « Transactions ».
::figure fin-tx-menu ring=tx-import
:::

Une ligne indique une caisse qui n'existe pas ? Elle est enregistrée dans la caisse par défaut. Une ligne datée dans une saison clôturée n'est pas importée. Sous le message de réussite, un deuxième message s'affiche. Il montre seulement les 10 premiers problèmes, un problème par ligne. Par exemple : « Ligne 3 : caisse « … » introuvable, la caisse par défaut a été utilisée. ». Une ligne au montant vide ou à zéro est ignorée, et le message n'en parle pas.

Quand vous choisissez un filtre, un bouton rouge avec un entonnoir apparaît. Passez la souris dessus : son nom « Effacer les filtres » s'affiche. Il efface la recherche et les filtres, et ramène la liste à la saison actuelle.

:::warning
L'import ne vérifie pas les doublons. Importer deux fois le même fichier enregistre chaque ligne deux fois. Importez chaque fichier une seule fois.
:::
