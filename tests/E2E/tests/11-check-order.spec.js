/**
 * Zero Spam — Spam Check Order & per-form master gate (HP-69 through HP-74).
 *
 * Uses the Shield mock (tests/E2E/mu-plugins/zs-e2e-shield.php) and the
 * check-order seam (tests/E2E/mu-plugins/zs-e2e-check-order.php).
 *
 * Contracts under test:
 *   - The configured order decides which check flags first and gets the spam
 *     attribution; with "Stop after first detection" ON, later checks never
 *     run (the Shield callable is not even consulted).
 *   - Stop OFF: later checks still evaluate and record verdict notes on the
 *     flagged entry, without ever clearing the spam status.
 *   - The per-form Zero Spam toggle master-gates the pipeline: runtime checks
 *     are skipped and the Shield/AI form fields are dependency-hidden, while
 *     stored Shield values survive saves made in that state.
 *   - Order settings UI: saves round-trip; a duplicated selection falls back
 *     to the default order; the order survives saves while Shield is absent.
 */

const { test, expect } = require('@wordpress/e2e-test-utils-playwright');
const helpers = require('../helpers');

// Mutates global plugin settings (order, stop, Shield default) and the mock.
test.describe.configure({ mode: 'serial' });

// GF Select fields use the bare field name as the id (no _gform_setting_ prefix, unlike toggles).
const ORDER_SELECT_1 = '#gf_zero_spam_check_order_1';
const ORDER_SELECT_2 = '#gf_zero_spam_check_order_2';
const ORDER_SELECT_3 = '#gf_zero_spam_check_order_3';
const STOP_TOGGLE = '#_gform_setting_gf_zero_spam_stop_after_first_detection';
const MASTER_TOGGLE = '#_gform_setting_enableGFZeroSpam';
const MASTER_TOGGLE_LABEL = `label[for="_gform_setting_enableGFZeroSpam"]`;
const SHIELD_TOGGLE = '#_gform_setting_shield_silent_captcha';
const AI_TOGGLE = '#_gform_setting_enableGFZeroSpamAI';
const SAVE_BUTTON = '#gform-settings-save';
const SUCCESS_NOTICE = '.alert.gforms_note_success';
const SHIELD_SKIPPED_TEXT = 'Shield Security is not active — this check is skipped.';

const PLUGIN_SETTINGS_URL = '/wp-admin/admin.php?page=gf_settings&subview=gf-zero-spam';

function formSettingsUrl(formId) {
    return `/wp-admin/admin.php?page=gf_edit_forms&view=settings&id=${formId}`;
}

async function saveAndWait(page) {
    await page.locator(SAVE_BUTTON).click();
    await expect(page.locator(SUCCESS_NOTICE)).toContainText('Settings updated.');
}

// POSTs the already-rendered form with a deliberately invalid token so the
// token check produces a spam verdict without the JS submission path.
async function postWithInvalidToken(page, formId, email) {
    return page.evaluate(
        async ({ id, submittedEmail }) => {
            const form = document.getElementById('gform_' + id);
            const fd = new FormData(form);
            fd.set('input_1', 'Botty');
            fd.set('input_2', submittedEmail);
            fd.set('gf_zero_spam_token', 'not-a-valid-zero-spam-token');
            fd.delete('gf_zero_spam_key');

            const res = await fetch(form.action || location.href, {
                method: 'POST',
                body: fd,
                redirect: 'follow',
            });

            return { status: res.status };
        },
        { id: formId, submittedEmail: email }
    );
}

async function getSpamEntryFor(request, formId, email) {
    const spam = await helpers.getEntries(request, formId, 'spam');
    const matched = spam.filter((entry) => entry['2'] === email);
    expect(matched, `spam entry expected for ${email}`).toHaveLength(1);

    return matched[0];
}

