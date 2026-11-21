"""Publish the EMS landing page and add its link to the public site navigation."""

import ftplib
import io
import os
from pathlib import Path
import re
import ssl
from urllib.request import urlopen

LANDING = "pdg-ems/index.html"
MENU_PAGES = (
    "index.html", "deye-support.html", "electrical-panels.html", "help-center.html",
    "panel-projects.html", "photovoltaic-systems.html", "solax-support.html",
)
SITEMAP_URL = b"https://pdgsystems.bg/pdg-ems/"
MENU_LINK = b'<a href="/pdg-ems/">PDG EMS</a>'
MENU_ANCHOR = rb'(>CAD Cloud</a>)(<a href="/?#contact">)'


def read_remote(ftp, name):
    data = io.BytesIO()
    ftp.retrbinary(f"RETR {name}", data.write)
    return data.getvalue()


def replace_remote(ftp, name, data):
    temporary = name + ".pdg-ems-upload"
    ftp.storbinary(f"STOR {temporary}", io.BytesIO(data))
    if read_remote(ftp, temporary) != data:
        raise RuntimeError(f"Incomplete upload: {name}")
    ftp.rename(temporary, name)


def add_menu_link(body):
    if MENU_LINK in body:
        return body
    result, count = re.subn(MENU_ANCHOR, rb"\g<1>" + MENU_LINK + rb"\g<2>", body)
    if count != 1:
        raise RuntimeError("Expected exactly one CAD Cloud menu anchor")
    return result


def main():
    files = ("pdg-ems/page.css", LANDING, *MENU_PAGES, "sitemap.xml")
    previous = {}
    with ftplib.FTP_TLS(context=ssl.create_default_context(), timeout=35) as ftp:
        ftp.connect(os.environ["FTP_HOST"], 21)
        ftp.login(os.environ["FTP_USER"], os.environ["FTP_PASSWORD"])
        ftp.prot_p()
        ftp.cwd("/")
        try:
            ftp.cwd("pdg-ems")
            ftp.cwd("/")
        except ftplib.error_perm as error:
            if not str(error).startswith("550"):
                raise
            ftp.mkd("pdg-ems")

        for name in files:
            try:
                previous[name] = read_remote(ftp, name)
            except ftplib.error_perm as error:
                if not name.startswith("pdg-ems/") or not str(error).startswith("550"):
                    raise
                previous[name] = None
            if previous[name] is not None:
                backup = Path("production-backup", name)
                backup.parent.mkdir(parents=True, exist_ok=True)
                backup.write_bytes(previous[name])

        updated = {name: Path(name).read_bytes() for name in files[:2]}
        for name in MENU_PAGES:
            updated[name] = add_menu_link(previous[name])
            if MENU_LINK not in Path(name).read_bytes():
                raise RuntimeError(f"Missing EMS navigation in repository: {name}")
        sitemap = previous["sitemap.xml"]
        if SITEMAP_URL not in sitemap:
            if sitemap.count(b"</urlset>") != 1:
                raise RuntimeError("Unexpected sitemap structure")
            sitemap = sitemap.replace(
                b"</urlset>", b"  <url><loc>" + SITEMAP_URL + b"</loc></url>\n</urlset>"
            )
        updated["sitemap.xml"] = sitemap

        published = []
        try:
            for name in files:
                if updated[name] != previous[name]:
                    replace_remote(ftp, name, updated[name])
                    published.append(name)
            release = os.environ["GITHUB_SHA"]
            for name, route in ((LANDING, "pdg-ems/"), ("pdg-ems/page.css", "pdg-ems/page.css"),
                                ("index.html", ""), ("sitemap.xml", "sitemap.xml")):
                url = f"https://pdgsystems.bg/{route}?ems_release={release}"
                with urlopen(url, timeout=35) as response:
                    if response.status != 200 or response.read() != updated[name]:
                        raise RuntimeError(f"Live verification failed: {url}")
                print(f"Verified {url}", flush=True)
        except Exception:
            for name in reversed(published):
                if previous[name] is None:
                    ftp.delete(name)
                else:
                    replace_remote(ftp, name, previous[name])
            raise


if __name__ == "__main__":
    main()
