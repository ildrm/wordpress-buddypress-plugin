export default [{
  files: ['assets/*.js', 'tests/E2E/*.cjs', 'playwright.config.cjs'],
  languageOptions: { ecmaVersion: 2022, globals: { wp: 'readonly', bpiConfig: 'readonly', document: 'readonly', window: 'readonly', FormData: 'readonly', URL: 'readonly', AbortController: 'readonly', setTimeout: 'readonly', clearTimeout: 'readonly', fetch: 'readonly', module: 'readonly', require: 'readonly', process: 'readonly' } },
  rules: { 'no-unused-vars': 'error', 'no-undef': 'error', 'eqeqeq': 'error', 'no-eval': 'error', 'no-implied-eval': 'error', 'no-new-func': 'error' }
}];
