# Tests

End-to-end QA suite for the release ZIP: see `../TESTING.md`.

- `qa/run-all.sh`: deploys a ZIP into disposable Apache sites and databases, runs everything
- `qa/run.php`: installer + workflows + roles + security, per layout
- `qa/reinstall.php`, `qa/upgrade.php`, `qa/failure-modes.php`: special scenarios
- `qa/browser.mjs`: headless Chromium checks (needs `playwright-core`)
- `qa/lib.php`: HTTP client and result helpers

This folder is not included in the release ZIP.
