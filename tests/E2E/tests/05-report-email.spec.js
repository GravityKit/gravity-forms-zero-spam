/**
 * Zero Spam — spam report email (HP-14, HP-15, HP-16).
 *
 * Verified live with Playwright MCP:
 *   - Settings page: Forms → Settings → Zero Spam (subview=gf-zero-spam).
 *   - Frequency radios:  #gf_zero_spam_email_frequency{0..5}
 *       0=Disabled, 1=Threshold-Based, 2=Twice Daily, 3=Daily, 4=Weekly, 5=Monthly.
 *   - Recipient input:   #gf_zero_spam_report_email
 *   - Subject input:     #gf_zero_spam_subject
 *   - Body textarea:     #_gform_setting_gf_zero_spam_message
 *   - Test send button:  #gf_zero_spam_test_email_button (sets a hidden flag
 *                         and triggers Save Settings; send_report runs in
 *                         is_test mode so REPORT_LAST_SENT_DATE_OPTION isn't
 *                         updated).
 *   - Outgoing mail is captured by tests/E2E/mu-plugins/zs-e2e-mailcatch.php
 *     (pre_wp_mail short-circuit). Read via /zs-e2e/v1/mail.
 *   - The cron hook name is `gf_zero_spam_send_report`. We trigger it
 *     synchronously via /zs-e2e/v1/cron-run-report so HP-16 doesn't have to
 *     wait on wp-cron.
 */

const { test, expect } = require('@wordpress/e2e-test-utils-playwright');
const helpers = require('../helpers');

const SETTINGS_URL = '/wp-admin/admin.php?page=gf_settings&subview=gf-zero-spam';
const SAVE_BUTTON = '#gform-settings-save';
const TEST_SEND_BUTTON = '#gf_zero_spam_test_email_button';
const SUCCESS_NOTICE = '.alert.gforms_note_success';

const FREQUENCY_DAILY = '#gf_zero_spam_email_frequency3';
const FREQUENCY_DISABLED = '#gf_zero_spam_email_frequency0';

const RECIPIENT_INPUT = '#gf_zero_spam_report_email';
const SUBJECT_INPUT = '#gf_zero_spam_subject';
const BODY_TEXTAREA = '#_gform_setting_gf_zero_spam_message';

