const { test, expect } = require('@playwright/test');
const mysql = require('mysql2/promise');
const { randomUUID } = require('node:crypto');

const suffix = randomUUID().replace(/-/g, '').slice(0, 12).toUpperCase();
const studentId = `E2E-${suffix}-S`;
const adminStudentId = `E2E-${suffix}-A`;
const studentEmail = `e2e.student.${suffix.toLowerCase()}@example.invalid`;
const adminEmail = `e2e.admin.${suffix.toLowerCase()}@example.invalid`;
const password = 'E2eTest!2026';
const electionTitle = `E2E Candidate Test ${suffix}`;
const protectedElectionTitle = `E2E Protected Election ${suffix}`;
const positionNames = ['E2E Approval Position', 'E2E Rejection Position'];
const statement = 'This application is submitted by the automated browser test.';
const rejectionFeedback = 'Automated test rejection feedback.';
const deletionGuardTable = `e2e_delete_guard_${suffix.toLowerCase()}`;

let connection;
let electionId;
let protectedElectionId;
let protectedPositionId;
let protectedCandidateId;

test.beforeAll(async () => {
  connection = await mysql.createConnection({
    host: process.env.DB_HOST || 'localhost',
    port: Number(process.env.DB_PORT || 3306),
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASSWORD || '',
    database: process.env.DB_NAME || 'voting_system'
  });

  const [protectedElectionResult] = await connection.execute(
    `INSERT INTO elections (title, description, starts_at, ends_at, status)
     VALUES (?, 'Playwright protected history fixture',
             DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 HOUR),
             DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 1 DAY), 'active')`,
    [protectedElectionTitle]
  );
  protectedElectionId = protectedElectionResult.insertId;
  const [positionResult] = await connection.execute(
    'INSERT INTO positions (election_id, name, description, sort_order) VALUES (?, ?, ?, 1)',
    [protectedElectionId, 'E2E Protected Position', 'Position with a recorded test ballot']
  );
  protectedPositionId = positionResult.insertId;
  const [candidateResult] = await connection.execute(
    `INSERT INTO candidates
       (election_id, position_id, student_id, name, manifesto, status)
     VALUES (?, ?, ?, 'E2E Protected Candidate', 'Test-only ballot candidate', 'approved')`,
    [protectedElectionId, protectedPositionId, `TEST-${suffix}`]
  );
  protectedCandidateId = candidateResult.insertId;
});

test.afterAll(async () => {
  if (!connection) {
    return;
  }

  try {
    await connection.query(`DROP TABLE IF EXISTS \`${deletionGuardTable}\``);
    await connection.beginTransaction();
    const electionIds = [electionId, protectedElectionId].filter(Boolean);
    await connection.execute(
      `DELETE FROM admin_audit_log
       WHERE actor_user_id IN
         (SELECT id FROM users WHERE student_id IN (?, ?))
         OR (entity_type = 'candidate_application' AND entity_id IN
           (SELECT id FROM candidate_applications WHERE election_id IN (?, ?)))
         OR (entity_type = 'election' AND entity_id IN (?, ?))
         OR details LIKE ? OR details LIKE ?`,
      [
        studentId,
        adminStudentId,
        electionId || 0,
        protectedElectionId || 0,
        electionId || 0,
        protectedElectionId || 0,
        `%Deleted election: ${electionTitle}%`,
        `%Deleted election: ${protectedElectionTitle}%`
      ]
    );
    await connection.execute(
      `DELETE FROM notifications
       WHERE election_id IN (?, ?) OR message LIKE ? OR message LIKE ?`,
      [
        electionId || 0,
        protectedElectionId || 0,
        `%${electionTitle}%`,
        `%${protectedElectionTitle}%`
      ]
    );

    for (const id of electionIds) {
      await connection.execute('DELETE FROM ballots WHERE election_id = ?', [id]);
      await connection.execute('DELETE FROM election_participation WHERE election_id = ?', [id]);
      await connection.execute('DELETE FROM election_eligibility WHERE election_id = ?', [id]);
      await connection.execute('DELETE FROM candidate_applications WHERE election_id = ?', [id]);
      await connection.execute('DELETE FROM candidates WHERE election_id = ?', [id]);
      await connection.execute('DELETE FROM positions WHERE election_id = ?', [id]);
      await connection.execute('DELETE FROM elections WHERE id = ?', [id]);
    }

    await connection.execute(
      'DELETE FROM notifications WHERE user_id IN (SELECT id FROM users WHERE student_id IN (?, ?))',
      [studentId, adminStudentId]
    );
    await connection.execute('DELETE FROM users WHERE student_id IN (?, ?)', [studentId, adminStudentId]);

    await connection.commit();
  } catch (error) {
    await connection.rollback();
    throw error;
  } finally {
    await connection.end();
  }
});

