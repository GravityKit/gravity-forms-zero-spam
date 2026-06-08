/**
 * Zero Spam — AI spam review (HP-20 through HP-47).
 *
 * Uses the E2E-only `gf_zero_spam_ai_verdict` short-circuit seam so the tests
 * never need a configured connector or real API spend.
 */

const { test, expect } = require('@wordpress/e2e-test-utils-playwright');
const helpers = require('../helpers');

test.describe.configure({ mode: 'serial' });

const FORM_SETTINGS_SAVE_BUTTON = '#gform-settings-save';
const SUCCESS_NOTICE = '.alert.gforms_note_success';

function formSettingsUrl(formId) {
    return `/wp-admin/admin.php?page=gf_edit_forms&view=settings&id=${formId}`;
}

async function createAiReviewForm() {
    const testId = helpers.generateTestId();
    const data = await helpers.api.setup({
        test_id: testId,
        form: {
            title: 'AI Review Form',
            fields: [
                { id: 1, type: 'text', label: 'Name', isRequired: true },
                { id: 2, type: 'email', label: 'Email', isRequired: true },
                { id: 3, type: 'textarea', label: 'Message', isRequired: true },
            ],
        },
        entries: [],
        skip_view: true,
    });

    if (data.error) {
        throw new Error(`Form creation failed: ${data.error}`);
    }

    return { testId, formId: data.form_id };
}

async function createSerializationForm() {
    const testId = helpers.generateTestId();
    const data = await helpers.api.setup({
        test_id: testId,
        form: {
            title: 'AI Review Serialization Form',
            fields: [
                { id: 1, type: 'text', label: 'Visible Text', isRequired: true },
                {
                    id: 2,
                    type: 'name',
                    label: 'Name',
                    nameFormat: 'advanced',
                    inputs: [
                        { id: '2.2', label: 'Prefix', name: '', isHidden: true, inputType: 'radio' },
                        { id: '2.3', label: 'First', name: '' },
                        { id: '2.4', label: 'Middle', name: '', isHidden: true },
                        { id: '2.6', label: 'Last', name: '' },
                        { id: '2.8', label: 'Suffix', name: '', isHidden: true },
                    ],
                },
                {
                    id: 3,
                    type: 'text',
                    label: 'Administrative Sentinel',
                    visibility: 'administrative',
                },
            ],
        },
        entries: [],
        skip_view: true,
    });

    if (data.error) {
        throw new Error(`Form creation failed: ${data.error}`);
    }

    await helpers.updateFormField(data.form_id, 3, { visibility: 'administrative' });

    return { testId, formId: data.form_id };
}

async function postFields(page, request, pageUrl, formId, fields, tokenMode = 'valid') {
    const token =
        tokenMode === 'valid' ? await helpers.mintToken(request, formId, 600) : tokenMode;

    await page.goto(pageUrl);

    return page.evaluate(
        async ({ id, submittedFields, submittedToken }) => {
            const form = document.getElementById('gform_' + id);
            if (!form) {
                return { error: 'no_form' };
            }

            const fd = new FormData(form);

            Object.entries(submittedFields).forEach(([name, value]) => {
                fd.set(name, value);
            });

            fd.delete('gf_zero_spam_key');

            if (submittedToken === null) {
                fd.delete('gf_zero_spam_token');
            } else {
                fd.set('gf_zero_spam_token', submittedToken);
            }

            const res = await fetch(form.action || location.href, {
                method: 'POST',
                body: fd,
                redirect: 'follow',
            });

            return { status: res.status };
        },
        { id: formId, submittedFields: fields, submittedToken: token }
    );
}

async function postSubmission(page, request, pageUrl, formId, values, tokenMode = 'valid') {
    return postFields(
        page,
        request,
        pageUrl,
        formId,
        {
            input_1: values.name,
            input_2: values.email,
            input_3: values.message,
        },
        tokenMode
    );
}

function findByEmail(entries, email) {
    return entries.filter((entry) => entry['2'] === email);
}

function findBySubject(messages, subject) {
    return messages.filter((message) => message.subject === subject);
}

async function configureSyncRescue(request, formId, subject, message) {
	await helpers.clearCapturedMail(request);
    await helpers.setFormAiReview(request, formId, {
        rescueEnabled: true,
        maxCallsPerHour: 0,
    });
    await helpers.setFormNotification(request, formId, {
        subject,
        message,
    });
}

