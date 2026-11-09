# Member accounting and privacy maintenance

The member review fixes introduce one migration, a reconciliation command, and new
payment and annual-generation workflows. Apply the migration with the application
release before serving member requests: payment queries now exclude reversed entries.
No deployment or normal application database migration is performed by the test suite.

## Release and existing data

1. Take the installation's normal database backup and arrange the usual release window.
2. Apply pending reviewed migrations with `php artisan migrate --force` during deployment.
   `2026_11_09_000002_complete_member_accounting_and_privacy` adds reversal and mail-status
   metadata, aligns payment-backed contribution statuses, and removes notes from already
   anonymized members and their old history snapshots. The preceding identity-redaction
   migration must also be applied if still pending.
3. Restart persistent queue workers using the installation's normal procedure so they
   load the new mail outcome tracking. Previously queued mail without a tracking ID
   remains sendable; old history has status `unknown`, not an invented delivery result.
4. Inspect the dry-run report: `php artisan members:reconcile-dues`.
5. After reviewing the report, `php artisan members:reconcile-dues --apply` aligns
   payment-backed statuses and missing due dates with the configured fiscal year.
   Applied changes are recorded in member history. Payment/allocation amount mismatches
   are reported for manual review and are never silently balanced by rewriting money.

Zero amounts can be deliberate tariffs. They are reported but never automatically
replaced. For explicitly reviewed, unpaid import placeholders, use e.g.
`php artisan members:reconcile-dues --repair-zero=12,34` first, followed by the same
command with `--apply`. Only selected zero-valued, unpaid dues with a configured rate
are eligible. Paid entries require a reviewed reversal/correction first. Missing-rate
imports should be rerun after configuring tariffs; member and due creation is idempotent.

Anonymization permanently removes notes and their history copies. Do not expect rollback
to reconstruct personal data. Once a payment has been reversed, the new migration refuses
rollback that would remove reversal metadata: use a forward correction. Old application
versions do not understand reversals and must not be used against reversed payments.

## Member workflows

- The member list exposes a fiscal-year selector. Payment filters and the displayed
  contribution refer to that same year, using common paid/open/overdue semantics.
- Annual generation offers a read-only preview and an execution report for every active
  member: ready/created, existing, no category, no rate, or not eligible in the target year.
  The latest contribution strictly before the target fiscal year supplies the category;
  new members fall back to their assigned category. Future years never supply the category.
- Open the member detail and select **Record payment**. A posting must exactly cover the
  selected dues. The server locks and rechecks dues before allocation. A linked payment
  protects the due amount and status against conflicting edits across normal model writes.
- Payment history retains the original amount/date, associated years, and reversals.
  Correct a posting by supplying a reversal reason and entering the corrected payment.
  Reversal preserves the original record, records the actor and time, and reopens its dues.
  Manual paid markers remain available and are explicitly distinguished from money postings.
- Imported email addresses are validated before importing any rows. Existing invalid
  recipient addresses block sending with a concrete correction message. Mail history
  distinguishes queued, submitted to transport, failed, and unknown historical state.
  Submission to transport does not prove delivery to an inbox.
- Sender names reject four-byte characters before touching legacy options tables. Normal
  member fields retain their Unicode support.

## Regression checks

Run PHP and Composer inside WSL for this repository. Relevant checks are the `Members`
PHPUnit suite, the complete default PHPUnit suite, PHPStan, changed-file Pint,
`composer check:design`, Blade compilation, and `tests/Browser/MembersManagementTest.php`.
The browser tests cover widths 390/768/1440 and phone payment posting/reversal.

Use isolated test databases for MySQL runs. Never point `RefreshDatabase`,
`DatabaseMigrations`, or schema/charset simulation tests at an installation database.
