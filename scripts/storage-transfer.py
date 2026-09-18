#!/usr/bin/env python3
"""Non-destructive, resumable S3 copy. Credentials stay in a private runtime file."""
import argparse
import getpass
import hashlib
import json
import os
from pathlib import Path
import subprocess
import time
import urllib.request
import zipfile


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("action", choices=["setup", "size", "copy", "check", "verify-progress"])
    parser.add_argument("--runtime", default="/tmp/kr-storage-transfer")
    parser.add_argument("--source")
    parser.add_argument("--destination", required=True)
    args = parser.parse_args()
    runtime = Path(args.runtime)
    runtime.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.chmod(runtime, 0o700)
    config = runtime / "rclone.conf"
    binary = runtime / "rclone"
    if args.action == "setup":
        version = "v1.75.1"
        archive = "rclone-" + version + "-linux-amd64.zip"
        base = "https://downloads.rclone.org/" + version + "/"
        checksums = urllib.request.urlopen(base + "SHA256SUMS", timeout=60).read().decode()
        expected = next(parts[0] for line in checksums.splitlines() if len(parts := line.split()) == 2 and parts[1].lstrip("*") == archive)
        data = urllib.request.urlopen(base + archive, timeout=120).read()
        if hashlib.sha256(data).hexdigest() != expected:
            raise RuntimeError("Rclone release checksum mismatch")
        import io
        with zipfile.ZipFile(io.BytesIO(data)) as package:
            binary.write_bytes(package.read("rclone-" + version + "-linux-amd64/rclone"))
        os.chmod(binary, 0o700)
        access = getpass.getpass("S3 access key: ")
        secret = getpass.getpass("S3 secret key: ")
        if any(c in access + secret for c in "\r\n"):
            raise ValueError("Invalid credential")
        fd = os.open(config, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        with os.fdopen(fd, "w") as out:
            out.write("[twc]\ntype = s3\nprovider = Other\naccess_key_id = " + access
                      + "\nsecret_access_key = " + secret
                      + "\nregion = ru-1\nendpoint = https://s3.twcstorage.ru\nacl = private\n")
        print("Private transfer runtime configured; original site unchanged.", flush=True)
        return
    if not config.is_file() or not binary.is_file():
        raise RuntimeError("Run setup first")
    if args.action == "verify-progress":
        verify_progress(args, binary, config, runtime)
        return
    command = [str(binary), "--config", str(config), args.action]
    destination = "twc:" + args.destination
    if args.action == "size":
        command += [destination, "--json"]
    else:
        if not args.source or not Path(args.source).is_dir():
            raise ValueError("Existing source directory is required")
        command += [args.source, destination, "--exclude", ".gitignore", "--exclude", ".DS_Store",
                    "--exclude", "livewire-tmp/**", "--stats", "30s", "--stats-one-line",
                    "--log-level", "NOTICE", "--stats-log-level", "NOTICE", "--disable-http2", "--checkers", "8"]
        if args.action == "copy":
            command += ["--immutable", "--checksum", "--transfers", "4", "--retries", "3"]
        else:
            command += ["--download", "--one-way"]
    raise SystemExit(subprocess.call(command))


def verify_progress(args, binary, config, runtime):
    source = Path(args.source).resolve()
    if not source.is_dir():
        raise ValueError("Existing source directory is required")
    files = {str(p.relative_to(source)): p for p in source.rglob("*")
             if p.is_file() and not p.is_symlink() and p.name not in [".gitignore", ".DS_Store"]
             and "livewire-tmp" not in p.parts}
    if any("\n" in p or "\r" in p for p in files):
        raise ValueError("Line-based verification cannot safely represent source filenames")
    destination = "twc:" + args.destination
    base = [str(binary), "--config", str(config), "--disable-http2"]
    verified = set()
    last_progress = time.monotonic()
    manifest = runtime / "verified.json"
    while len(verified) < len(files):
        available = set(subprocess.check_output(base + ["lsf", destination, "--recursive", "--files-only"], text=True).splitlines())
        pending = sorted((available & files.keys()) - verified)
        if pending:
            selection = runtime / "verify-selection.txt"
            selection.write_text("\n".join(pending) + "\n")
            subprocess.run(base + ["check", str(source), destination, "--download", "--one-way",
                                  "--files-from-raw", str(selection), "--checkers", "4",
                                  "--stats", "30s", "--stats-one-line", "--stats-log-level", "NOTICE",
                                  "--log-level", "NOTICE"], check=True)
            verified.update(pending)
            last_progress = time.monotonic()
            manifest.write_text(json.dumps({"source": str(source), "destination": args.destination,
                                           "verified": sorted(verified)}, ensure_ascii=False))
            print(json.dumps({"verified_files": len(verified), "total_files": len(files)}), flush=True)
        elif time.monotonic() - last_progress > 600:
            raise RuntimeError("No new completed objects for 10 minutes; copy may need a retry")
        else:
            time.sleep(20)
    print("Every source file verified by downloading and comparing S3 content.", flush=True)


if __name__ == "__main__":
    main()
