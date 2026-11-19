---
title: Comment réceptionner du stock d'équipement
summary: Faites entrer en stock les unités achetées ou données, et enregistrez leur prix comme dépense si besoin.
pages: [equipment.catalogs.show]
keywords: [réceptionner du stock, entrée de matériel, achat de matériel, dépense matériel, don, inventaire initial, ajustement, provenance, emplacement, numéro de série, désignation, quantité]
related: [equipment/add-catalog, equipment/equipment-lists, finance/cancel-transaction]
---

Le matériel entre en stock depuis la page de son équipement, avec le bouton « Réceptionner du stock ». Si le club l'a acheté, le logiciel enregistre en même temps son prix comme dépense.

:::example
Le club a acheté 10 plots d'entraînement, à 150 le plot. Il a payé avec la « Caisse du club », et a rangé les plots dans « المخزن الرئيسي » (magasin principal).
:::

:::step Ouvrez la page « Équipements »
Sur téléphone, ouvrez d'abord le menu avec le bouton ☰. Dans le menu, cliquez sur « Équipements » sous « Équipement ». Vous ne le voyez pas ? Cliquez sur « Équipement » pour l'afficher.
::figure eq-receive-stock-list ring=nav-equipment
:::

:::step Cliquez sur « قمع تدريب »
Dans le tableau, cliquez sur le nom de l'équipement « قمع تدريب » (plot d'entraînement). Sa page s'ouvre, avec le tableau « Articles » des unités en stock.
::figure eq-receive-stock-list ring=eq-catalog-link-2 zoom=eq-catalog-link-2
:::

:::step Cliquez sur le bouton « Réceptionner du stock »
En haut de la page, cliquez sur le bouton marqué d'un +. Quand vous posez la souris dessus, son nom « Réceptionner du stock » apparaît. La fenêtre « Réceptionner du stock — قمع تدريب » s'ouvre. Vous ne voyez pas le bouton ? Il vous faut le droit « Modifier » dans « Équipement ».
::figure eq-receive-stock-button ring=eq-receive zoom=eq-receive
:::

:::step Remplissez la quantité, le prix, la date et l'emplacement
Dans « Quantité », tapez : 10. Le « Prix unitaire » vient du prix de l'équipement : 150. Changez-le si vous avez payé un autre prix. Mettez dans « Date » le jour de l'achat. Laissez l'« État » sur « Neuf ». Dans « Emplacement », choisissez « المخزن الرئيسي ». Sous « Sections », cliquez sur une section seulement si les unités sont à elle seule. Les plots sont pour toutes les sections : ne cliquez sur aucune section.
::figure eq-receive-stock-fields ring=eq-rcv-quantity,eq-rcv-price,eq-rcv-date,eq-rcv-condition,eq-rcv-location,eq-rcv-branches
:::

:::step Cochez « Enregistrer comme dépense » et choisissez la caisse
Cochez la case, car le club a payé les plots. Le champ « Caisse qui paie la dépense » apparaît : choisissez « Caisse du club ». La dépense est le prix unitaire × la quantité : 1500. Elle est enregistrée comme payée à la date d'achat, et déduite de la caisse. Le « Prix unitaire » est vide ou à 0 ? L'erreur « Saisissez le prix unitaire pour enregistrer la dépense. » s'affiche.
::figure eq-receive-stock-expense ring=eq-rcv-expense,eq-rcv-caisse
:::

:::step Le club n'a pas payé ? Choisissez la « Provenance »
Cette étape remplace l'étape précédente, pour le matériel qui n'a pas été acheté. Laissez « Enregistrer comme dépense » décoché. Dans « Provenance », choisissez « Don », « Inventaire initial » ou « Ajustement ». « Inventaire initial » sert au matériel que le club avait avant le logiciel. Et « Ajustement » sert à corriger le nombre. Le logiciel n'enregistre pas tant que vous n'avez pas choisi de provenance.
::figure eq-receive-stock-source ring=eq-rcv-expense,eq-rcv-source
:::

:::step Cliquez sur « Enregistrer »
Le message « Stock reçu avec succès. » s'affiche. Une nouvelle ligne de 10 unités apparaît dans le tableau « Articles ». Et le nombre sous « Disponible / Total » augmente.
::figure eq-receive-stock-expense ring=eq-rcv-save zoom=eq-rcv-save
:::

:::tip
Certains équipements ont « Suivre chaque unité individuellement », comme « ساعة توقيت » (chronomètre). Chaque unité y prend une ligne et un numéro de série. « ID / N° de série » montre le numéro de la première unité. Dans « Désignation », tapez : ساعة توقيت, et dans « Quantité » : 2. Le logiciel continue après le plus grand numéro existant : « ساعة توقيت 4 » et « ساعة توقيت 5 ». Vous réceptionnez jusqu'à 200 unités à la fois.
:::

::figure eq-receive-stock-serial ring=eq-rcv-serial,eq-rcv-designation,eq-rcv-quantity

:::warning
La dépense enregistrée ici est une vraie dépense dans « Transactions », déduite de la caisse. Si vous supprimez ensuite les unités, la dépense reste. Pour l'annuler, supprimez la transaction elle-même dans « Transactions ».
:::
