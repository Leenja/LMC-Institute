import pytesseract
import pdfplumber
from PIL import Image
from pdf2image import convert_from_path
import os
import requests
import json
import re
import wordninja
import requests
import difflib

LOGIN_URL = "http://localhost:8000/api/LoginSuperAdmin"
UPLOAD_URL = "http://localhost:8000/api/super-admin/upload-placement-questions"

LOGIN_CREDENTIALS = {
    "email": "super@admin.com",
    "password": "password"
}

def login_and_get_token():
    try:
        response = requests.post(LOGIN_URL, json=LOGIN_CREDENTIALS)
        response.raise_for_status()
        token = response.json().get("token")
        print(f"Logged in successfully. Token acquired.")
        return token
    except Exception as e:
        print("Failed to login.")
        print(e)
        return None

def is_text_pdf(pdf_path):
    with pdfplumber.open(pdf_path) as pdf:
        for page in pdf.pages:
            if page.extract_text():
                return True
    return False

SKIP_PHRASES = [
    "now go to page", "look at the pictures", "this is the end of", "begin the",
    "objective placement test", "Cam Scanner", "in this section", "end of test",
    "go on to the next page", "stop now", "you will now hear", "test ends here", "turn to page",
    "Now go on to page","S E","n. 5 Q -c n. b N N§ Q.. .a~ gr,"
]

section_header_pattern = re.compile(r'section\s*([I1l]{1,3})[:\-]?\s*(language\s*use|reading|listening)', re.IGNORECASE)
language_use_intro_pattern = re.compile(
    r'(begin\s+the\s+language\s+use\s+section|go\s+on\s+to\s+page\s+\d+.*language\s+use\s+section|language\s+use\s+you\s+will\s+answer)',
    re.IGNORECASE
)

section_map = {
    'I': 'Listening',
    'II': 'Reading',
    'III': 'LanguageUse',
    'lIl': 'LanguageUse',
    'IlI': 'LanguageUse',
    'lll': 'LanguageUse',
    'Ill': 'LanguageUse',
}

def should_skip_line(line):
    for phrase in SKIP_PHRASES:
        if phrase.lower() in line.lower():
            return True
    return False


def normalize_line(line):
    return (
        line.lower()
        .replace('lan ua e', 'language')
        .replace('s ¢ion', 'section')
        .replace('lIl', 'III')
        .replace('IlI', 'III')
        .replace('lll', 'III')
        .replace(' ', '')
    )


def smart_word_split(line):
    fixed_line = ''
    for word in line.split():
        if len(word) > 10 or re.match(r'^[a-z]{12,}$', word):
            split_words = wordninja.split(word)
            if len(split_words) <= 1:
                split_words = re.findall(r'[A-Z]?[a-z]+|[A-Z]+(?![a-z])', word)
            fixed_line += ' ' + ' '.join(split_words)
        else:
            fixed_line += ' ' + word
    return fixed_line.strip()


def clean_and_fix_text(raw_text):
    lines = raw_text.splitlines()
    cleaned_lines = []

    for line in lines:
        line = line.strip()
        if not line:
            continue

        preserved_blanks = re.findall(r'(\_+|\.+)', line)

        if "____" in line or re.search(r'_+', line):
            cleaned_lines.append(line)
            continue

        line = smart_word_split(line)

        line = re.sub(r'\\', '', line)
        line = re.sub(r'\[\d+\]', '', line)
        line = re.sub(r'([a-z])([A-Z])', r'\1 \2', line)
        line = re.sub(r'([a-z])([A-Z][a-z])', r'\1 \2', line)
        line = re.sub(r'([a-z])(\'[A-Z])', r'\1 \2', line)

        line = re.sub(r'\s+', ' ', line)

        for blank in preserved_blanks:
            if blank not in line:
                line += f" {blank}"

        cleaned_lines.append(line)

    return '\n'.join(cleaned_lines)


def find_best_blank_position(question_text, answers):
    if not question_text:
        return "__________"

    question_text = question_text.strip()
    words = question_text.split()
    answer_texts = [ans["AnswerText"].strip().lower() for ans in answers if ans["AnswerText"]]

    best_score = 0
    best_index = -1
    for idx, word in enumerate(words):
        for answer in answer_texts:
            score = difflib.SequenceMatcher(None, word.lower(), answer).ratio()
            if score > best_score and score > 0.75:
                best_score = score
                best_index = idx

    if best_index != -1:
        words[best_index] = "__________"
        return " ".join(words)

    # Fallback strategy:
    if re.match(r'.*\b(is|are|was|were|has|have|had|can|will|could|should|would|may|might|must|to|by|at|for|in|with|on|about|as|from)\b[\.?]?$', question_text, re.IGNORECASE):
        return question_text + " __________"

    if words[0].lower() in ['who', 'what', 'when', 'where', 'why', 'how'] and len(words) > 2:
        return words[1] + " " + "__________ " + " ".join(words[2:])

    if question_text[0].isupper() and question_text[-1] in '.?':
        return " ".join(words[:-1]) + " __________"

    return question_text + " __________"


def extract_text_from_pdf(pdf_path):
    print(f"[INFO] Processing PDF: {pdf_path}")
    text = ''
    with pdfplumber.open(pdf_path) as pdf:
        for page in pdf.pages:
            page_text = page.extract_text()
            if page_text:
                text += page_text + '\n'
    return text


