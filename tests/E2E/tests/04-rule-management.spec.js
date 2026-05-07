/**
 * Zero Spam — email rejection rule management (HP-11, HP-12, HP-13).
 *
 * Verified live with Playwright MCP:
 *   - Import flow:
 *       Toggle:   button[data-import-toggle]   ("+ Import Rules" / "− Import Rules")
 *       Textarea: .gf-zero-spam-import textarea.gf-zero-spam-input
 *       Submit:   .gf-zero-spam-import button[data-action="import"]
 *   - Rule rows:  tr.gf-zero-spam-rule-row[data-rule-id]
 *       Type:    .gf-zero-spam-type-label
 *       Value:   code.gf-zero-spam-value
 *       Action:  .gf-zero-spam-action-badge.action-{block|flag|log}
 *       Disable: button[data-action="toggle"]
 *       Remove:  button[data-action="remove"]
 *   - Hidden JSON: input[name="_gform_setting_gf_zero_spam_email_rules"]
 *
 * Per-field overrides are stored on the GF field object as `field.emailRejection`:
 *   { enabled: bool, mode: 'replace'|'extend', rules: [...], message: '' }
 * The e2e-fixtures /update-form-field endpoint writes these directly.
 */

const { test, expect } = require('@wordpress/e2e-test-utils-playwright');
const helpers = require('../helpers');

const SETTINGS_URL = '/wp-admin/admin.php?page=gf_settings&subview=gf-zero-spam';
const SAVE_BUTTON = '#gform-settings-save';
const SUCCESS_NOTICE = '.alert.gforms_note_success';
const RULE_ROW = 'tr.gf-zero-spam-rule-row';

async function createEmailForm({ extraEmailField = false } = {}) {
    const testId = helpers.generateTestId();
    const fields = [
        { id: 1, type: 'text', label: 'First Name', isRequired: true },
        { id: 2, type: 'email', label: 'Email A', isRequired: true },
    ];
    if (extraEmailField) {
        fields.push({ id: 3, type: 'email', label: 'Email B', isRequired: true });
    }
    const data = await helpers.api.setup({
        test_id: testId,
        form: { title: 'Rule Mgmt Form', fields },
        entries: [],
        skip_view: true,
    });
    if (data.error) throw new Error(`Form creation failed: ${data.error}`);
    return { testId, formId: data.form_id };
}

async function fillAndSubmit(page, formId, values) {
    for (const [fieldId, value] of Object.entries(values)) {
        await page.locator(`#gform_${formId} input[name="input_${fieldId}"]`).fill(value);
    }
    await page.locator(`#gform_submit_button_${formId}`).click();
}

