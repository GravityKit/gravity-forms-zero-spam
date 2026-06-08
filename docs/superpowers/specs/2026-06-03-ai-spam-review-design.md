# AI Spam Review — Design Spec

**Date:** 2026-06-03
**Plugin:** Gravity Forms Zero Spam
**Status:** Approved design; pending implementation (via Codex).

## 1. Summary

Add AI-based **content** review of incoming Gravity Forms submissions to catch spam that the existing token (bot-detection) check cannot see. The token check only verifies that a valid signed token was submitted — it says nothing about the submission's content, so a bot that fetches a token and then posts spam passes cleanly (the known bypass pattern). The AI step classifies the *content* of submissions the token check has already **cleared**, and marks them spam when the model is confident.

The feature uses WordPress 7.0's native AI infrastructure — the **WP AI Client** (`wp_ai_client_prompt()`) reading credentials from the **Connectors API**. The plugin stores **no API keys of its own**. It is **WP7-only** and completely inert on older WordPress.

Guiding principle (project standing guidance): **false positives are worse than false negatives — fail open whenever the anti-spam mechanism cannot run or errors.**

## 2. Locked product decisions

1. **Role / order:** token check runs first (`gform_entry_is_spam`, priority 10). AI analyzes the content of submissions the token check cleared (`$is_spam === false`) to catch bypassers. v1 does **not** run AI on submissions already flagged spam.
2. **Cost:** AI is called only on the token-cleared subset, on forms where the feature is enabled, with an optional per-form rolling hourly rate cap (over cap → fail open).
3. **Failure mode = FAIL OPEN.** No connector configured / not approved / rate-limited / network / malformed response / timeout / WP < 7.0 → leave the token verdict untouched. Never mark spam due to an AI error.
4. **Enablement = per-form toggle**, mirroring `enableGFZeroSpam`; per-form setting inherits the global default.
5. **Configurable default prompt** shipped in global settings.
6. **Verdict recording:** spam verdict → `GFCommon::set_spam_filter( $form_id, 'Zero Spam (AI)', $reason )`, so it appears in the entry like the token reasons.
7. **Compat:** gate on `function_exists( 'wp_ai_client_prompt' )`; degrade silently on older WP.

## 3. Verified WP7 AI Client API (empirically confirmed on WP 7.0.1-alpha)

- `wp_ai_client_prompt( string $text )` → `WP_AI_Client_Prompt_Builder` (`wp-includes/ai-client/class-wp-ai-client-prompt-builder.php`, `@since 7.0.0`). snake_case methods are proxied via `__call` to the SDK `PromptBuilder`.
- Builder chain used here: `with_text()` / initial text arg, `using_system_instruction( string )`, `using_temperature( float )`, `using_max_tokens( int )`, `using_request_options( RequestOptions )`, `as_json_response( ?array $schema )`.
- Pre-gate: `is_supported_for_text_generation() : bool` — returns true only when a configured + approved provider supports the request; returns `false` (never throws) when `! wp_supports_ai()` or nothing is configured. **Sufficient on its own** — no separate `wp_supports_ai()` call needed.
- Terminal: `generate_text() : string|WP_Error`. With `as_json_response()`, the string is JSON to be `json_decode()`'d. Any failure returns `WP_Error` (codes: `prompt_network_error`, `prompt_client_error`, `prompt_upstream_server_error`, `prompt_token_limit_reached`, `prompt_invalid_argument`, `prompt_builder_error`, `prompt_prevented`).
- **Default request timeout is 30s** (constructor sets `RequestOptions::KEY_TIMEOUT => 30.0`; global filter `wp_ai_client_default_request_timeout`). Override per-call via `using_request_options( RequestOptions::fromArray([ RequestOptions::KEY_TIMEOUT => 8.0 ]) )`.
- Connectors registered on the test box: `anthropic`, `google`, `openai`, `akismet`; none configured → `is_supported_for_text_generation()` is `false` → fail-open path is the live default.
- Abilities API (`wp_register_ability()`) exists but is **out of scope for v1**.

`RequestOptions` FQCN: `WordPress\AiClient\Providers\Http\DTO\RequestOptions` (import with `use`). Implementation must confirm whether a later `using_request_options()` merges with or replaces the constructor's options; the `KEY_TIMEOUT` value must win either way.

## 4. Architecture

Two new files, loaded the way `class-email-rejection*.php` are:

- `includes/class-gf-zero-spam-ai-review.php` — runtime: hook callback, eligibility gates, content serialization, dedup cache, rate cap, AI call, verdict application.
- `includes/class-gf-zero-spam-ai-review-settings.php` — global + per-form settings fields, and the admin notice.

