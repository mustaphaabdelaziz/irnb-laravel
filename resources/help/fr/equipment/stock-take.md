---
title: Comment faire l'inventaire du matériel
summary: Comptez le matériel dans les magasins, notez ce que vous avez trouvé et ce qui manque, puis terminez l'inventaire et imprimez son rapport.
module: inventory
pages: [inventory.index, inventory.show]
keywords: [inventaire, inventaire mensuel, inventaire annuel, démarrer l'inventaire, feuille de comptage, terminer l'inventaire, présent, manquant, participants, rapport d'inventaire, écart, compter le matériel]
related: [equipment/damage-repair-lost, equipment/inventory-report, equipment/return-equipment, equipment/receive-stock]
---

L'inventaire compare ce qui est dans le logiciel avec ce qui est vraiment dans les magasins. Le logiciel met sur la feuille de comptage tout ce qui doit se trouver dans les magasins. Et vous, vous notez combien vous en avez trouvé.

:::example
C'est le début du mois. Le club fait l'inventaire de son matériel, avec l'aide du joueur أمين قاسمي. Il a tout trouvé, sauf deux plots sur les quarante, et « ساعة توقيت 3 » (chronomètre 3). Et « مرمى 1 » (but 1) n'est pas sur la feuille de comptage, car il est prêté à مراد بلقاسم.
:::

:::step Ouvrez la page « Inventaire »
Sur téléphone, ouvrez d'abord le menu avec le bouton ☰. Dans le menu, cliquez sur « Inventaire » sous « Équipement ». Vous ne le voyez pas ? Cliquez sur « Équipement » pour l'afficher. Toujours absent ? Il vous faut le droit « Voir » dans « Inventaire ». La page montre tous les inventaires, le plus récent en premier.
::figure eq-stock-take-list ring=nav-stocktake
:::

:::step Cliquez sur le bouton « Démarrer l'inventaire »
Sous les cartes, cliquez sur le bouton marqué d'un +. Quand vous posez la souris dessus, son nom « Démarrer l'inventaire » apparaît. Une petite carte s'ouvre en dessous. Vous ne voyez pas le bouton ? Il vous faut le droit « Ajouter » dans « Inventaire ».
::figure eq-stock-take-list ring=stk-start zoom=stk-start
:::

:::step Choisissez le « Type » et tapez la « Date » et la « Note »
Le « Type » commence sur « Annuel » : choisissez « Mensuel ». Les autres types sont « Trimestriel » et « Ponctuel ». La « Date » est aujourd'hui : laissez-la. Dans « Note », tapez : جرد شهري (inventaire mensuel). La note est facultative.
::figure eq-stock-take-form ring=stk-type,stk-date,stk-notes
:::

:::step Cliquez sur « Démarrer l'inventaire » dans la carte
Cliquez sur le bouton en bas de la carte. La page du nouvel inventaire s'ouvre, avec un message qui donne sa référence, par exemple « Inventaire INV-2026-03 démarré. ». La référence s'écrit ainsi : INV-ANNÉE-NUMÉRO. Le numéro suit le plus grand numéro utilisé cette année, comme INV-2026-03. Un numéro peut manquer après la suppression d'un inventaire. La page montre la feuille de comptage, rangée par emplacement de stockage. Elle a une ligne par unité numérotée, et une par groupe d'unités. Le matériel prêté n'est pas sur la feuille : vous ne voyez donc pas « مرمى متنقل » (but mobile), dont le numéro finit par 00001. Pour un groupe dont une partie est prêtée, seul le reste est attendu. Par exemple, sur la ligne « كرة قدم مقاس 5 » (ballon de football taille 5), vous lisez « 2 prêtés ». Les deux ballons sont chez أحمد بن علي : le nombre attendu est donc 18. Le matériel perdu n'est pas non plus sur la feuille. En revanche, le matériel « En réparation » y est, comme le « ساعة توقيت » dont le numéro finit par 00002. Une petite étiquette « En réparation » s'affiche à côté de son numéro.
::figure eq-stock-take-form ring=stk-submit zoom=stk-submit
:::

:::step Notez combien vous en avez trouvé
Toutes les lignes commencent complètes, comme si vous aviez tout trouvé. Sur la ligne « ساعة توقيت » dont le numéro finit par 00003, cliquez sur « Manquant ». Une ligne sans numéro, comme « قمع تدريب » (plot d'entraînement), regroupe plusieurs unités. Elle a un champ entre « Présent » et « sur 40 ». Effacez 40 et tapez 38. Si vous tapez plus que le nombre après « sur », le logiciel le ramène à ce nombre. Vous en avez trouvé plus ? Faites entrer le surplus avec le bouton « Réceptionner du stock » de la page de son équipement. Dans le champ « Note » en bout de ligne, tapez : ضاع قمعان في التدريب (deux plots perdus à l'entraînement). La note est facultative. Vous avez trouvé du matériel dans un autre état ou à un autre endroit ? Changez-les dans les deux listes avant « Note ». Sur une ligne de groupe, les deux listes changent tout le groupe. Seule une partie a changé d'état ? Après l'inventaire, utilisez « Reclasser des unités » sur la page de son équipement. Vous ne pouvez pas écrire ? Il vous faut le droit « Modifier » dans « Inventaire ». Vous vous êtes trompé ? Le bouton à la coche dans un cercle remet toutes les lignes complètes. Quand vous posez la souris dessus, son nom « Tout marquer présent » apparaît.
::figure eq-stock-take-lines ring=stk-missing-9,stk-qty-4,stk-note-4,stk-condition-7,stk-location-7 zoom=stk-qty-4
::figure eq-stock-take-session ring=stk-mark-all zoom=stk-mark-all
:::

:::step Cliquez sur « Enregistrer »
Au-dessus de la feuille de comptage, cliquez sur « Enregistrer ». Le message « Comptages enregistrés. » s'affiche. Le matériel ne change pas encore : vous pouvez finir le comptage plus tard. Ne quittez pas la page avant d'enregistrer, sinon ce que vous avez tapé est perdu.
::figure eq-stock-take-session ring=stk-save-counts zoom=stk-save-counts
:::

:::step Cliquez sur « Terminer l'inventaire » puis confirmez
Le comptage est fini ? Cliquez sur « Terminer l'inventaire », à côté de « Enregistrer ». Une petite fenêtre du navigateur vous demande : « Terminer l'inventaire ? ». Cliquez sur son bouton d'accord. Le logiciel enregistre le comptage, puis termine l'inventaire. Un message s'affiche avec sa référence, par exemple « Inventaire INV-2026-03 terminé. ». Seules les unités manquantes passent à « Perdu » sur la page de leur équipement. « ساعة توقيت 3 » passe à « Perdu ». Et sur la page « قمع تدريب », les deux plots se séparent sur une ligne « Perdu ». Les 38 autres plots restent comme ils sont. L'état et l'emplacement que vous avez changés sont enregistrés. Les cartes comptent les unités : « Présent » 113 et « Manquant » 3. Sous les chiffres s'affiche la carte « Rapport · Écart » avec les lignes qui diffèrent. Sur la ligne des plots, vous lisez 38 / 40 et votre note.
::figure eq-stock-take-session ring=stk-complete zoom=stk-complete
:::

:::warning
« Terminer l'inventaire » ne s'annule pas. Chaque unité manquante passe à « Perdu » sur la page de son équipement. Supprimer l'inventaire ensuite ne la rétablit pas. La suppression se fait avec le bouton corbeille de la page « Inventaire », avec le droit « Supprimer ». Vous avez trouvé une unité après l'inventaire ? Cliquez sur « Restaurer » sur la page de son équipement.
:::

::figure eq-stock-take-list ring=stk-delete-2 zoom=stk-delete-2

:::tip
La carte « Participants », en haut de la page, note qui vous a aidé à compter. Les participants sont seulement des joueurs, que vous choisissez dans « Ajouter un participant ». Sur l'image, ياسين بوزيد a été choisi, pour être ajouté à côté de أمين قاسمي. Vous ajoutez le nom avec le bouton marqué d'un +. Quand vous posez la souris dessus, son nom « Ajouter » apparaît. Aucun changement n'est enregistré tant que vous n'avez pas cliqué sur « Enregistrer » dans la carte.
:::

::figure eq-stock-take-participant ring=stk-participant-pick,stk-participant-add,stk-participant-save

Pour imprimer le rapport d'inventaire, cliquez sur le bouton à l'imprimante en haut de la page. Quand vous posez la souris dessus, son nom « Rapport » apparaît. Un fichier PDF s'ouvre dans une nouvelle page. Il donne l'attendu, le présent et le manquant, et les écarts ligne par ligne. Sur une ligne de groupe, le nombre s'écrit ainsi : 38 / 40, avec la note. Les états y sont écrits dans la langue du logiciel, comme « Bon » et « Endommagé ». Imprimez-le après « Terminer l'inventaire » : avant, le présent et le manquant restent à zéro. Le bouton à la flèche descendante à côté s'appelle « Exporter ». Il télécharge la feuille de comptage en « Excel (.xlsx) » ou « CSV ». Les deux boutons ne demandent que le droit « Voir » dans « Inventaire ».

::figure eq-stock-take-session ring=stk-report,stk-export
