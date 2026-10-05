# DCW Engage

DCW Engage is the portal used by the Deoband Community Wikimedia (DCW) community to manage open opportunities, application forms, member registration, and organizer workflows for scholarships, fellowships, internships, volunteering, and community support.

This repository contains the backend, organizer workspace, member dashboard, public application forms, member authentication, and support infrastructure for the platform.

## Highlights

- Public-facing application and membership forms for multiple DCW programs and community workflows
- Member onboarding with unique Member IDs (e.g., `A48213977`) generated per chapter upon approval
- Secure member authentication with Member ID + password, failed-login tracking and account lockout
- Member dashboard showing active membership status, support requests, and available programs
- Organizer workspace with role-based access for forms, membership review, and support queues
- Invitation-based onboarding for organizers with email verification and one-time token links
- Save-draft and resume flows using cryptographically secure magic links
- Form submission tracking and application review workflows with audit logs
- Membership lifecycle: new applications, renewals (with Member ID verification), expiry tracking, and chapter-based coordinator access
- Support ecosystem for members: Internet Support requests and Reimbursement claims with finance queue
- Member support ticketing system for complaints, suggestions and questions with staff conversation tracking
- File upload handling with sanitised filenames, strict MIME validation and file-type checking
- Built-in retention and scrub logic for sensitive applicant data
- Deployment automation and pull-request verification via GitHub Actions

## Technology stack

- Backend: PHP 8.2+
- Database: MySQL/MariaDB via PDO
- Mail: PHPMailer
- Frontend: HTML, CSS, and vanilla JavaScript
- Routing: Single-entry PHP front controller
- Dependency management: Composer
- CI/CD: GitHub Actions

## Project structure

```text
.
├── .github/              # GitHub workflows and templates
├── assets/               # Shared frontend assets
├── bin/                  # Utility scripts, e.g. admin bootstrap
├── cron/                 # Scheduled cleanup / maintenance jobs
├── docs/                 # Status and project documentation
├── includes/             # Core PHP app logic and config
│   ├── auth.php          # Organizer authentication and session management
│   ├── member_session.php # Member session and identity helpers
│   ├── csrf.php          # CSRF token generation and validation
│   ├── db.php            # PDO database connection (singleton)
│   ├── config.example.php # Template for local configuration
│   └── mail/             # Mail composition classes
├── migrations/           # Database migration scripts
├── models/               # Domain models and business logic
│   ├── MemberModel.php               # Membership and chapter logic
│   ├── MemberAuthModel.php           # Member login and password management
│   ├── ApplicationModel.php          # Form submissions and tracking
│   ├── InternetSupportModel.php      # Internet support requests
│   ├── ReimbursementModel.php        # Reimbursement claims
│   ├── MemberTicketModel.php         # Support ticketing
│   └── ...
├── uploads/              # Uploaded application files
├── views/                # Public and admin views
│   ├── admin/            # Organizer workspace pages
│   ├── member/           # Member dashboard and forms
│   └── forms/            # Form rendering engine
├── composer.json         # Composer dependencies
├── database.sql          # Schema and seed data for local setup
├── index.php             # Front-controller entry point
├── README.md             # Project overview and setup guide
├── SECURITY.md           # Security policy and responsible disclosure guidance
├── CONTRIBUTING.md       # Contributing guidelines
└── LICENSE               # MIT license
```

## Local development setup

### Prerequisites

- PHP 8.2 or newer with `pdo_mysql` extension
- MySQL 8.0+ or MariaDB 10.4+
- Composer
- Git

### 1. Clone the repository

```bash
git clone https://github.com/Deoband-Community-Wikimedia/dcw-engage.git
cd dcw-engage
composer install
```

### 2. Create configuration

Copy the example config and update the local database and app settings:

```bash
cp includes/config.example.php includes/config.php
```

Then edit `includes/config.php` to set:

- database host, name, username, and password
- app URL (for local development, usually `http://localhost:8000`)
- mail configuration if you want to test email flows
- security timing values (invitation expiry, magic-link expiry, password reset expiry)
- field encryption key (a base64-encoded 32-byte key for sensitive data)

Important: `includes/config.php` is local-only and should never be committed.

### 3. Prepare the database

Create a database and import the schema:

```bash
mysql -u root -p your_database_name < database.sql
```

### 4. Create the first organizer account

The application does not seed an organizer account automatically. Bootstrap the first owner with:

```bash
php bin/create_admin.php admin@example.com
```

This creates or resets an organizer account and is useful for initial setup and recovery when all owners are locked out.

### 5. Run the app locally

```bash
php -S localhost:8000
```

Then open:

