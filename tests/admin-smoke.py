"""Verify Dexo site-management workflows on the disposable admin fixture."""
import argparse
import json
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urlparse, parse_qs
from smoke import Browser


class Forms(HTMLParser):
    def __init__(self, document):
        super().__init__(convert_charrefs=True)
        self.forms = []
        self.form = None
        self.select = None
        self.option = None
        self.textarea = None
        self.feed(document)

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        if tag == "form":
            self.form = {"url": attrs.get("action", ""), "fields": {}}
            self.forms.append(self.form)
        if self.form is None:
            return
        if tag == "input" and "name" in attrs:
            if attrs.get("type") not in ("checkbox", "radio") or "checked" in attrs:
                self.form["fields"][attrs["name"]] = attrs.get("value", "")
        elif tag == "textarea" and "name" in attrs:
            self.textarea = attrs["name"]
            self.form["fields"][self.textarea] = ""
        elif tag == "select":
            self.select = attrs.get("name")
        elif tag == "option" and self.select:
            value = attrs.get("value", "")
            if self.select not in self.form["fields"] or "selected" in attrs:
                self.form["fields"][self.select] = value

    def handle_data(self, data):
        if self.form and self.textarea:
            self.form["fields"][self.textarea] += data

    def handle_endtag(self, tag):
        if tag == "form":
            self.form = None
        elif tag == "select":
            self.select = None
        elif tag == "textarea":
            self.textarea = None


def get_form(browser, tab, action, id=0, **match):
    page = browser.panel(tab=tab, id=id)[0]
    forms = Forms(page).forms
    found = [form for form in forms if form["fields"].get("action") == action and (not id or parse_qs(urlparse(form["url"]).query).get("id") == [str(id)]) and all(form["fields"].get(key) == str(value) for key, value in match.items())]
    assert found, (tab, action, match, page[:600])
    return found[-1]


def submit(browser, form, updates=None, expect=200):
    return browser.request(form["url"], data={**form["fields"], **(updates or {})}, expect=expect)


