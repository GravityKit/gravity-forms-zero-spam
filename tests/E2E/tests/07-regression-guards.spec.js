/**
 * Zero Spam — regression guards (HP-20, HP-21).
 *
 * HP-20 protects against the 1.7.3 fix: "Submissions from pages with multiple
 *   Gravity Forms were incorrectly marked as spam when the form wasn't the
 *   first one on the page". The fix moved gfZeroSpamConfig assembly from the
 *   per-form footer hook to a script_loader_tag injection that runs after
 *   every form on the page has been registered.
 *
 * HP-21 protects against the 1.7.2 fix: "Forms with conditional logic could
 *   be invisible to visitors". Conditional logic interacts with GF's render
 *   hiding logic; ZS must not break that path.
 */

const { test, expect } = require('@wordpress/e2e-test-utils-playwright');
const helpers = require('../helpers');

async function makeFormViaApi(testIdSuffix, fields) {
    const testId = helpers.generateTestId() + testIdSuffix;
    const data = await helpers.api.setup({
        test_id: testId,
        form: { title: `Regression ${testIdSuffix}`, fields },
        entries: [],
        skip_view: true,
    });
    if (data.error) throw new Error(`Form creation failed: ${data.error}`);
    return { testId, formId: data.form_id, formTitle: data.form_title };
}

