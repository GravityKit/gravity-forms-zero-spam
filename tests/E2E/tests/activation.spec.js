/**
 * Gravity Forms Zero Spam — post-build activation smoke.
 *
 * Runs against the built .zip artifact (not the source tree) to verify the
 * packaged plugin activates cleanly and doesn't break the core admin surfaces
 * it integrates with. The @smoke tag scopes `playwright test --grep=@smoke`
 * to just these checks; the rest of the suite stays out of the post-build
 * pipeline (it belongs in the pre-build run_e2e_tests job).
 *
 * The wordpress.org build has no GravityKit Foundation. The gravitykit.com
 * build (.gktools.gravitykit.json) bundles it, and the last two tests run only
 * when the zip under test is that build.
 */
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');

const builtPluginPath = process.env.BUILT_PLUGIN_PATH || '';
const isGravityKitEdition = '' !== builtPluginPath && fs.existsSync(path.join(builtPluginPath, 'vendor_prefixed/gravitykit/foundation/src/Core.php'));

test.describe('Gravity Forms Zero Spam — Activation Smoke Test @smoke', () => {

    test('Plugin activates without fatal PHP errors', async ({ page }) => {
        await page.goto('/wp-admin/plugins.php');
        await expect(page.getByText('The site is experiencing technical difficulties')).not.toBeVisible();
        await expect(page.locator('[data-plugin*="gravityforms-zero-spam.php"] .deactivate a')).toBeVisible();
    });

    test('WordPress admin dashboard loads cleanly after activation', async ({ page }) => {
        await page.goto('/wp-admin/');
        await expect(page.getByText('The site is experiencing technical difficulties')).not.toBeVisible();
        await expect(page.locator('#wpadminbar')).toBeVisible();
    });

    test('Gravity Forms menu loads without errors', async ({ page }) => {
        await page.goto('/wp-admin/admin.php?page=gf_edit_forms');
        await expect(page.getByText('The site is experiencing technical difficulties')).not.toBeVisible();
        await expect(page.locator('#wpbody')).toBeVisible();
    });

    test('gravitykit.com edition: Foundation serves the GravityKit licenses page', async ({ page }) => {
        test.skip(!isGravityKitEdition, 'The zip under test is the wordpress.org build.');
        await page.goto('/wp-admin/admin.php?page=gk_licenses');
        await expect(page.getByText('The site is experiencing technical difficulties')).not.toBeVisible();
        await expect(page.getByText('Sorry, you are not allowed to access this page.')).not.toBeVisible();
        await expect(page.locator('#wpbody')).toBeVisible();
    });

    test('gravitykit.com edition: the Email Check offers the GravityKit check', async ({ page }) => {
        test.skip(!isGravityKitEdition, 'The zip under test is the wordpress.org build.');
        await page.goto('/wp-admin/admin.php?page=gf_settings&subview=gf-zero-spam');
        await expect(page.getByText('The site is experiencing technical difficulties')).not.toBeVisible();
        await page.getByRole('checkbox', { name: 'Check Email Addresses' }).check();
        await expect(page.getByRole('checkbox', { name: 'Check with GravityKit' })).toBeVisible();
    });

});
