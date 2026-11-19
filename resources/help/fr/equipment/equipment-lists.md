---
title: Comment ajouter une catégorie d'équipement ou un emplacement de stockage
summary: Préparez les listes des catégories d'équipement et des emplacements de stockage avant d'ajouter le matériel lui-même.
module: equipment_categories
pages: [equipment-categories.index, storage-locations.index]
keywords: [catégorie d'équipement, catégories d'équipement, code de catégorie, emplacement de stockage, lieu de stockage, magasin, ajouter une catégorie, supprimer une catégorie, modifier une catégorie, numéro de série]
related: [equipment/add-catalog, equipment/receive-stock]
---

Chaque équipement a une catégorie, comme « كرات » (ballons) ou « ملابس » (vêtements). Et chaque unité en stock a un emplacement, comme « المخزن الرئيسي » (magasin principal). Vous ajoutez les catégories et les emplacements une seule fois, puis vous les choisissez dans les listes.

:::example
Le club achète des sacs pour les équipes. Il ajoute la catégorie « حقائب » (sacs) avec le code BAG. Et il les range dans un nouvel emplacement nommé « مخزن القاعة » (magasin de la salle).
:::

:::step Ouvrez la page « Catégories d'équipement »
Sur téléphone, ouvrez d'abord le menu avec le bouton ☰. Dans le menu, cliquez sur « Catégories d'équipement » sous « Paramétrage ». Vous ne le voyez pas ? Cliquez sur « Paramétrage » pour l'afficher. La page montre les catégories, et le nombre d'« Équipements » dans chaque catégorie.
::figure eq-equipment-lists-categories ring=nav-equipment-categories
:::

:::step Tapez le « Nom », le « Code » et la « Description »
Dans « Nom », tapez : حقائب. Dans « Code », tapez : BAG. La « Description » est facultative ; tapez par exemple : حقائب الفرق (sacs des équipes). Le code se compose de lettres latines ou de chiffres, jusqu'à 10. Le logiciel l'enregistre en majuscules. Vous ne voyez pas les champs ? Il vous faut le droit « Ajouter » dans « Catégories d'équipement ».
::figure eq-equipment-lists-category-typed ring=eqcat-name,eqcat-code,eqcat-description
:::

:::step Cliquez sur « Ajouter »
Le message « Catégorie créée avec succès. » s'affiche. La catégorie apparaît dans le tableau. Vous avez laissé le « Code » vide ? Le logiciel prend les 4 premières lettres latines ou chiffres du nom. Un nom tout en arabe ne suffit pas : le logiciel vous demande de taper le code vous-même. Le code entre dans le numéro de série de chaque unité suivie individuellement. Le changer plus tard ne change pas les numéros des unités existantes.
::figure eq-equipment-lists-category-typed ring=eqcat-add zoom=eqcat-add
:::

:::step Ouvrez la page « Emplacements de stockage »
Sur téléphone, ouvrez d'abord le menu avec le bouton ☰. Dans le menu, cliquez sur « Emplacements de stockage » sous « Paramétrage ». Il se trouve sous « Catégories d'équipement ». La page montre les emplacements existants, comme « المخزن الرئيسي ».
::figure eq-equipment-lists-places ring=nav-storage-locations
:::

:::step Tapez le nom de l'emplacement
Dans « Nom », tapez : مخزن القاعة. Le logiciel n'accepte pas le nom d'un emplacement qui existe déjà. Vous ne voyez pas le champ ? Il vous faut le droit « Ajouter » dans « Emplacements de stockage ».
::figure eq-equipment-lists-place-typed ring=loc-name
:::

:::step Cliquez sur « Ajouter »
Le message « Lieu de stockage créé avec succès. » s'affiche. L'emplacement apparaît dans la liste. Vous le choisissez dans « Emplacement » lors de la réception du stock.
::figure eq-equipment-lists-place-typed ring=loc-add zoom=loc-add
:::

:::tip
Pour corriger le nom d'une catégorie ou d'un emplacement, cliquez sur le bouton au crayon sur sa ligne. Quand vous posez la souris dessus, son nom « Modifier » apparaît. Le bouton demande le droit « Modifier » dans « Catégories d'équipement » ou « Emplacements de stockage ». Les champs de la ligne deviennent modifiables. Corrigez le nom, puis cliquez sur « Enregistrer » sur la même ligne. Le nouveau nom s'applique à tout le matériel qui y est lié. En revanche, une catégorie utilisée ne se supprime pas : son bouton corbeille ne fonctionne pas. Quand vous posez la souris dessus, « Catégorie utilisée par des équipements, suppression impossible » s'affiche.
:::
