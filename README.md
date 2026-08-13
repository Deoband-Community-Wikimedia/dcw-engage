# DCW Engage Portal

Welcome to the DCW Engage Portal, the central hub for applications, scholarships, fellowships, internships, and volunteer opportunities at the Deoband Community Wikimedia (DCW).

## Mission
To provide a secure, scalable, and seamless dynamic form experience for users seeking to engage with DCW initiatives. As an open-source platform maintained by global contributors, **security and data privacy are our highest priorities**.

## Technology Stack
- **Backend:** Vanilla PHP 8.x
- **Database:** MySQL/MariaDB (via PDO exclusively)
- **Frontend:** HTML5, Vanilla CSS3, Vanilla JS (Zero-build pipeline)

## Local Development Setup

1. **Clone the repository:**
   ```bash
   git clone https://github.com/Deoband-Community-Wikimedia/dcw-engage.git
   cd dcw-engage
   ```

2. **Database Setup:**
   Import the `database.sql` file into your local MySQL/MariaDB server.

3. **Configuration:**
   Duplicate the `config.example.php` to `config.php` (this is `.gitignore`'d for security) and update your database credentials. *Never commit your local `config.php` or `.env` files.*

4. **Serve:**
   You can use PHP's built-in server for quick testing:
   ```bash
   php -S localhost:8000
   ```

5. **Create the first organizer account:**
   No accounts are seeded by `database.sql` — a committed password hash is a
   password everybody has. Create the first one from the terminal:
   ```bash
   php bin/create_admin.php
   ```
   Accounts made this way are **owners**, because running the script already
   requires shell access. Everyone after that should be added through the
   workspace instead (see below).

## Organizer Accounts

There is no public sign-up. The workspace holds applicant data, so access is
granted deliberately, in one of two ways.

### Roles

| Role | Can do |
| --- | --- |
| `owner` | Everything an organizer can, plus invite people and revoke pending invitations |
| `organizer` | Manage forms and applications |

Existing installs default every account to `organizer` when the migration runs.
Promote yourself once, by hand:

```sql
UPDATE admin_users SET role = 'owner' WHERE email = 'you@example.org';
```

### Inviting someone (the normal path)

Owners get a **Team** link in the workspace header, at `/admin/team`.

1. The owner enters an email address and picks a role.
2. The system generates a token, stores only its SHA-256, and emails the raw
   value as a one-time link.
3. The recipient opens the link, sets a password, and the account is created.

Receiving the email is what proves control of the address, so there is no
separate verification step. **Nothing exists in `admin_users` until the link is
opened** — an unaccepted invitation cannot sign in.

Invitations are single use, expire after seven days (`security.invite_expiry`
in `config.php`), and can be revoked from the same page. Re-inviting an address
automatically revokes its earlier link, so there are never two live doors for
one person.

If SMTP is not configured or the send fails, the invitation is still created
and the link is shown once on screen, so a mail outage cannot strand it.

### Terminal path (bootstrap and recovery)

`php bin/create_admin.php <email>` creates an owner, or resets the password of
an account that already exists. It deliberately does **not** change the role of
an existing account, so a password reset can never silently promote someone.
Use it to bootstrap the first owner, or to recover if every owner is locked out.

### Forgotten passwords

Organizers reset their own password from **Forgot your password?** on the sign
in page. The address is emailed a one-time link that lasts an hour.

The request form answers identically whether or not the address has an account,
so it cannot be used to discover which addresses are organizers. Requests are
limited to three per account per hour, so it cannot be used to flood an inbox
either. Asking again cancels the previous link.

Completing a reset **signs out every session that account already had open**,
which is the point when the reason for resetting is that somebody else may be
in the account. This works by stamping `admin_users.password_changed_at` and
having `Auth::check()` compare each session against it.

### Password length

The minimum is defined once, as `Auth::MIN_PASSWORD_LENGTH`, and everything
reads it: the terminal script, the invitation page, and the reset page. Change
it there and all three follow.

### A note on expiry times

Invitation and reset expiry are computed by the database (`NOW() + INTERVAL n
SECOND`), not by PHP. Every check of whether a link is still alive runs in SQL,
so a value computed in PHP would be wrong by whatever the offset is between the
two clocks — and PHP and MySQL sit in different time zones more often than you
would expect. At an hour-long lifetime that is enough to hand out links which
are already dead. If you add another expiring token, follow the same pattern.

## 🔒 Security Paradigms (Strict Enforcement)
As an open-source project dealing with applicant data, all contributors **MUST** adhere to the following security architectures:

- **SQL Injection Prevention:** 100% PDO Prepared Statements. Direct variable interpolation in SQL is strictly forbidden and will be rejected in PRs.
- **CSRF Protection:** Required for all state-changing forms (POST/PUT/DELETE) using our internal token generator.
- **File Upload Pipeline:** Strict MIME checking, sanitized renaming (`[FormType]_[ApplicantID]_[DocType].ext`), and a secondary `.htaccess` in the `uploads/` directory to prevent arbitrary script execution.
- **Anti-Spam & Magic Links:** Employs cryptographically secure magic links for returning applicants (Save Draft functionality) instead of heavy authentication.
- **Data Retention & PII Scrubbing:** Automated CRON jobs will scrub Personally Identifiable Information (PII) for inactive/rejected applications after a 6-12 month grace period to comply with global data privacy standards.

## 🤝 Contributing
We welcome contributions from the global community! To ensure the integrity of the platform:
1. **Never commit sensitive data:** Ensure `.env`, `config.php`, and `*.log` files are never included in your commits.
2. **Review Security Policies:** Familiarize yourself with the Security Paradigms section above.
3. **Automated Checks:** All Pull Requests to `main` must pass the GitHub Actions PHP syntax linting.
4. **Code Reviews:** All PRs require review from a core maintainer before merging.

## 🛡️ Reporting Vulnerabilities
If you discover a security vulnerability, please **DO NOT** open a public issue. Instead, email the core maintainers directly or use GitHub's private vulnerability reporting feature to allow us to patch it securely.

## License
MIT License. Copyright (c) 2026 Deoband Community Wikimedia and Contributors.
