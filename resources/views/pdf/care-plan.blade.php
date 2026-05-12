@extends('pdf._layout', [
    'documentTitle' => 'Maßnahmenplan',
    'resident' => $resident,
    'generatedAt' => $generatedAt,
    'generatedBy' => $generatedBy,
])

@section('content')
    {{-- Bewohner-Karte als kompakte Tabelle --}}
    <table class="resident-card">
        <tr class="title-row">
            <td colspan="2">
                <span class="name">{{ $resident->formal_name }}</span>
                <span style="color:#54595F;font-size:9pt;margin-left:8px">({{ $resident->full_name }})</span>
            </td>
        </tr>
        <tr>
            <td class="label">Pseudonym</td>
            <td class="value">{{ $resident->pseudonym }}</td>
        </tr>
        @if($resident->birth_date)
            <tr>
                <td class="label">Geburtsdatum</td>
                <td class="value">{{ $resident->birth_date->format('d.m.Y') }}</td>
            </tr>
        @endif
        @if($resident->room_number)
            <tr>
                <td class="label">Zimmer</td>
                <td class="value">{{ $resident->room_number }}</td>
            </tr>
        @endif
        @if($location)
            <tr>
                <td class="label">Wohnbereich</td>
                <td class="value">{{ $location->name }}</td>
            </tr>
        @endif
        <tr class="last">
            <td class="label">MP-Status</td>
            <td class="value">
                Begonnen {{ $carePlan->started_at?->format('d.m.Y') ?? '—' }}
                @if($carePlan->evaluated_at)
                    · Letzte Evaluation {{ $carePlan->evaluated_at->format('d.m.Y') }}
                @endif
                @if($carePlan->next_evaluation_due)
                    · Nächste {{ $carePlan->next_evaluation_due->format('d.m.Y') }}
                @endif
            </td>
        </tr>
    </table>

    {{-- Grundbotschaft --}}
    @if(!empty($carePlan->grundbotschaft))
        <table class="grundbotschaft">
            <tr>
                <td>
                    <div class="label">Grundbotschaft</div>
                    <div class="text">{{ $carePlan->grundbotschaft }}</div>
                </td>
            </tr>
        </table>
    @endif

    {{-- Themenblöcke --}}
    <section>
        <h2 class="section-title">Themenblöcke</h2>

        @php
            $entriesByNumber = $carePlanTopics->keyBy('topic_number');
            $hasAny = $entriesByNumber->isNotEmpty();
        @endphp

        @if(!$hasAny)
            <p class="content muted">Noch keine Themenblöcke befüllt.</p>
        @else
            @foreach($topicCatalog as $topic)
                @php
                    $entry = $entriesByNumber->get($topic->value);
                @endphp
                @if($entry !== null && trim((string) $entry->content) !== '')
                    <div class="topic-block">
                        <h3 class="topic-title"><span class="num">{{ $topic->value }}.</span>{{ $topic->label() }}</h3>
                        <p class="content">{{ $entry->content }}</p>
                    </div>
                @endif
            @endforeach
        @endif
    </section>
@endsection
