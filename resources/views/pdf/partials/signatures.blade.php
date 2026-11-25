<table style="width:100%; margin-top:40px; text-align:center; font-size:12px;">
    <tr>
        <td style="width:50%;">
            <div style="font-weight:bold;">{{ $L('po.pdf.treasurer') }}</div>
            <div style="margin-top:4px;">{{ $signatories['treasurer'] ?? '' }}</div>
            <div style="margin-top:50px; border-top:1px solid #94a3b8; width:70%; margin-left:auto; margin-right:auto;"></div>
        </td>
        <td style="width:50%;">
            <div style="font-weight:bold;">{{ $L('po.pdf.president') }}</div>
            <div style="margin-top:4px;">{{ $signatories['president'] ?? '' }}</div>
            <div style="margin-top:50px; border-top:1px solid #94a3b8; width:70%; margin-left:auto; margin-right:auto;"></div>
        </td>
    </tr>
</table>
