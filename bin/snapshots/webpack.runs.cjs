/**
 * Dev-only: bundles the Runs React app WITH its @wordpress/* packages (no externals)
 * so it can mount in a plain static page for the UI snapshots. Never shipped.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const root = path.resolve( __dirname, '../..' );

module.exports = {
	...defaultConfig,
	mode: 'production',
	devtool: false,
	entry: { 'runs-bundle': path.resolve( __dirname, 'runs-entry.js' ) },
	output: { path: path.resolve( root, 'build/ui-snapshots' ), filename: '[name].js' },
	plugins: defaultConfig.plugins.filter( ( plugin ) => ! /DependencyExtraction/.test( plugin.constructor.name ) ),
	externals: {},
	optimization: { ...defaultConfig.optimization, splitChunks: false, runtimeChunk: false },
};
