# Gravity Forms Zero Spam — Happy Paths

Prioritized list of happy paths for Playwright E2E coverage. Most critical, user-facing flows first.

**Source**: plugin code (`includes/`), `readme.txt` changelog, [Getting started doc](https://www.gravitykit.com/docs/gravity-forms-zero-spam/getting-started-with-gravity-forms-zero-spam/).

**Conventions** (mirrored from `gravitykit-qa2/GravityView`): Playwright `1.56.x`, `@gravitykit/e2e-bootstrap`, `@gravitykit/e2e-fixtures`, `@wordpress/env`, tests under `tests/E2E/{setup,tests,fixtures,helpers,mu-plugins}`.

---

## Tier 1 — Core spam protection (must work or plugin is broken)

### HP-1. Legitimate visitor submits a Zero-Spam-protected form
- **Roles**: anonymous visitor
- **Preconditions**: Gravity Forms + Zero Spam active. A form with Zero Spam enabled is published on a page. JS enabled.
- **Steps**: visit page → wait for `gfZeroSpamConfig` to inject → fill required fields → submit.
- **Expected**: confirmation message rendered; entry exists in admin with **status = active** (not spam); configured notifications fire.

### HP-2. Submission without a valid token is flagged as spam
- **Roles**: simulated bot (`page.evaluate` strips `gf_zero_spam_token` / `gf_zero_spam_key` inputs before submit) — no real bots
- **Preconditions**: same as HP-1
- **Steps**: load page → remove the hidden zero-spam input(s) → submit valid field data
- **Expected**: GF default "Thanks for contacting us…" confirmation shown; admin entry list shows entry under **Spam** filter; notifications and confirmation feeds skipped; entry note records spam reason ("missing or invalid key").

### HP-3. Token AJAX endpoint issues a valid token
- **Roles**: anonymous visitor
- **Preconditions**: form with Zero Spam enabled rendered on a page
- **Steps**: page intercepts `admin-ajax.php?action=gf_zero_spam_token` request → assert 200 + JSON token; submit form using fetched token
- **Expected**: response shape contains a non-empty token; submission succeeds (entry not spam).

---

## Tier 2 — Per-form and global toggles

### HP-4. Admin enables Zero Spam on a single form
- **Roles**: admin
- **Preconditions**: global default = Disabled. Form exists with no Zero Spam protection.
- **Steps**: Forms → form → Settings → toggle "Prevent spam using Gravity Forms Zero Spam" ON → Save.
- **Expected**: setting persists on reload; rendered form HTML now contains `gfZeroSpamConfig`; HP-1 / HP-2 behavior applies.

### HP-5. Admin disables Zero Spam on a single form
- **Roles**: admin
- **Preconditions**: global default = Enabled. Target form has no per-form override.
- **Steps**: open Form Settings → toggle off → Save.
- **Expected**: setting persists; `gfZeroSpamConfig` no longer injected for that form; submission without a token is **not** flagged.

### HP-6. Global default toggle propagates to new forms
- **Roles**: admin
- **Preconditions**: clean install
- **Steps**: Forms → Settings → Zero Spam → set "Enable Zero Spam by Default" = Disabled → Save → create a new form → open Form Settings.
- **Expected**: new form's per-form toggle defaults to OFF. Switch global setting back to Enabled, create another form → its toggle defaults to ON.

---

## Tier 3 — Email rejection rules (1.5.0+)

### HP-7. Admin enables the Email Rejection feature
- **Roles**: admin
- **Preconditions**: feature off (default)
- **Steps**: Forms → Settings → Zero Spam → enable Email Rejection → Save.
- **Expected**: rule list UI becomes visible; "Add Rule" controls render.

### HP-8. Block rule on exact email rejects matching submission with custom message
- **Roles**: admin (setup), anonymous visitor (submission)
- **Preconditions**: GF ≥ 2.9.15 (block action requires it). HP-7 done. Form has an Email field.
- **Steps**: add rule → type=email, value=`spammer@example.test`, action=block, custom message=`Email rejected by test` → Save → visit form → submit with that email.
- **Expected**: form fails validation inline with the custom message; no entry created.

### HP-9. Flag rule on a domain marks submission as spam
- **Preconditions**: HP-7 done
- **Steps**: add rule → type=domain, value=`@badmail.test`, action=flag → Save → submit form with `user@badmail.test`.
- **Expected**: GF spam confirmation shown; entry exists with status = spam; entry detail shows email-rejection match reason.

### HP-10. Log rule on a wildcard adds an entry note
- **Preconditions**: HP-7 done
- **Steps**: add rule → type=wildcard, value=`*+test@*`, action=log → Save → submit form with `foo+test@anything.test`.
- **Expected**: entry created (status = active); entry notes include a note describing the matched rule. No spam flag, no block.

### HP-11. Bulk import creates multiple rules at once
- **Preconditions**: HP-7 done
- **Steps**: open Import → paste 3 lines (one email, one domain, one wildcard) → submit.
- **Expected**: list grows by 3; each parsed with correct type; all enabled by default.

### HP-12. Disabling an individual rule stops enforcement
- **Preconditions**: HP-9 created a flag rule
- **Steps**: toggle rule OFF in list → Save → submit a matching email.
- **Expected**: entry NOT flagged as spam; submission accepted as normal.

### HP-13. Per-field rule override in the form editor
- **Preconditions**: HP-7 done; form has two Email fields A and B; a global flag rule exists
- **Steps**: open form editor → field A → Email Rejection field settings → add a field-scoped rule different from global → Save form → submit values matching field-A rule via field A and matching global rule via field B.
- **Expected**: each field's rule is enforced independently; global rules still apply unless field opts out as configured.

---

## Tier 4 — Spam report email

### HP-14. Admin configures and saves a spam report frequency
- **Roles**: admin
- **Preconditions**: report frequency = Disabled
- **Steps**: Forms → Settings → Zero Spam → Spam Report Frequency = Daily → fill recipient + subject + body → Save Settings.
- **Expected**: settings persist on reload; cron event `gf_zero_spam_send_report` exists in WP cron schedule (verifiable via WP-CLI or admin UI).

### HP-15. Admin sends a test report email
- **Preconditions**: HP-14 done; mail capture in test environment (e.g., Mailpit/MailHog wired through wp-env)
- **Steps**: click "Send Email and Save Settings".
- **Expected**: 1 email captured at the configured recipient; subject and body present; merge tags (`{{site_name}}`, `{{admin_email}}`, `{{total_spam_count}}`, `{{spam_report_list}}`, `{{settings_link}}`) rendered as expected values; **no** `REPORT_LAST_SENT_DATE_OPTION` change.

### HP-16. Spam report summary lists actual spam entries with links
- **Preconditions**: HP-14 done; ≥1 spam entry exists (created via HP-2)
- **Steps**: trigger the cron hook directly (`wp cron event run gf_zero_spam_send_report`) → inspect captured email.
- **Expected**: body lists the form title with spam count and a link to GF entry list filtered by `filter=spam` and date `>` last sent.

---

## Tier 5 — Admin entry management

### HP-17. Spam entry detail shows the flagging reason
- **Roles**: admin
- **Preconditions**: spam entry created via HP-2 or HP-9
- **Steps**: Forms → Entries → Spam → open entry.
- **Expected**: entry notes / detail panel includes a human-readable reason (e.g., "Flagged by Zero Spam: missing or invalid key" or email-rejection rule match).

### HP-18. User with `gravityforms_edit_entries` cap bypasses checks
- **Roles**: editor-level WP user with that GF capability
- **Preconditions**: user logged in
- **Steps**: visit form → strip token (as in HP-2) → submit.
- **Expected**: entry accepted (not spam) — Zero Spam respects the capability.

---

## Tier 6 — Token lifetime

### HP-19. Anti-Spam Expiration setting persists and is honored
- **Roles**: admin
- **Preconditions**: clean state
- **Steps**: Forms → Settings → Zero Spam → Anti-Spam Expiration = a valid value (e.g., 1 day = 86400) → Save → reload settings → render a form on the front-end.
- **Expected**: saved value reloads correctly; the fallback token embedded in `gfZeroSpamConfig` reflects the configured TTL (decoded JWT payload `exp - iat` matches).

---

## Tier 7 — Compatibility regressions (cover prior bug fixes)

### HP-20. Multiple Gravity Forms on one page each submit successfully
- **Roles**: anonymous visitor
- **Preconditions**: page with two distinct Zero-Spam-protected forms
- **Steps**: submit form A with valid data; reload; submit form B with valid data.
- **Expected**: both submissions create active (non-spam) entries. Regression guard for 1.7.3 fix.

### HP-21. Form with conditional logic is visible and submittable
- **Roles**: anonymous visitor
- **Preconditions**: a published form using GF conditional logic on at least one field, Zero Spam enabled
- **Steps**: load page (no console errors) → trigger the conditional rule → fill the now-visible field → submit.
- **Expected**: form renders without being hidden; entry created as non-spam. Regression guard for 1.7.2 fix.

---

## Out of scope (intentional)

- Real adversarial bot simulation (covered structurally by HP-2).
- Cross-CAPTCHA interaction matrix (Akismet, reCAPTCHA, GravityCaptcha) — listed as compatible in readme but combinatorial; defer until requested.
- PHP-only filter behavior (`gf_zero_spam_client_ip`, `gf_zero_spam_rate_limit`, `gf_zero_spam_email_rules`) — better suited to PHPUnit than E2E.
- i18n / translation rendering.
- Multisite-specific behavior.
