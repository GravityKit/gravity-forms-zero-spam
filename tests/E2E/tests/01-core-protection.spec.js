/**
 * Zero Spam — core protection (HP-1, HP-2, HP-3).
 *
 * Verified live with Playwright MCP against wp-env (GF 2.9.25.1, ZS 1.8.0):
 *   - Form id: form#gform_<formId>; submit btn: input#gform_submit_button_<formId>.
 *   - Confirmation block: #gform_confirmation_wrapper_<formId>.
 *   - Token endpoint: POST /wp-admin/admin-ajax.php  action=gf_zero_spam_token,
 *     responds with { token, expires } and content-type application/json.
 *   - Server-side spam check fires for direct POSTs without gf_zero_spam_token,
 *     even when the JS pre-submission filter never runs.
 */

const { test, expect } = require('@wordpress/e2e-test-utils-playwright');
const helpers = require('../helpers');

// All assertions in this file are about anonymous-visitor behavior.
// Skipping admin storageState avoids the gravityforms_edit_entries cap bypass
// path in GF_Zero_Spam::check_key_field().
test.use({ storageState: { cookies: [], origins: [] } });

test.describe('Zero Spam — core protection', () => {
    let formId;
    let pageUrl;
    let pageId;
    let testId;

    test.beforeEach(async ({ request }) => {
        const data = await helpers.createFromTemplate({
            template: 'simple',
            skipView: true,
        });
        formId = data.form_id;
        testId = data.test_id;

        // Pin Zero Spam ON for THIS form regardless of the global default. Without
        // this, HP-6 in another worker can flip the global setting between when
        // we create the form and when we read it, leaving the form effectively
        // unprotected and HP-1 / HP-2 expectations broken.
        await helpers.setFormZeroSpam(request, formId, true);

        const created = await helpers.createPage(request, {
            title: `ZS Core Protection ${testId}`,
            content: `[gravityform id="${formId}" title="false" description="false" ajax="false"]`,
            slug: `zs-core-${testId}`,
        });
        pageUrl = created.permalink;
        pageId = created.page_id;
    });

    test.afterEach(async ({ request }) => {
        await helpers.cleanup(testId);
        // Parallel-safe: only delete this test's page, not every E2E page on the site.
        await helpers.cleanupPages(request, [pageId]);
    });

    test('HP-1: legitimate visitor submission creates an active (non-spam) entry', async ({
        page,
        request,
    }) => {
        await page.goto(pageUrl);

        await page
            .locator(`#gform_${formId} input[name="input_1"]`)
            .fill('Alice');
        await page
            .locator(`#gform_${formId} input[name="input_2"]`)
            .fill(`alice+${testId}@gravitykit.test`);

        await page.locator(`#gform_submit_button_${formId}`).click();

        await expect(
            page.locator(`#gform_confirmation_wrapper_${formId}`)
        ).toContainText('Thanks for contacting us!');

        const active = await helpers.getEntries(request, formId, 'active');
        const submitted = active.filter(
            (entry) => entry['2'] === `alice+${testId}@gravitykit.test`
        );

        expect(submitted, 'submission must produce exactly one active entry').toHaveLength(1);
        expect(submitted[0].status).toBe('active');

        const spam = await helpers.getEntries(request, formId, 'spam');
        const leaked = spam.filter(
            (entry) => entry['2'] === `alice+${testId}@gravitykit.test`
        );
        expect(leaked, 'legit submission must not appear in the spam list').toHaveLength(0);
    });

    test('HP-2: submission missing the zero-spam token is flagged as spam', async ({
        page,
        request,
    }) => {
        await page.goto(pageUrl);

        // Bypass the GF/ZS JS pre-submission filter by POSTing the form
        // directly with no token. This is the exact attack vector the
        // server-side check (gform_entry_is_spam) is designed to catch.
        const result = await page.evaluate(async (id) => {
            const form = document.getElementById('gform_' + id);
            const fd = new FormData(form);
            fd.set('input_1', 'Botty');
            fd.set('input_2', 'bot@gravitykit.test');
            fd.delete('gf_zero_spam_token');
            fd.delete('gf_zero_spam_key');

            const res = await fetch(form.action || location.href, {
                method: 'POST',
                body: fd,
                redirect: 'follow',
            });

            return { status: res.status };
        }, formId);

        expect(result.status).toBe(200);

        const spam = await helpers.getEntries(request, formId, 'spam');
        const trapped = spam.filter((entry) => entry['2'] === 'bot@gravitykit.test');

        expect(trapped, 'tokenless POST must produce a spam entry').toHaveLength(1);
        expect(trapped[0].status).toBe('spam');

        const active = await helpers.getEntries(request, formId, 'active');
        const leaked = active.filter((entry) => entry['2'] === 'bot@gravitykit.test');
        expect(leaked, 'tokenless POST must not slip into active entries').toHaveLength(0);
    });

    test('HP-3: AJAX token endpoint issues a usable token for the form', async ({ page }) => {
        await page.goto(pageUrl);

        const result = await page.evaluate(async (id) => {
            const fd = new FormData();
            fd.append('action', 'gf_zero_spam_token');
            fd.append('form_id', String(id));

            const res = await fetch('/wp-admin/admin-ajax.php', {
                method: 'POST',
                body: fd,
            });

            return {
                status: res.status,
                contentType: res.headers.get('content-type'),
                body: await res.json().catch(() => null),
            };
        }, formId);

        expect(result.status).toBe(200);
        expect(result.contentType || '').toMatch(/application\/json/);
        expect(typeof result.body?.token).toBe('string');
        // Stateless HMAC tokens are <header>.<sig> base64url; observed length ~165.
        expect(result.body.token.length).toBeGreaterThan(40);
        expect(result.body.token).toMatch(/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/);
    });
});
