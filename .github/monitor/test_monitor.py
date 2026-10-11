#!/usr/bin/env python3
"""Offline tests for monitor.py: every request is answered from the fixtures below, and GitHub is the
dry-run stand-in, so nothing leaves the machine. Run: python3 -I .github/monitor/test_monitor.py"""

import datetime as dt
import json
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
os.environ['MONITOR_DRY_RUN'] = '1'
os.environ.pop('GITHUB_TOKEN', None)
os.environ.pop('GITHUB_RUN_ID', None)
import monitor as m  # noqa: E402

m.PACE.clear()
SLUG = m.SLUG
FORUM = m.FORUM
TODAY = dt.date(2026, 10, 12)


def item(slug, title, author='Someone', date='Mon, 12 Oct 2026 10:00:00 +0000', replies=0, extra=''):
    url = m.topic_url(slug)
    count = '' if replies is None else f'<p>\n\t\t\tReplies: {replies}\t\t</p>'
    return (f'<item><guid>{url}</guid><title>{title}</title>'
            f'<link>{url}</link><pubDate>{date}</pubDate>'
            f'<dc:creator><![CDATA[{author}]]></dc:creator><description><![CDATA[<p>Hello, this is {slug}.</p>'
            f'{extra}{count}]]></description></item>')


def rss(*items):
    return ('<?xml version="1.0"?><rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel>'
            '<title>t</title>' + ''.join(items) + '</channel></rss>').encode()


def profile(login):
    return 'https://wordpress.org/support/users/%s/' % login


def md(posts, status='not resolved', slug='help-me', visible=None, starter='starter', start=1, last=None):
    """A topic page in WordPress.org's Markdown, laid out like the real one: the opening post, the
    replies shown, given as (post id, username), between two "Viewing" lines, then the sidebar.
    visible: the "Viewing" total (default: every reply up to this page's last). start: the number of
    this page's first reply (a long topic's later pages). last: the sidebar's last post as (post id,
    username) (default: this page's last reply)."""
    url = m.topic_url(slug)
    lines = ['Title: A topic', '', '---', '', '# A topic', '', ' *  [%s](%s)' % (starter.title(), profile(starter)),
             f' * (@{starter})', f' * [2 days ago]({url})', ' * The question.', '']
    through = start - 1 + len(posts)
    total = through if visible is None else visible
    viewing = f'Viewing {len(posts)} replies - {start} through {through} (of {total} total)'
    if posts:
        lines += [viewing, '']
    for pid, who in posts:
        lines += [' *  [%s](%s)' % (who.title(), profile(who)), f' * (@{who})',
                  f' * [1 hour ago]({url}#post-{pid})', ' * A reply.']
    if posts:
        lines += ['', viewing]
    last_pid, last_who = last or (posts[-1] if posts else (None, starter))
    lines += ['', 'You must be logged in to reply to this topic.', '',
              f' * [Support Threads]({FORUM})', f' * [Reviews]({FORUM}reviews/)', '',
              f' * {total} replies', ' * Last reply from: [%s](%s)' % (last_who.title(), profile(last_who)),
              f' * Last activity: [1 hour ago]({url}' + (f'#post-{last_pid})' if last_pid else ')')]
    if status:
        lines.append(f' * Status: {status}')
    return '\n'.join(lines).encode()


def offers(*currents, response='upgrade'):
    return json.dumps({'offers': [{'response': response, 'current': c} for c in currents]}).encode()


class World:
    """The outside world for one test: URL -> (status, body). Missing URLs fail the request."""

    def __init__(self):
        self.pages = {}
        self.calls = []
        empty = rss()
        for path in ('feed/', 'active/feed/', 'unresolved/feed/', 'reviews/feed/'):
            self.pages[FORUM + path] = (200, empty)
        self.pages[m.INFO_URL] = (200, json.dumps({'version': '0.2.2', 'tested': '7.1.3'}).encode())
        self.pages[m.VERSION_CHECK] = (200, offers('7.1.3'))
        self.pages[m.VERSION_CHECK + '?channel=beta'] = (200, offers('7.1.3'))

    def fetch(self, url, accept='text/plain', data=None, method=None, headers=None, attempts=3):
        self.calls.append(url)
        if url not in self.pages:
            raise RuntimeError(f'{url}: unreachable (test)')
        return self.pages[url]


