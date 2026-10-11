#!/usr/bin/env python3
"""Watches the plugin from the outside and opens a GitHub issue, assigned to the owner, when something
needs her. GitHub emails her about each one.

- WordPress versions: a release candidate of the next major, or a released major, that the plugin's
  "Tested up to" (as WordPress.org shows it) doesn't cover. Release candidate is the first point
  WordPress.org allows "Tested up to" to name the new version, so betas don't alert. The issue closes
  itself once WordPress.org shows the bump. Also: WordPress.org discarding the readme's value.
- WordPress.org listing: the plugin closed or gone.
- WordPress.org forum: a new support topic or review, and new replies from anyone but the plugin author
  (a topic she started herself alerts once someone else replies). A topic's issue closes itself when the
  topic is marked resolved; a review's when the author's reply is the latest post.
- GitHub: an issue or pull request opened by someone else gets the owner assigned, so she is emailed
  about it and about every later comment. Once the repository is public, a warning before GitHub's
  60-day switch-off of scheduled workflows would stop this monitor.
- Dated reminders listed in config.json.

Each alert is one issue labeled `monitor`, found again on later runs by the hidden key in its body, so
a run never opens a duplicate; small per-alert state (reply counts, the post ids seen) is kept in the
body too. A check that fails (after retries) fails the run and opens a "monitor is failing" issue,
closed by the next clean run, so a broken monitor doesn't go quiet. While that issue is open, runs
pass, so GitHub's own failure emails don't repeat on top of it.

Forum text is written by anyone, so it is treated as untrusted. Alerts link to posts and never quote
them (a quote would stay in the issue and its edit history, and be republished if the repository went
public); the titles and names they show are cleaned, sent as JSON through the API, and names go in
inline code (no mentions, links or HTML). The links in alerts are rebuilt from slugs that passed a
strict pattern. Nothing here runs a shell.

Standard library only, and it writes no files: everything goes to stdout. MONITOR_DRY_RUN=1 prints what
would happen and changes nothing on GitHub (without a GITHUB_TOKEN it also assumes no alerts exist yet).
"""

import datetime as dt
import email.utils
import html
import json
import os
import re
import sys
import time
import unicodedata
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET

HERE = os.path.dirname(os.path.abspath(__file__))
with open(os.path.join(HERE, 'config.json'), encoding='utf-8') as _f:
    CONFIG = json.load(_f)

SLUG = CONFIG['slug']
AUTHOR = CONFIG['wporg_author']
OWNER = CONFIG['owner_login']
SINCE = dt.datetime.fromisoformat(CONFIG['forum_since']).replace(tzinfo=dt.timezone.utc)
PLUGIN_PAGE = 'https://wordpress.org/plugins/%s/' % SLUG
FORUM = 'https://wordpress.org/support/plugin/%s/' % SLUG
INFO_URL = ('https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%%5Bslug%%5D=%s'
            '&request%%5Bfields%%5D%%5Bsections%%5D=0&request%%5Bfields%%5D%%5Bversions%%5D=0'
            '&request%%5Bfields%%5D%%5Bscreenshots%%5D=0' % SLUG)
VERSION_CHECK = 'https://api.wordpress.org/core/version-check/1.7/'
RELEASE_NEWS = 'https://wordpress.org/news/category/releases/feed/'

REPO = os.environ.get('GITHUB_REPOSITORY', 'daniowensSEO/local-pr-outbound-utm-tagger')
TOKEN = os.environ.get('GITHUB_TOKEN', '')
DRY_RUN = os.environ.get('MONITOR_DRY_RUN', '') not in ('', '0', 'false')
API = 'https://api.github.com'
BOT = 'github-actions[bot]'
USER_AGENT = 'local-pr-outbound-utm-tagger-monitor/1.0 (+https://github.com/daniowensSEO/local-pr-outbound-utm-tagger)'

LABEL = 'monitor'
LABELS = {
    'monitor': ('5319e7', 'Opened by the plugin monitor'),
    'wordpress-version': ('0075ca', 'A WordPress release the plugin should be tested with'),
    'wporg-support': ('d876e3', 'A WordPress.org support topic'),
    'wporg-review': ('fbca04', 'A WordPress.org review'),
    'plugin-status': ('b60205', 'The WordPress.org listing itself'),
    'reminder': ('c5def5', 'A dated reminder'),
}

KEY_RE = re.compile(r'<!-- monitor-key: ([a-z0-9._%-]+) -->')
STATE_RE = re.compile(r'<!-- monitor-state: (\{.*?\}) -->')
# Topic slugs are lowercase letters, digits, "-" and "_"; anything else (accents, other scripts) is %-encoded.
TOPIC_URL_RE = re.compile(r'^https://wordpress\.org/support/topic/((?:[a-z0-9_-]|%[0-9a-f]{2}){1,200})/?(?:[#?].*)?$', re.I)
REPLIES_RE = re.compile(r'Replies:\s*(\d+)')
RATING_RE = re.compile(r'Rating:\s*(\d)\s*stars?', re.I)
TITLE_RATING_RE = re.compile(r'\((\d) stars?\)\s*$', re.I)
# The Markdown version of a topic page. Each reply starts " *  [Name](profile)", " * (@username)",
# " * [when](topic link #post-<id>)"; the opening post's link has no #post, and it comes first, before
# any post text. After the posts comes a sidebar that begins with links to the plugin's pages (the last
# is its reviews) and ends " * N replies", " * Last reply from: [Name](profile)", " * Last activity:
# [when](link to the last post)", " * Status: ..." (topics only). Posts are anyone's text and can
# imitate any of this, so the sidebar's lines are only read after its reviews link.
MD_SIDEBAR = '](%sreviews' % FORUM
MD_POST_RE = re.compile(r'^ \* \(@([a-z0-9_.@%-]+)\)\n \* \[[^\]\n]*\]\(https://wordpress\.org/support/topic/'
                        r'[^)\s]*#post-(\d+)\)$', re.M | re.I)
