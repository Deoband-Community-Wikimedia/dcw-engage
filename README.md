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
