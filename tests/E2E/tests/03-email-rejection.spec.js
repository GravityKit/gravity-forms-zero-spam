/**
 * Zero Spam — email rejection rules (HP-7, HP-8, HP-9, HP-10).
 *
 * Verified live with Playwright MCP against ZS 1.8.0 / GF 2.9.25.1:
 *   - Settings URL:  /wp-admin/admin.php?page=gf_settings&subview=gf-zero-spam
 *   - Feature toggle input: input#_gform_setting_gf_zero_spam_email_rejection_enabled
 *   - Feature toggle label: label[for="_gform_setting_gf_zero_spam_email_rejection_enabled"]
 *   - Rules section panel:   div#gform_setting_gf_zero_spam_email_rules
 *     (rendered with inline style="display:none" while feature is off; visible
 *     once the toggle is saved as on)
 *   - Save button:    #gform-settings-save
 *   - Success notice: .alert.gforms_note_success ("Settings updated.")
 *
 * The rules array is stored as a PHP array under the key
 * `gf_zero_spam_email_rules` inside the addon settings option
 * (gravityformsaddon_gf-zero-spam_settings). Each rule is
 * { type: domain|email|wildcard|regex, value: string,
 *   action: block|flag|log, enabled: bool }.
 */

const { test, expect } = require('@wordpress/e2e-test-utils-playwright');
const helpers = require('../helpers');

// Email rejection state is GLOBAL to the addon. Two workers running these
// tests in parallel would clobber each other's read/modify/write of the
// gravityformsaddon_gf-zero-spam_settings option.
test.describe.configure({ mode: 'serial' });

const SETTINGS_URL = '/wp-admin/admin.php?page=gf_settings&subview=gf-zero-spam';
const FEATURE_TOGGLE_INPUT = '#_gform_setting_gf_zero_spam_email_rejection_enabled';
const FEATURE_TOGGLE_LABEL = `label[for="_gform_setting_gf_zero_spam_email_rejection_enabled"]`;
const RULES_PANEL = '#gform_setting_gf_zero_spam_email_rules';
const SAVE_BUTTON = '#gform-settings-save';
const SUCCESS_NOTICE = '.alert.gforms_note_success';

async function fillAndSubmit(page, formId, { input_1, input_2 }) {
    await page.locator(`#gform_${formId} input[name="input_1"]`).fill(input_1);
    await page.locator(`#gform_${formId} input[name="input_2"]`).fill(input_2);
    await page.locator(`#gform_submit_button_${formId}`).click();
}