class Base(unittest.TestCase):
    def setUp(self):
        self.world = World()
        self.logs = []
        self._fetch, self._log = m.fetch, m.log
        m.fetch = self.world.fetch
        m.log = self.logs.append
        # Independent of config.json, so editing it (a reminder done, a new start date) never fails the tests.
        self.patch(SINCE=dt.datetime(2026, 10, 10, tzinfo=dt.timezone.utc), AUTHOR='daniowens')
        self.alerts = m.Alerts()

    def tearDown(self):
        m.fetch, m.log = self._fetch, self._log

    def acts(self, prefix):
        return [line for line in self.logs if line.startswith(prefix)]

    def run_checks(self, today=TODAY):
        info = m.plugin_info()
        m.check_listing(self.alerts, info)
        if not info.get('error'):
            m.check_core(self.alerts, info)
        m.check_forum(self.alerts, today)

    def again(self, today=TODAY):
        """A second run on the same world must do nothing new."""
        before = self.alerts.actions
        self.run_checks(today)
        self.assertEqual(self.alerts.actions, before, self.logs[-6:])

    def patch(self, **values):
        """Set module attributes for this test only."""
        for name, value in values.items():
            old = getattr(m, name)
            self.addCleanup(setattr, m, name, old)
            setattr(m, name, value)


class UntrustedText(Base):
    def test_markup_and_overrides(self):
        name = m.inline('<b>hi</b> @someone ‮evil')
        self.assertEqual(name, '`hi @someone evil`')

    def test_invisible_characters(self):
        self.assertEqual(m.clean('a​b⁦c­d\x07e﻿'), 'abcde')

    def test_inline_has_no_backticks(self):
        self.assertEqual(m.inline('a`b'), "`a'b`")
        self.assertEqual(m.inline(''), '`(unknown)`')

    def test_topic_links_need_a_clean_slug(self):
        feed = rss(item('ok-topic', 'A'), item('x', 'B').replace('https://wordpress.org', 'https://evil.example'),
                   item('caf%C3%A9-help', 'C'), item('under_score', 'D'), item('bad"quote', 'E'))
        slugs = [i['slug'] for i in m.parse_feed(feed)]
        self.assertEqual(slugs, ['ok-topic', None, 'caf%c3%a9-help', 'under_score', None])


class Quiet(Base):
    def test_nothing_to_report(self):
        self.run_checks()
        self.assertEqual(self.alerts.actions, 0, self.logs)

    def test_beta_does_not_alert(self):
        self.world.pages[m.VERSION_CHECK + '?channel=beta'] = (200, offers('7.2-beta1', response='development'))
        self.run_checks()
        self.assertEqual(self.alerts.actions, 0, self.logs)


