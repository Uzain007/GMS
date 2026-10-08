@php
    $badgeBackground = match ($tone ?? 'neutral') {
        'success' => '#f0ecff',
        'warning' => '#fff4d6',
        'danger' => '#fde8e7',
        default => '#efedf4',
    };
    $badgeColor = match ($tone ?? 'neutral') {
        'success' => '#5e3bd0',
        'warning' => '#805c08',
        'danger' => '#a33a35',
        default => '#5f5b6b',
    };
@endphp
<span style="display:inline-block;padding:6px 10px;border-radius:999px;background:{{ $badgeBackground }};color:{{ $badgeColor }};font-size:11px;font-weight:800;letter-spacing:.7px;text-transform:uppercase;">{{ $status }}</span>
