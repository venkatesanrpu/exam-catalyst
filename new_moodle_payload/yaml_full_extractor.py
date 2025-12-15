import argparse
import csv
import json
import os
import re
import sys
from typing import List, Dict, Tuple

import yaml


def to_snake_case(text: str) -> str:
    """Convert text to snake_case format."""
    text = text.strip()
    text = re.sub(r"^[^\w]+", "", text)
    text = re.sub(r"[^\w]+", " ", text)
    text = text.strip().lower()
    text = re.sub(r"\s+", "_", text)
    return text


def load_batch_csv(batch_path: str) -> List[Dict[str, str]]:
    """
    Load batch CSV file and return list of topics.

    Returns:
      list of dicts: {topic, topic_key, filename_prefix}

    Expects rows like:
      Basic Quantum Mechanics,unit_01
      Approximation Methods of Quantum Mechanics,unit_02
    """
    if not os.path.isfile(batch_path):
        raise FileNotFoundError(
            f"❌ ERROR: batch.csv not found at: {batch_path}\n"
            f"   Current directory: {os.getcwd()}"
        )

    topics: List[Dict[str, str]] = []
    try:
        with open(batch_path, newline="", encoding="utf-8") as f:
            reader = csv.reader(f)
            rows = list(reader)

        if len(rows) == 0:
            raise ValueError("❌ ERROR: batch.csv is empty")

        first_row = rows[0]
        if len(first_row) < 2:
            raise ValueError(
                f"❌ ERROR: Invalid CSV format. Expected 2 columns, got {len(first_row)}\n"
                f"   First row: {first_row}"
            )

        # Optional header: topic,filename_prefix
        has_header = [c.lower().strip() for c in first_row] == ["topic", "filename_prefix"]
        start_idx = 1 if has_header else 0

        for idx, row in enumerate(rows[start_idx:], start=start_idx):
            if not row or len(row) < 2:
                print(f"⚠ WARNING: Skipping invalid row {idx+1}: {row}")
                continue

            topic = row[0].strip()
            filename_prefix = row[1].strip()

            if not topic or not filename_prefix:
                print(f"⚠ WARNING: Row {idx+1} has empty values: {row}")
                continue

            topics.append(
                {
                    "topic": topic,
                    "topic_key": to_snake_case(topic),
                    "filename_prefix": filename_prefix,
                }
            )

        if not topics:
            raise ValueError("❌ ERROR: No valid topics found in batch.csv")

        return topics

    except csv.Error as e:
        raise ValueError(f"❌ ERROR: Failed to parse CSV file: {e}")
    except Exception as e:
        raise Exception(f"❌ ERROR: Unexpected error reading batch.csv: {e}")


def build_lessons_from_yaml(yaml_path: str) -> Tuple[str, str, List[Dict]]:
    """
    From a unit_XX.yaml file, build lessons from learning_path[*].textbook_style_content.

    For each learning_path item:
      - Each textbook_style_content[*].lesson  -> lesson
      - Each lesson.sections[*].section_heading -> chapter

    Returns:
      subject, subject_key, lessons[]
    """
    if not os.path.isfile(yaml_path):
        raise FileNotFoundError(f"YAML file not found: {yaml_path}")

    try:
        with open(yaml_path, encoding="utf-8") as f:
            data = yaml.safe_load(f)

        if data is None or not isinstance(data, dict):
            raise ValueError("YAML file is empty or has invalid root (expected mapping)")

        # metadata -> subject
        metadata = data.get("metadata", {}) or {}
        if not isinstance(metadata, dict):
            metadata = {}
        subject = str(metadata.get("subject", "")).strip()
        subject_key = to_snake_case(subject) if subject else ""

        # learning_path list
        learning_path = data.get("learning_path", []) or []
        if not isinstance(learning_path, list):
            print(f"⚠ WARNING: learning_path is not a list in {yaml_path}")
            learning_path = []

        lessons: List[Dict] = []

        for lp_idx, lp_item in enumerate(learning_path):
            if not isinstance(lp_item, dict):
                print(f"⚠ WARNING: Skipping non-dict learning_path item {lp_idx} in {yaml_path}")
                continue

            # textbook_style_content is a list of lesson-blocks
            tsc = lp_item.get("textbook_style_content", []) or []
            if not isinstance(tsc, list):
                print(f"⚠ WARNING: textbook_style_content is not a list at learning_path[{lp_idx}]")
                continue

            for ct_idx, content_block in enumerate(tsc):
                if not isinstance(content_block, dict):
                    print(
                        f"⚠ WARNING: Skipping non-dict textbook_style_content item "
                        f"{ct_idx} at learning_path[{lp_idx}]"
                    )
                    continue

                lesson_name = str(content_block.get("lesson", "")).strip()
                if not lesson_name:
                    print(
                        f"⚠ WARNING: Skipping textbook_style_content[{ct_idx}] at "
                        f"learning_path[{lp_idx}] with empty lesson"
                    )
                    continue

                sections = content_block.get("sections", []) or []
                if not isinstance(sections, list):
                    print(
                        f"⚠ WARNING: sections is not a list for lesson '{lesson_name}' "
                        f"in {yaml_path}"
                    )
                    sections = []

                chapters = []
                for sec_idx, sec in enumerate(sections):
                    if not isinstance(sec, dict):
                        print(
                            f"⚠ WARNING: Skipping non-dict section {sec_idx} "
                            f"for lesson '{lesson_name}'"
                        )
                        continue
                    heading = str(sec.get("section_heading", "")).strip()
                    if heading:
                        chapters.append({"chapter": heading})

                lessons.append(
                    {
                        "lesson": lesson_name,
                        "lesson_key": to_snake_case(lesson_name),
                        "chapters": chapters,
                    }
                )

        return subject, subject_key, lessons

    except yaml.YAMLError as e:
        raise ValueError(f"YAML parsing error in {yaml_path}: {e}")
    except Exception as e:
        raise Exception(f"Unexpected error parsing {yaml_path}: {e}")


