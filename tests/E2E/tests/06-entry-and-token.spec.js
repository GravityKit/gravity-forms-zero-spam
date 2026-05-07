/**
 * Zero Spam — entry detail, capability bypass, and token lifetime
 * (HP-17, HP-18, HP-19).
 *
 * Behavior verified live with Playwright MCP / wp-cli:
 *   - When the spam check flags an entry, the addon calls
 *     GFAPI::add_note(entry_id, 0, 'Zero Spam', 'This entry has been marked
 *     as spam.', 'gf-zero-spam', 'success'). HP-17 reads the note via
 *     /zs-e2e/v1/entry-notes/{id}.
 *   - GF_Zero_Spam::check_key_field() short-circuits to "not spam" when the
 *     submitting user has gravityforms_edit_entries (HP-18). The default
 *     admin storage state already grants this cap.
 *   - The Anti-Spam Expiration setting is split into value + unit selects
 *     (#_gaddon_setting_gf_zero_spam_token_lifetime_value /
 *     #_gaddon_setting_gf_zero_spam_token_lifetime_unit). The persisted
 *     integer (in seconds) is exposed at the rendered form inside
 *     window.gfZeroSpamConfig.forms[i].fallbackToken; the token's payload
 *     is base64url("{form_id}|{iat}|{exp}|{nonce}|{salt_version}"), so
 *     exp - iat must equal the configured TTL.
 */

const { test, expect } = require('@wordpress/e2e-test-utils-playwright');
const helpers = require('../helpers');

const SETTINGS_URL = '/wp-admin/admin.php?page=gf_settings&subview=gf-zero-spam';
const TOKEN_VALUE_INPUT = '#_gaddon_setting_gf_zero_spam_token_lifetime_value';
const TOKEN_UNIT_SELECT = '#_gaddon_setting_gf_zero_spam_token_lifetime_unit';
const SAVE_BUTTON = '#gform-settings-save';
const SUCCESS_NOTICE = '.alert.gforms_note_success';

function decodeBase64Url(s) {
    const padded = s.replace(/-/g, '+').replace(/_/g, '/').padEnd(s.length + ((4 - (s.length % 4)) % 4), '=');
    return Buffer.from(padded, 'base64').toString('utf8');
}

async function createSimpleForm() {
    const testId = helpers.generateTestId();
    const data = await helpers.api.setup({
        test_id: testId,
        form: {
            title: 'Entry-and-Token Form',
            fields: [
                { id: 1, type: 'text', label: 'Name', isRequired: true },
                { id: 2, type: 'email', label: 'Email', isRequired: true },
            ],
        },
        entries: [],
        skip_view: true,
    });
    if (data.error) throw new Error(`Form creation failed: ${data.error}`);
    return { testId, formId: data.form_id };
}

