{{-- Card and board titles are people's own words: escaped, never through Markdown. --}}
<x-mail::html.notification>
    <x-slot:body>
        <ul style="padding-left: 18px; margin: 0;">
            @foreach ($blueprint->lines as $line)
                <li style="margin: 0 0 10px;"><a href="{{ $line['url'] }}">{{ $line['text'] }}</a></li>
            @endforeach
        </ul>
    </x-slot:body>
</x-mail::html.notification>
