const { test, expect } = require('@playwright/test');
const mysql = require('mysql2/promise');
const { randomUUID } = require('node:crypto');

const suffix = randomUUID().replace(/-/g, '').slice(0, 12).toUpperCase();
const studentId = `E2E-${suffix}-S`;
const adminStudentId = `E2E-${suffix}-A`;
const studentEmail = `e2e.student.${suffix.toLowerCase()}@example.invalid`;
const adminEmail = `e2e.admin.${suffix.toLowerCase()}@example.invalid`;
const password = 'E2eTest!2026';
const electionTitle = `E2E Voting Results ${suffix}`;
const positionName = `E2E President ${suffix}`;
const approvedCandidateName = `E2E Approved ${suffix}`;
const pendingCandidateName = `E2E Pending ${suffix}`;

let connection;
let electionId;
let positionId;
let approvedCandidateId;
let pendingCandidateId;

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
    const electionIds = electionId ? [electionId] : [];
    for (const id of electionIds) {
      await connection.execute('DELETE FROM ballots WHERE election_id = ?', [id]);
      await connection.execute('DELETE FROM election_participation WHERE election_id = ?', [id]);
      await connection.execute('DELETE FROM election_eligibility WHERE election_id = ?', [id]);
      await connection.execute('DELETE FROM candidate_applications WHERE election_id = ?', [id]);
      await connection.execute('DELETE FROM candidates WHERE election_id = ?', [id]);
      await connection.execute('DELETE FROM positions WHERE election_id = ?', [id]);
      await connection.execute(
        'DELETE FROM notifications WHERE election_id = ?',
        [id]
      );
      await connection.execute(
        "DELETE FROM admin_audit_log WHERE entity_type = 'election' AND entity_id = ?",
        [id]
      );
      await connection.execute('DELETE FROM elections WHERE id = ?', [id]);
    }
    await connection.execute(
      'DELETE FROM notifications WHERE user_id IN (SELECT id FROM users WHERE student_id IN (?, ?))',
      [studentId, adminStudentId]
    );
    await connection.execute(
      'DELETE FROM admin_audit_log WHERE actor_user_id IN (SELECT id FROM users WHERE student_id IN (?, ?))',
      [studentId, adminStudentId]
    );
    await connection.execute(
      'DELETE FROM users WHERE student_id IN (?, ?)',
      [studentId, adminStudentId]
    );
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

