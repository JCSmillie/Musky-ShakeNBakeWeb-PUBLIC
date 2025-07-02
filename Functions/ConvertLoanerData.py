#!/usr/bin/env python3

import sys
import json
import csv
import io

def mosyle_csv(json_text):
    text = json_text.encode('utf-8', errors='ignore').decode('utf-8', errors='ignore')

    try:
        data = json.loads(text)
    except Exception as e:
        print(f"❌ Error loading JSON: {e}", file=sys.stderr)
        sys.exit(1)

    devices = data.get("response", {}).get("devices", [])

    output = io.StringIO()
    writer = csv.writer(output, lineterminator='\n')
    writer.writerow(["deviceudid", "serial_number", "asset_tag", "userid", "last_ip_beat", "date_last_beat", "needosupdate", "enrollment_type"])

    for d in devices:
        writer.writerow([
            d.get("deviceudid", ""),
            d.get("serial_number", ""),
            d.get("asset_tag", ""),
            d.get("userid", ""),
            d.get("last_ip_beat", ""),
            d.get("date_last_beat", ""),
            d.get("needosupdate", ""),
            d.get("enrollment_type", "")
        ])

    return output.getvalue()

def main():
    # Read raw JSON from stdin
    raw_json = sys.stdin.read()

    # Process and output CSV
    csv_output = mosyle_csv(raw_json)
    print(csv_output)

if __name__ == "__main__":
    main()

