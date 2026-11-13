<x-mail::plain.notification>
<x-slot:body>
@foreach ($blueprint->lines as $line)
- {!! $line['text'] !!}
  {!! $line['url'] !!}
@endforeach
</x-slot:body>
</x-mail::plain.notification>
