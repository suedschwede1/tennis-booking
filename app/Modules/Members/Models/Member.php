<?php

declare(strict_types=1);

namespace App\Modules\Members\Models;

use App\Models\Option;
use App\Models\User;
use Carbon\Carbon;
use Database\Factories\Modules\Members\Models\MemberFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * @property int $id
 * @property int $member_number
 * @property int|null $nuliga_id
 * @property int|null $user_id
 * @property string $firstname
 * @property string $lastname
 * @property \Illuminate\Support\Carbon|null $birthdate
 * @property string|null $gender
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $street
 * @property string|null $zip
 * @property string|null $city
 * @property int|null $fee_category_id
 * @property \Illuminate\Support\Carbon $joined_at
 * @property \Illuminate\Support\Carbon|null $left_at
 * @property \Illuminate\Support\Carbon|null $anonymized_at
 * @property string $status
 * @property string|null $notiz
 * @property-read FeeCategory|null $feeCategory
 *
 * @method static Builder<static> active()
 */
class Member extends Model
{
    use HasFactory;

    public const GENDERS = ['m', 'w', 'd'];

    public const STATUSES = ['aktiv', 'ruhend', 'ausgetreten'];

    /** Fields removed by anonymization; none may be restored by ordinary updates. */
    public const ANONYMIZED_FIELDS = ['firstname', 'lastname', 'email', 'phone', 'street', 'zip', 'city', 'nuliga_id', 'user_id'];

    protected static function booted(): void
    {
        static::updated(function (self $member): void {
            if ($member->wasChanged('anonymized_at') && $member->anonymized_at !== null) {
                // Scrub every old copy, while preserving the audit event itself.
                MemberChange::where('member_id', $member->id)->each(function (MemberChange $change): void {
                    $change->save();
                });
            }
        });
    }

    public function save(array $options = []): bool
    {
        if (! $this->exists) {
            return parent::save($options);
        }

        return $this->getConnection()->transaction(function () use ($options): bool {
            // Route binding and importers may hold an instance loaded before
            // another request anonymized it. Serialize against that transition.
            $stored = static::query()->whereKey($this->getKey())->lockForUpdate()->first();
            if ($stored?->anonymized_at !== null
                && ($this->isDirty(self::ANONYMIZED_FIELDS) || $this->isDirty('anonymized_at'))) {
                throw ValidationException::withMessages([
                    'member' => __('members::admin.members.anonymize_field_locked'),
                ]);
            }

            return parent::save($options);
        });
    }

    protected $fillable = [
        'member_number', 'nuliga_id', 'user_id', 'firstname', 'lastname', 'birthdate', 'gender',
        'email', 'phone', 'street', 'zip', 'city', 'fee_category_id', 'joined_at', 'left_at', 'status',
        'anonymized_at', 'notiz',
    ];

    protected $casts = [
        'birthdate' => 'date',
        'joined_at' => 'date',
        'left_at' => 'date',
        'anonymized_at' => 'datetime',
    ];

    protected static function newFactory(): MemberFactory
    {
        return MemberFactory::new();
    }

    /** @return HasMany<MemberDue, $this> */
    public function dues(): HasMany
    {
        return $this->hasMany(MemberDue::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return BelongsTo<FeeCategory, $this> */
    public function feeCategory(): BelongsTo
    {
        return $this->belongsTo(FeeCategory::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'uid');
    }

    /** @return BelongsToMany<ClubRole, $this> */
    public function clubRoles(): BelongsToMany
    {
        return $this->belongsToMany(ClubRole::class, 'member_club_role');
    }

    public function age(): ?int
    {
        return $this->birthdate?->age;
    }

    /** @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'aktiv');
    }

    public function fullName(): string
    {
        return trim("{$this->firstname} {$this->lastname}");
    }

    /** Next free member_number, for pre-filling the create form (max existing + 1, or 1 if none exist). */
    public static function nextNumber(): int
    {
        return (int) (static::query()->max('member_number') ?? 0) + 1;
    }

    public function paidUntil(): ?Carbon
    {
        return $this->dues()
            ->get()
            ->filter(fn (MemberDue $due) => $due->isPaid())
            ->map(fn (MemberDue $due) => $due->fiscalYear->ends_on)
            ->sort()
            ->last();
    }

    /** Setzt das Austrittsdatum und leitet daraus automatisch den Status ab: gesetzt = ausgetreten, geleert = aktiv. */
    public function setLeftAt(?string $date): void
    {
        if ($date === $this->left_at?->toDateString()) {
            return;
        }
        $this->update([
            'left_at' => $date,
            'status' => $date === null ? 'aktiv' : 'ausgetreten',
        ]);
    }

    /** Setzt den Status manuell (nur 'aktiv' oder 'ruhend' — 'ausgetreten' läuft ausschließlich über setLeftAt()). Zurück auf 'aktiv' löscht ein gesetztes Austrittsdatum. */
    public function setStatus(string $status): void
    {
        $this->update([
            'status' => $status,
            'left_at' => $status === 'aktiv' ? null : $this->left_at,
        ]);
    }

    /** DSGVO: ein ausgetretenes Mitglied darf erst nach Ablauf der Aufbewahrungsfrist anonymisiert werden. */
    public function isAnonymizable(): bool
    {
        if ($this->anonymized_at !== null || $this->status !== 'ausgetreten' || $this->left_at === null) {
            return false;
        }

        $rawRetention = Option::getValue('members.anonymization.retention_years', 3);
        $retentionYears = is_numeric($rawRetention) && (int) $rawRetention > 0 ? (int) $rawRetention : 3;

        return $this->left_at->lt(now()->subYears($retentionYears));
    }
}
