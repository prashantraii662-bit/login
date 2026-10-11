const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './tests',
  testMatch: '**/*.spec.js',
  fullyParallel: false,
  workers: 1,
  timeout: 60000,
  expect: {
    timeout: 10000
  },
  use: {
    baseURL: process.env.BASE_URL || 'http://localhost/Voting/',
    browserName: 'chromium',
    headless: true
  }
});
