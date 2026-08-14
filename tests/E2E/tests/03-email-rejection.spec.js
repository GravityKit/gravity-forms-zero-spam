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

const FIELD_PANEL = 'li.email_rejection_setting';
const FIELD_DISABLED_NOTICE = `${FIELD_PANEL} [data-role="feature-disabled"]`;

/**
 * Open the Advanced tab of the form's email field (field ID 2) in the editor.
 * The editor keys field containers by field ID alone, not form ID.
 */
async function openEmailFieldAdvanced(page, formId) {
    await page.goto(`/wp-admin/admin.php?page=gf_edit_forms&id=${formId}`);
    await page.locator('#field_2').click();
    await page.getByRole('tab', { name: 'Advanced' }).click();
    await expect(page.locator(FIELD_PANEL)).toBeVisible();
}

/**
 * Add a rule through the per-field rule builder's add row.
 * The builder renders its rule table only once the field toggle is on.
 */
async function addFieldRule(page, { type, value, action }) {
    const toggle = page.locator('[data-role="field-enabled"]');

    if (!(await toggle.isChecked())) {
        await page.locator('label[for="gf-zs-field-enabled"]').click();
    }

    const addRow = page.locator(`${FIELD_PANEL} .gf-zero-spam-add-row`);

    await addRow.locator('.gf-zero-spam-type-select').selectOption(type);
    await addRow.locator('[data-role="new-value"]').fill(value);
    await addRow.locator('.gf-zero-spam-action-select').selectOption(action);
    await addRow.locator('[data-action="add"]').click();
}

