---
title: Comment enregistrer le retour du matériel
summary: Enregistrez le retour du matériel prêté ou affecté, en totalité ou en partie, avec son état au retour.
pages: [equipment.out, equipment.catalogs.show]
keywords: [retour, rendre le matériel, matériel sorti, prêt, affectation, en retard, retour partiel, état du matériel, date de retour, date d'échéance]
related: [equipment/lend-assign, equipment/damage-repair-lost]
---

La page « Matériel sorti » réunit tout ce qui est sorti du stock et n'est pas encore revenu. C'est là que vous enregistrez le retour.

:::example
Le club a prêté à أحمد بن علي deux ballons « كرة قدم مقاس 5 » (ballon de football taille 5). La date de retour est passée, et « En retard » s'est affiché. Aujourd'hui, il les a rendus en bon état.
:::

:::step Ouvrez la page « Matériel sorti »
Sur téléphone, ouvrez d'abord le menu avec le bouton ☰. Dans le menu, cliquez sur « Matériel sorti » sous « Équipement ». Vous ne le voyez pas ? Cliquez sur « Équipement » pour l'afficher. La page s'ouvre sur « Prêts ». Le matériel affecté se trouve dans « Affectations ».
::figure eq-return-out ring=nav-equipment-out
:::

:::step Cherchez la personne qui a le matériel
Dans « Rechercher », tapez : بن علي. Le logiciel cherche dans les noms des personnes qui ont le matériel. Seuls les prêts de أحمد بن علي restent dans le tableau. Dans la colonne « Date d'échéance », à côté de la date, s'affiche « En retard ». Il apparaît quand cette date est arrivée et que le matériel n'est pas revenu.
::figure eq-return-search ring=eq-out-search zoom=eq-out-overdue-3
:::

:::step Cliquez sur le bouton « Retourner »
En bout de ligne, cliquez sur le bouton marqué d'un ‹. Quand vous posez la souris dessus, son nom « Retourner » apparaît. La fenêtre « Retourner — كرة قدم مقاس 5 » s'ouvre. Vous ne voyez pas le bouton ? Il vous faut le droit « Modifier » dans « Équipement ».
::figure eq-return-search ring=eq-out-return-3 zoom=eq-out-return-3
:::

:::step Vérifiez la « Quantité » et la « Date de retour »
Dans « Quantité », le logiciel met tout ce qui n'est pas encore revenu : 2. Le joueur en a rendu moins ? Tapez seulement le nombre revenu. Le prêt reste ouvert pour le reste, jusqu'au retour complet. La « Quantité » n'apparaît que si plus d'une unité est sortie. La « Date de retour » est aujourd'hui : changez-la si le matériel est revenu avant aujourd'hui. Cette date est inscrite dans l'« Historique » de la page de l'unité, même pour un retour partiel.
::figure eq-return-form ring=eq-ret-quantity,eq-ret-date
:::

:::step Choisissez l'« État » et tapez des « Notes »
L'« État » commence sur l'état actuel du matériel, ici « Neuf ». Les deux ballons sont en bon état : laissez-le. Dans « Notes », tapez par exemple : رجعت الكرتان سليمتين (les deux ballons sont revenus intacts). L'état de l'unité change seulement si sa ligne est une seule unité, comme « ساعة توقيت 1 » (chronomètre 1). Sur une ligne de plusieurs unités, comme les ballons, l'« État » est seulement inscrit dans l'historique. Un ballon est revenu endommagé ? Enregistrez son retour ici, puis séparez-le avec le bouton « Reclasser des unités ».
::figure eq-return-form ring=eq-ret-condition,eq-ret-notes
:::

:::step Cliquez sur « Retourner »
Le message « Équipement rendu avec succès. » s'affiche. Toutes les unités sont revenues ? La ligne disparaît de « Matériel sorti ». Et les deux ballons redeviennent disponibles sur la page de l'équipement.
::figure eq-return-form ring=eq-ret-save zoom=eq-ret-save
:::

:::tip
Le même bouton ‹ existe sur la page de l'équipement. La colonne « Détenu par » montre toutes les personnes qui ont des unités de la ligne. À côté de chaque nom, il y a son propre bouton « Retourner ». Il ouvre la même fenêtre.
:::

::figure eq-lend-assign-catalog ring=eq-holder-return-3 zoom=eq-holder-return-3
