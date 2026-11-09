<section class="ui-card">
    <div class="ui-card-header"><h2>{{ __('members::admin.payments.title') }}</h2></div>
    <div class="ui-card-body ui-stack">
        <p>{{ __('members::admin.payments.manual_hint') }}</p>
        @forelse($payments as $payment)
            <article class="tc-stack">
                <p>#{{ $payment->id }} · {{ $payment->paid_at->format('d.m.Y') }} · {{ number_format((float) $payment->amount, 2, ',', '.') }} €</p>
                <p>{{ $payment->dues->map(fn ($due) => $due->fiscalYear->name)->implode(', ') }}</p>
                @if($payment->note)<p>{{ $payment->note }}</p>@endif
                @if($payment->voided_at)
                    <p>{{ __('members::admin.payments.voided') }} · {{ $payment->voided_at->format('d.m.Y H:i') }} · {{ $payment->void_reason }}</p>
                @else
                    <form method="POST" action="{{ route('members.payments.void', [$member, $payment]) }}" class="tc-row">
                        @csrf
                        <div class="tc-field">
                            <label class="tc-label" for="void-{{ $payment->id }}">{{ __('members::admin.payments.void_reason') }}</label>
                            <input class="tc-input" id="void-{{ $payment->id }}" name="reason" required maxlength="255">
                        </div>
                        <x-ui.button type="submit" variant="danger">{{ __('members::admin.payments.void') }}</x-ui.button>
                    </form>
                @endif
            </article>
        @empty
            <p>{{ __('members::admin.payments.empty') }}</p>
        @endforelse
    </div>
</section>
