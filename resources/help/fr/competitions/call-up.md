---
title: Comment convoquer les joueurs d'un match
summary: Choisissez les joueurs du match dans la liste de la compétition ou l'effectif, vérifiez les badges, puis imprimez la feuille de convocation.
pages: [games.show]
keywords: [convocation, convoquer, joueurs convoqués, feuille de convocation, motif, blessé, suspendu, sans licence, impayé, limite de convoqués, nombre de convoqués, match]
related: [competitions/schedule-match, competitions/create-competition, competitions/roster-coaches, competitions/lineup]
---

La convocation est la liste des joueurs appelés pour le match. Pour un match de compétition, vous choisissez dans la liste de la compétition. Pour un match amical, vous choisissez dans l'effectif de l'équipe. La limite de convoqués vient du format de jeu.

:::example
Nous convoquons les joueurs du match « الفريق أ — اتحاد الشباب » du championnat de wilaya. C'est la journée 4, au format « Football à 5 ». Sa limite est de dix joueurs.
:::

:::step Ouvrez la page « Matchs »
Sur téléphone, ouvrez d'abord le menu avec le bouton ☰. Dans le menu, cliquez sur « Matchs » sous « Compétitions et matchs ». Vous ne la voyez pas ? Cliquez sur « Compétitions et matchs » pour l'afficher.
::figure comp-schedule-match-list ring=nav-games
:::

:::step Ouvrez le match
Sous « À venir », cliquez sur la ligne « الفريق أ — اتحاد الشباب ». La page du match s'ouvre sur l'onglet « Match ».
::figure comp-schedule-match-list ring=game-row-4
:::

:::step Ouvrez l'onglet « Convocation »
Cliquez sur « Convocation ». Vous voyez tous les joueurs qui peuvent être convoqués : le numéro, le nom et la catégorie de chacun. Un joueur qui a quitté le sport de l'équipe n'apparaît pas.
::figure comp-call-up-list ring=game-tab-callup
:::

:::step Cochez les joueurs convoqués
Cochez chaque joueur que vous voulez convoquer. Le compteur au-dessus du tableau affiche « 6 convoqués (max 10) ». Dans notre exemple, nous convoquons six joueurs. Si le compteur dépasse la limite, il devient rouge. L'application refuse alors d'enregistrer et dit : « Trop de joueurs convoqués pour ce format de jeu. ». À côté des noms, des badges avertissent seulement, sans empêcher la convocation. حمداني رياض a « Blessé ». بلعيد عبد الرحمن, قاسمي أمين et عمراني زكرياء ont « Impayé ». بن علي أحمد a « Sans licence ». Placez la souris sur « Impayé » pour voir « Doit » suivi du montant. Le montant s'affiche pour qui a le droit « Voir » dans « Finances ».
::figure comp-call-up-list ring=game-callup-count,game-callup-table,game-callup-badges-4,game-callup-badges-7,game-callup-badges-3,game-callup-badges-8,game-callup-badges-1
:::

:::step Notez pourquoi un joueur est laissé de côté
Sous « Motif si non convoqué », une zone apparaît pour chaque joueur non coché. Tapez-y un motif court. Dans notre exemple, pour حمداني رياض : « مصاب » (blessé). L'application garde le joueur non convoqué seulement si vous tapez un motif. Si vous laissez la zone vide, aucune ligne n'est gardée pour lui.
::figure comp-call-up-list ring=game-callup-reason-4
:::

:::step Cliquez sur « Enregistrer »
Le message « Convocation enregistrée. » s'affiche. Rien n'est enregistré avant ce clic. Il vous faut le droit « Modifier » dans « Matchs ». Un entraîneur limité à ses équipes convoque seulement les joueurs des équipes qu'il entraîne.
::figure comp-call-up-list ring=game-callup-save zoom=game-callup-save
:::

:::step Cliquez sur « Feuille de convocation (PDF) »
Le lien est au-dessus du tableau, après l'enregistrement. Une feuille s'ouvre avec le match, l'heure du rendez-vous et les joueurs convoqués. Imprimez-la ou envoyez-la.
::figure comp-call-up-list ring=game-callup-pdf zoom=game-callup-pdf
:::

:::warning
⚠️ Un joueur a des événements, une note ou une place dans la composition ? Si vous le décochez, l'application vous demande avant d'enregistrer. Vous lisez « Supprimer des données du rapport ? ». Puis : « Tout ce que le rapport contient sur les joueurs non convoqués sera supprimé : événements, note, remarque et place dans la composition. Joueurs : » et leurs noms. Le bouton « Enregistrer » de la fenêtre confirme la suppression. « Annuler » garde la convocation telle quelle. Après la confirmation, vous lisez « Convocation enregistrée. ». S'il avait des événements, vous lisez « Convocation enregistrée. Les événements des joueurs retirés ont été supprimés du rapport. ».
:::
