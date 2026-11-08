<table style="width: 100%; margin-top: 24px;">
    <tr>
        @foreach ($data['signers'] as $signer)
            <td style="width: 50%; text-align: center; vertical-align: top; padding: 0 8px;">
                <div style="font-weight: bold;">{{ $signer['label'] }}</div>
                <div style="margin-top: 4px;">{{ $signer['name'] }}</div>
                <div style="height: 30mm;"></div>
            </td>
        @endforeach
    </tr>
</table>
