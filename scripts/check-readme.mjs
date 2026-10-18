// Validates readme.txt the way the wordpress.org readme parser and Plugin Check read it (the parser of
// wordpress.org/plugins/developers/readme-validator and Plugin Check's Plugin_Readme_Check and
// Plugin_Header_Fields_Check), plus the plugin's own rules from docs/17 §8 and §9 in the Qersh repository.
// No dependency. Usage: node scripts/check-readme.mjs [--wp 7.1.3]
//   --wp <version>  the current stable WordPress release; "Tested up to" must then be its major version
//                   (or the next one, as wordpress.org allows). Without it only the format is checked.
// Exit 1 on any error. Warnings are printed and do not fail.
import { existsSync, readFileSync, statSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const README = path.join(root, 'readme.txt');
const MAIN = path.join(root, 'qersh-for-woocommerce.php');
const SLUG = 'qersh-for-woocommerce';
// docs/17 §9 asks for a readme under 10 KB. wordpress.org itself only warns above that (docs/16 §11.4), so this is
// the plugin's own rule: the check fails at MAX_BYTES and warns while fewer than HEADROOM_BYTES are left, so the
// next release trims the readme on purpose (README.md, "Release": readme.txt keeps the newest Changelog entries
// that fit, always the current one, and the current version's Upgrade Notice only; CHANGELOG.md keeps everything).
const MAX_BYTES = 10 * 1024;
const HEADROOM_BYTES = 1024;
const SCREENSHOTS = 6;
// The sections docs/17 §8 asks for, by their parser keys ("External services" is not a parser section: the parser
// appends it to the description as "other notes", which is how wordpress.org shows it).
const REQUIRED_SECTIONS = ['description', 'faq', 'screenshots', 'changelog', 'upgrade_notice'];
const REQUIRED_OTHER = ['External services'];
const REQUIRED_HEADERS = ['contributors', 'tags', 'requires', 'tested', 'requires_php', 'stable_tag', 'license', 'license_uri'];

// --- the wordpress.org parser, as far as its rules decide what the directory shows -------------------------------
const VALID_HEADERS = {
	tested: 'tested', 'tested up to': 'tested', requires: 'requires', 'requires at least': 'requires',
	'requires php': 'requires_php', tags: 'tags', contributors: 'contributors', 'donate link': 'donate_link',
	'stable tag': 'stable_tag', license: 'license', 'license uri': 'license_uri',
};
const HEADER_LABELS = {
	contributors: 'Contributors', tags: 'Tags', requires: 'Requires at least', tested: 'Tested up to', requires_php: 'Requires PHP',
	stable_tag: 'Stable tag', license: 'License', license_uri: 'License URI', donate_link: 'Donate link',
};
const EXPECTED_SECTIONS = ['description', 'installation', 'faq', 'screenshots', 'changelog', 'upgrade_notice', 'other_notes'];
const ALIAS_SECTIONS = { frequently_asked_questions: 'faq', change_log: 'changelog', screenshot: 'screenshots' };
const IGNORE_TAGS = ['plugin', 'wordpress'];
const MAX_LENGTHS = { short_description: 150, section: 2500, 'section-changelog': 5000, 'section-faq': 5000 };
const RESTRICTED_CONTRIBUTORS = ['username', 'your-name', 'your-username', 'your-wordpress-username', 'your_wordpress_username', 'yourusername', 'yourwordpressusername', 'wordpressdotorg'];

const errors = [];
const warnings = [];
const error = (m) => errors.push(m);
const warn = (m) => warnings.push(m);

function possibleHeader(line, onlyValid = false) {
	if (!line.includes(':') || line.startsWith('#') || line.startsWith('=')) return null;
	const i = line.indexOf(':');
	const key = line.slice(0, i).replace(/^[ \t*\-\r\n]+|[ \t*\-\r\n]+$/g, '').toLowerCase();
	const value = line.slice(i + 1).replace(/^[ \t*\-\r\n]+|[ \t*\-\r\n]+$/g, '');
	if (onlyValid && !(key in VALID_HEADERS)) return null;
	return [key, value];
}
const isHeading = (t) => (t[0] === '=' && t[1] === '=') || (t[0] === '#' && t[1] === '#' && t[2] !== '#');

function parse(text) {
	const lines = text.split(/\r\n|\n|\r/);
	const out = { name: '', headers: {}, unknownHeaders: [], short: '', sections: {}, other: [] };
	const firstNonBlank = () => {
		let line;
		while ((line = lines.shift()) !== undefined) if (line.trim()) return line;
		return '';
	};
	let line = firstNonBlank();
	out.nameLine = line;
	out.name = line.replace(/^[#= \t]+|[#= \t]+$/g, '');
	if (possibleHeader(line, true)) {
		lines.unshift(line);
		out.name = false;
	}
	if (lines.length && lines[0].replace(/[=-]/g, '') === '' && lines[0] !== '') lines.shift();
	if (out.name && out.name.toLowerCase() === 'plugin name') out.name = false;

	// Headers: until the first line that is neither a header nor blank (a blank line followed by an unknown
	// "key: value" ends them too, as in the parser).
	line = firstNonBlank();
	let lastBlank = false;
	do {
		const header = possibleHeader(line);
		if (!header) {
			if (!line) {
				lastBlank = true;
				continue;
			}
			break;
		}
		const [key, value] = header;
		if (key in VALID_HEADERS) out.headers[VALID_HEADERS[key]] = value;
		else if (lastBlank) break;
		else out.unknownHeaders.push(key);
		lastBlank = false;
	} while ((line = lines.shift()) !== undefined);
	if (line !== undefined) lines.unshift(line);

	while ((line = lines.shift()) !== undefined) {
		const t = line.trim();
		if (!t) continue;
		if ((t[0] === '=' && t[1] === '=') || (t[0] === '#' && t[1] === '#')) {
			lines.unshift(line);
			break;
		}
		out.short += line + ' ';
	}
	out.short = out.short.trim();

	let name = '';
	let current = '';
	const flush = () => {
		if (name) out.sections[name] = (out.sections[name] || '') + current.trim();
	};
	while ((line = lines.shift()) !== undefined) {
		const t = line.trim();
		if (!t) {
			current += '\n';
			continue;
		}
		if (isHeading(t)) {
			flush();
			current = '';
			const title = line.replace(/^[#= \t]+|[#= \t]+$/g, '');
			name = title.toLowerCase().replace(/ /g, '_');
			if (ALIAS_SECTIONS[name]) name = ALIAS_SECTIONS[name];
			if (!EXPECTED_SECTIONS.includes(name)) {
				out.other.push(title);
				current += `<h3>${title}</h3>`;
				name = 'other_notes';
			}
			continue;
		}
		current += line + '\n';
	}
	flush();
	return out;
}

// "= Question =" blocks of a section (parse_section of the parser, heading style).
function subsections(body) {
	const list = [];
	let key = null;
	let value = '';
	for (const raw of (body || '').split('\n')) {
		const t = raw.trim();
		if (t && (t[0] === '=' || t[0] === '#')) {
			if (key !== null || value.trim()) list.push([key ?? '', value.trim()]);
			key = t.replace(/^[=# \t]+|[=# \t]+$/g, '');
			value = '';
			continue;
		}
		value += raw + '\n';
	}
	if (key !== null || value.trim()) list.push([key ?? '', value.trim()]);
	return list;
}

const words = (s) => (s.trim() ? s.trim().split(/\s+/u).length : 0);
const decode = (s) => s.replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#0?39;/g, "'");
const visible = (s) => decode(s.replace(/<[^>]*>/g, '').replace(/\[([^\]]*)\]\([^)]*\)/g, '$1').replace(/[*_`]/g, '')).trim();

// validate_license() of the parser, reduced to its verdict for the licences a plugin can use.
function licenseVerdict(license) {
	let l = license.toLowerCase().replace(/licence/g, 'license').replace(/(version |v)([0-9])/gi, '$2');
	l = l.replace(/(\s*[^a-z0-9. ]+\s*)/gi, '').replace(/\s+/g, '');
	if (['4clausebsd', 'bsd4clause', 'apache1', 'ccbync', 'ccnc', 'noncommercial', 'ccbynd', 'noderivative', 'eupl', 'osl', 'personaluse', 'proprietary'].some((m) => l.includes(m))) return 'invalid_license';
	if (['gpl', 'generalpubliclicense', 'mit', 'isc', 'expat', 'apache2', 'mpl', 'publicdomain', 'cc0', 'unlicense'].some((m) => l.includes(m))) return true;
	return 'unknown_license';
}
// get_normalized_license() of Plugin Check: "GPLv2 or later" and "GPL-2.0-or-later" both become GPL2.
function normalizedLicense(license) {
	let l = license.trim().replace(/ {2}/g, ' ');
	for (const end of ['.', 'http://www.gnu.org/licenses/old-licenses/gpl-2.0.html', 'https://www.gnu.org/licenses/old-licenses/gpl-2.0.html', 'https://www.gnu.org/licenses/gpl-3.0.html', ' or later', '-or-later', '+']) {
		if (l.endsWith(end)) l = l.slice(0, -end.length).trim();
	}
	l = l.replace(/-/g, '').replace('GNU General Public License (GPL)', 'GPL').replace('GNU General Public License', 'GPL').replace(/ version /g, 'v');
	l = l.replace(/GPL\s*[-|.]*\s*[v]?([0-9])(\.[0])?/i, 'GPL$1').replace(/\./g, '');
	return l;
}
const versionCmp = (a, b) => {
	const pa = a.split('.').map(Number);
	const pb = b.split('.').map(Number);
	for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
		const d = (pa[i] || 0) - (pb[i] || 0);
		if (d) return d < 0 ? -1 : 1;
	}
	return 0;
};

// --- the main plugin file header (get_file_data rules: the first 8 KB, "Name: value" per line) -----------------------
function pluginHeaders(file) {
	const head = readFileSync(file, 'utf8').slice(0, 8192).replace(/\r/g, '\n');
	const fields = {
		Name: 'Plugin Name', PluginURI: 'Plugin URI', Version: 'Version', Description: 'Description', Author: 'Author',
		AuthorURI: 'Author URI', TextDomain: 'Text Domain', DomainPath: 'Domain Path', Network: 'Network', RequiresWP: 'Requires at least',
		RequiresPHP: 'Requires PHP', UpdateURI: 'Update URI', RequiresPlugins: 'Requires Plugins', License: 'License', LicenseURI: 'License URI',
		TestedWP: 'Tested up to', WCRequires: 'WC requires at least', WCTested: 'WC tested up to',
	};
	const out = {};
	for (const [key, label] of Object.entries(fields)) {
		const re = new RegExp(`^(?:[ \\t]*<\\?php)?[ \\t/*#@]*${label.replace(/ /g, ' ')}:(.*)$`, 'mi');
		const m = re.exec(head);
		out[key] = m ? m[1].replace(/\s*(?:\*\/|\?>).*/, '').trim() : '';
	}
	return out;
}

// -----------------------------------------------------------------------------------------------------------------
if (!existsSync(README)) {
	console.error('check-readme: readme.txt does not exist');
	process.exit(1);
}
const buf = readFileSync(README);
const text = buf.toString('utf8');
const size = statSync(README).size;
const TRIM_HINT = 'keep only the current version\'s Upgrade Notice and drop the oldest Changelog entries until it fits (the current one stays, CHANGELOG.md keeps them all)';
if (size >= MAX_BYTES) error(`readme.txt is ${size} bytes; docs/17 §9 asks for less than ${MAX_BYTES} (10 KB, where wordpress.org starts warning): ${TRIM_HINT}`);
else if (MAX_BYTES - size < HEADROOM_BYTES) warn(`readme.txt is ${size} bytes, ${MAX_BYTES - size} below the 10 KB limit; at the next release ${TRIM_HINT}`);
if (buf[0] === 0xef && buf[1] === 0xbb && buf[2] === 0xbf) error('readme.txt starts with a UTF-8 byte order mark');
if (/\r/.test(text)) warn('readme.txt has CR line endings; the repository uses LF');
if (!/^===\s*[^=].*===\s*$/m.test(text.split(/\n/).find((l) => l.trim()) || '')) error('the first line must be the plugin name header: === Qersh for WooCommerce ===');

const r = parse(text);
const main = pluginHeaders(MAIN);
const mainSource = readFileSync(MAIN, 'utf8');

// Name.
if (!r.name) error('the plugin name header is missing or invalid (=== Example Name ===)');
else if (r.name !== main.Name) error(`readme name "${r.name}" differs from the plugin header name "${main.Name}"`);

// Headers.
for (const key of r.unknownHeaders) error(`unknown readme header "${key}"; the parser knows ${Object.values(HEADER_LABELS).join(', ')}`);
for (const key of REQUIRED_HEADERS) if (!r.headers[key]) error(`the "${HEADER_LABELS[key]}" header is missing`);
const h = r.headers;

if (h.contributors) {
	const users = h.contributors.split(',').map((s) => s.trim()).filter(Boolean);
	for (const u of users) {
		if (!/^[a-z0-9_.\-@ ]+$/i.test(u)) error(`Contributors: "${u}" is not a wordpress.org username`);
		else if (RESTRICTED_CONTRIBUTORS.includes(u.toLowerCase())) error(`Contributors: "${u}" is a placeholder or restricted username`);
		else if (u !== u.toLowerCase()) warn(`Contributors: "${u}" has capitals; wordpress.org usernames in readmes are lower case`);
	}
	warnings.push(`Contributors: ${users.join(', ')} (must be the owner's wordpress.org username before submitting)`);
}

let tags = [];
if (h.tags) {
	tags = h.tags.split(',').map((s) => s.trim()).filter(Boolean);
	const ignored = tags.filter((t) => IGNORE_TAGS.includes(t.toLowerCase()));
	if (ignored.length) error(`Tags: ${ignored.join(', ')} are ignored by wordpress.org`);
	if (tags.length > 5) error(`Tags: ${tags.length} tags; wordpress.org keeps the first 5 (${tags.slice(5).join(', ')} would be dropped)`);
	if (new Set(tags.map((t) => t.toLowerCase())).size !== tags.length) error('Tags: a tag is listed twice');
	if (tags.includes('tag1')) error('Tags: default text "tag1"');
}

const WP_VERSION = /^\d+\.\d(\.\d+)?$/;
if (h.requires) {
	if (!WP_VERSION.test(h.requires)) error(`Requires at least: "${h.requires}" is not a WordPress version such as 6.9`);
	if (main.RequiresWP && h.requires !== main.RequiresWP) error(`Requires at least: readme ${h.requires} != plugin header ${main.RequiresWP}`);
}
if (h.requires_php) {
	if (!/^\d+(\.\d+){1,2}$/.test(h.requires_php)) error(`Requires PHP: "${h.requires_php}" is not a PHP version such as 7.4`);
	if (main.RequiresPHP && h.requires_php !== main.RequiresPHP) error(`Requires PHP: readme ${h.requires_php} != plugin header ${main.RequiresPHP}`);
}
if (h.tested) {
	const tested = h.tested.replace(/WordPress|WP/gi, '').trim().split('-')[0];
	if (!WP_VERSION.test(tested)) error(`Tested up to: "${h.tested}" is not a WordPress version`);
	else if (!/^\d+\.\d$/.test(tested)) error(`Tested up to: "${h.tested}" must name the major version only (${tested.split('.').slice(0, 2).join('.')}), not a minor release`);
	if (h.requires && WP_VERSION.test(tested) && versionCmp(tested, h.requires) < 0) error(`Tested up to ${tested} is lower than Requires at least ${h.requires}`);
	const wpIndex = process.argv.indexOf('--wp');
	const current = wpIndex > 0 ? process.argv[wpIndex + 1] : null;
	if (current && WP_VERSION.test(current)) {
		const major = current.split('.').slice(0, 2).join('.');
		const next = (Math.round((Number(major) + 0.1) * 10) / 10).toFixed(1);
		if (versionCmp(tested, major) < 0) error(`Tested up to ${tested} < ${major}: the plugin would be hidden from searches`);
		else if (versionCmp(tested, next) > 0) error(`Tested up to ${tested}: WordPress ${tested} does not exist yet (current ${current})`);
	}
	if (main.TestedWP && main.TestedWP.split('-')[0] !== tested) error(`Tested up to: readme ${tested} != plugin header ${main.TestedWP}`);
}

if (h.stable_tag) {
	const tag = h.stable_tag.replace(/^["']|["']$/g, '').replace(/^\/?tags\//i, '').replace(/[^a-z0-9_.-]/gi, '');
	const constant = /define\(\s*'QERSH_WC_VERSION'\s*,\s*'([^']+)'\s*\)/.exec(mainSource);
	if (tag === 'trunk') error('Stable tag: trunk is not allowed; use the version');
	else if (tag !== h.stable_tag) error(`Stable tag: "${h.stable_tag}" is read as "${tag}"`);
	if (tag !== main.Version) error(`Stable tag ${tag} != Version ${main.Version} in qersh-for-woocommerce.php`);
	if (!constant || constant[1] !== tag) error(`Stable tag ${tag} != QERSH_WC_VERSION ${constant ? constant[1] : '(not found)'}`);
}

if (h.license) {
	const verdict = licenseVerdict(h.license);
	if (verdict !== true) error(`License: "${h.license}" is ${verdict === 'invalid_license' ? 'not GPL-compatible' : 'not a licence wordpress.org recognizes'}`);
	if (main.License && normalizedLicense(h.license) !== normalizedLicense(main.License)) error(`License: readme "${h.license}" != plugin header "${main.License}"`);
}
if (h.license_uri) {
	if (!/^https?:\/\/\S+$/.test(h.license_uri)) error(`License URI: "${h.license_uri}" is not a URL`);
	if (main.LicenseURI && h.license_uri !== main.LicenseURI) error(`License URI: readme ${h.license_uri} != plugin header ${main.LicenseURI}`);
}
if (h.donate_link && !/^https?:\/\/\S+$/.test(h.donate_link)) error('Donate link: not a URL');

// Short description.
const shortText = visible(r.short);
const shortLength = [...shortText].length;
if (!r.short) error('the short description (the line after the headers) is missing');
else if (shortLength > MAX_LENGTHS.short_description) error(`the short description has ${shortLength} characters; wordpress.org cuts it at ${MAX_LENGTHS.short_description}`);
if (shortText.includes('Here is a short description of the plugin.')) error('the short description is default text');

// Sections.
for (const s of REQUIRED_SECTIONS) if (!r.sections[s]) error(`the "${s.replace(/_/g, ' ')}" section is missing or empty`);
for (const title of REQUIRED_OTHER) if (!r.other.includes(title)) error(`the "== ${title} ==" section is missing`);
for (const [name, body] of Object.entries(r.sections)) {
	// The parser appends the other notes to the description before it counts words.
	const counted = name === 'description' && r.sections.other_notes ? body + '\n' + r.sections.other_notes : body;
	if (name === 'other_notes' || name === 'upgrade_notice') continue;
	const limit = MAX_LENGTHS[`section-${name}`] || MAX_LENGTHS.section;
	const n = words(counted);
	if (n >= limit) error(`the "${name}" section has ${n} words; wordpress.org cuts it at ${limit}`);
}

const version = h.stable_tag || main.Version;
const changelog = subsections(r.sections.changelog);
if (!changelog.some(([v, body]) => v === version && body)) error(`the Changelog has no "= ${version} =" entry`);
const notices = subsections(r.sections.upgrade_notice);
if (!notices.some(([v, body]) => v === version && body)) error(`the Upgrade Notice has no "= ${version} =" entry`);
for (const [v, body] of notices) if (visible(body).length > 300) error(`the upgrade notice for ${v || '(no version)'} has more than 300 characters`);

const faq = subsections(r.sections.faq).filter(([q]) => q);
const questions = new Set();
for (const [q, a] of faq) {
	if (!a) error(`FAQ: "${q}" has no answer`);
	if (questions.has(q.toLowerCase())) error(`FAQ: "${q}" is asked twice`);
	questions.add(q.toLowerCase());
}
if (!faq.length) error('FAQ: no "= Question =" entries');

const shots = (r.sections.screenshots || '').split('\n').map((l) => /^\s*(\d+)\.\s+\S/.exec(l)).filter(Boolean).map((m) => Number(m[1]));
if (shots.length !== SCREENSHOTS) error(`Screenshots: ${shots.length} captions; docs/17 §8 asks for ${SCREENSHOTS}`);
shots.forEach((n, i) => {
	if (n !== i + 1) error(`Screenshots: caption ${i + 1} is numbered ${n}; screenshot-N.png files are matched by their order`);
});

// English only (docs/17 §8): no Arabic script, except the name قرش where it is needed.
text.split('\n').forEach((line, i) => {
	const rest = line.replace(/قرش/g, '');
	if (/[\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF\uFB50-\uFDFF\uFE70-\uFEFF]/u.test(rest)) error(`line ${i + 1}: readme.txt must be in English (docs/17 §8)`);
});
if (/\brecognis/i.test(text)) error('use US English spelling ("recognize", not "recognise")');

// Promises the code does not keep, which this readme once made (docs/17 §4.3, §4.6 and rule 5):
// - the daily retention (Store\Transfers::purge_personal) only erases the payer's number and name of a transfer that
//   paid no order; it never deletes the transfer itself, so no sentence about transfers may say "deleted after N days";
// - a transfer that arrived before its order was placed is a personal transfer: it does not wait in "Needs a decision"
//   and is not counted in the badge, so a sentence about it may not say it waits there.
for (const sentence of text.split(/(?<=[.!?])\s+|\n/)) {
	const quote = sentence.trim().slice(0, 90);
	if (/\btransfers?\b/i.test(sentence) && /\b(?:deleted|removed|purged)\s+after\s+\d+\s+days?\b/i.test(sentence)) {
		error(`"${quote}": transfers are never deleted; the retention erases only the payer's number and name of a transfer that paid no order`);
	}
	if (/\bbefore\s+(?:its|the|their)\s+order\b|\bbefore\s+you\s+created\s+the\s+order\b/i.test(sentence) && /\bwaits?\b|Needs a decision/i.test(sentence)) {
		error(`"${quote}": a transfer that arrived before its order is filed under "Personal transfers", not in "Needs a decision"`);
	}
}

// The main file header (docs/17 §9 and Plugin_Header_Fields_Check).
if (main.Name !== 'Qersh for WooCommerce') error(`plugin header: Plugin Name is "${main.Name}"`);
if (!main.Description) error('plugin header: Description is missing');
else if ([...main.Description].length >= 140) error(`plugin header: Description has ${[...main.Description].length} characters; keep it under 140`);
if (!/^\d+(\.\d+)*$/.test(main.Version)) error(`plugin header: Version "${main.Version}" must be digits and dots`);
if (!main.PluginURI || !main.AuthorURI || main.PluginURI === main.AuthorURI) error('plugin header: Plugin URI and Author URI must both be set and differ');
if (main.Author !== 'Qersh') error(`plugin header: Author is "${main.Author}", docs/17 §9 says Qersh`);
if (main.TextDomain !== SLUG) error(`plugin header: Text Domain is "${main.TextDomain}", must be ${SLUG}`);
if (main.DomainPath !== '/languages') error(`plugin header: Domain Path is "${main.DomainPath}", must be /languages`);
else if (!existsSync(path.join(root, 'languages'))) warn('plugin header: Domain Path /languages does not exist yet (Plugin Check warns plugin_header_nonexistent_domain_path, and the package must ship it)');
if (!/(^|,)\s*woocommerce\s*(,|$)/.test(main.RequiresPlugins)) error('plugin header: Requires Plugins must list woocommerce');
if (!main.WCRequires || !main.WCTested) error('plugin header: WC requires at least and WC tested up to are both needed');
if (main.UpdateURI) error('plugin header: no Update URI on wordpress.org');
if (main.Network) error('plugin header: no Network header (docs/17 §9)');
if (!main.License) error('plugin header: License is missing');
if (main.RequiresWP && !WP_VERSION.test(main.RequiresWP)) error(`plugin header: Requires at least "${main.RequiresWP}" is not a version`);

for (const w of warnings) console.log(`warning: ${w}`);
if (errors.length) {
	console.error(`check-readme: ${errors.length} error(s)\n` + errors.map((e) => `error: ${e}`).join('\n'));
	process.exit(1);
}
console.log(`check-readme: ok (${size} bytes, short description ${shortLength} characters, ${tags.length} tags, ${faq.length} FAQ entries, ${shots.length} screenshots, Stable tag ${version})`);
