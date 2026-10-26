import json
import tempfile
import unittest
from pathlib import Path
from package_legacy_odds import package


class PackageTests(unittest.TestCase):
    def payload(self, day=9):
        return {"queryUrl": f"vfc_stats_round_odds2/vf:season:3147366/{day}",
                "doc": [{"data": {"odds": [{"tournament_id": "vf:tournament:14149"}]}}]}

    def test_scan_uses_payload_route_not_filename_and_preserves_bytes(self):
        with tempfile.TemporaryDirectory() as root:
            root = Path(root)
            source = root / "source"
            source.mkdir()
            body = json.dumps(self.payload(), indent=2).encode()
            (source / "misleading-name.json").write_bytes(body)
            (source / "duplicate.json").write_bytes(body)
            (source / "other.json").write_text("{}")
            output = root / "package"
            counts, coverage = package(source, output)
            files = list((output / "files").glob("*.json"))
            self.assertEqual(len(files), 1)
            self.assertEqual(files[0].read_bytes(), body)
            self.assertEqual(counts["identical_duplicates"], 1)
            self.assertEqual(coverage[("virtual-euro", "3147366")], 1)
            manifest = json.loads((output / "manifest.json").read_text())
            self.assertIsNone(manifest["files"][0]["original_received_at"])
            self.assertEqual(manifest["files"][0]["matchday"], 9)

    def test_refuses_existing_destination_or_output_inside_source(self):
        with tempfile.TemporaryDirectory() as root:
            with self.assertRaises(ValueError):
                package(root, root)
            with self.assertRaises(ValueError):
                package(root, Path(root) / "nested")

    def test_season_filter_does_not_copy_unrequested_data(self):
        with tempfile.TemporaryDirectory() as root:
            root = Path(root)
            source = root / "source"
            source.mkdir()
            (source / "record.json").write_text(json.dumps(self.payload()))
            counts, coverage = package(source, root / "output", {"3147685"})
            self.assertEqual(counts["staged"], 0)
            self.assertEqual(len(coverage), 0)


if __name__ == "__main__":
    unittest.main()
