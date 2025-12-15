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

    Raises:
        FileNotFoundError: If batch.csv doesn't exist
        ValueError: If CSV format is invalid
    """
    if not os.path.isfile(batch_path):
        raise FileNotFoundError(
            f"❌ ERROR: batch.csv not found at: {batch_path}\n"
            f"   Current directory: {os.getcwd()}"
        )

    topics = []
    try:
        with open(batch_path, newline="", encoding="utf-8") as f:
            reader = csv.reader(f)
            rows = list(reader)

            if len(rows) == 0:
                raise ValueError("❌ ERROR: batch.csv is empty")

            print(f"✓ Found batch.csv with {len(rows)} rows")

            # Check for header
            first_row = rows[0]
            if len(first_row) < 2:
                raise ValueError(
                    f"❌ ERROR: Invalid CSV format. Expected 2 columns, got {len(first_row)}\n"
                    f"   First row: {first_row}"
                )

            # Detect header row
            has_header = [c.lower().strip() for c in first_row] == ["topic", "filename_prefix"]
            start_idx = 1 if has_header else 0

            if has_header:
                print("✓ Detected header row in batch.csv")

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

            if len(topics) == 0:
                raise ValueError("❌ ERROR: No valid topics found in batch.csv")

            print(f"✓ Loaded {len(topics)} valid topics from batch.csv\n")
            return topics

    except csv.Error as e:
        raise ValueError(f"❌ ERROR: Failed to parse CSV file: {e}")
    except Exception as e:
        raise Exception(f"❌ ERROR: Unexpected error reading batch.csv: {e}")


def build_lessons_from_yaml(yaml_path: str) -> Tuple[str, str, List[Dict]]:
    """
    Parse YAML file and extract subject and lessons from learning_path.

    Returns:
        Tuple of (subject, subject_key, lessons)
    """
    if not os.path.isfile(yaml_path):
        raise FileNotFoundError(f"YAML file not found: {yaml_path}")

    try:
        with open(yaml_path, encoding="utf-8") as f:
            data = yaml.safe_load(f)

        if data is None:
            raise ValueError("YAML file is empty or invalid")

        if not isinstance(data, dict):
            raise ValueError(f"YAML root must be a dictionary, got {type(data)}")

        # Extract metadata
        metadata = data.get("metadata", {})
        if not isinstance(metadata, dict):
            print(f"  ⚠ WARNING: metadata is not a dict: {type(metadata)}")
            metadata = {}

        subject = metadata.get("subject", "").strip()
        subject_key = to_snake_case(subject) if subject else ""

        # Extract learning_path
        learning_path = data.get("learning_path", [])
        if learning_path is None:
            learning_path = []

        if not isinstance(learning_path, list):
            print(f"  ⚠ WARNING: learning_path is not a list: {type(learning_path)}")
            learning_path = []

        lessons: List[Dict] = []

        for idx, path_item in enumerate(learning_path):
            if not isinstance(path_item, dict):
                print(
                    f"  ⚠ WARNING: Skipping non-dict learning_path item {idx}: "
                    f"{type(path_item)}"
                )
                continue

            # Get the topic (your YAML uses 'topic' for lesson name)
            lesson_name = (path_item.get("topic") or "").strip()

            if not lesson_name:
                print(f"  ⚠ WARNING: Skipping learning_path item {idx} with empty topic")
                continue

            # Get learning_objectives (which become chapters)
            learning_objectives = path_item.get("learning_objectives", [])
            if learning_objectives is None:
                learning_objectives = []

            if not isinstance(learning_objectives, list):
                print(f"  ⚠ WARNING: learning_objectives is not a list for item {idx}")
                learning_objectives = []

            # Build chapters from learning_objectives
            chapters: List[Dict[str, str]] = []
            for obj in learning_objectives:
                if isinstance(obj, str):
                    obj_text = obj.strip()
                    if obj_text:
                        chapters.append({"chapter": obj_text})
                else:
                    print(f"  ⚠ WARNING: Skipping non-string learning_objective: {obj}")

            lesson_obj = {
                "lesson": lesson_name,
                "lesson_key": to_snake_case(lesson_name),
                "chapters": chapters,
            }

            lessons.append(lesson_obj)

        print(f"  ✓ Parsed {len(lessons)} lessons from learning_path")
        if lessons:
            total_chapters = sum(len(lesson["chapters"]) for lesson in lessons)
            print(f"  ✓ Total chapters (learning objectives): {total_chapters}")

        return subject, subject_key, lessons

    except yaml.YAMLError as e:
        raise ValueError(f"YAML parsing error: {e}")
    except Exception as e:
        raise Exception(f"Unexpected error parsing YAML: {e}")


def main():
    parser = argparse.ArgumentParser(
        description="Convert YAML files with learning_path structure to syllabus.json"
    )
    parser.add_argument("--folder", required=True, help="Folder containing YAML and batch.csv")
    parser.add_argument(
        "--batch",
        default="batch.csv",
        help="Batch CSV filename inside the folder (default: batch.csv)",
    )
    parser.add_argument(
        "--output",
        default="syllabus200.json",
        help="Output JSON filename (default: syllabus200.json)",
    )
    parser.add_argument(
        "--verbose",
        action="store_true",
        help="Show detailed progress information",
    )

    args = parser.parse_args()
    folder = args.folder
    batch_csv_path = os.path.join(folder, args.batch)

    print("=" * 70)
    print("YAML to Syllabus JSON Converter")
    print("=" * 70)
    print(f"Folder: {folder}")
    print(f"Batch CSV: {args.batch}")
    print(f"Output: {args.output}")
    print("=" * 70 + "\n")

    # Check if folder exists
    if not os.path.isdir(folder):
        print(f"❌ ERROR: Folder does not exist: {folder}")
        sys.exit(1)

    # Load batch.csv
    try:
        topics_info = load_batch_csv(batch_csv_path)
    except Exception as e:
        print(f"\n{e}")
        sys.exit(1)

    syllabus = {
        "subject": None,
        "subject_key": None,
        "topics": [],
    }

    subject_set = False
    processed_count = 0
    skipped_count = 0

    # Process each topic
    for idx, t in enumerate(topics_info, start=1):
        topic = t["topic"]
        topic_key = t["topic_key"]
        prefix = t["filename_prefix"]

        yaml_filename = f"{prefix}.yaml"
        yaml_path = os.path.join(folder, yaml_filename)

        print(f"[{idx}/{len(topics_info)}] Processing: {topic}")
        print(f"  Looking for: {yaml_filename}")

        if not os.path.isfile(yaml_path):
            print(f"  ❌ SKIPPED: File not found at {yaml_path}\n")
            skipped_count += 1
            continue

        print("  ✓ Found YAML file")

        try:
            subject, subject_key, lessons = build_lessons_from_yaml(yaml_path)

            # Set subject from first valid YAML
            if subject and not subject_set:
                syllabus["subject"] = subject
                syllabus["subject_key"] = subject_key
                subject_set = True
                print(f"  ✓ Set subject: {subject}")

            topic_obj = {
                "topic": topic,
                "topic_key": topic_key,
                "lessons": lessons,
            }
            syllabus["topics"].append(topic_obj)
            processed_count += 1
            print("  ✓ Successfully processed\n")

        except Exception as e:
            print(f"  ❌ ERROR processing YAML: {e}\n")
            skipped_count += 1
            continue

    # Summary
    print("=" * 70)
    print("PROCESSING SUMMARY")
    print("=" * 70)
    print(f"Total topics in batch.csv: {len(topics_info)}")
    print(f"Successfully processed: {processed_count}")
    print(f"Skipped/Failed: {skipped_count}")
    print(f"Subject: {syllabus.get('subject') or 'Not set'}")
    print(f"Total topics in output: {len(syllabus['topics'])}")

    # Calculate total lessons and chapters
    total_lessons = sum(len(topic["lessons"]) for topic in syllabus["topics"])
    total_chapters = sum(
        len(lesson["chapters"])
        for topic in syllabus["topics"]
        for lesson in topic["lessons"]
    )
    print(f"Total lessons: {total_lessons}")
    print(f"Total chapters: {total_chapters}")
    print("=" * 70 + "\n")

    if processed_count == 0:
        print("❌ ERROR: No YAML files were successfully processed!")
        print("\nPossible issues:")
        print("1. YAML files don't exist in the specified folder")
        print("2. YAML filenames don't match: {filename_prefix}.yaml")
        print("3. YAML files have invalid format or missing learning_path")
        print("\nExpected files:")
        for t in topics_info:
            print(f"  - {t['filename_prefix']}.yaml")
        sys.exit(1)

    # Write output
    output_path = os.path.join(folder, args.output)
    try:
        with open(output_path, "w", encoding="utf-8") as f:
            json.dump(syllabus, f, indent=2, ensure_ascii=False)
        print(f"✓ Successfully wrote output to: {output_path}")
        print("\nDone! Created syllabus with:")
        print(f"  - {len(syllabus['topics'])} topics")
        print(f"  - {total_lessons} lessons")
        print(f"  - {total_chapters} chapters")
    except Exception as e:
        print(f"❌ ERROR: Failed to write output file: {e}")
        sys.exit(1)


if __name__ == "__main__":
    main()
