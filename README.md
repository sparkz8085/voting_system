# CampusVote — Online Voting System

A secure, academic online voting platform for campus elections. Voters register,
review candidate profiles, cast one ballot per election and download a
participation receipt. An administrator panel manages elections, candidates,
the voter roll, tallies and publishing.

**Stack:** PHP 8 (OOP) + MySQL / MariaDB · HTML5 / CSS3 / vanilla JavaScript ·
Bootstrap 5.3 + Bootstrap Icons 1.11 · Inter + Sora typography · custom SVG art.

---

## Table of contents

1. [Overview](#overview)
2. [Features](#features)
3. [Technology stack](#technology-stack)
4. [Requirements](#requirements)
5. [Installation](#installation)
6. [Configuration](#configuration)
7. [Default credentials](#default-credentials)
8. [Accessing the project](#accessing-the-project)
9. [Usage walkthrough](#usage-walkthrough)
10. [Security measures](#security-measures)
11. [Testing performed](#testing-performed)
12. [Limitations and disclaimer](#limitations-and-disclaimer)
13. [Project structure](#project-structure)

---

## Overview

CampusVote replaces paper ballots for common campus elections (student council
president, faculty senate, sports council, alumni advisory polls, …) with a
web-based, single-vote-per-election voting flow:

* Voters authenticate through a self-service portal and get a personalised
  dashboard of elections.
* The **ballot** shows every candidate for an election with tagline, background
  and manifesto, plus an accessibility-friendly card list and a live selection
  summary.
* The vote is stored **anonymously** (the ballot row never stores the voter
  identity) while a separate participation record confirms a voter may vote only
  once and issues a receipt code (`OVS-XXXX-XXXX-XXXX`).
* Once the scheduled window closes, administrators publish the certified results.

Administrators can create and schedule elections, assemble candidate ballots,
review the voter roll, publish results and keep a full audit trail of sensitive
actions.

---

## Features

### Voter portal (`/voter/*`)

* Registration (auto sign-in) with validation and duplicate handling.
* Login / logout with generic errors and rate limiting (5 attempts / 15 minutes).
* Dashboard: welcome summary, elections list, status pills, voted indicators.
* Ballot: live or scheduled window enforcement, one vote per election,
  candidate selection summary, countdown to close, privacy notice, readonly
  profile view when voting is closed or upcoming.
* Receipt: confirmation, participation details, vote fingerprint (`sealed`).

### Admin panel (`/admin/*`)

* Dashboard: real-time counts, 7-day voting activity chart, spotlight election,
  recent voters / elections / activity.
* Elections: create, edit schedule, activate, close, publish, unpublish, delete.
* Candidates: ballot builder with locking rules (locked once live or once votes
  exist), manifesto handling, display order.
* Voters: list, activate / deactivate, reset password, delete.
* Results: tallies, winner highlight, integrity checks, publish / unpublish.
* Activity: audit log of administrative actions.
* Profile: administrator account management.

### Public area

* Landing page with live platform stats, upcoming elections and published
  results.

---

## Technology stack

| Layer      | Technology                                            |
| ---------- | ----------------------------------------------------- |
| Backend    | PHP 8.2+ (OOP classes, strict types, PSR-ish layout)  |
| Database   | MySQL 8 / MariaDB (PDO, prepared statements)          |
| Frontend   | Bootstrap 5.3, Bootstrap Icons, vanilla JS            |
| Fonts      | Inter + Sora (variable font files)                   |
| Art        | Hand-authored SVG logo, favicon, hero illustration   |

---

## Requirements

* Web server with PHP support (Apache + mod_php, nginx + PHP-FPM, or the
  **PHP built-in development server**).
* MySQL / MariaDB server and client.
* PHP extensions: `pdo_mysql`, `session`, `json`, `mbstring` (recommended).

LAMPP users get everything from a standard install. For quick local testing a
plain PHP CLI + MariaDB setup is enough (see below).

---

## Installation

1. **Place the project** in your web root, keeping the folder name
   `online-voting-system` (required when served under a sub-path):
   * LAMP/Linux: `/opt/lampp/htdocs/online-voting-system`
   * XAMPP/Windows: `C:\xampp\htdocs\online-voting-system`
   * Custom: create a subdirectory of your document root and copy the project.

2. **Create and import the database**
   * Open phpMyAdmin (usually `http://localhost/phpmyadmin`).
   * Import `database/database.sql` via the *SQL* tab, or run
     `mysql -u root -p < database/database.sql`.
   * This creates the database `online_voting_system` with its tables and the
     demonstration dataset.

3. **Configure the application**
   * Copy `config/config.local.example.php` to `config/config.local.php`.
   * Adjust database credentials if your MySQL user/password differ
     (defaults: host `127.0.0.1`, user `root`, empty password).

4. **Start the stack**
   * Apache + MySQL via your control panel, or
   * `sudo service mysql start` and restart Apache, then import the database.

5. **Open the application**
   * `http://localhost/online-voting-system/`

### Quick local run without Apache (PHP built-in server)

```bash
cd /home/flamewall85          # parent of the project folder
php -S 127.0.0.1:8080 -t /home/flamewall85
```

Then visit <http://127.0.0.1:8080/online-voting-system/>.

---

## Configuration

All configuration lives in `config/config.php` with environment overrides via
`config/config.local.php`.

| Key                      | Purpose                                        | Default |
| ------------------------ | ---------------------------------------------- | ------- |
| `app.name`               | Application name                              | CampusVote |
| `app.tagline`            | Short subtitle                                 | Secure campus elections |
| `app.timezone`           | Server / display timezone                     | Asia/Kolkata |
| `app.base_url`           | Base path prefix when served in a subfolder  | auto-detected |
| `app.debug`              | Show debug details                            | false |
| `database.host`          | MySQL host                                    | 127.0.0.1 |
| `database.port`          | MySQL port                                    | 3306 |
| `database.name`          | Database name                                 | online_voting_system |
| `database.user`          | Database user                                 | root |
| `database.password`      | Database password                             | (empty) |
| `security.max_login_attempts` | Failed attempts before lockout         | 5 |
| `security.login_window_minutes` | Rate-limit window                    | 15 |
| `security.session_idle_minutes` | Idle session timeout              | 120 |

Environment overrides: `APP_DEBUG`, `APP_BASE_URL`, `DB_HOST`, `DB_NAME`,
`DB_USER`, `DB_PASSWORD`.

---

## Default credentials

| Account   | Username | Password        | Role  |
| --------- | -------- | --------------- | ----- |
| Admin     | `admin`  | `Admin@12345`  | Admin |
| Voter 1   | `aditi`  | `Voter@12345`  | Voter |
| Voter 2   | `rahul`  | `Voter@12345`  | Voter |
| …         | …        | …               | …     |

All sample voters share the password `Voter@12345` and are part of the
demonstration dataset.

> Change every default credential before any real deployment.

---

## Accessing the project

1. Start MySQL and your PHP web server (Apache + PHP-FPM or the built-in server).
2. Import `database/database.sql`.
3. Ensure `config/config.local.php` exists and matches your database.
4. Open the URL that serves this folder:

* Apache/LAMPP: <http://localhost/online-voting-system/>
* PHP built-in server: <http://127.0.0.1:8080/online-voting-system/>

Admin sign-in: `/auth/admin-login.php`
Voter sign-in: `/auth/login.php`
Voter registration: `/auth/register.php`
Public home: `/`
Admin dashboard: `/admin/dashboard.php`
Voter dashboard: `/voter/dashboard.php`
Elections list: `/voter/elections.php`
Ballot: `/voter/vote.php?election_id=…`
Results: `/voter/results.php?election_id=…`
Admin elections: `/admin/elections.php`
Admin candidates: `/admin/candidates.php`
Admin voters: `/admin/voters.php`
Admin results: `/admin/results.php`
Admin activity: `/admin/activity.php`
Admin profile: `/admin/profile.php`

---

## Usage walkthrough

1. Sign in as a voter → open an active election → cast a ballot → view receipt.
2. Try voting again in the same election → the ballot is rejected as duplicate.
3. Sign in as `admin` → activate / close elections, add candidates,
   publish results, review the audit log.

---

## Security measures

* Passwords hashed with `password_hash()` / verified with `password_verify()`.
* All database access through PDO prepared statements (no emulated prepares).
* CSRF token per session (rotated after authentication and voting events).
* POST-only state changes with PRG pattern and flash messages.
* Session hardening: strict mode, cookie-only sessions, HttpOnly, SameSite=Lax,
  idle timeout enforced server-side.
* Login rate limiting with generic error messages (no user enumeration).
* Role-based access control with per-request account revalidation.
* Secure response headers (CSP, X-Frame-Options, nosniff, Referrer-Policy, HSTS
  on HTTPS, no X-Powered-By).
* Server-side validation, output escaping, HTML escaping on all output.
* Voting performed inside a MySQL transaction with `SELECT … FOR UPDATE` on the
  election row, unique participation key and anonymous ballot insert.

---

## Testing performed

During development the stack was exercised with PHP syntax checks, curl-driven
end-to-end requests and database assertions:

* Public / auth / app pages returned expected status codes without errors.
* Voter login and dashboard load.
* Ballot submission succeeded; second submission was blocked as a duplicate.
* Admin login and every admin module returned HTTP 200 without runtime errors.

---

## Limitations and disclaimer

CampusVote is an **academic prototype** built for coursework and demonstration.
It is **not** designed for, and must not be used for, real elections. In
particular it does not provide independent verifiability, end-to-end ballot
verification, legal compliance for government use, or a trustworthy secrecy
guarantee on its own. Any production election system would require independent
technical audits, postal/physical voting fallbacks, and legal oversight.

---

## Project structure

```
online-voting-system/
├── database/
│   └── database.sql          # Schema + demonstration data
├── config/
│   ├── config.php
│   ├── database.php
│   └── config.local.example.php
├── includes/
│   ├── app.php               # Bootstrap, security headers, sessions
│   ├── auth.php              # Authentication, rate limiting, roles
│   ├── functions.php         # Helpers, CSRF, formatting, avatars, toasts
│   ├── csrf.php
│   ├── classes/              # Election, VoteService, ResultService
│   ├── header.php / footer.php / admin_* / voter_* / auth_* / confirm_modal.php
├── admin/                    # Admin panel pages
├── voter/                    # Voter portal pages
├── auth/                     # Login, admin login, registration, logout
├── errors/                   # Standalone error view
├── assets/
│   ├── css/app.css
│   ├── js/app.js
│   ├── img/, fonts/, vendor/
├── tools/
│   └── create_admin.php     # CLI admin bootstrap
└── .htaccess                # Directory-level deny rules
```