def extract_questions_and_answers(text):
    text = clean_and_fix_text(text)
    lines = text.splitlines()
    questions = []
    i = 0
    current_context = ""
    current_context_full = ""
    current_section = None
    context_lines = []
    reading_section_finished = False
    question_counter_after_reading = 0

    while i < len(lines):
        line = lines[i].strip()
        norm_line = normalize_line(line)

        if should_skip_line(line):
            print(f"[SKIP] Ignored line: {line}")
            i += 1
            continue

        # Detect Section
        header_match = section_header_pattern.match(norm_line)
        if header_match:
            roman = header_match.group(1).upper().replace('1', 'I').replace('l', 'I')
            section_title = section_map.get(roman, None)
            if section_title:
                if current_section and section_title != current_section:
                    print(f"[INFO] End of section: {current_section}")

                current_section = section_title
                print(f"[DEBUG] Switched to section: {current_section}")

                if section_title == "Reading":
                    reading_section_finished = False
                elif section_title == "LanguageUse":
                    reading_section_finished = True

                current_context = ""
                current_context_full = ""
                question_counter_after_reading = 0
                i += 1
                continue

        if language_use_intro_pattern.search(line.lower()):
            print("[INFO] Found LanguageUse intro sentence. Switching section to 'LanguageUse'")
            current_section = "LanguageUse"
            reading_section_finished = True
            current_context = ""
            current_context_full = ""
            question_counter_after_reading = 0
            i += 1
            continue

        # Detect context (Situation / Passage)
        context_match = re.match(r'^(Situation|Passage)\s*\d*[:\-]?\s*(.*)', line, re.IGNORECASE)
        if context_match:
            current_context = context_match.group(0)
            context_lines = [current_context]

            # For Reading: capture the full paragraph
            if current_section == "Reading":
                j = i + 1
                while j < len(lines):
                    next_line = lines[j].strip()
                    if re.match(r'^\d+[\).:-]', next_line) or re.match(r'^[A-Da-d][\).:-]', next_line):
                        break
                    if next_line:
                        context_lines.append(next_line)
                    j += 1
                current_context_full = '\n'.join(context_lines)
                i = j
                continue
            else:
                current_context_full = current_context
                i += 1
                continue

        # Detect question
        question_match = None
        patterns = [
            r'^\d+[\).:-]\s*(.+)',     # 1. Question
            r'^Q\d*[:\-]?\s*(.+)',        # Q1: Question
            r'^[•\-]\s*(.+)',             # • Question
            r'^(?:[A-Z][^a-z]{1,10}\s*)?(.+?\?)(?:\s*\(.+\))?$' # Anything ending with '?'
        ]

        for pattern in patterns:
            question_match = re.match(pattern, line)
            if question_match:
                break

        if question_match:
            question_text = question_match.group(question_match.lastindex).strip()

            number_match = re.match(r'^(\d+)[\).:\-]', line)
            question_number = int(number_match.group(1)) if number_match else None

            if question_number and question_number >= 41 and current_section != "LanguageUse":
                print(f"[HACK] Forcing switch to LanguageUse at question {question_number}")
                current_section = "LanguageUse"
                reading_section_finished = True

            # Add continuation lines
            j = i + 1
            while j < len(lines):
                next_line = lines[j].strip()
                if next_line and not re.match(r'^[A-Da-d][\)\.:\-]', next_line) and not re.match(r'^\d+[\).:-]', next_line):
                    question_text += ' ' + next_line
                    j += 1
                else:
                    break

            # Extract answers
            answers = []
            while j < len(lines):
                answer_line = lines[j].strip()
                answer_match = re.match(r'^[a-dA-D][\)\.:\-]\s*(.+)', answer_line)
                if answer_match:
                    ans_text = answer_match.group(1).strip()

                    if len(ans_text) > 15 or re.fullmatch(r'[a-z]+', ans_text.lower()):
                        ans_text = ' '.join(wordninja.split(ans_text))

                    is_correct = ans_text.startswith('✓') or ans_text.startswith('*')
                    if is_correct:
                        ans_text = ans_text[1:].strip()

                    answers.append({
                        "AnswerText": ans_text,
                        "isCorrect": is_correct
                    })
                    j += 1
                else:
                    break

            if answers:
                first_option = answers[0]['AnswerText']
                replaced_question_text = find_best_blank_position(question_text, answers)

                questions.append({
                    "Section": current_section,
                    "Context": current_context_full if current_section in ["Listening", "Reading"] and not reading_section_finished else None,
                    "QuestionText": replaced_question_text,
                    "Answers": answers
                })
            i = j
        else:
            i += 1

    return questions


def send_questions_to_api(questions_json, token):
    try:
        headers = {
            "Authorization": f"Bearer {token}",
            "Content-Type": "application/json"
        }

        response = requests.post(UPLOAD_URL, headers=headers, json=questions_json)
        response.raise_for_status()
        print("Questions uploaded successfully!")
        print("API response:", response.json())

    except requests.exceptions.HTTPError as http_err:
        print(f"HTTP error occurred: {http_err}")
        print(f"Response content: {response.text}")
    except Exception as err:
        print(f"Error: {err}")


def process_file(file_path):
    ext = os.path.splitext(file_path)[1].lower()
    text = ''

    if ext == '.pdf':
        if is_text_pdf(file_path):
            text = extract_text_from_pdf(file_path)
    else:
        print("Unsupported file type")
        return []

    print(f"[DEBUG] Length of extracted text: {len(text)}")

    if not text.strip():
        print("[ERROR] No text extracted from the file.")
        return []

    questions_json = extract_questions_and_answers(text)
    print(f"\n[INFO] Extracted {len(questions_json)} questions")

    #with open("output.txt", "w", encoding="utf-8") as f:
        #f.write(text)

    return questions_json