MD_STARTER_RE = re.compile(r'^ \* \(@([a-z0-9_.@%-]+)\)\n \* \[[^\]\n]*\]\(https://wordpress\.org/support/topic/'
                           r'[^)\s#]*\)$', re.M | re.I)
# "Viewing 8 replies - 1 through 8 (of 8 total)", "Viewing 1 replies (of 1 total)"; a long topic's later
# pages say "- 16 through 30" and so on.
MD_VIEWING_RE = re.compile(r'^Viewing \d+ repl(?:y|ies)(?: - \d+ through (\d+))? \(of (\d+) total\)$', re.M)
MD_LAST_BY_RE = re.compile(r'^ \* Last reply from: .*\]\(https://wordpress\.org/support/users/([a-z0-9_.@%-]+)/?\)$',
                           re.M | re.I)
MD_LAST_POST_RE = re.compile(r'^ \* Last activity: .*\]\(https://wordpress\.org/support/topic/[^)\s]*?(?:#post-(\d+))?\)$',
                             re.M)
MD_STATUS_RE = re.compile(r'^ \* Status: (resolved|not resolved|not a support question)$', re.M | re.I)
PRE_RE = re.compile(r'(\d+)\.(\d+)-(beta|rc)(\d+)', re.I)
NEWS_RE = re.compile(r'(\d+)\.(\d+)\s+(beta|release candidate|rc)\s*(\d+)', re.I)
DC = '{http://purl.org/dc/elements/1.1/}'

# Seconds between requests to the same WordPress.org host: these are shared community servers.
PACE = {'wordpress.org': 2.0, 'api.wordpress.org': 1.0}
_last_hit = {}


def log(msg):
    print(msg, flush=True)


# ---------------------------------------------------------------- fetching

def _pace(url):
    host = urllib.parse.urlsplit(url).hostname or ''
    gap = PACE.get(host)
    if gap:
        wait = _last_hit.get(host, 0) + gap - time.monotonic()
        if wait > 0:
            time.sleep(wait)
        _last_hit[host] = time.monotonic()


def fetch(url, accept='text/plain', data=None, method=None, headers=None, attempts=3):
    """GET (or another method) with retries on network errors, 429 and 5xx. Returns (status, bytes)."""
    hdrs = {'User-Agent': USER_AGENT, 'Accept': accept}
    hdrs.update(headers or {})
    delay = 5
    for attempt in range(1, attempts + 1):
        _pace(url)
        req = urllib.request.Request(url, data=data, method=method, headers=hdrs)
        try:
            with urllib.request.urlopen(req, timeout=30) as resp:
                return resp.status, resp.read()
        except urllib.error.HTTPError as e:
            body = e.read()
            if e.code in (429, 500, 502, 503, 504) and attempt < attempts:
                time.sleep(delay)
                delay *= 3
                continue
            return e.code, body
        except (urllib.error.URLError, TimeoutError, ConnectionError) as e:
            if attempt < attempts:
                time.sleep(delay)
                delay *= 3
                continue
            raise RuntimeError(f'{url}: {e}') from None
    raise RuntimeError(f'{url}: gave up')


def fetch_json(url):
    status, body = fetch(url, accept='application/json')
    if status != 200:
        raise RuntimeError(f'{url}: HTTP {status}')
    return json.loads(body)


def parse_feed(xml_bytes):
    """RSS items as dicts: title, link, slug (None unless the link is a forum topic), author (a display
    name, not a username), date (the topic's creation time, even in the activity feed), text (the HTML
    description), replies (its "Replies: N", or None)."""
    root = ET.fromstring(xml_bytes)
    if root.tag != 'rss' or root.find('channel') is None:
        # An HTML challenge page that happens to parse must not read as "no topics".
        raise ET.ParseError(f'root element <{root.tag}>, not an RSS channel')
    items = []
    for item in root.iter('item'):
        def txt(tag):
            el = item.find(tag)
            return (el.text or '') if el is not None else ''
        link = txt('link').strip()
        m = TOPIC_URL_RE.match(link)
        desc = txt('description')
        replies = REPLIES_RE.search(clean(desc))
        try:
            date = email.utils.parsedate_to_datetime(txt('pubDate'))
        except (TypeError, ValueError):
            date = None
        items.append({
            'title': txt('title'), 'link': link, 'slug': m.group(1).lower() if m else None,
            'author': txt(DC + 'creator'), 'date': date, 'text': desc,
            'replies': int(replies.group(1)) if replies else None,
        })
    return items


def feed(url):
    status, body = fetch(url, accept='application/rss+xml, application/xml')
    if status != 200:
        raise RuntimeError(f'{url}: HTTP {status}')
    try:
        return parse_feed(body)
    except ET.ParseError as e:
        raise RuntimeError(f'{url}: not a feed ({e})') from None


