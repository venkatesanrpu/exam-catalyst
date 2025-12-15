#!/usr/bin/env python3
"""
Generate HTML files with study notes and MCQ links from YAML learning_path.
"""

import yaml
import csv
import os
import argparse
from pathlib import Path


def load_csv_mapping(csv_path):
    """Load batch.csv and create mapping of filename_prefix -> topic (lesson_topic)."""
    mapping = {}
    with open(csv_path, 'r', encoding='utf-8') as f:
        reader = csv.reader(f)
        for row in reader:
            if len(row) >= 2:
                lesson_topic = row[0].strip()
                filename_prefix = row[1].strip()
                mapping[filename_prefix] = lesson_topic
    return mapping


def convert_to_snake_case(text):
    """Convert text to snake_case."""
    return text.lower().replace(' ', '_').replace('-', '_')


def generate_html_for_learning_path_item(lp_item, metadata, lesson_topic):
    """
    Generate multiple <li> elements for a learning_path item:
    - Lesson: lp_item['topic']
    - Clarification: each learning_objectives[i]
    - tags: comma-joined lp_item['tags'] (if list) or string
    """
    subject = metadata.get('subject', '')
    subject_snake = convert_to_snake_case(subject)
    lesson_topic_snake = convert_to_snake_case(lesson_topic)

    lp_topic = lp_item.get('topic', '')
    lp_tags = lp_item.get('tags', [])
    if isinstance(lp_tags, list):
        tags_value = ",".join(lp_tags)
    else:
        tags_value = str(lp_tags)

    learning_objectives = lp_item.get('learning_objectives', []) or []

    lis = []

    for obj in learning_objectives:
        clarifier = obj

        study_notes = (
            f'<a href="#" \n'
            f'class="notes-link" \n'
            f'data-function="ask_agent" \n'
            f'data-subject="{subject_snake}"\n'
            f'data-topic="{lesson_topic_snake}"\n'
            f'data-lesson="{lp_topic}"\n'
            f'data-tags="{tags_value}"\n'
            f'data-agent-text="Generate study notes on {clarifier}">Study Notes</a>'
        )

        mcq_basic = (
            f'<a href="#" \n'
            f'class="mcq-flashcard-link" \n'
            f'data-function="mcq_widget"\n'
            f'data-level="basic"\n'
            f'data-number="5" \n'
            f'data-subject="{subject_snake}"\n'
            f'data-topic="{lesson_topic_snake}"\n'
            f'data-lesson="{lp_topic}"\n'
            f'data-tags="{tags_value}"\n'
            f'data-agent-text="Create Basic MCQ on {clarifier}">MCQ Basic</a>'
        )

        mcq_intermediate = (
            f'<a href="#" \n'
            f'class="mcq-flashcard-link" \n'
            f'data-function="mcq_widget"\n'
            f'data-level="intermediate"\n'
            f'data-number="3" \n'
            f'data-subject="{subject_snake}"\n'
            f'data-topic="{lesson_topic_snake}"\n'
            f'data-lesson="{lp_topic}"\n'
            f'data-tags="{tags_value}"\n'
            f'data-agent-text="Create Intermediate MCQ on {clarifier}">MCQ Intermediate</a>'
        )

        mcq_advanced = (
            f'<a href="#" \n'
            f'class="mcq-flashcard-link" \n'
            f'data-function="mcq_widget"\n'
            f'data-level="advanced"\n'
            f'data-number="2" \n'
            f'data-subject="{subject_snake}"\n'
            f'data-topic="{lesson_topic_snake}"\n'
            f'data-lesson="{lp_topic}"\n'
            f'data-tags="{tags_value}"\n'
            f'data-agent-text="Create advanced MCQ on {clarifier}">MCQ Advanced</a>'
        )

        all_links = (
            f"Topic: {lesson_topic}\n\n"
            f" Lesson: {lp_topic} \n\n"
            f" Clarification: {clarifier} \n\n"
            f" GET|| {study_notes} | {mcq_basic} | {mcq_intermediate} | {mcq_advanced}"
        )

        lis.append(f'<li style="white-space: pre;">{all_links}</li><br><br>')

    return "\n".join(lis)


def process_yaml_file(yaml_path, csv_mapping, output_folder):
    """Process a single YAML file and generate HTML output from learning_path."""
    filename = os.path.basename(yaml_path)
    # filename_prefix: e.g., unit_01 from unit_01.yaml or unit_01_concept.yaml
    filename_prefix = filename.replace('_concepts.yaml', '').replace('_concept.yaml', '').replace('.yaml', '')

    if filename_prefix not in csv_mapping:
        print(f"Warning: No mapping found for {filename_prefix} in batch.csv")
        return

    lesson_topic = csv_mapping[filename_prefix]

    with open(yaml_path, 'r', encoding='utf-8') as f:
        data = yaml.safe_load(f)

    metadata = data.get('metadata', {})
    syllabus_line = metadata.get('syllabus_line', '')

    learning_path = data.get('learning_path', []) or []

    html_content = f'<h4><blockquote>{syllabus_line}</blockquote></h4>\n'
    html_content += '<h3>Core Concepts</h3>\n<ol>\n'

    for lp_item in learning_path:
        html_content += generate_html_for_learning_path_item(lp_item, metadata, lesson_topic)
        html_content += "\n"

    html_content += "</ol>"

    # Output file: unit_01_learning.html style
    output_path = os.path.join(output_folder, f"{filename_prefix}_learning.html")
    with open(output_path, 'w', encoding='utf-8') as f:
        f.write(html_content)

    print(f"Generated: {output_path}")


def main():
    parser = argparse.ArgumentParser(
        description='Generate HTML files from YAML learning_path using batch.csv mapping'
    )
    parser.add_argument('--folder', required=True, help='Folder containing YAML files')

    args = parser.parse_args()
    folder_path = args.folder

    if not os.path.exists(folder_path):
        print(f"Error: Folder '{folder_path}' does not exist")
        return

    csv_path = os.path.join(folder_path, 'batch.csv')
    if not os.path.exists(csv_path):
        print(f"Error: batch.csv not found in '{folder_path}'")
        return

    csv_mapping = load_csv_mapping(csv_path)
    print(f"Loaded {len(csv_mapping)} mappings from batch.csv")

    # Process *.yaml (source units) to make *_learning.html
    yaml_files = list(Path(folder_path).glob('unit_*.yaml'))

    if not yaml_files:
        print(f"No unit_*.yaml files found in '{folder_path}'")
        return

    print(f"Found {len(yaml_files)} YAML files to process")

    for yaml_file in yaml_files:
        process_yaml_file(str(yaml_file), csv_mapping, folder_path)

    print(f"\nProcessing complete! HTML files saved in '{folder_path}'")


if __name__ == '__main__':
    main()
