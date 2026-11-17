"""Run after content-fixture.php on a disposable local WordPress installation."""
import argparse
import json
from pathlib import Path
from urllib.parse import urlparse, parse_qs
from smoke import Browser, InputParser

password = "dexo-content-local-test-password"


def fields(page):
    parser = InputParser(page)
    return {key: parser.values[key] for key in ("_dexo_nonce", "revision") if key in parser.values}


def run(base, fixture, route):
    author = Browser(base, route)
    page, _, _ = author.login("dexo_content_author", password)
    assert "Posts" in page and "Media library" in page and "Pages" not in page
    assert "Dexo content test own" in page and "Dexo content test other" not in page
    author.panel(tab="orders", expect=404)
    author.panel(tab="pages", expect=404)
    own_page = author.panel(tab="posts", id=fixture["own"])[0]
    own_fields = fields(own_page)
    values = {"title": "Updated author draft", "body": "<p>Test body with a backslash \\ and a quote '.</p>", "status": "draft"}
    result = author.panel(tab="posts", id=fixture["own"], data={**own_fields, **values})[0]
    assert "Updated author draft" in result
    author.panel(tab="posts", id=fixture["own"], expect=409, data={**own_fields, **values})
    author.panel(tab="posts", id=fixture["own"], expect=403, data=values)
    # Another author's record stays hidden and rejects a forged request.
    assert "This record is unavailable." in author.panel(tab="posts", id=fixture["other"])[0]
    author.panel(tab="posts", id=fixture["other"], expect=403, data={**own_fields, **values})

    new_page = author.panel(tab="posts", new=1)[0]
    result, url, _ = author.panel(tab="posts", new=1, data={
        **fields(new_page), "title": "Created from Dexo", "body": "<p>Simple article</p>", "status": "draft",
    })
    assert "Created from Dexo" in result and "id" in parse_qs(urlparse(url).query)
    result = author.panel(tab="posts", id=fixture["published"])[0]
    assert "Body (text or basic HTML)" in result

    contributor = Browser(base, route)
    contributor.login("dexo_content_contributor", password)
    result = contributor.panel(tab="posts", id=fixture["contributor"])[0]
    assert '<option value="publish"' not in result
    contributor.panel(tab="posts", id=fixture["contributor"], expect=403, data={
        **fields(result), "title": "Cannot publish", "body": "Draft", "status": "publish",
    })
    contributor.panel(tab="media", expect=404)

    editor = Browser(base, route)
    editor.login("dexo_content_editor", password)
    result = editor.panel(tab="posts")[0]
    assert "Dexo content test other" in result and "Updated author draft" in result
    result = editor.panel(tab="pages", id=fixture["page"])[0]
    editor.panel(tab="pages", id=fixture["page"], data={
        **fields(result), "title": "About our shop", "body": "<p>Updated page</p>", "status": "publish",
    })
    block_page = editor.panel(tab="pages", id=fixture["blocks"])[0]
    assert "Open WordPress editor" in block_page and 'name="body"' not in block_page
    warehouse = Browser(base, route)
    warehouse.login("dexo_warehouse_test")
    warehouse.panel(tab="posts", expect=404)
    warehouse.panel(tab="media", expect=404)
    print("Passed content creation, record permissions, stale updates, publication limits, and block preservation UI.")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("base")
    parser.add_argument("fixture", type=Path)
    parser.add_argument("--route", default="/dexo/")
    args = parser.parse_args()
    run(args.base, json.loads(args.fixture.read_text()), args.route)
