@extends('pdf._layout', [
    'documentTitle' => 'Strukturierte Informationssammlung',
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
            <td class="label">SIS-Status</td>
            <td class="value">
                @if($sis->completed_at)
                    Fertiggestellt {{ $sis->completed_at->format('d.m.Y') }}
                @else
                    In Bearbeitung
                @endif
                @if($sis->evaluated_at)
                    · Letzte Evaluation {{ $sis->evaluated_at->format('d.m.Y') }}
                @endif
                @if($sis->next_evaluation_due)
                    · Nächste {{ $sis->next_evaluation_due->format('d.m.Y') }}
                @endif
            </td>
        </tr>
    </table>

    {{-- Eingangsfrage --}}
    @if(!empty($sis->opening_question))
        <section>
            <h2 class="section-title">Eingangsfrage</h2>
            <p class="content">{{ $sis->opening_question }}</p>
        </section>
    @endif

    {{-- Themenfelder --}}
    <section>
        <h2 class="section-title">Themenfelder</h2>
        @foreach($topics as $topic)
            @php
                $entry = $topicEntries->firstWhere('topic_number', $topic->value);
                $content = $entry?->content;
            @endphp
            <div class="topic-block">
                <h3 class="topic-title"><span class="num">{{ $topic->value }}.</span>{{ $topic->label() }}</h3>
                @if(!empty($content))
                    <p class="content">{{ $content }}</p>
                @else
                    <p class="content muted">— keine Eintragung —</p>
                @endif
            </div>
        @endforeach
    </section>

    {{-- Risikomatrix --}}
    <section>
        <h2 class="section-title">Risikomatrix</h2>
        <table class="risk-matrix">
            <thead>
                <tr>
                    <th>Risiko</th>
                    <th class="center">Vorhanden</th>
                    <th class="center">Weitere Einschätzung</th>
                    <th>Notizen</th>
                </tr>
            </thead>
            <tbody>
                @foreach($riskKinds as $kind)
                    @php
                        $risk = $risks->firstWhere('risk_kind', $kind->value);
                    @endphp
                    <tr>
                        <td><strong>{{ $kind->label() }}</strong></td>
                        <td class="center">
                            @if($risk && $risk->is_at_risk)
                                <span class="pill yes">Ja</span>
                            @else
                                <span class="pill no">Nein</span>
                            @endif
                        </td>
                        <td class="center">
                            @if($risk && $risk->needs_further_assessment)
                                <span class="pill yes">Ja</span>
                            @else
                                <span class="pill no">Nein</span>
                            @endif
                        </td>
                        <td>{{ $risk?->notes ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
@endsection
