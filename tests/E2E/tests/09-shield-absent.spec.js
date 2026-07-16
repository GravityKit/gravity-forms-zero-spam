/**
 * Zero Spam — Shield silentCAPTCHA with Shield ABSENT (HP-60 through HP-63).
 *
 * This is the default CI state: the Shield Security plugin is not installed,
 * so the integration must render disabled controls, never wipe stored values
 * on save (a disabled toggle posts nothing, which is indistinguishable from an
 * uncheck), preserve per-form inheritance, and fail open on submissions even
 * when the setting is enabled.
 *
 * Selectors (same GF Settings framework as 02-form-toggles):
 *   - Toggle input:   input#_gform_setting_shield_silent_captcha (checkbox).
 *   - Save button:    #gform-settings-save.
 *   - Success notice: .alert.gforms_note_success → "Settings updated."
 */

const { test, expect } = require('@wordpress/e2e-test-utils-playwright');
const helpers = require('../helpers');

// Mutates the global Shield plugin setting; keep tests in this file ordered.
test.describe.configure({ mode: 'serial' });

const SHIELD_TOGGLE = '#_gform_setting_shield_silent_captcha';
const SAVE_BUTTON = '#gform-settings-save';
const SUCCESS_NOTICE = '.alert.gforms_note_success';
const UNAVAILABLE_TEXT =
    'Shield silentCAPTCHA is currently unavailable because Shield Security is not installed or active.';

const PLUGIN_SETTINGS_URL = '/wp-admin/admin.php?page=gf_settings&subview=gf-zero-spam';

function formSettingsUrl(formId) {
    return `/wp-admin/admin.php?page=gf_edit_forms&view=settings&id=${formId}`;
}

test.describe('Shield silentCAPTCHA — Shield absent', () => {
    test.beforeEach(async ({ request }) => {
        // Defensive: make sure a previous run's mock isn't leaving Shield "installed".
        await helpers.resetShieldMock(request);
    });

    test.afterEach(async ({ request }) => {
        await helpers.setShieldPluginSetting(request, { remove: true });
        await helpers.resetShieldMock(request);
    });

    test('HP-60: plugin settings render a disabled Shield toggle with the unavailable message', async ({
        page,
    }) => {
        await page.goto(PLUGIN_SETTINGS_URL);

        const toggle = page.locator(SHIELD_TOGGLE);
        await expect(toggle, 'Shield toggle must render').toHaveCount(1);
        await expect(toggle, 'Shield toggle must be disabled when Shield is absent').toBeDisabled();
        await expect(toggle, 'default stored value is off').not.toBeChecked();

        await expect(page.locator(`text=${UNAVAILABLE_TEXT}`)).toBeVisible();

        const learnMore = page.locator('a', { hasText: 'Learn More' }).first();
        await expect(learnMore).toBeVisible();
        await expect(learnMore).toHaveAttribute('href', /gravitykit\.com\/zero-spam-shield-silentcaptcha/);
    });

    test('HP-61: saving plugin settings while Shield is absent preserves the stored value', async ({
        page,
        request,
    }) => {
        // A site enabled Shield, then deactivated the Shield plugin. Saving the
        // Zero Spam settings page must NOT wipe the stored '1' to '0'.
        await helpers.setShieldPluginSetting(request, { value: '1' });

        await page.goto(PLUGIN_SETTINGS_URL);

        const toggle = page.locator(SHIELD_TOGGLE);
        await expect(toggle).toBeDisabled();
        await expect(toggle, 'stored value must render as checked even while disabled').toBeChecked();

        await page.locator(SAVE_BUTTON).click();
        await expect(page.locator(SUCCESS_NOTICE)).toContainText('Settings updated.');

        const stored = await helpers.getShieldPluginSetting(request);
        expect(stored.present, 'stored Shield setting must survive the save').toBe(true);
        expect(stored.value, 'disabled toggle must not wipe the stored value to 0').toBe('1');

        // The save postback re-renders from POSTed values, where the disabled
        // toggle posted nothing, so it briefly renders unchecked. The durable
        // state is what a fresh page load renders.
        await page.reload();
        await expect(page.locator(SHIELD_TOGGLE)).toBeChecked();
    });

    test('HP-62: form settings render a disabled Shield toggle and an untouched save keeps inheriting', async ({
        page,
        request,
    }) => {
        const data = await helpers.createFromTemplate({
            template: 'simple',
            skipView: true,
        });

        try {
            await page.goto(formSettingsUrl(data.form_id));

            const toggle = page.locator(SHIELD_TOGGLE);
            await expect(toggle, 'per-form Shield toggle must render').toHaveCount(1);
            await expect(toggle, 'per-form Shield toggle must be disabled when Shield is absent').toBeDisabled();

            await page.locator(SAVE_BUTTON).click();
            await expect(page.locator(SUCCESS_NOTICE)).toContainText('Settings updated.');

            const meta = await helpers.getFormShieldMeta(request, data.form_id);
            expect(
                meta.present,
                'untouched save must not materialize a per-form Shield override'
            ).toBe(false);
        } finally {
            await helpers.cleanup(data.test_id);
        }
    });

    test('HP-63: submission passes when Shield is enabled but absent (fail-open smoke)', async ({
        request,
        browser,
    }) => {
        // Worst case for fail-open: the setting says ON but Shield is gone.
        await helpers.setShieldPluginSetting(request, { value: '1' });

        const data = await helpers.createFromTemplate({
            template: 'simple',
            skipView: true,
        });
        const created = await helpers.createPage(request, {
            title: `ZS Shield Absent ${data.test_id}`,
            content: `[gravityform id="${data.form_id}" title="false" description="false" ajax="false"]`,
            slug: `zs-shield-absent-${data.test_id}`,
        });

        // Token protection ON like HP-1: a real browser passes the token check,
        // then the Shield hook runs — and must be a no-op without Shield.
        await helpers.setFormZeroSpam(request, data.form_id, true);

        const anonContext = await browser.newContext({
            storageState: { cookies: [], origins: [] },
        });
        const anonPage = await anonContext.newPage();
        const email = `shield-absent+${data.test_id}@gravitykit.test`;

        try {
            await anonPage.goto(created.permalink);
            await anonPage
                .locator(`#gform_${data.form_id} input[name="input_1"]`)
                .fill('Alice');
            await anonPage
                .locator(`#gform_${data.form_id} input[name="input_2"]`)
                .fill(email);
            await anonPage.locator(`#gform_submit_button_${data.form_id}`).click();

            await expect(
                anonPage.locator(`#gform_confirmation_wrapper_${data.form_id}`)
            ).toContainText('Thanks for contacting us!');

            const active = await helpers.getEntries(request, data.form_id, 'active');
            const submitted = active.filter((entry) => entry['2'] === email);
            expect(submitted, 'submission must land as an active entry').toHaveLength(1);

            const spam = await helpers.getEntries(request, data.form_id, 'spam');
            const leaked = spam.filter((entry) => entry['2'] === email);
            expect(leaked, 'Shield-absent must never flag spam').toHaveLength(0);
        } finally {
            await anonContext.close();
            await helpers.cleanup(data.test_id);
            await helpers.cleanupPages(request, [created.page_id]);
        }
    });
});