test.describe('Zero Spam — regression guards', () => {
    test('HP-20: two Zero-Spam-protected forms on one page each submit cleanly', async ({
        browser,
        request,
    }) => {
        const a = await makeFormViaApi('-a', [
            { id: 1, type: 'text', label: 'Name', isRequired: true },
            { id: 2, type: 'email', label: 'Email', isRequired: true },
        ]);
        const b = await makeFormViaApi('-b', [
            { id: 1, type: 'text', label: 'Name', isRequired: true },
            { id: 2, type: 'email', label: 'Email', isRequired: true },
        ]);

        await helpers.setFormZeroSpam(request, a.formId, true);
        await helpers.setFormZeroSpam(request, b.formId, true);

        const slug = `zs-multi-${Date.now()}`;
        const created = await helpers.createPage(request, {
            title: `ZS Multi ${slug}`,
            content:
                `[gravityform id="${a.formId}" title="false" description="false" ajax="false"]` +
                `\n\n[gravityform id="${b.formId}" title="false" description="false" ajax="false"]`,
            slug,
        });

        try {
            // Submit each form via the real-user UI path (token auto-injected
            // by GF's pre-submission filter). Use a fresh anonymous context
            // so admin bypass doesn't mask real behaviour.
            const ctx = await browser.newContext({
                storageState: { cookies: [], origins: [] },
            });
            const page = await ctx.newPage();

            try {
                // Both forms must render their config block; the 1.7.3 bug was
                // that the second form's entry in gfZeroSpamConfig.forms was
                // missing, so its submission lacked a fallback token.
                await page.goto(created.permalink);
                const configFormIds = await page.evaluate(() =>
                    (window.gfZeroSpamConfig?.forms || []).map((f) => f.formId)
                );
                expect(configFormIds, 'gfZeroSpamConfig must list both forms').toEqual(
                    expect.arrayContaining([a.formId, b.formId])
                );

                // Submit form A.
                await page.locator(`#gform_${a.formId} input[name="input_1"]`).fill('Anna');
                await page
                    .locator(`#gform_${a.formId} input[name="input_2"]`)
                    .fill(`anna-${a.testId}@gravitykit.test`);
                await page.locator(`#gform_submit_button_${a.formId}`).click();
                await expect(
                    page.locator(`#gform_confirmation_wrapper_${a.formId}`)
                ).toContainText('Thanks for contacting us!');

                // Reload because both forms re-render after the first submit.
                await page.goto(created.permalink);

                await page.locator(`#gform_${b.formId} input[name="input_1"]`).fill('Ben');
                await page
                    .locator(`#gform_${b.formId} input[name="input_2"]`)
                    .fill(`ben-${b.testId}@gravitykit.test`);
                await page.locator(`#gform_submit_button_${b.formId}`).click();
                await expect(
                    page.locator(`#gform_confirmation_wrapper_${b.formId}`)
                ).toContainText('Thanks for contacting us!');
            } finally {
                await ctx.close();
            }

            const aActive = await helpers.getEntries(request, a.formId, 'active');
            const aMatch = aActive.filter(
                (entry) => entry['2'] === `anna-${a.testId}@gravitykit.test`
            );
            expect(aMatch, 'form A submission must be an active entry').toHaveLength(1);

            const aSpam = await helpers.getEntries(request, a.formId, 'spam');
            expect(
                aSpam.filter((entry) => entry['2'] === `anna-${a.testId}@gravitykit.test`),
                'form A submission must NOT land in spam'
            ).toHaveLength(0);

            const bActive = await helpers.getEntries(request, b.formId, 'active');
            const bMatch = bActive.filter(
                (entry) => entry['2'] === `ben-${b.testId}@gravitykit.test`
            );
            expect(bMatch, 'form B submission must be an active entry').toHaveLength(1);

            const bSpam = await helpers.getEntries(request, b.formId, 'spam');
            expect(
                bSpam.filter((entry) => entry['2'] === `ben-${b.testId}@gravitykit.test`),
                'form B submission must NOT land in spam'
            ).toHaveLength(0);
        } finally {
            await helpers.cleanup(a.testId);
            await helpers.cleanup(b.testId);
            await helpers.cleanupPages(request, [created.page_id]);
        }
    });

    test('HP-21: form with conditional logic renders visible and submits to an active entry', async ({
        browser,
        request,
    }) => {
        // Three fields:
        //   1: "Show extra" checkbox  (controls field 3 visibility)
        //   2: Email  (always visible, required)
        //   3: Extra  (conditionally visible — shown when the checkbox is checked)
        const testId = helpers.generateTestId();
        const data = await helpers.api.setup({
            test_id: testId,
            form: {
                title: 'Conditional Form',
                fields: [
                    {
                        id: 1,
                        type: 'checkbox',
                        label: 'Show extra',
                        choices: [{ text: 'Show extra', value: 'yes', isSelected: false }],
                        inputs: [{ id: '1.1', label: 'Show extra' }],
                    },
                    {
                        id: 2,
                        type: 'email',
                        label: 'Email',
                        isRequired: true,
                    },
                    {
                        id: 3,
                        type: 'text',
                        label: 'Extra',
                        conditionalLogic: {
                            actionType: 'show',
                            logicType: 'all',
                            rules: [{ fieldId: '1.1', operator: 'is', value: 'yes' }],
                        },
                    },
                ],
            },
            entries: [],
            skip_view: true,
        });
        if (data.error) throw new Error(`Form creation failed: ${data.error}`);
        const formId = data.form_id;

        await helpers.setFormZeroSpam(request, formId, true);

        const created = await helpers.createPage(request, {
            title: `ZS Conditional ${testId}`,
            content: `[gravityform id="${formId}" title="false" description="false" ajax="false"]`,
            slug: `zs-cond-${testId}`,
        });

        try {
            const ctx = await browser.newContext({
                storageState: { cookies: [], origins: [] },
            });
            const page = await ctx.newPage();

            try {
                await page.goto(created.permalink);

                // Form must be visibly rendered (the 1.7.2 bug hid it entirely).
                await expect(page.locator(`#gform_${formId}`)).toBeVisible();
                await expect(page.locator(`#gform_${formId} input[name="input_2"]`)).toBeVisible();
                await expect(page.locator(`#gform_${formId} input[name="gform_submit"]`)).toBeAttached();

                // Submit without triggering the conditional branch.
                await page
                    .locator(`#gform_${formId} input[name="input_2"]`)
                    .fill(`cond-${testId}@gravitykit.test`);
                await page.locator(`#gform_submit_button_${formId}`).click();

                await expect(
                    page.locator(`#gform_confirmation_wrapper_${formId}`)
                ).toContainText('Thanks for contacting us!');
            } finally {
                await ctx.close();
            }

            const active = await helpers.getEntries(request, formId, 'active');
            const ours = active.filter(
                (entry) => entry['2'] === `cond-${testId}@gravitykit.test`
            );
            expect(ours, 'conditional-logic form submission must be an active entry').toHaveLength(1);
        } finally {
            await helpers.cleanup(testId);
            await helpers.cleanupPages(request, [created.page_id]);
        }
    });
});
