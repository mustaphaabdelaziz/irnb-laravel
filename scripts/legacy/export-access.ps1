<#
  Exports the old MS Access app's tables to the access-legacy-v1 JSON that
  Settings → Old software import reads. Needs the Microsoft ACE OLEDB 16.0
  provider (64-bit). The output holds names and bank numbers: keep it out of git.

  Usage: powershell -File scripts/legacy/export-access.ps1 -Database "<path>.accdb" -Out "D:\BACKUPS\irnb-access-legacy.json"
#>
param(
    [Parameter(Mandatory = $true)][string]$Database,
    [Parameter(Mandatory = $true)][string]$Out
)

$ErrorActionPreference = 'Stop'
$connection = New-Object System.Data.OleDb.OleDbConnection("Provider=Microsoft.ACE.OLEDB.16.0;Data Source=$Database;Mode=Read")
$connection.Open()

function Read-Table([string]$sql) {
    $command = $connection.CreateCommand()
    $command.CommandText = $sql
    $adapter = New-Object System.Data.OleDb.OleDbDataAdapter($command)
    $table = New-Object System.Data.DataTable
    [void]$adapter.Fill($table)
    return ,$table
}
function Get-Text($value) { if ($value -is [DBNull] -or $null -eq $value) { '' } else { ("$value").Trim() } }
function Get-Day($value) { if ($value -is [DateTime]) { $value.ToString('yyyy-MM-dd') } else { $null } }
function Get-Amount($value) { if ($value -is [DBNull] -or $null -eq $value) { 0 } else { [double]$value } }
# Dates the old app kept as text: exported as typed (the importer reads them day first); a real date as yyyy-MM-dd.
function Get-DayText($value) { if ($value -is [DateTime]) { $value.ToString('yyyy-MM-dd') } else { Get-Text $value } }

$suppliers = @{}
$orders = New-Object System.Collections.ArrayList

foreach ($row in (Read-Table 'SELECT * FROM [Fonctionnement]').Rows) {
    $name = Get-Text $row['Fournisseur']
    if ($name -ne '' -and -not $suppliers.ContainsKey($name.ToLower())) {
        $suppliers[$name.ToLower()] = [ordered]@{ name = $name; bank = (Get-Text $row['BANQUE F']); rib = (Get-Text $row['N° COMPTE (RIB) F']) }
    }
    [void]$orders.Add([ordered]@{
        source = 'transfer'; reference = (Get-Text $row['N°1']); date = (Get-Day $row['Date'])
        subject = (Get-Text $row['Objet de La Dèpense:']); amount = (Get-Amount $row['MONTANT'])
        beneficiary = $name; bank = (Get-Text $row['BANQUE F']); rib = (Get-Text $row['N° COMPTE (RIB) F'])
        payment_reference = (Get-Text $row['N° virement']); nature = (Get-Text $row['Nature3'])
    })
}

foreach ($row in (Read-Table 'SELECT * FROM [order de versement]').Rows) {
    $name = Get-Text $row['Fournisseur']
    [void]$orders.Add([ordered]@{
        source = 'cash'; reference = (Get-Text $row['N°1']); date = (Get-Day $row['Date'])
        subject = (Get-Text $row['Objet de La Dèpense:']); amount = (Get-Amount $row['MONTANT'])
        beneficiary = $name; bank = ''; rib = ''
        payment_reference = (Get-Text $row['N° versement']); nature = (Get-Text $row['Nature3'])
    })
}

foreach ($row in (Read-Table 'SELECT * FROM [الامر بالدفع]').Rows) {
    [void]$orders.Add([ordered]@{
        source = 'payment'; reference = (Get-Text $row['رقم الأمر بالدفع']); date = (Get-Day $row['تاريخ'])
        subject = (Get-Text $row['موضوع النفقة']); amount = (Get-Amount $row['مبلغ النفقة'])
        beneficiary = (Get-Text $row['المورد']); bank = (Get-Text $row['البنك']); rib = (Get-Text $row['رقم  البنك البنكي'])
        payment_reference = (Get-Text $row['رقم الشيك']); nature = (Get-Text $row['ملاحظات'])
    })
}

