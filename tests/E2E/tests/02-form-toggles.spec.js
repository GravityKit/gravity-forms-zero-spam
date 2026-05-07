/**
 * Zero Spam — per-form and global default toggles (HP-4, HP-5, HP-6).
 *
 * Verified live with Playwright MCP against GF 2.9.25.1:
 *   - Form Settings URL: /wp-admin/admin.php?page=gf_edit_forms&view=settings&id=<id>
 *   - Toggle input:      input#_gform_setting_enableGFZeroSpam (checkbox).
 *   - Toggle affordance: label[for="_gform_setting_enableGFZeroSpam"] (visible switch).
 *   - Save button:       #gform-settings-save.
 *   - Success notice:    .alert.gforms_note_success → "Settings updated."
 *   - The Zero Spam toggle lives under the "Spam Detection" panel (GF 2.9.21+).
 */

const { test, expect } = require('@wordpress/e2e-test-utils-playwright');
const helpers = require('../helpers');

const TOGGLE_INPUT = '#_gform_setting_enableGFZeroSpam';
const TOGGLE_LABEL = `label[for="_gform_setting_enableGFZeroSpam"]`;
const SAVE_BUTTON = '#gform-settings-save';
const SUCCESS_NOTICE = '.alert.gforms_note_success';

function formSettingsUrl(formId) {
    return `/wp-admin/admin.php?page=gf_edit_forms&view=settings&id=${formId}`;
}

async function postFormDirectly(page, formId, values) {
    return page.evaluate(
        async ({ id, vals }) => {
            const form = document.getElementById('gform_' + id);
            if (!form) return { error: 'no_form' };

            const fd = new FormData(form);
            for (const [k, v] of Object.entries(vals)) {
                fd.set(k, v);
            }
            fd.delete('gf_zero_spam_token');
            fd.delete('gf_zero_spam_key');

            const res = await fetch(form.action || location.href, {
                method: 'POST',
                body: fd,
                redirect: 'follow',
            });
            return { status: res.status };
        },
        { id: formId, vals: values }
    );
}

async function waitForToggleSavedAs(page, expected) {
    await expect(page.locator(SUCCESS_NOTICE)).toContainText('Settings updated.');
    // After save, GF re-renders the panel; assert the persisted value.
    await expect(page.locator(TOGGLE_INPUT)).toBeChecked({ checked: expected });
}

