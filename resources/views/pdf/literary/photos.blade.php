<h2>{{ $l('literary.section.photos') }}</h2>
@foreach ($data['chapters'] as $chapter)
    @if (! $loop->first)
        <pagebreak />
    @endif
    <div style="text-align: center; margin: 20px 0 14px;">
        <div style="font-size: 30px; font-weight: bold; color: #02a85c;"><bdi dir="ltr">{{ $chapter['number'] }}</bdi></div>
        <div style="font-size: 18px; font-weight: bold; color: #0f172a;">{{ $chapter['type'] }}</div>
    </div>
    @foreach ($chapter['photos'] as $photo)
        <div style="page-break-inside: avoid; text-align: center; margin: 0 0 12px;">
            <img src="{{ $photo['file'] }}" style="max-width: 100%; max-height: 110mm;">
            <div style="font-size: 11px; margin-top: 3px;">{{ $photo['caption'] }}</div>
        </div>
    @endforeach
@endforeach
