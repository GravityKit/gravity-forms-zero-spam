const path = require('path');
const { createPlaywrightConfig } = require('@gravitykit/e2e-bootstrap');

module.exports = createPlaywrightConfig({
    setupDir: __dirname,
    testDir: path.resolve(__dirname, '..', 'tests'),
    snapshotPathTemplate:
        '{testDir}/snapshots/{testFileDir}/{testName}-snapshots/{arg}{ext}',
    // Many of our tests mutate global Zero Spam plugin settings
    // (gf_zero_spam_blocking, email rejection rules, message). Two parallel
    // workers doing read-modify-write on the same WordPress option clobber
    // each other. Serial execution avoids the race entirely; the suite still
    // finishes in ~30s which is acceptable for the trade-off.
    fullyParallel: false,
    workers: 1,
    use: {
        trace: 'on-first-retry',
    },
});