test.describe('Zero Spam — email rejection rules', () => {
    let formId;
    let pageId;
    let pageUrl;
    let testId;

    test.beforeEach(async ({ request }) => {
        // The 'simple' template's "Email" field is type=text, so GF's
        // gform_email_field_rejectable_values filter (the block-rule hook)
        // never fires against it. Use a custom form with a real email field.
        //
        // Calling api.setup() directly because fixtures.create() doesn't
        // forward `skip_view` — without it, the /create endpoint tries to
        // build a GravityView and fails on a GF-only install.
        testId = helpers.generateTestId();
        const data = await helpers.api.setup({
            test_id: testId,
            form: {
                title: 'A Simple Form',
                fields: [
                    {
                        id: 1,
                        type: 'text',
                        label: 'First Name',
                        isRequired: true,
                    },
                    {
                        id: 2,
                        type: 'email',
                        label: 'Email',
                        isRequired: true,
                    },
                ],
            },
            entries: [],
            skip_view: true,
        });

        if (data.error) {
            throw new Error(`Form creation failed: ${data.error}`);
        }

        formId = data.form_id;

        await helpers.setFormZeroSpam(request, formId, true);

        const created = await helpers.createPage(request, {
            title: `ZS Email Rejection ${testId}`,
            content: `[gravityform id="${formId}" title="false" description="false" ajax="false"]`,
            slug: `zs-email-${testId}`,
        });
        pageId = created.page_id;
        pageUrl = created.permalink;
    });

    test.afterEach(async ({ request }) => {
        await helpers.cleanup(testId);
        await helpers.cleanupPages(request, [pageId]);

        // Clear email-rejection-specific state. Leaves gf_zero_spam_blocking
        // and other unrelated keys alone so HP-6 in another file isn't
        // poisoned by cross-file cleanup.
        await helpers.setEmailRules(request, {
            enabled: false,
            rules: [],
            message: '',
        });
    });

    test('HP-7: enabling the feature reveals the rule builder and persists across reload', async ({
        page,
        request,
    }) => {
        // Pre-condition: feature off.
        await helpers.setEmailRules(request, { enabled: false, rules: [] });

        await page.goto(SETTINGS_URL);

        await expect(page.locator(FEATURE_TOGGLE_INPUT)).not.toBeChecked();
        await expect(
            page.locator(RULES_PANEL),
            'rules panel is hidden while feature is off'
        ).toBeHidden();

        await page.locator(FEATURE_TOGGLE_LABEL).click();
        await expect(page.locator(FEATURE_TOGGLE_INPUT)).toBeChecked();

        await page.locator(SAVE_BUTTON).click();
        await expect(page.locator(SUCCESS_NOTICE)).toContainText('Settings updated.');

        // After save the page re-renders. The rules section should now be visible
        // and the toggle should still be on.
        await expect(page.locator(FEATURE_TOGGLE_INPUT)).toBeChecked();
        await expect(page.locator(RULES_PANEL)).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'Add Rule' })
        ).toBeVisible();
    });

    test('HP-8: a block rule rejects a matching submission inline with the custom message', async ({
        page,
        request,
    }) => {
        const customMessage = `Email rejected by E2E ${testId}`;

        await helpers.setEmailRules(request, {
            enabled: true,
            message: customMessage,
            rules: [
                {
                    type: 'email',
                    value: 'spammer@example.test',
                    action: 'block',
                    enabled: true,
                },
            ],
        });

        await page.goto(pageUrl);
        await page
            .locator(`#gform_${formId} input[name="input_1"]`)
            .fill('Mallory');
        await page
            .locator(`#gform_${formId} input[name="input_2"]`)
            .fill('spammer@example.test');
        await page.locator(`#gform_submit_button_${formId}`).click();

        // Validation error container renders at the top of the form on reload.
        await expect(
            page.locator(`#gform_${formId}_validation_container`)
        ).toBeVisible();

        // The custom message renders inline as a field-level validation message.
        await expect(
            page.locator(`#gform_${formId}`).getByText(customMessage)
        ).toBeVisible();

        // No entry is created — block rejects pre-validation.
        const entries = await helpers.getEntries(request, formId);
        const ours = entries.filter(
            (entry) => entry['2'] === 'spammer@example.test'
        );
        expect(ours, 'block rule must prevent entry creation').toHaveLength(0);
    });

    test('HP-9: a flag rule marks a matching submission as spam', async ({
        page,
        request,
    }) => {
        await helpers.setEmailRules(request, {
            enabled: true,
            rules: [
                {
                    // Domain rules match by bare hostname (no leading @).
                    // match_domain() compares the post-@ portion of the
                    // submitted address to the rule's value verbatim.
                    type: 'domain',
                    value: 'badmail.test',
                    action: 'flag',
                    enabled: true,
                },
            ],
        });

        await page.goto(pageUrl);

        // Real-user submission. GF's pre-submission filter (registered by ZS)
        // injects a valid token, so we exercise the flag path, not the
        // missing-token spam path.
        await fillAndSubmit(page, formId, {
            input_1: 'Mallory',
            input_2: `mallory+${testId}@badmail.test`,
        });

        await expect(
            page.locator(`#gform_confirmation_wrapper_${formId}`)
        ).toContainText('Thanks for contacting us!');

        const spam = await helpers.getEntries(request, formId, 'spam');
        const flagged = spam.filter(
            (entry) => entry['2'] === `mallory+${testId}@badmail.test`
        );
        expect(flagged, 'flag rule must mark the submission as spam').toHaveLength(1);

        const active = await helpers.getEntries(request, formId, 'active');
        const leaked = active.filter(
            (entry) => entry['2'] === `mallory+${testId}@badmail.test`
        );
        expect(leaked, 'flag rule entry must not be active').toHaveLength(0);
    });

    test('HP-10: a log rule attaches an entry note without flagging the submission', async ({
        page,
        request,
    }) => {
        await helpers.setEmailRules(request, {
            enabled: true,
            rules: [
                {
                    type: 'wildcard',
                    value: '*+log@*',
                    action: 'log',
                    enabled: true,
                },
            ],
        });

        await page.goto(pageUrl);

        await fillAndSubmit(page, formId, {
            input_1: 'Logger',
            input_2: `logger+log@gravitykit.test`,
        });

        await expect(
            page.locator(`#gform_confirmation_wrapper_${formId}`)
        ).toContainText('Thanks for contacting us!');

        const active = await helpers.getEntries(request, formId, 'active');
        const ours = active.filter(
            (entry) => entry['2'] === `logger+log@gravitykit.test`
        );
        expect(ours, 'log rule must NOT change entry status').toHaveLength(1);

        const notes = await helpers.getEntryNotes(request, ours[0].id);
        // The plugin tags its notes with note_type='gf-zero-spam'; sub_type
        // is 'info' for log matches and 'warning' for flag matches.
        const zsNotes = notes.filter((note) => note.note_type === 'gf-zero-spam');
        expect(zsNotes.length, 'log rule must add an entry note').toBeGreaterThan(0);
        expect(zsNotes[0].value).toMatch(/log action/i);
    });
});
