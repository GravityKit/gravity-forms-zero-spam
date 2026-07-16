/**
 * Zero Spam — Shield silentCAPTCHA with Shield MOCKED (HP-64 through HP-68).
 *
 * Uses tests/E2E/mu-plugins/zs-e2e-shield.php, which defines the integration's
 * documented global-function fallbacks (shield_test_ip_is_bot,
 * shield_get_silentcaptcha_bot_threshold) so the integration sees Shield as
 * installed, with a REST-controlled verdict per test.
 *
 * Contracts under test:
 *   - Global toggle: check → '1', uncheck → '0' (an unchecked enabled toggle
 *     posts nothing and must resolve to OFF, not resurrect the old value).
 *   - Per-form: an untouched save must keep the form meta key ABSENT so the
 *     form keeps inheriting the global default; an explicit uncheck stores '0'.
 *   - Runtime: only a strict boolean true verdict flags spam (with Shield
 *     attribution on the entry); false, garbage, and thrown errors fail open.
 */

const { test, expect } = require('@wordpress/e2e-test-utils-playwright');
const helpers = require('../helpers');

// Mutates the global Shield plugin setting and the shared mock option.
test.describe.configure({ mode: 'serial' });

const SHIELD_TOGGLE = '#_gform_setting_shield_silent_captcha';
const SHIELD_TOGGLE_LABEL = `label.gform-field__toggle-container[for="_gform_setting_shield_silent_captcha"]`;
const SAVE_BUTTON = '#gform-settings-save';
const SUCCESS_NOTICE = '.alert.gforms_note_success';

const PLUGIN_SETTINGS_URL = '/wp-admin/admin.php?page=gf_settings&subview=gf-zero-spam';

function formSettingsUrl(formId) {
    return `/wp-admin/admin.php?page=gf_edit_forms&view=settings&id=${formId}`;
}

async function saveAndWait(page) {
    await page.locator(SAVE_BUTTON).click();
    await expect(page.locator(SUCCESS_NOTICE)).toContainText('Settings updated.');
}