# ---------------------------------------------------------------- untrusted text

def clean(text, limit=None):
    """Plain text from feed HTML: tags dropped, entities decoded, invisible and control characters
    removed, runs of whitespace collapsed."""
    text = re.sub(r'<[^>]*>', ' ', text or '')
    text = html.unescape(text)
    # Control and format characters (Unicode Cc, Cf): zero-width ones, direction overrides and the like.
    text = ''.join(ch for ch in text if ch in '\t\n\r' or unicodedata.category(ch) not in ('Cc', 'Cf'))
    text = re.sub(r'\s+', ' ', text).strip()
    if limit and len(text) > limit:
        text = text[: limit - 1].rstrip() + '…'
    return text


def inline(text, limit=80):
    """A short untrusted string (a name) as inline code."""
    text = clean(text, limit).replace('`', "'")
    return f'`{text}`' if text else '`(unknown)`'


def title_text(text, limit=90):
    return clean(text, limit) or '(no title)'


# ---------------------------------------------------------------- GitHub

def gh(method, path, payload=None, ok=(200, 201, 204)):
    data = json.dumps(payload).encode() if payload is not None else None
    status, body = fetch(
        API + path, accept='application/vnd.github+json', data=data, method=method,
        headers={'Authorization': f'Bearer {TOKEN}', 'X-GitHub-Api-Version': '2022-11-28',
                 'Content-Type': 'application/json'},
        # A POST that timed out may still have worked: sending it again could open a second issue.
        attempts=1 if method == 'POST' else 3,
    )
    if status not in ok:
        raise RuntimeError(f'GitHub {method} {path}: HTTP {status} {body[:300]!r}')
    return json.loads(body) if body else None


def gh_pages(path):
    items, page = [], 1
    while True:
        sep = '&' if '?' in path else '?'
        batch = gh('GET', f'{path}{sep}per_page=100&page={page}')
        items.extend(batch)
        if len(batch) < 100:
            return items
        page += 1


class Alerts:
    """The monitor's issues, by key. All writes go through here so dry runs can skip them."""

    def __init__(self):
        self.by_key = {}
        self.actions = 0
        if not TOKEN:
            if not DRY_RUN:
                raise RuntimeError('GITHUB_TOKEN is not set')
            log('(dry run without a token: assuming no monitor issues exist yet)')
            return
        for issue in gh_pages(f'/repos/{REPO}/issues?state=all&labels={LABEL}'):
            # Only the monitor's own issues count: someone else's issue carrying a copied key is ignored.
            if 'pull_request' in issue or (issue.get('user') or {}).get('login') != BOT:
                continue
            m = KEY_RE.search(issue.get('body') or '')
            if not m:
                continue
            key = m.group(1)
            # Keep the newest issue for a key if a duplicate ever slipped through.
            if key not in self.by_key or issue['number'] > self.by_key[key]['number']:
                self.by_key[key] = issue

    @staticmethod
    def state(issue):
        m = STATE_RE.search((issue or {}).get('body') or '')
        if not m:
            return {}
        try:
            return json.loads(m.group(1))
        except ValueError:
            return {}

    @staticmethod
    def compose(key, text, state=None):
        head = f'<!-- monitor-key: {key} -->\n'
        if state is not None:
            head += f'<!-- monitor-state: {json.dumps(state, sort_keys=True, separators=(",", ":"))} -->\n'
        return head + text

    def get(self, key):
        return self.by_key.get(key)

    def items(self):
        return list(self.by_key.items())

    def open(self, key, title, text, labels, state=None):
        """Open a new alert issue assigned to the owner. Returns the issue (a stub in a dry run)."""
        self.actions += 1
        body = self.compose(key, text, state)
        log(f'OPEN  [{key}] {title}')
        if DRY_RUN:
            issue = {'number': 0, 'state': 'open', 'title': title, 'body': body, 'dry': True}
        else:
            issue = gh('POST', f'/repos/{REPO}/issues', {
                'title': title, 'body': body, 'labels': [LABEL] + labels, 'assignees': [OWNER],
            })
            log(f'      -> #{issue["number"]} {issue["html_url"]}')
            # GitHub drops assignees it won't accept without an error, and the assignment is what emails
            # the owner. Try once more on its own, then mention her: a mention emails her too.
            if not any(a.get('login') == OWNER for a in issue.get('assignees') or []):
                got = gh('POST', f'/repos/{REPO}/issues/{issue["number"]}/assignees', {'assignees': [OWNER]})
                if not any(a.get('login') == OWNER for a in (got or {}).get('assignees') or []):
                    log(f'      (could not assign {OWNER}; mentioning instead)')
                    gh('POST', f'/repos/{REPO}/issues/{issue["number"]}/comments',
                       {'body': f'@{OWNER} this needs you (the monitor could not assign it).'})
        self.by_key[key] = issue
        return issue

    def comment(self, key, text, reopen=False):
        issue = self.by_key[key]
        reopening = reopen and issue['state'] == 'closed'
        self.actions += 1
        log(f'NOTE  [{key}] #{issue["number"]}{" (reopen)" if reopening else ""}: {text.splitlines()[0][:100]}')
        if DRY_RUN or issue.get('dry'):
            if reopening:
                issue['state'] = 'open'
            return
        if reopening:
            gh('PATCH', f'/repos/{REPO}/issues/{issue["number"]}', {'state': 'open'})
            issue['state'] = 'open'
        gh('POST', f'/repos/{REPO}/issues/{issue["number"]}/comments', {'body': text})

    def close(self, key, text):
        issue = self.by_key[key]
        if issue['state'] == 'closed':
            return
        self.actions += 1
        log(f'CLOSE [{key}] #{issue["number"]}: {text[:100]}')
        issue_number = issue['number']
        issue['state'] = 'closed'
        if DRY_RUN or issue.get('dry'):
            return
        gh('POST', f'/repos/{REPO}/issues/{issue_number}/comments', {'body': text})
        gh('PATCH', f'/repos/{REPO}/issues/{issue_number}', {'state': 'closed', 'state_reason': 'completed'})

    def update(self, key, title=None, state=None):
        """Quietly edit an alert's title or stored state (edits send no email)."""
        issue = self.by_key[key]
        payload = {}
        if state is not None and state != self.state(issue):
            body = STATE_RE.sub('', issue.get('body') or '', count=1)
            body = KEY_RE.sub('', body, count=1).lstrip('\n')
            payload['body'] = self.compose(key, body, state)
        if title and title != issue.get('title'):
            payload['title'] = title
        if not payload:
            return
        log(f'EDIT  [{key}] #{issue["number"]}: {", ".join(sorted(payload))}')
        issue.update(payload)
        if DRY_RUN or issue.get('dry'):
            return
        gh('PATCH', f'/repos/{REPO}/issues/{issue["number"]}', payload)


