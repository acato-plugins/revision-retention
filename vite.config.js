/**
 * Builds the settings screen's script and stylesheet into dist/.
 *
 * The script and the stylesheet are separate entries, so the stylesheet is a
 * file of its own that loads whether or not the script runs. Both come out
 * minified, under hashed names; the plugin finds them through the manifest,
 * so the hash is what busts the browser cache.
 */
import { defineConfig } from 'vite';

export default defineConfig( {
	// The files are loaded by WordPress, not by an HTML page, so there is no
	// public folder to copy and no base path to rewrite.
	base: './',
	publicDir: false,
	build: {
		outDir: 'dist',
		emptyOutDir: true,
		// Everything flat in dist/, the manifest included, rather than in an
		// assets/ folder and a hidden .vite/ one: the plugin ships through SVN,
		// where a dot folder is easily lost or ignored.
		assetsDir: '',
		manifest: 'manifest.json',
		// The script imports nothing and exports nothing, so the module build
		// is plain code that runs as the classic script WordPress loads.
		rollupOptions: {
			// Listed rather than named, so each output takes its source's name:
			// settings-<hash>.js and settings-<hash>.css.
			input: [ 'src/settings.js', 'src/settings.css' ],
		},
	},
} );
