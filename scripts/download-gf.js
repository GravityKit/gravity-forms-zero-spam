#!/usr/bin/env node
/**
 * Downloads the latest Gravity Forms plugin zip into .tmp/gravityforms
 * and updates .env so wp-env mounts it.
 *
 * Requires the GitHub CLI (`gh`) authenticated against an account with
 * access to the gravityforms/gravityforms repository, OR set
 * GRAVITYFORMS_GH_TOKEN in the environment.
 *
 * Run: npm run tests:e2e:download-gf
 */

const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const repoRoot = path.resolve(__dirname, '..');
const tmpDir = path.join(repoRoot, '.tmp');
const targetDir = path.join(tmpDir, 'gravityforms');
const envPath = path.join(repoRoot, '.env');

function run(cmd, opts = {}) {
    return execSync(cmd, { stdio: 'inherit', cwd: repoRoot, ...opts });
}

function ensureGhAuth() {
    try {
        execSync('gh auth status', { stdio: 'ignore' });
    } catch {
        if (!process.env.GRAVITYFORMS_GH_TOKEN && !process.env.GITHUB_TOKEN) {
            console.error(
                'gh is not authenticated and neither GRAVITYFORMS_GH_TOKEN nor GITHUB_TOKEN is set.\n' +
                    'Run `gh auth login` or export a token with access to gravityforms/gravityforms.'
            );
            process.exit(1);
        }
    }
}

function updateEnvWpPlugins(absoluteGfPath) {
    let env = fs.existsSync(envPath) ? fs.readFileSync(envPath, 'utf8') : '';
    const line = `WP_ENV_PLUGINS=${absoluteGfPath}`;

    if (/^WP_ENV_PLUGINS=.*$/m.test(env)) {
        env = env.replace(/^WP_ENV_PLUGINS=.*$/m, line);
    } else {
        env += (env.endsWith('\n') || env === '' ? '' : '\n') + line + '\n';
    }

    fs.writeFileSync(envPath, env);
    console.log(`Updated ${envPath}: ${line}`);
}

function main() {
    ensureGhAuth();

    fs.mkdirSync(tmpDir, { recursive: true });

    if (fs.existsSync(targetDir)) {
        fs.rmSync(targetDir, { recursive: true, force: true });
    }

    console.log('Downloading latest gravityforms release...');
    run(
        `gh release download -R gravityforms/gravityforms --clobber --pattern "*.zip" --dir "${tmpDir}"`
    );

    const zip = fs
        .readdirSync(tmpDir)
        .filter((f) => /^gravityforms.*\.zip$/i.test(f))
        .map((f) => path.join(tmpDir, f))
        .sort((a, b) => fs.statSync(b).mtimeMs - fs.statSync(a).mtimeMs)[0];

    if (!zip) {
        console.error('No gravityforms*.zip found after download.');
        process.exit(1);
    }

    console.log(`Unzipping ${path.basename(zip)}...`);
    run(`unzip -o -q "${zip}" -d "${tmpDir}"`);

    if (!fs.existsSync(targetDir)) {
        console.error(`Expected ${targetDir} to exist after unzip.`);
        process.exit(1);
    }

    if (fs.existsSync(envPath)) {
        updateEnvWpPlugins(targetDir);
    } else {
        console.warn(
            `.env not found. Copy .env.sample to .env, then re-run, or set WP_ENV_PLUGINS=${targetDir} manually.`
        );
    }

    console.log('Done.');
}

main();
