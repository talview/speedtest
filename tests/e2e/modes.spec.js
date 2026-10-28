const { test, expect } = require('@playwright/test');
const { baseUrls } = require('./helpers/env');
const { classicStartButton } = require('./helpers/ui');

const frontendRemoteServerListUrl = 'http://127.0.0.1:18184/tests/e2e/fixtures/servers-frontend-remote.json';

test.describe('Runtime mode smoke coverage', () => {
  test('standalone exposes UI and local backend endpoints', async ({ page, request }) => {
    const root = await request.get(`${baseUrls.standalone}/`);
    expect(root.ok()).toBeTruthy();

    const index = await request.get(`${baseUrls.standalone}/index.html`);
    expect(index.ok()).toBeTruthy();
    await expect(await index.text()).toContain('Talview Speedtest');

    for (const endpoint of ['/backend/empty.php', '/backend/garbage.php', '/backend/getIP.php']) {
      const response = await request.get(`${baseUrls.standalone}${endpoint}`);
      expect(response.ok()).toBeTruthy();
    }

    await page.goto(`${baseUrls.standalone}/index.html`);
    await expect(classicStartButton(page)).toBeVisible();
  });

  test('Alpine standalone serves frontend settings', async ({ page, request }) => {
    const settings = await request.get(`${baseUrls.standaloneAlpine}/settings.json`);
    expect(settings.ok()).toBeTruthy();
    await expect(settings.json()).resolves.toMatchObject({ telemetry_level: 'off', time_dl_max: 12 });

    await page.goto(`${baseUrls.standaloneAlpine}/index.html`);
    await expect(classicStartButton(page)).toBeVisible();
  });

  test('backend exposes only local backend contract endpoints', async ({ request }) => {
    for (const endpoint of ['/empty.php', '/garbage.php', '/getIP.php']) {
      const response = await request.get(`${baseUrls.backend}${endpoint}`);
      expect(response.ok()).toBeTruthy();
    }
  });

  test('frontend serves UI and server list without local backend contract', async ({ page, request }) => {
    const serverList = await request.get(`${baseUrls.frontend}/server-list.json`);
    expect(serverList.ok()).toBeTruthy();
    await expect(await serverList.text()).toContain('Backend testpoint');

    const localBackendEndpoint = await request.get(`${baseUrls.frontend}/backend/empty.php`);
    expect(localBackendEndpoint.status()).toBe(404);

    await page.goto(`${baseUrls.frontend}/index.html`);
    await expect(classicStartButton(page)).toBeVisible();
  });

  test('frontend starts with SERVER_LIST_URL without requiring /servers.json', async ({ page, request }) => {
    const index = await request.get(`${baseUrls.frontendRemote}/index.html`);
    expect(index.ok()).toBeTruthy();
    await expect(await index.text()).toContain(frontendRemoteServerListUrl);

    const stability = await request.get(`${baseUrls.frontendRemote}/stability.html`);
    expect(stability.ok()).toBeTruthy();
    await expect(await stability.text()).toContain(frontendRemoteServerListUrl);

    const localBackendEndpoint = await request.get(`${baseUrls.frontendRemote}/backend/empty.php`);
    expect(localBackendEndpoint.status()).toBe(404);

    await page.goto(`${baseUrls.frontendRemote}/index.html`);
    await expect(classicStartButton(page)).toBeVisible();
    await expect(page.locator('#server')).toContainText('Remote frontend backend', { timeout: 10_000 });
  });

  test('default entrypoint loads the Talview frontend with SERVER_LIST_URL', async ({ page }) => {
    const pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));

    await page.goto(`${baseUrls.frontendRemote}/index.html`);
    await expect(classicStartButton(page)).toBeVisible();
    await expect(page.locator('#server')).toContainText('Remote frontend backend', { timeout: 10_000 });
    expect(pageErrors).toEqual([]);
  });

  test('dual combines frontend and local backend availability', async ({ page, request }) => {
    const serverList = await request.get(`${baseUrls.dual}/server-list.json`);
    expect(serverList.ok()).toBeTruthy();
    await expect(await serverList.text()).toContain('Local dual backend');

    for (const endpoint of ['/backend/empty.php', '/backend/garbage.php', '/backend/getIP.php']) {
      const response = await request.get(`${baseUrls.dual}${endpoint}`);
      expect(response.ok()).toBeTruthy();
    }

    await page.goto(`${baseUrls.dual}/index.html`);
    await expect(classicStartButton(page)).toBeVisible();
  });
});
