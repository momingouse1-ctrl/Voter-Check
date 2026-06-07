#!/usr/bin/env python3
"""
PDF Text Extraction Helper Script
Called by Laravel PdfTextExtractor service as a fallback.

Usage:
  python extract_pdf_text.py <pdf_path> <mode>

Modes:
  text  - Extract text using pymupdf/pdfplumber
  ocr   - Extract text using Tesseract OCR
  count - Just count pages (returns JSON with 'pages' key)

Output:
  JSON array to stdout: [{"page": 1, "text": "...", "method": "..."}, ...]
"""

import sys
import json
import os

def extract_with_fitz(pdf_path):
    """Extract text using PyMuPDF (fitz) - best for custom fonts."""
    import fitz
    doc = fitz.open(pdf_path)
    pages = []
    for i, page in enumerate(doc):
        text = page.get_text("text")
        # Also try extracting as blocks for better structure
        if not text or len(text.strip()) < 10:
            blocks = page.get_text("blocks")
            text = "\n".join([b[4] for b in blocks if len(b) > 4])
        pages.append({
            "page": i + 1,
            "text": text or "",
            "method": "fitz"
        })
    doc.close()
    return pages


def extract_with_pdfplumber(pdf_path):
    """Extract text using pdfplumber - good for tables and layouts."""
    import pdfplumber
    pages = []
    with pdfplumber.open(pdf_path) as pdf:
        for i, page in enumerate(pdf.pages):
            text = page.extract_text() or ""
            pages.append({
                "page": i + 1,
                "text": text,
                "method": "pdfplumber"
            })
    return pages


def extract_with_ocr(pdf_path):
    """Extract text using Tesseract OCR via PyMuPDF rendering + pytesseract."""
    import pytesseract
    import fitz
    from PIL import Image

    import os
    # Set explicit paths for Windows
    pytesseract.pytesseract.tesseract_cmd = r"C:\Program Files\Tesseract-OCR\tesseract.exe"
    os.environ['TESSDATA_PREFIX'] = r"D:\My Projects\Voter List\backend\tessdata"
    lang = "tel+eng"

    pages = []
    try:
        doc = fitz.open(pdf_path)
        for i, page in enumerate(doc):
            # Render page to an image (zoom 2x for better OCR quality)
            zoom_matrix = fitz.Matrix(2.0, 2.0)
            pix = page.get_pixmap(matrix=zoom_matrix)
            
            # Convert PyMuPDF pixmap to PIL Image
            if pix.n - pix.alpha < 4:      # GRAY or RGB
                mode = "RGBA" if pix.alpha else "RGB"
            else:                          # CMYK
                mode = "RGBA" if pix.alpha else "CMYK"
            
            img = Image.frombytes(mode, [pix.width, pix.height], pix.samples)

            # Extract text
            try:
                text = pytesseract.image_to_string(img, lang=lang)
            except Exception as e:
                # Fallback to English
                try:
                    text = pytesseract.image_to_string(img, lang="eng")
                except Exception:
                    text = ""
                    
            pages.append({
                "page": i + 1,
                "text": text or "",
                "method": "ocr"
            })
        doc.close()
    except Exception as e:
        raise Exception(f"OCR failed: {str(e)}")
        
    return pages


def count_pages(pdf_path):
    """Count pages without full extraction."""
    try:
        import fitz
        doc = fitz.open(pdf_path)
        count = len(doc)
        doc.close()
        return count
    except Exception:
        try:
            import pdfplumber
            with pdfplumber.open(pdf_path) as pdf:
                return len(pdf.pages)
        except Exception:
            return 0


def main():
    if len(sys.argv) < 3:
        print(json.dumps({"error": "Usage: extract_pdf_text.py <path> <mode>"}))
        sys.exit(1)

    pdf_path = sys.argv[1]
    mode = sys.argv[2]

    if not os.path.exists(pdf_path):
        print(json.dumps({"error": f"File not found: {pdf_path}"}))
        sys.exit(1)

    if mode == "count":
        print(json.dumps({"pages": count_pages(pdf_path)}))
        return

    pages = []
    error = None

    if mode == "ocr":
        try:
            pages = extract_with_ocr(pdf_path)
        except Exception as e:
            error = str(e)

    else:  # mode == "text"
        # Try fitz first, then pdfplumber
        try:
            pages = extract_with_fitz(pdf_path)
        except Exception as e1:
            try:
                pages = extract_with_pdfplumber(pdf_path)
            except Exception as e2:
                error = f"fitz: {e1}, pdfplumber: {e2}"

    if error and not pages:
        print(json.dumps({"error": error}))
        sys.exit(1)

    print(json.dumps(pages))


if __name__ == "__main__":
    main()