def ensure_labels():
    if DRY_RUN:
        return
    have = {lab['name'] for lab in gh_pages(f'/repos/{REPO}/labels')}
    for name, (color, desc) in LABELS.items():
        if name not in have:
            gh('POST', f'/repos/{REPO}/labels', {'name': name, 'color': color, 'description': desc})


# ---------------------------------------------------------------- WordPress.org listing

def plugin_info():
    """The info API's answer. A closed or missing plugin comes back as HTTP 404 with JSON holding an
    "error" field; network trouble raises instead, so an outage never reads as a closure."""
    status, body = fetch(INFO_URL, accept='application/json')
    try:
        data = json.loads(body)
    except ValueError:
        raise RuntimeError(f'info API: HTTP {status}, not JSON') from None
    if isinstance(data, dict) and (status == 200 or (status == 404 and data.get('error'))):
        return data
    raise RuntimeError(f'info API: HTTP {status}')


def check_listing(alerts, info):
    key = 'plugin-unavailable'
    issue = alerts.get(key)
    if info.get('error'):
        log(f'WordPress.org listing: ERROR {clean(str(info["error"]), 120)}')
        details = '\n'.join(f'- {name}: {inline(str(info[name]), 300)}'
                            for name in ('error', 'closed', 'closed_date', 'reason', 'reason_text', 'description')
                            if info.get(name) not in (None, ''))
        text = (f'The WordPress.org plugin API no longer serves **{SLUG}**. Sites can\'t install it or get '
                f'updates while this lasts.\n\n{details}\n\n'
                f'Check the plugin page ({PLUGIN_PAGE}) and the WordPress.org account\'s inbox '
                f'(`{AUTHOR}`) for mail from the Plugins Team.')
        if issue is None:
            alerts.open(key, 'WordPress.org is not serving the plugin (closed or removed?)', text, ['plugin-status'])
        elif issue['state'] == 'closed':
            alerts.comment(key, 'It happened again.\n\n' + text, reopen=True)
        return
    log(f'WordPress.org listing: version {clean(str(info.get("version")), 20)}, '
        f'{info.get("active_installs", "?")} active installs, {info.get("num_ratings", "?")} ratings, '
        f'{info.get("support_threads", "?")} recent support threads')
    if issue is not None and issue['state'] == 'open':
        alerts.close(key, f'The WordPress.org API serves the plugin again (version {inline(info.get("version"))}).')


# ---------------------------------------------------------------- WordPress versions

def major(version):
    m = re.match(r'^\s*(\d+)\.(\d+)', str(version or ''))
    return (int(m.group(1)), int(m.group(2))) if m else None


def index(mj):
    """WordPress majors count in tenths: 7.9 is followed by 8.0."""
    return mj[0] * 10 + mj[1]


def next_major(mj):
    return divmod(index(mj) + 1, 10)


def fmt(mj):
    return f'{mj[0]}.{mj[1]}'


def latest_release():
    """The newest stable major, from the first offer the version check calls an upgrade."""
    data = fetch_json(VERSION_CHECK)
    for offer in data.get('offers') or []:
        if isinstance(offer, dict) and offer.get('response') in ('upgrade', 'latest') and major(offer.get('current')):
            return major(offer['current'])
    raise RuntimeError('version check: no stable offer')


def _rank(stage):
    return (1 if stage[0] == 'RC' else 0, stage[1])


