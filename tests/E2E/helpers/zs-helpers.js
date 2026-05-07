/**
 * Zero-Spam-specific test helpers.
 *
 * Wraps the REST endpoints exposed by `tests/E2E/mu-plugins/zs-e2e-helpers.php`
 * so tests don't pass auth tokens around or hand-build URLs.
 */

const TEST_TOKEN = 'gravitykit-e2e-test';
const NAMESPACE = '/wp-json/zs-e2e/v1';

function authHeaders() {
    return {
        'Content-Type': 'application/json',
        'X-E2E-TEST-TOKEN': TEST_TOKEN,
    };
}

async function callJson(request, method, path, body) {
    const response = await request[method.toLowerCase()](`${NAMESPACE}${path}`, {
        headers: authHeaders(),
        ...(body !== undefined ? { data: body } : {}),
    });

    if (!response.ok()) {
        throw new Error(
            `${method} ${path} failed: ${response.status()} ${await response.text()}`
        );
    }

    return response.json();
}

/**
 * Fully reset Zero Spam plugin state and clear captured mail. Call in beforeEach.
 *
 * @param {import('@playwright/test').APIRequestContext} request
 */
async function resetZeroSpam(request) {
    return callJson(request, 'POST', '/reset');
}

/**
 * Set the per-form "Prevent spam using Gravity Forms Zero Spam" toggle.
 *
 * @param {import('@playwright/test').APIRequestContext} request
 * @param {number} formId
 * @param {boolean} enabled
 */
async function setFormZeroSpam(request, formId, enabled) {
    return callJson(request, 'POST', `/form/${formId}/zero-spam`, { enabled });
}

/**
 * Set the global "Enable Zero Spam by Default" plugin setting.
 */
async function setGlobalDefault(request, enabled) {
    return callJson(request, 'POST', '/global-default', { enabled });
}

/**
 * Mint a Zero Spam token server-side. Useful for tests that bypass JS.
 */
async function mintToken(request, formId, ttl) {
    const body = ttl !== undefined ? { form_id: formId, ttl } : { form_id: formId };
    const json = await callJson(request, 'POST', '/token', body);

    return json.token;
}

/**
 * Read entries for a form, optionally filtered by status (active|spam|trash).
 */
async function getEntries(request, formId, status) {
    const qs = status ? `?status=${encodeURIComponent(status)}` : '';

    return callJson(request, 'GET', `/entries/${formId}${qs}`);
}

/**
 * Read notes for a Gravity Forms entry.
 */
async function getEntryNotes(request, entryId) {
    return callJson(request, 'GET', `/entry-notes/${entryId}`);
}

/**
 * Set spam-report email settings without going through the GF Settings UI.
 * Useful for HP-16 and similar tests that focus on the cron email content.
 *
 * @param {import('@playwright/test').APIRequestContext} request
 * @param {object} payload
 * @param {string} [payload.frequency] - '' | 'entry_limit' | 'twicedaily' | 'daily' | 'weekly' | 'monthly'.
 * @param {string} [payload.recipient]
 * @param {string} [payload.subject]
 * @param {string} [payload.body]
 */
async function setReportConfig(request, payload = {}) {
    return callJson(request, 'POST', '/report-config', payload);
}

/**
 * Trigger the Zero Spam cron handler synchronously, bypassing wp-cron.
 * Used to assert summary email content without waiting for a scheduled run.
 */
async function runReportCron(request) {
    return callJson(request, 'POST', '/cron-run-report');
}

/**
 * Inspect whether a wp-cron hook is currently scheduled.
 */
async function getScheduledCron(request, hook) {
    return callJson(request, 'GET', `/cron-scheduled/${hook}`);
}

/**
 * Configure email rejection — global enable toggle, rules list, and the
 * default rejection message used when a block rule fires.
 *
 * @param {import('@playwright/test').APIRequestContext} request
 * @param {object} payload
 * @param {boolean} [payload.enabled]
 * @param {Array<{type:'domain'|'email'|'wildcard'|'regex',value:string,action:'block'|'flag'|'log',enabled?:boolean}>} [payload.rules]
 * @param {string} [payload.message]
 */
async function setEmailRules(request, { enabled, rules, message } = {}) {
    const body = {};
    if (typeof enabled === 'boolean') body.enabled = enabled;
    if (Array.isArray(rules)) body.rules = rules;
    if (typeof message === 'string') body.message = message;

    return callJson(request, 'POST', '/email-rules', body);
}

/**
 * Create a published page with arbitrary content (typically a Gravity Forms shortcode).
 *
 * Returns { page_id, permalink }. Pages created this way are tagged with
 * meta `_zs_e2e_test=1` so cleanupPages() can remove them in one call.
 */
async function createPage(request, { title, content, slug }) {
    return callJson(request, 'POST', '/page', { title, content, slug });
}

/**
 * Delete pages created via createPage().
 *
 * Pass an explicit `ids` array to delete only those pages (recommended for
 * parallel-safe cleanup). Omitting `ids` deletes every E2E-tagged page in the
 * site — useful for one-off cleanup scripts but unsafe to call from a
 * per-test afterEach() because it would clobber pages owned by other workers.
 *
 * @param {import('@playwright/test').APIRequestContext} request
 * @param {number[]} [ids] - Specific page ids to delete.
 */
async function cleanupPages(request, ids) {
    return callJson(request, 'DELETE', '/pages', ids ? { ids } : undefined);
}

/**
 * Read all captured outgoing mail.
 */
async function getCapturedMail(request) {
    return callJson(request, 'GET', '/mail');
}

/**
 * Clear captured mail.
 */
async function clearCapturedMail(request) {
    return callJson(request, 'DELETE', '/mail');
}

/**
 * Locate the live Zero Spam input element on a rendered Gravity Form.
 *
 * The plugin injects a hidden `gf_zero_spam_token` (JS path) and may also leave
 * a fallback `gf_zero_spam_key` for legacy compatibility. This helper returns
 * whichever input is currently present so tests can read or remove it.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<import('@playwright/test').Locator>}
 */
async function getZeroSpamInput(page) {
    const tokenInput = page.locator('input[name="gf_zero_spam_token"]').first();
    if ((await tokenInput.count()) > 0) {
        return tokenInput;
    }

    return page.locator('input[name="gf_zero_spam_key"]').first();
}

/**
 * Strip both the token and legacy key inputs from every form on the page.
 * Used to simulate a bot submission without rewriting selectors per test.
 */
async function stripZeroSpamInputs(page) {
    await page.evaluate(() => {
        document
            .querySelectorAll(
                'input[name="gf_zero_spam_token"], input[name="gf_zero_spam_key"]'
            )
            .forEach((el) => el.parentNode && el.parentNode.removeChild(el));
    });
}

module.exports = {
    NAMESPACE,
    TEST_TOKEN,
    resetZeroSpam,
    setFormZeroSpam,
    setGlobalDefault,
    mintToken,
    getEntries,
    getCapturedMail,
    clearCapturedMail,
    getZeroSpamInput,
    stripZeroSpamInputs,
    createPage,
    cleanupPages,
    getEntryNotes,
    setEmailRules,
    runReportCron,
    getScheduledCron,
    setReportConfig,
};
