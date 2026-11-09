<?php

declare(strict_types=1);

use App\Modules\Members\Models\Member;
use App\Modules\Members\Models\MemberChange;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->timestamp('voided_at')->nullable();
            $table->unsignedInteger('voided_by_user_id')->nullable();
            $table->string('void_reason', 255)->nullable();
        });
        Schema::table('mail_sends', function (Blueprint $table): void {
            // Historical entries did not distinguish enqueueing from transport success.
            $table->string('status', 20)->default('unknown');
            $table->timestamp('submitted_at')->nullable();
        });
        DB::table('member_dues')->whereExists(function ($query): void {
            $query->selectRaw('1')->from('payment_dues')->join('payments', 'payments.id', '=', 'payment_dues.payment_id')
                ->whereColumn('payment_dues.member_due_id', 'member_dues.id')->whereNull('payments.voided_at');
        })->update(['status' => 'bezahlt']);
        Member::whereNotNull('anonymized_at')->chunkById(100, function ($members): void {
            foreach ($members as $member) {
                DB::transaction(function () use ($member): void {
                    Member::whereKey($member->id)->lockForUpdate()->firstOrFail();
                    DB::table('members')->where('id', $member->id)->update(['notiz' => null]);
                    MemberChange::where('member_id', $member->id)->each(fn (MemberChange $change) => $change->save());
                });
            }
        });
    }

    public function down(): void
    {
        if (DB::table('payments')->whereNotNull('voided_at')->exists()) {
            throw new RuntimeException('Cannot remove payment reversal metadata after a reversal. Roll forward instead.');
        }
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn(['voided_at', 'voided_by_user_id', 'void_reason']));
        Schema::table('mail_sends', fn (Blueprint $table) => $table->dropColumn(['status', 'submitted_at']));
        // Erased notes and repaired financial states cannot be reconstructed.
    }
};