def run(base, route, fixture):
    admin = Browser(base, route)
    admin.login("dexo_admin_test", "Dexo-admin-fixture-local-123!")
    for tab in ("profile", "users", "comments", "categories", "tags", "menus", "general", "reading", "discussion", "media-settings", "health", "privacy"):
        page = admin.panel(tab=tab)[0]
        assert "</html>" in page, tab
        if tab == "health":
            assert page.count('<section class="card">') == 8
    general = get_form(admin, "general", "save")
    submit(admin, general, {"blogdescription": "A sample store tagline " + str(fixture["page"])})
    submit(admin, general, {"blogdescription": "Stale write"}, expect=409)
    general = get_form(admin, "general", "save")
    submit(admin, general, {"siteurl": "https://attacker.example", "blogname": general["fields"]["blogname"]})
    reading = get_form(admin, "reading", "save")
    submit(admin, reading, {"show_on_front": "page", "page_on_front": fixture["page"], "page_for_posts": "0", "posts_per_page": "12"})
    discussion = get_form(admin, "discussion", "save")
    submit(admin, discussion, {"comment_moderation": "1", "default_comment_status": "1"})
    media = get_form(admin, "media-settings", "save")
    submit(admin, media, {"thumbnail_size_w": "180", "thumbnail_size_h": "180"})
    media = get_form(admin, "media-settings", "save")
    submit(admin, media, {"large_size_w": "-1"}, expect=400)

    term = get_form(admin, "categories", "save")
    page, url, _ = submit(admin, term, {"name": "Dexo parent " + str(fixture["page"]), "slug": "dexo-parent-" + str(fixture["page"])})
    parent = parse_qs(urlparse(url).query)["id"][0]
    term = get_form(admin, "categories", "save")
    _, url, _ = submit(admin, term, {"name": "Dexo child " + str(fixture["page"]), "slug": "dexo-child-" + str(fixture["page"]), "parent": parent})
    child = parse_qs(urlparse(url).query)["id"][0]
    term = get_form(admin, "categories", "save", id=parent)
    submit(admin, term, {"parent": child}, expect=400)
    term = get_form(admin, "tags", "save")
    _, url, _ = submit(admin, term, {"name": "Dexo test tag", "slug": "dexo-test-tag"})
    tag = parse_qs(urlparse(url).query)["id"][0]
    deletion = get_form(admin, "tags", "delete", id=tag)
    submit(admin, deletion, {"confirm": "1"})

    moderation = get_form(admin, "comments", "moderate", id=fixture["comment"])
    submit(admin, moderation, {"moderation": "approve"})
    reply = get_form(admin, "comments", "reply", id=fixture["comment"])
    submit(admin, reply, {"reply": "Thanks for the sample feedback."})
    moderation = get_form(admin, "comments", "moderate", id=fixture["comment"])
    submit(admin, moderation, {"moderation": "spam"})
    # The list defaults to all active comments. Open the spam-filtered page to restore.
    page = admin.panel(tab="comments", status="spam")[0]
    form = next(form for form in Forms(page).forms if form["fields"].get("action") == "moderate" and parse_qs(urlparse(form["url"]).query).get("id") == [str(fixture["comment"])])
    submit(admin, form, {"moderation": "unspam"})

    create = get_form(admin, "users", "create")
    login = "dexo_created_" + parent
    _, url, _ = submit(admin, create, {"login": login, "display_name": "Sample colleague", "email": login+"@example.test", "role": "dexo_orders"})
    user_id = parse_qs(urlparse(url).query)["id"][0]
    edit = get_form(admin, "users", "edit", id=user_id)
    submit(admin, edit, {"display_name": "Updated sample colleague", "role": "dexo_warehouse"})
    reset = get_form(admin, "users", "reset", id=user_id)
    submit(admin, reset)

    policy = get_form(admin, "privacy", "policy")
    submit(admin, policy, {"policy_page": fixture["page"]})
    request = get_form(admin, "privacy", "request")
    submit(admin, request, {"email": "pending-"+parent+"@example.test", "request_action": "export_personal_data"})

    menu = get_form(admin, "menus", "create")
    _, url, _ = submit(admin, menu, {"name": "Dexo classic menu " + str(fixture["page"]), "menu_kind": "classic"})
    menu_id = parse_qs(urlparse(url).query)["id"][0]
    item = get_form(admin, "menus", "item", id=menu_id)
    submit(admin, item, {"title": "Our shop", "url": base, "position": "1"})
    location = get_form(admin, "menus", "location", id=menu_id)
    submit(admin, location, {"location": "dexo-test-primary"})

    editor = Browser(base, route)
    editor.login("dexo_editor_test", "Dexo-admin-fixture-local-123!")
    for tab in ("users", "general", "reading", "discussion", "media-settings", "menus", "health", "privacy"):
        editor.panel(tab=tab, expect=404)
    editor.panel(tab="comments")
    editor.panel(tab="categories")
    staff = Browser(base, route)
    staff.login("dexo_warehouse_test")
    for tab in ("users", "general", "comments", "categories", "menus", "privacy", "health"):
        staff.panel(tab=tab, expect=404)

    subscriber = Browser(base, route)
    subscriber.login("dexo_profile_test", "Dexo-admin-fixture-local-123!")
    profile = get_form(subscriber, "profile", "save")
    submit(subscriber, profile, {"display_name": "Updated profile", "user_id": fixture["users"]["dexo_admin_test"], "role": "administrator"})
    profile = get_form(subscriber, "profile", "save")
    submit(subscriber, profile, {"email": "new-profile@example.test", "current_password": "wrong"}, expect=400)
    for tab in ("orders", "products", "users", "general", "media", "posts", "privacy"):
        subscriber.panel(tab=tab, expect=404)
    print("Passed settings, users, profile isolation, comments, terms, classic menus, privacy requests, and role boundaries.")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("base")
    parser.add_argument("fixture", type=Path)
    parser.add_argument("--route", default="/dexo/")
    args = parser.parse_args()
    run(args.base, args.route, json.loads(args.fixture.read_text()))
