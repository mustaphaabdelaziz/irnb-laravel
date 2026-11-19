---
title: Comment enregistrer du matériel endommagé, en réparation ou perdu
summary: Séparez les unités endommagées des bonnes, suivez les réparations, et enregistrez ce qui est perdu et ce qui est retrouvé.
pages: [equipment.catalogs.show, equipment.items.history]
keywords: [endommagé, cassé, reclasser, réparation, envoyer en réparation, réparé, perdu, égaré, restaurer, historique de l'unité, état du matériel]
related: [equipment/return-equipment, equipment/lend-assign, equipment/receive-stock]
---

Tout cela se fait dans le tableau « Articles » de la page de l'équipement. Comment ouvrir la page de l'équipement ? Voyez l'article « Comment prêter du matériel ou l'affecter à un joueur ».

:::example
Deux plots sur les 40 « قمع تدريب » (plot d'entraînement) se sont cassés. « ساعة توقيت 2 » (chronomètre 2) est tombé en panne et est parti chez le réparateur. Aujourd'hui, il est revenu en bon état. « ساعة توقيت 3 » a été perdu, puis l'entraîneur l'a retrouvé.
:::

:::step Cliquez sur le bouton « Reclasser des unités »
Sur la page « قمع تدريب », cliquez sur le bouton au triangle, sur la ligne des plots. Quand vous posez la souris dessus, son nom « Reclasser des unités » apparaît. Une fenêtre du même nom s'ouvre. Vous ne voyez pas le bouton ? Il vous faut le droit « Modifier » dans « Équipement ». Ce droit est nécessaire pour tous les boutons de cet article, sauf « Historique ». Le bouton manque aussi sur une ligne d'une seule unité, ou sans unité disponible.
::figure eq-damage-split-button ring=eq-split-4 zoom=eq-split-4
:::

:::step Tapez la « Quantité » et choisissez l'« État »
Dans « Quantité », tapez le nombre d'unités endommagées : 2. Le logiciel n'accepte pas plus que ce qui est disponible sur la ligne. Les unités prêtées ne comptent pas, car vous ne les avez pas sous la main. L'« État » commence sur « Endommagé » : laissez-le. Dans « Notes », tapez la raison, par exemple : انكسرا في التدريب (cassés à l'entraînement).
::figure eq-damage-split-form ring=eq-split-quantity,eq-split-condition,eq-split-notes
:::

:::step Cliquez sur « Enregistrer »
Le message « Unités reclassées avec succès. » s'affiche. Les deux plots passent sur une nouvelle ligne à l'état « Endommagé ». Sur la première ligne restent 38 plots « Neuf ». La nouvelle ligne ne compte pas dans le disponible, et le logiciel refuse de la prêter. Envoyez-la en réparation, ou supprimez-la si vous l'avez jetée.
::figure eq-damage-split-form ring=eq-split-save zoom=eq-split-save
:::

:::step Cliquez sur « Envoyer en réparation » puis confirmez
Sur la page « ساعة توقيت », cliquez sur le bouton à la clé plate, sur la ligne de l'unité. Quand vous posez la souris dessus, son nom « Envoyer en réparation » apparaît. Le bouton n'apparaît que si la ligne est « Disponible » et que toutes ses unités sont en stock. Des unités de la ligne sont sorties ? Enregistrez d'abord leur retour. Sur l'image, « ساعة توقيت 2 » n'a pas ce bouton, car il est déjà « En réparation ». Après le clic, la fenêtre « Confirmer l'action » vous demande : « Envoyer ce matériel en réparation ? ». Cliquez sur « Confirmer ». Le message « Matériel envoyé en réparation. » s'affiche. L'unité passe « En réparation » et ne peut plus être prêtée. En haut de la page s'affiche « En réparation » avec le nombre d'unités à côté. Ces unités ne comptent pas dans « Loué ».
::figure eq-damage-units ring=eq-repair-7 zoom=eq-repair-7
::figure eq-damage-repair-confirm ring=confirm-ok zoom=confirm-ok
:::

:::step Cliquez sur « Marquer réparé » puis confirmez
« ساعة توقيت 2 » est revenu de chez le réparateur. Sur sa ligne, cliquez sur le bouton à la coche. Quand vous posez la souris dessus, son nom « Marquer réparé » apparaît. La fenêtre vous demande : « Marquer cet article comme réparé et de nouveau disponible ? ». Sous la question, le champ « État » commence sur « Bon ». Changez-le si l'unité est revenue dans un autre état, puis cliquez sur « Confirmer ». Le message « Réparation terminée. » s'affiche. L'unité redevient « Disponible », avec l'état que vous avez choisi.
::figure eq-damage-units ring=eq-fixed-8 zoom=eq-fixed-8
:::

:::step Une unité est perdue ? Cliquez sur « Perdu » puis confirmez
Vous ne trouvez plus « ساعة توقيت 3 ». Sur sa ligne, cliquez sur le bouton au cercle avec un ×. Quand vous posez la souris dessus, son nom « Perdu » apparaît. La fenêtre vous demande : « Marquer ce matériel comme perdu ? ». Cliquez sur « Confirmer ». Le message « Matériel déclaré perdu. » s'affiche. L'unité passe à « Perdu ». Si elle était prêtée, son prêt est clôturé.
::figure eq-damage-units ring=eq-lost-9 zoom=eq-lost-9
:::

:::step Vous l'avez retrouvée ? Cliquez sur « Restaurer » puis confirmez
L'entraîneur a retrouvé « ساعة توقيت 3 ». Sur sa ligne, cliquez sur le bouton à la flèche circulaire. Quand vous posez la souris dessus, son nom « Restaurer » apparaît. La fenêtre vous demande : « Restaurer cet article comme disponible ? ». Cliquez sur « Confirmer ». Le message « Matériel remis en service. » s'affiche. L'unité redevient « Disponible ».
::figure eq-damage-restore ring=eq-restore-9 zoom=eq-restore-9
:::

:::warning
« Envoyer en réparation » et « Perdu » changent toute la ligne, pas une partie. Sur une ligne de 20 ballons, les vingt ballons passent à « Perdu ». Et tous les prêts de la ligne sont clôturés, comme les deux ballons de أحمد بن علي. Seuls 3 ballons sont perdus ? Reclassez-en d'abord 3 avec le bouton « Reclasser des unités ». Puis cliquez sur « Perdu » sur la nouvelle ligne. Les unités prêtées ne peuvent pas être reclassées : enregistrez d'abord leur retour. Et « Restaurer » ne rouvre pas les prêts clôturés par « Perdu ».
:::

:::tip
Pour voir tout ce qui est arrivé à une unité, cliquez sur le bouton au calendrier sur sa ligne. Quand vous posez la souris dessus, son nom « Historique » apparaît. La page de l'unité s'ouvre : sous « Historique », chaque événement avec sa date, le plus récent en premier. Pour « ساعة توقيت 2 », par exemple : « Envoyé en réparation », et avant lui « Réceptionné ». Sous « Envoyé en réparation », « Statut précédent : Disponible » indique son statut avant la réparation. Sous un prêt avec une durée s'affiche la « Date d'échéance ». Rien de tel sous une affectation ou un prêt sans durée. Et sous chaque retour s'affiche la « Date de retour », même pour un retour partiel.
:::

::figure eq-damage-units ring=eq-history-8 zoom=eq-history-8

::figure eq-damage-history
