/**
 * Gravity Forms Zero Spam — post-build activation smoke.
 *
 * Runs against the built .zip artifact (not the source tree) to verify the
 * packaged plugin activates cleanly and doesn't break the core admin surfaces
 * it integrates with. The @smoke tag scopes `playwright test --grep=@smoke`
 * to just these checks; the rest of the suite stays out of the post-build
 * pipeline (it belongs in the pre-build run_e2e_tests job).
 *
 * This plugin is the community / free anti-spam product. It is NOT registered
 * with GravityKit Foundation, so there is no GravityKit licenses card to
 * assert against — that test (present in the licensed-plugin variants) is
 * intentionally omitted here.
 */
const { test, expect } = require('@playwright/test');

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

});