test.describe('Spam check order — runtime attribution and stop toggle', () => {
    let formId;
    let pageId;
    let pageUrl;
    let testId;

    test.beforeEach(async ({ request }) => {
        await helpers.resetCheckOrder(request);
        await helpers.setShieldPluginSetting(request, { value: '1' });
        await helpers.setShieldMock(request, { available: true, verdict: 'bot' });

        const data = await helpers.createFromTemplate({
            template: 'simple',
            skipView: true,
        });
        formId = data.form_id;
        testId = data.test_id;

        await helpers.setFormZeroSpam(request, formId, true);

        const created = await helpers.createPage(request, {
            title: `ZS Check Order ${testId}`,
            content: `[gravityform id="${formId}" title="false" description="false" ajax="false"]`,
            slug: `zs-check-order-${testId}`,
        });
        pageId = created.page_id;
        pageUrl = created.permalink;
    });

    test.afterEach(async ({ request }) => {
        await helpers.resetCheckOrder(request);
        await helpers.resetShieldMock(request);
        await helpers.setShieldPluginSetting(request, { remove: true });
        await helpers.cleanup(testId);
        await helpers.cleanupPages(request, [pageId]);
    });

    test('HP-69: the configured order decides which check attributes the spam', async ({
        request,
        browser,
    }) => {
        const anonContext = await browser.newContext({
            storageState: { cookies: [], origins: [] },
        });
        const anonPage = await anonContext.newPage();

        try {
            await anonPage.goto(pageUrl);

            // Default order (token first): the token check flags and, with stop
            // ON (default), the Shield callable must never even be consulted.
            const tokenFirstEmail = `order-token-first+${testId}@gravitykit.test`;
            const first = await postWithInvalidToken(anonPage, formId, tokenFirstEmail);
            expect(first.status).toBe(200);

            const tokenEntry = await getSpamEntryFor(request, formId, tokenFirstEmail);
            const tokenNotes = await helpers.getEntryNotes(request, Number(tokenEntry.id));
            expect(
                tokenNotes.some((note) => note.user_name === 'Zero Spam'),
                'token-first order must attribute the spam to Zero Spam'
            ).toBe(true);
            expect(
                tokenNotes.some((note) => note.user_name === 'Shield silentCAPTCHA'),
                'stop ON must leave no Shield note'
            ).toBe(false);

            let mock = await helpers.getShieldMock(request);
            expect(
                mock.calls,
                'stop ON must skip Shield entirely after the token flags'
            ).toBe(0);

            // Shield first: Shield flags at the first slot and the token check,
            // seeing a flagged entry with stop ON, stays silent.
            await helpers.setCheckOrder(request, { order: ['shield', 'token', 'ai'] });

            const shieldFirstEmail = `order-shield-first+${testId}@gravitykit.test`;
            const second = await postWithInvalidToken(anonPage, formId, shieldFirstEmail);
            expect(second.status).toBe(200);

            const shieldEntry = await getSpamEntryFor(request, formId, shieldFirstEmail);
            const shieldNotes = await helpers.getEntryNotes(request, Number(shieldEntry.id));
            expect(
                shieldNotes.some((note) => note.user_name === 'Shield silentCAPTCHA'),
                'shield-first order must attribute the spam to Shield'
            ).toBe(true);
            expect(
                shieldNotes.some((note) => note.user_name === 'Zero Spam'),
                'stop ON must leave no token note on a Shield-flagged entry'
            ).toBe(false);

            mock = await helpers.getShieldMock(request);
            expect(mock.calls, 'shield-first order must consult the callable').toBeGreaterThan(0);
        } finally {
            await anonContext.close();
        }
    });

    test('HP-70: stop OFF records every check verdict as a note on the flagged entry', async ({
        request,
        browser,
    }) => {
        await helpers.setCheckOrder(request, { stop: false });

        const anonContext = await browser.newContext({
            storageState: { cookies: [], origins: [] },
        });
        const anonPage = await anonContext.newPage();

        try {
            await anonPage.goto(pageUrl);

            // Token first: token flags, Shield still evaluates and records its
            // agreeing verdict as a secondary note.
            const tokenFirstEmail = `stopoff-token-first+${testId}@gravitykit.test`;
            const first = await postWithInvalidToken(anonPage, formId, tokenFirstEmail);
            expect(first.status).toBe(200);

            const tokenEntry = await getSpamEntryFor(request, formId, tokenFirstEmail);
            const tokenNotes = await helpers.getEntryNotes(request, Number(tokenEntry.id));
            expect(
                tokenNotes.some((note) => note.user_name === 'Zero Spam'),
                'token attribution note must be present'
            ).toBe(true);
            expect(
                tokenNotes.some(
                    (note) =>
                        note.user_name === 'Shield silentCAPTCHA' &&
                        note.value.includes('also identified this submission as spam')
                ),
                'Shield must record its agreeing verdict as a note'
            ).toBe(true);

            const mock = await helpers.getShieldMock(request);
            expect(mock.calls, 'stop OFF must still consult Shield').toBeGreaterThan(0);

            // Shield first: Shield flags, the token check still evaluates and
            // records its agreeing verdict as a secondary note.
            await helpers.setCheckOrder(request, {
                order: ['shield', 'token', 'ai'],
                stop: false,
            });

            const shieldFirstEmail = `stopoff-shield-first+${testId}@gravitykit.test`;
            const second = await postWithInvalidToken(anonPage, formId, shieldFirstEmail);
            expect(second.status).toBe(200);

            const shieldEntry = await getSpamEntryFor(request, formId, shieldFirstEmail);
            const shieldNotes = await helpers.getEntryNotes(request, Number(shieldEntry.id));
            expect(
                shieldNotes.some((note) => note.user_name === 'Shield silentCAPTCHA'),
                'Shield attribution note must be present'
            ).toBe(true);
            expect(
                shieldNotes.some(
                    (note) =>
                        note.user_name === 'Zero Spam' &&
                        note.value.includes('also flagged this submission')
                ),
                'the token check must record its agreeing verdict as a note'
            ).toBe(true);
        } finally {
            await anonContext.close();
        }
    });

    test('HP-71: the per-form Zero Spam toggle master-gates Shield at runtime', async ({
        request,
        browser,
    }) => {
        await helpers.setFormZeroSpam(request, formId, false);

        const anonContext = await browser.newContext({
            storageState: { cookies: [], origins: [] },
        });
        const anonPage = await anonContext.newPage();
        const email = `master-gate+${testId}@gravitykit.test`;

        try {
            await anonPage.goto(pageUrl);

            // Tokenless POST with a bot verdict staged: with the master gate
            // off, neither the token check nor Shield may flag it.
            const result = await anonPage.evaluate(
                async ({ id, submittedEmail }) => {
                    const form = document.getElementById('gform_' + id);
                    const fd = new FormData(form);
                    fd.set('input_1', 'Visitor');
                    fd.set('input_2', submittedEmail);
                    fd.delete('gf_zero_spam_token');
                    fd.delete('gf_zero_spam_key');

                    const res = await fetch(form.action || location.href, {
                        method: 'POST',
                        body: fd,
                        redirect: 'follow',
                    });

                    return { status: res.status };
                },
                { id: formId, submittedEmail: email }
            );
            expect(result.status).toBe(200);
        } finally {
            await anonContext.close();
        }

        const active = await helpers.getEntries(request, formId, 'active');
        const submitted = active.filter((entry) => entry['2'] === email);
        expect(submitted, 'gated submission must land as an active entry').toHaveLength(1);

        const spam = await helpers.getEntries(request, formId, 'spam');
        const leaked = spam.filter((entry) => entry['2'] === email);
        expect(leaked, 'no check may flag while the master gate is off').toHaveLength(0);

        const mock = await helpers.getShieldMock(request);
        expect(mock.calls, 'the Shield callable must never be consulted').toBe(0);
    });

    test('HP-72: the master toggle hides the Shield and AI fields and preserves stored Shield values', async ({
        page,
        request,
    }) => {
        await helpers.setFormShieldMeta(request, formId, { value: '1' });

        await page.goto(formSettingsUrl(formId));

        await expect(page.locator(MASTER_TOGGLE)).toBeChecked();
        await expect(page.locator(SHIELD_TOGGLE)).toBeVisible();
        await expect(page.locator(AI_TOGGLE)).toBeVisible();

        await page.locator(MASTER_TOGGLE_LABEL).click();
        await expect(page.locator(MASTER_TOGGLE)).not.toBeChecked();
        await expect(
            page.locator(SHIELD_TOGGLE),
            'Shield field must live-hide when the master toggle is off'
        ).toBeHidden();
        await expect(
            page.locator(AI_TOGGLE),
            'AI field must live-hide when the master toggle is off'
        ).toBeHidden();

        await saveAndWait(page);

        const meta = await helpers.getFormShieldMeta(request, formId);
        expect(meta.present, 'stored Shield override must survive a master-off save').toBe(true);
        expect(meta.value, 'stored Shield override value must be preserved').toBe('1');

        await page.reload();
        await expect(page.locator(MASTER_TOGGLE)).not.toBeChecked();
        await expect(page.locator(SHIELD_TOGGLE)).toBeHidden();

        // Re-enabling the master toggle brings the preserved state back.
        await page.locator(MASTER_TOGGLE_LABEL).click();
        await expect(page.locator(SHIELD_TOGGLE)).toBeVisible();
        await expect(page.locator(SHIELD_TOGGLE)).toBeChecked();
    });
});

