# College Online Voting System

An existing PHP/MySQL college voting application, enhanced in place with student
verification, administrator controls, scheduled elections, secret ballots, and
published election results. It uses plain PHP, MySQLi, MariaDB/MySQL, HTML, CSS,
and vanilla JavaScript, with no runtime framework. The browser-test harness
uses Node.js, npm, Playwright, and MySQL2.

## Project structure

- `index.html`, `index.php` - landing pages
- `register.php`, `login.php`, `logout.php` - student account lifecycle
- `admin_login.php`, `admin.php`, `admin_logout.php` - administrator sign-in and
  management interface
- `dashboard.php`, `candidates.php`, `vote.php` - student dashboard, candidates,
  and ballot
- `vote_success.php`, `already_voted.php`, `results.php` - ballot status and
  results
- `auth.php`, `config.php`, `db.php` - sessions, authorization, and database
  connection
- `migrations/001_...sql` through `migrations/007_...sql` - ordered schema
  upgrades
- `uploads/candidates/.htaccess` - prevents PHP execution in candidate uploads
- `init_db.php` - CLI-only initializer for a brand-new database
- `database_setup.sql` - base schema for a manual installation

The PHP pages submit form requests directly; the project does not expose a
separate REST API.

## Browser tests

Playwright end-to-end tests cover election visibility, candidate
application/election management, and voting/results. The visibility test registers temporary
student and admin accounts, creates an election through the admin interface,
verifies the database record and student Elections list, then removes its test
data. The candidate workflow test exercises submission, approval, rejection,
cancellation, safe deletion, and history-protected deletion in Chromium,
verifies persisted records and transaction rollback, then removes its test
data. The voting/results test activates an election through the admin panel,
checks that pending candidates cannot be selected, submits and verifies a
ballot, tests duplicate-vote prevention, closes voting, publishes results, and
confirms student result access while keeping unpublished tallies private. The
full-workflow test follows election creation, student visibility, position
creation, candidate application and approval, voting, closure, and published
student results in one browser session. It also verifies the role-specific
sidebar order.

Start Apache and MySQL in XAMPP, install the Node dependencies, and install the
Playwright Chromium browser once:

```sh
npm install
npx playwright install chromium
npm run test:e2e
```

The test uses the app at `http://localhost/Voting/` and the XAMPP defaults
(`localhost`, `root`, an empty password, and `voting_system`). Set `BASE_URL`,
`DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASSWORD`, or `DB_NAME` if your local
settings differ. The database settings supplied to the test must match
`config.php`. The test cleans up its uniquely named temporary records; do not
run it against a production database.

## Landing page and authenticated navigation

- `index.html` is the public landing page and links to the existing student
  registration and shared login forms. `index.php` routes an existing signed-in
  user to the dashboard for their session role.
- `login.php` contains separate Student and Admin tabs and forms on the same
  page. The student form accepts Student ID or email; the admin form accepts
  the administrator account's Student ID or email (the existing schema has no
  username column). Each form queries only its own `users.role` value and
  verifies the stored password hash. Successful sign-ins go to `dashboard.php`
  for students and `admin.php` for administrators. `admin_login.php` remains
  as a compatibility URL that redirects to the shared login page.
- Protected pages continue to check current database role, verification, and
  account activation on the server. Administrator operations remain restricted
  to administrator sessions.
- `includes/navigation.php`, `includes/navigation.css`, and
  `includes/navigation.js` provide the common role-aware sidebar, page heading,
  user/profile summary, and responsive navigation used by authenticated pages.
  The sidebar can be collapsed on desktop and opens as a dismissible drawer on
  mobile; its collapsed preference lasts for the current browser tab. Admin
  student management shows live total, active, inactive, and last-30-day
  registration counts; prepared search across names, Student IDs, and emails;
  account-status filters; whitelisted sort orders; and server-side pagination.
  Student details omit passwords and ballot choices. Existing student add,
  edit, eligibility verification, and activate/deactivate actions use CSRF
  protection, hashed initial passwords, and audit logging. Deactivation blocks
  sign-in without deleting participation history. The administrator sidebar
  separates Dashboard, Elections, Positions, Candidate Applications,
  Candidates, Students, Voting, and Results. Election creation/editing is
  separate from position management; application review stays on its own page;
  Candidates defaults to approved records and keeps pending/rejected direct
  candidate review available through a status filter; voting controls are
  separate from published results. The duplicate Reports menu was removed
  because it opened the same results page. Notifications, audit logs, settings,
  and logout remain available. Admin election and candidate lists also support
  server-side search/filtering and pagination. Administrator
  links are rendered only for administrator sessions; the corresponding admin
  pages still enforce authorization independently.
- Student and administrator accounts have role-scoped notifications and an
  authenticated, CSRF-protected mark-as-read action. Protected page responses
  use no-store cache headers to avoid reusing dashboard content after logout.
- Student election, candidate, and voting navigation points to the existing
  dashboard sections and voting/candidate pages; results, notifications,
  profile, and logout reuse the existing application workflows.

## Setup

### Fresh database

Use one of these options:

1. With XAMPP defaults, run `php init_db.php` from the project directory. This
   initializer refuses to modify an existing `voting_system` database and
   creates no sample accounts. It creates the base schema and applies all seven
   migrations.
