#!/usr/bin/env python3
# combine_unit_xml.py
#
# Combine Moodle-XML question files into one unit XML:
#   advanced_01.xml + basic_01.xml + intermediate_01.xml -> unit_01.xml
#
# Usage:
#   python combine_unit_xml.py --folder inorganic --unit 01
#
# Requirements:
#   pip install lxml
#
# Logic: take everything inside <quiz> from each input and append into one <quiz>. [web:86][web:96]

import argparse
import logging
from pathlib import Path
from typing import List
from lxml import etree as ET


def setup_logger(folder: Path) -> logging.Logger:
    log_path = folder / "combine_output.log"
    logger = logging.getLogger("combine_moodle_xml")
    logger.setLevel(logging.DEBUG)

    if logger.handlers:
        return logger

    fh = logging.FileHandler(log_path, mode="w", encoding="utf-8")
    fh.setLevel(logging.DEBUG)
    fh.setFormatter(logging.Formatter("%(asctime)s [%(levelname)s] %(message)s"))

    ch = logging.StreamHandler()
    ch.setLevel(logging.INFO)
    ch.setFormatter(logging.Formatter("%(message)s"))

    logger.addHandler(fh)
    logger.addHandler(ch)
    return logger


def load_quiz_children(xml_path: Path) -> List[ET._Element]:
    """
    Returns direct children of <quiz> (typically <question> nodes).
    """
    parser = ET.XMLParser(remove_blank_text=True, recover=False)
    tree = ET.parse(str(xml_path), parser)
    root = tree.getroot()
    if root.tag != "quiz":
        raise ValueError(f"{xml_path.name}: root tag is '{root.tag}', expected 'quiz'")
    return list(root)


def combine_unit(folder: Path, unit: str, logger: logging.Logger) -> Path:
    unit2 = str(unit).zfill(2)

    inputs = [
        folder / f"advanced_{unit2}.xml",
        folder / f"basic_{unit2}.xml",
        folder / f"intermediate_{unit2}.xml",
    ]
    output = folder / f"unit_{unit2}.xml"

    missing = [p.name for p in inputs if not p.exists()]
    if missing:
        raise FileNotFoundError(f"Missing files in {folder}: {', '.join(missing)}")

    out_root = ET.Element("quiz")

    total_appended = 0
    for p in inputs:
        children = load_quiz_children(p)
        logger.info(f"{p.name}: found {len(children)} top-level elements under <quiz>")
        for child in children:
            out_root.append(child)  # move node into output tree
            total_appended += 1

    xml_bytes = ET.tostring(
        out_root,
        encoding="UTF-8",
        xml_declaration=True,
        pretty_print=True
    )
    output.write_bytes(xml_bytes)

    logger.info(f"Written: {output.name}")
    logger.info(f"Total appended elements: {total_appended}")
    return output


def main():
    ap = argparse.ArgumentParser(description="Combine advanced/basic/intermediate Moodle XML into unit XML.")
    ap.add_argument("--folder", required=True, help="Folder containing XML files.")
    ap.add_argument("--unit", required=True, help="Unit number like 01, 1, 02, etc.")
    args = ap.parse_args()

    folder = Path(args.folder)
    if not folder.exists() or not folder.is_dir():
        raise SystemExit(f"Folder not found: {folder}")

    logger = setup_logger(folder)
    combine_unit(folder, args.unit, logger)


if __name__ == "__main__":
    main()