Existing integration points (verified):
- `includes/class-gf-zero-spam.php:60` registers the token check on `gform_entry_is_spam` priority 10; `check_key_field()` at `:331`.
- Per-form fields: `includes/class-gf-zero-spam-addon.php:236` (`add_settings_field`), placed in the GF `spam` section (fallback `form_options`).
- Global settings: `plugin_settings_fields()` at `includes/class-gf-zero-spam-addon.php:262` (GF AddOn `$sections`). Settings stored under option `gravityformsaddon_gf-zero-spam_settings`.
- Reason recording: `GFCommon::set_spam_filter( $form_id, 'Zero Spam', $reason )` (`:395`, `:424`).

## 5. Hook flow (agreed ordering)

`GF_Zero_Spam_AI_Review::maybe_mark_spam( $is_spam, $form, $entry )` on `gform_entry_is_spam` **priority 20**:

1. Receive current `$is_spam`.
2. If `$is_spam` is already true, only the opt-in synchronous false-positive rescue path may reconsider it; otherwise return `$is_spam`.
3. Eligibility gates: global/per-form AI enabled for the current context, main Zero Spam enabled for the form, normal submission context (skip admin / preview / API / non-`form-submit`), same skips the token check uses.
4. **WP7 gate:** `function_exists( 'wp_ai_client_prompt' )` — else return unchanged (keeps the feature inert on WP < 7).
5. Serialize the submission payload (§6). If empty → return unchanged.
6. Request-local dedup cache lookup (§8). On hit, reuse.
7. Rate-cap availability check (§7). Over cap → return unchanged (fail open). Counter is incremented only when a verdict is actually produced (step 8/10).
8. Apply filter `gf_zero_spam_ai_verdict` (default `null`) with the serialized payload + context. (Runs **before** the connector availability check so tests need no connector.)
   - Array `{is_spam, confidence, reason}` → validate, go to step 11.
   - `WP_Error` → fail open (return unchanged).
   - `null` → proceed to real AI call.
9. Build prompt: `wp_ai_client_prompt( $payload )->using_system_instruction( $prompt )->using_max_tokens( 200 )->using_request_options( RequestOptions::fromArray([ KEY_TIMEOUT => $timeout /*8.0*/ ]) )->as_json_response( $schema )`. Temperature and model are omitted so the provider/default connector policy decides.
10. `is_supported_for_text_generation()` → false → fail open. Else `generate_text()`; `is_wp_error()` → fail open; `json_decode()` failure / schema-invalid → fail open.
11. Verdict handling: if `is_spam === true` **and** `confidence >= threshold`, call `GFCommon::set_spam_filter( $form_id, 'Zero Spam (AI)', $reason )` and return `true`. Otherwise return `$is_spam` (false).

JSON schema for `as_json_response()`:
```
{ type: object,
  additionalProperties: false,
  properties: {
    is_spam:    { type: boolean },
    confidence: { type: number, minimum: 0, maximum: 1 },
    reason:     { type: string }
  },
  required: [ is_spam, confidence, reason ] }
```

## 6. Content serialization (privacy-aware)

Send the model only user-visible, content-bearing data:

- **Include:** form id + title; submission source URL host + path (query stripped); each included field's label, type, and value.
- **Field types included** when textual/selection-like: `text`, `textarea`, `website`/`url`, `select`, `multiselect`, `radio`, `checkbox`, `list`, `post_title`, `post_content`, `post_excerpt`, and unknown custom scalar fields (unless deny-listed).
- **Email values redacted** to `*@domain.tld` (never the full local part).
- **Exclude:** `hidden`, `password`, `creditcard` / payment / pricing / `total`, `fileupload`, `consent`, `captcha`, honeypot, `html`, `section`, `page`, `signature`, and admin-only / display-only / hidden-visibility fields. Never send IP, user id, cookies, raw uploaded-file URLs, or tracking fields.
- **Normalize:** strip tags, collapse whitespace; cap each field ≈1000 chars and total payload ≈8000 chars.
- Included/excluded field-type lists are filterable together via `gf_zero_spam_ai_field_types`.

## 7. Rate cap

- Per form, rolling one-hour window. Storage: transient `gf_zs_ai_rate_{form_id}` holding recent attempt timestamps.
- On attempt: load timestamps, drop entries older than the window; if count ≥ cap → fail open (skip AI). Otherwise append `now` and persist for the window.
- Increment only when a verdict is actually produced (filtered or real call), not on early fail-open exits. Transient/cache failures → fail open.
- Per-form field `gfZeroSpamAIMaxCallsPerHour`; blank/`0` = no cap.

## 8. Dedup

Request-local **static** cache keyed by `form_id + hash( payload + prompt + threshold )`, consulted before the rate-cap increment / AI call, so GF's repeated spam evaluations within one request don't double-spend. No persistent verdict cache in v1 (prompts/settings change; payloads can be sensitive).

## 9. Settings

