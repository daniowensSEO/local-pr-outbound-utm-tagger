// Runs the shared parity cases through the real click-mode script
// (assets/outbound-utm-click.js) in a sandbox with a minimal fake DOM, by simulating
// a click on each link and reading back its href. Exits 1 on any mismatch.
//
// Usage: node tests/parity/run-js.mjs

import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const here = new URL('.', import.meta.url);
const script = readFileSync(new URL('../../assets/outbound-utm-click.js', here), 'utf8');
const { site, cases } = JSON.parse(readFileSync(new URL('cases.json', here), 'utf8'));

// Same order the PHP side builds the query in (Outbound_UTM_Settings::fields()).
const FIELDS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

function runCase(c) {
	const params = {};
	for (const f of FIELDS) {
		if (c.fields[f]) {
			params[f] = c.fields[f];
		}
	}

	const handlers = {};
	const sandbox = {
		URL,
		console,
		location: { hostname: site },
		document: {
			baseURI: `https://${site}/page/`,
			addEventListener(type, fn) {
				handlers[type] = fn;
			},
		},
		outboundUtmConfig: {
			params,
			internal: [site],
			exclude: c.skip,
			skipClass: 'noutm',
			tidy: c.tidy ? 1 : 0,
		},
	};
	sandbox.window = sandbox;
	vm.runInNewContext(script, sandbox);

	const attrs = { href: c.href };
	const link = {
		textContent: c.text,
		getAttribute: (name) => attrs[name],
		setAttribute: (name, value) => {
			attrs[name] = value;
		},
		// The link itself answers 'a[href]'; nothing wraps it in a .noutm block.
		closest: (selector) => (selector === 'a[href]' ? link : null),
	};
	handlers.click({ target: link });

	return attrs.href === c.href ? null : attrs.href;
}

let failed = 0;
for (const c of cases) {
	const got = runCase(c);
	if (got === c.expected) {
		console.log(`ok    ${c.name}`);
	} else {
		failed++;
		console.log(`FAIL  ${c.name}\n      expected: ${c.expected}\n      got:      ${got}`);
	}
}
console.log(`\nJS mode: ${cases.length - failed}/${cases.length} parity cases pass`);
process.exit(failed ? 1 : 0);