test.describe('Shield silentCAPTCHA — settings with Shield available', () => {
    test.beforeEach(async ({ request }) => {
        await helpers.setShieldMock(request, { available: true });
        await helpers.setShieldPluginSetting(request, { remove: true });
    });

    test.afterEach(async ({ request }) => {
        await helpers.setShieldPluginSetting(request, { remove: true });
        await helpers.resetShieldMock(request);
    });

    test('HP-64: global toggle saves ON as 1 and a later uncheck saves 0', async ({
        page,
        request,
    }) => {
        await page.goto(PLUGIN_SETTINGS_URL);

        const toggle = page.locator(SHIELD_TOGGLE);
        await expect(toggle, 'toggle must be interactive when Shield is available').toBeEnabled();
        await expect(toggle).not.toBeChecked();

        await page.locator(SHIELD_TOGGLE_LABEL).click();
        await expect(toggle).toBeChecked();
        await saveAndWait(page);

        let stored = await helpers.getShieldPluginSetting(request);
        expect(stored.present).toBe(true);
        expect(stored.value, 'checked toggle must persist as 1').toBe('1');
        await expect(page.locator(SHIELD_TOGGLE)).toBeChecked();

        await page.locator(SHIELD_TOGGLE_LABEL).click();
        await expect(page.locator(SHIELD_TOGGLE)).not.toBeChecked();
        await saveAndWait(page);

        stored = await helpers.getShieldPluginSetting(request);
        expect(stored.present).toBe(true);
        expect(stored.value, 'unchecked toggle must persist as 0, not resurrect 1').toBe('0');
        await expect(page.locator(SHIELD_TOGGLE)).not.toBeChecked();
    });

    test('HP-65: an untouched form settings save keeps the form inheriting the global default', async ({
        page,
        request,
    }) => {
        const data = await helpers.createFromTemplate({
            template: 'simple',
            skipView: true,
        });

        try {
            // Global ON: the inherited toggle renders checked, and GF materializes
            // that value into the posted settings — the save must still not write
            // a per-form override.
            await helpers.setShieldPluginSetting(request, { value: '1' });

            await page.goto(formSettingsUrl(data.form_id));
            await expect(page.locator(SHIELD_TOGGLE)).toBeEnabled();
            await expect(
                page.locator(SHIELD_TOGGLE),
                'inheriting form must render the global default'
            ).toBeChecked();

            await saveAndWait(page);

            let meta = await helpers.getFormShieldMeta(request, data.form_id);
            expect(
                meta.present,
                'untouched save under global ON must keep the meta key absent'
            ).toBe(false);

            // Global OFF: the unchecked toggle posts nothing, which must not be
            // mistaken for a deliberate uncheck on an inheriting form.
            await helpers.setShieldPluginSetting(request, { value: '0' });

            await page.goto(formSettingsUrl(data.form_id));
            await expect(page.locator(SHIELD_TOGGLE)).not.toBeChecked();

            await saveAndWait(page);

            meta = await helpers.getFormShieldMeta(request, data.form_id);
            expect(
                meta.present,
                'untouched save under global OFF must keep the meta key absent'
            ).toBe(false);
        } finally {
            await helpers.cleanup(data.test_id);
        }
    });

    test('HP-66: a per-form override saves 1, and unchecking it saves 0', async ({
        page,
        request,
    }) => {
        await helpers.setShieldPluginSetting(request, { value: '0' });

        const data = await helpers.createFromTemplate({
            template: 'simple',
            skipView: true,
        });

        try {
            await page.goto(formSettingsUrl(data.form_id));
            await expect(page.locator(SHIELD_TOGGLE)).not.toBeChecked();

            await page.locator(SHIELD_TOGGLE_LABEL).click();
            await expect(page.locator(SHIELD_TOGGLE)).toBeChecked();
            await saveAndWait(page);

            let meta = await helpers.getFormShieldMeta(request, data.form_id);
            expect(meta.present, 'checking the toggle must write an override').toBe(true);
            expect(meta.value).toBe('1');

            await page.goto(formSettingsUrl(data.form_id));
            await expect(page.locator(SHIELD_TOGGLE)).toBeChecked();

            await page.locator(SHIELD_TOGGLE_LABEL).click();
            await expect(page.locator(SHIELD_TOGGLE)).not.toBeChecked();
            await saveAndWait(page);

            meta = await helpers.getFormShieldMeta(request, data.form_id);
            expect(meta.present, 'the override must remain explicit after unchecking').toBe(true);
            expect(meta.value, 'unchecking an override must store 0, not resurrect 1').toBe('0');
        } finally {
            await helpers.cleanup(data.test_id);
        }
    });
});

