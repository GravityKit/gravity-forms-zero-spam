/**
 * Unified helpers entry point. Tests import everything from here.
 *
 * Combines the bootstrap helpers (cleanup, fixture orchestration, sleep,
 * BASE_URL) with Zero-Spam-specific helpers.
 */

const path = require('path');
const { createHelpers } = require('@gravitykit/e2e-bootstrap');
const zs = require('./zs-helpers');

const projectRoot = path.resolve(__dirname, '..', '..', '..');

module.exports = createHelpers({
    envPath: path.resolve(projectRoot, '.env'),
    setupDir: path.resolve(projectRoot, 'tests/E2E/setup'),
    pluginHelpers: {
        ...zs,
    },
});