def prerelease(nxt):
    """The furthest beta or release candidate of the next major, as ('Beta' | 'RC', n), or None.
    Source: the version check's beta channel (what the WordPress Beta Tester plugin uses), which also
    reports release candidates; if that call fails, WordPress.org's release announcements."""
    try:
        data = fetch_json(VERSION_CHECK + '?channel=beta')
        offers = data.get('offers')
        if not isinstance(offers, list) or not offers or not isinstance(offers[0], dict):
            raise RuntimeError('unexpected shape')
        offer = offers[0]
        m = PRE_RE.fullmatch(str(offer.get('current') or ''))
        if offer.get('response') == 'development' and m and (int(m.group(1)), int(m.group(2))) == nxt:
            return ('RC' if m.group(3).lower() == 'rc' else 'Beta', int(m.group(4)))
        return None
    except Exception as e:
        log(f'(beta channel failed: {e}; reading the release announcements instead)')
    best = None
    for item in feed(RELEASE_NEWS):
        for m in NEWS_RE.finditer(clean(item['title'])):
            if (int(m.group(1)), int(m.group(2))) != nxt:
                continue
            stage = ('Beta' if m.group(3).lower() == 'beta' else 'RC', int(m.group(4)))
            if best is None or _rank(stage) > _rank(best):
                best = stage
    return best


def stage_name(stage):
    return 'released' if stage == 'released' else f'{"Release Candidate" if stage[0] == "RC" else "Beta"} {stage[1]}'


def core_text(ver, tested, stage, behind):
    if stage == 'released':
        lead = (f'WordPress {ver} has been released, and WordPress.org still lists the plugin as tested up to '
                f'**{tested}**.\n\nWhat that means now: on sites running {ver}, the Add Plugins screen labels the '
                f'plugin "Untested with your version of WordPress" and the update screen says "Compatibility '
                f'with WordPress {ver}: Not tested". Plugin Check, in the Test workflow, now fails on the old '
                f'"Tested up to".')
        if behind >= 2:
            lead += ' WordPress.org search also ranks plugins lower once they are two majors behind.'
        if behind >= 3:
            lead += (' At three majors behind, the plugin page warns that it "hasn\'t been tested with the latest '
                     '3 major releases" and WordPress.org drops it from its sitemaps.')
    else:
        lead = (f'WordPress {ver} {stage_name(stage)} is out, and WordPress.org lists the plugin as tested up to '
                f'**{tested}**. From the release candidate on, WordPress.org allows "Tested up to: {ver}", so now '
                f'is the time to test, before {ver} ships.')
    return (f'{lead}\n\nTo do (each step that changes WordPress.org needs your OK):\n'
            f'1. Test the plugin with {ver} (the Test workflow\'s `trunk` job runs the development version).\n'
            f'2. Set `Tested up to: {ver}` (just {ver}, never three numbers) in `readme.txt`. With no code '
            f'changes, the *Update WordPress.org readme and art* workflow publishes it without a release; '
            f'with code changes, it goes out with the next release.\n\n'
            f'This issue closes itself at the first daily check after WordPress.org shows '
            f'"Tested up to: {ver}".')


def check_core(alerts, info):
    tested = major(info.get('tested'))
    bad = 'wp-tested-invalid'
    if not tested:
        text = (f'WordPress.org shows no "Tested up to" for the plugin (the API gives '
                f'{inline(str(info.get("tested")))}). It discards a value it can\'t read or one above the next '
                f'WordPress version. Check the `Tested up to:` line in `readme.txt` on WordPress.org (trunk and '
                f'the stable tag): it should be a version like `7.1`.')
        issue = alerts.get(bad)
        if issue is None:
            alerts.open(bad, 'WordPress.org ignored the plugin\'s "Tested up to"', text, ['wordpress-version'])
        elif issue['state'] == 'closed':
            alerts.comment(bad, 'It happened again.\n\n' + text, reopen=True)
        return
    if alerts.get(bad) is not None:
        alerts.close(bad, f'WordPress.org shows "Tested up to: {fmt(tested)}" again.')

    latest = latest_release()
    nxt = next_major(latest)
    pre = prerelease(nxt)
    log(f'WordPress: latest release {fmt(latest)}; {fmt(nxt)} '
        f'{stage_name(pre) if pre else "not in beta yet"}; plugin tested up to {fmt(tested)}')

    targets = {}
    if index(latest) > index(tested):
        targets[latest] = 'released'
    if pre and pre[0] == 'RC' and index(nxt) > index(tested):
        targets[nxt] = pre
    for mj, stage in sorted(targets.items()):
        ver, key = fmt(mj), f'wp-{fmt(mj)}'
        title = (f'WordPress {ver} is out: test the plugin and update "Tested up to"' if stage == 'released'
                 else f'WordPress {ver} {stage_name(stage)} is out: test the plugin before {ver} ships')
        text = core_text(ver, fmt(tested), stage, index(latest) - index(tested))
        stored = 'released' if stage == 'released' else stage_name(stage)
        issue = alerts.get(key)
        if issue is None:
            alerts.open(key, title, text, ['wordpress-version'], {'stage': stored})
        elif Alerts.state(issue).get('stage') != stored:
            prev = Alerts.state(issue).get('stage')
            if stage == 'released' or prev == 'done':
                news = (f'WordPress.org shows "Tested up to: {fmt(tested)}" again' if prev == 'done' else
                        f'WordPress {ver} has now been released. WordPress.org still shows "Tested up to: '
                        f'{fmt(tested)}"')
                alerts.comment(key, news + '.\n\n' + text, reopen=True)
            alerts.update(key, title=title, state={'stage': stored})  # a later RC alone: no email

    for key, issue in alerts.items():
        mj = major(key[3:]) if key.startswith('wp-') else None
        if mj and index(mj) <= index(tested):
            # So that if "Tested up to" ever falls back below this version, the alert reopens, even when
            # the issue was closed by hand. No edit once it is already 'done'.
            alerts.update(key, state={'stage': 'done'})
            if issue['state'] == 'open':
                alerts.close(key, f'WordPress.org now shows "Tested up to: {fmt(tested)}". Done.')


