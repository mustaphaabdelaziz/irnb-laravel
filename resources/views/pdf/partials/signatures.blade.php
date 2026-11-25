<table style="width:100%; margin-top:40px; text-align:center; font-size:12px;">
    <tr>
        <td style="width:50%; vertical-align:top;">
            <div style="font-weight:bold;">{{ $L('po.pdf.treasurer') }}</div>
            <div style="margin-top:4px;">{{ $signatories['treasurer'] ?? '' }}</div>
            @include('pdf.partials.sign-line')
        </td>
        <td style="width:50%; vertical-align:top;">
            <div style="font-weight:bold;">{{ $L('po.pdf.president') }}</div>
            <div style="margin-top:4px;">{{ $signatories['president'] ?? '' }}</div>
            @include('pdf.partials.sign-line')
        </td>
    </tr>
</table>
