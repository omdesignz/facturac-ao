{{--
    What lets a reader check the paper against the record: the validated
    program notice the AGT requires, the signature digest, and a QR that
    reopens the document itself.
--}}
<table style="width: 100%; border-collapse: collapse;">
    <tr>
        <td style="vertical-align: bottom; font-size: 7pt;" class="muted">
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
        <td style="width: 90pt; vertical-align: bottom; text-align: right;">
            @if ($qrImage)
                <img src="{{ $qrImage }}" style="width: 70pt; height: 70pt;" alt="">
                <div class="center muted" style="font-size: 6.5pt;">Verificar</div>
            @endif
        </td>
    </tr>
</table>