**Global** (new section "AI spam protection" in `plugin_settings_fields()`):
- `gf_zero_spam_ai_review_enabled` — toggle, default `false`, catches spam that passed the token check.
- `gf_zero_spam_ai_rescue_enabled` — toggle, default `false`, rescues legitimate submissions blocked only by the Zero Spam token check.
- `gf_zero_spam_ai_default_prompt` — textarea (default text below), visible when review or rescue is enabled.
- Advanced preset selects: `gf_zero_spam_ai_confidence_threshold` for review (`0.90`/`0.95`) and `gf_zero_spam_ai_rescue_confidence_threshold` for rescue (`0.95`/`0.98`).
- AI review and rescue both run synchronously in v1. Background processing is deferred to a later release.
- No model/temperature UI or public hook in v1: provider defaults are used.

**Per-form** (same `spam`/`form_options` placement as `enableGFZeroSpam`):
- `enableGFZeroSpamAI` — toggle, inherits the global default.
- `enableGFZeroSpamAIRescue` — toggle, inherits the global default.
- `gfZeroSpamAIPrompt` — optional prompt override shared by review and rescue.
- `gfZeroSpamAIExcludedFields` — optional field picker for sensitive content.
- `gfZeroSpamAIMaxCallsPerHour` — number; blank/`0` = no cap.

**Default prompt:**
> Review this Gravity Forms submission for spam content. Classify as spam only when it is clearly unsolicited, deceptive, malicious, or mass-promotional. Do not classify normal customer questions, support requests, quote requests, bookings, or job applications as spam. False positives are worse than false negatives. Use only the form title, source path, field labels, field types, and submitted values provided. Ignore missing context and do not guess. Return JSON matching the supplied schema. The reason must be concise and must not quote personal data.

## 10. Admin notice

Shown only on `page=gf_settings&subview=gf-zero-spam`, only when global AI review or rescue is enabled and `function_exists( 'wp_ai_client_prompt' )`. Build a tiny prompt and call `is_supported_for_text_generation()`; if `false`, show a warning that AI is enabled but no usable AI connector is configured, linking to **Settings → Connectors** via the encapsulated connectors settings URL helper. Do not invent a Connector-Approvals API.

## 11. Extension filters (public, `gf_zero_spam_ai_*` naming)

Final public surface:

- `gf_zero_spam_ai_enabled( $enabled, $context, $form, $entry )` for context-aware enablement.
- `gf_zero_spam_ai_confidence_threshold( $threshold, $context, $form, $entry )` for exact threshold overrides.
- `gf_zero_spam_ai_prompt( $prompt, $form, $entry, $payload )` for prompt customization.
- `gf_zero_spam_ai_field_types( $field_types, $form, $entry )` for included/excluded field-type customization.
- `gf_zero_spam_ai_verdict( $verdict, $payload, $form, $entry )` as the deterministic short-circuit seam.
- `gf_zero_spam_ai_result( $is_spam, $verdict, $form, $entry )` for final review decisions.
- `gf_zero_spam_ai_rescue_result( $is_rescued, $verdict, $form, $entry )` for final rescue decisions.
- `gf_zero_spam_ai_timeout( $timeout, $form, $entry, $payload )` for the per-call request timeout.
- `gf_zero_spam_token_rejected( $reason_code, $form, $entry )` for token rejection diagnostics.

## 12. Testing (Playwright E2E — no PHPUnit in this repo)

- New test mu-plugin hooks `gf_zero_spam_ai_verdict` and returns canned verdicts / `WP_Error` / `null`, driven by new `/zs-e2e/v1/ai-review` REST control endpoints (set verdict, set error mode, read call count). Deterministic, zero API spend, version-agnostic.
- Cases: valid-token + AI spam verdict → entry spam with "Zero Spam (AI)" reason; valid-token + ham → active; missing/invalid token → AI **not** called (priority-20 sees `$is_spam===true`); `WP_Error` verdict → fail open (active); per-form rate cap → second call skipped (fail open); AI review disabled → not called.
- Run via the project's `tests:e2e:run` Playwright harness against the WP7 wp-env.

## 13. Compatibility

Entirely inert when `wp_ai_client_prompt()` is absent: the priority-20 callback returns early, and the AI settings sections/fields are hidden (or rendered disabled with an explanatory note) on WP < 7.0.

## 14. Open / unverified items

- Exact Settings → Connectors page slug (discovered + filterable, not hardcoded).
- Whether SDK `using_request_options()` merges or replaces options (verify at implementation; `KEY_TIMEOUT` must win).
- Third-party field-type safety for serialization (filterable allow/deny lists).
- Connector-Approvals query API is undocumented; fail-open path covers the "not approved" case without it.

## 15. Constraints

Project code style (CLAUDE.md): braces on all `if`; comments end with a period; early returns, no nesting; no blank line after `{`; `use` imports (no inline `\FQCN`); `@since TBD` on all new code; translatable strings use `[placeholder]`/`{{token}}` + `strtr()`, no `%s`/`%d`, no layout/HTML/newlines in strings; new hooks use `gf_zero_spam_*` naming. No commit/push/delete during implementation.
