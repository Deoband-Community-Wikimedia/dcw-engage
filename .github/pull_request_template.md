<!--
Thanks for contributing to DCW Engage! Fill in what's relevant and delete what isn't.

Found a SECURITY vulnerability? Please report it privately instead:
see SECURITY.md.
-->

## What does this change?

<!-- A couple of sentences in plain language. What was wrong or missing, and what
     does DCW Engage do differently now? -->

Closes #

## How did you test it?

<!-- Be specific. "Saved a scholarship form with 3 fields, confirmed public renderer renders them in order, submitted an application and confirmed email received" tells a reviewer far more than "tested locally". -->

- [ ] Ran `php -l` on every file changed
- [ ] Tested form submission / admin action in a browser
- [ ] Checked responsive layout on mobile/small screens if touching UI

## Screenshots

<!-- Recommended for visual changes: before & after screenshots if applicable. -->

## Does this change the database?

- [ ] No, `database.sql` is untouched
- [ ] Yes — and I've included the migration / `ALTER TABLE` queries below for existing installs

```sql

```

## Security checklist

- [ ] All database queries use PDO prepared statements with bound parameters
- [ ] Every new `POST` form contains CSRF protection (`CSRF::getInputField()`) and validates it
- [ ] Every output echoed to the view is escaped with `htmlspecialchars()`
- [ ] No secrets, SMTP credentials, or real PII are committed in this diff

## Notes for maintainers / reviewers

<!-- Trade-offs made, edge cases, or parts needing extra attention. -->
