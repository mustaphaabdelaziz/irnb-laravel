---
title: Comment ajouter un nouveau type d'équipement
summary: Créez la fiche d'un équipement avec son nom, sa catégorie et son prix, puis réceptionnez ses unités en stock.
pages: [equipment.catalogs.index, equipment.catalogs.create]
keywords: [ajouter un équipement, nouvel équipement, catalogue, matériel, nouveau matériel, suivre chaque unité, numéro de série, nom proche, prix du matériel]
related: [equipment/equipment-lists, equipment/receive-stock]
---

Un équipement est une seule fiche pour un type de matériel, comme « قمع تدريب » (plot d'entraînement). La fiche porte le nom, la catégorie et le prix. Les unités elles-mêmes entrent en stock ensuite.

:::example
Le club achète des cordes à sauter pour l'entraînement. Il crée l'équipement « حبل قفز » (corde à sauter) dans la catégorie « معدات التدريب » (matériel d'entraînement), au prix de 400 la corde.
:::

:::step Ouvrez la page « Équipements »
Sur téléphone, ouvrez d'abord le menu avec le bouton ☰. Dans le menu, cliquez sur « Équipements » sous « Équipement ». Vous ne le voyez pas ? Cliquez sur « Équipement » pour l'afficher. La page montre tous les équipements et le nombre de leurs unités.
::figure eq-add-catalog-list ring=nav-equipment
:::

:::step Cliquez sur le bouton « Ajouter un équipement »
En haut de la page, cliquez sur le bouton marqué d'un +. Quand vous posez la souris dessus, son nom « Ajouter un équipement » apparaît. La page « Ajouter un équipement » s'ouvre. Vous ne voyez pas le bouton ? Il vous faut le droit « Ajouter » dans « Équipement ».
::figure eq-add-catalog-list ring=eq-add-catalog zoom=eq-add-catalog
:::

:::step Tapez le « Nom », choisissez la « Catégorie » et tapez le « Prix »
Dans « Nom », tapez : حبل قفز. Dans « Catégorie », choisissez « معدات التدريب ». La liste montre les catégories de la page « Catégories d'équipement ». La « Marque » est facultative : laissez-la vide. Dans « Prix », tapez le prix d'une seule unité : 400. Le logiciel le propose lors de la réception du stock.
::figure eq-add-catalog-form ring=eq-name,eq-category,eq-brand,eq-price
:::

:::step Laissez « Suivre chaque unité individuellement » décoché
Les cordes se comptent : chaque corde n'a pas besoin d'un numéro. Cochez la case seulement pour le matériel cher, comme « ساعة توقيت » (chronomètre). Chaque unité reçoit alors un numéro de série et son propre historique. Choisissez bien : après la première réception, ce choix est verrouillé dans la page de modification de l'équipement. Le message « Le mode de suivi ne peut plus être modifié une fois que le catalogue contient du stock. » s'y affiche.
::figure eq-add-catalog-form ring=eq-track zoom=eq-track
:::

:::step Cliquez sur « Enregistrer »
La page « حبل قفز » s'ouvre avec le message « Catalogue de matériel créé avec succès. ». L'équipement n'a encore aucune unité. Pour faire entrer les cordes en stock, lisez l'article « Comment réceptionner du stock d'équipement ».
::figure eq-add-catalog-form ring=eq-save zoom=eq-save
:::

:::tip
Le logiciel affiche un avertissement sous « Nom » si un équipement porte le même nom écrit autrement, ou un nom qui le contient. Par exemple, « كره قدم مقاس 5 » avec ه au lieu de ة correspond à « كرة قدم مقاس 5 » (ballon de football taille 5). « كرة قدم » seul vous le rappelle aussi. Le message « Un équipement au nom proche existe déjà : كرة قدم مقاس 5 » s'affiche. L'avertissement n'empêche pas l'enregistrement. Si c'est bien le même équipement, n'en créez pas un deuxième. Réceptionnez les unités dans l'équipement qui existe.
:::

::figure eq-add-catalog-similar ring=eq-name,eq-similar