test.describe('Zero Spam — spam report email', () => {
    test.afterEach(async ({ request }) => {
        // Roll back to a known clean state: no schedule, no captured mail,
        // default settings cleared.
        await helpers.resetZeroSpam(request);
        await helpers.clearCapturedMail(request);
    });

    test('HP-14: saving Daily frequency persists the setting and schedules the cron hook', async ({
        page,
        request,
    }) => {
        await page.goto(SETTINGS_URL);

        // Set Daily.
        await page.locator(FREQUENCY_DAILY).check();

        // Use a deterministic recipient instead of the {{admin_email}} default
        // so the persisted value is something we can assert on directly.
        await page.locator(RECIPIENT_INPUT).fill('reports@gravitykit.test');
        await page.locator(SUBJECT_INPUT).fill('HP-14 daily report');

        await page.locator(SAVE_BUTTON).click();
        await expect(page.locator(SUCCESS_NOTICE)).toContainText('Settings updated.');

        // Settings persist on reload.
        await page.reload();
        await expect(page.locator(FREQUENCY_DAILY)).toBeChecked();
        await expect(page.locator(RECIPIENT_INPUT)).toHaveValue('reports@gravitykit.test');
        await expect(page.locator(SUBJECT_INPUT)).toHaveValue('HP-14 daily report');

        // The frequency save_callback wires up wp-cron; the hook should be scheduled.
        const scheduled = await helpers.getScheduledCron(request, 'gf_zero_spam_send_report');
        expect(scheduled.scheduled, 'cron hook gf_zero_spam_send_report must be scheduled').toBe(true);
    });

    test('HP-15: clicking "Send Email & Save Settings" sends a test email with merge tags rendered', async ({
        page,
        request,
    }) => {
        await page.goto(SETTINGS_URL);

        // Pick an explicit frequency so we know the saved state is meaningful.
        await page.locator(FREQUENCY_DAILY).check();
        await page.locator(RECIPIENT_INPUT).fill('hp15@gravitykit.test');
        await page.locator(SUBJECT_INPUT).fill('HP-15 for {{site_name}}');

        await helpers.clearCapturedMail(request);

        await page.locator(TEST_SEND_BUTTON).click();
        await expect(page.locator(SUCCESS_NOTICE)).toContainText('Settings updated.');

        const mail = await helpers.getCapturedMail(request);
        expect(mail.length, 'test send must produce one captured email').toBe(1);

        const sent = mail[0];
        expect(sent.to).toEqual(['hp15@gravitykit.test']);
        // {{site_name}} is replaced by the site title — we don't pin it to a
        // specific value because it depends on wp-env defaults; we just
        // assert the placeholder doesn't survive.
        expect(sent.subject).toMatch(/^HP-15 for .+/);
        expect(sent.subject).not.toContain('{{site_name}}');

        // Body has the default template; merge tags must be rendered.
        expect(sent.message).toContain('Gravity Forms Spam Report');
        expect(sent.message).not.toContain('{{total_spam_count}}');
        expect(sent.message).not.toContain('{{spam_report_list}}');
        expect(sent.message).not.toContain('{{settings_link}}');
        expect(sent.message).not.toContain('{{/settings_link}}');
        // The settings_link merge tag becomes a real anchor pointing back at us.
        expect(sent.message).toMatch(
            /<a href="[^"]*page=gf_settings[^"]*subview=gf-zero-spam[^"]*">[\s\S]*<\/a>/
        );

    });

    test('HP-16: the cron handler emails a spam summary listing forms with new spam entries', async ({
        browser,
        request,
    }) => {
        const testId = helpers.generateTestId();
        const formData = await helpers.api.setup({
            test_id: testId,
            form: {
                title: 'Report Form',
                fields: [
                    { id: 1, type: 'text', label: 'Name', isRequired: true },
                    { id: 2, type: 'email', label: 'Email', isRequired: true },
                ],
            },
            entries: [],
            skip_view: true,
        });
        if (formData.error) throw new Error(`Form creation failed: ${formData.error}`);
        const formId = formData.form_id;

        await helpers.setFormZeroSpam(request, formId, true);

        // The cron handler reads recipient/subject/body straight off the
        // saved option. Without a recipient, send_report() bails on
        // is_email() and no mail is captured.
        await helpers.setReportConfig(request, {
            frequency: 'daily',
            recipient: 'hp16@gravitykit.test',
            subject: 'HP-16 cron summary for {{site_name}}',
            body:
                '<h2>Spam Report</h2>\n' +
                'Total: {{total_spam_count}}\n' +
                '{{spam_report_list}}\n' +
                '{{settings_link}}settings{{/settings_link}}',
        });

        const created = await helpers.createPage(request, {
            title: `ZS Report Seed ${testId}`,
            content: `[gravityform id="${formId}" title="false" description="false" ajax="false"]`,
            slug: `zs-report-${testId}`,
        });

        try {
            // Seed a spam entry via a tokenless POST. Admin bypass means we
            // MUST submit from an anonymous context, otherwise check_key_field
            // returns false and the entry lands as active.
            const anonContext = await browser.newContext({
                storageState: { cookies: [], origins: [] },
            });
            const anonPage = await anonContext.newPage();

            try {
                await anonPage.goto(created.permalink);
                await anonPage.evaluate(
                    async (id) => {
                        const form = document.getElementById('gform_' + id);
                        const fd = new FormData(form);
                        fd.set('input_1', 'SpamSeeder');
                        fd.set('input_2', `seed+${id}@gravitykit.test`);
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
                await anonContext.close();
            }

            const spam = await helpers.getEntries(request, formId, 'spam');
            const seeded = spam.filter(
                (entry) =>
                    typeof entry['2'] === 'string' && entry['2'].startsWith('seed+')
            );
            expect(seeded, 'spam entry must exist before triggering the cron').toHaveLength(1);

            // Capture only what the cron generates.
            await helpers.clearCapturedMail(request);

            await helpers.runReportCron(request);

            const mail = await helpers.getCapturedMail(request);
            expect(mail.length, 'cron must produce a single summary email').toBe(1);

            const summary = mail[0];
            expect(summary.message).toContain(formData.form_title);
            // The summary links to the entry list filtered by spam status.
            expect(summary.message).toMatch(
                new RegExp(`page=gf_entries[^"]*id=${formId}[^"]*filter=spam`)
            );
            // No unrendered merge tags should leak through.
            expect(summary.message).not.toMatch(/\{\{[^}]+\}\}/);
        } finally {
            await helpers.cleanup(testId);
            await helpers.cleanupPages(request, [created.page_id]);
        }
    });
});
