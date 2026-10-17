// Writes the wp-env override file of one CI job (never committed: .wp-env.override.json and
// .wp-env.test.override.json are in .gitignore), from the base config:
//   node tests/e2e/wp-env-override.mjs [--config .wp-env.json] [--php 8.2] [--core <zip url>] [--wc <version>]
//                                      [--mu-finish] [--mu-mail] [--disable-rest]
// --mu-finish     maps tests/e2e/mu/qersh-test-finish-request.php as a must-use plugin (fastcgi_finish_request()).
// --mu-mail       maps tests/e2e/mu/qersh-test-mail.php as a must-use plugin (the mail log, and the 5-second mail that
//                 deliveries.spec.mjs turns on through tests/e2e/setup/mail.php).
// --disable-rest  adds the "Disable REST API" plugin from wordpress.org (the fallback addresses are then the only
//                 way in for the phone and the order page).
// wp-env merges only `config` and `mappings` of an override; `plugins` replaces the base list, so it is written whole.
import { readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const args = process.argv.slice(2);
const flag = (name, fallback = null) => {
	const i = args.indexOf(name);
	return i >= 0 && i + 1 < args.length && !args[i + 1].startsWith('--') ? args[i + 1] : fallback;
};
const has = (name) => args.includes(name);

const configName = flag('--config', '.wp-env.json');
const base = JSON.parse(readFileSync(path.join(root, configName), 'utf8'));
const override = {};

if (flag('--php')) override.phpVersion = flag('--php');
if (flag('--core')) override.core = flag('--core');

let plugins = Array.isArray(base.plugins) ? [...base.plugins] : [];
if (flag('--wc')) {
	plugins = plugins.filter((p) => !/\/woocommerce(\.[0-9][^/]*)?\.zip$/.test(p));
	plugins.unshift(`https://downloads.wordpress.org/plugin/woocommerce.${flag('--wc')}.zip`);
}
if (has('--disable-rest')) plugins.push('https://downloads.wordpress.org/plugin/disable-json-api.zip');
if (flag('--wc') || has('--disable-rest')) override.plugins = plugins;

const mappings = {};
if (has('--mu-finish')) mappings['wp-content/mu-plugins/qersh-test-finish-request.php'] = './tests/e2e/mu/qersh-test-finish-request.php';
if (has('--mu-mail')) mappings['wp-content/mu-plugins/qersh-test-mail.php'] = './tests/e2e/mu/qersh-test-mail.php';
if (Object.keys(mappings).length) override.mappings = mappings;

const ext = path.extname(configName);
const out = path.join(root, configName.slice(0, configName.length - ext.length) + '.override' + ext);
writeFileSync(out, JSON.stringify(override, null, '\t') + '\n');
console.log(`${path.relative(root, out)}: ${JSON.stringify(override)}`);
