const { defineConfig } = require('@playwright/test');
module.exports = defineConfig({
  testDir: './tests/E2E',
  testMatch: '**/*.cjs',
  fullyParallel: false,
  timeout: 60000,
  workers: 1,
  reporter: [['list'], ['json', { outputFile: '.runtime/e2e-results.json' }]],
  use: {
    baseURL: 'http://127.0.0.1:8917',
    headless: true,
    launchOptions: process.env.BPI_BROWSER ? { executablePath: process.env.BPI_BROWSER } : (process.platform === 'win32' ? { executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe' } : {}),
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure'
  },
  outputDir: '.runtime/playwright-results'
});
