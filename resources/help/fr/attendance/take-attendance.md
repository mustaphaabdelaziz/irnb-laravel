---
title: Comment enregistrer les présences d'une séance
summary: Ouvrez la séance dans le calendrier, marquez les retards et les absences, ajoutez un invité, puis enregistrez.
pages: [attendance.index, attendance.sessions.show]
keywords: [présence, absence, faire l'appel, séance, entraînement, retard, absent justifié, invité, entraîneur, effectuée]
related: [attendance/add-session]
---

Enregistrez les présences sur la page de la séance : un joueur par ligne, avec les boutons de statut en face.

:::example
À la prochaine séance des « أقل من 17 سنة » (moins de 17 ans), **أمين قاسمي** arrive avec 10 minutes de retard. **عبد الرحمن بلعيد** est absent car il est malade. Et **وليد سعداوي**, des « أكابر » (seniors), s'entraîne avec eux.
:::

:::step Ouvrez la page « Présence »
Sur téléphone, ouvrez d'abord le menu avec le bouton ☰. Dans le menu, cliquez sur « Présence » sous « Gestion des joueurs ». Vous ne le voyez pas ? Cliquez sur « Gestion des joueurs » pour l'afficher.
::figure att-calendar ring=nav-members,nav-attendance
:::

:::step Choisissez la section et la catégorie
En haut de la page, cliquez sur « Football ». Dans le champ de la catégorie, choisissez « أقل من 17 سنة ». Le calendrier du mois affiche les séances de cette catégorie.
::figure att-calendar ring=att-branch,att-category
:::

:::step Ouvrez la séance
Dans le calendrier, cliquez sur la séance à son jour : un rectangle gris marqué 18:00. Les séances effectuées sont vertes, avec un ✓. La page de la séance s'ouvre, et en haut : « Pas encore saisie : tout le monde est présent jusqu'à l'enregistrement. ».
::figure att-calendar ring=att-session-next zoom=att-session-next
::figure att-session ring=att-not-saved,att-roster
:::

:::step Marquez le retard de أمين
Sur la ligne de أمين, cliquez sur « En retard ». Le champ « Minutes » apparaît en dessous : tapez 10. « En retard » ne s'enregistre pas sans minutes.
::figure att-session-late ring=att-row-late,att-minutes zoom=att-minutes
:::

:::step Marquez l'absence justifiée de عبد الرحمن
Sur la ligne de عبد الرحمن, cliquez sur « Absent (justifié) ». Le champ « Motif » apparaît, avec « Autre ». Choisissez-y « Maladie ».
::figure att-session-excused ring=att-row-excused,att-reason zoom=att-reason
:::

:::step Ajoutez وليد à la séance
Sous le dernier joueur, tapez « وليد » dans le champ « Rechercher un joueur par son nom pour l'ajouter… ». Cliquez sur son nom dans la liste qui apparaît. Il est ajouté à la fin de la séance, avec le statut « Présent ».
::figure att-session-guest ring=att-guest,att-guest-result
:::

:::step Cliquez sur « Enregistrer »
Dans le champ « Entraîneur », l'application a écrit l'entraîneur de la séance précédente. Corrigez-le s'il a changé. Cliquez sur « Enregistrer ». Le message « Présences enregistrées. » s'affiche, et la séance passe à l'état « Effectuée ». L'enregistrement demande le droit « Modifier » dans « Présence ».
::figure att-session-save ring=att-coach,att-save zoom=att-save
:::

Vous pouvez chercher n'importe quel joueur du club. S'il est d'une autre section, ses sections s'affichent à côté de son nom.

:::tip
Tout le monde était là ? Cliquez quand même sur « Enregistrer ». Avant l'enregistrement, tout le monde apparaît « Présent », mais rien n'est enregistré. La séance reste « Prévue » et n'entre pas dans les statistiques.
:::
