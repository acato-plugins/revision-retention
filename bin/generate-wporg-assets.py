#!/usr/bin/env python3
"""Generate the WordPress.org banner and icon assets for this plugin.

Acato uses one shared artwork template across its WordPress.org plugins: the
same background, the same mascot and the same wordmark, with only the plugin
title differing. This script takes that published template, clears the title
area and stamps a new title in its place, then writes every size WordPress.org
expects into .wordpress-org/.

Usage:
    python3 bin/generate-wporg-assets.py
    python3 bin/generate-wporg-assets.py --title "Revision|Retention"

Requires Pillow:  python3 -m pip install --user Pillow
"""

from __future__ import annotations

import argparse
import io
import pathlib
import sys
import urllib.request

from PIL import Image, ImageDraw, ImageFont

# An existing Acato plugin on WordPress.org supplies the shared template.
TEMPLATE_BANNER = "https://ps.w.org/wp-rest-cache/assets/banner-1544x500.png"
TEMPLATE_ICON = "https://ps.w.org/wp-rest-cache/assets/icon-256x256.png"

# Geometry measured from the template. The title sits on a flat dark field on
# the left; the artwork starts around x=840.
TITLE_LEFT = 83
TITLE_CAP_TOP = 81
LINE_HEIGHT = 118
TITLE_MAX_RIGHT = 843
CLEAR_BOX = (0, 70, 838, 340)
CLEAN_ROW_ABOVE = 68
CLEAN_ROW_BELOW = 344

# Helvetica Neue Bold is the closest match to the template's grotesque.
FONT_CANDIDATES = [
    ("/System/Library/Fonts/HelveticaNeue.ttc", "Bold"),
    ("/System/Library/Fonts/Helvetica.ttc", "Bold"),
    ("/System/Library/Fonts/Supplemental/Arial Bold.ttf", None),
]

# Helvetica's cap height as a fraction of the em, used to size from cap height.
CAP_HEIGHT_RATIO = 0.714


def load_font(size: int) -> ImageFont.FreeTypeFont:
    """Return the closest available bold grotesque at the given size."""
    for path, want_bold in FONT_CANDIDATES:
        if not pathlib.Path(path).exists():
            continue
        if want_bold is None:
            return ImageFont.truetype(path, size)
        for index in range(12):
            try:
                font = ImageFont.truetype(path, size, index=index)
            except OSError:
                break
            name = " ".join(font.getname())
            if "Bold" in name and "Italic" not in name and "Condensed" not in name:
                return font
    raise SystemExit("No suitable bold font found on this system.")


def fetch(url: str) -> Image.Image:
    """Download an image."""
    with urllib.request.urlopen(url) as response:  # noqa: S310 - fixed https URL
        return Image.open(io.BytesIO(response.read())).convert("RGBA")


def clear_title(banner: Image.Image) -> None:
    """Repaint the title area with the background behind it.

    The field is a smooth vertical gradient, so every column is rebuilt by
    interpolating between a clean row above and a clean row below the text.
    """
    pixels = banner.load()
    left, top, right, bottom = CLEAR_BOX
    span = CLEAN_ROW_BELOW - CLEAN_ROW_ABOVE

    for x in range(left, right):
        start = pixels[x, CLEAN_ROW_ABOVE]
        end = pixels[x, CLEAN_ROW_BELOW]
        for y in range(top, bottom):
            ratio = (y - CLEAN_ROW_ABOVE) / span
            pixels[x, y] = tuple(
                round(start[channel] + (end[channel] - start[channel]) * ratio)
                for channel in range(4)
            )


def fit_font(lines: list[str], cap_height: int) -> ImageFont.FreeTypeFont:
    """Pick the largest size at or below cap_height that keeps every line inside."""
    size = round(cap_height / CAP_HEIGHT_RATIO)
    while size > 10:
        font = load_font(size)
        widest = max(font.getbbox(line)[2] - font.getbbox(line)[0] for line in lines)
        if TITLE_LEFT + widest <= TITLE_MAX_RIGHT:
            return font
        size -= 2
    raise SystemExit("Title does not fit, even at the smallest size.")


def draw_title(banner: Image.Image, lines: list[str]) -> None:
    """Stamp the title onto the cleared area."""
    font = fit_font(lines, cap_height=74)
    scale = font.size / round(74 / CAP_HEIGHT_RATIO)
    draw = ImageDraw.Draw(banner)

    for index, line in enumerate(lines):
        # getbbox()[1] is the offset from the drawing origin to the cap top, so
        # subtracting it lands the cap exactly on the intended baseline grid.
        top = TITLE_CAP_TOP + round(index * LINE_HEIGHT * scale) - font.getbbox(line)[1]
        draw.text((TITLE_LEFT, top), line, font=font, fill=(255, 255, 255, 255))


def main() -> int:
    """Build every asset."""
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--title",
        default="Revision|Retention",
        help="Banner title, use | to separate lines.",
    )
    parser.add_argument(
        "--output",
        default=str(pathlib.Path(__file__).resolve().parent.parent / ".wordpress-org"),
        help="Directory to write the assets into.",
    )
    args = parser.parse_args()

    lines = [part.strip() for part in args.title.split("|") if part.strip()]
    output = pathlib.Path(args.output)
    output.mkdir(parents=True, exist_ok=True)

    banner = fetch(TEMPLATE_BANNER)
    clear_title(banner)
    draw_title(banner, lines)
    banner.save(output / "banner-1544x500.png")
    banner.resize((772, 250), Image.LANCZOS).save(output / "banner-772x250.png")

    icon = fetch(TEMPLATE_ICON)
    icon.save(output / "icon-256x256.png")
    icon.resize((128, 128), Image.LANCZOS).save(output / "icon-128x128.png")

    print(f"Wrote banner and icon assets to {output}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
