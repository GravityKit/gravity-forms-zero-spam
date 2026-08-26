=== Gravity Forms Zero Spam ===
Contributors: gravityview
Tags: gravity forms, spam, captcha, honeypot, anti-spam
Requires at least: 4.7
Tested up to: 7.0.4
Stable tag: 1.10.2
Requires PHP: 7.4
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Donate link: https://www.gravitykit.com?utm_source=plugin&utm_campaign=zero-spam&utm_content=donate

Block form spam in Gravity Forms with an invisible token check, email rejection rules, and optional AI review. No CAPTCHAs for your visitors.

== Description ==

Gravity Forms Zero Spam stops spam submissions without CAPTCHAs, checkboxes, or puzzles. Install it, switch it on for a form, and it starts working. Nothing changes for the people filling in your forms.

You get four spam checks. One runs out of the box, and three more are there when you need them:

* **Invisible token check.** On by default. Blocks automated submissions using a signed, time-limited token.
* **Email rejection rules.** Reject submissions by email address, domain, wildcard, or regular expression.
* **AI spam review.** Optional. Catches spam the token check missed, and rescues good submissions it blocked by mistake.
* **Shield silentCAPTCHA.** Optional. Uses Shield Security's bot detection as an extra signal.

Run one, or run all four. Every check happens on your own server, and optional summary emails keep you posted on what was caught.

### Spam blocking your visitors never see 🕵️

The plugin adds a signed, time-limited token to your form and checks it on submission. Most spam bots post directly to your site without loading the page or running its JavaScript, so they never pick up a token and their submissions are marked as spam.

No CAPTCHAs, no puzzles, no extra fields, no cookies, and nothing to configure to get started.

### Reject unwanted emails by address, domain, or pattern ✉️

Filter submissions against a list you control. Useful when spam keeps arriving from one domain, or when a business enquiry form should not accept free consumer addresses.

* Match by exact address, domain, wildcard, or regular expression
* On a match, block the submission (requires Gravity Forms 2.9.15 or newer), flag it as spam, or log it with an entry note
* Add rules in bulk, and enable or disable them individually while you test
* Override or replace the global rules on a single email field

