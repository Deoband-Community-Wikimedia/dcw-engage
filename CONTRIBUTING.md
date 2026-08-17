# Contributing to DCW Engage

This portal is built and maintained by volunteers from the **Deoband Community Wikimedia** user group. It manages application forms, volunteer registrations, fellowship intakes, and community grants.

Contributions of every size are welcome, including fixing a typo in documentation.

---

## 🚀 Setting Up Locally

### Prerequisites
- PHP 8.1 or newer (with `pdo_mysql` enabled)
- MySQL 8.0 or MariaDB 10.4+
- Composer
- Git

### 1. Clone the repository
```bash
git clone https://github.com/Deoband-Community-Wikimedia/dcw-engage.git
cd dcw-engage
composer install
```

### 2. Configure Environment
Copy the example configuration file:
```bash
cp includes/config.example.php includes/config.php
```
Open `includes/config.php` and set your local database credentials (`host`, `database`, `username`, `password`).

### 3. Database Setup
Create an empty database and import the schema:
```bash
mysql -u root -p your_database_name < database.sql
```

### 4. Create an Admin Account
Run the CLI script to create your first administrative user:
```bash
php bin/create_admin.php admin@example.com
```
*(Copy the generated temporary password printed in your terminal).*

### 5. Run the Local Dev Server
```bash
php -S localhost:8000
```
- **Public Portal:** [http://localhost:8000/](http://localhost:8000/)
- **Admin Workspace:** [http://localhost:8000/admin/login](http://localhost:8000/admin/login)
- **Status Lookup:** [http://localhost:8000/track](http://localhost:8000/track)

---

## 🛠️ Codebase Structure

- `index.php` — Single entry router mapping public & admin URLs.
- `views/` — View templates (public forms in `views/forms/`, admin tools in `views/admin/`).
- `models/` — Data models (`FormModel.php`, `ApplicationModel.php`, `NotesModel.php`, `InviteModel.php`).
- `includes/` — Core infrastructure (`init.php`, `auth.php`, `db.php`, `csrf.php`, `mailer.php`, `audit.php`, `markdown.php`).
- `bin/` — Maintenance CLI scripts (`create_admin.php`, `backfill_tracking_ids.php`).

---

## 🛡️ Security & Quality Guidelines

Every pull request is expected to follow these fundamental security requirements:

1. **Prepared Statements:** ALWAYS use `$stmt = $db->prepare(...)` with bound parameters for database queries. Never interpolate variables directly into SQL strings.
2. **CSRF Protection:** Include `<?= CSRF::getInputField() ?>` on every form and call `CSRF::validate()` in POST handlers.
3. **XSS Protection:** Escape all dynamic output with `htmlspecialchars($var, ENT_QUOTES, 'UTF-8')`.
4. **No Hardcoded Secrets:** Never commit real passwords, API keys, SMTP credentials, or real PII.
5. **Linting Check:** Run `php -l` on all modified files before pushing:
   ```bash
   php -l path/to/file.php
   ```

---

## 📋 Submitting a Pull Request

1. **Branch Naming:** Name your branch clearly (e.g. `feature/checkbox-field`, `fix/form-title-slug`, `issue-28-notifications`).
2. **PR Description:** Link the issue it closes using `Closes #XX`.
3. **Testing & Visual Proof:** Test locally before submitting. Include screenshots or screen recordings in your PR description for any UI or layout changes.