async function registerAccount(page, { firstName, lastName, email, id }) {
  await page.goto('register.php');
  await page.getByLabel('First Name *', { exact: true }).fill(firstName);
  await page.getByLabel('Last Name *', { exact: true }).fill(lastName);
  await page.getByLabel('Email ID *', { exact: true }).fill(email);
  await page.getByLabel('Student ID *', { exact: true }).fill(id);
  await page.getByLabel('Department *', { exact: true }).selectOption({ label: 'Computer Engineering' });
  await page.getByLabel('Password *', { exact: true }).fill(password);
  await page.getByLabel('Confirm Password *', { exact: true }).fill(password);
  await page.getByRole('button', { name: 'Register' }).click();
  await expect(page).toHaveURL(/login\.php\?success=/);
  await expect(page.getByText('Registration successful!', { exact: false })).toBeVisible();
}

async function loginAs(page, role, id) {
  await page.goto('login.php');
  await page.getByRole('tab', { name: `Login as ${role}` }).click();
  const panel = page.getByRole('tabpanel', { name: `Login as ${role}` });
  const identityLabel = role === 'Admin' ? 'Admin username or email *' : 'Student ID or email *';
  await panel.getByLabel(identityLabel, { exact: true }).fill(id);
  await panel.getByLabel('Password *', { exact: true }).fill(password);
  await panel.getByRole('button', { name: `Login as ${role}` }).click();
  await expect(page).toHaveURL(role === 'Admin' ? /admin\.php$/ : /dashboard\.php$/);
}

async function submitApplication(page, positionName) {
  const positionCard = page.locator('article').filter({ hasText: positionName });
  await expect(positionCard).toBeVisible();
  await positionCard.getByText('Apply for this position', { exact: true }).click();
  await positionCard.locator('textarea[name="statement_of_purpose"]').fill(statement);
  await positionCard.locator('textarea[name="qualifications"]').fill('Automated browser test applicant.');
  await positionCard.getByRole('button', { name: 'Submit application' }).click();
  await expect(page.getByText('Your application has been submitted successfully.', { exact: false })).toBeVisible();
  await expect(
    page.locator('article').filter({ hasText: positionName }).getByText('Already applied: pending', { exact: true })
  ).toBeVisible();
}

