from PIL import Image
import pytesseract

image_path = "page_2.jpg"
text = pytesseract.image_to_string(Image.open(image_path), lang='eng')

print("[INFO] OCR Result Preview:")
print(text[:1000])

with open("ocr_output.txt", "w", encoding="utf-8") as f:
    f.write(text)

print("[✅] OCR output saved to ocr_output.txt")
