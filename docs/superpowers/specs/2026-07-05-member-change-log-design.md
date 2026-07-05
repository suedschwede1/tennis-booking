# Member change log

## Problem

Changes to `Member` records (name, contact details, fee category, joined/departed dates, etc.) currently leave no trace of who changed what and when. If a board member's data looks wrong, there's no way to find out when it changed or who changed it. We need an audit trail for manual edits made through the Members admin UI.

CSV import and database restore are explicitly **out of scope** — those are bulk/recovery operations, not the day-to-day edits this log is meant to make accountable.

## Design

### 1. `member_changes` table

```
member_changes
  id
  member_id      FK -> members, cascadeOnDelete
  admin_user_id  FK -> bs_users.uid, cascadeOnDelete
  action         string(16): 'created' | 'updated' | 'departed'
  before         json, nullable (null for 'created')
  after          json
  created_at     timestamp
```

No `updated_at` — log rows are write-once and never modified. `before`/`after` store the member's full attribute snapshot (`Member::toArray()`) rather than a per-field diff; the changed fields are derived at display time by comparing the two JSON blobs. This keeps the write path simple (one row per save, regardless of how many fields changed) and still supports "what changed" queries by diffing in PHP.

### 2. `MemberChange` model

`app/Modules/Members/Models/MemberChange.php`:
- `belongsTo(Member::class)`
- `belongsTo(User::class, 'admin_user_id', 'uid')`
- `changedFields(): array` — returns `[['field' => string, 'old' => mixed, 'new' => mixed], ...]` for keys present in both `before` and `after` whose values differ, excluding `id`, `created_at`, and `updated_at`. Returns `[]` when `before` is null (a 'created' row has nothing to diff — the view renders it as "record created" instead).

### 3. Recording changes in `MemberController`

Logging happens explicitly in the controller, not via a global Eloquent observer — this is what keeps `ImportMembersCommand` and `DatabaseBackupService::restore()` (both bypass the controller) out of the log without any extra suppression logic.

A private helper on `MemberController`:

```php
private function logChange(Member $member, string $action, ?array $before): void
{
    MemberChange::create([
        'member_id' => $member->id,
        'admin_user_id' => auth()->id(),
        'action' => $action,
        'before' => $before,
        'after' => $member->fresh()->toArray(),
    ]);
}
```

Called from each mutating action:
- `store()`: after `Member::create($validated)`, call `logChange($member, 'created', null)`.
- `update()`: capture `$before = $member->toArray()` **before** calling `$member->update($validated)`, then call `logChange($member, 'updated', $before)`.
- `updateField()`: same pattern as `update()` — snapshot before, mutate, log as `'updated'`. The log still records the full record snapshot even though only one field changed; `changedFields()` will correctly show just that one field changed.
- `destroy()`: capture `$before = $member->toArray()` before setting `left_at`, then call `logChange($member, 'departed', $before)`.

All four actions already run behind the existing `auth` + `can:admin.members` middleware, so `auth()->id()` is always populated.

### 4. History section on the member edit page

`app/Modules/Members/resources/views/members/edit.blade.php` gets a new "Änderungshistorie" card below the edit form, fed by `MemberChange::where('member_id', $member->id)->with('adminUser')->latest()->get()` (added to `MemberController::edit()`'s view data).

Each row shows: timestamp, `adminUser->alias`, and either "Angelegt" (for `created`) or the list of changed fields as `Feldname: alter Wert → neuer Wert` (for `updated`/`departed`), using `changedFields()`. Field keys are mapped to their existing German labels from `members::admin.members.*` lang keys where one exists (e.g. `firstname` → "Vorname"), falling back to the raw key otherwise.

## Tests

- `MemberChange::changedFields()`: returns the changed keys for two differing snapshots, empty array when `before` is null, excludes `id`/timestamps.
- Feature test: creating a member writes one `member_changes` row with `action='created'`, `before=null`.
- Feature test: updating a member (both `update()` and `updateField()`) writes a row with `action='updated'` and the correct `before`/`after` snapshots.
- Feature test: marking a member departed writes a row with `action='departed'`.
- Feature test: `ImportMembersCommand` does **not** write any `member_changes` rows.
- Feature test: the edit page shows the history section with the expected changed-field text for a member with prior changes.
