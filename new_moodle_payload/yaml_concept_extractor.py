#!/usr/bin/env python3
"""
YAML Concept Extractor
Extracts metadata and concepts sections from YAML files and saves them as separate files.
"""

import yaml
import argparse
import os
from pathlib import Path

def extract_concepts(yaml_file_path):
    """
    Extract metadata and concepts from a YAML file.

    Args:
        yaml_file_path (str): Path to the input YAML file

    Returns:
        dict: Dictionary containing metadata and concepts
    """
    try:
        with open(yaml_file_path, 'r', encoding='utf-8') as f:
            data = yaml.safe_load(f)

        # Extract only metadata and concepts
        extracted_data = {}

        if 'metadata' in data:
            extracted_data['metadata'] = data['metadata']

        if 'concepts' in data:
            extracted_data['concepts'] = data['concepts']

        return extracted_data

    except Exception as e:
        print(f"Error processing {yaml_file_path}: {str(e)}")
        return None

def save_concept_file(input_path, extracted_data):
    """
    Save extracted data to a new YAML file with _concept suffix.

    Args:
        input_path (str): Original file path
        extracted_data (dict): Data to save

    Returns:
        str: Path to the saved file
    """
    try:
        # Create output filename
        input_file = Path(input_path)
        output_filename = input_file.stem + '_concept' + input_file.suffix
        output_path = input_file.parent / output_filename

        # Save to YAML file
        with open(output_path, 'w', encoding='utf-8') as f:
            yaml.dump(extracted_data, f, default_flow_style=False, sort_keys=False, allow_unicode=True)

        print(f"✓ Created: {output_path}")
        return str(output_path)

    except Exception as e:
        print(f"Error saving file: {str(e)}")
        return None

def process_file(file_path):
    """Process a single YAML file."""
    print(f"Processing: {file_path}")
    extracted_data = extract_concepts(file_path)

    if extracted_data:
        save_concept_file(file_path, extracted_data)
    else:
        print(f"✗ Failed to process: {file_path}")

def process_folder(folder_path):
    """Process all YAML files in a folder."""
    folder = Path(folder_path)

    if not folder.exists():
        print(f"Error: Folder '{folder_path}' does not exist.")
        return

    if not folder.is_dir():
        print(f"Error: '{folder_path}' is not a directory.")
        return

    # Find all YAML files
    yaml_files = list(folder.glob('*.yaml')) + list(folder.glob('*.yml'))

    if not yaml_files:
        print(f"No YAML files found in '{folder_path}'")
        return

    print(f"\nFound {len(yaml_files)} YAML file(s) in '{folder_path}'\n")

    for yaml_file in yaml_files:
        process_file(str(yaml_file))
        print()  # Empty line between files

def main():
    parser = argparse.ArgumentParser(
        description='Extract metadata and concepts from YAML files',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  # Process a single file
  python yaml_concept_extractor.py unit_09.yaml

  # Process all YAML files in a folder
  python yaml_concept_extractor.py --folder myfolder

  # Process all YAML files in current directory
  python yaml_concept_extractor.py --folder .
        """
    )

    parser.add_argument(
        'file',
        nargs='?',
        help='Input YAML file to process (if not using --folder)'
    )

    parser.add_argument(
        '--folder',
        '-f',
        help='Process all YAML files in the specified folder'
    )

    args = parser.parse_args()

    # Check if folder or file argument is provided
    if args.folder:
        process_folder(args.folder)
    elif args.file:
        if not os.path.exists(args.file):
            print(f"Error: File '{args.file}' does not exist.")
            return
        process_file(args.file)
    else:
        parser.print_help()
        print("\nError: Please provide either a file or use --folder option.")

if __name__ == '__main__':
    main()
