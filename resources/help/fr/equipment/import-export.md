---
title: Comment importer l'équipement depuis un fichier Excel et l'exporter
summary: Faites entrer une liste d'équipements ou d'unités depuis un fichier Excel ou CSV, et exportez les listes d'équipement vers un fichier.
pages: [equipment.catalogs.index, equipment.catalogs.show]
keywords: [importer, exporter, Excel, CSV, xlsx, modèle, télécharger le modèle, fichier, liste du matériel, importer des unités, enregistrer comme dépense, prix d'achat]
related: [equipment/add-catalog, equipment/receive-stock, finance/cancel-transaction]
---

Le club a une liste de matériel dans un fichier Excel ? Ne la tapez pas équipement par équipement. Importez-la en une seule fois depuis la page « Équipements ». Et exportez la liste vers un fichier quand vous voulez.

:::example
Le fichier du club contient trois équipements : « حبل قفز » (corde à sauter), « سلم رشاقة » (échelle de rythme) et « كرة قدم مقاس 5 » (ballon de football taille 5). Les trois sont dans une catégorie qui existe, et le troisième existe déjà dans le logiciel. Après l'import, le club exporte la liste complète vers Excel.
:::

:::step Ouvrez la page « Équipements »
Sur téléphone, ouvrez d'abord le menu avec le bouton ☰. Dans le menu, cliquez sur « Équipements » sous « Équipement ». Vous ne le voyez pas ? Cliquez sur « Équipement » pour l'afficher.
::figure eq-import-export-list ring=nav-equipment
:::

:::step Cliquez sur le bouton « Importer »
En haut de la page, cliquez sur le bouton à la flèche montante. Quand vous posez la souris dessus, son nom « Importer » apparaît. La fenêtre « Importer — Équipements » s'ouvre. Vous ne voyez pas le bouton ? Il vous faut le droit « Modifier » dans « Équipement ».
::figure eq-import-export-list ring=eq-import zoom=eq-import
:::

:::step Téléchargez le modèle et remplissez-le
Dans la fenêtre, cliquez sur le bouton à la flèche descendante. Quand vous posez la souris dessus, son nom « Télécharger le modèle » apparaît. Choisissez « Excel (.xlsx) » : le fichier se télécharge. Ses colonnes : « Nom », « Catégorie », « Marque », « Prix d'achat » et « Description ». Laissez les titres tels quels, et effacez la ligne d'exemple. Puis tapez une ligne par équipement. Tapez la « Catégorie » exactement comme dans la page « Catégories d'équipement », lettre par lettre. Et tapez le prix en chiffres seulement, comme 400.
::figure eq-import-export-template ring=eq-import-template,eq-import-template-xlsx zoom=eq-import-template-xlsx
:::

:::step Choisissez le fichier rempli
Dans la fenêtre, cliquez sur le bouton de choix du fichier. Son nom est dans la langue de votre navigateur. La fenêtre des fichiers de l'ordinateur s'ouvre : choisissez votre fichier. Le nom du fichier apparaît à côté du bouton.
::figure eq-import-export-file ring=eq-import-file
:::

:::step Cliquez sur « Importer »
Le bouton ne fonctionne pas tant que vous n'avez pas choisi de fichier. Le message « 2 équipements importés avec succès. » s'affiche. Seuls « حبل قفز » et « سلم رشاقة » sont entrés. En dessous, un deuxième message donne une ligne pour chaque problème de votre fichier. Ici : « Ligne 4 : « كرة قدم مقاس 5 » existe déjà — ignoré. ». Le chiffre est le numéro de la ligne dans votre fichier. Une ligne dont la catégorie n'existe pas n'est pas importée : le message dit « catégorie inconnue ». En revanche, un nom proche, comme « كره قدم مقاس 5 » avec ه au lieu de ة, est importé comme nouvel équipement. Le message vous le signale avec les mots « importé quand même ». Les deux messages restent environ 8 secondes, et le deuxième ne montre que les 10 premiers problèmes.
::figure eq-import-export-file ring=eq-import-submit zoom=eq-import-submit
:::

:::step Cliquez sur le bouton « Exporter »
Sur la page « Équipements », cliquez sur le bouton à la flèche descendante. Quand vous posez la souris dessus, son nom « Exporter » apparaît. Choisissez « Excel (.xlsx) » ou « CSV » : le fichier se télécharge. Il contient tous les équipements, quels que soient la recherche ou la catégorie choisie. Pour exporter, le droit « Voir » dans « Équipement » suffit.
::figure eq-import-export-export ring=eq-export,eq-export-xlsx,eq-export-csv
:::

Chaque équipement a aussi son propre import et export pour ses unités. En haut de la page de l'équipement, comme « ساعة توقيت » (chronomètre), il y a le bouton à la flèche montante. Quand vous posez la souris dessus, son nom « Importer » apparaît. La fenêtre « Importer — ساعة توقيت » fonctionne comme celle des équipements. Mais son modèle a d'autres colonnes : « Désignation », « Date d'achat (AAAA-MM-JJ) », « État », « Emplacement », « Prix d'achat » et « Notes ». Chaque ligne devient une seule unité, avec un nouveau numéro de série. La ligne d'exemple n'est pas importée si elle reste dans le fichier. Tapez l'« Emplacement » comme dans « Emplacements de stockage » : un emplacement absent de la liste est gardé tel qu'il est écrit, et le message vous le signale. Et le bouton à la flèche descendante à côté, « Exporter », télécharge les unités de l'équipement.

::figure eq-import-export-units-button ring=eq-units-import,eq-units-export

:::warning
La fenêtre d'import des unités a une case « Enregistrer comme dépense », décochée au départ. Cochez-la seulement si le club a payé les unités. Vous choisissez alors la « Caisse qui paie la dépense ». Chaque ligne qui a un « Prix d'achat » devient une dépense payée par cette caisse. Une dépense par ligne, à la date d'achat écrite sur la ligne. Importer deux fois le même fichier double les unités et les dépenses. Pour annuler une dépense, supprimez sa transaction dans « Transactions ».
:::

::figure eq-import-export-units-expense ring=eq-units-import-expense,eq-units-import-caisse
