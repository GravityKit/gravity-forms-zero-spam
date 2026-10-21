<?php
/**
 * Turns the checkout into the gravitykit.com edition. Run by the build steps in .gktools.gravitykit.json,
 * before `composer install`; never commit the files it changes.
 *
 * - Swaps in composer.gravitykit.json and its lock, so `composer install` bundles Foundation.
 * - Adds the `Update URI` header, so WordPress stops offering the wordpress.org update over this edition.
 * - Rewrites the readme for this edition: the remote email check and the services it calls.
 *
 * Exits non-zero when any expected text is missing, so a changed readme or header fails the build
 * rather than shipping a half-edited edition.
 *
 *   php bin/gravitykit-build.php            Apply.
 *   php bin/gravitykit-build.php --check    Report whether the checkout is already the gravitykit.com edition.
 */

$root = dirname( __DIR__ );

const GF_ZERO_SPAM_UPDATE_URI = 'https://www.gravitykit.com/products/gravity-forms-zero-spam/';

/**
 * Replaces exactly one occurrence of $search in a file, or exits.
 */
function gf_zero_spam_build_replace( $file, $search, $replace ) {
	$contents = file_get_contents( $file );

	if ( 1 !== substr_count( $contents, $search ) ) {
		fwrite( STDERR, "gravitykit-build: expected exactly one match in {$file} for:\n{$search}\n" );
		exit( 1 );
	}

	file_put_contents( $file, str_replace( $search, $replace, $contents ) );
}

$plugin_file = "{$root}/gravityforms-zero-spam.php";
$readme      = "{$root}/readme.txt";

$is_built = false !== strpos( file_get_contents( $plugin_file ), 'Update URI:' );

if ( in_array( '--check', $argv, true ) ) {
	echo $is_built ? "gravitykit.com edition\n" : "wordpress.org edition\n";
	exit( 0 );
}

if ( $is_built ) {
	fwrite( STDERR, "gravitykit-build: the checkout is already the gravitykit.com edition.\n" );
	exit( 1 );
}

foreach ( [ 'composer.gravitykit.json' => 'composer.json', 'composer.gravitykit.lock' => 'composer.lock' ] as $from => $to ) {
	if ( ! copy( "{$root}/{$from}", "{$root}/{$to}" ) ) {
		fwrite( STDERR, "gravitykit-build: could not copy {$from} to {$to}\n" );
		exit( 1 );
	}
}

gf_zero_spam_build_replace(
	$plugin_file,
	" * Domain Path:       /languages\n",
	" * Domain Path:       /languages\n * Update URI:        " . GF_ZERO_SPAM_UPDATE_URI . "\n"
);

gf_zero_spam_build_replace(
	$readme,
	'Run one, or run all four. Every check happens on your own server, and optional summary emails keep you posted on what was caught.',
	'Run one, or run all four. Every check happens on your own server unless you turn on the GravityKit email check (see External services below). Optional summary emails keep you posted on what was caught.'
);

gf_zero_spam_build_replace(
	$readme,
	"== Frequently Asked Questions ==\n",
	<<<'README'
### Check that email addresses can receive mail 📮

Turn on the Email Check to catch placeholder text like you@company.com and throwaway inboxes, on your own server. Connect the site to a free GravityKit.com account and turn on "Check with GravityKit" to also catch domains with no mail server, mailboxes that do not exist, and typos such as jane@gmial.com, with a suggested fix.

Matching entries are flagged as spam by default, so a visitor never sees an error unless you choose to block. If the service is slow or unavailable, submissions always go through.

== External services ==

This edition can check submitted email addresses with GravityKit's email check service. It sends nothing until an administrator turns on "Check with GravityKit" under Forms > Settings > Zero Spam, and it needs the site connected to a GravityKit.com account.

* **When:** each time a visitor submits a form with an email field, on forms where the email check is on.
* **What is sent:** the email address, and nothing else, to `https://email-check.gravitykit.com`. Once a day the site also asks `https://www.gravitykit.com` for a token that says which GravityKit.com account it belongs to.
* **Why:** to find addresses that cannot receive mail, so the site owner's reply is not lost.

GravityKit's [Terms of Service](https://www.gravitykit.com/terms-service-terms/) and [Privacy Policy](https://www.gravitykit.com/privacy-policy/) apply.

== Frequently Asked Questions ==

README
);

echo "gravitykit-build: the checkout is now the gravitykit.com edition.\n";
