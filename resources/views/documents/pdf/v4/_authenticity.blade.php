{{--
    What lets a reader check the paper against the record: the validated
    program notice the AGT requires, the signature digest, and the AGT
    verification QR, the specified 350 × 350 PNG at a fixed 30 × 30 mm.
--}}
<table class="authenticity">
    <tr>
        <td style="vertical-align: bottom; padding-right: 14pt; font-size: 8pt; line-height: 1.55;" class="muted">
            @if ($document['support_email'])
                <div>{{ $document['support_email'] }}</div>
            @endif
        </td>
        <td style="width: 32mm; vertical-align: bottom; text-align: right;">
            @if ($qrImage)
                <img src="{{ $qrImage }}" class="qr" width="30mm" height="30mm" alt="QR de verificação AGT">
                <div class="label center" style="margin-top: 2pt;">Verificar na AGT</div>
            @endif
        </td>
    </tr>
</table>
