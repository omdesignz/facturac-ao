{{--
    What lets a reader check the paper against the record: the validated
    program notice the AGT requires, the signature digest, and the AGT
    verification QR. The QR is the specified 350 × 350 PNG, placed at a fixed
    30 × 30 mm so it prints square at the same size on every document.
--}}
<table class="authenticity">
    <tr>
        <td style="vertical-align: bottom; font-size: 7pt; padding-right: 10pt;" class="muted">
            @if ($document['authenticity']['software_validation_number'])
                <div>Processado por programa validado n.º
                    {{ $document['authenticity']['software_validation_number'] }}</div>
            @endif
            @if ($document['authenticity']['digest'])
                <div>Documento <span class="bold">{{ $document['authenticity']['digest'] }}</span>
                    — confirme com o emissor em caso de dúvida.</div>
            @endif
            @if ($document['support_email'])
                <div>{{ $document['support_email'] }}</div>
            @endif
        </td>
        <td style="width: 32mm; vertical-align: bottom; text-align: right;">
            @if ($qrImage)
                <img src="{{ $qrImage }}" class="qr" width="30mm" height="30mm" alt="QR de verificação AGT">
                <div class="center muted" style="font-size: 6.5pt;">Verificar na AGT</div>
            @endif
        </td>
    </tr>
</table>
