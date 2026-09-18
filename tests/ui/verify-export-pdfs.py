"""Check rendered fixture PDFs after PanelExportTest (SAVE_EXPORT_QA=1)."""
from pathlib import Path
import re
import pdfplumber

root = Path("storage/framework/testing/export-qa")
files = sorted(p for p in root.glob("*.pdf") if not p.name.startswith("old-"))
assert files, "Generate fixture PDFs first"
pages_checked = 0
for file in files:
    with pdfplumber.open(file) as pdf:
        for number, page in enumerate(pdf.pages, 1):
            text = page.extract_text() or ""
            assert text.strip(), (file.name, number, "blank page")
            assert "WRONG STUDENT CLUB" not in text, (file.name, number)
            for char in page.chars:
                if char["text"].strip():
                    assert char["x0"] >= -1 and char["x1"] <= page.width + 1, (file.name, number, "horizontal overflow", char["text"])
                    assert char["top"] >= -1 and char["bottom"] <= page.height + 1, (file.name, number, "vertical overflow", char["text"])
            pages_checked += 1

for name in ["brackets", "kumite-protocols"]:
    with pdfplumber.open(root / (name + ".pdf")) as pdf:
        assert len(pdf.pages) == 7, (name, "unexpected category/page count")
        for page, size in zip(pdf.pages, [2, 3, 4, 8, 16, 32]):
            text = page.extract_text()
            for participant in range(1, size + 1):
                assert re.search(r"Константинопольский\s+Александр\s+" + str(participant) + r"(?!\d)", text), (name, size, participant)
            if size >= 4:
                words = page.extract_words()
                final = next(w for w in words if w["text"] == "Финал")
                third = next(w for w in words if w["text"] == "место")
                assert third["x0"] < final["x0"] and abs(third["top"] - final["top"]) < 1, (name, size, "third place alignment")
        assert "Round Robin" in pdf.pages[-1].extract_text()
with pdfplumber.open(root / "en-kata-tables.pdf") as pdf:
    text = "\n".join(p.extract_text() for p in pdf.pages)
    for label in ["PRELIMINARY STAGE", "FINAL", "RESULTS", "REFEREE", "JUDGE 1"]:
        assert label in text, label
print(f"PASS: {len(files)} PDFs / {pages_checked} pages; bounds, all bracket participants, third/final alignment, English kata labels")
