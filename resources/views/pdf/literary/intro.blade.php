<h2>{{ $l('literary.section.intro') }}</h2>
@foreach (['president_word', 'introduction'] as $field)
    @if (isset($data[$field]))
        <h3>{{ $l('literary.'.$field) }}</h3>
        <div class="text">{!! $text($data[$field]) !!}</div>
    @endif
@endforeach
