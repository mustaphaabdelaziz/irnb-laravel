---
title: Comment modifier les codes de présence, les règles de discipline et les alertes
summary: Changez les lettres, les couleurs et les points des statuts, réglez les règles de discipline et les alertes, et ajoutez des codes propres au club.
pages: [attendance.settings]
keywords: [codes de présence, code, couleur, points, règles de discipline, alerte, alertes, absences d'affilée, retard, codes personnalisés, en voyage, masquer un code, réglages des présences]
related: [attendance/month-grid, attendance/timetable]
---

Les codes sont les lettres que l'entraîneur écrit sur la feuille et dans « Grille du mois » : « P » présent, « R » en retard… Changez-les dans « Réglages des présences ».

:::example
Les entraîneurs du club écrivent « ت » pour un retard au lieu de « R ». Le club veut une alerte après 3 absences non justifiées d'affilée. Il ajoute aussi un nouveau code « س » pour un joueur **en voyage**, compté « Absent (justifié) ».
:::

:::step Ouvrez la page « Réglages des présences »
Ouvrez-la depuis la page « Présence », comme dans « Comment régler l'horaire hebdomadaire et les fermetures ». Il vous faut le droit « Modifier » dans « Présence ».
::figure att-calendar ring=att-settings zoom=att-settings
:::

:::step Tapez « ت » comme code du retard
Descendez jusqu'à la carte « Codes et couleurs » avec la molette de la souris ou le pavé tactile. Sur téléphone, faites glisser l'écran vers le haut avec le doigt. Sur la ligne « En retard », effacez R dans le champ « Code », puis tapez ت. Un code a de 1 à 3 lettres, et les lettres arabes sont acceptées.
::figure set-codes ring=set-code-late zoom=set-code-late
:::

:::step Tapez 3 dans l'alerte des absences d'affilée
Sous la carte « Codes et couleurs », cherchez « Alertes ». Dans le champ « Alerte après ce nombre d'absences non justifiées d'affilée », tapez 3. Il contient déjà 3 : c'est le nombre par défaut. Le zéro arrête l'alerte.
::figure set-rules ring=set-alerts
:::

:::step Cliquez sur « Enregistrer » sous « Statuts des joueurs sur la liste »
La page a d'autres boutons « Enregistrer ». Cliquez sur celui qui est sous la carte « Statuts des joueurs sur la liste ». Le message « Réglages des présences enregistrés. » s'affiche. Ce bouton enregistre ensemble les codes, les couleurs, les points, les règles, les alertes et les statuts des joueurs.
::figure set-rules ring=set-save zoom=set-save
:::

:::step Remplissez la ligne du code personnalisé
Dans la carte « Codes personnalisés », tapez س dans « Code ». Dans « Nom (arabe) », tapez « مسافر » (en voyage). Dans « Compte comme », choisissez « Absent (justifié) ». Dans « Couleur », un violet est déjà choisi : changez-le si vous voulez.
::figure set-custom ring=set-custom-form
:::

:::step Cliquez sur « Ajouter un code »
Dans la carte « Codes personnalisés », cliquez sur « Ajouter un code ». Le message « Réglages des présences enregistrés. » s'affiche. « س » apparaît dans le tableau sous les champs, avec l'état « Affiché ». Et « مسافر » apparaît avec les boutons de statut sur la page de la séance.
::figure set-custom ring=set-custom-add zoom=set-custom-add
:::

Après l'enregistrement, l'entraîneur écrit « ت15 » dans « Grille du mois » pour 15 minutes de retard. Changer un code ne change pas les présences enregistrées.

Un code en double n'est pas enregistré, et le message « Un autre statut utilise déjà ce code. » s'affiche. Dans « Codes et couleurs », il apparaît sous le nom du statut. Dans « Codes personnalisés », il apparaît sous les champs.

La ligne de chaque statut a aussi « Couleur » et « Points ». Les points vont de −5 à 5, par quart de point. Ils comptent seulement pour le classement des joueurs et les alertes. Les nombres de statuts dans les rapports ne changent pas.

« Règles de discipline » est à côté de « Alertes », et contient déjà 3 et 30. La première : tous les 3 retards comptent dans les points comme une absence non justifiée. La deuxième : un retard de plus de 30 minutes compte dans les points comme une absence non justifiée. Le zéro arrête la règle.

« Compte comme » a quatre valeurs : « Présent », « Absent (justifié) », « Absent (non justifié) », « Non compté ». « Non compté » retire la séance du calcul du joueur.

:::warning
Un code personnalisé utilisé dans des présences ne peut pas être supprimé. Vous pouvez seulement le masquer avec le bouton en forme de boîte sur sa ligne. Son nom « Masquer » s'affiche quand vous passez la souris dessus.
:::
