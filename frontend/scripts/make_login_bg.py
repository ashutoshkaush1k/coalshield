"""Login background: AVIF, WebP and JPEG versions of src/assets/login-bg.jpg at three widths.

    python frontend/scripts/make_login_bg.py

The source is a 1125 x 1989 portrait photo, so the widest version is its own width (anything wider
would only be upscaled); the browser scales it to cover larger windows. A thin border is trimmed
(the source is a phone screenshot with a rounded corner), and no metadata is kept. Each file must
stay under 300 KB. Output: src/assets/login-bg/login-bg-<width>.<avif|webp|jpg>.
"""
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
SRC = ROOT / "src" / "assets" / "login-bg.jpg"
OUT = ROOT / "src" / "assets" / "login-bg"
WIDTHS = [640, 960, 1125]
TRIM = 16          # px trimmed on every side (the screenshot's rounded corner)
LIMIT = 300_000    # bytes per file

OUT.mkdir(parents=True, exist_ok=True)
im = Image.open(SRC).convert("RGB")
im = im.crop((TRIM, TRIM, im.width - TRIM, im.height - TRIM))
for w in WIDTHS:
    w = min(w, im.width)
    h = round(im.height * w / im.width)
    frame = im.resize((w, h), Image.LANCZOS) if w != im.width else im
    for ext, opts in (("avif", {"quality": 50, "speed": 4}), ("webp", {"quality": 72, "method": 6}),
                      ("jpg", {"quality": 76, "optimize": True, "progressive": True})):
        path = OUT / f"login-bg-{w}.{ext}"
        # Lower the quality step by step until the file fits (the widest JPEG needs it).
        while True:
            frame.save(path, format={"jpg": "JPEG"}.get(ext, ext.upper()), **opts)
            size = path.stat().st_size
            if size < LIMIT or opts["quality"] <= 50:
                break
            opts = {**opts, "quality": opts["quality"] - 4}
        assert size < LIMIT, f"{path.name}: {size} bytes"
        print(f"{path.name}: {w}x{h}, {size // 1024} KB")
