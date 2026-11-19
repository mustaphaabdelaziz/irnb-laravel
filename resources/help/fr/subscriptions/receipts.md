---
title: Comment imprimer un reçu et vérifier un reçu papier
summary: Imprimez le reçu d'un paiement en 80 mm ou en A4, et vérifiez qu'un reçu papier vient bien du club.
module: transactions
pages: [players.show, transactions.show, receipts.verify]
keywords: [reçu, quittance, imprimer, ticket, 80 mm, A4, duplicata, original, code de vérification, QR, vérifier un reçu, faux reçu]
related: [subscriptions/take-payment, subscriptions/fix-payment]
---

Chaque reçu imprimé par l'application porte un code de 12 chiffres. Avec ce code, vous vérifiez plus tard que le reçu est authentique.

:::example
**أحمد بن علي** a payé « اشتراك سنوي » : 3000 DA. Nous lui imprimons un reçu, puis nous le vérifions.
:::

:::step Cliquez sur le bouton imprimante dans la ligne du paiement
Sur téléphone, ouvrez d'abord le menu avec le bouton ☰. Ouvrez la page de أحمد, et descendez jusqu'à « Historique des transactions ». Pour descendre, tournez la molette de la souris vers vous, ou glissez deux doigts sur le pavé tactile. Sur téléphone, faites glisser l'écran vers le haut avec le doigt. Sur téléphone, faites glisser le tableau vers la gauche avec le doigt pour voir les boutons. Dans la ligne du paiement, 3 000,00, cliquez sur le bouton imprimante. Le ticket (80 mm) s'ouvre dans une nouvelle fenêtre. Imprimez-le depuis cette fenêtre.
::figure receipt-ticket-button ring=player-history-ticket zoom=player-history-ticket
:::

:::step Ouvrez la page du paiement
Vous voulez un reçu au format A4 ? Ouvrez la page du paiement. Sur téléphone, ouvrez d'abord le menu avec le bouton ☰. Dans le menu, cliquez sur « Transactions » sous « Finances ». Vous ne la voyez pas ? Cliquez sur « Finances » pour l'afficher. Tapez « أحمد » dans la zone de recherche. Cliquez sur le titre de son paiement.
::figure receipt-tx-index ring=nav-transactions,tx-search,tx-row-first
:::

:::step Cliquez sur le bouton « Imprimer le reçu A4 »
En haut de la page, il y a deux boutons d'impression : celui avec une imprimante pour le ticket, et celui avec une feuille pour le reçu A4. Quand vous placez la souris sur un bouton, son nom apparaît. Cliquez sur « Imprimer le reçu A4 ». Le reçu s'ouvre dans une nouvelle fenêtre.
::figure receipt-tx-show ring=tx-print-a4 zoom=tx-print-a4
:::

:::step Ouvrez « Vérifier un reçu »
Sur téléphone, ouvrez d'abord le menu avec le bouton ☰. Dans le menu, cliquez sur « Vérifier un reçu » sous « Finances ». Vous ne la voyez pas ? Cliquez sur « Finances » pour l'afficher.
::figure receipt-verify ring=nav-receipts-verify
:::

:::step Tapez le code du reçu
En bas du reçu, il y a un code QR. Sous la mention « Code de vérification », il y a 12 chiffres. Tapez-les dans la zone « Code de vérification ». Les espaces entre les chiffres ne gênent pas. Vous avez un lecteur QR ? Scannez le code avec : il écrit les chiffres tout seul.
::figure receipt-verify-code ring=receipt-code
:::

:::step Cliquez sur « Vérifier »
Le bouton est à côté de la zone. Le résultat s'affiche en dessous.
::figure receipt-verify-code ring=receipt-check zoom=receipt-check
:::

:::step Lisez le résultat
Le ticket de أحمد est « Valide » : il a été imprimé par l'application et correspond aux enregistrements. Sous « Ce reçu » : « ORIGINAL · Ticket 80 mm ». Sous « Montant sur le reçu » : 3 000,00 DA. Et sous « Reçus imprimés », chaque copie imprimée pour ce paiement.
::figure receipt-verify-result ring=receipt-result
:::

En haut de chaque reçu, le mot « ORIGINAL » marque la première impression. Chaque impression suivante porte « DUPLICATA N° 2 », puis 3, et ainsi de suite. Le ticket de أحمد est l'original, donc le reçu A4 porte « DUPLICATA N° 2 ». Si vous rouvrez le même reçu dans la minute, aucune nouvelle copie n'est comptée. Sur la page du paiement, sous « Reçus imprimés », vous trouvez chaque copie et son code.

Les autres résultats :

- « Modifié après impression » : le paiement a changé après l'impression. Le tableau « Modifié depuis l'impression » montre ce qui a changé.
- Si le paiement a été corrigé après l'impression, « Annulé » ou « Modifié après impression » peut s'afficher. Imprimez un nouveau reçu.
- « Supprimé » : le paiement n'est plus dans l'application. Les informations du reçu, telles qu'imprimées, s'affichent en dessous.
- « Code inconnu » : aucun reçu n'a été imprimé avec ce code. Relisez les chiffres. S'ils sont justes, le reçu ne vient pas du club.

:::tip
Votre clavier écrit les chiffres ainsi : ١٢٣ ? Pas de souci, l'application les lit comme 123.
:::
