#!/usr/bin/env python3
# Erzeugt data/reduced-index.json aus search-index.json (Suchindex der Google-Material-
# Icons-Website, https://fonts.google.com/icons): nur Icons, die die Familie
# "Material Icons" - und damit der eingebundene Font MaterialIcons-2.woff2 - kennt,
# reduziert auf name, categories und tags. Aufruf aus diesem Verzeichnis:
#     python3 reduce-search-index.py
import json
import os

def reduce_material_icons(input_path, output_path):
    with open(input_path, 'r', encoding='utf-8') as file:
        data = json.load(file)

    reduced_icons = []
    
    for icon in data.get('icons', []):
        # 1. Prüfen, ob das Icon von "Material Icons" NICHT unterstützt wird
        unsupported = icon.get("unsupported_families", [])
        if "Material Symbols Outlined" in unsupported:
            continue  # Überspringt diesen Durchlauf und geht zum nächsten Icon
            
        # 2. Wenn es unterstützt wird, Daten extrahieren
        reduced_icon = {
            "name": icon.get("name"),
            "categories": icon.get("categories", []),
            "tags": icon.get("tags", [])
        }
        reduced_icons.append(reduced_icon)

    output_data = {
        "icons": reduced_icons
    }

    with open(output_path, 'w', encoding='utf-8') as file:
        json.dump(output_data, file, ensure_ascii=False, indent=2)

    print(f"Erfolgreich {len(reduced_icons)} unterstützte Icons extrahiert und in {output_path} gespeichert.")

if __name__ == "__main__":
    # Dateinamen entsprechend anpassen
    here = os.path.dirname(os.path.abspath(__file__))
    INPUT_FILE = os.path.join(here, 'search-index.json')
    OUTPUT_FILE = os.path.join(here, '..', 'data', 'reduced-index.json')
    
    reduce_material_icons(INPUT_FILE, OUTPUT_FILE)