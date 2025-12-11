#!/usr/bin/env python3
import yaml
import sys
import argparse
import re
from pathlib import Path

def to_snake_case(s: str) -> str:
    """Convert a string to snake_case."""
    if s is None or not s:
        return ''
    s = s.strip().lower()
    s = re.sub(r'[^0-9a-z]+', '_', s)
    s = re.sub(r'__+', '_', s)
    return s.strip('_')

def parse_yaml_and_generate_html(yaml_file, user_topic):
    """Parse YAML file and generate HTML output."""

    # Read YAML file
    with open(yaml_file, 'r', encoding='utf-8') as f:
        data = yaml.safe_load(f)

    # Extract metadata and convert to snake_case
    subject = data.get('metadata', {}).get('subject', 'unknown subject')
    subject_key = to_snake_case(subject)

    # Start building HTML content
    html_lines = []

    # Process each learning path item
    learning_path = data.get('learning_path', [])

    for path_item in learning_path:
        title = path_item.get('title', '')
        textbook_content = path_item.get('textbook_style_content', [])
        tags_list = path_item.get('tags', [])
        tags = ', '.join(tags_list) if tags_list else ''

        # Add title as h3
        html_lines.append(f'<h3>{title}</h3>')

        # Process each content item (topic level)
        for content_item in textbook_content:
            topic = content_item.get('topic', '')
            sections = content_item.get('sections', [])

            if not sections:
                continue

            # Add topic as h4
            html_lines.append(f'<h4>{topic}</h4>')
            html_lines.append('<ol>')

            # Process each section
            for section in sections:
                section_title = section.get('section_title', '')

                if not section_title:
                    continue

                # Build the list item with all links (using subject_key instead of subject)
                li_content = f'{section_title} | '

                # Study Notes link
                li_content += f'<a href="#" class="notes-link" data-function="ask_agent" data-subject="{subject_key}" data-lesson="{title}" data-topic="{user_topic}" data-tags="{tags}" data-agent-text="{section_title}">Study Notes</a> | '

                # Basic Level MCQ link
                li_content += f'<a href="#" class="mcq-link" data-function="mcq" data-level="basic" data-subject="{subject_key}" data-lesson="{title}" data-topic="{user_topic}" data-tags="{tags}" data-agent-text="Basic MCQ: {section_title}">MCQ Basic</a> | '

                # Intermediate Level MCQ link
                li_content += f'<a href="#" class="mcq-link" data-function="mcq" data-level="intermediate" data-subject="{subject_key}" data-lesson="{title}" data-topic="{user_topic}" data-tags="{tags}" data-agent-text="Intermediate MCQ: {section_title}">MCQ Intermediate</a> | '

                # Advanced Level MCQ link
                li_content += f'<a href="#" class="mcq-link" data-function="mcq" data-level="advanced" data-subject="{subject_key}" data-lesson="{title}" data-topic="{user_topic}" data-tags="{tags}" data-agent-text="Advanced MCQ: {section_title}">MCQ Advanced</a>'

                html_lines.append(f'<li>{li_content}</li><br>')

            html_lines.append('</ol>')

    return '\n'.join(html_lines)

def main():
    parser = argparse.ArgumentParser(description='Parse YAML syllabus and generate HTML')
    parser.add_argument('--topic', required=True, help='Topic name for data-topic attribute')
    parser.add_argument('yaml_file', help='Path to YAML file')

    args = parser.parse_args()

    yaml_path = Path(args.yaml_file)

    if not yaml_path.exists():
        print(f"Error: File {args.yaml_file} not found")
        sys.exit(1)

    # Generate HTML
    html_content = parse_yaml_and_generate_html(args.yaml_file, args.topic)

    # Determine output filename (same name but .html extension)
    output_file = yaml_path.with_suffix('.html')

    # Write output
    with open(output_file, 'w', encoding='utf-8') as f:
        f.write(html_content)

    print(f"HTML generated successfully: {output_file}")

if __name__ == '__main__':
    main()
