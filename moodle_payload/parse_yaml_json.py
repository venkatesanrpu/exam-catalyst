#!/usr/bin/env python3
"""
parse_yaml_json.py

Scan a folder for YAML files, extract subject, syllabus_line, and learning_path details,
and produce nested JSON output with lessons and tags.
"""

import argparse
import json
import os
import re
from typing import List, Dict, Any

import yaml

VALID_EXT = {'.yaml', '.yml', '.txt'}


def to_snake_case(s: str) -> str:
    """Convert a string to snake_case."""
    if s is None or not s:
        return ''
    s = s.strip().lower()
    s = re.sub(r'[^0-9a-z]+', '_', s)
    s = re.sub(r'__+', '_', s)
    return s.strip('_')


def extract_tags_from_learning_path_item(lp_item: Dict[str, Any]) -> List[str]:
    """
    Extract tags from a learning_path item's textbook_style_content.
    Returns a list of topic strings.
    """
    tags = []
    textbook_content = lp_item.get('textbook_style_content', [])
    if isinstance(textbook_content, list):
        for content_item in textbook_content:
            if isinstance(content_item, dict):
                topic = content_item.get('topic', '')
                if topic and isinstance(topic, str):
                    tags.append(topic.strip())
    return tags


def extract_lessons_from_yaml(data: Dict[str, Any]) -> List[Dict[str, Any]]:
    """
    Extract lessons from learning_path in YAML data.
    Each lesson contains: lesson, lesson_key, and tags.
    """
    lessons = []
    lp = data.get('learning_path')
    if isinstance(lp, list):
        for item in lp:
            if isinstance(item, dict):
                title = item.get('title', '')
                if isinstance(title, str) and title.strip():
                    lesson_title = title.strip()
                    lesson_key = to_snake_case(lesson_title)
                    tags = extract_tags_from_learning_path_item(item)

                    lessons.append({
                        'lesson': lesson_title,
                        'lesson_key': lesson_key,
                        'tags': tags
                    })
    return lessons


def extract_from_yaml(data: Dict[str, Any]) -> Dict[str, Any]:
    """
    Extract topic (syllabus_line) and lessons from YAML data.
    Returns a dict with topic, topic_key, and lessons.
    """
    topic = ''
    if isinstance(data, dict):
        metadata = data.get('metadata') or {}
        topic = metadata.get('syllabus_line') or data.get('syllabus_line') or ''

    topic_key = to_snake_case(topic)
    lessons = extract_lessons_from_yaml(data)

    return {
        'topic': topic,
        'topic_key': topic_key,
        'lessons': lessons
    }


def extract_subject_from_yaml(data: Dict[str, Any]) -> tuple:
    """
    Extract subject from metadata.
    Returns (subject, subject_key) tuple.
    """
    subject = ''
    if isinstance(data, dict):
        metadata = data.get('metadata') or {}
        subject = metadata.get('subject', '')

    subject_key = to_snake_case(subject)
    return subject, subject_key


def find_yaml_files(folder: str, recursive: bool = True) -> List[str]:
    """Find all YAML files in the specified folder."""
    files = []
    if recursive:
        for root, _, filenames in os.walk(folder):
            for fn in filenames:
                if os.path.splitext(fn)[1].lower() in VALID_EXT:
                    files.append(os.path.join(root, fn))
    else:
        for fn in os.listdir(folder):
            path = os.path.join(folder, fn)
            if os.path.isfile(path) and os.path.splitext(fn)[1].lower() in VALID_EXT:
                files.append(path)
    return sorted(files)


def load_yaml_file(path: str) -> Any:
    """Load and parse a YAML file."""
    with open(path, 'r', encoding='utf-8') as f:
        return yaml.safe_load(f)


def main():
    parser = argparse.ArgumentParser(description='Parse YAML files and extract structured data into JSON.')
    parser.add_argument('--folder', required=True, help='Folder containing YAML files')
    parser.add_argument('--no-recursive', dest='recursive', action='store_false',
                        help='Do not search folders recursively')
    args = parser.parse_args()

    folder = args.folder
    if not os.path.isdir(folder):
        raise SystemExit(f"Folder not found: {folder}")

    files = find_yaml_files(folder, recursive=args.recursive)

    # Dictionary to group topics by subject
    subjects_dict = {}

    for path in files:
        try:
            data = load_yaml_file(path)
        except Exception as e:
            print(f"Warning: YAML parse error in {path}: {e}", flush=True)
            continue

        # Extract subject and topic data
        subject, subject_key = extract_subject_from_yaml(data)
        topic_data = extract_from_yaml(data)

        # Group by subject
        if subject_key not in subjects_dict:
            subjects_dict[subject_key] = {
                'subject': subject,
                'subject_key': subject_key,
                'topics': []
            }

        subjects_dict[subject_key]['topics'].append(topic_data)

    # Convert dictionary to list
    results = list(subjects_dict.values())

    # Generate JSON output
    output_json = json.dumps(results, indent=2, ensure_ascii=False)

    # Write to <folder_name>.json
    folder_base = os.path.basename(os.path.normpath(folder))
    file1 = f"{folder_base}.json"
    with open(file1, 'w', encoding='utf-8') as f:
        f.write(output_json)

    # Also write to inorganic_chemistry.json (fixed name)
    file2 = "inorganic_chemistry.json"
    with open(file2, 'w', encoding='utf-8') as f:
        f.write(output_json)

    total_topics = sum(len(s['topics']) for s in results)
    print(f"Wrote {len(results)} subject(s) with {total_topics} topic(s) to {file1} and {file2}")


if __name__ == '__main__':
    main()
