<?php

declare(strict_types=1);

namespace App\Modules\Members\Http\Controllers;

use App\Modules\Members\Models\Member;
use App\Modules\Members\Models\MemberChange;
use App\Modules\Members\Models\MemberDue;
use App\Modules\Members\Models\Payment;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentController
{
    public function create(Member $member): View
    {
        $openDues = $member->dues()
            ->open()
            ->with(['fiscalYear', 'feeCategory'])
            ->get();

        return view('members::payments.create', ['member' => $member, 'openDues' => $openDues]);
    }

    public function void(Request $request, Member $member, Payment $payment): RedirectResponse
    {
        abort_unless((int) $payment->member_id === $member->id, 404);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        DB::transaction(function () use ($member, $payment, $validated): void {
            // Match the posting lock order: dues first, then payment.
            $dues = $payment->dues()->orderBy('member_dues.id')->lockForUpdate()->get();
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->voided_at !== null) {
                return;
            }
            $locked->update(['voided_at' => now(), 'voided_by_user_id' => auth()->id(), 'void_reason' => $validated['reason']]);
            foreach ($dues as $due) {
                if (! $due->payments()->exists()) {
                    $due->update(['status' => 'ausstehend', 'bezahlt_bis_fiscal_year_id' => null]);
                }
            }
            MemberChange::create(['member_id' => $member->id, 'admin_user_id' => auth()->id(), 'action' => 'updated',
                'before' => ['payment' => '#'.$locked->id.' / '.$locked->amount],
                'after' => ['payment' => '#'.$locked->id.' / '.__('members::admin.payments.voided').' / '.$validated['reason']]]);
        });

        return redirect()->route('members.edit', $member)->with('status', __('members::admin.payments.voided'));
    }

    public function store(Request $request, Member $member): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999.99'],
            'paid_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
            'member_due_ids' => ['required', 'array', 'min:1'],
            'member_due_ids.*' => [
                'integer',
                'distinct',
                'exists:member_dues,id,member_id,'.$member->id,
                function (string $attribute, mixed $value, callable $fail): void {
                    $isAlreadyPaid = MemberDue::query()
                        ->whereKey($value)
                        ->paid()
                        ->exists();

                    if ($isAlreadyPaid) {
                        $fail(__('members::admin.payments.due_already_paid'));
                    }
                },
            ],
        ]);

        $dueIds = array_map(intval(...), $validated['member_due_ids']);

        // The payment must cover the selected dues exactly - otherwise a partial
        // payment would mark them as paid and block the remainder forever.
        $expectedCents = (int) MemberDue::query()
            ->whereKey($dueIds)
            ->get()
            ->sum(fn (MemberDue $due): int => (int) round(((float) $due->amount) * 100));
        $paidCents = (int) round(((float) $validated['amount']) * 100);

        if ($paidCents !== $expectedCents) {
            throw ValidationException::withMessages([
                'amount' => __('members::admin.payments.amount_mismatch', [
                    'sum' => number_format($expectedCents / 100, 2, ',', '.'),
                ]),
            ]);
        }

        DB::transaction(function () use ($validated, $member, $dueIds): void {
            // Re-check inside the transaction (with the due rows locked) so two
            // concurrent submissions cannot allocate the same due twice.
            $dues = MemberDue::whereKey($dueIds)->where('member_id', $member->id)->orderBy('id')->lockForUpdate()->get();
            if ($dues->count() !== count($dueIds) || $dues->contains(fn (MemberDue $due): bool => $due->isPaid())) {
                throw ValidationException::withMessages(['member_due_ids' => __('members::admin.payments.due_already_paid')]);
            }
            $expected = $dues->sum(fn (MemberDue $due): int => (int) round((float) $due->amount * 100));
            if ($expected !== (int) round((float) $validated['amount'] * 100)) {
                throw ValidationException::withMessages(['amount' => __('members::admin.payments.amount_mismatch', ['sum' => number_format($expected / 100, 2, ',', '.')])]);
            }

            $payment = Payment::create([
                'member_id' => $member->id,
                'amount' => $validated['amount'],
                'paid_at' => $validated['paid_at'],
                'note' => $validated['note'] ?? null,
            ]);

            $payment->dues()->attach($dueIds);

            MemberDue::whereKey($dueIds)->update(['status' => 'bezahlt']);
            MemberChange::create(['member_id' => $member->id, 'admin_user_id' => auth()->id(), 'action' => 'updated',
                'before' => ['payment' => null], 'after' => ['payment' => '#'.$payment->id.' / '.$payment->amount.' / '.$payment->paid_at->toDateString()]]);
        });

        return redirect()->route('members.edit', $member)
            ->with('status', __('members::admin.payments.created'));
    }
}