def main():
    parser = argparse.ArgumentParser(
        description="Convert unit_XX.yaml files to syllabus300.json"
    )
    parser.add_argument(
        "--folder", required=True, help="Folder containing batch.csv and unit_XX.yaml files"
    )
    parser.add_argument(
        "--batch",
        default="batch.csv",
        help="Batch CSV filename inside the folder (default: batch.csv)",
    )
    parser.add_argument(
        "--output",
        default="syllabus300.json",
        help="Output JSON filename (default: syllabus300.json)",
    )

    args = parser.parse_args()
    folder = args.folder
    batch_csv_path = os.path.join(folder, args.batch)

    if not os.path.isdir(folder):
        print(f"❌ ERROR: Folder does not exist: {folder}")
        sys.exit(1)

    # Load topics from batch.csv
    try:
        topics_info = load_batch_csv(batch_csv_path)
    except Exception as e:
        print(e)
        sys.exit(1)

    syllabus: Dict = {
        "subject": None,
        "subject_key": None,
        "topics": [],
    }

    subject_set = False
    processed = 0
    skipped = 0

    for idx, t in enumerate(topics_info, start=1):
        topic = t["topic"]
        topic_key = t["topic_key"]
        prefix = t["filename_prefix"]

        yaml_filename = f"{prefix}.yaml"
        yaml_path = os.path.join(folder, yaml_filename)

        print(f"[{idx}/{len(topics_info)}] Topic: {topic}")
        print(f"  YAML: {yaml_filename}")

        if not os.path.isfile(yaml_path):
            print(f"  ❌ SKIPPED: {yaml_filename} not found\n")
            skipped += 1
            continue

        try:
            subject, subject_key, lessons = build_lessons_from_yaml(yaml_path)

            if subject and not subject_set:
                syllabus["subject"] = subject
                syllabus["subject_key"] = subject_key
                subject_set = True

            topic_obj = {
                "topic": topic,
                "topic_key": topic_key,
                "lessons": lessons,
            }
            syllabus["topics"].append(topic_obj)
            processed += 1
            print(f"  ✓ Added {len(lessons)} lessons\n")

        except Exception as e:
            print(f"  ❌ ERROR parsing {yaml_filename}: {e}\n")
            skipped += 1

    if processed == 0:
        print("❌ ERROR: No YAML files were successfully processed.")
        sys.exit(1)

    output_path = os.path.join(folder, args.output)
    try:
        with open(output_path, "w", encoding="utf-8") as f:
            json.dump(syllabus, f, indent=2, ensure_ascii=False)
        print(f"✓ Wrote syllabus to {output_path}")
    except Exception as e:
        print(f"❌ ERROR writing output file: {e}")
        sys.exit(1)


if __name__ == "__main__":
    main()
