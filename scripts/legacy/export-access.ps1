<#
  Exports the old MS Access app's tables to the irnb-access-v1 JSON that
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

$connection.Close()

$document = [ordered]@{
    format = 'irnb-access-v1'
    exported_at = (Get-Date).ToString('yyyy-MM-ddTHH:mm:ss')
    suppliers = @($suppliers.Values)
    payment_orders = @($orders)
}
$json = $document | ConvertTo-Json -Depth 5
[System.IO.File]::WriteAllText($Out, $json, (New-Object System.Text.UTF8Encoding($false)))
Write-Output ("Wrote {0} suppliers, {1} payment orders to {2}" -f $suppliers.Count, $orders.Count, $Out)
