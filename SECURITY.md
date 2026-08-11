# Security Policy

DCW Engage processes program applications, magic link authentication tokens, and participant data for Deoband Community Wikimedia. We take security seriously and ask that security vulnerabilities be reported to us privately.

## Reporting a Vulnerability

**Please do not open a public issue, discussion, or pull request for a security problem.**

Use GitHub's private vulnerability reporting:

1. Go to the **Security** tab of this repository.
2. Click **Report a vulnerability**.
3. Detail your findings and reproduction steps.

This opens a private thread visible only to you and the project maintainers (`@zaidusyy`).

### What to include

- A clear explanation of the vulnerability.
- Steps to reproduce the issue.
- Impact (e.g. CSRF, SQL injection, unauthorized access to applicant PII, file upload bypass).
- Tested environment (e.g., local PHP 8.2 + MariaDB setup).

Please test locally on your own environment per [README.md](README.md), and do not run automated scanners or exploit scripts against live production instances (`engage.dcwwiki.org`).

## Supported Versions

Only the latest `main` branch is actively supported and maintained. Security patches are committed directly to `main`.
