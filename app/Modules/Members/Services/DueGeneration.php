<?php

declare(strict_types=1);

namespace App\Modules\Members\Services;

use App\Modules\Members\Models\FeeCategoryRate;
use App\Modules\Members\Models\FiscalYear;
use App\Modules\Members\Models\Member;
use App\Modules\Members\Models\MemberDue;
use Illuminate\Support\Facades\DB;

class DueGeneration
{
    /** The same decision is used for preview and execution; execution rechecks locked members. */
    public function run(FiscalYear $year, bool $apply = false): array
    {
        $rows = [];
        Member::active()->orderBy('id')->each(function (Member $candidate) use ($year, $apply, &$rows): void {
            $rows[] = DB::transaction(function () use ($candidate, $year, $apply): array {
                $member = Member::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                $row = ['member_id' => $member->id, 'name' => $member->fullName(), 'reason' => 'ready', 'amount' => null];
                if ($member->status !== 'aktiv' || $member->joined_at->gt($year->ends_on)) {
                    return [...$row, 'reason' => 'not_eligible'];
                }
                if ($member->dues()->where('fiscal_year_id', $year->id)->exists()) {
                    return [...$row, 'reason' => 'existing'];
                }
                $previous = $member->dues()->join('fiscal_years', 'fiscal_years.id', '=', 'member_dues.fiscal_year_id')
                    ->where('fiscal_years.starts_on', '<', $year->starts_on->toDateString())
                    ->orderByDesc('fiscal_years.starts_on')->select('member_dues.*')->first();
                $categoryId = $previous->fee_category_id ?? $member->fee_category_id;
                if ($categoryId === null) {
                    return [...$row, 'reason' => 'no_category'];
                }
                $rate = FeeCategoryRate::where('fee_category_id', $categoryId)->where('fiscal_year_id', $year->id)->first();
                if ($rate === null) {
                    return [...$row, 'reason' => 'no_rate'];
                }
                if ($apply) {
                    MemberDue::create(['member_id' => $member->id, 'fiscal_year_id' => $year->id,
                        'fee_category_id' => $categoryId, 'amount' => $rate->amount, 'status' => 'ausstehend',
                        'faellig_am' => $year->faellig_am ?? $year->starts_on]);
                }

                return [...$row, 'amount' => $rate->amount, 'reason' => $apply ? 'created' : 'ready'];
            });
        });

        return $rows;
    }
}
