---
title: Comment annuler une séance, effacer ses présences ou la supprimer
summary: Annulez une séance qui n'a pas eu lieu avec son motif, effacez des présences saisies par erreur, ou supprimez la séance pour de bon.
pages: [attendance.sessions.show]
keywords: [annuler une séance, séance annulée, motif d'annulation, supprimer une séance, effacer les présences, présences par erreur, erreur de saisie, piscine fermée, séance non tenue]
related: [attendance/take-attendance, attendance/month-grid]
---

Une séance n'aura pas lieu ? Annulez-la et notez le motif. Des présences saisies par erreur ? Effacez-les, la séance reste.

:::example
La piscine est fermée pour entretien le jour de la séance « أصاغر » en « Natation » : annulez la séance. En « Football », les présences de la séance de préparation « جري 7.2 كم في 40 دقيقة » ont été saisies par erreur : effacez-les.
:::

:::step Ouvrez la page de la séance
Ouvrez la séance « أصاغر » depuis le calendrier de « Présence », comme dans « Comment enregistrer les présences d'une séance ». Sous son titre : la date et l'heure, puis l'état « Prévue ».
::figure att-session-actions ring=att-state
:::

:::step Cliquez sur le bouton « Annuler la séance »
En haut de la page, cliquez sur le bouton rouge avec un cercle et une ×. Son nom « Annuler la séance » s'affiche quand vous passez la souris dessus. La fenêtre « Annuler la séance » s'ouvre.
::figure att-session-actions ring=att-cancel zoom=att-cancel
:::

:::step Tapez le motif d'annulation, puis confirmez
Dans le champ « Motif d'annulation », tapez « المسبح مغلق للصيانة » (piscine fermée pour entretien). Le motif est obligatoire : une séance ne s'annule pas sans lui. Puis cliquez sur le bouton rouge « Annuler la séance ». Le message « Séance annulée. » s'affiche. En haut de la page : « Annulée : المسبح مغلق للصيانة ». Si des présences avaient été saisies, elles restent enregistrées mais ne comptent pas.
::figure att-cancel-modal ring=att-cancel-reason
::figure att-cancel-modal ring=att-cancel-confirm zoom=att-cancel-confirm
::figure att-cancelled ring=att-cancelled-banner
:::

:::step Ouvrez la séance de préparation
Des présences saisies par erreur ? N'annulez pas la séance. Vous êtes sur « Natation » : dans « Présence », choisissez la section « Football » et la catégorie « أقل من 17 سنة ». Puis ouvrez la séance du samedi « جري 7.2 كم في 40 دقيقة ».
::figure att-calendar ring=att-branch,att-category
:::

:::step Cliquez sur le bouton « Supprimer la séance »
En haut de la page, cliquez sur le bouton rouge avec une corbeille. Son nom « Supprimer la séance » s'affiche quand vous passez la souris dessus. La fenêtre « Supprimer la séance » s'ouvre.
::figure att-session-held ring=att-delete zoom=att-delete
:::

:::step Choisissez « Garder la séance, effacer les présences »
La fenêtre s'ouvre sur « Supprimer la séance ». Cliquez sur le deuxième choix : « Garder la séance, effacer les présences ». Le bouton rouge devient « Effacer les présences ». Vous ne voyez pas ce choix ? Aucune présence n'est saisie dans la séance : il n'y a rien à effacer.
::figure att-delete-modal ring=att-delete-choices,att-delete-reset
:::

:::step Cliquez sur « Effacer les présences »
Au-dessus du bouton : « 4 présence(s) seront effacées définitivement. ». Cliquez sur « Effacer les présences ». Le message « Présences de la séance effacées. » s'affiche. La séance reste dans le calendrier, avec l'état « Prévue ». Saisissez à nouveau ses présences.
::figure att-delete-modal ring=att-delete-confirm zoom=att-delete-confirm
:::

Ces boutons demandent le droit « Modifier » dans « Présence ». Vous ne les voyez pas ? Demandez ce droit à l'administrateur du club.

:::tip
Vous avez annulé une séance par erreur ? Ouvrez-la et cliquez sur le bouton corbeille. Choisissez « Garder la séance, effacer les présences », puis cliquez sur « Effacer les présences ». La séance redevient « Prévue », et ses présences sont effacées s'il y en avait.
:::

:::warning
« Supprimer la séance » retire la séance du calendrier et efface ses présences de toutes les statistiques. Vous ne pourrez pas la récupérer. Et l'application ne recrée pas une séance de l'horaire hebdomadaire supprimée.
:::