# ---------------------------------------------------------------- WordPress.org forum

def topic_url(slug):
    return 'https://wordpress.org/support/topic/%s/' % slug


MAX_PAGES = 20


def topic_page(slug):
    """The Markdown version of the topic page (the per-topic feeds are always empty, so this is the
    only reliable source): who started the topic, the replies shown on every page of it, as (post id,
    username) in page order, plus the last post from the sidebar, how many replies are visible, and
    from the sidebar who posted last and the resolved status. Raises if a page doesn't look as
    expected, so a format change fails loudly instead of going quiet."""
    url = topic_url(slug)
    posts, visible, starter, sidebar, shown = [], 0, None, None, 0
    for number in range(1, MAX_PAGES + 1):
        page_url = url + ('page/%d/' % number if number > 1 else '') + '?output_format=md'
        status, body = fetch(page_url, accept='text/markdown, text/plain')
        if status != 200:
            raise RuntimeError(f'{page_url}: HTTP {status}')
        text = body.decode('utf-8', 'replace')
        cut = text.rfind(MD_SIDEBAR)
        last_by = MD_LAST_BY_RE.search(text, cut) if cut >= 0 else None
        last_post = MD_LAST_POST_RE.search(text, cut) if cut >= 0 else None
        if not last_by or not last_post:
            raise RuntimeError(f'{page_url}: no "Last reply from" in the sidebar (did the page format change?)')
        posts_text = text[:cut]
        if number == 1:
            first = MD_STARTER_RE.search(posts_text)
            starter = first.group(1).lower() if first else None
            sidebar = (last_by.group(1).lower(), last_post.group(1), MD_STATUS_RE.search(text, cut))
        posts += [(int(pid), login.lower()) for login, pid in MD_POST_RE.findall(posts_text)]
        viewing = MD_VIEWING_RE.search(posts_text)
        if not viewing:
            if number > 1:
                raise RuntimeError(f'{page_url}: no "Viewing ... replies" line (did the page format change?)')
            break
        through, visible = viewing.group(1), int(viewing.group(2))
        if through is None or int(through) >= visible:
            break
        if int(through) <= shown:
            raise RuntimeError(f'{page_url}: shows replies up to {through} again (did the page format change?)')
        shown = int(through)
    else:
        log(f'(read only the first {MAX_PAGES} pages of {url})')
    posts = list(dict.fromkeys(posts))
    last_by, last_pid, resolved = sidebar
    if last_pid and int(last_pid) not in {pid for pid, _ in posts}:
        posts.append((int(last_pid), last_by))
    return {'posts': posts, 'visible': visible, 'last_by': last_by, 'starter': starter,
            'status': resolved.group(1).lower() if resolved else None}


def rating(item):
    m = RATING_RE.search(clean(item['text'])) or TITLE_RATING_RE.search(clean(item['title']))
    return int(m.group(1)) if m else None


def by_author(login):
    return (login or '').lower() == AUTHOR.lower()


RECHECK_DAYS = 14