# Optional: the old purchase orders ("bon de commande"). One row per ordered
# item, grouped by the order reference N°1 ("01/2021"). A database without the
# table still exports (the importer treats the section as optional).
$purchaseOrders = [ordered]@{}
try {
    $purchaseRows = (Read-Table 'SELECT * FROM [bon de commande]').Rows
} catch {
    $purchaseRows = @()
    Write-Warning 'No [bon de commande] table: purchase orders skipped'
}
foreach ($row in $purchaseRows) {
    $reference = Get-Text $row['N°1']
    if ($reference -eq '') { $reference = Get-Text $row['N°'] }
    if ($reference -eq '') { continue }
    if (-not $purchaseOrders.Contains($reference)) {
        $purchaseOrders[$reference] = [ordered]@{
            reference = $reference; date = (Get-Day $row['Date'])
            supplier = (Get-Text $row['Fournisseur']); address = (Get-Text $row['Adresse'])
            requested_by = (Get-Text $row['service']); delivery_mode = (Get-Text $row['Mode de livraison'])
            payment_mode = (Get-Text $row['mode de paiement']); order_number = (Get-Text $row['N° Ordre'])
            observation = (Get-Text $row['Observation'])
            lines = (New-Object System.Collections.ArrayList)
        }
    }
    $price = Get-Amount $row['PU']
    [void]$purchaseOrders[$reference].lines.Add([ordered]@{
        designation = (Get-Text $row['Désignation']); reference = (Get-Text $row['Ref'])
        quantity = (Get-Amount $row['Quantité'])
        unit_price = $(if ($price -gt 0) { $price } else { $null })
    })
}

# Optional: the old outgoing mail register (Corresponds): reference text
# ("YYYY/NN"), recipient, "address" (often the status «طبعت» = printed, moved
# to the notes by the importer) and date (often empty). The N° column is the
# Access row id and is not exported. A database without the table still exports.
$mail = New-Object System.Collections.ArrayList
try {
    $mailRows = (Read-Table 'SELECT * FROM [Corresponds]').Rows
} catch {
    $mailRows = @()
    Write-Warning 'No [Corresponds] table: mail skipped'
}
foreach ($row in $mailRows) {
    [void]$mail.Add([ordered]@{
        reference = (Get-Text $row['المرجع']); correspondent = (Get-Text $row['الى السيد:'])
        address = (Get-Text $row['العنوان']); date = (Get-Day $row['التاريخ'])
    })
}

# Optional: the old mission orders (Ordre de Mission), one traveler per row.
# Every column but N° is text in Access, dates included. The N° column is the
# Access row id and is not exported. A database without the table still exports.
$missions = New-Object System.Collections.ArrayList
try {
    $missionRows = (Read-Table 'SELECT * FROM [Ordre de Mission]').Rows
} catch {
    $missionRows = @()
    Write-Warning 'No [Ordre de Mission] table: missions skipped'
}
foreach ($row in $missionRows) {
    [void]$missions.Add([ordered]@{
        'reference' = (Get-Text $row['Réf']); 'date' = (Get-DayText $row['Date'])
        'lastname' = (Get-Text $row['Nom']); 'firstname' = (Get-Text $row['Prénom']); 'function' = (Get-Text $row['Fonction'])
        'destination' = (Get-Text $row['Départ Vers']); 'reason' = (Get-Text $row['السبب'])
        'departure_date' = (Get-DayText $row['Date Départ']); 'return_date' = (Get-DayText $row['Date Retour'])
        'transport' = (Get-Text $row['Moyen Transport'])
    })
}

$connection.Close()

$document = [ordered]@{
    format = 'access-legacy-v1'
    exported_at = (Get-Date).ToString('yyyy-MM-ddTHH:mm:ss')
    suppliers = @($suppliers.Values)
    payment_orders = @($orders)
    purchase_orders = @($purchaseOrders.Values)
    mail = @($mail)
    missions = @($missions)
}
$json = $document | ConvertTo-Json -Depth 6
[System.IO.File]::WriteAllText($Out, $json, (New-Object System.Text.UTF8Encoding($false)))
Write-Output ("Wrote {0} suppliers, {1} payment orders, {2} purchase orders, {3} letters, {4} mission orders to {5}" -f $suppliers.Count, $orders.Count, $purchaseOrders.Count, $mail.Count, $missions.Count, $Out)