[Email rejection rules guide](https://www.gravitykit.com/docs/gravity-forms-zero-spam/email-rejection-rules/)

### Let AI review the edge cases 🤖

An optional second opinion on submissions the token check has already judged. Two separate tools, both off until you turn them on:

* **Catch what was missed.** AI reads a submission that passed the spam checks and flags it if it looks like spam.
* **Rescue what was wrongly blocked.** If the token check blocked a submission but the content looks genuine, AI can let it through before notifications are sent.

It runs on WordPress 7.0's built-in AI connectors, so you connect OpenAI, Anthropic, or Google once and every plugin on the site shares it. Zero Spam never stores API keys, and nothing is sent until you opt in. You can write your own instructions, skip fields that collect sensitive data, and cap how many submissions are reviewed per hour.

[AI spam review guide](https://www.gravitykit.com/docs/gravity-forms-zero-spam/ai-spam-review/)

### Add Shield silentCAPTCHA as an extra signal 🛡️

If the free [Shield Security](https://wordpress.org/plugins/wp-simple-firewall/) plugin is active, its silentCAPTCHA bot detection can act as an additional check, globally or per form. Like the token check it runs server-side and stays invisible to visitors, and if Shield is deactivated or returns an error, submissions are never blocked. Contributed by Shield Security developer Paul Goodchild.

[Shield silentCAPTCHA guide](https://www.gravitykit.com/docs/gravity-forms-zero-spam/shield-silentcaptcha/)

### Control how and when the checks run 🎛️

* Each form gets a "Prevent spam using Gravity Forms Zero Spam" setting, so you protect only the forms you want (requires Gravity Forms 2.5 or newer)
* Set the default for new forms once in the global settings
* When more than one check is active, choose the order they run in and whether to stop at the first detection

### Spam summary report emails 📬

Off by default. Turn them on and you get a summary of how many entries were marked as spam, broken down per form.

* Send after a threshold is reached, or on a schedule
* Nothing is sent when there is no spam to report
* Customize the email body with merge tags

### Works with your other anti-spam tools 🤝

Zero Spam does not interfere with other spam-blocking plugins, including Gravity Forms' own honeypot field and reCAPTCHA integration. Honeypots catch simple bots and reCAPTCHA asks real people to click traffic lights; Zero Spam asks nothing of your visitors at all, and you can layer it on top of either rather than replacing them.

-------

Requires [Gravity Forms](https://www.gravityforms.com/?partner_id=1210629&irgwc=1&utm_medium=affiliate&utm_campaign=1210629&utm_source=Katz%20Web%20Services%2C%20Inc.). That is an affiliate link: if you buy through it we earn a commission, at no extra cost to you.

[Read the documentation](https://www.gravitykit.com/docs/gravity-forms-zero-spam/) for setup guides and troubleshooting.

_Brought to you by [GravityKit](https://www.gravitykit.com?utm_source=plugin&utm_campaign=zero-spam&utm_content=readme). We create essential Gravity Forms Add-Ons._

== Frequently Asked Questions ==

= Is the plugin free? =

Yes, and every feature described above is included. You need a Gravity Forms license for Gravity Forms itself.

= Does AI spam review send my form data to an AI service? =

Only if you turn it on. Both AI tools are off by default and nothing leaves your site until you enable them.

When you do, the plugin sends the form title, the page path, and the field labels, types, and submitted values, along with your instructions. Email addresses are masked so only the domain remains, fields you list under "Skip these fields" are removed, and the content is length-capped. The connection is made through WordPress 7.0's own AI connectors, so Zero Spam never stores your API keys.

= Does this plugin stop email notifications when a submission is marked as spam? =

Yes. Marking an entry as spam prevents processing of any configured notifications and add-on feeds.

Note: a spam entry also skips your configured confirmation. The person submitting sees the default Gravity Forms "Thanks for contacting us! We will get in touch with you shortly." confirmation instead.

= Does this work alongside other CAPTCHAs, honeypot fields, or spam blockers? =

Yes. Run it next to Gravity Forms' reCAPTCHA feature or honeypot field, Akismet, CleanTalk, WPBruiser, GravityCaptcha, WP Armor, or fail2ban without conflict.

= What version of Gravity Forms do I need? =

Gravity Forms 2.3 and newer. Two features need more recent versions: per-form control requires 2.5 or newer, and blocking a submission with an email rejection rule requires 2.9.15 or newer.

= How do I control which forms are protected? =

Open the form, click Settings, and toggle "Prevent spam using Gravity Forms Zero Spam" under Form Options. To protect most forms, leave the global "Enable Zero Spam by Default" setting Enabled and switch off the individual forms you want excluded.

Note: setting "Enable Zero Spam by Default" to Disabled turns off every check on every form, and per-form settings have no effect while it is.

= My own test submission was not marked as spam. Is it broken? =

Probably not. Zero Spam deliberately skips logged-in users who can edit entries, form previews, and entries created through the Gravity Forms API, so submitting your own form as an administrator is expected to go through. To test it properly, log out and submit with JavaScript disabled. See [how Zero Spam works and how to test it](https://www.gravitykit.com/docs/gravity-forms-zero-spam/how-zero-spam-works/).

= All of my entries are going to spam. What can I do? =

First, deactivate and reactivate the plugin. If that does not fix it, see [Troubleshooting Gravity Forms Zero Spam](https://www.gravitykit.com/docs/gravity-forms-zero-spam/troubleshooting-gravity-forms-zero-spam/), then let us know on the support tab.

= Will this block spam comments or registrations on my site? =

No, it only protects Gravity Forms submissions. For comments and registrations we recommend Ben Marshall's [WordPress Zero Spam plugin](https://wordpress.org/plugins/zero-spam/).

== Changelog ==

= 1.10.2 on August 13, 2026 =

* Improved: Custom rejection messages now have HTML safely removed before being shown to visitors
* Fixed: Email rejection rules added to a specific field quietly did nothing when the feature was turned off in the plugin settings — the form editor now lets you know and links to the setting
* Fixed: Regular expression rejection rules not working as intended:
  - Rules could change when saved (a starting or ending period was removed, and anything after a `<` was cut off), so they never matched
  - Rules were checked against lowercased text, which flipped the meaning of patterns like `\D`, `\S`, and `\W` and could block real submissions

= 1.10.1 on August 6, 2026 =

* Fixed: Multi-page forms fetched an anti-spam token on every Next and Previous click, delaying page navigation on slow servers; the token is now only fetched on final submission and Save and Continue

= 1.10.0 on July 23, 2026 =

* Added: [Shield silentCAPTCHA integration](https://www.gravitykit.com/docs/gravity-forms-zero-spam/shield-silentcaptcha/) that uses Shield Security's bot detection as an additional spam signal, with a global default and per-form override (thanks, Paul Goodchild!)
* Added: "Spam Check Order" settings - control the order the token check, Shield silentCAPTCHA, and AI Spam Review run in, and whether to stop checking once a submission is flagged as spam
* Fixed: A spam verdict from one check could be cleared when the submitter was logged in with entry-editing permissions
* API: Added `gf_zero_spam_check_order` filter to modify the order in which spam checks run

= 1.9.0 on June 11, 2026 =

* Added: AI-powered spam review that flags likely spam the token check missed and recovers legitimate submissions it blocked by mistake, powered by an AI service (OpenAI, Anthropic, or Google Gemini) connected in WordPress 7.0+
* Fixed: Per-form links in the spam report email opened an empty Spam entries list, hiding spam received after the previous report

= 1.8.0 on April 16, 2026 =

* Added: Translations in 27 languages
* Fixed: Spam report emails could fail to send when the feature was first enabled

= 1.7.5 on April 9, 2026 =

* Fixed: Forms rendered in modals or other elements output via `wp_footer` (e.g., site-wide popups, slide-ins) were missing the spam prevention token, causing legitimate submissions to be flagged as spam

= 1.7.4 on April 2, 2026 =

* Added: "Anti-Spam Expiration" setting to control how long spam prevention tokens remain valid, accessible from Forms > Settings > Zero Spam
* Fixed: Email rejection settings and form editor scripts not loading when Gravity Forms No Conflict mode is enabled
* Fixed: Form submission failure ("Cannot read properties of undefined") caused by the token fetch request failing unexpectedly

= 1.7.3 on March 24, 2026 =

* Improved: Extended token lifetime to 7 days and improved token fetching compatibility
* Fixed: Submissions from pages with multiple Gravity Forms were incorrectly marked as spam when the form wasn't the first one on the page
* Added: `gf_zero_spam_token_ttl` and `gf_zero_spam_fallback_token_ttl` filters to adjust the lifetime of dynamically fetched and HTML-embedded fallback tokens respectively (default for both: 7 days)

= 1.7.2 on March 12, 2026 =

* Fixed: Forms with conditional logic could be invisible to visitors

= 1.7.1 on March 6, 2026 =

* Fixed: Fatal error on sites where `AUTH_KEY` or `SECURE_AUTH_KEY` constants are not defined in `wp-config.php`

= 1.7.0 on March 5, 2026 =

* Added: Stronger spam prevention using signed, time-limited tokens
* API: Added `gf_zero_spam_client_ip` filter to override the visitor IP used for rate limiting (useful for sites behind Cloudflare or load balancers)
* API: Added `gf_zero_spam_rate_limit` filter to adjust the maximum token requests allowed per IP per minute (default: 30)

= 1.6.0 on February 27, 2026 =

* Improved: Removed dependency on jQuery
* Fixed: "Prevent spam using Gravity Forms Zero Spam" toggle appeared twice in form settings

= 1.5.0 on February 26, 2026 =

**This release requires PHP 7.4 or newer.**

* Added: Email rejection rules to validate email field submissions and take action based on matching addresses, domains, or patterns
  - Match by exact email, domain (e.g., `@example.com`), wildcard, or regular expressions (regex)
  - Block the submission (requires Gravity Forms 2.9.15+), flag it as spam, or log it with an entry note
  - Import multiple rules at once from a text list
  - Enable/disable rules individually
  - Set field-specific overrides in the Form Editor
* Added: Spam entries now show the reason they were flagged (e.g., missing or invalid key)
* Added: Zero Spam toggle now appears in the "Spam" form settings section on Gravity Forms 2.9.21+
* Added: Spam protection for Save and Continue, preventing bots from creating spam drafts
* Improved: Redundant spam checks are skipped when the entry is already flagged by another filter
* API: Added `gf_zero_spam_email_rules` filter to modify email rejection rules before evaluation
* API: Added `gf_zero_spam_email_rule_match` action that fires when an email matches a rejection rule

= 1.4.6 on January 7, 2025 =

* Fixed: Fatal error on PHP 7.2 and older

= 1.4.5 on November 28, 2024 =

* Fixed: PHP deprecation warning when using Gravity Forms 2.8 or newer

= 1.4.4 on October 15, 2024 =

* Improved: Submissions by users who have the capability to edit Gravity Forms entries are no longer processed by Zero Spam (thanks for the idea, @richardjb62!)
* Fixed: Use GMT time for spam summary email reports to make sure the report is sent at the correct time

= 1.4.2 & 1.4.3 on October 10, 2024 =

* Improved: Sanitized form ID in JavaScript
* Modified: Changed the text domain for strings to `gravity-forms-zero-spam` to match the plugin slug
* Fixed: Added missing Text Domain header for translation

= 1.4.1 on November 10, 2023 =

* Fixed: Conflict with the Gravity Forms Stripe Add-On

= 1.4 on April 24, 2023 =

* Added: **Spam summary email reports** are now available! Receive a helpful email summarizing spam messages received. To enable, from your Dashboard, go to Forms, then Settings, then Zero Spam to enable and configure this feature.
    - Choose when to send the report (threshold-based or frequency-based)
    - Customize the report message
    - Works with any spam messages, not just those blocked by Zero Spam
* Added: A setting to globally enable or disable Zero Spam by default (default: Enabled)

= 1.3 on February 18, 2023 =

Gravity Forms 2.7 added improved spam blocking in 2.7. Keep this plugin installed—it won't hurt, and we're about to add some great new features soon! ❤️ Thanks to Richard Wawrzyniak for the code updates in this release.

* Added: Support for API submissions (which don't have a form submit action)
* Fixed: Gravity Forms 2.7 support
* Fixed: PHP 8.0 warnings
* Tested with WordPress 6.2

= 1.2.3 on August 11, 2022 =

* Tweak: Changed the autocomplete on the hidden field to `new-password` instead of `off`. Thanks for the suggestion, Ross!

= 1.2.2 on August 11, 2022 =

* Improved: Prevent autocomplete on the hidden field. This may help prevent some reported false-positives.
* Tested with WordPress 6.0.1

= 1.2.1 on February 16, 2022 =

* Improved: Don't check for spam on the Gravity Forms Preview page
* Tested with WordPress 5.9

= 1.2 & 1.2.0.1 on December 10, 2021 =

* New! Added a per-form setting "Prevent spam using Gravity Forms Zero Spam" that enables or disables Gravity Forms Zero Spam from processing! [Check out the FAQ to learn how to use this setting](https://wordpress.org/plugins/gravity-forms-zero-spam/#faq-header). Note: this feature requires Gravity Forms 2.5 or newer.

= 1.1.3 on August 9, 2021 =

* Enhancement: Adds an entry note when an entry is marked as spam. Thanks to Gravity Forms for the enhancement!

= 1.1.2 on July 5, 2021 =

* Fixed: Entries going to spam on websites that have plugins that override random password generation. Thanks, @thomascharbit!

= 1.1.1 on April 22, 2021 =

* Fixed: Script not being correctly loaded using Gravity Forms' inline JavaScript

= 1.1 on April 21, 2021 =

* Improved: The JavaScript code that adds the spam-catching field is now output at the same time as Gravity Forms' inline JavaScript.
    - The script is only loaded when forms are displayed
    - jQuery is always available because Gravity Forms loads it as-needed

= 1.0.7 on March 18, 2021 =

* Fixed: All entries marked as spam for AJAX-enabled forms. Sorry, folks! Won't happen again.
* Went back to using jQuery to listen to form submissions.

= 1.0.6.1 on March 15, 2021 =

* Fixed: JavaScript "null is not an object (evaluating 'el.addEventListener')" error on pages without a form

= 1.0.6 on March 11, 2021 =

* Improved: No longer requires jQuery
* Fixed: Only checks for spam on submissions that were submitted by a form, not for entries created programatically

= 1.0.5 on February 16, 2021 =

The Gravity Forms Zero Spam plugin is now maintained by [GravityKit](https://www.gravitykit.com?utm_source=plugin&utm_campaign=zero-spam&utm_content=changelog). We look forward to continuing to improve this simple, effective spam blocker for Gravity Forms. Thanks to GoWP for their great work!

* Improved: Only add anti-spam JavaScript when the Gravity Forms plugin is active
* Fixed: Sanitized key, in case there were any unsafe characters generated by overriding the `random_password` filter.
* Fixed: Made `GF_Zero_Spam::deactivate` a static method
* Updated: Now using the `wp_print_footer_scripts` action to add the script (was `wp_footer`)

= 1.0.4 =
* Should fix 'jQuery undefined' errors

= 1.0.3 =
* Added support for multi-page forms (thanks, @supporthero)

= 1.0.2 =
* Update FAQ and compatibility notes in readme.txt

= 1.0.1 =
* Fix issue with forms not submitting after an initial validation error

= 1.0.0 =
* Initial version