class WordPressVersions(Base):
    def test_release_candidate_then_release_then_bump(self):
        beta = m.VERSION_CHECK + '?channel=beta'
        self.world.pages[beta] = (200, offers('7.2-RC1', response='development'))
        self.run_checks()
        self.assertEqual(len(self.acts('OPEN  [wp-7.2]')), 1, self.logs)
        self.again()

        self.world.pages[beta] = (200, offers('7.2-RC2', response='development'))
        self.run_checks()  # a later RC: quiet edit only
        self.assertEqual(self.acts('NOTE'), [])
        self.assertIn('Release Candidate 2', self.alerts.get('wp-7.2')['title'])

        self.world.pages[m.VERSION_CHECK] = (200, offers('7.2'))
        self.world.pages[beta] = (200, offers('7.2'))
        self.run_checks()
        self.assertEqual(len(self.acts('NOTE  [wp-7.2]')), 1, self.logs)
        self.again()

        self.world.pages[m.INFO_URL] = (200, json.dumps({'version': '0.2.3', 'tested': '7.2.0'}).encode())
        self.run_checks()
        self.assertEqual(len(self.acts('CLOSE [wp-7.2]')), 1, self.logs)
        self.again()

        # "Tested up to" falling back below 7.2 reopens it.
        self.world.pages[m.INFO_URL] = (200, json.dumps({'version': '0.2.3', 'tested': '7.1'}).encode())
        self.run_checks()
        self.assertEqual(len(self.acts('NOTE  [wp-7.2]')), 2, self.logs)
        self.assertEqual(self.alerts.get('wp-7.2')['state'], 'open')
        self.again()

    def test_closed_by_hand_still_reopens(self):
        self.world.pages[m.VERSION_CHECK] = (200, offers('7.2'))
        self.run_checks()
        self.alerts.get('wp-7.2')['state'] = 'closed'  # she closes it herself after the bump
        self.world.pages[m.INFO_URL] = (200, json.dumps({'version': '0.2.3', 'tested': '7.2'}).encode())
        self.run_checks()
        self.assertEqual(self.acts('CLOSE'), [])
        self.assertEqual(m.Alerts.state(self.alerts.get('wp-7.2')), {'stage': 'done'})
        self.again()
        self.world.pages[m.INFO_URL] = (200, json.dumps({'version': '0.2.3', 'tested': '7.1'}).encode())
        self.run_checks()
        self.assertEqual(len(self.acts('NOTE  [wp-7.2] #0 (reopen)')), 1, self.logs)

    def test_release_seen_without_rc(self):
        self.world.pages[m.VERSION_CHECK] = (200, offers('7.2.1'))
        self.run_checks()
        self.assertEqual(len(self.acts('OPEN  [wp-7.2]')), 1, self.logs)
        self.assertIn('is out: test the plugin', self.alerts.get('wp-7.2')['title'])

    def test_three_behind_mentions_the_warning(self):
        self.world.pages[m.VERSION_CHECK] = (200, offers('7.4'))
        self.run_checks()
        self.assertIn('3 major releases', self.alerts.get('wp-7.4')['body'])

    def test_major_rolls_over(self):
        self.assertEqual(m.next_major((7, 9)), (8, 0))
        self.assertEqual(m.index((8, 0)) - m.index((7, 9)), 1)

    def test_fallback_to_release_news(self):
        self.world.pages.pop(m.VERSION_CHECK + '?channel=beta')
        self.world.pages[m.RELEASE_NEWS] = (200, rss(
            item('a', 'WordPress 7.1.3 and 7.2 Release Candidate 1'), item('b', 'WordPress 7.2 Beta 4')))
        self.run_checks()
        self.assertEqual(len(self.acts('OPEN  [wp-7.2]')), 1, self.logs)

    def test_unreadable_tested(self):
        self.world.pages[m.INFO_URL] = (200, json.dumps({'version': '0.2.2', 'tested': False}).encode())
        self.run_checks()
        self.assertEqual(len(self.acts('OPEN  [wp-tested-invalid]')), 1, self.logs)
        self.again()


class Listing(Base):
    def test_closed_plugin(self):
        self.world.pages[m.INFO_URL] = (404, json.dumps({
            'error': 'closed', 'closed': True, 'closed_date': '2026-11-01',
            'description': 'This closure is temporary, pending a full review.'}).encode())
        self.run_checks()
        self.assertEqual(len(self.acts('OPEN  [plugin-unavailable]')), 1, self.logs)
        self.again()
        self.world.pages[m.INFO_URL] = (200, json.dumps({'version': '0.2.2', 'tested': '7.1.3'}).encode())
        self.run_checks()
        self.assertEqual(len(self.acts('CLOSE [plugin-unavailable]')), 1, self.logs)

    def test_outage_is_not_a_closure(self):
        self.world.pages[m.INFO_URL] = (503, b'<html>down</html>')
        with self.assertRaises(RuntimeError):
            m.plugin_info()
        self.assertEqual(self.alerts.actions, 0)