test('candidate review and safe election deletion work end to end', async ({ page }) => {
  await registerAccount(page, {
    firstName: 'E2E',
    lastName: `Student${suffix}`,
    email: studentEmail,
    id: studentId
  });
  await registerAccount(page, {
    firstName: 'E2E',
    lastName: `Admin${suffix}`,
    email: adminEmail,
    id: adminStudentId
  });

  const [studentUpdate] = await connection.execute(
    'UPDATE users SET is_verified = 1, is_active = 1 WHERE student_id = ?',
    [studentId]
  );
  const [adminUpdate] = await connection.execute(
    "UPDATE users SET role = 'admin', is_verified = 1, is_active = 1 WHERE student_id = ?",
    [adminStudentId]
  );
  expect(studentUpdate.affectedRows).toBe(1);
  expect(adminUpdate.affectedRows).toBe(1);

  await loginAs(page, 'Admin', adminStudentId);
  await page.goto('admin.php?nav=elections');
  for (const item of [
    'Dashboard',
    'Elections',
    'Positions',
    'Applications',
    'Candidates',
    'Students',
    'Voting',
    'Results',
    'Logout'
  ]) {
    await expect(page.locator('.app-sidebar').getByRole('link', { name: item, exact: true })).toBeVisible();
  }
  await expect(page.getByLabel('Position name')).toHaveCount(0);
  await expect(page.getByRole('heading', { name: 'Create a new election' })).toBeVisible();
  const startsAt = new Date(Date.now() + 2 * 24 * 60 * 60 * 1000);
  const endsAt = new Date(Date.now() + 3 * 24 * 60 * 60 * 1000);
  const toLocalDateTime = (date) => {
    const localDate = new Date(date.getTime() - date.getTimezoneOffset() * 60 * 1000);
    return localDate.toISOString().slice(0, 16);
  };
  await page.getByLabel('Election title').fill(electionTitle);
  await page.getByLabel('Voting starts').fill(toLocalDateTime(startsAt));
  await page.getByLabel('Voting ends').fill(toLocalDateTime(endsAt));
  await page.getByLabel('Description for students (optional)').fill('Playwright candidate application fixture');
  await page.getByRole('button', { name: 'Create draft and continue' }).click();
  await expect(page.getByText('Election created as a draft.', { exact: true })).toBeVisible();

  const [createdElectionRows] = await connection.execute(
    'SELECT id, status FROM elections WHERE title = ?',
    [electionTitle]
  );
  expect(createdElectionRows).toHaveLength(1);
  electionId = createdElectionRows[0].id;
  expect(createdElectionRows[0].status).toBe('draft');
  await page.goto('admin.php?nav=positions');
  await page.getByLabel('Select election').selectOption({ label: electionTitle });
  await page.getByRole('button', { name: 'Open election positions' }).click();
  const electionCardForSetup = page.locator('article.election').filter({ hasText: electionTitle });
  await expect(electionCardForSetup).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Create a new election' })).toHaveCount(0);
  const addPositionForm = electionCardForSetup.locator('form').filter({
    has: page.locator('input[name="action"][value="add_position"]')
  });
  for (const positionName of positionNames) {
    await addPositionForm.locator('input[name="name"]').fill(positionName);
    await addPositionForm.getByRole('button', { name: 'Add position' }).click();
    await expect(page.getByText('Position added. Student applications are open; you can close them in the position settings.', { exact: true })).toBeVisible();
    if (positionName !== positionNames[positionNames.length - 1]) {
      await page.goto(`admin.php?nav=positions&position_election_id=${electionId}`);
    }
  }
  const [createdPositions] = await connection.execute(
    'SELECT id, name, election_id, applications_open FROM positions WHERE election_id = ? ORDER BY sort_order',
    [electionId]
  );
  expect(createdPositions.map((position) => position.name)).toEqual(positionNames);
  expect(createdPositions.every((position) =>
    Number(position.election_id) === Number(electionId) && Number(position.applications_open) === 1
  )).toBe(true);
  await expect(electionCardForSetup).toContainText('2 positions');
  await connection.execute(
    `INSERT INTO candidates
       (election_id, position_id, student_id, name, manifesto, status)
     VALUES (?, ?, ?, 'E2E Pending Candidate', 'Pending review visibility fixture', 'pending')`,
    [electionId, createdPositions[0].id, `PENDING-${suffix}`]
  );

  const [studentRows] = await connection.execute(
    'SELECT id FROM users WHERE student_id = ?',
    [studentId]
  );
  const testUserId = studentRows[0].id;
  await connection.execute(
    'INSERT INTO election_eligibility (election_id, user_id) VALUES (?, ?)',
    [electionId, testUserId]
  );
  await connection.execute(
    'INSERT INTO election_eligibility (election_id, user_id) VALUES (?, ?)',
    [protectedElectionId, testUserId]
  );
  await connection.execute(
    'INSERT INTO election_participation (election_id, user_id) VALUES (?, ?)',
    [protectedElectionId, testUserId]
  );
  await connection.execute(
    'INSERT INTO ballots (election_id, position_id, candidate_id) VALUES (?, ?, ?)',
    [protectedElectionId, protectedPositionId, protectedCandidateId]
  );

  await page.goto('admin_logout.php');
  await loginAs(page, 'Student', studentId);
  const activeElectionSection = page.locator('section').filter({
    has: page.getByRole('heading', { name: 'In preparation' })
  });
  await expect(activeElectionSection.getByText(electionTitle, { exact: true })).toBeVisible();
  await expect(activeElectionSection).toContainText('This election is being prepared and is not open for voting.');
  await expect(activeElectionSection.getByRole('link', { name: 'Vote' })).toHaveCount(0);
  const upcomingElectionSection = page.locator('section').filter({
    has: page.getByRole('heading', { name: 'Upcoming elections' })
  });
  await expect(upcomingElectionSection.getByText('No elections in this category.')).toBeVisible();
  await expect(page.getByText(electionTitle, { exact: true })).toHaveCount(1);
  await page.goto('candidate_applications.php');
  for (const positionName of positionNames) {
    await expect(page.locator('article').filter({ hasText: positionName })).toBeVisible();
  }
  await submitApplication(page, positionNames[0]);
  await submitApplication(page, positionNames[1]);

  const [submittedApplications] = await connection.execute(
    `SELECT ca.election_id, ca.position_id, ca.status, p.name AS position_name,
            c.id AS candidate_id
     FROM candidate_applications ca
     JOIN positions p ON p.id = ca.position_id AND p.election_id = ca.election_id
     LEFT JOIN candidates c ON c.application_id = ca.id
     WHERE ca.user_id = ? AND ca.election_id = ?
     ORDER BY p.name`,
    [testUserId, electionId]
  );
  expect(submittedApplications).toHaveLength(2);
  expect(submittedApplications.map((application) => ({
    electionId: Number(application.election_id),
    positionName: application.position_name,
    status: application.status,
    candidateId: application.candidate_id
  }))).toEqual([
    { electionId: Number(electionId), positionName: positionNames[0], status: 'pending', candidateId: null },
    { electionId: Number(electionId), positionName: positionNames[1], status: 'pending', candidateId: null }
  ]);

  await page.goto('logout.php');
  await loginAs(page, 'Admin', adminStudentId);
  await page.goto('admin_candidate_applications.php');
  await expect(page.locator('.application-card')).toHaveCount(2);
  await expect(page.getByText(studentId, { exact: false })).toHaveCount(2);
  await expect(page.locator('.application-card .status.pending')).toHaveCount(2);

  const rejectCard = page.locator('.application-card').filter({ hasText: positionNames[1] });
  await rejectCard.locator('textarea[name="admin_feedback"]').fill(rejectionFeedback);
  page.once('dialog', (dialog) => dialog.accept());
  await rejectCard.getByRole('button', { name: 'Reject application' }).click();
  await expect(page.getByText('Application rejected.', { exact: false })).toBeVisible();
  await expect(
    page.locator('.application-card').filter({ hasText: positionNames[1] }).locator('.status.rejected')
  ).toBeVisible();

  const approveCard = page.locator('.application-card').filter({ hasText: positionNames[0] });
  page.once('dialog', (dialog) => dialog.accept());
  await approveCard.getByRole('button', { name: 'Approve application' }).click();
  await expect(page.getByText('Application approved and linked to official candidate', { exact: false })).toBeVisible();
  await expect(
    page.locator('.application-card').filter({ hasText: positionNames[0] }).locator('.status.approved')
  ).toBeVisible();

  await page.goto('admin.php?nav=candidates');
  await expect(page.getByLabel('Candidate status')).toHaveValue('approved');
  await expect(page.locator('.candidate').filter({ hasText: studentId })).toBeVisible();
  await expect(page.getByText('E2E Pending Candidate', { exact: true })).toHaveCount(0);
  await page.getByLabel('Candidate status').selectOption('pending');
  await page.getByRole('button', { name: 'Apply filters' }).click();
  await expect(page.getByText('E2E Pending Candidate', { exact: true })).toBeVisible();
  await page.goto('admin.php?nav=voting');
  await expect(
    page.locator('article.election')
      .filter({ hasText: protectedElectionTitle })
      .getByRole('button', { name: 'Close voting' })
  ).toBeVisible();
  await page.goto('results.php');
  await expect(
    page.locator('.app-sidebar').getByRole('link', { name: 'Results', exact: true })
  ).toHaveAttribute('aria-current', 'page');

  await page.goto('admin_logout.php');
  await loginAs(page, 'Student', studentId);
  await page.goto('candidate_applications.php');
  const approvedApplication = page.locator('#application-history article').filter({ hasText: positionNames[0] });
  const rejectedApplication = page.locator('#application-history article').filter({ hasText: positionNames[1] });
  await expect(approvedApplication.getByText('Your application has been approved.', { exact: false })).toBeVisible();
  await expect(rejectedApplication.getByText('Your application was not accepted.', { exact: true })).toBeVisible();
  await expect(rejectedApplication.locator('p').filter({ hasText: rejectionFeedback })).toContainText(rejectionFeedback);

  await connection.execute(
    "UPDATE elections SET status = 'scheduled' WHERE id = ?",
    [electionId]
  );
  await page.goto(`candidates.php?election_id=${electionId}`);
  const approvedPosition = page.locator('section').filter({
    has: page.getByRole('heading', { name: positionNames[0], exact: true })
  });
  const rejectedPosition = page.locator('section').filter({
    has: page.getByRole('heading', { name: positionNames[1], exact: true })
  });
  await expect(approvedPosition.locator('.candidate h3')).toHaveText(`E2E Student${suffix}`);
  await expect(rejectedPosition.getByText('No approved candidates for this position.', { exact: true })).toBeVisible();
  await expect(rejectedPosition.locator('.candidate')).toHaveCount(0);

  const [student] = await connection.execute('SELECT id FROM users WHERE student_id = ?', [studentId]);
  const [applications] = await connection.execute(
    `SELECT ca.id, ca.status, ca.admin_feedback, c.id AS candidate_id, c.status AS candidate_status
     FROM candidate_applications ca
     LEFT JOIN candidates c ON c.application_id = ca.id
     WHERE ca.user_id = ? AND ca.election_id = ?`,
    [student[0].id, electionId]
  );
  expect(applications).toHaveLength(2);

  const approved = applications.find((application) => application.status === 'approved');
  const rejected = applications.find((application) => application.status === 'rejected');
  expect(approved).toBeDefined();
  expect(approved.candidate_id).not.toBeNull();
  expect(approved.candidate_status).toBe('approved');
  expect(rejected).toBeDefined();
  expect(rejected.admin_feedback).toBe(rejectionFeedback);
  expect(rejected.candidate_id).toBeNull();

  const [auditRows] = await connection.execute(
    `SELECT action FROM admin_audit_log
     WHERE entity_type = 'candidate_application' AND entity_id IN (?, ?)
     ORDER BY action`,
    [approved.id, rejected.id]
  );
  expect(auditRows.map((row) => row.action).sort()).toEqual([
    'candidate_application_approved',
    'candidate_application_rejected'
  ]);

  const [studentNotifications] = await connection.execute(
    'SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND election_id = ?',
    [student[0].id, electionId]
  );
  expect(studentNotifications[0].total).toBe(4);

  await page.goto('admin_logout.php');
  await loginAs(page, 'Admin', adminStudentId);
  await page.goto('admin.php?nav=elections');

  const electionCard = page.locator('article.election').filter({ hasText: electionTitle });
  const deleteButton = electionCard.getByRole('button', { name: `Delete ${electionTitle}`, exact: true });
  await expect(deleteButton).toBeVisible();

  let confirmationText = '';
  page.once('dialog', async (dialog) => {
    confirmationText = dialog.message();
    await dialog.dismiss();
  });
  await deleteButton.click();
  expect(confirmationText).toContain(electionTitle);
  expect(confirmationText).toContain('2 position(s)');
  await expect(electionCard).toBeVisible();
  const [uncancelledElection] = await connection.execute(
    'SELECT COUNT(*) AS total FROM elections WHERE id = ?',
    [electionId]
  );
  expect(uncancelledElection[0].total).toBe(1);

  const [positionRows] = await connection.execute(
    'SELECT id FROM positions WHERE election_id = ? ORDER BY id LIMIT 1',
    [electionId]
  );
  const [notificationsBeforeDelete] = await connection.execute(
    'SELECT COUNT(*) AS total FROM notifications WHERE election_id = ?',
    [electionId]
  );
  await connection.query(
    `CREATE TABLE \`${deletionGuardTable}\` (
       position_id INT UNSIGNED NOT NULL PRIMARY KEY,
       CONSTRAINT \`fk_${deletionGuardTable}\` FOREIGN KEY (position_id)
         REFERENCES positions (id) ON DELETE RESTRICT
     ) ENGINE=InnoDB`
  );
  await connection.execute(
    `INSERT INTO \`${deletionGuardTable}\` (position_id) VALUES (?)`,
    [positionRows[0].id]
  );
  page.once('dialog', (dialog) => dialog.accept());
  await deleteButton.click();
  await expect(page.locator('.error')).toContainText('The administrative change could not be saved.');
  const [rollbackRows] = await connection.execute(
    `SELECT
       (SELECT COUNT(*) FROM elections WHERE id = ?) AS election_count,
       (SELECT COUNT(*) FROM positions WHERE election_id = ?) AS position_count,
       (SELECT COUNT(*) FROM candidate_applications WHERE election_id = ?) AS application_count,
       (SELECT COUNT(*) FROM candidates WHERE election_id = ?) AS candidate_count,
       (SELECT COUNT(*) FROM election_eligibility WHERE election_id = ?) AS eligibility_count,
       (SELECT COUNT(*) FROM notifications WHERE election_id = ?) AS linked_notifications`,
    [electionId, electionId, electionId, electionId, electionId, electionId]
  );
  expect(Object.values(rollbackRows[0])).toEqual([
    1,
    2,
    2,
    2,
    1,
    notificationsBeforeDelete[0].total
  ]);
  await connection.query(`DROP TABLE \`${deletionGuardTable}\``);

  page.once('dialog', (dialog) => dialog.accept());
  await deleteButton.click();
  await expect(page.getByText(`Election "${electionTitle}" and its positions`, { exact: false })).toBeVisible();
  await expect(page.locator('article.election').filter({ hasText: electionTitle })).toHaveCount(0);

  const [deletedRows] = await connection.execute(
    `SELECT
       (SELECT COUNT(*) FROM elections WHERE id = ?) AS election_count,
       (SELECT COUNT(*) FROM positions WHERE election_id = ?) AS position_count,
       (SELECT COUNT(*) FROM candidate_applications WHERE election_id = ?) AS application_count,
       (SELECT COUNT(*) FROM candidates WHERE election_id = ?) AS candidate_count,
       (SELECT COUNT(*) FROM election_eligibility WHERE election_id = ?) AS eligibility_count,
       (SELECT COUNT(*) FROM notifications WHERE election_id = ?) AS linked_notifications`,
    [electionId, electionId, electionId, electionId, electionId, electionId]
  );
  expect(Object.values(deletedRows[0])).toEqual([0, 0, 0, 0, 0, 0]);
  const [preservedNotifications] = await connection.execute(
    'SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND election_id IS NULL',
    [testUserId]
  );
  expect(preservedNotifications[0].total).toBeGreaterThanOrEqual(4);
  const [deleteAudit] = await connection.execute(
    `SELECT COUNT(*) AS total FROM admin_audit_log
     WHERE action = 'election_deleted' AND entity_type = 'election' AND entity_id = ?`,
    [electionId]
  );
  expect(deleteAudit[0].total).toBe(1);
  const [preservedReviewAudit] = await connection.execute(
    `SELECT COUNT(*) AS total FROM admin_audit_log
     WHERE entity_type = 'candidate_application' AND entity_id IN (?, ?)
       AND action IN ('candidate_application_approved', 'candidate_application_rejected')`,
    [approved.id, rejected.id]
  );
  expect(preservedReviewAudit[0].total).toBe(2);

  await page.goto('admin.php');
  const [remainingElectionCount] = await connection.execute(
    'SELECT COUNT(*) AS total FROM elections'
  );
  await expect(page.locator('.stats .stat').nth(3).locator('strong')).toHaveText(
    String(remainingElectionCount[0].total)
  );
  await page.goto('admin.php?nav=elections');
  await expect(
    page.locator('#candidate_position option').filter({ hasText: positionNames[0] })
  ).toHaveCount(0);

  const protectedCard = page.locator('article.election').filter({ hasText: protectedElectionTitle });
  let protectedDeleteButton = protectedCard.getByRole('button', { name: `Deletion blocked for ${protectedElectionTitle}` });
  await expect(protectedDeleteButton).toBeDisabled();
  await expect(protectedCard).toContainText('cannot be permanently deleted');

  await protectedDeleteButton.evaluate((button) => {
    button.form.elements.csrf_token.value = 'invalid-csrf-token';
    button.form.requestSubmit();
  });
  await expect(page.locator('.error')).toContainText('Your session expired.');
  await page.goto('admin.php?nav=elections');

  protectedDeleteButton = page
    .locator('article.election')
    .filter({ hasText: protectedElectionTitle })
    .getByRole('button', { name: `Deletion blocked for ${protectedElectionTitle}` });
  await protectedDeleteButton.evaluate((button) => {
    button.form.elements.election_id.value = '2147483647';
    button.form.requestSubmit();
  });
  await expect(page.locator('.error')).toContainText('This election no longer exists.');
  const [invalidRequestRecords] = await connection.execute(
    'SELECT COUNT(*) AS total FROM elections WHERE id = ?',
    [protectedElectionId]
  );
  expect(invalidRequestRecords[0].total).toBe(1);
  await page.goto('admin.php?nav=elections');

  protectedDeleteButton = page
    .locator('article.election')
    .filter({ hasText: protectedElectionTitle })
    .getByRole('button', { name: `Deletion blocked for ${protectedElectionTitle}` });
  await protectedDeleteButton.evaluate((button) => button.closest('form').requestSubmit());
  await expect(page.locator('.error')).toContainText(
    'This election has voting records or published results and cannot be permanently deleted.'
  );
  const [protectedRows] = await connection.execute(
    `SELECT
       (SELECT COUNT(*) FROM elections WHERE id = ?) AS election_count,
       (SELECT COUNT(*) FROM ballots WHERE election_id = ?) AS ballot_count,
       (SELECT COUNT(*) FROM election_participation WHERE election_id = ?) AS participation_count`,
    [protectedElectionId, protectedElectionId, protectedElectionId]
  );
  expect(Object.values(protectedRows[0])).toEqual([1, 1, 1]);

  await page.goto('logout.php');
  await loginAs(page, 'Student', studentId);
  await page.goto('admin.php?nav=elections');
  await expect(page).toHaveURL(/login\.php$/);
  await loginAs(page, 'Student', studentId);
  await page.goto('candidate_applications.php');
  await expect(page.getByText(electionTitle, { exact: false })).toHaveCount(0);
  const candidatesResponse = await page.goto(`candidates.php?election_id=${electionId}`);
  expect(candidatesResponse.status()).toBe(404);
  await expect(page.getByText('Election not found.', { exact: true })).toBeVisible();
  await page.goto(`vote.php?election_id=${electionId}`);
  await expect(page.getByText('Choose an active election', { exact: true })).toBeVisible();
  await expect(page.getByText(protectedElectionTitle, { exact: true })).toBeVisible();
  await expect(page.locator('body')).not.toContainText(electionTitle);

  await page.goto(`dashboard.php?q=${encodeURIComponent(electionTitle)}`);
  await expect(page.locator('article.card').filter({ hasText: electionTitle })).toHaveCount(0);
  await page.goto('logout.php');
  await page.goto('admin.php?nav=elections');
  await expect(page).toHaveURL(/login\.php$/);
});