test.describe('Spam check order — settings UI', () => {
    test.beforeEach(async ({ request }) => {
        await helpers.resetCheckOrder(request);
        await helpers.setShieldMock(request, { available: true });
    });

    test.afterEach(async ({ request }) => {
        await helpers.resetCheckOrder(request);
        await helpers.resetShieldMock(request);
    });

    test('HP-73: reordering saves and round-trips; duplicate selections fall back to the default order', async ({
        page,
        request,
    }) => {
        await page.goto(PLUGIN_SETTINGS_URL);

        await expect(page.locator(ORDER_SELECT_1)).toHaveValue('token');
        await expect(page.locator(ORDER_SELECT_2)).toHaveValue('shield');
        await expect(page.locator(ORDER_SELECT_3)).toHaveValue('ai');
        await expect(page.locator(STOP_TOGGLE), 'stop defaults to ON').toBeChecked();

        await page.locator(ORDER_SELECT_1).selectOption('shield');
        await page.locator(ORDER_SELECT_2).selectOption('token');
        await saveAndWait(page);

        let stored = await helpers.getCheckOrder(request);
        expect(stored.order, 'reorder must persist').toEqual(['shield', 'token', 'ai']);

        await page.reload();
        await expect(page.locator(ORDER_SELECT_1)).toHaveValue('shield');
        await expect(page.locator(ORDER_SELECT_2)).toHaveValue('token');
        await expect(page.locator(ORDER_SELECT_3)).toHaveValue('ai');

        // Duplicate selection: 'shield' in two slots cannot persist — the save
        // must fall back to the default order.
        await page.locator(ORDER_SELECT_2).selectOption('shield');
        await saveAndWait(page);

        stored = await helpers.getCheckOrder(request);
        expect(stored.order, 'a duplicated selection must fall back to the default order').toEqual([
            'token',
            'shield',
            'ai',
        ]);

        await page.reload();
        await expect(page.locator(ORDER_SELECT_1)).toHaveValue('token');
        await expect(page.locator(ORDER_SELECT_2)).toHaveValue('shield');
        await expect(page.locator(ORDER_SELECT_3)).toHaveValue('ai');
    });

    test('HP-74: order settings save while Shield is absent, with the skipped-check note shown', async ({
        page,
        request,
    }) => {
        await helpers.resetShieldMock(request);

        await page.goto(PLUGIN_SETTINGS_URL);

        await expect(
            page.locator(`text=${SHIELD_SKIPPED_TEXT}`).first(),
            'unavailable Shield must be called out as skipped'
        ).toBeVisible();

        await page.locator(ORDER_SELECT_1).selectOption('ai');
        await page.locator(ORDER_SELECT_2).selectOption('token');
        await page.locator(ORDER_SELECT_3).selectOption('shield');
        await saveAndWait(page);

        const stored = await helpers.getCheckOrder(request);
        expect(
            stored.order,
            'the order must persist even while Shield is absent'
        ).toEqual(['ai', 'token', 'shield']);

        await page.reload();
        await expect(page.locator(ORDER_SELECT_1)).toHaveValue('ai');
        await expect(page.locator(ORDER_SELECT_2)).toHaveValue('token');
        await expect(page.locator(ORDER_SELECT_3)).toHaveValue('shield');
    });
});