test.describe('Zero Spam — entry detail and capability bypass', () => {
    let testId;
    let formId;
    let pageId;
    let pageUrl;

    test.beforeEach(async ({ request }) => {
        ({ testId, formId } = await createSimpleForm());
        await helpers.setFormZeroSpam(request, formId, true);

        const created = await helpers.createPage(request, {
            title: `ZS Entry ${testId}`,
            content: `[gravityform id="${formId}" title="false" description="false" ajax="false"]`,
            slug: `zs-entry-${testId}`,
        });
        pageId = created.page_id;
        pageUrl = created.permalink;
    });

    test.afterEach(async ({ request }) => {
        await helpers.cleanup(testId);
        await helpers.cleanupPages(request, [pageId]);
    });

    test('HP-17: spam entry has a Zero Spam note explaining why it was flagged', async ({
        browser,
        request,
    }) => {
        // Anonymous, tokenless submission → spam.
        const anon = await browser.newContext({
            storageState: { cookies: [], origins: [] },
        });
        const anonPage = await anon.newPage();

        try {
            await anonPage.goto(pageUrl);
            await anonPage.evaluate(
                async (id) => {
                    const form = document.getElementById('gform_' + id);
                    const fd = new FormData(form);
                    fd.set('input_1', 'NoteCheck');
                    fd.set('input_2', `notecheck@gravitykit.test`);
                    fd.delete('gf_zero_spam_token');
                    fd.delete('gf_zero_spam_key');
                    await fetch(form.action || location.href, {
                        method: 'POST',
                        body: fd,
                        redirect: 'follow',
                    });
                },
                formId
            );
        } finally {
            await anon.close();
        }

        const spam = await helpers.getEntries(request, formId, 'spam');
        const ours = spam.filter((entry) => entry['2'] === 'notecheck@gravitykit.test');
        expect(ours).toHaveLength(1);

        const notes = await helpers.getEntryNotes(request, ours[0].id);
        // GF (form_display.php :create_spam_entry_note) writes a note keyed by
        // the registered spam filter name. Zero Spam calls
        // GFCommon::set_spam_filter(form_id, 'Zero Spam', $reason); so the
        // note's user_name is "Zero Spam" and the body includes the reason.
        const zsNotes = notes.filter((note) => note.user_name === 'Zero Spam');
        expect(zsNotes.length, 'spam entry must have a Zero Spam note').toBeGreaterThan(0);
        expect(zsNotes[0].value).toMatch(/spam/i);
        expect(zsNotes[0].value).toMatch(/Reason:/i);
    });

    test('HP-18: a user with gravityforms_edit_entries cap bypasses the spam check', async ({
        page,
        request,
    }) => {
        // We arrive logged in as admin (default storageState). Admin has
        // the gravityforms_edit_entries capability, so check_key_field()
        // short-circuits to "not spam" even when no token is on the form.
        await page.goto(pageUrl);

        await page.evaluate(
            async (id) => {
                const form = document.getElementById('gform_' + id);
                const fd = new FormData(form);
                fd.set('input_1', 'AdminUser');
                fd.set('input_2', `admin-bypass@gravitykit.test`);
                fd.delete('gf_zero_spam_token');
                fd.delete('gf_zero_spam_key');
                await fetch(form.action || location.href, {
                    method: 'POST',
                    body: fd,
                    redirect: 'follow',
                });
            },
            formId
        );

        const active = await helpers.getEntries(request, formId, 'active');
        const allowed = active.filter(
            (entry) => entry['2'] === 'admin-bypass@gravitykit.test'
        );
        expect(allowed, 'admin tokenless POST must NOT be flagged as spam').toHaveLength(1);

        const spam = await helpers.getEntries(request, formId, 'spam');
        const flagged = spam.filter(
            (entry) => entry['2'] === 'admin-bypass@gravitykit.test'
        );
        expect(flagged, 'admin submission must not appear in spam').toHaveLength(0);
    });
});

test.describe('Zero Spam — token lifetime', () => {
    test.afterEach(async ({ request }) => {
        await helpers.resetZeroSpam(request);
    });

    test('HP-19: Anti-Spam Expiration setting persists and the fallback token honors it', async ({
        page,
        request,
    }) => {
        await page.goto(SETTINGS_URL);

        // Choose a non-default value/unit so we know the assertion isn't
        // matching the static fallback. 1 day = 86400 seconds.
        await page.locator(TOKEN_VALUE_INPUT).fill('1');
        await page.locator(TOKEN_UNIT_SELECT).selectOption('days');

        await page.locator(SAVE_BUTTON).click();
        await expect(page.locator(SUCCESS_NOTICE)).toContainText('Settings updated.');

        // Persistence on reload.
        await page.reload();
        await expect(page.locator(TOKEN_VALUE_INPUT)).toHaveValue('1');
        await expect(page.locator(TOKEN_UNIT_SELECT)).toHaveValue('days');

        // Render a form and assert the embedded fallback token's TTL.
        const { testId, formId } = await createSimpleForm();
        await helpers.setFormZeroSpam(request, formId, true);

        const created = await helpers.createPage(request, {
            title: `ZS Token TTL ${testId}`,
            content: `[gravityform id="${formId}" title="false" description="false" ajax="false"]`,
            slug: `zs-ttl-${testId}`,
        });

        try {
            // Fresh context — storageState doesn't matter for the rendered
            // config, but isolating avoids surprises from logged-in-only
            // optimizations elsewhere.
            await page.goto(created.permalink);

            const fallbackToken = await page.evaluate(() => {
                const cfg = window.gfZeroSpamConfig;
                return cfg && cfg.forms && cfg.forms[0]
                    ? cfg.forms[0].fallbackToken
                    : null;
            });
            expect(fallbackToken, 'fallback token must be embedded').toBeTruthy();

            const payloadB64 = fallbackToken.split('.')[0];
            const payload = decodeBase64Url(payloadB64);
            const [embeddedFormId, iat, exp] = payload.split('|').map(Number);

            expect(embeddedFormId).toBe(formId);
            expect(exp - iat, 'TTL must equal the configured 86400 seconds').toBe(86400);
        } finally {
            await helpers.cleanup(testId);
            await helpers.cleanupPages(request, [created.page_id]);
        }
    });
});
