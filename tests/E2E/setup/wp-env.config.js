const { generateWpEnvConfig } = require('@gravitykit/e2e-bootstrap');

generateWpEnvConfig({
    outputDir: __dirname,
    pluginPath: '../../..',
    // The loader stub is a stable file-level mount; the directory it requires
    // contains the actual implementation. This survives editor atomic rewrites,
    // which would otherwise break inode-pinned file-level Docker bind mounts.
    additionalMappings: {
        'wp-content/mu-plugins/zs-e2e-loader.php': './zs-e2e-loader.php',
        'wp-content/mu-plugins/zs-e2e': '../mu-plugins',
    },
}).catch((err) => {
    console.error(err);
    process.exit(1);
});