class Forum(Base):
    PAGE = m.topic_url('help-me') + '?output_format=md'

    def topic(self, replies, posts=(), status='not resolved', unresolved=True, visible=None):
        it = item('help-me', 'Help me', replies=replies)
        self.world.pages[FORUM + 'feed/'] = (200, rss(it))
        self.world.pages[FORUM + 'active/feed/'] = (200, rss(it))
        self.world.pages[FORUM + 'unresolved/feed/'] = (200, rss(it) if unresolved else rss())
        self.world.pages[self.PAGE] = (200, md(list(posts), status, visible=visible))

    def page_reads(self):
        return self.world.calls.count(self.PAGE)

    def test_topic_lifecycle(self):
        self.topic(0)
        self.run_checks()
        self.assertEqual(len(self.acts('OPEN  [wporg-topic-help-me]')), 1, self.logs)
        self.again()

        self.topic(1, [(101, 'daniowens')])  # her own reply: no email
        self.run_checks()
        self.assertEqual(self.acts('NOTE'), [])
        self.again()

        self.topic(2, [(101, 'daniowens'), (102, 'someone')])  # their reply: email
        self.run_checks()
        self.assertEqual(len(self.acts('NOTE  [wporg-topic-help-me]')), 1, self.logs)
        self.assertIn('`someone`', self.acts('NOTE  [wporg-topic-help-me]')[0])
        self.again()

        self.topic(2, [(101, 'daniowens'), (102, 'someone')], 'resolved', unresolved=False)
        self.run_checks()
        self.assertEqual(len(self.acts('CLOSE [wporg-topic-help-me]')), 1, self.logs)
        self.again()

        # A thank-you after resolving: noted, but it stays closed.
        self.topic(3, [(101, 'daniowens'), (102, 'someone'), (103, 'someone')], 'resolved', unresolved=False)
        self.run_checks()
        self.assertEqual(len(self.acts('NOTE  [wporg-topic-help-me]')), 2, self.logs)
        self.assertEqual(self.alerts.get('wporg-topic-help-me')['state'], 'closed')

    def test_hidden_posts_send_no_email(self):
        self.topic(1, [(101, 'someone')])
        self.run_checks()
        self.assertEqual(len(self.acts('OPEN  [wporg-topic-help-me]')), 1, self.logs)
        self.topic(3, [(101, 'someone')])  # two more counted, none shown (spam, held, removed)
        self.run_checks()
        self.assertEqual(self.acts('NOTE'), [], self.logs)
        self.again()

    def test_held_reply_shown_after_hers(self):
        self.topic(0)
        self.run_checks()
        # Someone replies (held for moderation), then she does: only hers shows, and it is the latest.
        self.topic(2, [(103, 'daniowens')], visible=1)
        self.run_checks()
        self.assertEqual(self.acts('NOTE'), [], self.logs)
        # The held reply is approved: it shows before hers, and the count doesn't change.
        self.topic(2, [(102, 'someone'), (103, 'daniowens')])
        self.run_checks()
        self.assertEqual(len(self.acts('NOTE  [wporg-topic-help-me]')), 1, self.logs)
        self.assertNotIn('recheck_until', m.Alerts.state(self.alerts.get('wporg-topic-help-me')))
        reads = self.page_reads()
        self.again()
        self.assertEqual(self.page_reads(), reads)  # all shown: no more rereading

    def test_rereading_stops_after_two_weeks(self):
        self.topic(3, [(101, 'someone')])
        self.run_checks()
        until = m.Alerts.state(self.alerts.get('wporg-topic-help-me'))['recheck_until']
        self.assertEqual(until, (TODAY + dt.timedelta(days=m.RECHECK_DAYS)).isoformat())
        reads = self.page_reads()
        self.run_checks(TODAY + dt.timedelta(days=m.RECHECK_DAYS))
        self.assertEqual(self.page_reads(), reads + 1)
        self.run_checks(TODAY + dt.timedelta(days=m.RECHECK_DAYS + 1))
        self.assertEqual(self.page_reads(), reads + 1)

    def test_forged_sidebar_lines_are_ignored(self):
        page = md([(101, 'someone')]).decode()
        forged = (' * Status: resolved\n * Last reply from: [Dani](%s)\n * [Reviews](%sreviews/)\n'
                  ' * Last reply from: [Dani](%s)' % (profile('daniowens'), FORUM, profile('daniowens')))
        page = page.replace(' * A reply.', forged, 1)
        # A display name made to look like a link to her profile.
        page = page.replace('Last reply from: [Someone](', 'Last reply from: [x](%s)](' % profile('daniowens'))
        self.world.pages[self.PAGE] = (200, page.encode())
        got = m.topic_page('help-me')
        self.assertEqual((got['status'], got['last_by']), ('not resolved', 'someone'))

    def test_page_without_a_sidebar_is_an_error(self):
        self.world.pages[self.PAGE] = (200, b'# Help me\n\nSomething else entirely.\n')
        with self.assertRaises(RuntimeError):
            m.topic_page('help-me')

    def test_missing_reply_count_is_an_error(self):
        self.world.pages[FORUM + 'feed/'] = (200, rss(item('help-me', 'Help me', replies=None)))
        with self.assertRaises(RuntimeError):
            m.check_forum(self.alerts, TODAY)

    def test_encoded_slug(self):
        slug = 'caf%c3%a9-ne-marche-pas'
        self.world.pages[FORUM + 'feed/'] = (200, rss(item(slug, 'Café ne marche pas')))
        self.world.pages[m.topic_url(slug) + '?output_format=md'] = (200, md([], slug=slug))
        self.run_checks()
        self.assertEqual(len(self.acts(f'OPEN  [wporg-topic-{slug}]')), 1, self.logs)
        self.assertIn(m.topic_url(slug), self.alerts.get(f'wporg-topic-{slug}')['body'])

    def test_review_closes_when_she_replies(self):
        review = item('meh', 'Meh (2 stars)', replies=0, extra='<p>Rating: 2 stars</p>')
        self.world.pages[FORUM + 'reviews/feed/'] = (200, rss(review))
        self.run_checks()
        body = self.alerts.get('wporg-review-meh')['body']
        self.assertIn('2 of 5 stars', body)
        self.assertNotIn('Hello, this is', body)
        self.again()

        self.world.pages[FORUM + 'reviews/feed/'] = (200, rss(review.replace('Replies: 0', 'Replies: 1')))
        self.world.pages[m.topic_url('meh') + '?output_format=md'] = (200, md([(201, 'daniowens')], None, 'meh'))
        self.run_checks()
        self.assertEqual(len(self.acts('CLOSE [wporg-review-meh]')), 1, self.logs)
        self.again()

    def test_review_she_already_answered_closes_next_run(self):
        review = item('fine', 'Fine (4 stars)', replies=1, extra='<p>Rating: 4 stars</p>')
        self.world.pages[FORUM + 'reviews/feed/'] = (200, rss(review))
        self.world.pages[m.topic_url('fine') + '?output_format=md'] = (200, md([(301, 'daniowens')], None, 'fine'))
        self.run_checks()
        self.assertEqual(len(self.acts('OPEN  [wporg-review-fine]')), 1, self.logs)
        self.world.pages.pop(m.topic_url('fine') + '?output_format=md')  # unchanged count: no page read
        self.run_checks()
        self.assertEqual(len(self.acts('CLOSE [wporg-review-fine]')), 1, self.logs)

    def test_alerts_quote_nothing(self):
        self.topic(0)
        self.run_checks()
        body = self.alerts.get('wporg-topic-help-me')['body']
        self.assertNotIn('```', body)
        self.assertNotIn('Hello, this is', body)
        self.assertIn('Read it on WordPress.org', body)

    def test_her_own_topic_waits_for_someone_else(self):
        def mine(replies, posts):
            it = item('help-me', 'Read this first', author='Dani Owens', replies=replies)
            self.world.pages[FORUM + 'feed/'] = (200, rss(it))
            self.world.pages[FORUM + 'unresolved/feed/'] = (200, rss(it))
            self.world.pages[self.PAGE] = (200, md(posts, starter='daniowens'))
        mine(0, [])
        self.run_checks()
        mine(1, [(101, 'daniowens')])
        self.run_checks()
        self.assertEqual(self.alerts.actions, 0, self.logs)
        self.assertEqual(self.page_reads(), 2)  # read on each run until someone else replies
        mine(2, [(101, 'daniowens'), (102, 'someone')])
        self.run_checks()
        self.assertEqual(len(self.acts('OPEN  [wporg-topic-help-me]')), 1, self.logs)
        self.assertIn('Someone replied to your support topic', self.alerts.get('wporg-topic-help-me')['body'])
        self.again()

    def long_topic(self, replies, posts, size=15):
        """A topic shown over several pages of `size` replies."""
        self.topic(replies, posts[:size], visible=len(posts))
        last = posts[-1]
        self.world.pages[self.PAGE] = (200, md(posts[:size], visible=len(posts), last=last))
        for n, first in enumerate(range(size, len(posts), size), start=2):
            self.world.pages[m.topic_url('help-me') + 'page/%d/?output_format=md' % n] = (
                200, md(posts[first:first + size], visible=len(posts), start=first + 1, last=last))

    def test_long_topic_reads_every_page(self):
        posts = [(100 + n, 'someone') for n in range(39)] + [(200, 'daniowens')]
        self.long_topic(41, posts)  # one more counted than shown: held for moderation
        self.run_checks()
        self.assertEqual(len(self.acts('OPEN  [wporg-topic-help-me]')), 1, self.logs)
        self.assertEqual(len(m.Alerts.state(self.alerts.get('wporg-topic-help-me'))['seen']), 40)
        # The held reply is approved: it shows on page 2, before her reply at the end.
        posts.insert(20, (150, 'helper'))
        self.long_topic(41, posts)
        self.run_checks()
        notes = self.acts('NOTE  [wporg-topic-help-me]')
        self.assertEqual(len(notes), 1, self.logs)
        self.assertIn('`helper`', notes[0])
        self.assertIn(m.topic_url('help-me') + 'page/3/?output_format=md', self.world.calls)
        self.again()

    def test_later_page_in_another_format_is_an_error(self):
        posts = [(100 + n, 'someone') for n in range(20)]
        self.long_topic(20, posts)
        self.world.pages[m.topic_url('help-me') + 'page/2/?output_format=md'] = (200, md([], visible=20))
        with self.assertRaises(RuntimeError):
            m.topic_page('help-me')

    def test_old_topics_are_skipped(self):
        old = item('old-one', 'Old', date='Mon, 05 Oct 2026 10:00:00 +0000')
        self.world.pages[FORUM + 'feed/'] = (200, rss(old))
        self.run_checks()
        self.assertEqual(self.alerts.actions, 0, self.logs)

    def test_feed_error_is_an_error(self):
        self.world.pages[FORUM + 'active/feed/'] = (403, b'Checking your browser')
        with self.assertRaises(RuntimeError):
            m.check_forum(self.alerts, TODAY)

    def test_html_is_not_an_empty_feed(self):
        self.world.pages[FORUM + 'reviews/feed/'] = (200, b'<html><body><p>Checking your browser</p></body></html>')
        with self.assertRaises(RuntimeError):
            m.check_forum(self.alerts, TODAY)


