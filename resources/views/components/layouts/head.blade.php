@props(['title' => 'Magnetite'])
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $title }}</title>
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
        }
    } catch {}
</script>
{{-- What the player needs to know about the person: the meters they chose (null: they never did). Read once, when the player starts. --}}
<script data-navigate-once>
    window.magnetite = @json(['signedIn' => auth()->check(), 'userId' => auth()->id(), 'meters' => auth()->user()?->meters]);
</script>
@vite(['resources/css/app.css', 'resources/js/app.js'])
@livewireScriptConfig
