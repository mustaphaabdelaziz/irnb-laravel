---
title: Comment prêter du matériel ou l'affecter à un joueur
summary: Remettez des unités du stock à un joueur ou à une personne extérieure au club, et fixez la date de leur retour.
pages: [equipment.catalogs.show]
keywords: [prêt, prêter, affectation, louer, remettre du matériel, sortir du matériel, joueur, personne externe, durée de prêt, date de retour, en retard]
related: [equipment/return-equipment, equipment/damage-repair-lost, equipment/receive-stock]
---

Le matériel sort du stock avec le bouton « Louer » de la page de son équipement. Malgré son nom, ce bouton ne prend pas d'argent. Et le logiciel n'enregistre aucune transaction.

:::example
أمين قاسمي emprunte deux ballons « كرة قدم مقاس 5 » (ballon de football taille 5) pour 7 jours.
:::

:::step Ouvrez la page « Équipements »
Sur téléphone, ouvrez d'abord le menu avec le bouton ☰. Dans le menu, cliquez sur « Équipements » sous « Équipement ». Vous ne le voyez pas ? Cliquez sur « Équipement » pour l'afficher.
::figure eq-lend-assign-list ring=nav-equipment
:::

:::step Cliquez sur « كرة قدم مقاس 5 »
Dans le tableau, cliquez sur le nom de l'équipement. Sa page s'ouvre, avec le tableau « Articles » des unités en stock.
::figure eq-lend-assign-list ring=eq-catalog-link-1 zoom=eq-catalog-link-1
:::

:::step Cliquez sur le bouton « Louer » sur la ligne des ballons
Sur la ligne des ballons, cliquez sur le bouton à l'avion en papier. Quand vous posez la souris dessus, son nom « Louer » apparaît. La fenêtre « Louer — كرة قدم مقاس 5 » s'ouvre. Vous ne voyez pas le bouton ? Il vous faut le droit « Modifier » dans « Équipement ». Le bouton manque aussi sur une ligne où il ne reste aucune unité disponible.
::figure eq-lend-assign-catalog ring=eq-rent-3 zoom=eq-rent-3
:::

:::step Choisissez « Prêt » ou « Affectation »
« Prêt » sert au matériel qui revient après un temps. Choisissez-le pour les deux ballons. « Affectation » sert au matériel avec lequel le joueur travaille, sans date de retour. Quand vous le choisissez, le message « Remis à une personne pour son travail. Sans date de retour. » s'affiche. L'affectation est réservée aux joueurs, et ne passe jamais « En retard ».
::figure eq-lend-assign-type ring=eq-rent-rental,eq-rent-assignment
:::

:::step Choisissez le joueur
Vérifiez que « Joueur » est choisi. Cliquez sur « Sélectionner joueur », et tapez : أمين قاسمي. Puis cliquez sur son nom dans la liste. Les ballons appartiennent à une autre section que celle du joueur ? Le message « Ce matériel appartient à une autre section. » s'affiche. C'est seulement un avertissement : il n'empêche pas le prêt.
::figure eq-lend-assign-player ring=eq-rent-player-btn,eq-rent-player
:::

:::step Tapez la « Quantité » et la « Durée de prêt prévue »
Dans « Quantité », tapez : 2. En dessous s'affiche le nombre d'unités disponibles : « 18 disponibles ». Le logiciel n'accepte pas plus que le disponible. La « Quantité » n'apparaît que sur une ligne de plusieurs unités. La « Date de location » est aujourd'hui : changez-la si vous avez remis les ballons avant aujourd'hui. Dans « Durée de prêt prévue », tapez le nombre de jours : 7. La « Date de retour » s'affiche en dessous. Vous l'avez laissée vide ? Le prêt ne passe jamais « En retard ».
::figure eq-lend-assign-fields ring=eq-rent-quantity,eq-rent-date,eq-rent-days
:::

:::step Cliquez sur « Louer »
Le message « Équipement remis avec succès. » s'affiche. أمين قاسمي apparaît dans la colonne « Détenu par », avec le nombre de ballons à côté. Le prêt apparaît aussi sur la page « Matériel sorti ». Si les ballons ne reviennent pas avant la « Date de retour », il y apparaît « En retard ».
::figure eq-lend-assign-fields ring=eq-rent-save zoom=eq-rent-save
:::

:::tip
Vous prêtez à une personne extérieure au club ? Cliquez sur « Personne externe » au lieu de « Joueur ». Puis tapez le « Nom complet » et le « Téléphone ». Le logiciel n'enregistre pas sans le nom. Passer de « Joueur » à « Personne externe » efface ce que vous avez choisi ou tapé. « Personne externe » n'apparaît qu'avec « Prêt », pas avec « Affectation ».
:::

::figure eq-lend-assign-external ring=eq-rent-external-btn,eq-rent-name,eq-rent-phone
