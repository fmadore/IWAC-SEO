#!/usr/bin/env python3
"""Read-only Search Console monitor. No third-party Python dependencies."""
import argparse
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import sys
import time
from urllib.error import HTTPError
from urllib.parse import quote
from urllib.request import Request, urlopen


def request(url, token, body=None):
    data = json.dumps(body).encode() if body is not None else None
    for attempt in range(4):
        try:
            req = Request(url, data=data, headers={
                "Authorization": "Bearer " + token, "Content-Type": "application/json"})
            with urlopen(req, timeout=30) as response:
                return json.load(response)
        except HTTPError as error:
            if error.code not in (429, 500, 502, 503, 504) or attempt == 3:
                raise RuntimeError("Google API HTTP " + str(error.code)) from None
            time.sleep(2 ** attempt)
    raise RuntimeError("Google API retries exhausted")


def findings(result, expected):
    """Unknown/missing sections are not evidence of a healthy page."""
    index = result.get("indexStatusResult", {})
    issues = []
    if not index.get("verdict"):
        return ["inspection unavailable"]
    if expected.get("indexable", True) and index["verdict"] != "PASS":
        issues.append("index: " + index.get("coverageState", index["verdict"]))
    canonical = expected.get("canonical", expected["url"])
    if index.get("googleCanonical") and index["googleCanonical"] != canonical:
        issues.append("Google canonical differs: " + index["googleCanonical"])
    rich = result.get("richResultsResult", {})
    if rich.get("verdict") == "FAIL":
        issues.append("rich results failed")
    for item in rich.get("detectedItems", []):
        for instance in item.get("items", []):
            for issue in instance.get("issues", []):
                if issue.get("severity") == "ERROR":
                    issues.append("rich result: " + issue.get("issueMessage", "error"))
    return sorted(set(issues))


def transitions(previous, observations):
    """Only compare observed URLs; require two matching findings before alerting."""
    state = dict(previous)
    alerts, recoveries = [], []
    for row in observations:
        url, issues = row["url"], row["issues"]
        if issues == ["inspection unavailable"]:
            continue
        old = previous.get(url, {})
        streak = old.get("streak", 0) + 1 if issues and issues == old.get("issues") else int(bool(issues))
        confirmed = bool(issues and streak >= 2)
        if confirmed and (not old.get("confirmed") or issues != old.get("issues")):
            alerts.append(url)
        if not issues and old.get("confirmed"):
            recoveries.append(url)
        state[url] = {"issues": issues, "streak": streak, "confirmed": confirmed}
    return state, alerts, recoveries


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--manifest", default="config/search-console-urls.json")
    parser.add_argument("--output", default="monitor-results")
    parser.add_argument("--previous", default="previous/inspection.json")
    args = parser.parse_args()
    output = Path(args.output)
    output.mkdir(parents=True, exist_ok=True)
    previous = {}
    try:
        snapshot = json.loads(Path(args.previous).read_text(encoding="utf-8"))
        if time.time() - snapshot.get("observed_at", 0) <= 7 * 86400:
            previous = snapshot.get("state", {})
    except (OSError, ValueError, TypeError):
        pass
    report = {"complete": False, "observations": [], "sitemaps": [], "errors": [],
              "baseline": bool(previous), "observed_at": time.time(),
              "time": datetime.now(timezone.utc).isoformat()}
    try:
        token, prop = os.environ["GSC_ACCESS_TOKEN"], os.environ["GSC_PROPERTY"]
        entries = json.loads(Path(args.manifest).read_text(encoding="utf-8"))
        if not entries or len(entries) > 250:
            raise ValueError("Manifest must contain 1–250 URLs")
        sitemap = request("https://www.googleapis.com/webmasters/v3/sites/"
                          + quote(prop, safe="") + "/sitemaps", token)
        report["sitemaps"] = sitemap.get("sitemap", [])
        for row in report["sitemaps"]:
            report["observations"].append({"url": "sitemap:" + row["path"],
                "issues": [str(row["errors"]) + " sitemap errors"] if int(row.get("errors", 0)) else [], "result": row})
        for entry in entries:
            result = request("https://searchconsole.googleapis.com/v1/urlInspection/index:inspect",
                             token, {"siteUrl": prop, "inspectionUrl": entry["url"],
                                     "languageCode": "en-US"})["inspectionResult"]
            issues = findings(result, entry)
            report["observations"].append({"url": entry["url"], "issues": issues, "result": result})
            if issues == ["inspection unavailable"]:
                report["errors"].append("Inspection unavailable: " + entry["url"])
        report["complete"] = not report["errors"]
    except (KeyError, ValueError, OSError, RuntimeError) as error:
        report["errors"].append(str(error))
    failures = sum(bool(row["issues"]) for row in report["observations"])
    sitemap_errors = sum(int(row.get("errors", 0)) for row in report["sitemaps"])
    report["state"], alerts, recoveries = transitions(previous, report["observations"])
    report["new_alerts"], report["recoveries"] = alerts, recoveries
    lines = ["# Search Console inspection", "",
             "Google's indexed version; this is not a live URL test or the notification inbox.", "",
             f"Complete: {report['complete']}; observations with findings: {failures}; sitemap errors: {sitemap_errors}.",
             f"Previous baseline: {bool(previous)}; new confirmed alerts: {len(alerts)}; recoveries: {len(recoveries)}."]
    if not previous:
        lines.append("No baseline: this run establishes observations; it cannot establish a recovery.")
    lines.extend("- Recovered: " + url for url in recoveries)
    for row in report["observations"]:
        if row["issues"]:
            lines.append("- " + row["url"] + ": " + "; ".join(row["issues"]))
    lines.extend("- Monitor error: " + error for error in report["errors"])
    summary = "\n".join(lines) + "\n"
    (output / "inspection.json").write_text(json.dumps(report, indent=2), encoding="utf-8")
    (output / "summary.md").write_text(summary, encoding="utf-8")
    if os.environ.get("GITHUB_STEP_SUMMARY"):
        with open(os.environ["GITHUB_STEP_SUMMARY"], "a", encoding="utf-8") as handle:
            handle.write(summary)
    print(summary)
    return 2 if not report["complete"] else int(bool(alerts))


if __name__ == "__main__":
    sys.exit(main())
