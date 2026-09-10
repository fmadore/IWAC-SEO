import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location("monitor", Path(__file__).parents[1] / ".github/scripts/search-console.py")
monitor = importlib.util.module_from_spec(spec)
spec.loader.exec_module(monitor)
live_spec = importlib.util.spec_from_file_location("live", Path(__file__).parents[1] / ".github/scripts/live-seo.py")
live = importlib.util.module_from_spec(live_spec)
live_spec.loader.exec_module(live)


class InspectionTest(unittest.TestCase):
    def test_live_html_signals(self):
        html = '<head><link rel="canonical" href="https://example.org/"><meta name="description" content="A page"><script type="application/ld+json">{"@type":"WebPage"}</script></head>'
        self.assertEqual([], live.validate(html, {"url": "https://example.org/"})[1])
        broken = html.replace('</head>', '<meta name="robots" content="noindex"></head>')
        self.assertIn("unexpected noindex", live.validate(broken, {"url": "https://example.org/"})[1])

    def test_live_html_ignores_fake_body_metadata(self):
        html = '<head></head><body><meta name="description" content="Fake"><script type="application/ld+json">{}</script></body>'
        self.assertIn("missing JSON-LD", live.validate(html, {"url": "https://example.org/"})[1])

    def test_two_observations_and_no_repeated_alert(self):
        rows = [{"url": "https://example.org/", "issues": ["blocked"]}]
        state, alerts, _ = monitor.transitions({}, rows)
        self.assertEqual([], alerts)
        state, alerts, _ = monitor.transitions(state, rows)
        self.assertEqual(["https://example.org/"], alerts)
        state, alerts, _ = monitor.transitions(state, rows)
        self.assertEqual([], alerts)
        retained, _, recovered = monitor.transitions(state, [])
        self.assertEqual(state, retained)
        self.assertEqual([], recovered)
        _, _, recovered = monitor.transitions(state, [{"url": "https://example.org/", "issues": []}])
        self.assertEqual(["https://example.org/"], recovered)

    def test_missing_is_not_healthy(self):
        self.assertEqual(["inspection unavailable"], monitor.findings({}, {"url": "https://example.org/"}))

    def test_expected_canonical(self):
        self.assertEqual([], monitor.findings({"indexStatusResult": {"verdict": "PASS",
            "googleCanonical": "https://example.org/"}}, {"url": "https://example.org/"}))

    def test_rich_result_errors(self):
        result = {"indexStatusResult": {"verdict": "PASS"}, "richResultsResult": {
            "detectedItems": [{"items": [{"issues": [{"severity": "ERROR", "issueMessage": "Missing name"}]}]}]}}
        self.assertEqual(["rich result: Missing name"], monitor.findings(result, {"url": "https://example.org/"}))

    def test_intentionally_excluded(self):
        self.assertEqual([], monitor.findings({"indexStatusResult": {"verdict": "NEUTRAL"}},
                                             {"url": "https://example.org/", "indexable": False}))


if __name__ == "__main__":
    unittest.main()