def handle_post(alerts, item, kind, resolved, today):
    """kind: 'topic' or 'review'. resolved: True/False for topics if the feeds tell, else None.

    The topic page is read when the feed's reply count changes. A reply is new when its post id hasn't
    been seen before, whoever's reply comes last: the count also includes hidden posts (spam, held for
    moderation, removed), and a held reply approved later keeps its place before newer posts. While
    the count is above the replies shown, the page is read on every run for RECHECK_DAYS days, to catch
    held replies when they appear. A topic the plugin author started opens no issue until someone else
    replies (its page is read on each run until then: the feed only gives a display name)."""
    slug = item['slug']
    key = f'wporg-{kind}-{slug}'
    issue = alerts.get(key)
    if issue is None and item['date'] and item['date'] < SINCE:
        return  # older than the monitor itself
    replies = item['replies']
    state = Alerts.state(issue)
    recheck = state.get('recheck_until')
    page = None
    if (issue is None and kind == 'topic') or (replies and (
            issue is None or replies != state.get('replies') or (recheck and today.isoformat() <= recheck))):
        page = topic_page(slug)
        if kind == 'topic' and page['status']:
            resolved = page['status'] != 'not resolved'
    url = topic_url(slug)
    noun = 'review' if kind == 'review' else 'support topic'
    mine = kind == 'topic' and page is not None and by_author(page['starter'])
    if issue is None and mine and all(by_author(who) for _, who in page['posts']):
        log(f'(no alert for {key}: the plugin author started it and no one else has replied)')
        return

    if page is not None:
        seen = state.get('seen')
        known = set(seen or [])
        fresh = [who for pid, who in page['posts'] if pid not in known] if seen is not None else []
        new_state = {'replies': replies, 'last_by': page['last_by'],
                     'seen': sorted(known | {pid for pid, _ in page['posts']})[-1000:]}
        if replies > page['visible']:
            same = recheck and replies == state.get('replies')
            new_state['recheck_until'] = recheck if same else (today + dt.timedelta(days=RECHECK_DAYS)).isoformat()
    else:
        fresh = []
        new_state = dict(state, replies=replies, seen=state.get('seen', []))
        if not replies:
            new_state['last_by'] = None
    last_by = new_state.get('last_by')

    if issue is None:
        stars = rating(item) if kind == 'review' else None
        if mine:
            text = f'Someone replied to your support topic on WordPress.org: {url}\n\n'
        else:
            text = f'New {noun} on WordPress.org: {url}\n\n'
        if stars is not None:
            text += f'Rating: **{stars} of 5 stars**\n\n'
        when = item['date'].strftime('%Y-%m-%d %H:%M UTC') if item['date'] else 'an unknown date'
        text += f'Opened by {inline(item["author"])} on {when}. Read it on WordPress.org.\n\n'
        if replies:
            text += f'Replies so far: {replies}, the latest from {inline(last_by)}.\n\n'
        text += (f'Reply on WordPress.org, logged in as `{AUTHOR}`. ')
        text += ('This issue closes itself once your reply is the latest post.' if kind == 'review' else
                 'This issue closes itself when the topic is marked resolved.')
        title = f'WordPress.org {noun}: {title_text(item["title"])}'
        alerts.open(key, title, text, ['wporg-review' if kind == 'review' else 'wporg-support'], new_state)
        return

    others = [who for who in fresh if not by_author(who)]
    if others:
        what = 'New reply' if len(others) == 1 else f'{len(others)} new replies'
        alerts.comment(key, f'{what} on {url}, the latest from {inline(others[-1])}.',
                       reopen=not (kind == 'topic' and resolved))
    alerts.update(key, state=new_state)
    if kind == 'review' and not others and replies and by_author(last_by) and issue['state'] == 'open':
        alerts.close(key, 'Your reply is the latest post on this review.')
    if kind == 'topic' and resolved and issue['state'] == 'open':
        alerts.close(key, f'Marked resolved on WordPress.org: {url}')


def forum_feed(url):
    """A forum feed's topics. Raises if one has no reply count (the feed's format changed), and logs
    any item whose link isn't a plain topic link, since those are skipped."""
    items = feed(url)
    for item in items:
        if not item['slug']:
            log(f'(skipped a feed item whose link is not a topic link: {inline(item["link"], 160)})')
        elif item['replies'] is None:
            raise RuntimeError(f'{url}: no reply count for {item["slug"]} (did the feed format change?)')
    return [i for i in items if i['slug']]


def check_forum(alerts, today):
    """Support topics come from the newest-topics and latest-activity feeds (each the 30 most recent,
    with each topic's reply count), reviews from the reviews feed. A topic counts as resolved when it is
    missing from the unresolved feed, which is only trusted when that feed isn't full; otherwise the
    topic page decides."""
    reviews = forum_feed(FORUM + 'reviews/feed/')
    topics = {}
    for url in (FORUM + 'feed/', FORUM + 'active/feed/'):
        for item in forum_feed(url):
            slug = item['slug']
            if slug not in topics or item['replies'] > topics[slug]['replies']:
                topics[slug] = item
    unresolved_items = feed(FORUM + 'unresolved/feed/')
    unresolved = {i['slug'] for i in unresolved_items if i['slug']}
    complete = len(unresolved_items) < 30
    review_slugs = {r['slug'] for r in reviews}
    log(f'WordPress.org forum: {len(topics)} recent topics ({len(unresolved)} unresolved), {len(reviews)} reviews')

    for item in reviews:
        handle_post(alerts, item, 'review', None, today)
    for slug, item in topics.items():
        if slug in review_slugs:
            continue
        resolved = (slug not in unresolved) if (complete or slug in unresolved) else None
        if resolved is None:
            issue = alerts.get(f'wporg-topic-{slug}')
            if issue is not None and issue['state'] == 'open':
                resolved = topic_page(slug)['status'] not in (None, 'not resolved')
        handle_post(alerts, item, 'topic', resolved, today)


# ---------------------------------------------------------------- GitHub issues and pull requests

def check_github(alerts):
    """Assign the owner to every open issue or pull request someone else opened, once: GitHub then
    emails her about it and about every later comment. If she unassigns herself, it stays that way."""
    if not TOKEN:
        log('(no token: skipping the GitHub check)')
        return
    for item in gh_pages(f'/repos/{REPO}/issues?state=open'):
        user = item.get('user') or {}
        login = user.get('login', '')
        if login == OWNER or user.get('type') == 'Bot' or login.endswith('[bot]'):
            continue
        if any(a.get('login') == OWNER for a in item.get('assignees') or []):
            continue
        events = gh_pages(f'/repos/{REPO}/issues/{item["number"]}/events')
        if any(e.get('event') == 'assigned' and (e.get('assignee') or {}).get('login') == OWNER for e in events):
            continue
        what = 'pull request' if 'pull_request' in item else 'issue'
        alerts.actions += 1
        log(f'ASSIGN #{item["number"]} ({what} by {login})')
        if not DRY_RUN:
            gh('POST', f'/repos/{REPO}/issues/{item["number"]}/assignees', {'assignees': [OWNER]})