// All tests in this file mutate global Zero Spam plugin settings; suite is
// already serial via playwright.config.js, but we keep the describe scope
// explicit for readability.
test.describe('Zero Spam — rule management', () => {
    let testId;
    let formId;
    let pageId;
    let pageUrl;

    test.beforeEach(async ({ request }) => {
        ({ testId, formId } = await createEmailForm());
        await helpers.setFormZeroSpam(request, formId, true);

        const created = await helpers.createPage(request, {
            title: `ZS Rules ${testId}`,
            content: `[gravityform id="${formId}" title="false" description="false" ajax="false"]`,
            slug: `zs-rules-${testId}`,
        });
        pageId = created.page_id;
        pageUrl = created.permalink;

        // Make sure the email-rejection feature is on but rules are empty
        // before each test sets up its own scenario.
        await helpers.setEmailRules(request, {
            enabled: true,
            rules: [],
            message: '',
        });
    });

    test.afterEach(async ({ request }) => {
        await helpers.cleanup(testId);
        await helpers.cleanupPages(request, [pageId]);
        await helpers.setEmailRules(request, {
            enabled: false,
            rules: [],
            message: '',
        });
    });

    test('HP-11: bulk import creates one rule per pasted line, persisted on save', async ({
        page,
        request,
    }) => {
        await page.goto(SETTINGS_URL);

        // Open the import panel.
        await page.locator('button[data-import-toggle]').click();

        const textarea = page.locator(
            '.gf-zero-spam-import textarea.gf-zero-spam-input'
        );
        await expect(textarea).toBeVisible();

        const lines = ['badone.test', 'badtwo.test', 'badthree.test'];
        await textarea.fill(lines.join('\n'));

        await page.locator('.gf-zero-spam-import button[data-action="import"]').click();

        // 3 rows visible on the page (still pre-save).
        await expect(page.locator(RULE_ROW)).toHaveCount(3);

        await page.locator(SAVE_BUTTON).click();
        await expect(page.locator(SUCCESS_NOTICE)).toContainText('Settings updated.');

        // After save and re-render the rules persist with the right values.
        await expect(page.locator(RULE_ROW)).toHaveCount(3);
        for (const value of lines) {
            await expect(page.locator(`code.gf-zero-spam-value:has-text("${value}")`)).toBeVisible();
        }

        // Behavioral check: settings option contains the imported rules.
        const stored = await helpers.setEmailRules(request, {});
        const importedValues = stored.rules.map((rule) => rule.value).sort();
        expect(importedValues).toEqual(lines.slice().sort());
        for (const rule of stored.rules) {
            expect(rule.type).toBe('domain');
            expect(rule.action).toBe('block');
            expect(rule.enabled).toBe(true);
        }
    });

    test('HP-12: disabling a rule via the UI stops enforcement on a matching submission', async ({
        page,
        request,
    }) => {
        // Seed a flag rule. The UI's toggle handler keys off rule.id, so we
        // mimic the format the rule builder assigns to UI-created rules
        // (Date.now base36 + 5 chars of random base36) so clicking "Disable"
        // can find this row.
        await helpers.setEmailRules(request, {
            enabled: true,
            rules: [
                {
                    id: 'seeded12345',
                    type: 'domain',
                    value: 'flagme.test',
                    action: 'flag',
                    enabled: true,
                },
            ],
        });

        // Sanity precondition: with the rule enabled, the submission would be
        // flagged. We assert the disable path below; this assertion documents
        // the baseline so a future change to default rule semantics is caught.
        const baseline = await helpers.setEmailRules(request, {});
        expect(baseline.rules).toHaveLength(1);
        expect(baseline.rules[0].enabled).toBe(true);

        await page.goto(SETTINGS_URL);

        const row = page.locator(RULE_ROW).filter({
            has: page.locator('code.gf-zero-spam-value:has-text("flagme.test")'),
        });
        await expect(row).toHaveCount(1);

        await row.locator('button[data-action="toggle"]').click();
        await page.locator(SAVE_BUTTON).click();
        await expect(page.locator(SUCCESS_NOTICE)).toContainText('Settings updated.');

        const persisted = await helpers.setEmailRules(request, {});
        expect(persisted.rules).toHaveLength(1);
        expect(persisted.rules[0].enabled).toBe(false);

        // Submit a matching email; entry must NOT be marked as spam.
        await page.goto(pageUrl);
        await fillAndSubmit(page, formId, {
            1: 'Mallory',
            2: `mallory+${testId}@flagme.test`,
        });

        await expect(
            page.locator(`#gform_confirmation_wrapper_${formId}`)
        ).toContainText('Thanks for contacting us!');

        const spam = await helpers.getEntries(request, formId, 'spam');
        expect(
            spam.filter((entry) => entry['2'] === `mallory+${testId}@flagme.test`),
            'disabled rule must NOT flag the submission as spam'
        ).toHaveLength(0);

        const active = await helpers.getEntries(request, formId, 'active');
        expect(
            active.filter((entry) => entry['2'] === `mallory+${testId}@flagme.test`),
            'submission with disabled rule should land in active entries'
        ).toHaveLength(1);
    });

    test('HP-13: per-field rule override is enforced independently of the global rule', async ({
        page,
        request,
    }) => {
        // Custom form with two email fields so we can compare per-field vs global.
        const { testId: localTestId, formId: localFormId } = await createEmailForm({
            extraEmailField: true,
        });
        await helpers.setFormZeroSpam(request, localFormId, true);

        const created = await helpers.createPage(request, {
            title: `ZS Per-Field ${localTestId}`,
            content: `[gravityform id="${localFormId}" title="false" description="false" ajax="false"]`,
            slug: `zs-perfield-${localTestId}`,
        });

        try {
            // Global rule: flag emails ending in @globalbad.test.
            await helpers.setEmailRules(request, {
                enabled: true,
                rules: [
                    {
                        type: 'domain',
                        value: 'globalbad.test',
                        action: 'flag',
                        enabled: true,
                    },
                ],
            });

            // Field A (id=2) gets a REPLACE override that flags @fieldabad.test
            // and ignores the global rule entirely.
            await helpers.api.updateFormField(localFormId, 2, {
                emailRejection: {
                    enabled: true,
                    mode: 'replace',
                    message: '',
                    rules: [
                        {
                            type: 'domain',
                            value: 'fieldabad.test',
                            action: 'flag',
                            enabled: true,
                        },
                    ],
                },
            });

            // Field B (id=3) has no override, so global rule applies.

            // Case 1: Field A=bad-by-field-rule, Field B=clean → spam (field A flagged).
            await page.goto(created.permalink);
            await fillAndSubmit(page, localFormId, {
                1: 'CaseA',
                2: `casea+${localTestId}@fieldabad.test`,
                3: `clean+${localTestId}@neutral.test`,
            });
            await expect(
                page.locator(`#gform_confirmation_wrapper_${localFormId}`)
            ).toContainText('Thanks for contacting us!');

            // Case 2: Field A=clean, Field B=bad-by-global → spam (global flagged on field B).
            await page.goto(created.permalink);
            await fillAndSubmit(page, localFormId, {
                1: 'CaseB',
                2: `caseb+${localTestId}@neutral.test`,
                3: `caseb+${localTestId}@globalbad.test`,
            });
            await expect(
                page.locator(`#gform_confirmation_wrapper_${localFormId}`)
            ).toContainText('Thanks for contacting us!');

            // Case 3: Field A=clean, Field B=clean → active (neither rule fires).
            await page.goto(created.permalink);
            await fillAndSubmit(page, localFormId, {
                1: 'CaseC',
                2: `casec+${localTestId}@neutral.test`,
                3: `casec+${localTestId}@still-neutral.test`,
            });
            await expect(
                page.locator(`#gform_confirmation_wrapper_${localFormId}`)
            ).toContainText('Thanks for contacting us!');

            // Case 4: Field A's REPLACE means the global rule does NOT apply
            // through field A. So @globalbad.test in field A should NOT flag.
            await page.goto(created.permalink);
            await fillAndSubmit(page, localFormId, {
                1: 'CaseD',
                2: `cased+${localTestId}@globalbad.test`,
                3: `cased+${localTestId}@still-neutral.test`,
            });
            await expect(
                page.locator(`#gform_confirmation_wrapper_${localFormId}`)
            ).toContainText('Thanks for contacting us!');

            const spam = await helpers.getEntries(request, localFormId, 'spam');
            const active = await helpers.getEntries(request, localFormId, 'active');

            const findIn = (list, identifier) =>
                list.find((entry) => Object.values(entry).some((v) => typeof v === 'string' && v.includes(identifier)));

            expect(findIn(spam, 'casea'), 'field A rule must flag the submission').toBeTruthy();
            expect(findIn(spam, 'caseb'), 'global rule must flag field B').toBeTruthy();
            expect(findIn(active, 'casec'), 'no rule matches → active entry').toBeTruthy();
            expect(findIn(active, 'cased'), 'replace mode hides global rule from field A → active').toBeTruthy();
        } finally {
            await helpers.cleanup(localTestId);
            await helpers.cleanupPages(request, [created.page_id]);
        }
    });
});
