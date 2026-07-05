# Member anonymization (DSGVO)

## Problem

Departed members (`left_at` set) keep their full personal data — name, email, phone, address, and external federation ID (`nuliga_id`) — indefinitely. There is no way to fulfil a deletion/anonymization obligation (DSGVO Art. 17) for members who left long ago, short of manually editing the database. We need an admin-triggered anonymization action, gated by a configurable retention period, that clears personal data while preserving the historical records (bookings, dues, payments) needed for accounting and statistics.

CSV import, database restore, and the not-yet-built member change log are out of scope — this spec covers only the anonymization action itself.

## Design

### 1. `anonymized_at` column

Migration adds `anonymized_at` (timestamp, nullable) to `members`. Added to `Member::$fillable` and `$casts` (`datetime`).

### 2. Retention period as an admin-configurable option

Follows the existing `Option`-backed pattern in `OptionController` (see `BEHAVIOR_MAP`), not a static config file:

- New `BEHAVIOR_MAP` entry: `'members_anonymization_retention_years' => 'members.anonymization.retention_years'`.
- Rendered as a number input on the existing "Verhalten" admin config page (`admin.config.behavior.edit`).
- Default `3` (years) when the option is unset, applied the same way other numeric behavior options default (via `Option::getValue(..., default)`).

### 3. `Member::isAnonymizable(): bool`

```php
public function isAnonymizable(): bool
{
    if ($this->anonymized_at !== null || $this->left_at === null) {
        return false;
    }

    $retentionYears = (int) Option::getValue('members.anonymization.retention_years', 3);

    return $this->left_at->lt(now()->subYears($retentionYears));
}
```

### 4. `MemberController::anonymize(Member $member)`

New route `POST members/{member}/anonymize` → `members.anonymize`, inside the existing `auth` + `can:admin.members` group.

- 404/aborts with a flashed error if `! $member->isAnonymizable()`.
- Requires a typed confirmation field (`confirmation`), matching the existing pattern used for DB migrate/restore (`Js::from(...)` confirm dialog + a text input the admin must fill with the member's full name, validated server-side before proceeding).
- Updates the member:
  ```php
  $member->update([
      'firstname'   => 'Ehemaliges',
      'lastname'    => "Mitglied #{$member->member_number}",
      'email'       => null,
      'phone'       => null,
      'street'      => null,
      'zip'         => null,
      'city'        => null,
      'nuliga_id'   => null,
      'anonymized_at' => now(),
  ]);
  ```
- `birthdate`, `gender`, `member_number`, `joined_at`, `left_at`, `fee_category_id`, and all `dues`/`payments` relations are left untouched.
- Redirects back to the member list/detail with a flashed success message.

### 5. UI changes

`app/Modules/Members/resources/views/members/edit.blade.php` (and the members index row actions, wherever the departed-member actions already live):

- If `$member->isAnonymizable()`: show an "Anonymisieren" button opening a confirmation form (typed-name pattern, same UX as DB restore) posting to `members.anonymize`.
- If `$member->anonymized_at` is set: show "Anonymisiert am {date}" instead of the button, and disable/hide the firstname/lastname/email/phone/street/zip/city/nuliga_id inputs on the edit form (render as read-only text) so an admin can't accidentally repopulate PII on an anonymized record.
- Neither state affects members that are still active (`left_at === null`) or departed-but-within-retention — no button, no message, form behaves as today.

### 6. Tests

- `Member::isAnonymizable()`: false when active, false when already anonymized, false when departed but within retention, true when departed past retention; respects a custom `members.anonymization.retention_years` option value.
- Feature test: `POST members/{member}/anonymize` requires `can:admin.members`.
- Feature test: anonymizing a member not yet eligible (`isAnonymizable() === false`) is rejected (422/403 — no fields change).
- Feature test: anonymizing an eligible member clears the expected fields, sets `anonymized_at`, and leaves `member_number`/`birthdate`/`gender`/`joined_at`/`left_at`/`fee_category_id`/`dues`/`payments` unchanged.
- Feature test: the edit page shows the "Anonymisieren" button only when eligible, and shows the anonymized-state message with disabled PII fields after anonymization.