test.describe('Zero Spam — per-form toggles', () => {
    let formId;
    let pageId;
    let pageUrl;
    let testId;

    test.beforeEach(async ({ request }) => {
        const data = await helpers.createFromTemplate({
            template: 'simple',
            skipView: true,
        });
        formId = data.form_id;
        testId = data.test_id;

        const created = await helpers.createPage(request, {
            title: `ZS Toggles ${testId}`,
            content: `[gravityform id="${formId}" title="false" description="false" ajax="false"]`,
            slug: `zs-toggles-${testId}`,
        });
        pageId = created.page_id;
        pageUrl = created.permalink;
    });

    test.afterEach(async ({ request }) => {
        await helpers.cleanup(testId);
        await helpers.cleanupPages(request, [pageId]);
    });

    test('HP-4: admin enables the Zero Spam toggle on a previously-unprotected form', async ({
        page,
        request,
        browser,
    }) => {
        // Start from a known "off" state, independent of global default.
        await helpers.setFormZeroSpam(request, formId, false);

        await page.goto(formSettingsUrl(formId));
        await expect(page.locator(TOGGLE_INPUT)).not.toBeChecked();

        await page.locator(TOGGLE_LABEL).click();
        await expect(page.locator(TOGGLE_INPUT)).toBeChecked();

        await page.locator(SAVE_BUTTON).click();
        await waitForToggleSavedAs(page, true);

        // Behavioral assertion in an anonymous context: tokenless POST is now spam.
        const anonContext = await browser.newContext({
            storageState: { cookies: [], origins: [] },
        });
        const anonPage = await anonContext.newPage();

        try {
            await anonPage.goto(pageUrl);
            const result = await postFormDirectly(anonPage, formId, {
                input_1: 'Botty',
                input_2: `bot+${testId}@gravitykit.test`,
            });
            expect(result.status).toBe(200);
        } finally {
            await anonContext.close();
        }

        const spam = await helpers.getEntries(request, formId, 'spam');
        const trapped = spam.filter(
            (entry) => entry['2'] === `bot+${testId}@gravitykit.test`
        );
        expect(trapped, 'after enabling, tokenless POST must be spam').toHaveLength(1);
    });

    test('HP-5: admin disables the Zero Spam toggle on a protected form', async ({
        page,
        request,
        browser,
    }) => {
        // Start from a known "on" state.
        await helpers.setFormZeroSpam(request, formId, true);

        await page.goto(formSettingsUrl(formId));
        await expect(page.locator(TOGGLE_INPUT)).toBeChecked();

        await page.locator(TOGGLE_LABEL).click();
        await expect(page.locator(TOGGLE_INPUT)).not.toBeChecked();

        await page.locator(SAVE_BUTTON).click();
        await waitForToggleSavedAs(page, false);

        // Behavioral assertion: tokenless POST should NOT be flagged as spam.
        const anonContext = await browser.newContext({
            storageState: { cookies: [], origins: [] },
        });
        const anonPage = await anonContext.newPage();

        try {
            await anonPage.goto(pageUrl);
            const result = await postFormDirectly(anonPage, formId, {
                input_1: 'Allowed',
                input_2: `allowed+${testId}@gravitykit.test`,
            });
            expect(result.status).toBe(200);
        } finally {
            await anonContext.close();
        }

        const active = await helpers.getEntries(request, formId, 'active');
        const allowed = active.filter(
            (entry) => entry['2'] === `allowed+${testId}@gravitykit.test`
        );
        expect(allowed, 'after disabling, tokenless POST must be active').toHaveLength(1);

        const spam = await helpers.getEntries(request, formId, 'spam');
        const leaked = spam.filter(
            (entry) => entry['2'] === `allowed+${testId}@gravitykit.test`
        );
        expect(leaked, 'no spam entry should exist when toggle is off').toHaveLength(0);
    });
});

test.describe('Zero Spam — global default propagation', () => {
    // Mutates the GLOBAL "Enable Zero Spam by Default" plugin setting, which
    // is shared across all forms. Run serially so two workers don't race
    // each other into a "flipped" intermediate state.
    test.describe.configure({ mode: 'serial' });

    let createdTestIds = [];
    let createdPageIds = [];

    test.afterEach(async ({ request }) => {
        for (const id of createdTestIds) {
            await helpers.cleanup(id);
        }
        if (createdPageIds.length > 0) {
            await helpers.cleanupPages(request, createdPageIds);
        }
        createdTestIds = [];
        createdPageIds = [];

        // Restore the documented default (enabled) so we don't poison subsequent runs.
        await helpers.setGlobalDefault(request, true);
    });

    test('HP-6: global default propagates to a freshly-created form', async ({
        page,
        request,
    }) => {
        // 1) Global = disabled → new form should default to OFF.
        await helpers.setGlobalDefault(request, false);

        const off = await helpers.createFromTemplate({
            template: 'simple',
            skipView: true,
        });
        createdTestIds.push(off.test_id);

        await page.goto(formSettingsUrl(off.form_id));
        await expect(
            page.locator(TOGGLE_INPUT),
            'form created with global default off should render toggle unchecked'
        ).not.toBeChecked();

        // 2) Global = enabled → new form should default to ON.
        await helpers.setGlobalDefault(request, true);

        const on = await helpers.createFromTemplate({
            template: 'simple',
            skipView: true,
        });
        createdTestIds.push(on.test_id);

        await page.goto(formSettingsUrl(on.form_id));
        await expect(
            page.locator(TOGGLE_INPUT),
            'form created with global default on should render toggle checked'
        ).toBeChecked();
    });
});