async function saveForm(page) {
    await page.locator('#ajax-save-form-menu-bar').click();
    await expect(page.locator('#please_wait_container')).toBeHidden();
}

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
        // Filter falsy ids: if beforeEach throws before pageId is assigned,
        // we don't want to send [undefined] to the cleanup endpoint.
        const pageIds = [pageId].filter(Boolean);
        if (pageIds.length) {
            await helpers.cleanupPages(request, pageIds);
        }

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
        // Assert structured fields rather than free-text wording. The plugin
        // tags log notes with note_type='gf-zero-spam' and sub_type='info'
        // (flag rules use sub_type='warning'). The note body should mention
        // the rule's value so we know we're looking at the right match, but
        // we keep that check tolerant — wording is not stable.
        const logNotes = notes.filter(
            (note) => note.note_type === 'gf-zero-spam' && note.sub_type === 'info'
        );
        expect(logNotes.length, 'log rule must add an info-typed Zero Spam note').toBeGreaterThan(0);
        expect(logNotes[0].value).toContain('*+log@*');
    });

    test('HP-11: field rules are inert while the feature is off, and the editor says so', async ({
        page,
        request,
    }) => {
        // The per-field builder renders whether or not the feature is on, so
        // without the warning its rules look active and silently do nothing.
        await helpers.setEmailRules(request, { enabled: false, rules: [] });

        await openEmailFieldAdvanced(page, formId);

        const notice = page.locator(FIELD_DISABLED_NOTICE);

        await expect(notice, 'field editor must warn while the feature is off').toBeVisible();
        await expect(notice).toContainText('will not run');
        await expect(notice.locator('a')).toHaveAttribute(
            'href',
            /subview=gf-zero-spam/
        );

        // Configure a real field rule through the editor and save it, so the
        // rest of the test proves enforcement rather than an empty ruleset.
        await addFieldRule(page, {
            type: 'domain',
            value: 'inert.test',
            action: 'block',
        });
        await saveForm(page);

        await openEmailFieldAdvanced(page, formId);
        await expect(
            page.locator(`${FIELD_PANEL} tr[data-rule-id]`),
            'the field rule must persist across a save and reload'
        ).toHaveCount(1);

        // Feature still off: the saved rule must not stop the submission.
        await page.goto(pageUrl);
        await fillAndSubmit(page, formId, {
            input_1: 'Inert',
            input_2: `inert+${testId}@inert.test`,
        });
        await expect(
            page.locator(`#gform_confirmation_wrapper_${formId}`),
            'field rules must not run while the feature is off'
        ).toContainText('Thanks for contacting us!');

        // Turn the feature on: the same rule now blocks, and the warning is gone.
        await helpers.setEmailRules(request, { enabled: true, rules: [] });

        await openEmailFieldAdvanced(page, formId);
        await expect(
            page.locator(FIELD_DISABLED_NOTICE),
            'no warning once the feature is on'
        ).toHaveCount(0);

        await page.goto(pageUrl);
        await fillAndSubmit(page, formId, {
            input_1: 'Inert',
            input_2: `inert+${testId}@inert.test`,
        });
        await expect(
            page.locator(`#gform_${formId}_validation_container`),
            'the same field rule must block once the feature is on'
        ).toBeVisible();
    });

    test('HP-12: regex rules survive the settings save path verbatim', async ({
        page,
        request,
    }) => {
        // Must go through the real UI: the E2E helper writes the option directly,
        // so it exercises neither normalizeValue() nor sanitize_rule(), the two
        // places that were corrupting patterns.
        await helpers.setEmailRules(request, { enabled: true, rules: [] });

        // A leading "." was stripped as stray punctuation; "<" was stripped as
        // markup by sanitize_text_field(), breaking lookbehinds.
        const patterns = ['.+@leadingdot\\.test', '(?<=@)lookbehind\\.test'];

        await page.goto(SETTINGS_URL);

        for (const pattern of patterns) {
            const addRow = page.locator(
                '#gf-zero-spam-rule-builder .gf-zero-spam-add-row'
            );

            await addRow.locator('.gf-zero-spam-type-select').selectOption('regex');
            await addRow.locator('[data-role="new-value"]').fill(pattern);
            await addRow.locator('.gf-zero-spam-action-select').selectOption('block');
            await addRow.locator('[data-action="add"]').click();
        }

        await page.locator(SAVE_BUTTON).click();
        await expect(page.locator(SUCCESS_NOTICE)).toContainText('Settings updated.');

        const stored = await helpers.getEmailRules(request);
        const storedValues = stored.rules.map((rule) => rule.value);

        expect(
            storedValues,
            'regex patterns must round-trip through the save path verbatim'
        ).toEqual(patterns);

        // The customer's pattern, end to end.
        await page.goto(pageUrl);
        await fillAndSubmit(page, formId, {
            input_1: 'Regex',
            input_2: 'bob@leadingdot.test',
        });
        await expect(
            page.locator(`#gform_${formId}_validation_container`),
            'a pattern starting with "." must block after saving'
        ).toBeVisible();
    });

    test('HP-13: regex rules match case-sensitively', async ({
        page,
        request,
    }) => {
        // Lowercasing rule values turned "\D" (non-digit) into "\d" (digit),
        // inverting the rule and blocking legitimate submissions.
        await helpers.setEmailRules(request, {
            enabled: true,
            rules: [
                {
                    type: 'regex',
                    value: '^\\D.*@caseclass\\.test$',
                    action: 'block',
                    enabled: true,
                },
            ],
        });

        // Starts with a letter — \D matches, so this is blocked.
        await page.goto(pageUrl);
        await fillAndSubmit(page, formId, {
            input_1: 'Regex',
            input_2: 'abc@caseclass.test',
        });
        await expect(
            page.locator(`#gform_${formId}_validation_container`),
            '\\D must match a non-digit first character'
        ).toBeVisible();

        // Starts with a digit — \D does not match, so this goes through.
        await page.goto(pageUrl);
        await fillAndSubmit(page, formId, {
            input_1: 'Regex',
            input_2: `1abc+${testId}@caseclass.test`,
        });
        await expect(
            page.locator(`#gform_confirmation_wrapper_${formId}`),
            '\\D must not match a digit first character'
        ).toContainText('Thanks for contacting us!');
    });

    test('HP-14: a custom validation message cannot inject scripts into the form', async ({
        page,
        request,
    }) => {
        await helpers.setEmailRules(request, {
            enabled: true,
            message: '<img src=x onerror="window.__zsXss=1">Rejected <strong>here</strong>',
            rules: [
                {
                    type: 'domain',
                    value: 'xsscheck.test',
                    action: 'block',
                    enabled: true,
                },
            ],
        });

        await page.goto(pageUrl);
        await fillAndSubmit(page, formId, {
            input_1: 'Mallory',
            input_2: 'mallory@xsscheck.test',
        });

        await expect(
            page.locator(`#gform_${formId}_validation_container`)
        ).toBeVisible();

        await expect(
            page.locator(`#gform_${formId} [onerror]`),
            'event handlers must be stripped from the validation message'
        ).toHaveCount(0);
        expect(
            await page.evaluate(() => window.__zsXss),
            'the payload must not execute'
        ).toBeUndefined();

        // Safe formatting still survives, so the message stays useful.
        await expect(
            page.locator(`#gform_${formId} strong`).filter({ hasText: 'here' })
        ).toBeVisible();
    });

    test('HP-15: a per-field validation message cannot inject scripts into the form', async ({
        page,
        request,
    }) => {
        // The per-field message is the branch that shipped the vulnerability: it
        // lives in a custom field property Gravity Forms does not sanitize on save.
        await helpers.setEmailRules(request, { enabled: true, rules: [] });

        await openEmailFieldAdvanced(page, formId);
        await addFieldRule(page, {
            type: 'domain',
            value: 'fieldxss.test',
            action: 'block',
        });
        await page
            .locator('[data-role="field-message"]')
            .fill('<img src=x onerror="window.__zsFieldXss=1">Nope <strong>bold</strong>');
        await saveForm(page);

        await page.goto(pageUrl);
        await fillAndSubmit(page, formId, {
            input_1: 'Mallory',
            input_2: 'mallory@fieldxss.test',
        });

        await expect(
            page.locator(`#gform_${formId}_validation_container`),
            'the field rule must block so the message actually renders'
        ).toBeVisible();
        await expect(
            page.locator(`#gform_${formId} [onerror]`),
            'event handlers must be stripped from the field message'
        ).toHaveCount(0);
        expect(
            await page.evaluate(() => window.__zsFieldXss),
            'the payload must not execute'
        ).toBeUndefined();
        await expect(
            page.locator(`#gform_${formId} strong`).filter({ hasText: 'bold' })
        ).toBeVisible();
    });
});