2. Or execute `database_setup.sql` in phpMyAdmin, then apply migrations
   `001_auth_roles.sql` through `007_candidate_applications.sql` in numeric
   order.

The initializer uses the database settings in `db.php`. Update those settings
for your local MySQL account before running it if they differ from XAMPP's
default `localhost` / `root` / empty password.

### Existing database

Back up `voting_system` before applying migrations. Apply migrations
`001_auth_roles.sql` through `007_candidate_applications.sql` in numeric order,
once each. The initializer is not for upgrades and intentionally refuses an
existing database. Do not run migrations a second time.

Migration 005 preserves every legacy vote choice and timestamp, copies the
associated voter participation into `legacy_voter_participation`, then removes
the voter ID from the legacy `votes` table. This separates the old participation
record from ballot choices; after this privacy change, the identity-to-choice
mapping cannot be restored. Existing legacy ballots remain historical and are
not included in the new election's published results.

Start Apache and MySQL in XAMPP, then open
`http://localhost/Voting/index.html`.

## Authentication and administrator setup

- Registration stores a password hash using PHP `password_hash()`. New student
  accounts remain unverified and cannot log in or vote until an administrator
  approves them.
- Accounts that existed before migration 001 remain verified so the upgrade
  does not lock them out.
- Student accounts can be activated/deactivated separately from election
  eligibility. Existing accounts default to active in migration 006; inactive
  accounts cannot sign in or access protected routes.
- There is no default administrator or shared password. Register a dedicated
  account, then promote it using its exact student ID:

  ```sql
  UPDATE users
  SET role = 'admin', is_verified = 1
  WHERE student_id = 'YOUR_ADMIN_STUDENT_ID';
  ```

- Administrators can verify students and manage elections, positions, and
  candidates. Administrative changes require an authenticated admin session
  and CSRF validation; actions are recorded without ballot choices.
- Student management supports adding accounts, editing contact/profile fields,
  searching and filtering, eligibility verification, and account activation.
  Admin-created accounts start unverified and active, with an administrator-set
  initial password that should be shared privately and changed by the student.
- Students can edit their name, email, and department from the dashboard and
  change their password after verifying the current password. Student IDs and
  voting records are not editable in the profile form.
- The admin dashboard includes election/candidate search and status/position
  filters, turnout/ballot summary cards, and recent administrative audit events.

## Election and ballot behavior

- Administrators create elections with future start/end times, positions, and
  candidates. Election creation saves only the election record; positions are
  created and managed separately from the Positions section and remain linked
  through `positions.election_id`. Positions accept applications by default;
  admins can close applications or set an application window in position
  settings.
  Draft elections appear in a clearly labeled “In preparation” section on the
  student Elections list, without voting actions. Scheduling makes an election
  visible as upcoming; voting is available only while an election is active
  and within its voting dates. Student candidates must be verified;
  candidates can be approved or rejected and may have an uploaded photo and
  manifesto.
- Students can submit one candidate application per position while its
  application window is open. Administrators review applications separately
  from directly managed candidates; approval creates or links exactly one
  official approved candidate, while rejection and feedback remain in the
  student's application history and generate in-app notifications.
- Administrators can permanently delete an election only when it has no ballot
  or voter-participation records and no published results. Deletion is a
  CSRF-protected POST within a transaction; it removes only that election's
  applications, candidates, eligibility snapshots, positions, and candidate
  photos. Notifications are retained with their election link cleared, audit
  history is retained, and failed deletes roll back. Elections with voting
  history or published results remain in place and cannot be deleted.
- Elections must be scheduled and activated within their configured time
  window. Activation snapshots verified, active students for that election.
- A student can submit one ballot per election. The server checks the session,
  verification, eligibility snapshot, election window, approved candidates,
  ballot completeness, and CSRF token. Participation is stored separately from
  anonymous ballot choices, and the unique database constraint prevents repeat
  participation.
- Results remain hidden from students until an administrator closes the
  election and publishes them. Results, turnout, and percentages are computed
  from database records; ties are shown as ties.
- Students see election status, candidate details, notifications, and their
  participation status on the dashboard. Search filters the displayed
  elections.

## Database and compatibility

The application database is `voting_system`. The configured MySQL connection is
in `db.php`; credentials are not read from a separate environment file. The
shared connection also configures PHP's timezone from the active MySQL session
to keep election scheduling comparisons consistent.

The election schema includes `elections`, `positions`, `candidates`,
`election_eligibility`, `election_participation`, `ballots`,
`legacy_voter_participation`, `admin_audit_log`, and `notifications`, in addition to `users` and historical
ballot/participation records.

Tested locally with PHP 8.0.30 and MariaDB 10.4.32 under XAMPP. The focused
Playwright end-to-end suite is described in the Browser tests section above.

## Verification performed

Manual HTTP and database checks have covered student registration and login,
unverified/invalid login rejection, administrator access control, election and
candidate management, eligibility snapshotting, early/late voting rejection,
forged/invalid ballots, successful and duplicate submissions, unpublished and
published results, turnout, and removal of temporary test records. All PHP files pass `php -l`.

Candidate photo upload validation, HTTP serving/removal, admin/student search
filters, notification read state, and authenticated student/admin flows were
also exercised manually. Registration and the student dashboard were checked
at a 390 px browser viewport with no horizontal overflow; this is not a
full-device accessibility or cross-browser test. Forgot-password, email
delivery, and CSV/PDF export are not implemented.
