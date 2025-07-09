from pdf2image import convert_from_path

POPPLER_PATH = r"C:\Users\LOQ\Downloads\poppler-24.08.0\Library\bin"
PDF_PATH = r"C:\laragon\www\LMCInstitute-Python\python\samples\rami.pdf"

try:
    print("[INFO] Converting PDF to images...")
    pages = convert_from_path(PDF_PATH, poppler_path=POPPLER_PATH)

    for i, img in enumerate(pages):
        img_path = f"page_{i}.jpg"
        img.save(img_path, "JPEG")
        print(f"[✅] Saved: {img_path}")
except Exception as e:
    print(f"[❌] Error during conversion: {e}")
