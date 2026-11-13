@props(['title' => 'Magnetite', 'description' => null, 'image' => null])
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $title }}</title>
@if ($description)
    <meta name="description" content="{{ $description }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Magnetite">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ url()->current() }}">
    @if ($image)
        <meta property="og:image" content="{{ url($image) }}">
        <meta name="twitter:card" content="summary_large_image">
    @endif
@endif
{{-- Apply the saved theme before first paint so a full reload does not flash the default colours.
     boot.js saves the resolved theme (colours, radius, font) under this key when it is chosen. --}}
<script>
    try {
        const v = JSON.parse(localStorage.getItem('magnetite.vars'));
        if (v) {
            const root = document.documentElement;
            for (const [k, c] of Object.entries(v.colors)) root.style.setProperty('--c-' + k, c);
            root.style.setProperty('--radius', v.radius);
            root.style.setProperty('--font-ui', v.font);
            if (v.meterFont) root.style.setProperty('--font-meter', v.meterFont);
        }
    } catch {}
</script>
@php
    $user = auth()->user();
    $boot = ['signedIn' => auth()->check(), 'userId' => auth()->id(), 'meters' => $user?->meters, 'ui' => $user?->ui,
        'uploads' => $user ? ['url' => route('upload.store'), 'scanUrl' => route('scan.show'), 'maxMb' => config('magnetite.upload.max_zip_mb')] : null];
@endphp
{{-- What the player needs to know about the person: the meters they chose and their interface choices (null: they never did). Read once, when the player starts. --}}
<script data-navigate-once>
    window.magnetite = @json($boot);
</script>
@vite(['resources/css/app.css', 'resources/js/app.js'])
@livewireScriptConfig
