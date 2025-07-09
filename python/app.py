from fastapi import FastAPI, UploadFile, File
import uvicorn
import os
from main import (
    extract_text_from_pdf,
    extract_questions_and_answers,
    is_text_pdf,
    process_file,
)

app = FastAPI()

@app.post("/process-file")
async def process_file_endpoint(file: UploadFile = File(...)):
    ext = file.filename.split('.')[-1].lower()
    contents = await file.read()
    temp_filename = f"temp.{ext}"

    with open(temp_filename, "wb") as f:
        f.write(contents)

    questions = process_file(temp_filename)

    print("Questions type:", type(questions))
    print("Sample questions:", questions[:2])

    try:
        os.remove(temp_filename)
    except Exception as e:
        print(f"Could not delete temp file: {e}")

    return {"Questions": questions}

if __name__ == "__main__":
    uvicorn.run(app, host="0.0.0.0", port=8001)