test.describe('Shield silentCAPTCHA — submissions with Shield available', () => {
    let formId;
    let pageId;
    let pageUrl;
    let testId;

    test.beforeEach(async ({ request }) => {
        await helpers.setShieldPluginSetting(request, { remove: true });

        const data = await helpers.createFromTemplate({
            template: 'simple',
            skipView: true,
        });
        formId = data.form_id;
        testId = data.test_id;

        // Zero Spam explicitly ON: it is now the master gate for the whole
        // pipeline, Shield included, so it can no longer be disabled to isolate
        // Shield. The browser flow submits a valid token, so the token check
        // passes and Shield still decides alone.
        await helpers.setFormZeroSpam(request, formId, true);

        const created = await helpers.createPage(request, {
            title: `ZS Shield Mock ${testId}`,
            content: `[gravityform id="${formId}" title="false" description="false" ajax="false"]`,
            slug: `zs-shield-mock-${testId}`,
        });
        pageId = created.page_id;
        pageUrl = created.permalink;
    });

    test.afterEach(async ({ request }) => {
        await helpers.resetShieldMock(request);
        await helpers.setShieldPluginSetting(request, { remove: true });
        await helpers.cleanup(testId);
        await helpers.cleanupPages(request, [pageId]);
    });

    test('HP-67: a bot verdict sends the entry to spam with Shield attribution', async ({
        request,
        browser,
    }) => {
        // The form has no override, so the flag must arrive via inheritance
        // from the global default.
        await helpers.setShieldPluginSetting(request, { value: '1' });
        await helpers.setShieldMock(request, { available: true, verdict: 'bot' });

        const anonContext = await browser.newContext({
            storageState: { cookies: [], origins: [] },
        });
        const anonPage = await anonContext.newPage();
        const email = `shield-bot+${testId}@gravitykit.test`;

        try {
            await anonPage.goto(pageUrl);
            await anonPage.locator(`#gform_${formId} input[name="input_1"]`).fill('Botty');
            await anonPage.locator(`#gform_${formId} input[name="input_2"]`).fill(email);
            await anonPage.locator(`#gform_submit_button_${formId}`).click();

            // Spam entries still show the confirmation: the submitter must not
            // learn they were flagged.
            await expect(
                anonPage.locator(`#gform_confirmation_wrapper_${formId}`)
            ).toContainText('Thanks for contacting us!');
        } finally {
            await anonContext.close();
        }

        const spam = await helpers.getEntries(request, formId, 'spam');
        const trapped = spam.filter((entry) => entry['2'] === email);
        expect(trapped, 'bot verdict must produce a spam entry').toHaveLength(1);

        const active = await helpers.getEntries(request, formId, 'active');
        const leaked = active.filter((entry) => entry['2'] === email);
        expect(leaked, 'bot verdict must not produce an active entry').toHaveLength(0);

        const notes = await helpers.getEntryNotes(request, Number(trapped[0].id));
        expect(
            notes.some((note) => note.user_name === 'Shield silentCAPTCHA'),
            'spam note must be attributed to Shield silentCAPTCHA'
        ).toBe(true);
        expect(
            notes.some((note) => note.value.includes('Shield silentCAPTCHA')),
            'spam note must name the Shield filter in its reason'
        ).toBe(true);

        const mock = await helpers.getShieldMock(request);
        expect(mock.calls, 'the Shield callable must have been consulted').toBeGreaterThan(0);
    });

    test('HP-68: non-true verdicts (human, garbage, thrown error) all fail open', async ({
        request,
        browser,
    }) => {
        await helpers.setFormShieldMeta(request, formId, { value: '1' });

        const anonContext = await browser.newContext({
            storageState: { cookies: [], origins: [] },
        });
        const anonPage = await anonContext.newPage();

        try {
            await anonPage.goto(pageUrl);

            for (const verdict of ['human', 'garbage', 'throw']) {
                await helpers.setShieldMock(request, { available: true, verdict });

                const email = `shield-${verdict}+${testId}@gravitykit.test`;
                // Zero Spam is ON (master gate), so this programmatic POST needs
                // a valid token; the async JS injection is not awaited here.
                const token = await helpers.mintToken(request, formId);
                const result = await anonPage.evaluate(
                    async ({ id, submittedEmail, token }) => {
                        const form = document.getElementById('gform_' + id);
                        const fd = new FormData(form);
                        fd.set('input_1', 'Visitor');
                        fd.set('input_2', submittedEmail);
                        fd.set('gf_zero_spam_token', token);

                        const res = await fetch(form.action || location.href, {
                            method: 'POST',
                            body: fd,
                            redirect: 'follow',
                        });

                        return { status: res.status };
                    },
                    { id: formId, submittedEmail: email, token }
                );
                expect(result.status, `${verdict}: POST must succeed`).toBe(200);

                const active = await helpers.getEntries(request, formId, 'active');
                const submitted = active.filter((entry) => entry['2'] === email);
                expect(
                    submitted,
                    `${verdict}: submission must land as an active entry`
                ).toHaveLength(1);

                const spam = await helpers.getEntries(request, formId, 'spam');
                const leaked = spam.filter((entry) => entry['2'] === email);
                expect(leaked, `${verdict}: must never be flagged as spam`).toHaveLength(0);
            }
        } finally {
            await anonContext.close();
        }

        // 'human' and 'garbage' reach the callable; 'throw' increments before
        // throwing — all three must have consulted the mock.
        const mock = await helpers.getShieldMock(request);
        expect(mock.calls, 'the thrown-error run must still have called the mock').toBeGreaterThan(0);
    });
});
