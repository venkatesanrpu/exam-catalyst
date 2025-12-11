import yaml
import os
import argparse
from pathlib import Path

def parse_concepts_file(input_file, output_file):
    """
    Parse YAML file and extract metadata and core concepts.
    Save to filename_concepts.yaml with count in header.
    """
    with open(input_file, 'r', encoding='utf-8') as f:
        data = yaml.safe_load(f)

    # Extract core concepts
    core_concepts = data.get('concepts', {}).get('core', [])
    total_core_concepts = len(core_concepts)

    # Extract required fields
    output_data = {
        'metadata': data.get('metadata', {}),
        'total_core_concepts': total_core_concepts,
        'concepts': {
            'core': core_concepts
        }
    }

    # Write to output file
    with open(output_file, 'w', encoding='utf-8') as f:
        yaml.dump(output_data, f, default_flow_style=False, allow_unicode=True, sort_keys=False)

    print(f"Created: {output_file} (Total core concepts: {total_core_concepts})")

def parse_learning_file(input_file, output_file):
    """
    Parse YAML file and extract metadata, id, title, and learning_objectives.
    Save to filename_learning.yaml with count in header.
    """
    with open(input_file, 'r', encoding='utf-8') as f:
        data = yaml.safe_load(f)

    # Extract learning path entries
    learning_path = data.get('learning_path', [])
    total_titles = len(learning_path)

    # Build output data
    output_data = {
        'metadata': data.get('metadata', {}),
        'total_titles': total_titles,
        'learning_path': []
    }

    for item in learning_path:
        entry = {
            'id': item.get('id'),
            'title': item.get('title'),
            'learning_objectives': item.get('learning_objectives', [])
        }
        output_data['learning_path'].append(entry)

    # Write to output file
    with open(output_file, 'w', encoding='utf-8') as f:
        yaml.dump(output_data, f, default_flow_style=False, allow_unicode=True, sort_keys=False)

    print(f"Created: {output_file} (Total titles: {total_titles})")

def process_folder(folder_path):
    """
    Process all YAML files in the specified folder.
    """
    folder = Path(folder_path)

    if not folder.exists():
        print(f"Error: Folder '{folder_path}' does not exist.")
        return

    if not folder.is_dir():
        print(f"Error: '{folder_path}' is not a directory.")
        return

    # Find all .yaml and .yml files
    yaml_files = list(folder.glob('*.yaml')) + list(folder.glob('*.yml'))

    if not yaml_files:
        print(f"No YAML files found in '{folder_path}'")
        return

    print(f"Found {len(yaml_files)} YAML file(s) in '{folder_path}'")
    print("-" * 60)

    for yaml_file in yaml_files:
        # Skip already processed files
        if '_concepts' in yaml_file.stem or '_learning' in yaml_file.stem:
            print(f"Skipping: {yaml_file.name} (already processed file)")
            continue

        print(f"\nProcessing: {yaml_file.name}")

        # Generate output filenames
        base_name = yaml_file.stem
        concepts_file = folder / f"{base_name}_concepts.yaml"
        learning_file = folder / f"{base_name}_learning.yaml"

        try:
            # Parse and create concepts file
            parse_concepts_file(yaml_file, concepts_file)

            # Parse and create learning file
            parse_learning_file(yaml_file, learning_file)

        except Exception as e:
            print(f"Error processing {yaml_file.name}: {str(e)}")

    print("\n" + "-" * 60)
    print("Processing complete!")

def main():
    parser = argparse.ArgumentParser(
        description='Parse YAML files to extract concepts and learning objectives'
    )
    parser.add_argument(
        '--folder',
        type=str,
        required=True,
        help='Folder path containing YAML files to process'
    )

    args = parser.parse_args()
    process_folder(args.folder)

if __name__ == '__main__':
    main()
