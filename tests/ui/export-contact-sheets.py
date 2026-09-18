from pathlib import Path
import subprocess
from pypdf import PdfReader
from PIL import Image, ImageDraw
import sys
root = Path("storage/framework/testing/export-qa")
poppler = Path(sys.argv[1])
for file in sorted(root.glob("*.pdf")):
    pages = len(PdfReader(file).pages)
    prefix = root / file.stem
    for previous in root.glob(file.stem + '-[0-9]*.png'):
        previous.unlink()
    for previous in root.glob(file.stem + '-contact-*.png'):
        previous.unlink()
    subprocess.run([str(poppler / "pdftoppm"), "-scale-to", "700", "-png", str(file), str(prefix)], check=True)
    images = sorted(root.glob(file.stem + "-[0-9]*.png"))
    for start in range(0, len(images), 9):
        subset = images[start:start+9]
        canvas = Image.new("RGB", (1260, ((len(subset)+2)//3)*625), "#dddddd")
        draw = ImageDraw.Draw(canvas)
        for i,path in enumerate(subset):
            picture = Image.open(path).convert("RGB")
            picture.thumbnail((410,590))
            x=(i%3)*420; y=(i//3)*625
            draw.text((x+5,y+5),path.name,fill="black")
            canvas.paste(picture,(x+(420-picture.width)//2,y+25))
        canvas.save(root / (file.stem + "-contact-" + str(start//9+1) + ".png"))
    print(file.name, pages, flush=True)
