<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
facturac.ao
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
**facturac.ao** · Facturação electrónica para Angola

© {{ date('Y') }} {{ \App\Models\PlatformSetting::get('company_legal_name') }} · [{{ \App\Models\PlatformSetting::get('support_email') }}](mailto:{{ \App\Models\PlatformSetting::get('support_email') }}) · [Privacidade]({{ url('/privacidade') }})
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
