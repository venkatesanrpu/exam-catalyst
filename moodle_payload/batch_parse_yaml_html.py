#!/usr/bin/env python3
import yaml
import sys
import argparse
import re
import csv
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
                # li_content += f'<a href="#" class="mcq-link" data-function="mcq" data-level="basic" data-subject="{subject_key}" data-lesson="{title}" data-topic="{user_topic}" data-tags="{tags}" data-agent-text="Basic MCQ: {section_title}">MCQ Basic</a> | '
                li_content += f'<a href="#" class="mcq-flashcard-link" data-function="mcq_widget" data-level="basic" data-subject="{subject_key}" data-lesson="{title}" data-topic="{user_topic}" data-tags="{tags}" data-agent-text="Basic MCQ: {section_title}">MCQ Basic</a> | '

                # Intermediate Level MCQ link
                li_content += f'<a href="#" class="mcq-flashcard-link" data-function="mcq_widget" data-level="intermediate" data-subject="{subject_key}" data-lesson="{title}" data-topic="{user_topic}" data-tags="{tags}" data-agent-text="Intermediate MCQ: {section_title}">MCQ Intermediate</a> | '

                # Advanced Level MCQ link
                li_content += f'<a href="#" class="mcq-flashcard-link" data-function="mcq_widget" data-level="advanced" data-subject="{subject_key}" data-lesson="{title}" data-topic="{user_topic}" data-tags="{tags}" data-agent-text="Advanced MCQ: {section_title}">MCQ Advanced</a>'

                html_lines.append(f'<li>{li_content}</li><br>')

            html_lines.append('</ol>')

    return '\n'.join(html_lines)

def process_batch_file(batch_file):
    """Process batch file containing topic,yaml_file pairs."""
    
    # Determine file extension
    batch_path = Path(batch_file)
    if not batch_path.exists():
        print(f"Error: Batch file {batch_file} not found")
        sys.exit(1)
    
    processed_count = 0
    error_count = 0
    
    # Read the batch file (CSV or TXT with comma-separated values)
    with open(batch_file, 'r', encoding='utf-8') as f:
        reader = csv.reader(f)
        
        for line_num, row in enumerate(reader, start=1):
            # Skip empty lines
            if not row or len(row) < 2:
                print(f"Warning: Line {line_num} skipped (invalid format)")
                continue
            
            topic = row[0].strip()
            yaml_file = row[1].strip()
            
            yaml_path = Path(yaml_file)
            
            # Check if YAML file exists
            if not yaml_path.exists():
                print(f"Error: Line {line_num} - YAML file not found: {yaml_file}")
                error_count += 1
                continue
            
            try:
                # Generate HTML
                html_content = parse_yaml_and_generate_html(yaml_file, topic)
                
                # Determine output filename
                output_file = yaml_path.with_suffix('.html')
                
                # Write output
                with open(output_file, 'w', encoding='utf-8') as out_f:
                    out_f.write(html_content)
                
                print(f"✓ Processed: {yaml_file} → {output_file}")
                processed_count += 1
                
            except Exception as e:
                print(f"Error: Line {line_num} - Failed to process {yaml_file}: {e}")
                error_count += 1
    
    print(f"\n{'='*60}")
    print(f"Batch processing complete!")
    print(f"Successfully processed: {processed_count} file(s)")
    print(f"Errors: {error_count} file(s)")
    print(f"{'='*60}")

def main():
    parser = argparse.ArgumentParser(
        description='Parse YAML syllabus and generate HTML (single or batch mode)'
    )
    
    # Create mutually exclusive group for single vs batch mode
    mode_group = parser.add_mutually_exclusive_group(required=True)
    mode_group.add_argument('--batch', type=str, help='Path to CSV/TXT batch file with topic,yaml_file pairs')
    mode_group.add_argument('--topic', type=str, help='Topic name for data-topic attribute (single file mode)')
    
    parser.add_argument('yaml_file', nargs='?', help='Path to YAML file (required in single file mode)')

    args = parser.parse_args()

    # Batch mode
    if args.batch:
        process_batch_file(args.batch)
    
    # Single file mode
    elif args.topic:
        if not args.yaml_file:
            parser.error("--topic requires yaml_file argument")
        
        yaml_path = Path(args.yaml_file)
        
        if not yaml_path.exists():
            print(f"Error: File {args.yaml_file} not found")
            sys.exit(1)

        # Generate HTML
        html_content = parse_yaml_and_generate_html(args.yaml_file, args.topic)

        # Determine output filename
        output_file = yaml_path.with_suffix('.html')

        # Write output
        with open(output_file, 'w', encoding='utf-8') as f:
            f.write(html_content)

        print(f"HTML generated successfully: {output_file}")

if __name__ == '__main__':
    main()
