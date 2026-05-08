/**
 * Re-exports per-test fixture orchestration from the unified helpers entry point.
 *
 * Kept as a thin shim so test files can `require('../fixtures')` if they prefer
 * fixture-only naming, but everything ultimately resolves through the same
 * createHelpers() pipeline.
 */

module.exports = require('../helpers');
