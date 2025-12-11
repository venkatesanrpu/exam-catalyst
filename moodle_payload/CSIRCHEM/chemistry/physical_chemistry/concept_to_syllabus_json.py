import argparse
import csv
import json
import os
import re

import yaml


def to_snake_case(text: str) -> str:
    # strip emojis and non-word separators crudely, then snake_case
    text = text.strip()
    # remove leading emojis and symbols
    text = re.sub(r"^[^\w]+", "", text)
    # replace non alnum with spaces
    text = re.sub(r"[^\w]+", " ", text)
    text = text.strip().lower()
    text = re.sub(r"\s+", "_", text)
    return text


def load_batch_csv(batch_path: str):
    """
    Returns:
      list of dicts: {topic, topic_key, filename_prefix}
    """
    topics = []
    with open(batch_path, newline="", encoding="utf-8") as f:
        reader = csv.reader(f)
        # if the csv has header row like: topic,filename_prefix
        # detect header by name; otherwise treat as data
        first_row = next(reader)
        if [c.lower() for c in first_row] in (["topic", "filename_prefix"],):
            # header present, read remaining rows
            for row in reader:
                if not row:
                    continue
                topic = row[0].strip()
                filename_prefix = row[1].strip()
                topics.append(
                    {
                        "topic": topic,
                        "topic_key": to_snake_case(topic),
                        "filename_prefix": filename_prefix,
                    }
                )
        else:
            # first row is data
            topic = first_row[0].strip()
            filename_prefix = first_row[1].strip()
            topics.append(
                {
                    "topic": topic,
                    "topic_key": to_snake_case(topic),
                    "filename_prefix": filename_prefix,
                }
            )
            for row in reader:
                if not row:
                    continue
                topic = row[0].strip()
                filename_prefix = row[1].strip()
                topics.append(
                    {
                        "topic": topic,
                        "topic_key": to_snake_case(topic),
                        "filename_prefix": filename_prefix,
                    }
                )

    return topics


def build_lessons_from_yaml(yaml_path: str):
    """
    From a single *_concepts.yaml file, build the lessons list:

    [
      {
        "lesson": <core name>,
        "lesson_key": <snake_case>,
        "chapters": [
          { "chapter": <clarifier> }
        ]
      },
      ...
    ]
    """
    with open(yaml_path, encoding="utf-8") as f:
        data = yaml.safe_load(f)

    # metadata.subject for subject and subject_key
    metadata = data.get("metadata", {})
    subject = metadata.get("subject", "").strip()
    subject_key = to_snake_case(subject) if subject else ""

    concepts = data.get("concepts", {})
    core_list = concepts.get("core", []) or []

    lessons = []
    for core in core_list:
        # supports both list-of-dicts and yaml where name/clarifier as keys
        if isinstance(core, dict):
            name = core.get("name", "").strip()
            clarifier = core.get("clarifier", "")
        else:
            # unexpected structure; skip
            continue

        if not name:
            continue

        lesson_obj = {
            "lesson": name,
            "lesson_key": to_snake_case(name),
            "chapters": [],
        }

        if clarifier:
            lesson_obj["chapters"].append({"chapter": clarifier})

        lessons.append(lesson_obj)

    return subject, subject_key, lessons


def main():
    parser = argparse.ArgumentParser(
        description="Convert concept YAML files in a folder to syllabus.json"
    )
    parser.add_argument("--folder", required=True, help="Folder containing YAML and batch.csv")
    parser.add_argument(
        "--batch",
        default="batch.csv",
        help="Batch CSV filename inside the folder (default: batch.csv)",
    )
    parser.add_argument(
        "--output",
        default="syllabus.json",
        help="Output JSON filename (default: syllabus.json)",
    )

    args = parser.parse_args()
    folder = args.folder
    batch_csv_path = os.path.join(folder, args.batch)

    topics_info = load_batch_csv(batch_csv_path)  # [file:1]

    syllabus = {
        "subject": None,
        "subject_key": None,
        "topics": [],
    }

    # will be set from the first YAML that has metadata.subject
    subject_set = False

    for t in topics_info:
        topic = t["topic"]
        topic_key = t["topic_key"]
        prefix = t["filename_prefix"]

        yaml_filename = f"{prefix}_concepts.yaml"
        yaml_path = os.path.join(folder, yaml_filename)
        if not os.path.isfile(yaml_path):
            # skip silently if file not present
            continue

        subject, subject_key, lessons = build_lessons_from_yaml(yaml_path)  # [file:2]

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

    # write a single syllabus.json
    output_path = os.path.join(folder, args.output)
    with open(output_path, "w", encoding="utf-8") as f:
        json.dump(syllabus, f, indent=2, ensure_ascii=False)


if __name__ == "__main__":
    main()
