"""HTTP integration checks on a fresh installation seeded using fixture.php."""
import argparse
from http.cookiejar import CookieJar
from html.parser import HTMLParser
import json
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import urlencode, urljoin
from urllib.request import Request, build_opener, HTTPCookieProcessor


class InputParser(HTMLParser):
    def __init__(self, document):
        super().__init__()
        self.values = {}
        self.feed(document)

    def handle_starttag(self, tag, attrs):
        fields = dict(attrs)
        if tag == "input" and "name" in fields:
            self.values[fields["name"]] = fields.get("value", "")


class Browser:
    def __init__(self, base, route):
        self.base = base
        self.route = route
        self.opener = build_opener(HTTPCookieProcessor(CookieJar()))

    def request(self, url, data=None, expect=200):
        payload = urlencode(data).encode() if data is not None else None
        request = Request(urljoin(self.base, url), data=payload)
        try:
            response = self.opener.open(request, timeout=12)
        except HTTPError as error:
            response = error
        with response:
            page = response.read().decode()
            assert response.code == expect, (response.code, expect, url, page[:400])
            return page, response.url, response.headers

    def panel(self, data=None, expect=200, **query):
        url = self.route
        if query:
            url += ("&" if "?" in url else "?") + urlencode(query)
        return self.request(url, data=data, expect=expect)

    def login(self, username, password="dexo-fixture-password-test-only", allowed=True):
        page, url, _ = self.panel()
        assert "wp-login.php" in url and "dexo-login-css" in page and ">DEXO</a>" in page
        inputs = InputParser(page).values
        return self.request(
            "/wp-login.php",
            {"log": username, "pwd": password, "testcookie": "1", "redirect_to": inputs["redirect_to"]},
            expect=200 if allowed else 403,
        )

    def write(self, tab, id, values, expect=200):
        page, _, _ = self.panel(tab=tab, id=id)
        nonce = InputParser(page).values["_dexo_nonce"]
        return self.panel(
            tab=tab, id=id, expect=expect, data={"_dexo_nonce": nonce, **values}
        )[0]


def run(base, route, ids, admin_login, admin_password):
    order, product, parent, variation = (ids[key] for key in ("order", "product", "parent", "variation"))
    admin = Browser(base, route)
    page, url, headers = admin.login(admin_login, admin_password)
    assert "<h1>Orders</h1>" in page and "wp-admin-bar" not in page and "wp-login.php" not in url
    assert "no-store" in headers["Cache-Control"]
    assert f"#{order}</a>" in admin.panel(order=order)[0]
    assert "No orders found." in admin.panel(order=order, status="wc-completed")[0]

    customer = Browser(base, route)
    assert "Access restricted" in customer.login("dexo_customer_test", allowed=False)[0]

    warehouse = Browser(base, route)
    warehouse.login("dexo_warehouse_test")
    page, url, _ = warehouse.request("/wp-admin/")
    assert "wp-admin" not in url and "<h1>Orders</h1>" in page
    page = warehouse.panel(tab="orders", id=order)[0]
    for private in ("person@example.test", "555-0109", "22 Example Road", "Synthetic private note", "Order total", "Save status"):
        assert private not in page, private
    assert "Canvas utility bag" in page and "Save tracking code" in page
    page = warehouse.panel(tab="orders", id=order, print=1)[0]
    assert "Packing slip" in page and "dexo-print" in page and "22 Example Road" not in page
    warehouse.write("orders", order, {"change": "status", "status": "completed", "previous": "on-hold"}, expect=403)
    warehouse.panel(tab="orders", id=order, expect=403, data={"change": "tracking", "tracking": "INVALID"})
    assert "DX-TRACK-01" in warehouse.write("orders", order, {"change": "tracking", "tracking": "DX-TRACK-01", "previous": ""})
    warehouse.write("orders", order, {"change": "tracking", "tracking": "STALE", "previous": ""}, expect=409)

    orders = Browser(base, route)
    orders.login("dexo_orders_test")
    page = orders.panel(tab="orders", id=order)[0]
    assert "person@example.test" in page and "Synthetic private note" in page
    assert "22 Example Road" in orders.panel(tab="orders", id=order, print=1)[0]
    orders.panel(tab="products", id=product, expect=404)
    assert "Completed" in orders.write("orders", order, {"change": "status", "status": "completed", "previous": "on-hold"})
    assert "Check packing carton" in orders.write("orders", order, {"change": "note", "note": "Check packing carton"})
    orders.write("orders", order, {"change": "status", "status": "refunded", "previous": "completed"}, expect=400)
    orders.write("orders", order, {"change": "status", "status": "on-hold", "previous": "processing"}, expect=409)

    assert "Canvas utility bag" in warehouse.panel(tab="products", q="DX-BAG")[0]
    assert "Regular price" not in warehouse.panel(tab="products", id=product)[0]
    warehouse.write("products", product, {"change": "price", "price": "49", "previous": "45"}, expect=403)
    assert 'value="30"' in warehouse.write("products", product, {"change": "quantity", "quantity": "۳۰", "previous": ids["stock"]})
    warehouse.write("products", product, {"change": "quantity", "quantity": "28", "previous": ids["stock"]}, expect=409)
    assert "uses the parent product" in warehouse.panel(tab="products", id=variation)[0]
    assert f'id={variation}' in warehouse.panel(tab="products", id=parent)[0]

    catalog = Browser(base, route)
    catalog.login("dexo_catalog_test")
    catalog.write("products", variation, {"change": "quantity", "quantity": "10", "previous": "40"}, expect=400)
    result = catalog.write("products", product, {"change": "price", "price": "۴۹٫۵۰", "previous": "45"})
    assert 'value="49.5"' in result or 'value="49.50"' in result
    catalog.panel(tab="orders", expect=404)
    admin.request("/wp-admin/")
    print(f"Passed Dexo HTTP integration checks · HPOS={ids['hpos']} · route={route}")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("base")
    parser.add_argument("fixture", type=Path)
    parser.add_argument("--route", default="/dexo/")
    parser.add_argument("--admin-login", default="admin")
    parser.add_argument("--admin-password", required=True)
    args = parser.parse_args()
    run(args.base, args.route, json.loads(args.fixture.read_text()), args.admin_login, args.admin_password)
