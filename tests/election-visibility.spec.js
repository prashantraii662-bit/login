const { test, expect } = require('@playwright/test');
const mysql = require('mysql2/promise');
const { randomUUID } = require('node:crypto');

const suffix = randomUUID().replace(/-/g, '').slice(0, 12).toUpperCase();
const studentId = `E2E-${suffix}-S`;
const adminStudentId = `E2E-${suffix}-A`;
const studentEmail = `e2e.student.${suffix.toLowerCase()}@example.invalid`;
const adminEmail = `e2e.admin.${suffix.toLowerCase()}@example.invalid`;
const password = 'E2eTest!2026';
const electionTitle = `E2E Election Visibility ${suffix}`;
const positionName = `E2E President ${suffix}`;
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
         WHERE entity_type = 'position' AND entity_id IN
           (SELECT id FROM positions WHERE election_id = ?)`,
        [electionId]
      );
      await connection.execute(
        "DELETE FROM admin_audit_log WHERE entity_type = 'election' AND entity_id = ?",
        [electionId]
      );
      await connection.execute('DELETE FROM positions WHERE election_id = ?', [electionId]);
      await connection.execute('DELETE FROM elections WHERE id = ?', [electionId]);
    }
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

test('admin-created draft election appears in the student Elections list', async ({ page }) => {
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
  const startsAt = new Date(Date.now() + 2 * 24 * 60 * 60 * 1000);
  const endsAt = new Date(Date.now() + 3 * 24 * 60 * 60 * 1000);
  const toLocalDateTime = (date) => {
    const localDate = new Date(date.getTime() - date.getTimezoneOffset() * 60 * 1000);
    return localDate.toISOString().slice(0, 16);
  };
  await page.getByLabel('Election title').fill(electionTitle);
  await page.getByLabel('Voting starts').fill(toLocalDateTime(startsAt));
  await page.getByLabel('Voting ends').fill(toLocalDateTime(endsAt));
  await page.getByLabel('Description for students (optional)').fill('Election visibility end-to-end test');
  await page.getByRole('button', { name: 'Create draft and continue' }).click();
  await expect(page.getByText('Election created as a draft.', { exact: true })).toBeVisible();

  const [createdElections] = await connection.execute(
    'SELECT id, title, description, status FROM elections WHERE title = ?',
    [electionTitle]
  );
  expect(createdElections).toHaveLength(1);
  electionId = createdElections[0].id;
  expect(createdElections[0]).toMatchObject({
    title: electionTitle,
    description: 'Election visibility end-to-end test',
    status: 'draft'
  });
  await expect(
    page.locator('article.election').filter({ hasText: electionTitle })
  ).toBeVisible();

  await page.goto('admin.php?nav=positions');
  await page.getByLabel('Select election').selectOption({ label: electionTitle });
  await page.getByRole('button', { name: 'Open election positions' }).click();
  const electionPositionCard = page.locator('article.election').filter({ hasText: electionTitle });
  const addPositionForm = electionPositionCard.locator('form').filter({
    has: page.locator('input[name="action"][value="add_position"]')
  });
  await addPositionForm.locator('input[name="name"]').fill(positionName);
  await addPositionForm.getByRole('button', { name: 'Add position' }).click();
  await expect(page.getByText('Position added.', { exact: false })).toBeVisible();

  const [createdPositions] = await connection.execute(
    'SELECT id, name, election_id FROM positions WHERE name = ?',
    [positionName]
  );
  expect(createdPositions).toHaveLength(1);
  expect(Number(createdPositions[0].election_id)).toBe(Number(electionId));
  await expect(electionPositionCard).toContainText(positionName);

  await page.goto('admin_logout.php');
  await loginAs(page, 'Student', studentId);
  const inPreparationSection = page.locator('section').filter({
    has: page.getByRole('heading', { name: 'In preparation' })
  });
  const electionCard = inPreparationSection.locator('article.card').filter({ hasText: electionTitle });
  await expect(electionCard).toBeVisible();
  await expect(electionCard).toContainText('Election visibility end-to-end test');
  await expect(electionCard).toContainText('Status: draft');
  await expect(electionCard.getByRole('link', { name: 'Vote' })).toHaveCount(0);
  await electionCard.getByRole('link', { name: 'View positions' }).click();
  await expect(page).toHaveURL(new RegExp(`election_positions\\.php\\?election_id=${electionId}`));
  await expect(page.getByRole('heading', { name: electionTitle })).toBeVisible();
  await expect(page.locator('article.position').getByRole('heading', { name: positionName })).toBeVisible();
  await expect(page.locator('article.position')).toHaveCount(1);
});