test.describe('Zero Spam — AI spam review', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    let formId;
    let pageId;
    let pageUrl;
    let testId;

    test.beforeEach(async ({ request }) => {
        ({ testId, formId } = await createAiReviewForm());

        await helpers.setFormZeroSpam(request, formId, true);
        await helpers.setFormAiReview(request, formId, {
            enabled: true,
            maxCallsPerHour: 0,
        });
        await helpers.setAiReview(request, {
            global_enabled: true,
            mode: 'none',
            threshold: 0.9,
        });

        const created = await helpers.createPage(request, {
            title: `ZS AI Review ${testId}`,
            content: `[gravityform id="${formId}" title="false" description="false" ajax="false"]`,
            slug: `zs-ai-${testId}`,
        });
        pageId = created.page_id;
        pageUrl = created.permalink;
    });

    test.afterEach(async ({ request }) => {
        await helpers.resetAiReview(request);
        await helpers.cleanup(testId);

        if (pageId) {
            await helpers.cleanupPages(request, [pageId]);
        }
    });

    test('HP-20: spam verdict marks a token-cleared submission as spam with Zero Spam (AI)', async ({
        page,
        request,
    }) => {
        const email = `ai-spam+${testId}@example.test`;
        const reason = 'E2E AI spam verdict';

        await helpers.setAiReview(request, {
            global_enabled: true,
            mode: 'verdict',
            verdict: {
                is_spam: true,
                confidence: 0.95,
                reason,
            },
        });

        const result = await postSubmission(page, request, pageUrl, formId, {
            name: 'Promo Bot',
            email,
            message: 'Buy traffic now at http://spam.example.test',
        });
        expect(result.status).toBe(200);

        const spam = await helpers.getEntries(request, formId, 'spam');
        const ours = findByEmail(spam, email);
        expect(ours, 'AI spam verdict must create a spam entry').toHaveLength(1);

        const notes = await helpers.getEntryNotes(request, ours[0].id);
        expect(
            notes.some((note) => note.user_name === 'Zero Spam (AI)'),
            'spam filter name must be Zero Spam (AI)'
        ).toBe(true);
        expect(
            notes.some((note) => note.value.includes(reason)),
            'spam reason must include the AI reason'
        ).toBe(true);

        const state = await helpers.getAiReview(request);
        expect(state.calls).toBe(1);
        expect(state.last_payload).toContain('*@example.test');
        expect(state.last_payload).not.toContain(email);
    });

    test('HP-30: per-form prompt overrides global and blank inherits global prompt', async ({
        page,
        request,
    }) => {
        const globalPrompt = `Global prompt ${testId}`;
        const perFormPrompt = `Per-form prompt ${testId}`;

        await helpers.setAiReview(request, {
            global_enabled: true,
            mode: 'verdict',
            default_prompt: globalPrompt,
            verdict: {
                is_spam: false,
                confidence: 0.01,
                reason: 'Prompt capture ham verdict',
            },
        });
        await helpers.setFormAiReview(request, formId, {
            prompt: perFormPrompt,
        });

        const firstResult = await postSubmission(page, request, pageUrl, formId, {
            name: 'Prompt Override',
            email: `ai-prompt-form+${testId}@example.test`,
            message: 'The per-form prompt should be resolved.',
        });
        expect(firstResult.status).toBe(200);

        let state = await helpers.getAiReview(request);
        expect(state.calls).toBe(1);
        expect(state.last_system_instruction).toBe(perFormPrompt);

        await helpers.setFormAiReview(request, formId, {
            prompt: '',
        });

        const secondResult = await postSubmission(page, request, pageUrl, formId, {
            name: 'Prompt Inherit',
            email: `ai-prompt-global+${testId}@example.test`,
            message: 'The blank per-form prompt should inherit global.',
        });
        expect(secondResult.status).toBe(200);

        state = await helpers.getAiReview(request);
        expect(state.calls).toBe(2);
        expect(state.last_system_instruction).toBe(globalPrompt);
    });

    test('HP-21: ham verdict leaves a token-cleared submission active', async ({
        page,
        request,
    }) => {
        const email = `ai-ham+${testId}@example.test`;

        await helpers.setAiReview(request, {
            global_enabled: true,
            mode: 'verdict',
            verdict: {
                is_spam: false,
                confidence: 0.05,
                reason: 'E2E ham verdict',
            },
        });

        const result = await postSubmission(page, request, pageUrl, formId, {
            name: 'Alice',
            email,
            message: 'I would like to ask a question about services.',
        });
        expect(result.status).toBe(200);

        const active = await helpers.getEntries(request, formId, 'active');
        expect(findByEmail(active, email), 'ham verdict must stay active').toHaveLength(1);

        const spam = await helpers.getEntries(request, formId, 'spam');
        expect(findByEmail(spam, email), 'ham verdict must not create spam').toHaveLength(0);

        const state = await helpers.getAiReview(request);
        expect(state.calls).toBe(1);
    });

    test('HP-22: missing token is already spam and does not call AI', async ({
        page,
        request,
    }) => {
        const email = `ai-tokenless+${testId}@example.test`;

        await helpers.setAiReview(request, {
            global_enabled: true,
            mode: 'verdict',
            verdict: {
                is_spam: true,
                confidence: 0.99,
                reason: 'Should not be used',
            },
        });

        const result = await postSubmission(
            page,
            request,
            pageUrl,
            formId,
            {
                name: 'Tokenless',
                email,
                message: 'No token submission.',
            },
            null
        );
        expect(result.status).toBe(200);

        const spam = await helpers.getEntries(request, formId, 'spam');
        expect(findByEmail(spam, email), 'tokenless submission must be token spam').toHaveLength(1);

        const state = await helpers.getAiReview(request);
        expect(state.calls, 'AI verdict filter must not run after token spam').toBe(0);
    });

    test('HP-22b: invalid token is already spam and does not call AI', async ({
        page,
        request,
    }) => {
        const email = `ai-invalid-token+${testId}@example.test`;

        await helpers.setAiReview(request, {
            global_enabled: true,
            mode: 'verdict',
            verdict: {
                is_spam: true,
                confidence: 0.99,
                reason: 'Should not be used',
            },
        });

        const result = await postSubmission(
            page,
            request,
            pageUrl,
            formId,
            {
                name: 'Bad Token',
                email,
                message: 'Invalid token submission.',
            },
            'not-a-valid-zero-spam-token'
        );
        expect(result.status).toBe(200);

        const spam = await helpers.getEntries(request, formId, 'spam');
        expect(findByEmail(spam, email), 'invalid token submission must be token spam').toHaveLength(1);

        const state = await helpers.getAiReview(request);
        expect(state.calls, 'AI verdict filter must not run after invalid token spam').toBe(0);
    });

    test('HP-39: rescue restores token spam when AI is confident it is legitimate', async ({
        page,
        request,
    }) => {
        const email = `ai-rescue-ham+${testId}@example.test`;
        const subject = `AI Rescue Ham ${testId}`;

        await configureSyncRescue(request, formId, subject, 'Rescued notification.');
        await helpers.setAiReview(request, {
            global_enabled: true,
            mode: 'verdict',
            rescueThreshold: 0.95,
            verdict: {
                is_spam: false,
                confidence: 0.99,
                reason: 'Legitimate token false positive',
            },
        });

        const result = await postSubmission(
            page,
            request,
            pageUrl,
            formId,
            {
                name: 'Rescued Ham',
                email,
                message: 'Legitimate submission with a bad token.',
            },
            'not-a-valid-zero-spam-token'
        );
        expect(result.status).toBe(200);

        const active = await helpers.getEntries(request, formId, 'active');
        const [entry] = findByEmail(active, email);
        expect(entry, 'confident ham verdict must restore the entry').toBeTruthy();

        const spam = await helpers.getEntries(request, formId, 'spam');
        expect(findByEmail(spam, email), 'rescued entry must not remain spam').toHaveLength(0);

        const mail = await helpers.getCapturedMail(request);
        expect(findBySubject(mail, subject), 'rescued entry must send notification').toHaveLength(1);

        const notes = await helpers.getEntryNotes(request, Number(entry.id));
        expect(notes.some((note) => note.user_name === 'Zero Spam (AI Rescue)')).toBe(true);
    });

    test('HP-48: result hook can veto a rescue verdict', async ({ page, request }) => {
        const email = `ai-rescue-result-veto+${testId}@example.test`;
        const subject = `AI Rescue Result Veto ${testId}`;

        await configureSyncRescue(request, formId, subject, 'Should not be sent.');
        await helpers.setAiReview(request, {
            global_enabled: true,
            mode: 'verdict',
            rescueThreshold: 0.95,
            forceRescueResult: 'spam',
            verdict: {
                is_spam: false,
                confidence: 0.99,
                reason: 'Legitimate unless result hook vetoes rescue',
            },
        });

        const result = await postSubmission(
            page,
            request,
            pageUrl,
            formId,
            {
                name: 'Rescue Result Veto',
                email,
                message: 'The verdict is ham, but the result hook vetoes rescue.',
            },
            'not-a-valid-zero-spam-token'
        );
        expect(result.status).toBe(200);

        const spam = await helpers.getEntries(request, formId, 'spam');
        expect(findByEmail(spam, email), 'result hook veto must keep the entry spam').toHaveLength(1);

        const active = await helpers.getEntries(request, formId, 'active');
        expect(findByEmail(active, email), 'vetoed rescue must not restore the entry').toHaveLength(0);

        const mail = await helpers.getCapturedMail(request);
        expect(findBySubject(mail, subject), 'vetoed rescue must not send notification').toHaveLength(0);

        const state = await helpers.getAiReview(request);
        expect(state.calls).toBe(1);
        expect(state.force_rescue_result).toBe('spam');
    });

    test('HP-40: rescue leaves token spam when AI says spam', async ({ page, request }) => {
        const email = `ai-rescue-spam+${testId}@example.test`;
        const subject = `AI Rescue Spam ${testId}`;

        await configureSyncRescue(request, formId, subject, 'Should not be sent.');
        await helpers.setAiReview(request, {
            global_enabled: true,
            mode: 'verdict',
            rescueThreshold: 0.95,
            verdict: {
                is_spam: true,
                confidence: 0.99,
                reason: 'Still spam',
            },
        });

        const result = await postSubmission(
            page,
            request,
            pageUrl,
            formId,
            {
                name: 'Rescue Spam',
                email,
                message: 'Spam with bad token.',
            },
            'not-a-valid-zero-spam-token'
        );
        expect(result.status).toBe(200);

        const spam = await helpers.getEntries(request, formId, 'spam');
        expect(findByEmail(spam, email), 'AI spam verdict must stay spam').toHaveLength(1);

        const mail = await helpers.getCapturedMail(request);
        expect(findBySubject(mail, subject), 'spam verdict must not send notification').toHaveLength(0);
    });

    test('HP-41: rescue leaves token spam when ham confidence is low', async ({
        page,
        request,
    }) => {
        const email = `ai-rescue-low-confidence+${testId}@example.test`;
        const subject = `AI Rescue Low Confidence ${testId}`;

        await configureSyncRescue(request, formId, subject, 'Should not be sent.');
        await helpers.setAiReview(request, {
            global_enabled: true,
            mode: 'verdict',
            rescueThreshold: 0.95,
            verdict: {
                is_spam: false,
                confidence: 0.5,
                reason: 'Uncertain ham',
            },
        });

        const result = await postSubmission(
            page,
            request,
            pageUrl,
            formId,
            {
                name: 'Low Confidence',
                email,
                message: 'Maybe legitimate but uncertain.',
            },
            'not-a-valid-zero-spam-token'
        );
        expect(result.status).toBe(200);

        const spam = await helpers.getEntries(request, formId, 'spam');
        expect(findByEmail(spam, email), 'low-confidence ham verdict must stay spam').toHaveLength(1);

        const mail = await helpers.getCapturedMail(request);
        expect(findBySubject(mail, subject), 'low confidence must not send notification').toHaveLength(0);
    });

    test('HP-42: rescue leaves token spam when AI fails', async ({ page, request }) => {
        const email = `ai-rescue-error+${testId}@example.test`;
        const subject = `AI Rescue Error ${testId}`;

        await configureSyncRescue(request, formId, subject, 'Should not be sent.');
        await helpers.setAiReview(request, {
            global_enabled: true,
            mode: 'error',
            rescueThreshold: 0.95,
            error_code: 'zs_e2e_rescue_error',
            error_message: 'E2E rescue failure',
        });

        const result = await postSubmission(
            page,
            request,
            pageUrl,
            formId,
            {
                name: 'Rescue Error',
                email,
                message: 'AI failure must fail closed.',
            },
            'not-a-valid-zero-spam-token'
        );
        expect(result.status).toBe(200);

        const spam = await helpers.getEntries(request, formId, 'spam');
        expect(findByEmail(spam, email), 'AI failure must stay spam').toHaveLength(1);

        const mail = await helpers.getCapturedMail(request);
        expect(findBySubject(mail, subject), 'AI failure must not send notification').toHaveLength(0);
    });

    test('HP-43: rescue disabled leaves token spam untouched', async ({ page, request }) => {
        const email = `ai-rescue-disabled+${testId}@example.test`;
        const subject = `AI Rescue Disabled ${testId}`;

        await helpers.clearCapturedMail(request);
        await helpers.setFormAiReview(request, formId, {
            rescueEnabled: false,
            maxCallsPerHour: 0,
        });
        await helpers.setFormNotification(request, formId, {
            subject,
            message: 'Should not be sent.',
        });
        await helpers.setAiReview(request, {
            global_enabled: true,
            mode: 'verdict',
            rescueThreshold: 0.95,
            verdict: {
                is_spam: false,
                confidence: 0.99,
                reason: 'Would rescue if enabled',
            },
        });

        const result = await postSubmission(
            page,
            request,
            pageUrl,
            formId,
            {
                name: 'Rescue Disabled',
                email,
                message: 'Rescue is off.',
            },
            'not-a-valid-zero-spam-token'
        );
        expect(result.status).toBe(200);

        const spam = await helpers.getEntries(request, formId, 'spam');
        expect(findByEmail(spam, email), 'disabled rescue must stay spam').toHaveLength(1);

        const state = await helpers.getAiReview(request);
        expect(state.calls, 'disabled rescue must not call the verdict seam').toBe(0);
    });

    test('HP-44: rescue does not run when another spam filter flags before the token check', async ({
        page,
        request,
    }) => {
        const email = `ai-rescue-other-source+${testId}@example.test`;
        const subject = `AI Rescue Other Source ${testId}`;

        await configureSyncRescue(request, formId, subject, 'Should not be sent.');
        await helpers.setAiReview(request, {
            global_enabled: true,
            mode: 'verdict',
            otherSpam: true,
            otherSpamFormId: formId,
            rescueThreshold: 0.95,
            verdict: {
                is_spam: false,
                confidence: 0.99,
                reason: 'Legitimate but from another spam source',
            },
        });

        const result = await postSubmission(
            page,
            request,
            pageUrl,
            formId,
            {
                name: 'Other Spam Source',
                email,
                message: 'Another spam filter flags before the token check.',
            },
            'not-a-valid-zero-spam-token'
        );
        expect(result.status).toBe(200);

        const spam = await helpers.getEntries(request, formId, 'spam');
        expect(findByEmail(spam, email), 'pre-token spam must stay spam').toHaveLength(1);

        const mail = await helpers.getCapturedMail(request);
        expect(findBySubject(mail, subject), 'other-source spam must not send notification').toHaveLength(0);

        const state = await helpers.getAiReview(request);
        expect(state.calls, 'rescue must not call AI for other spam sources').toBe(0);
    });

    test('HP-45: rescue does not override email-rejection flag rules', async ({
        page,
        request,
    }) => {
        const email = `ai-rescue-email-rule+${testId}@example.test`;
        const subject = `AI Rescue Email Rule ${testId}`;

        try {
            await configureSyncRescue(request, formId, subject, 'Should not be sent.');
            await helpers.setEmailRules(request, {
                enabled: true,
                rules: [
                    {
                        type: 'email',
                        value: email,
                        action: 'flag',
                        enabled: true,
                    },
                ],
            });
            await helpers.setAiReview(request, {
                global_enabled: true,
                mode: 'verdict',
                rescueThreshold: 0.95,
                verdict: {
                    is_spam: false,
                    confidence: 0.99,
                    reason: 'Legitimate but blocked by email rejection',
                },
            });

            const result = await postSubmission(
                page,
                request,
                pageUrl,
                formId,
                {
                    name: 'Email Rule Spam',
                    email,
                    message: 'This must stay spam because email rejection also flagged it.',
                },
                'not-a-valid-zero-spam-token'
            );
            expect(result.status).toBe(200);

            const spam = await helpers.getEntries(request, formId, 'spam');
            const [entry] = findByEmail(spam, email);
            expect(entry, 'email-rejection flag must keep the entry spam').toBeTruthy();

            const active = await helpers.getEntries(request, formId, 'active');
            expect(findByEmail(active, email), 'email-rejection flag must not be rescued').toHaveLength(0);

            const mail = await helpers.getCapturedMail(request);
            expect(findBySubject(mail, subject), 'email-rejection flag must not send notification').toHaveLength(0);

            const notes = await helpers.getEntryNotes(request, Number(entry.id));
            expect(notes.some((note) => note.user_name === 'Zero Spam (AI Rescue)')).toBe(false);

            const state = await helpers.getAiReview(request);
            expect(state.calls, 'mixed token and email-rejection spam must not call AI').toBe(0);
        } finally {
            await helpers.setEmailRules(request, {
                enabled: false,
                rules: [],
                message: '',
            });
        }
    });

    test('HP-23: WP_Error verdict fails open', async ({ page, request }) => {
        const email = `ai-error+${testId}@example.test`;

        await helpers.setAiReview(request, {
            global_enabled: true,
            mode: 'error',
            error_code: 'zs_e2e_prompt_error',
            error_message: 'E2E prompt failure',
        });

        const result = await postSubmission(page, request, pageUrl, formId, {
            name: 'Error Path',
            email,
            message: 'This should fail open.',
        });
        expect(result.status).toBe(200);

        const active = await helpers.getEntries(request, formId, 'active');
        expect(findByEmail(active, email), 'AI error must fail open').toHaveLength(1);

        const state = await helpers.getAiReview(request);
        expect(state.calls).toBe(1);
    });

    test('HP-26: spam verdict below threshold fails open after AI runs', async ({
        page,
        request,
    }) => {
        const email = `ai-threshold-low+${testId}@example.test`;

        await helpers.setAiReview(request, {
            global_enabled: true,
            mode: 'verdict',
            threshold: 0.9,
            verdict: {
                is_spam: true,
                confidence: 0.5,
                reason: 'Below threshold',
            },
        });

        const result = await postSubmission(page, request, pageUrl, formId, {
            name: 'Threshold Low',
            email,
            message: 'This should be reviewed but not blocked.',
        });
        expect(result.status).toBe(200);

        const active = await helpers.getEntries(request, formId, 'active');
        expect(findByEmail(active, email), 'below-threshold spam verdict must stay active').toHaveLength(1);

        const spam = await helpers.getEntries(request, formId, 'spam');
        expect(findByEmail(spam, email), 'below-threshold spam verdict must not create spam').toHaveLength(0);

        const state = await helpers.getAiReview(request);
        expect(state.calls, 'AI must run before the threshold rejects the verdict').toBe(1);
    });

    test('HP-27: spam verdict at threshold marks spam', async ({ page, request }) => {
        const email = `ai-threshold-boundary+${testId}@example.test`;

        await helpers.setAiReview(request, {
            global_enabled: true,
            mode: 'verdict',
            threshold: 0.9,
            verdict: {
                is_spam: true,
                confidence: 0.9,
                reason: 'At threshold',
            },
        });

        const result = await postSubmission(page, request, pageUrl, formId, {
            name: 'Threshold Boundary',
            email,
            message: 'This should be marked at the threshold.',
        });
        expect(result.status).toBe(200);

        const spam = await helpers.getEntries(request, formId, 'spam');
        expect(findByEmail(spam, email), 'confidence equal to threshold must mark spam').toHaveLength(1);

        const state = await helpers.getAiReview(request);
        expect(state.calls).toBe(1);
    });

    test('HP-28: serialization includes visible text and name but excludes administrative fields', async ({
        page,
        request,
    }) => {
        const serialization = await createSerializationForm();
        let serializationPageId;

        await helpers.setFormZeroSpam(request, serialization.formId, true);
        await helpers.setFormAiReview(request, serialization.formId, {
            enabled: true,
            maxCallsPerHour: 0,
        });
        await helpers.setAiReview(request, {
            global_enabled: true,
            mode: 'verdict',
            verdict: {
                is_spam: false,
                confidence: 0.01,
                reason: 'Serialization ham verdict',
            },
        });

        const created = await helpers.createPage(request, {
            title: `ZS AI Serialization ${serialization.testId}`,
            content: `[gravityform id="${serialization.formId}" title="false" description="false" ajax="false"]`,
            slug: `zs-ai-serialization-${serialization.testId}`,
        });
        serializationPageId = created.page_id;

        try {
            const visible = `VISIBLE-${serialization.testId}`;
            const spamName = `SPAMNAME-${serialization.testId}`;
            const secret = `SECRET-${serialization.testId}`;

            const result = await postFields(
                page,
                request,
                created.permalink,
                serialization.formId,
                {
                    input_1: visible,
                    input_2_3: 'Serialization',
                    input_2_6: spamName,
                    input_3: secret,
                }
            );
            expect(result.status).toBe(200);

            const state = await helpers.getAiReview(request);
            expect(state.calls).toBe(1);
            expect(state.last_payload).toContain(visible);
            expect(state.last_payload).toContain(spamName);
            expect(state.last_payload).not.toContain(secret);
        } finally {
            await helpers.cleanup(serialization.testId);

            if (serializationPageId) {
                await helpers.cleanupPages(request, [serializationPageId]);
            }
        }
    });

    test('HP-29: malformed verdict fails open after AI runs', async ({ page, request }) => {
        const email = `ai-malformed+${testId}@example.test`;

        await helpers.setAiReview(request, {
            global_enabled: true,
            mode: 'verdict',
            verdict: {
                is_spam: true,
            },
        });

        const result = await postSubmission(page, request, pageUrl, formId, {
            name: 'Malformed',
            email,
            message: 'This verdict is missing required fields.',
        });
        expect(result.status).toBe(200);

        const active = await helpers.getEntries(request, formId, 'active');
        expect(findByEmail(active, email), 'malformed verdict must fail open').toHaveLength(1);

        const state = await helpers.getAiReview(request);
        expect(state.calls, 'AI verdict filter must have produced the malformed value').toBe(1);
    });

    test('HP-31: per-form excluded fields are omitted from the AI payload', async ({
        page,
        request,
    }) => {
        const included = `AI-INCLUDED-${testId}`;
        const excluded = `AI-EXCLUDED-${testId}`;

        await helpers.setFormAiReview(request, formId, {
            enabled: true,
            maxCallsPerHour: 0,
            excludedFields: [3],
        });
        await helpers.setAiReview(request, {
            global_enabled: true,
            mode: 'verdict',
            verdict: {
                is_spam: false,
                confidence: 0.01,
                reason: 'Excluded field payload verdict',
            },
        });

        const result = await postSubmission(page, request, pageUrl, formId, {
            name: included,
            email: `ai-excluded-field+${testId}@example.test`,
            message: excluded,
        });
        expect(result.status).toBe(200);

        const state = await helpers.getAiReview(request);
        expect(state.calls).toBe(1);
        expect(state.last_payload).toContain(included);
        expect(state.last_payload).not.toContain(excluded);
    });

    test('HP-32: result hook can force a ham AI verdict to spam', async ({
        page,
        request,
    }) => {
        const email = `ai-result-force-spam+${testId}@example.test`;
        const reason = 'Forced spam from ham verdict';

        await helpers.setAiReview(request, {
            global_enabled: true,
            mode: 'verdict',
            forceResult: 'spam',
            verdict: {
                is_spam: false,
                confidence: 0.01,
                reason,
            },
        });

        const result = await postSubmission(page, request, pageUrl, formId, {
            name: 'Forced Spam',
            email,
            message: 'The verdict is ham, but the result hook forces spam.',
        });
        expect(result.status).toBe(200);

        const spam = await helpers.getEntries(request, formId, 'spam');
        const ours = findByEmail(spam, email);
        expect(ours, 'result hook must be able to override ham to spam').toHaveLength(1);

        const notes = await helpers.getEntryNotes(request, ours[0].id);
        expect(notes.some((note) => note.user_name === 'Zero Spam (AI)')).toBe(true);
        expect(notes.some((note) => note.value.includes(reason))).toBe(true);

        const state = await helpers.getAiReview(request);
        expect(state.calls).toBe(1);
        expect(state.force_result).toBe('spam');
    });

    test('HP-33: result hook can veto a spam AI verdict to ham', async ({
        page,
        request,
    }) => {
        const email = `ai-result-force-ham+${testId}@example.test`;

        await helpers.setAiReview(request, {
            global_enabled: true,
            mode: 'verdict',
            forceResult: 'ham',
            verdict: {
                is_spam: true,
                confidence: 0.99,
                reason: 'Would be spam without the result hook',
            },
        });

        const result = await postSubmission(page, request, pageUrl, formId, {
            name: 'Forced Ham',
            email,
            message: 'The verdict is spam, but the result hook vetoes it.',
        });
        expect(result.status).toBe(200);

        const active = await helpers.getEntries(request, formId, 'active');
        expect(findByEmail(active, email), 'result hook must be able to veto spam').toHaveLength(1);

        const spam = await helpers.getEntries(request, formId, 'spam');
        expect(findByEmail(spam, email), 'vetoed spam verdict must stay active').toHaveLength(0);

        const state = await helpers.getAiReview(request);
        expect(state.calls).toBe(1);
        expect(state.force_result).toBe('ham');
    });

	test('HP-24: per-form rate cap skips the second AI review and fails open', async ({
		page,
        request,
    }) => {
        const firstEmail = `ai-cap-one+${testId}@example.test`;
        const secondEmail = `ai-cap-two+${testId}@example.test`;

        await helpers.setFormAiReview(request, formId, {
            enabled: true,
            maxCallsPerHour: 1,
        });
        await helpers.setAiReview(request, {
            global_enabled: true,
            mode: 'verdict',
            verdict: {
                is_spam: true,
                confidence: 0.99,
                reason: 'E2E rate cap spam verdict',
            },
        });

        const firstResult = await postSubmission(page, request, pageUrl, formId, {
            name: 'First Bot',
            email: firstEmail,
            message: 'First spam payload.',
        });
        expect(firstResult.status).toBe(200);

        const secondResult = await postSubmission(page, request, pageUrl, formId, {
            name: 'Second Bot',
            email: secondEmail,
            message: 'Second spam payload.',
        });
        expect(secondResult.status).toBe(200);

        const spam = await helpers.getEntries(request, formId, 'spam');
        expect(findByEmail(spam, firstEmail), 'first call is under cap and spam').toHaveLength(1);
        expect(findByEmail(spam, secondEmail), 'second call over cap is not spam').toHaveLength(0);

        const active = await helpers.getEntries(request, formId, 'active');
        expect(findByEmail(active, secondEmail), 'second call over cap fails open').toHaveLength(1);

        const state = await helpers.getAiReview(request);
        // This is the load-bearing assertion; do not reset the AI review seam between these submissions.
        expect(state.calls, 'rate-capped second submission must not call AI').toBe(1);
    });

    test('HP-25: disabled AI review does not call AI', async ({ page, request }) => {
        const email = `ai-disabled+${testId}@example.test`;

        await helpers.setFormAiReview(request, formId, {
            enabled: false,
            maxCallsPerHour: 0,
        });
        await helpers.setAiReview(request, {
            global_enabled: true,
            mode: 'verdict',
            verdict: {
                is_spam: true,
                confidence: 0.99,
                reason: 'Should not be used',
            },
        });

        const result = await postSubmission(page, request, pageUrl, formId, {
            name: 'Disabled',
            email,
            message: 'AI review is disabled for this form.',
        });
        expect(result.status).toBe(200);

        const active = await helpers.getEntries(request, formId, 'active');
        expect(findByEmail(active, email), 'disabled AI review must fail open').toHaveLength(1);

        const state = await helpers.getAiReview(request);
        expect(state.calls).toBe(0);
    });
});

test.describe('Zero Spam — AI settings UI', () => {
    let testId;

    test.afterEach(async () => {
        if (testId) {
            await helpers.cleanup(testId);
        }
    });

    test('HP-47: rescue-only forms can edit the AI instructions field', async ({
        page,
        request,
    }) => {
        const form = await createAiReviewForm();
        const prompt = `Rescue-only prompt ${form.testId}`;
        testId = form.testId;

        await helpers.setFormZeroSpam(request, form.formId, true);
        await helpers.setFormAiReview(request, form.formId, {
            enabled: false,
            rescueEnabled: true,
            prompt: '',
        });

        await page.goto(formSettingsUrl(form.formId));

        const promptField = page.locator('#gfZeroSpamAIPrompt');
        await expect(promptField, 'rescue-only form must show AI instructions').toBeVisible();

        await promptField.fill(prompt);
        await page.locator(FORM_SETTINGS_SAVE_BUTTON).click();
        await expect(page.locator(SUCCESS_NOTICE)).toContainText('Settings updated.');

        await page.goto(formSettingsUrl(form.formId));
        await expect(page.locator('#gfZeroSpamAIPrompt')).toHaveValue(prompt);
    });
});
