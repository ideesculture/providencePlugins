#!/usr/bin/env python3
"""
stamp_pages.py — Add footers to catalogue PDF pages.

Usage: python3 stamp_pages.py <input.pdf> <chapters.json> <output.pdf>

chapters.json format:
[
    {"title": "Chapter title", "start_page": 1, "count": 15, "is_title_page": true},
    {"title": "Chapter title", "start_page": 2, "count": 15, "is_title_page": false},
    ...
]

Each page gets:
  - Bottom left: chapter title — X/Y
  - Bottom right: Page N / Total
"""

import sys
import json
import io

from reportlab.pdfgen import canvas
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont

# Try to register Marianne font
FONT_NAME = 'Helvetica'
try:
    pdfmetrics.registerFont(TTFont('Marianne', '/usr/local/share/fonts/Marianne-Regular.otf'))
    FONT_NAME = 'Marianne'
except:
    pass

def main():
    if len(sys.argv) != 4:
        print("Usage: python3 stamp_pages.py <input.pdf> <chapters.json> <output.pdf>")
        sys.exit(1)

    input_pdf = sys.argv[1]
    chapters_file = sys.argv[2]
    output_pdf = sys.argv[3]

    with open(chapters_file, 'r') as f:
        chapters = json.load(f)

    # Build page -> chapter info mapping
    # chapters list has one entry per page, in order
    page_info = {}
    for ch in chapters:
        pg = ch['start_page']
        page_info[pg] = ch

    # Import pypdf here to avoid import error if not installed
    try:
        from pypdf import PdfReader, PdfWriter
    except ImportError:
        from PyPDF2 import PdfReader, PdfWriter

    reader = PdfReader(input_pdf)
    writer = PdfWriter()
    total_pages = len(reader.pages)

    for page_num in range(total_pages):
        page = reader.pages[page_num]
        page_width = float(page.mediabox.width)
        page_height = float(page.mediabox.height)

        # Find chapter info for this page
        info = page_info.get(page_num + 1)

        # Create overlay
        packet = io.BytesIO()
        c = canvas.Canvas(packet, pagesize=(page_width, page_height))
        c.setFont(FONT_NAME, 8)
        c.setFillColorRGB(0.5, 0.5, 0.5)

        margin_left = 15 * mm
        margin_right = 15 * mm
        margin_bottom = 8 * mm

        # Draw separator line
        c.setStrokeColorRGB(0.8, 0.8, 0.8)
        c.setLineWidth(0.5)
        c.line(margin_left, margin_bottom + 10, page_width - margin_right, margin_bottom + 10)

        # Bottom left: chapter info
        if info and not info.get('is_title_page', False):
            left_text = u"%s \u2014 %d/%d" % (info['title'], info['index'], info['count'])
            c.drawString(margin_left, margin_bottom, left_text)

        # Bottom right: page number
        right_text = "Page %d / %d" % (page_num + 1, total_pages)
        c.drawRightString(page_width - margin_right, margin_bottom, right_text)

        c.save()
        packet.seek(0)

        # Merge overlay onto page
        overlay_reader = PdfReader(packet)
        page.merge_page(overlay_reader.pages[0])
        writer.add_page(page)

    with open(output_pdf, 'wb') as f:
        writer.write(f)


if __name__ == '__main__':
    main()
