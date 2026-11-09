@if(session()->has('generationReport'))
    <section class="ui-card">
        <div class="ui-card-header"><h2>{{ __('members::admin.review.generation_report') }}</h2></div>
        <div class="ui-card-body tc-stack">
            @if(session('generationPreview'))<p>{{ __('members::admin.review.preview_hint') }}</p>@endif
            @foreach(collect(session('generationReport'))->countBy('reason') as $reason => $count)
                <p>{{ __('members::admin.review.reason_'.$reason) }}: {{ $count }}</p>
            @endforeach
        </div>
        <div class="ui-table-wrap" tabindex="0" role="region" aria-label="{{ __('members::admin.review.generation_report') }}">
            <table class="ui-table">
                <thead><tr><th>{{ __('members::admin.members.title') }}</th><th>{{ __('members::admin.dues.status') }}</th><th>{{ __('members::admin.payments.amount') }}</th></tr></thead>
                <tbody>
                    @foreach(session('generationReport') as $row)
                        <tr><td><a href="{{ route('members.edit', $row['member_id']) }}">{{ $row['name'] }}</a></td><td>{{ __('members::admin.review.reason_'.$row['reason']) }}</td><td>{{ $row['amount'] === null ? '—' : number_format((float) $row['amount'], 2, ',', '.').' €' }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif
