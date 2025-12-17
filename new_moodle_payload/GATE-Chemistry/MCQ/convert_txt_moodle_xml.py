#!/usr/bin/env python3
# convert_txt_moodle_xml.py
#
# Usage:
#   python convert_txt_moodle_xml.py --folder inorganic
#
# Requirements:
#   pip install lxml
#
# Output:
#   - For each *.txt: writes same-name *.xml in same folder
#   - Writes output.log in same folder
#
# Uses real <![CDATA[...]]> via lxml.etree.CDATA. [web:71]

import argparse
import logging
import re
from dataclasses import dataclass, field
from pathlib import Path
from typing import Dict, List, Optional, Tuple

from lxml import etree as ET
from lxml.etree import CDATA  # CDATA support. [web:71]


# -------------------- Data model --------------------
@dataclass
class MCQ:
    qnum: int
    question: str = ""
    options: Dict[str, str] = field(default_factory=dict)  # "A"/"B"/"C"/"D" -> text
    correct_key: Optional[str] = None                      # "A"/"B"/"C"/"D"
    explanation: str = ""                                  # stored as generalfeedback


# -------------------- Regex patterns --------------------
QSTART_RE = re.compile(r"^\s*(\d+)\)\s*(.*)\s*$")                 # 1) Question...
OPT_RE = re.compile(r"^\s*([A-D])\s*[\)\.]\s*(.*)\s*$")           # A) ... OR A. ...
CORRECT_RE = re.compile(r"^\s*Correct\s*answer\s*:\s*(.+?)\s*$", re.IGNORECASE)
EXPL_RE = re.compile(r"^\s*Explanation\s*:\s*(.*)\s*$", re.IGNORECASE)
CITE_RE = re.compile(r"^\s*\(cite url\)cite url\s*$", re.IGNORECASE)


def normalize_space(s: str) -> str:
    return re.sub(r"\s+", " ", s).strip()


def normalize_correct_letter(token: str) -> Optional[str]:
    m = re.match(r"^\s*([A-D])\b", token.strip(), re.IGNORECASE)
    return m.group(1).upper() if m else None


def make_question_name(question_text: str, max_words: int = 5, max_chars: int = 80) -> str:
    """
    Build a short question name from the first N words of the question text.
    Keeps LaTeX/backslashes as-is; strips only leading numbering/punctuation.
    """
    text = normalize_space(question_text)

    # Remove any leading "1)" style if it somehow remains
    text = re.sub(r"^\d+\)\s*", "", text)

    # Split into words (whitespace-based)
    words = text.split()
    title = " ".join(words[:max_words]) if words else "Question"

    # Trim trailing punctuation that looks odd in a name
    title = title.strip().rstrip(":;,.")  # keep ) ] } etc as-is

    # Enforce max length for Moodle UI sanity
    if len(title) > max_chars:
        title = title[:max_chars].rstrip()

    return title or "Question"


# -------------------- Parsing --------------------
def parse_questions(lines: List[str], logger: logging.Logger, filename: str) -> Tuple[List[MCQ], List[str]]:
    questions: List[MCQ] = []
    errors: List[str] = []

    cur: Optional[MCQ] = None
    last_field: Optional[Tuple[str, Optional[str]]] = None  # ("question"/"option"/"explanation", opt_key)

    def finalize():
        nonlocal cur
        if cur is not None:
            questions.append(cur)
            cur = None

    for lineno, raw in enumerate(lines, start=1):
        line = raw.rstrip("\n")  # preserve LaTeX and special characters

        if CITE_RE.match(line):
            continue

        m = QSTART_RE.match(line)
        if m:
            finalize()
            cur = MCQ(qnum=int(m.group(1)), question=m.group(2).rstrip())
            last_field = ("question", None)
            continue

        if cur is None:
            if line.strip():
                logger.debug(f"{filename}:{lineno} ignored outside any question: {line.strip()}")
            continue

        m = OPT_RE.match(line)
        if m:
            key = m.group(1).upper()
            cur.options[key] = m.group(2).rstrip()
            last_field = ("option", key)
            continue

        m = CORRECT_RE.match(line)
        if m:
            token = m.group(1).strip()
            letter = normalize_correct_letter(token)
            if letter:
                cur.correct_key = letter
            else:
                # Type II: correct answer given as the option TEXT (e.g., "4.90 BM")
                token_norm = normalize_space(token).lower()
                matched = None
                for k, opt_text in cur.options.items():
                    if normalize_space(opt_text).lower() == token_norm:
                        matched = k
                        break
                if matched:
                    cur.correct_key = matched
                else:
                    msg = f"{filename}:{lineno} cannot map correct answer '{token}' (q{cur.qnum})"
                    errors.append(msg)
                    logger.error(msg)

            last_field = ("correct", None)
            continue

        m = EXPL_RE.match(line)
        if m:
            cur.explanation = m.group(1).rstrip()
            last_field = ("explanation", None)
            continue

        # Continuation lines (inconsistent spacing)
        if line.strip() == "":
            continue

        extra = line.rstrip()
        if last_field:
            field_name, opt_key = last_field
            if field_name == "question":
                cur.question = (cur.question + "\n" + extra).rstrip()
            elif field_name == "option" and opt_key:
                cur.options[opt_key] = (cur.options.get(opt_key, "") + "\n" + extra).rstrip()
            elif field_name == "explanation":
                cur.explanation = (cur.explanation + "\n" + extra).rstrip()
            else:
                logger.debug(f"{filename}:{lineno} unassigned continuation: {extra}")
        else:
            logger.debug(f"{filename}:{lineno} ignored (no last_field): {extra}")

    finalize()

    # Validation
    for q in questions:
        if not q.question.strip():
            msg = f"{filename}: q{q.qnum} missing question text"
            errors.append(msg)
            logger.error(msg)

        if len(q.options) < 2:
            msg = f"{filename}: q{q.qnum} has too few options ({len(q.options)})"
            errors.append(msg)
            logger.error(msg)

        if q.correct_key not in q.options:
            msg = f"{filename}: q{q.qnum} missing/invalid correct option (got {q.correct_key})"
            errors.append(msg)
            logger.error(msg)

    return questions, errors


# -------------------- Moodle XML generation (CDATA) --------------------
def add_cdata_text(parent: ET._Element, tag: str, text: str, fmt: Optional[str] = None) -> ET._Element:
    """
    Creates:
      <tag format="html"><text><![CDATA[...]]></text></tag>
    or, if fmt is None:
      <tag><text><![CDATA[...]]></text></tag>
    """
    if fmt is None:
        el = ET.SubElement(parent, tag)
    else:
        el = ET.SubElement(parent, tag, format=fmt)

    t = ET.SubElement(el, "text")
    t.text = CDATA(text if text is not None else "")
    return el


def build_moodle_tree(valid_questions: List[MCQ]) -> ET._ElementTree:
    quiz = ET.Element("quiz")

    for q in valid_questions:
        q_el = ET.SubElement(quiz, "question", type="multichoice")

        # name derived from first words of the question (instead of Q2)
        qname = make_question_name(q.question, max_words=5)
        name_el = ET.SubElement(q_el, "name")
        name_text = ET.SubElement(name_el, "text")
        name_text.text = CDATA(qname)

        # question text
        qt = ET.SubElement(q_el, "questiontext", format="html")
        qt_text = ET.SubElement(qt, "text")
        qt_text.text = CDATA(q.question)

        ET.SubElement(q_el, "defaultgrade").text = "1.0000000"
        ET.SubElement(q_el, "penalty").text = "0.3333333"
        ET.SubElement(q_el, "hidden").text = "0"
        ET.SubElement(q_el, "single").text = "true"
        ET.SubElement(q_el, "shuffleanswers").text = "1"
        ET.SubElement(q_el, "answernumbering").text = "ABCD"

        # Explanation as general feedback
        add_cdata_text(q_el, "generalfeedback", q.explanation or "", fmt="html")

        # answers with Correct/Wrong per-choice feedback
        for key in ["A", "B", "C", "D"]:
            if key not in q.options:
                continue

            is_correct = (key == q.correct_key)
            fraction = "100" if is_correct else "0"

            ans = ET.SubElement(q_el, "answer", fraction=fraction, format="html")
            ans_text = ET.SubElement(ans, "text")
            ans_text.text = CDATA(q.options[key])

            fb = ET.SubElement(ans, "feedback", format="html")
            fb_text = ET.SubElement(fb, "text")
            fb_text.text = CDATA("Correct!" if is_correct else "Wrong!")

    return ET.ElementTree(quiz)


# -------------------- Logging + CLI --------------------
def setup_logger(folder: Path) -> logging.Logger:
    log_path = folder / "output.log"
    logger = logging.getLogger("txt2moodle")
    logger.setLevel(logging.DEBUG)

    if logger.handlers:
        return logger

    fh = logging.FileHandler(log_path, mode="w", encoding="utf-8")
    fh.setLevel(logging.DEBUG)
    fh.setFormatter(logging.Formatter("%(asctime)s [%(levelname)s] %(message)s"))

    ch = logging.StreamHandler()
    ch.setLevel(logging.INFO)
    ch.setFormatter(logging.Formatter("%(message)s"))

    logger.addHandler(fh)
    logger.addHandler(ch)
    return logger


def main() -> None:
    ap = argparse.ArgumentParser(description="Convert text-based MCQs to Moodle XML (CDATA, named from question text).")
    ap.add_argument("--folder", required=True, help="Folder containing *.txt files.")
    args = ap.parse_args()

    folder = Path(args.folder)
    if not folder.exists() or not folder.is_dir():
        raise SystemExit(f"Folder not found: {folder}")

    logger = setup_logger(folder)

    txt_files = sorted([p for p in folder.iterdir() if p.is_file() and p.suffix.lower() == ".txt"])
    if not txt_files:
        logger.info(f"No .txt files found in {folder}")
        return

    logger.info(f"Found {len(txt_files)} text files in {folder}")

    total_parsed = 0
    total_valid = 0
    total_errors = 0

    for txt_path in txt_files:
        xml_path = txt_path.with_suffix(".xml")
        logger.info(f"\n--- Processing: {txt_path.name} ---")

        lines = txt_path.read_text(encoding="utf-8").splitlines(True)
        questions, errors = parse_questions(lines, logger, txt_path.name)

        valid = [
            q for q in questions
            if q.question.strip() and (q.correct_key in q.options) and (len(q.options) >= 2)
        ]

        logger.info(f"Parsed questions: {len(questions)}")
        logger.info(f"Valid questions:  {len(valid)}")
        logger.info(f"Format errors:    {len(errors)}")

        total_parsed += len(questions)
        total_valid += len(valid)
        total_errors += len(errors)

        if not valid:
            logger.info(f"Skipping XML write (no valid questions): {txt_path.name}")
            continue

        tree = build_moodle_tree(valid)
        xml_bytes = ET.tostring(
            tree.getroot(),
            encoding="UTF-8",
            xml_declaration=True,
            pretty_print=True
        )
        xml_path.write_bytes(xml_bytes)

        logger.info(f"Written: {xml_path.name}")

    logger.info("\n=== Overall ===")
    logger.info(f"Total parsed questions: {total_parsed}")
    logger.info(f"Total valid questions:  {total_valid}")
    logger.info(f"Total format errors:    {total_errors}")
    logger.info(f"Audit log: {folder / 'output.log'}")


if __name__ == "__main__":
    main()
