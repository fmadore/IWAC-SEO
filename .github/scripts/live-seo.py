#!/usr/bin/env python3
"""Check rendered public HTML independently of Google's last crawl."""
from html.parser import HTMLParser
import json
import os
from pathlib import Path
import sys
from urllib.request import Request, urlopen


class Head(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.inside = False
        self.script = None
        self.meta, self.links, self.jsonld = [], [], []

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == "head":
            self.inside = True
        if not self.inside:
            return
        if tag == "meta":
            self.meta.append(attrs)
        if tag == "link":
            self.links.append(attrs)
        if tag == "script" and attrs.get("type") == "application/ld+json":
            self.script = ""

    def handle_endtag(self, tag):
        if tag == "script" and self.script is not None:
            self.jsonld.append(self.script)
            self.script = None
        if tag == "head":
            self.inside = False

    def handle_data(self, data):
        if self.script is not None:
            self.script += data


def validate(html, expected):
    head = Head()
    head.feed(html)
    errors = []
    canonical = [row.get("href") for row in head.links if row.get("rel") == "canonical"]
    if canonical != [expected.get("canonical", expected["url"])]:
        errors.append("missing, duplicate or unexpected canonical")
    descriptions = [row.get("content", "").strip() for row in head.meta if row.get("name") == "description"]
    if len(descriptions) != 1 or not descriptions[0]:
        errors.append("missing or duplicate meta description")
    robots = ",".join(row.get("content", "") for row in head.meta if row.get("name", "").lower() == "robots")
    if expected.get("indexable", True) and "noindex" in robots.lower():
        errors.append("unexpected noindex")
    if not head.jsonld:
        errors.append("missing JSON-LD")
    for raw in head.jsonld:
        try:
            document = json.loads(raw)
            if not isinstance(document, (dict, list)):
                errors.append("JSON-LD must be an object or array")
        except ValueError:
            errors.append("invalid JSON-LD")
    return head, errors


def main():
    manifest = json.loads(Path("config/search-console-urls.json").read_text(encoding="utf-8"))
    results, heads = [], {}
    for entry in manifest:
        try:
            request = Request(entry["url"], headers={"User-Agent": "IWAC-SEO-check/1.1"})
            with urlopen(request, timeout=30) as response:
                body = response.read(8 * 1024 * 1024 + 1)
                if len(body) > 8 * 1024 * 1024:
                    raise ValueError("HTML exceeds checker's 8 MiB limit")
                head, errors = validate(body.decode("utf-8"), entry)
                if response.url != entry["url"]:
                    errors.append("sentinel redirects")
                if entry.get("indexable", True) and "noindex" in response.headers.get("X-Robots-Tag", "").lower():
                    errors.append("unexpected X-Robots-Tag noindex")
                heads[entry["url"]] = head
        except (OSError, ValueError) as error:
            errors = [str(error)]
        results.append({"url": entry["url"], "errors": errors})
    for result in results:
        for link in heads.get(result["url"], Head()).links:
            target = link.get("href")
            if link.get("hreflang") and target in heads:
                reciprocal = any(row.get("href") == result["url"] and row.get("hreflang") for row in heads[target].links)
                if not reciprocal:
                    result["errors"].append("non-reciprocal hreflang: " + target)
    output = Path("live-seo-results")
    output.mkdir(exist_ok=True)
    (output / "checks.json").write_text(json.dumps(results, indent=2), encoding="utf-8")
    lines = ["# Live SEO checks", "", "Public HTML only; no claim about Google's indexing or video prominence.", ""]
    lines += ["- " + row["url"] + ": " + "; ".join(row["errors"]) for row in results if row["errors"]]
    if len(lines) == 4:
        lines.append("All sentinel checks passed.")
    summary = "\n".join(lines) + "\n"
    print(summary)
    if os.environ.get("GITHUB_STEP_SUMMARY"):
        with open(os.environ["GITHUB_STEP_SUMMARY"], "a", encoding="utf-8") as handle:
            handle.write(summary)
    return int(any(row["errors"] for row in results))


if __name__ == "__main__":
    sys.exit(main())
