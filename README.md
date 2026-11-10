# DCW Engage

DCW Engage is the engagement portal for Deoband Community Wikimedia (DCW). It brings together public program forms, membership, member services and rewards, and staff workflows for reviewing and administering them.

- **Live portal:** <https://engage.dcwwiki.org/>
- **License:** MIT

## What the portal does

### Public forms and applications

- Serves configurable program and opportunity forms from the form builder, including scholarship and other application forms, plus feedback forms.
- Supports email verification, draft saving and resume links, file uploads, deadlines, and application tracking.
- Provides staff queues for reviewing applications and feedback. Scholarship workflows include award/decline letters and private award-confirmation links; swag applications can be marked dispatched with courier tracking details.
- Provides a public status lookup at `/track` for application tracking IDs.

### Membership and member services

- Accepts membership applications and renewals for DCW chapters and clubs; authorized coordinators review applications within their assigned scope.
- Creates and manages member accounts, Member IDs, membership expiry, sign-in and password recovery.
- Provides a member dashboard for applications, profile details, certificates (when configured), and services.
- Offers membership cards with QR verification and PDF downloads, along with a public member directory that members can opt out of.
- Lets members earn and claim points, view the goodies catalogue, redeem points, and follow fulfilment.
- Provides member-only Internet Support and reimbursement requests with follow-up conversations and receipt uploads. Staff have review and finance queues.
- Includes member support tickets for questions, suggestions and complaints, as well as technical problem reporting.

### Staff and organizer workflows

- Form management, previews, application and feedback review, internal notes, and audit history.
- Invitation-based team onboarding, role and chapter assignment, password recovery, and team administration.
- Membership review and member-card management; community records for Conversation Hour attendance and technical contributors.
- Goodies catalogue and points administration, event attendance and awards, and event participation cards.
- Technical issue diagnostics and triage.
- Role-based access for owners, organizers, membership coordinators/reviewers, support reviewers, finance staff, and member-support staff. Access to each queue is limited by the role and, where applicable, assigned chapter.

## Technology

- PHP 8.2 or newer, using PDO
- MySQL 8.0+ or MariaDB 10.4+
- Composer dependencies: PHPMailer for email and mPDF for PDF generation
- HTML, CSS, and vanilla JavaScript; a PHP front controller handles application routes
- Apache with `mod_rewrite` and `.htaccess` support for the provided rewrite and access-control rules

## Repository layout

```text
.
├── .github/       # GitHub Actions workflows and templates
├── assets/        # Frontend assets
├── bin/           # Administrative and maintenance CLI tools
├── cron/          # Scheduled privacy and retention jobs
├── docs/          # Project notes and database-related documentation
├── includes/      # Initialization, configuration, auth, database, mail and shared services
├── migrations/    # Upgrade scripts for existing databases
├── models/        # Application and domain logic
├── uploads/       # User-uploaded files; served only through an access-checked route
├── views/         # Public, member, admin, finance and form pages
├── composer.json
├── database.sql   # Complete schema for a fresh database
└── index.php      # Front controller and route definitions
```

## Local setup

### Requirements

Install PHP 8.2+, Composer, and MySQL 8.0+ or MariaDB 10.4+. PHP must have the PDO MySQL driver enabled. For Apache deployments, enable `mod_rewrite` and allow the repository's `.htaccess` rules to run.

### 1. Install dependencies

```bash
git clone https://github.com/Deoband-Community-Wikimedia/dcw-engage.git
cd dcw-engage
composer install
```

### 2. Configure the application

Copy the configuration template and edit it for your environment:

```bash
cp includes/config.example.php includes/config.php
```

Set the application URL and environment, database credentials, SMTP settings, and security secrets in `includes/config.php`. Use strong, unique secrets for `field_encryption_key` and `otp_secret`; configure the field-encryption key as a base64-encoded 32-byte key. Keep `config.php` private and never commit it. The example configuration is not suitable for production unchanged.

Email-dependent features—including verification, invitations, password recovery, and notifications—require working SMTP settings.

### 3. Create and initialize the database

Create an empty database, then import the complete schema:

```bash
mysql -u root -p your_database_name < database.sql
```

A fresh installation needs `database.sql` only. Do **not** run the files in `migrations/` on a new database; they are for upgrading older installations. Database settings and credentials are configured in `includes/config.php`.

### 4. Create the first owner account

No administrator is seeded in the database. Create the first owner from the command line:

```bash
php bin/create_admin.php admin@example.com
```

### 5. Start the application

For local development, PHP's built-in server can use the front controller as its router:

```bash
php -S localhost:8000 index.php
```

Then visit `http://localhost:8000/`. Useful routes include:

- `/admin/login` — organizer sign-in
- `/member/login` — member sign-in
- `/member/dashboard` — member dashboard (sign-in required)
- `/track` — public application status lookup
- `/membership` — membership landing page
- `/members` — public member directory

For production, deploy behind a web server configured to use the repository's rewrite and access-control rules. Do not expose configuration, SQL files, internal PHP directories, or uploaded files directly. The `/file` route checks access before returning uploaded content.

## Optional integrations

- **Certificates:** copy `includes/certs_db.example.php` to `includes/certs_db.php` and configure read-only credentials for the certificates database. Without it, the member certificates section has no external certificate data.
- **What's on events:** the portal can read public event data from the DCW wiki API. Defaults are documented in `includes/events_db.example.php`; copy it to `includes/events_db.php` only if you need to change the API URL or Cargo table.

These real configuration files are gitignored. Never commit credentials.

## Maintenance

The `cron/` directory contains scheduled privacy and retention jobs, including `scrub_pii.php` and `scrub_feedback.php`; `bin/` also contains maintenance utilities such as receipt purging. Review each script and the deployment's retention requirements, then configure the appropriate jobs in the hosting environment. Do not run migration or data-maintenance scripts blindly against production.

GitHub Actions workflows are in `.github/workflows/` (`verify.yml` and `deploy.yml`). Review the deployment workflow and required repository secrets before using it for a deployment.

## Security and contributing

Please read [SECURITY.md](SECURITY.md) before reporting security issues and [CONTRIBUTING.md](CONTRIBUTING.md) before submitting changes. Do not commit credentials, private configuration, production data, or uploaded files. Preserve the existing authorization, CSRF, prepared-statement, upload-validation, and data-retention protections when changing the application.

A PHP syntax check for the repository can be run with:

```bash
find . -type f -name '*.php' -not -path './vendor/*' -exec php -l {} \;
```

See [docs/STATUS.md](docs/STATUS.md) for additional project notes; feature status can change as development continues.