class Health(Base):
    def test_issue_opens_once_and_closes(self):
        self.assertTrue(m.report_health(self.alerts, ['forum']))
        self.assertTrue(m.report_health(self.alerts, ['forum']))
        self.assertEqual(len(self.acts('OPEN  [monitor-failing]')), 1)
        self.assertFalse(m.report_health(self.alerts, []))
        self.assertEqual(self.alerts.get('monitor-failing')['state'], 'closed')
        m.report_health(self.alerts, ['plugin info'])
        self.assertEqual(len(self.acts('NOTE  [monitor-failing]')), 1)
        self.assertEqual(self.alerts.get('monitor-failing')['state'], 'open')

    def test_one_failed_run_opens_nothing(self):
        self.patch(failed_before=lambda: False)
        self.assertFalse(m.report_health(self.alerts, ['forum']))
        self.assertIsNone(self.alerts.get('monitor-failing'))
        self.assertTrue(m.report_health(self.alerts, ['test failure'], True))  # the test input forces it
        self.assertEqual(len(self.acts('OPEN  [monitor-failing]')), 1)

    def test_previous_run_from_the_actions_api(self):
        runs = {'workflow_runs': [{'id': 7, 'conclusion': None}, {'id': 6, 'conclusion': 'success'}]}

        def fake_gh(method, path, payload=None, ok=None):
            if path.endswith('/actions/runs/7'):
                return {'workflow_id': 3}
            if '/actions/workflows/3/runs?' in path:
                return runs
            raise RuntimeError('unexpected ' + path)
        def down(*args, **kwargs):
            raise RuntimeError('API down')
        self.patch(DRY_RUN=False, TOKEN='x', gh=fake_gh)
        os.environ['GITHUB_RUN_ID'] = '7'
        self.addCleanup(os.environ.pop, 'GITHUB_RUN_ID', None)
        self.assertFalse(m.failed_before())
        runs['workflow_runs'][1]['conclusion'] = 'failure'
        self.assertTrue(m.failed_before())
        self.patch(gh=down)
        self.assertTrue(m.failed_before())  # unknown counts as failed

    def test_main_fails_a_dry_run_with_errors(self):
        self.world.pages.pop(FORUM + 'reviews/feed/')
        self.assertEqual(m.main(), 1)
        self.assertEqual(len(self.acts('OPEN  [monitor-failing]')), 1, self.logs)

    def test_main_passes_once_the_failure_is_on_record(self):
        self.world.pages.pop(FORUM + 'reviews/feed/')
        alerts = self.alerts

        class Loaded(m.Alerts):
            def __new__(cls):
                return alerts
        self.patch(DRY_RUN=False, ensure_labels=lambda: None, Alerts=Loaded,
                   report_health=lambda a, errors, force: True)
        self.assertEqual(m.main(), 0)
        self.patch(report_health=lambda a, errors, force: False)
        self.assertEqual(m.main(), 1)


