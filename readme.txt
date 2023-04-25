=== Gravity Forms Zero Spam ===
Contributors: gravityview
Tags: gravityforms, gravity forms, anti-spam, antispam, spam, spam-blocker, spambot, spammer, add-ons, honeypot
Requires at least: 3.0.1
Tested up to: 6.2
Stable tag: trunk
Requires PHP: 5.2.6
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Donate link: https://www.gravitykit.com?utm_source=plugin&utm_campaign=zero-spam&utm_content=donate

Enhance your Gravity Forms to include anti-spam measures originally based on the work of David Walsh's "Zero Spam" technique.

== Description ==

This Gravity Forms add-on blocks spam using a non-obtrusive anti-spam measure and can email a spam report summary.


## Spam blocking

To get started, all you need to do is activate the plugin!

### Is the Gravity Forms honeypot field not working for you? 🍯 🐝

Zero Spam is better than the Gravity Forms anti-spam honeypot field. If you're getting spammed, try this plugin.

### Use instead of reCaptcha

**reCaptcha is user-hostile!** Use this instead! Users don't need to click stoplights, crosswalks, or bicycles when you use this plugin to stop spam.

### Enable or disable per-form

If you only want the plugin for specific forms, that's possible! The plugin adds a simple "Prevent spam using Gravity Forms Zero Spam" setting to each form (requires Gravity Forms 2.5 or newer).

## Spam report emails

Spam summary report emails are disabled by default. Once enabled, a spam summary that includes the number of entries per-form will be sent via email.

Choose whether you want to be notified after the number of entries reaches a threshold (e.g. 10 spam entries) or after a certain number of days (e.g. every week). If there are no spam entries, no report will be sent.

-------

Requires [Gravity Forms](https://www.gravityforms.com/?partner_id=1210629&irgwc=1&utm_medium=affiliate&utm_campaign=1210629&utm_source=Katz%20Web%20Services%2C%20Inc.).

_Brought to you by [GravityKit](https://www.gravitykit.com?utm_source=plugin&utm_campaign=zero-spam&utm_content=readme). We create essential Gravity Forms Add-Ons._

== Frequently Asked Questions ==

= Does the Gravity Forms Zero Spam plugin stop email notifications from being emailed when a message is considered spam? =

Yes! When this plugin marks an entry as spam, it prevents processing of any configured notifications and add-on feeds.

Note: When an entry is marked as Spam, it also prevents use of the configured confirmation. Users submitted a entry marked as Spam will see the default Gravity Forms “Thanks for contacting us! We will get in touch with you shortly.” confirmation text instead.

= I have Gravity Forms 2.7 installed. Do I need this plugin? =

Gravity Forms 2.7 added functionality that has similar features to this plugin, but we're adding additional features soon—keep this plugin installed!

= Does this plugin work in combination with other CAPTCHAs, honeypot fields, or spam blocker plugins? =

Yep, you can use this plugin in combination with all other spam-blocking plugins:

- Gravity Forms' Google reCaptcha feature
- WPBruiser
- Akismet
- Gravity Forms' honeypot fields
- GravityCaptcha
- СleanTalk
- WP Armor
- fail2ban

Zero Spam will not interfere with the operation of those plugins. It also doesn't interfere with Gravity Forms' built-in honeypot functionality.

= What version of Gravity Forms does this work with? =

This works with all Gravity Forms releases after v2.3 (released June 2017).

= Will this block spam comments or registrations on my site? =

No. For that, we recommend Ben Marshall‘s [WordPress Zero Spam plugin](https://wordpress.org/plugins/zero-spam/).

= How do I disable Zero Spam on specific forms? =

1. Go to the form
2. Click on Settings
3. Under Form Options, disable "Prevent spam using Gravity Forms Zero Spam". _Don't see the setting? This feature requires Gravity Forms 2.5 or newer._
4. Save the settings

New form submissions will not be checked using Zero Spam.

= I only want on specific forms. How do I disable Zero Spam by default? =

To disable by default, from your Dashboard, go to Forms, then Settings, then the Zero Spam tab. Under the "Enable Zero Spam by Default" setting, choose "Disabled", then save the form.

Once you have saved the setting, to enable for specific forms:

1. Go to the form
2. Click on Settings
3. Under Form Options, enable "Prevent spam using Gravity Forms Zero Spam". _Don't see the setting? This feature requires Gravity Forms 2.5 or newer._
4. Save the settings

Now that form will use Zero Spam.

= Is this plugin PHP 7 and PHP 8 compatible? =

Yes.

= All entries are going to spam. What can I do? =

First, **de-activate and re-activate the plugin**. Then let us know on the support tab!

= Spam summary report emails =

You can enable a spam summary report email. This email will be sent to the email address configured in the "Spam Summary Email" setting on the Gravity Forms "Forms" menu, click Settings, then click the Zero Spam tab.

== Changelog ==

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
