import os
import argparse

def count_occurrences_in_file(filepath, search_string):
    """Count occurrences of search_string in a single file."""
    try:
        with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
            content = f.read()
        return content.count(search_string)
    except Exception as e:
        print(f"Error reading {filepath}: {e}")
        return 0

def count_occurrences_in_folder(folder, search_string):
    """Count occurrences of search_string in all HTML files in folder."""
    total_count = 0
    for root, _, files in os.walk(folder):
        for file in files:
            if file.lower().endswith(".html"):
                filepath = os.path.join(root, file)
                total_count += count_occurrences_in_file(filepath, search_string)
    return total_count

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Count string occurrences in HTML files.")
    parser.add_argument("--folder", required=True, help="Folder containing HTML files")
    args = parser.parse_args()

    search_string = "Study Notes"
    total = count_occurrences_in_folder(args.folder, search_string)
    print(f"Total occurrences of '{search_string}': {total}")