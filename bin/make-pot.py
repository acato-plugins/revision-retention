#!/usr/bin/env python3
"""Build languages/revision-retention.pot from the plugin's source.

`wp i18n make-pot` is the tool of record; this exists so the template can be
refreshed on a machine without WP-CLI. It understands the context carrying
functions the plugin uses throughout — _x, _ex, esc_html_x, esc_attr_x and
_nx — and writes the context out as msgctxt, which is what makes two identical
English strings translatable apart.
"""

from __future__ import annotations

import datetime
import os
import pathlib
import re

DOMAIN = "revision-retention"
ROOT = pathlib.Path(__file__).resolve().parent.parent
STR = r"""('(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*")"""
SINGLE = r"(?:_x|_ex|esc_html_x|esc_attr_x)"

HEADERS = [
    ("Revision Retention", "Plugin Name of the plugin"),
    ("https://github.com/acato-plugins/revision-retention", "Plugin URI of the plugin"),
    (
        "Give post revisions a retention policy: keep the newest few, drop the ones "
        "older than a threshold, per post type, on a schedule or from WP-CLI.",
        "Description of the plugin",
    ),
    ("Acato", "Author of the plugin"),
    ("https://acato.nl", "Author URI of the plugin"),
]


def unquote(token: str) -> str:
    body = token[1:-1]
    if token[0] == "'":
        return body.replace("\\'", "'").replace("\\\\", "\\")
    return body.replace('\\"', '"').replace("\\\\", "\\")


def escape(text: str) -> str:
    return (
        text.replace("\\", "\\\\")
        .replace('"', '\\"')
        .replace("\n", "\\n")
        .replace("\t", "\\t")
    )


def sources() -> list[str]:
    files = ["revision-retention.php", "uninstall.php"]
    files += sorted(f"includes/{name}" for name in os.listdir(ROOT / "includes") if name.endswith(".php"))
    return files


def main() -> int:
    entries: dict[tuple[str, str, str | None], dict] = {}
    comment_pattern = re.compile(r"/\*\s*translators:\s*(.*?)\*/", re.S | re.I)

    def add(context, msgid, plural, ref, comment):
        entry = entries.setdefault((context, msgid, plural), {"refs": [], "comments": []})
        if ref not in entry["refs"]:
            entry["refs"].append(ref)
        if comment and comment not in entry["comments"]:
            entry["comments"].append(comment)

    for relative in sources():
        source = (ROOT / relative).read_text(encoding="utf-8")
        starts = [0]
        for line in source.split("\n"):
            starts.append(starts[-1] + len(line) + 1)

        def line_of(index: int) -> int:
            return sum(1 for start in starts if start <= index)

        def preceding_comment(index: int) -> str:
            window = source[max(0, index - 400):index]
            found = None
            for found in comment_pattern.finditer(window):
                pass
            if not found or len(window) - found.end() > 250:
                return ""
            return "translators: " + " ".join(found.group(1).split())

        for match in re.finditer(SINGLE + r"\(\s*" + STR + r"\s*,\s*" + STR + r"\s*,\s*" + STR + r"\s*\)", source):
            if unquote(match.group(3)) != DOMAIN:
                continue
            add(
                unquote(match.group(2)),
                unquote(match.group(1)),
                None,
                f"{relative}:{line_of(match.start())}",
                preceding_comment(match.start()),
            )

        for match in re.finditer(
            r"_nx\(\s*" + STR + r"\s*,\s*" + STR + r"\s*,(.*?),\s*" + STR + r"\s*,\s*" + STR + r"\s*\)",
            source,
            re.S,
        ):
            if unquote(match.group(5)) != DOMAIN:
                continue
            add(
                unquote(match.group(4)),
                unquote(match.group(1)),
                unquote(match.group(2)),
                f"{relative}:{line_of(match.start())}",
                preceding_comment(match.start()),
            )

    # A plugin header string that is also used in the code becomes one entry
    # with both references rather than a duplicate definition.
    merged: dict[tuple[str, str, str | None], dict] = {}
    for text, label in HEADERS:
        entry = merged.setdefault(("", text, None), {"refs": [], "comments": []})
        entry["comments"].append(label)
        entry["refs"].append("revision-retention.php")

    for key, data in entries.items():
        entry = merged.setdefault(key, {"refs": [], "comments": []})
        for comment in data["comments"]:
            if comment not in entry["comments"]:
                entry["comments"].append(comment)
        for ref in data["refs"]:
            if ref not in entry["refs"]:
                entry["refs"].append(ref)

    now = datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%S+00:00")
    out = [
        f"# Copyright (C) {datetime.date.today().year} Acato",
        "# This file is distributed under the GPL-2.0-or-later.",
        'msgid ""',
        'msgstr ""',
        '"Project-Id-Version: Revision Retention 1.1.0\\n"',
        f'"Report-Msgid-Bugs-To: https://wordpress.org/support/plugin/{DOMAIN}\\n"',
        '"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n"',
        '"Language-Team: LANGUAGE <LL@li.org>\\n"',
        '"MIME-Version: 1.0\\n"',
        '"Content-Type: text/plain; charset=UTF-8\\n"',
        '"Content-Transfer-Encoding: 8bit\\n"',
        f'"POT-Creation-Date: {now}\\n"',
        '"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n"',
        f'"X-Domain: {DOMAIN}\\n"',
        "",
    ]

    for (context, msgid, plural), data in merged.items():
        out += [f"#. {comment}" for comment in data["comments"]]
        out.append("#: " + " ".join(data["refs"]))
        if context:
            out.append(f'msgctxt "{escape(context)}"')
        out.append(f'msgid "{escape(msgid)}"')
        if plural is None:
            out.append('msgstr ""')
        else:
            out.append(f'msgid_plural "{escape(plural)}"')
            out += ['msgstr[0] ""', 'msgstr[1] ""']
        out.append("")

    (ROOT / "languages" / f"{DOMAIN}.pot").write_text("\n".join(out), encoding="utf-8")
    print(f"{len(merged)} strings written to languages/{DOMAIN}.pot")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