- Public portal: http://localhost:8000/
- Organizer login: http://localhost:8000/admin/login
- Member login: http://localhost:8000/member/login
- Status lookup: http://localhost:8000/track
- Member dashboard: http://localhost:8000/member/dashboard

## Core features

### Public applications and programs

The platform hosts application forms for community programs and opportunities:

- scholarships
- fellowships
- internships
- volunteer roles
- course registrations
- club and initiative submissions
- community feedback
- program-specific forms with custom field layouts

Each form supports file uploads, email verification, draft saving with resume links, and tracking lookup.

### Membership lifecycle

Members join through application or renewal:

- **New applicants** apply for membership via a public form, choosing their chapter (generic DCW, AMU club, Jamia club, or Photographers club)
- **Renewals** use their existing Member ID to extend membership; coordinators verify the ID matches their email and chapter
- **Approval** creates a unique Member ID (e.g., `A48213977`: letter = chapter, 8 random digits) with a 1-year expiry date
- **Login** uses Member ID + password, with failed-login tracking and 15-minute account lockout after 5 wrong attempts
- **Dashboard** shows membership status, expiry date, available support programs, and member's own requests
- **Chapter coordinators** review applications for their assigned chapters; owners and reviewers see all chapters

### Organizer workspace

Organizers manage forms, applications, and support workflows:

- **Form builder** creates application forms with custom fields, auto-generated slugs, and schema storage
- **Application review** filters by status, shows applicant names and submissions, supports bulk state changes and internal notes
- **Membership review** filters by chapter (if coordinator), shows approval/rejection/resend options with audit logs
- **Member access** allows owners to assign chapters to membership coordinators
- **Support queues** for reviewing Internet Support requests and Reimbursement claims
- **Finance processing** approves and tracks claim payments with receipt archival
- **Member support** handles member complaints, suggestions and questions with status tracking
- **Team management** (owners only) invites organizers and sets their roles and chapter permissions

### Member support ecosystem

Members can request help through their dashboard:

- **Internet Support** — connectivity packages and support for internet-related needs
- **Reimbursement** — expense reimbursement with line items and receipt upload
- **Support tickets** — complaints, suggestions and questions routed to DCW Support staff

Each request gets a tracking ID (e.g., `HQ-12345`), status updates via email, and visibility in the member's dashboard. Finance staff process payments; organizers and owners manage the support queue.

### Organizer roles

- `owner`: full administrative authority including invitations, team management, and all review queues
- `organizer`: manages forms and application submissions (no support or membership access)
- `membership_coordinator`: reviews membership applications for assigned chapters only
- `membership_reviewer`: reviews all membership applications (no chapter restrictions)
- `support_reviewer`: reviews Internet Support and Reimbursement claims
- `finance`: processes approved claims and manages the payment queue
- `member_support`: manages member support tickets (complaints and suggestions)

### Security model

- PDO prepared statements throughout the app to prevent SQL injection
- CSRF checks for all state-changing requests
- Strict file upload validation (MIME type, extension, double-extension checks)
- Sanitised file naming for uploads
- Organizer session management with password reset and account lockout
- Magic-link based draft resume and email verification flows
- Expiry-based lifecycle controls for invitations and reset tokens
- Member passwords hashed with bcrypt, failed-login tracking
- Field encryption for sensitive applicant data (using AES-256-GCM)
- Audit logs recording who did what and when
- Automated retention scrub for old, inactive, or rejected applications

## CI and deployment

This project uses GitHub Actions for verification and deployment:

- `verify.yml`: runs PHP syntax validation on pull requests
- `deploy.yml`: deploys the production site to configured hosting (via FTP/FTPS) when changes land on `main`, protecting key files and directories

## Security policy

Please review `SECURITY.md` before reporting issues or making sensitive changes.

The project treats member and applicant data seriously. Do not expose secrets in commits, and never commit local configuration or upload data.

## Contributing

We welcome improvements from contributors. Before opening a PR:

1. Keep configuration secrets and sensitive data out of version control.
2. Use prepared statements and follow the existing security patterns throughout.
3. Validate PHP syntax for any modified files.
4. Review the repository's contributing guidance in `CONTRIBUTING.md`.

Example syntax check:

```bash
find . -type f -name "*.php" -exec php -l {} \;
```

## Current project status

The repository is actively evolving. The core platform, authentication systems (organizer and member), invitation flows, membership lifecycle, support ecosystem, and review infrastructure are in place. Form-specific UX enhancements, multi-step flows, and conditional field logic continue to be built out. A detailed status report is available in `docs/STATUS.md`.

## License

This project is licensed under the MIT License. See `LICENSE` for details.

## Maintainers

This project is maintained by volunteers within the Deoband Community Wikimedia community. For support, review the repository documentation and issue tracker before reaching out directly.