def check_inactivity(alerts, today):
    """In a public repository GitHub turns scheduled workflows off after 60 days without activity, and
    this monitor would stop without a word. GitHub doesn't define activity; a commit is the one thing
    known to count. So from 45 days after the last commit to the default branch, an issue says so."""
    if not TOKEN:
        return
    key = 'repo-inactive'
    issue = alerts.get(key)
    repo = gh('GET', f'/repos/{REPO}')
    days = None
    if not repo.get('private'):
        commits = gh('GET', f'/repos/{REPO}/commits?sha={repo["default_branch"]}&per_page=1')
        last = dt.datetime.fromisoformat(commits[0]['commit']['committer']['date'].replace('Z', '+00:00')).date()
        days = (today - last).days
    if days is None or days < 45:
        if issue is not None and issue['state'] == 'open':
            alerts.close(key, 'There has been a commit since, so the 60 days start again.')
        return
    stop = last + dt.timedelta(days=60)
    text = (f'The last commit to `{repo["default_branch"]}` was on {last.isoformat()}, {days} days ago. In a public '
            f'repository GitHub switches scheduled workflows off after 60 days without activity, so this monitor '
            f'stops around **{stop.isoformat()}** unless something is committed before then.\n\n'
            f'Any real commit to `{repo["default_branch"]}` resets it (a pending readme or "Tested up '
            f'to" update is ideal). If it has already stopped, the Actions tab shows a button to turn it back on, '
            f'and GitHub\'s warning email has one too. This issue closes itself after the next commit.')
    if issue is None:
        alerts.open(key, f'The plugin monitor stops around {stop.isoformat()} unless there is a commit', text, [])
    elif issue['state'] == 'closed':
        alerts.comment(key, text, reopen=True)
        alerts.update(key, title=f'The plugin monitor stops around {stop.isoformat()} unless there is a commit')


# ---------------------------------------------------------------- reminders and tests

def check_reminders(alerts, today):
    for r in CONFIG.get('reminders', []):
        # "id", not "key": the secret scanner reads a "key" field as an API key.
        if today >= dt.date.fromisoformat(r['date']) and alerts.get(r['id']) is None:
            alerts.open(r['id'], r['title'], r['body'], ['reminder'])


def test_alert(alerts):
    run = os.environ.get('GITHUB_RUN_ID', 'local')
    alerts.open(f'test-{run}', 'Test alert: the plugin monitor can reach you',
                'This is a test from the plugin monitor. If an email about it reached you, alerts work.\n\n'
                'Close this issue whenever you like.', [])


def test_failure():
    raise RuntimeError('a deliberate failure, to test the "monitor is failing" alert')


def report_health(alerts, errors):
    """One issue while the monitor keeps failing: opened by the first failed run (the monitor runs once
    a day, and each request is already retried) and closed by the next clean run. GitHub's own "run
    failed" emails depend on the owner's notification settings; this doesn't. Returns True when the
    failure is on record in that open issue."""
    key = 'monitor-failing'
    issue = alerts.get(key)
    run = os.environ.get('GITHUB_RUN_ID')
    server = os.environ.get('GITHUB_SERVER_URL', 'https://github.com')
    where = 'run %s/%s/actions/runs/%s' % (server, REPO, run) if run else 'a local run'
    if not errors:
        if issue is not None and issue['state'] == 'open':
            alerts.close(key, f'Working again ({where}).')
        return False
    if issue is not None and issue['state'] == 'open':
        return True
    text =(f'These checks failed in {where}, after retries:\n\n' + '\n'.join(f'- {e}' for e in errors) +
            '\n\nWhile this is open, the monitor may be missing things. Check the run log. '
            'This issue closes itself after the next run without errors.')
    if issue is None:
        alerts.open(key, 'The plugin monitor is failing', text, [])
    else:
        alerts.comment(key, 'Failing again.\n\n' + text, reopen=True)
    return True


# ---------------------------------------------------------------- main

def main():
    errors = []

    def step(name, fn, *args):
        try:
            return fn(*args)
        except Exception as e:  # one failing check must not stop the others
            errors.append(name)
            log(f'ERROR {name}: {type(e).__name__}: {e}')
            return None

    log(f'Plugin monitor for {SLUG} ({REPO}){" (DRY RUN)" if DRY_RUN else ""}')
    step('labels', ensure_labels)
    alerts = step('loading alerts', Alerts)
    if alerts is None:
        return 1
    if os.environ.get('TEST_ALERT') == 'true':
        step('test alert', test_alert, alerts)
    if os.environ.get('TEST_FAILURE') == 'true':
        step('test failure', test_failure)
    today = dt.datetime.now(dt.timezone.utc).date()
    info = step('plugin info', plugin_info)
    if info is not None:
        step('listing', check_listing, alerts, info)
        if not info.get('error'):
            step('WordPress versions', check_core, alerts, info)
    step('forum', check_forum, alerts, today)
    step('GitHub issues', check_github, alerts)
    step('inactivity', check_inactivity, alerts, today)
    step('reminders', check_reminders, alerts, today)
    on_record = step('health', report_health, alerts, list(errors))

    log(f'{alerts.actions} action(s), {len(errors)} error(s){": " + ", ".join(errors) if errors else ""}.')
    # Once the open "monitor is failing" issue records the errors, the run passes, so GitHub's own
    # failure email doesn't repeat every day. Dry runs always show their errors.
    return 1 if errors and (DRY_RUN or not on_record) else 0


if __name__ == '__main__':
    sys.exit(main())