class Bookkeeping(Base):
    def test_only_the_bots_own_issues_count(self):
        key = '<!-- monitor-key: wp-7.2 -->\n'
        issues = [
            {'number': 3, 'state': 'open', 'body': key, 'user': {'login': 'someone'}},
            {'number': 4, 'state': 'open', 'body': key, 'user': {'login': m.BOT}},
            {'number': 5, 'state': 'open', 'body': key, 'user': {'login': m.BOT}, 'pull_request': {}},
            {'number': 6, 'state': 'open', 'body': '<!-- monitor-key: wporg-topic-caf%c3%a9 -->\n',
             'user': {'login': m.BOT}},
        ]
        self.patch(TOKEN='x', gh_pages=lambda path: issues)
        alerts = m.Alerts()
        self.assertEqual(alerts.get('wp-7.2')['number'], 4)
        self.assertEqual(alerts.get('wporg-topic-caf%c3%a9')['number'], 6)

    def test_state_round_trip(self):
        self.alerts.open('k', 't', 'body text', [], {'replies': 1})
        self.alerts.update('k', state={'replies': 2})
        issue = self.alerts.get('k')
        self.assertEqual(m.Alerts.state(issue), {'replies': 2})
        self.assertTrue(issue['body'].endswith('body text'))
        self.assertEqual(issue['body'].count('monitor-key'), 1)

    def test_posts_are_sent_once(self):
        sent = []

        def fake_fetch(url, accept='text/plain', data=None, method=None, headers=None, attempts=3):
            sent.append((method, attempts))
            return 201, b'{}'
        self.patch(fetch=fake_fetch)
        m.gh('POST', '/repos/x/issues', {'title': 't'})
        m.gh('PATCH', '/repos/x/issues/1', {'state': 'closed'})
        self.assertEqual(sent, [('POST', 1), ('PATCH', 3)])

    def test_inactivity_warning_only_when_public(self):
        repo = {'private': True, 'default_branch': 'main'}
        last = {'date': '2026-10-10T23:00:00Z'}

        def fake_gh(method, path, payload=None, ok=None):
            if path.endswith('/commits?sha=main&per_page=1'):
                return [{'commit': {'committer': last}}]
            return repo
        self.patch(TOKEN='x', gh=fake_gh)
        late = dt.date(2026, 11, 30)  # 51 days after the last commit
        m.check_inactivity(self.alerts, late)
        self.assertIsNone(self.alerts.get('repo-inactive'))  # private: never
        repo['private'] = False
        m.check_inactivity(self.alerts, dt.date(2026, 11, 20))  # 41 days
        self.assertIsNone(self.alerts.get('repo-inactive'))
        m.check_inactivity(self.alerts, late)
        m.check_inactivity(self.alerts, late)
        self.assertEqual(len(self.acts('OPEN  [repo-inactive]')), 1, self.logs)
        self.assertIn('2026-12-09', self.alerts.get('repo-inactive')['title'])
        last['date'] = '2026-11-29T12:00:00Z'  # a commit
        m.check_inactivity(self.alerts, late)
        self.assertEqual(self.alerts.get('repo-inactive')['state'], 'closed')
        m.check_inactivity(self.alerts, dt.date(2027, 1, 20))  # 52 days later
        self.assertEqual(self.alerts.get('repo-inactive')['state'], 'open')
        self.assertIn('2027-01-28', self.alerts.get('repo-inactive')['title'])

    def test_reminder_on_its_date(self):
        r = {'id': 'reminder-test', 'date': '2026-11-09', 'title': 'Reminder: test', 'body': 'Do it.'}
        self.patch(CONFIG={'reminders': [r]})
        day = dt.date.fromisoformat(r['date'])
        m.check_reminders(self.alerts, day - dt.timedelta(days=1))
        self.assertIsNone(self.alerts.get(r['id']))
        m.check_reminders(self.alerts, day)
        m.check_reminders(self.alerts, day + dt.timedelta(days=1))
        self.assertEqual(len(self.acts(f'OPEN  [{r["id"]}]')), 1)


if __name__ == '__main__':
    unittest.main(verbosity=1)