test('active voting, one-ballot enforcement, closing, publishing, and student results work end to end', async ({ page }) => {
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

  const [electionResult] = await connection.execute(
    `INSERT INTO elections (title, description, starts_at, ends_at, status)
     VALUES (?, 'Playwright voting and results fixture',
             DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 HOUR),
             DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 1 DAY), 'scheduled')`,
    [electionTitle]
  );
  electionId = electionResult.insertId;
  const [positionResult] = await connection.execute(
    'INSERT INTO positions (election_id, name, description, sort_order) VALUES (?, ?, ?, 1)',
    [electionId, positionName, 'Fixture position for the voting workflow']
  );
  positionId = positionResult.insertId;
  const [approvedCandidateResult] = await connection.execute(
    `INSERT INTO candidates
       (election_id, position_id, student_id, name, manifesto, status)
     VALUES (?, ?, ?, ?, 'Approved test candidate', 'approved')`,
    [electionId, positionId, `C-${suffix}`, approvedCandidateName]
  );
  approvedCandidateId = approvedCandidateResult.insertId;
  const [pendingCandidateResult] = await connection.execute(
    `INSERT INTO candidates
       (election_id, position_id, student_id, name, manifesto, status)
     VALUES (?, ?, ?, ?, 'Pending candidates cannot be selected', 'pending')`,
    [electionId, positionId, `P-${suffix}`, pendingCandidateName]
  );
  pendingCandidateId = pendingCandidateResult.insertId;

  await loginAs(page, 'Admin', adminStudentId);
  await page.goto('admin.php?nav=voting');
  const electionCard = page.locator('article.election').filter({ hasText: electionTitle });
  await expect(electionCard.getByRole('button', { name: 'Activate voting' })).toBeVisible();
  await electionCard.getByRole('button', { name: 'Activate voting' }).click();
  await expect(page.getByText('Election status updated.', { exact: true })).toBeVisible();
  const [activeElection] = await connection.execute(
    "SELECT status FROM elections WHERE id = ?",
    [electionId]
  );
  expect(activeElection[0].status).toBe('active');

  await page.goto('admin_logout.php');
  await loginAs(page, 'Student', studentId);
  const activeCard = page.locator('section').filter({
    has: page.getByRole('heading', { name: 'Active elections' })
  }).locator('article.card').filter({ hasText: electionTitle });
  await expect(activeCard).toBeVisible();
  await page.goto(`candidates.php?election_id=${electionId}`);
  await expect(page.getByText(approvedCandidateName, { exact: true })).toBeVisible();
  await expect(page.getByText(pendingCandidateName, { exact: true })).toHaveCount(0);
  await page.goto(`vote.php?election_id=${electionId}`);
  await expect(page.getByRole('heading', { name: positionName, exact: true })).toBeVisible();
  await expect(page.getByText(approvedCandidateName, { exact: true })).toBeVisible();
  await expect(page.getByText(pendingCandidateName, { exact: true })).toHaveCount(0);

  const approvedRadio = page.locator(`input[name="selection[${positionId}]"]`);
  await approvedRadio.evaluate((input, candidateId) => {
    input.value = String(candidateId);
    input.checked = true;
  }, pendingCandidateId);
  page.once('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: 'Review and submit ballot' }).click();
  await expect(page.locator('.error')).toContainText('One or more selections are not valid for this election.');
  const [invalidBallotCounts] = await connection.execute(
    `SELECT
       (SELECT COUNT(*) FROM election_participation WHERE election_id = ? AND user_id = (SELECT id FROM users WHERE student_id = ?)) AS participation_count,
       (SELECT COUNT(*) FROM ballots WHERE election_id = ?) AS ballot_count`,
    [electionId, studentId, electionId]
  );
  expect(Object.values(invalidBallotCounts[0])).toEqual([0, 0]);

  await page.goto(`vote.php?election_id=${electionId}`);
  await page.locator(`input[name="selection[${positionId}]"][value="${approvedCandidateId}"]`).check();
  const csrfToken = await page.locator('input[name="csrf_token"]').inputValue();
  page.once('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: 'Review and submit ballot' }).click();
  await expect(page).toHaveURL(/vote_success\.php$/);
  await expect(page.getByRole('heading', { name: 'Vote Successfully Submitted!' })).toBeVisible();

  const [storedBallot] = await connection.execute(
    `SELECT ep.user_id, b.position_id, b.candidate_id, c.status AS candidate_status
     FROM election_participation ep
     JOIN ballots b ON b.election_id = ep.election_id
     JOIN candidates c ON c.id = b.candidate_id
     WHERE ep.election_id = ? AND ep.user_id = (SELECT id FROM users WHERE student_id = ?)`,
    [electionId, studentId]
  );
  expect(storedBallot).toHaveLength(1);
  expect(Number(storedBallot[0].position_id)).toBe(Number(positionId));
  expect(Number(storedBallot[0].candidate_id)).toBe(Number(approvedCandidateId));
  expect(storedBallot[0].candidate_status).toBe('approved');

  const duplicateResponse = await page.request.post(`vote.php?election_id=${electionId}`, {
    form: {
      csrf_token: csrfToken,
      election_id: String(electionId),
      [`selection[${positionId}]`]: String(approvedCandidateId)
    }
  });
  expect(duplicateResponse.status()).toBe(200);
  expect(await duplicateResponse.text()).toContain('A ballot has already been submitted for this election.');
  const [duplicateCounts] = await connection.execute(
    `SELECT
       (SELECT COUNT(*) FROM election_participation WHERE election_id = ? AND user_id = (SELECT id FROM users WHERE student_id = ?)) AS participation_count,
       (SELECT COUNT(*) FROM ballots WHERE election_id = ?) AS ballot_count`,
    [electionId, studentId, electionId]
  );
  expect(Object.values(duplicateCounts[0])).toEqual([1, 1]);

  await page.goto('admin_logout.php');
  await loginAs(page, 'Admin', adminStudentId);
  await page.goto('admin.php?nav=voting');
  const activeElectionCard = page.locator('article.election').filter({ hasText: electionTitle });
  page.once('dialog', (dialog) => dialog.accept());
  await activeElectionCard.getByRole('button', { name: 'Close voting' }).click();
  await expect(page.getByText('Election status updated.', { exact: true })).toBeVisible();
  const [closedElection] = await connection.execute(
    'SELECT status, results_published FROM elections WHERE id = ?',
    [electionId]
  );
  expect(closedElection[0]).toMatchObject({ status: 'closed', results_published: 0 });

  await page.goto('admin_logout.php');
  await loginAs(page, 'Student', studentId);
  const unpublishedResponse = await page.goto(`results.php?election_id=${electionId}`);
  expect(unpublishedResponse.status()).toBe(404);
  await expect(page.getByText('Results are not available for this election.', { exact: true })).toBeVisible();
  await page.goto(`vote.php?election_id=${electionId}`);
  await expect(page.getByText('No active elections', { exact: true })).toBeVisible();

  await page.goto('admin_logout.php');
  await loginAs(page, 'Admin', adminStudentId);
  await page.goto(`results.php?election_id=${electionId}`);
  await expect(page.getByText('These tallies are visible only to administrators until results are published.')).toBeVisible();
  const resultCard = page.locator('.card').filter({ hasText: approvedCandidateName });
  await expect(resultCard).toContainText('1 vote');
  await expect(page.locator('.card').filter({ hasText: pendingCandidateName })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Publish results' })).toBeVisible();
  page.once('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: 'Publish results' }).click();
  await expect(page.getByText('Results are now published.', { exact: true })).toBeVisible();
  const [publishedElection] = await connection.execute(
    'SELECT status, results_published FROM elections WHERE id = ?',
    [electionId]
  );
  expect(publishedElection[0]).toMatchObject({ status: 'closed', results_published: 1 });

  await page.goto('admin_logout.php');
  await loginAs(page, 'Student', studentId);
  await page.goto('dashboard.php');
  const completedCard = page.locator('section').filter({
    has: page.getByRole('heading', { name: 'Completed elections' })
  }).locator('article.card').filter({ hasText: electionTitle });
  await expect(completedCard.getByRole('link', { name: 'Published results' })).toBeVisible();
  await completedCard.getByRole('link', { name: 'Published results' }).click();
  await expect(page.getByRole('heading', { name: `${electionTitle} — Results` })).toBeVisible();
  await expect(page.getByText('1 vote', { exact: false })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Publish results' })).toHaveCount(0);
  await expect(page.locator('form')).toHaveCount(0);

  await page.goto('admin.php?nav=voting');
  await expect(page).toHaveURL(/login\.php$/);
}); 
