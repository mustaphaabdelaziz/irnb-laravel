---
title: Comment suivre les suspensions et ajouter une suspension manuelle
summary: Réglez les règles de suspension de la compétition, vérifiez les suspensions automatiques tirées des rapports, et ajoutez ou supprimez une suspension manuelle.
module: competitions
pages: [competitions.show]
keywords: [suspension, suspensions, suspendu, carton rouge, cartons jaunes, suspension automatique, suspension manuelle, règles de suspension, période, nombre de matchs, toutes compétitions, supprimer une suspension, commission de discipline]
related: [competitions/match-report, competitions/call-up, competitions/create-competition]
---

Une suspension écarte un joueur des matchs de la compétition. L'application la calcule seule à partir des rapports de match. Vous pouvez aussi ajouter une suspension sur décision.

:::example
Dans « البطولة الولائية لأقل من 17 سنة » (championnat de wilaya des moins de 17 ans), un carton rouge vaut un match de suspension. Trois cartons jaunes valent aussi un match de suspension. بلعيد عبد الرحمن a pris un carton rouge à la journée 2 : il a manqué la journée 3. لعريبي أنس a deux cartons jaunes : il n'est pas suspendu. Ensuite, nous lui ajoutons une suspension manuelle d'un match, puis nous la supprimons.
:::

:::step Ouvrez la compétition
Ouvrez « Compétitions » comme dans « Comment créer une compétition et fixer sa liste de joueurs ». Cliquez sur le nom « البطولة الولائية لأقل من 17 سنة ». Sa page s'ouvre sur l'onglet « Liste ».
::figure comp-suspensions-list ring=compn-link-1
:::

:::step Ouvrez l'onglet « Réglages »
Cliquez sur « Réglages ». Sous « Règles de suspension », il y a trois zones. La première, « Matchs de suspension après un rouge », vaut ici 1. La deuxième, « Cartons jaunes avant suspension », vaut ici 3. La troisième, « Matchs de suspension pour les jaunes », vaut ici 1. Après chaque suspension pour les jaunes, le compte des jaunes repart de zéro. Tapez 0 dans la première pour supprimer la suspension après un rouge. Laissez la deuxième vide pour supprimer la suspension pour les jaunes. En dessous, vous lisez : « Les suspensions sont calculées automatiquement depuis les feuilles de match selon ces règles. ».
::figure comp-suspensions-rules ring=compn-tab-rules,compn-rule-red,compn-rule-yellow,compn-rule-yellow-ban,compn-rules-help
:::

:::step Cliquez sur « Enregistrer »
Cliquez dessus après tout changement dans les trois zones. Le message « Compétition modifiée. » s'affiche. L'application recalcule les suspensions automatiques à partir des rapports. Dans notre exemple, nous n'avons rien changé : inutile d'enregistrer. Il vous faut le droit « Modifier » dans « Compétitions ». Un entraîneur limité à ses équipes enregistre seulement les règles des compétitions des équipes qu'il entraîne.
::figure comp-suspensions-rules ring=compn-rules-save zoom=compn-rules-save
:::

:::step Ouvrez l'onglet « Suspensions »
Cliquez sur « Suspensions ». Les colonnes du tableau : « Joueur », « Motif », « Sanction » et « Statut ». Dans notre exemple, il y a la ligne de بلعيد عبد الرحمن. Son motif : « carton rouge », avec la date du match et le nom de l'adversaire. Sa sanction : « Matchs purgés : 1/1 ». Son statut : « Purgée ». Le statut « En cours » veut dire qu'il reste des matchs. Le statut « Pas commencée » est pour une suspension qui commence à une date pas encore arrivée. La sanction se purge dans les matchs « Joué » après le match du carton. Un match reporté, annulé ou amical ne compte pas. Le reste de la sanction passe aux matchs officiels de l'équipe des saisons suivantes. Si un joueur a plusieurs suspensions dans la même portée, elles se purgent l'une après l'autre.
::figure comp-suspensions-bans ring=compn-tab-suspensions,compn-ban-auto,compn-ban-col-length,compn-ban-col-status
:::

:::step Remplissez le formulaire « Ajouter une suspension manuelle »
Le formulaire est sous le tableau. Dans « Joueur », choisissez لعريبي أنس. Laissez « S'applique à » sur « Cette compétition ». « Toutes compétitions » l'applique à tous les matchs officiels des équipes du joueur. Laissez « Type » sur « Nombre de matchs », et laissez « Matchs » à 1. Laissez « Du » vide : le compte commence aujourd'hui. Une ligne vous le rappelle : « Laissez vide pour commencer aujourd'hui. ». Le premier match compté est celui après ce jour, pas le match du jour même. Dans « Motif », tapez : « قرار لجنة الانضباط » (décision de la commission de discipline). Avec « Cette compétition », vous voyez seulement les joueurs de la liste de la compétition. Avec « Toutes compétitions », vous voyez les joueurs de l'effectif de l'équipe cette saison. Pour une suspension par dates, choisissez « Période » dans « Type ». « Matchs » disparaît et « Au » apparaît. Les zones « Du » et « Au » sont obligatoires, et la suspension inclut ces deux jours. Avec « Cette compétition », elle couvre seulement les matchs de la compétition. Avec « Toutes compétitions », elle couvre chaque match, même amical.
::figure comp-suspensions-form ring=compn-ban-player,compn-ban-count,compn-ban-from-hint,compn-ban-reason
::figure comp-suspensions-dates ring=compn-ban-kind,compn-ban-from,compn-ban-to
:::

:::step Cliquez sur « Ajouter »
Le message « Suspension ajoutée. » s'affiche. لعريبي أنس apparaît en haut du tableau avec le statut « En cours ». Sa sanction commence à la journée 4. Il vous faut le droit « Modifier » dans « Compétitions ». Un entraîneur limité à ses équipes ajoute seulement des suspensions pour les équipes qu'il entraîne.
::figure comp-suspensions-form ring=compn-ban-add zoom=compn-ban-add
:::

:::step Cliquez sur le bouton corbeille
Au bout de la ligne de la suspension manuelle, il y a un bouton corbeille. Quand vous placez la souris dessus, son nom « Supprimer » s'affiche. Une fenêtre « Confirmer l'action » s'ouvre, avec « Supprimer cette suspension ? ». Cliquez sur « Supprimer » dans la fenêtre. Le message « Suspension supprimée. » s'affiche. Il vous faut le droit « Modifier » dans « Compétitions ». Un entraîneur limité à ses équipes supprime seulement les suspensions des équipes qu'il entraîne. Dans notre exemple, nous supprimons la suspension de لعريبي أنس.
::figure comp-suspensions-added ring=compn-ban-delete zoom=compn-ban-delete
::figure comp-suspensions-delete-confirm ring=confirm-ok
:::

:::warning
⚠️ La suppression d'une suspension est définitive. Une ligne de suspension automatique n'a pas de bouton de suppression. Elle change quand vous modifiez le rapport du match, ou quand vous changez les règles de suspension.
:::

:::tip
Une suspension en cours affiche le badge « Suspendu » dans la convocation du match. Il avertit seulement, sans empêcher la convocation.
:::
