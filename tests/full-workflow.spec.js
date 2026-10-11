const { test, expect } = require('@playwright/test');
const mysql = require('mysql2/promise');
const { randomUUID } = require('node:crypto');

const suffix = randomUUID().replace(/-/g, '').slice(0, 12).toUpperCase();
const studentId = `E2E-${suffix}-S`;
const adminStudentId = `E2E-${suffix}-A`;
const studentEmail = `e2e.student.${suffix.toLowerCase()}@example.invalid`;
const adminEmail = `e2e.admin.${suffix.toLowerCase()}@example.invalid`;
const password = 'E2eTest!2026';
const electionTitle = `E2E Full Workflow ${suffix}`;
const positionName = `E2E President ${suffix}`;
const statement = 'I am applying to represent students and serve our campus community.';

let connection;
let electionId;

test.beforeAll(async () => {
  connection = await mysql.createConnection({
    host: process.env.DB_HOST || 'localhost',
    port: Number(process.env.DB_PORT || 3306),
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASSWORD || '',
    database: process.env.DB_NAME || 'voting_system'
  });
});

test.afterAll(async () => {
  if (!connection) {
    return;
  }

  try {
    await connection.beginTransaction();
    if (!electionId) {
      const [rows] = await connection.execute(
        'SELECT id FROM elections WHERE title = ?',
        [electionTitle]
      );
      electionId = rows[0]?.id;
    }
    if (electionId) {
      await connection.execute(
        `DELETE FROM admin_audit_log
         WHERE (entity_type = 'election' AND entity_id = ?)
            OR (entity_type = 'candidate_application' AND entity_id IN
                (SELECT id FROM candidate_applications WHERE election_id = ?))`,
        [electionId, electionId]
      );
      await connection.execute('DELETE FROM ballots WHERE election_id = ?', [electionId]);
      await connection.execute('DELETE FROM election_participation WHERE election_id = ?', [electionId]);
      await connection.execute('DELETE FROM election_eligibility WHERE election_id = ?', [electionId]);
      await connection.execute('DELETE FROM candidate_applications WHERE election_id = ?', [electionId]);
      await connection.execute('DELETE FROM candidates WHERE election_id = ?', [electionId]);
      await connection.execute('DELETE FROM positions WHERE election_id = ?', [electionId]);
      await connection.execute('DELETE FROM notifications WHERE election_id = ?', [electionId]);
      await connection.execute('DELETE FROM elections WHERE id = ?', [electionId]);
    }
    await connection.execute(
      'DELETE FROM notifications WHERE user_id IN (SELECT id FROM users WHERE student_id IN (?, ?))',
      [studentId, adminStudentId]
    );
    await connection.execute(
      'DELETE FROM admin_audit_log WHERE actor_user_id IN (SELECT id FROM users WHERE student_id IN (?, ?))',
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

async function expectNavigation(page, expectedLabels) {
  const labels = await page.locator('.app-nav .app-nav-link').evaluateAll((links) =>
    links.map((link) => link.querySelector('span:last-child').textContent.trim())
  );
  expect(labels.slice(0, expectedLabels.length)).toEqual(expectedLabels);
}

test('admin-to-student election, application, candidate, voting, and published results flow works end to end', async ({ page }) => {
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
  await expectNavigation(page, [
    'Dashboard', 'Elections', 'Positions', 'Applications',
    'Candidates', 'Students', 'Voting', 'Results'
  ]);
  const startsAt = new Date(Date.now() + 2 * 24 * 60 * 60 * 1000);
  const endsAt = new Date(Date.now() + 3 * 24 * 60 * 60 * 1000);
  const toLocalDateTime = (date) => {
    const localDate = new Date(date.getTime() - date.getTimezoneOffset() * 60 * 1000);
    return localDate.toISOString().slice(0, 16);
  };
  await page.getByLabel('Election title').fill(electionTitle);
  await page.getByLabel('Voting starts').fill(toLocalDateTime(startsAt));
  await page.getByLabel('Voting ends').fill(toLocalDateTime(endsAt));
  await page.getByLabel('Description for students (optional)').fill('End-to-end workflow verification.');
  await page.getByRole('button', { name: 'Create draft and continue' }).click();
  await expect(page.getByText('Election created as a draft.', { exact: true })).toBeVisible();

  const [elections] = await connection.execute(
    'SELECT id, status FROM elections WHERE title = ?',
    [electionTitle]
  );
  expect(elections).toHaveLength(1);
  electionId = elections[0].id;
  expect(elections[0].status).toBe('draft');

  await page.goto('admin_logout.php');
  await loginAs(page, 'Student', studentId);
  await expectNavigation(page, [
    'Dashboard', 'Elections', 'Applications', 'Candidates', 'Vote', 'Results', 'Profile'
  ]);
  const draftCard = page.locator('section').filter({
    has: page.getByRole('heading', { name: 'In preparation' })
  }).locator('article.card').filter({ hasText: electionTitle });
  await expect(draftCard).toBeVisible();
  await draftCard.getByRole('link', { name: 'View positions' }).click();
  await expect(page.getByText('No positions have been added to this election yet.', { exact: true })).toBeVisible();

  await page.goto('logout.php');
  await loginAs(page, 'Admin', adminStudentId);
  await page.goto('admin.php?nav=positions');
  await page.getByLabel('Select election').selectOption({ label: electionTitle });
  await page.getByRole('button', { name: 'Open election positions' }).click();
  const positionForm = page.locator('article.election').filter({ hasText: electionTitle }).locator('form').filter({
    has: page.locator('input[name="action"][value="add_position"]')
  });
  await positionForm.locator('input[name="name"]').fill(positionName);
  await positionForm.getByRole('button', { name: 'Add position' }).click();
  await expect(page.getByText('Position added.', { exact: false })).toBeVisible();

  const [positions] = await connection.execute(
    'SELECT id, election_id FROM positions WHERE election_id = ? AND name = ?',
    [electionId, positionName]
  );
  expect(positions).toHaveLength(1);
  const positionId = positions[0].id;
  expect(Number(positions[0].election_id)).toBe(Number(electionId));

  await page.goto('admin_logout.php');
  await loginAs(page, 'Student', studentId);
  await page.goto(`election_positions.php?election_id=${electionId}`);
  await expect(page.locator('article.position').getByRole('heading', { name: positionName })).toBeVisible();
  await page.goto('candidate_applications.php');
  const positionCard = page.locator('article').filter({ hasText: positionName });
  await positionCard.getByText('Apply for this position', { exact: true }).click();
  await positionCard.locator('textarea[name="statement_of_purpose"]').fill(statement);
  await positionCard.getByRole('button', { name: 'Submit application' }).click();
  await expect(page.getByText('Your application has been submitted successfully.', { exact: false })).toBeVisible();

  const [studentRows] = await connection.execute(
    'SELECT id FROM users WHERE student_id = ?',
    [studentId]
  );
  const userId = studentRows[0].id;
  const [applications] = await connection.execute(
    `SELECT ca.id, ca.election_id, ca.position_id, ca.status
     FROM candidate_applications ca
     JOIN positions p ON p.id = ca.position_id AND p.election_id = ca.election_id
     WHERE ca.user_id = ? AND ca.position_id = ?`,
    [userId, positionId]
  );
  expect(applications).toHaveLength(1);
  expect(Number(applications[0].election_id)).toBe(Number(electionId));
  expect(Number(applications[0].position_id)).toBe(Number(positionId));
  expect(applications[0].status).toBe('pending');

  await page.goto('logout.php');
  await loginAs(page, 'Admin', adminStudentId);
  await page.goto('admin_candidate_applications.php');
  const applicationCard = page.locator('.application-card').filter({ hasText: positionName });
  await expect(applicationCard.locator('.status.pending')).toBeVisible();
  page.once('dialog', (dialog) => dialog.accept());
  await applicationCard.getByRole('button', { name: 'Approve application' }).click();
  await expect(page.getByText('Application approved and linked to official candidate', { exact: false })).toBeVisible();

  const [officialCandidates] = await connection.execute(
    `SELECT c.id, c.status, c.application_id
     FROM candidates c
     JOIN candidate_applications ca ON ca.id = c.application_id
     WHERE ca.id = ? AND c.election_id = ? AND c.position_id = ?`,
    [applications[0].id, electionId, positionId]
  );
  expect(officialCandidates).toHaveLength(1);
  expect(officialCandidates[0].status).toBe('approved');

  await page.goto('admin.php?nav=voting');
  const votingCard = page.locator('article.election').filter({ hasText: electionTitle });
  await votingCard.getByRole('button', { name: 'Schedule election' }).click();
  await expect(page.getByText('Election status updated.', { exact: true })).toBeVisible();
  await connection.execute(
    'UPDATE elections SET starts_at = DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 5 MINUTE) WHERE id = ?',
    [electionId]
  );
  await page.goto('admin.php?nav=voting');
  await page.locator('article.election').filter({ hasText: electionTitle })
    .getByRole('button', { name: 'Activate voting' }).click();
  await expect(page.getByText('Election status updated.', { exact: true })).toBeVisible();

  await page.goto('admin_logout.php');
  await loginAs(page, 'Student', studentId);
  const activeCard = page.locator('section').filter({
    has: page.getByRole('heading', { name: 'Active elections' })
  }).locator('article.card').filter({ hasText: electionTitle });
  await expect(activeCard).toBeVisible();
  await page.goto(`candidates.php?election_id=${electionId}`);
  await expect(page.locator('.candidate h3')).toHaveText(`E2E Student${suffix}`);
  await page.goto(`vote.php?election_id=${electionId}`);
  await expect(
    page.getByRole('group', { name: positionName }).getByText(`E2E Student${suffix}`, { exact: true })
  ).toBeVisible();
  const candidateInput = page.locator(`input[name="selection[${positionId}]"]`);
  const candidateId = Number(await candidateInput.first().getAttribute('value'));
  await candidateInput.first().check();
  page.once('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: 'Review and submit ballot' }).click();
  await expect(page).toHaveURL(/vote_success\.php$/);
  const [ballots] = await connection.execute(
    `SELECT b.candidate_id, c.status
     FROM ballots b JOIN candidates c ON c.id = b.candidate_id
     WHERE b.election_id = ? AND b.position_id = ?`,
    [electionId, positionId]
  );
  expect(ballots).toHaveLength(1);
  expect(Number(ballots[0].candidate_id)).toBe(candidateId);
  expect(ballots[0].status).toBe('approved');

  await page.goto('admin_logout.php');
  await loginAs(page, 'Admin', adminStudentId);
  await page.goto('admin.php?nav=voting');
  page.once('dialog', (dialog) => dialog.accept());
  await page.locator('article.election').filter({ hasText: electionTitle })
    .getByRole('button', { name: 'Close voting' }).click();
  await expect(page.getByText('Election status updated.', { exact: true })).toBeVisible();
  await page.goto(`results.php?election_id=${electionId}`);
  await expect(page.getByRole('button', { name: 'Publish results' })).toBeVisible();
  await expect(page.locator('.card').filter({ hasText: `E2E Student${suffix}` })).toContainText('1 vote');
  page.once('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: 'Publish results' }).click();
  await expect(page.getByText('Results are now published.', { exact: true })).toBeVisible();

  await page.goto('admin_logout.php');
  await loginAs(page, 'Student', studentId);
  await page.goto('dashboard.php');
  const completedCard = page.locator('section').filter({
    has: page.getByRole('heading', { name: 'Completed elections' })
  }).locator('article.card').filter({ hasText: electionTitle });
  await completedCard.getByRole('link', { name: 'Published results' }).click();
  await expect(page.getByRole('heading', { name: `${electionTitle} — Results` })).toBeVisible();
  await expect(page.locator('.card').filter({ hasText: `E2E Student${suffix}` })).toContainText('1 vote');
  await expect(page.getByRole('button', { name: 'Publish results' })).toHaveCount(0);
});
