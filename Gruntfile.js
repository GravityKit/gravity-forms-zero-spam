module.exports = function ( grunt ) {
	'use strict';

	grunt.initConfig( {
		addtextdomain: {
			options: {
				textdomain: 'gravity-forms-zero-spam',
				updateDomains: []
			},
			target: {
				files: {
					src: [
						'*.php',
						'includes/**/*.php'
					]
				}
			}
		},

		exec: {
			makepot: {
				cmd: function () {
					var fileComments = [
						'Copyright (C) ' + new Date().getFullYear() + ' GravityKit',
						'This file is distributed under the GPLv2 or later',
					];

					var headers = {
						'Last-Translator': 'GravityKit <support@gravitykit.com>',
						'Language-Team': 'GravityKit <support@gravitykit.com>',
						'Language': 'en_US',
						'Plural-Forms': 'nplurals=2; plural=(n != 1);',
						'Report-Msgid-Bugs-To': 'https://www.gravitykit.com/support',
					};

					var command = 'wp i18n make-pot --exclude=dist,tests,vendor . translations.pot';

					command += ' --file-comment="' + fileComments.join( '\n' ) + '"';

					command += ' --headers=\'' + JSON.stringify( headers ) + '\'';

					return command;
				}
			}
		}
	} );

	grunt.loadNpmTasks( 'grunt-exec' );
	grunt.loadNpmTasks( 'grunt-wp-i18n' );

	grunt.registerTask( 'default', [ 'addtextdomain', 'exec:makepot' ] );
};
