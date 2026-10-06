const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
async function login(page, user = 'bpi_alice') {
  await page.context().clearCookies();
  // WordPress's load-time autofocus must finish before filling the password.
  await page.goto('/wp-login.php?redirect_to=' + encodeURIComponent('/?pagename=community'), { waitUntil: 'load' });
  await page.getByLabel('Username or Email Address').fill(user);
  await page.locator('#user_pass').fill('bpi-test-password');
  await Promise.all([
    page.waitForURL(url => !url.pathname.endsWith('/wp-login.php'), { waitUntil: 'domcontentloaded' }),
    page.locator('#wp-submit').click()
  ]);
  await page.goto('/?pagename=community', { waitUntil: 'domcontentloaded' });
  await expect(page.getByRole('heading', { name: 'Feed', exact: true })).toBeVisible();
  await expect(page.getByRole('menuitem', { name: `Howdy, ${user}` })).toBeVisible();
}
async function saveAndReload(page, label) {
  await Promise.all([
    page.waitForEvent('domcontentloaded'),
    page.getByRole('button', { name: label, exact: true }).click()
  ]);
  await expect(page.getByRole('status').filter({ hasText: 'Your changes were saved.' })).toBeVisible();
}
test('anonymous state does not expose member content', async ({ page }) => {
  await page.goto('/?pagename=community');
  await expect(page.getByRole('link', { name: 'Sign in to use community tools.' })).toBeVisible();
  const response = await page.request.get('/?rest_route=/buddypress-intelligence/v1/graph');
  expect(response.status()).toBe(401);
});
test('keyboard preferences, loading and success flow', async ({ page }) => {
  await login(page);
  await page.getByRole('link', { name: 'Interests and privacy', exact: true }).click();
  const goals = page.getByLabel('Goals (optional)');
  await goals.fill('');
  await goals.focus();
  await page.keyboard.type('Help other developers');
  await page.getByRole('button', { name: 'Save preferences' }).focus();
  const request = page.waitForRequest(r => r.method() === 'POST' && r.url().includes('/buddypress-intelligence/v1/preferences'));
  await page.keyboard.press('Enter');
  await request;
  await expect(page.getByRole('status').filter({ hasText: 'Your changes were saved.' })).toBeVisible();
  await expect(page.getByLabel('Goals (optional)')).toHaveValue('Help other developers');
});
test('search empty state, mobile layout, RTL and accessibility', async ({ page }) => {
  await login(page);
  await page.setViewportSize({ width: 390, height: 844 });
  await page.getByRole('link', { name: 'Search', exact: true }).first().click();
  await page.getByLabel('Search your community').fill('no-result-unique-phrase');
  await page.getByRole('button', { name: 'Search', exact: true }).click();
  await expect(page.getByText('No matching items yet. Try other interests or a broader search.')).toBeVisible();
  await page.evaluate(() => document.documentElement.dir = 'rtl');
  const overflow = await page.locator('.bpi-community').first().evaluate(element => element.scrollWidth > element.clientWidth + 1);
  expect(overflow).toBe(false);
  const results = await new AxeBuilder({ page }).include('.bpi-community').withTags(['wcag2a','wcag2aa','wcag21aa','wcag22aa']).analyze();
  expect(results.violations).toEqual([]);
  await page.screenshot({ path: '.runtime/community-mobile-rtl.png', fullPage: true });
  await page.emulateMedia({ colorScheme: 'dark' });
  const dark = await new AxeBuilder({ page }).include('.bpi-community').withTags(['wcag2a','wcag2aa','wcag21aa','wcag22aa']).analyze();
  expect(dark.violations).toEqual([]);
  await page.screenshot({ path: '.runtime/community-mobile-dark.png', fullPage: true });
});
test('no-JavaScript preferences use nonce protected form fallback', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();
  await login(page);
  await page.getByRole('link', { name: 'Interests and privacy', exact: true }).click();
  await page.getByLabel('Goals (optional)').fill('Accessible without JavaScript');
  await page.getByRole('button', { name: 'Save preferences' }).click();
  await expect(page.getByText('Your changes were saved.')).toBeVisible();
  await expect(page.getByLabel('Goals (optional)')).toHaveValue('Accessible without JavaScript');
  await context.close();
});
test('moderation admin screen is keyboard operable and accessible', async ({ page }) => {
  await login(page, 'bpi_admin');
  await page.goto('/wp-admin/admin.php?page=bpi-moderation');
  await expect(page.getByRole('heading', { name: 'Moderation', exact: true })).toBeVisible();
  const results = await new AxeBuilder({ page }).include('.bpi-community').withTags(['wcag2a','wcag2aa','wcag21aa']).analyze();
  expect(results.violations).toEqual([]);
});
test('question, answer, acceptance and moderator reversal work through visible controls', async ({ page }) => {
  test.setTimeout(120000);
  await login(page);
  await page.getByRole('link', { name: 'Questions', exact: true }).click();
  const title = `Browser question ${Date.now()}`;
  await page.getByLabel('Question title').fill(title);
  await page.getByLabel('Question details').fill('A real browser workflow');
  await page.getByRole('button', { name: 'Ask a question', exact: true }).click();
  await expect(page.getByRole('heading', { name: title, exact: true })).toBeVisible();
  const questionUrl = page.url();
  await login(page, 'bpi_bob');
  await page.goto(questionUrl);
  await page.getByLabel('Your answer', { exact: true }).fill('Verified browser answer');
  await page.getByRole('button', { name: 'Post answer', exact: true }).click();
  await expect(page.getByText('Verified browser answer', { exact: true })).toBeVisible();
  await login(page);
  await page.goto(questionUrl);
  await page.getByRole('button', { name: 'Accept this answer', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Accepted answer', exact: true })).toBeVisible();
  await login(page, 'bpi_admin');
  await page.goto(questionUrl);
  const results = await new AxeBuilder({ page }).include('.bpi-community').withTags(['wcag2a','wcag2aa','wcag21aa']).analyze();
  expect(results.violations).toEqual([]);
  await page.screenshot({ path: '.runtime/community-question-desktop.png', fullPage: true });
  await page.getByRole('button', { name: 'Reverse acceptance and its reputation credit', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Answer', exact: true })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Accepted answer', exact: true })).toHaveCount(0);
});
test('administrator can edit archived topics and automation configuration', async ({ page }) => {
  test.setTimeout(120000);
  await login(page, 'bpi_admin');
  await page.goto('/wp-admin/admin.php?page=bpi-topics');
  const name = `Browser topic ${Date.now()}`;
  await page.getByLabel('Topic name', { exact: true }).fill(name);
  await saveAndReload(page, 'Create topic');
  await expect(page.getByRole('link', { name: `Edit topic: ${name}`, exact: true })).toBeVisible();
  await page.getByRole('link', { name: `Edit topic: ${name}`, exact: true }).click();
  await page.getByLabel('Topic status', { exact: true }).selectOption('archived');
  await saveAndReload(page, 'Save topic');
  await expect(page.getByLabel('Topic status', { exact: true })).toHaveValue('archived');
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.getByLabel('Topic status', { exact: true }).selectOption('active');
  await expect(page.getByLabel('Topic status', { exact: true })).toHaveValue('active');
  await saveAndReload(page, 'Save topic');
  await expect(page.getByLabel('Topic status', { exact: true })).toHaveValue('active');
  await page.goto('/wp-admin/admin.php?page=bpi-automations');
  const rule = `Browser rule ${Date.now()}`;
  await page.getByLabel('Rule name', { exact: true }).fill(rule);
  await page.getByLabel('When this happens', { exact: true }).selectOption('question_created');
  await page.getByLabel('Action', { exact: true }).selectOption('notify');
  await saveAndReload(page, 'Create automation');
  await expect(page.getByRole('link', { name: `Edit automation: ${rule}`, exact: true })).toBeVisible();
  await page.getByRole('link', { name: `Edit automation: ${rule}`, exact: true }).click();
  await expect(page.getByLabel('Test without performing actions', { exact: true })).toBeChecked();
  await page.getByLabel('Enabled', { exact: true }).check();
  await saveAndReload(page, 'Save automation');
  await expect(page.getByLabel('Enabled', { exact: true })).toBeChecked();
  const results = await new AxeBuilder({ page }).include('.bpi-community').withTags(['wcag2a','wcag2aa','wcag21aa']).analyze();
  expect(results.violations).toEqual([]);
});
test('reporter and moderator complete a protected case through keyboard controls', async ({ page }) => {
  test.setTimeout(120000);
  await login(page, 'bpi_bob');
  await page.getByRole('link', { name: 'Questions', exact: true }).click();
  const title = `Report browser question ${Date.now()}`;
  await page.getByLabel('Question title', { exact: true }).fill(title);
  await page.getByLabel('Question details', { exact: true }).fill('A contribution for case verification');
  await page.getByRole('button', { name: 'Ask a question', exact: true }).click();
  await expect(page.getByRole('heading', { name: title, exact: true })).toBeVisible();
  await login(page);
  await page.getByRole('link', { name: 'Questions', exact: true }).click();
  const card = page.locator('.bpi-card').filter({ has: page.getByRole('link', { name: title, exact: true }) });
  await card.locator('summary').click();
  await card.getByLabel('Report category', { exact: true }).selectOption('spam');
  await card.getByLabel('What happened?', { exact: true }).fill('Please review this item');
  let receipt;
  await page.route('**/buddypress-intelligence/v1/reports', async route => {
    const response = await route.fetch();
    receipt = await response.json();
    await route.fulfill({ response });
  });
  await card.getByRole('button', { name: 'Submit report', exact: true }).click();
  await expect.poll(() => receipt?.case_id).toBeGreaterThan(0);
  expect(receipt.case_id).toBeGreaterThan(0);
  await login(page, 'bpi_admin');
  await page.goto(`/wp-admin/admin.php?page=bpi-moderation&bpi_case=${receipt.case_id}`);
  const note = `Protected moderator note ${Date.now()}`;
  await page.getByLabel('Internal note (visible to moderators only)', { exact: true }).fill(note);
  await page.getByRole('button', { name: 'Apply action', exact: true }).focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('.bpi-records dd').filter({ hasText: note }).first()).toBeVisible();
  const results = await new AxeBuilder({ page }).include('.bpi-community').withTags(['wcag2a','wcag2aa','wcag21aa']).analyze();
  expect(results.violations).toEqual([]);
  await page.screenshot({ path: '.runtime/community-moderation-desktop.png', fullPage: true });
  await login(page);
  await page.getByRole('link', { name: 'Reports and appeals', exact: true }).click();
  await expect(page.getByText(note, { exact: true })).toHaveCount(0);
});
