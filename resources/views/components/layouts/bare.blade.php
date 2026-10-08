<!DOCTYPE html>
<html lang="en">
<head>
    <x-layouts.head :title="$title ?? 'Magnetite'" />
</head>
<body class="flex min-h-screen items-center justify-center bg-bg px-4 py-10 text-text antialiased">
    {{ $slot }}
</body>
</html>
