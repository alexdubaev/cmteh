"""Check the public HTTP contract of a seeded, loopback-only OpenCart install."""

import argparse
import json
import re
from html import unescape
from urllib.error import HTTPError
from urllib.parse import urlencode, urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener
from xml.etree import ElementTree

NS = "{http://www.sitemaps.org/schemas/sitemap/0.9}"
SYSTEM = {"/", "/catalog", "/brands", "/articles", "/delivery", "/payment", "/about", "/contacts"}
BRANDS = "caterpillar komatsu volvo hitachi jcb doosan john-deere cnh claas perkins sany"
CATEGORIES = "filters engine cooling hydraulics attachments undercarriage fuel transmission"
PRODUCTS = (
    "cat-1r-1808 cat-320-0600 cat-223-7962 komatsu-207-27-00310 komatsu-205-70-19570 "
    "volvo-voe14550092 hitachi-4633600 jcb-320-04542 jcb-332-l8078 doosan-400504-00215 "
    "jd-re568158 jd-dz121294 cnh-378410a1 claas-0000550380 perkins-2654403 sany-1903085"
)


class RejectRedirects(HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base", required=True)
    parser.add_argument("--fixture", action="store_true", help="also check opt-in HTTP test records")
    options = parser.parse_args()
    base = options.base.rstrip("/")
    url = urlsplit(base)
    if (
        url.scheme != "http"
        or url.hostname not in {"127.0.0.1", "::1"}
        or url.netloc not in {f"127.0.0.1:{url.port}", f"[::1]:{url.port}"}
        or url.path or url.query or url.fragment
    ):
        parser.error("Provide an explicit loopback HTTP origin and port.")
    opener = build_opener(RejectRedirects())

    def request(path, expected=200, headers=None, method="GET", data=None):
        try:
            result = opener.open(Request(base + path, data=data, headers=headers or {}, method=method), timeout=8)
        except HTTPError as error:
            result = error
        with result:
            assert result.code == expected, f"{method} {path}: {result.code}, expected {expected}"
            return result.read(), result.headers

    def metadata(body, key):
        if key == "canonical":
            pattern = r'<link rel="canonical" href="([^"]+)"'
        else:
            pattern = rf'<meta name="{key}" content="([^"]+)"'
        match = re.search(pattern, body.decode())
        return unescape(match.group(1)) if match else None

    def schemas(body, kind):
        nodes = []
        for raw in re.findall(rb'<script type="application/ld\+json">(.*?)</script>', body, re.S):
            value = json.loads(raw)
            nodes.extend(value.get("@graph", [value]))
        return [node for node in nodes if node.get("@type") == kind]

    def locs(path):
        body, headers = request(path)
        assert headers.get_content_type() == "application/xml", path
        return [element.text for element in ElementTree.fromstring(body).iter(NS + "loc")]

    home, _ = request("/")
    assert '<h1' in home.decode() and 'Запчасти для спецтехники' in home.decode(), "expected Twig homepage"
    assert schemas(home, "Organization"), "missing Organization"
    print("PASS Twig homepage and Organization")

    static_paths = (
        SYSTEM | {f"/brand/{slug}" for slug in BRANDS.split()}
        | {f"/category/{slug}" for slug in CATEGORIES.split()} | {"/articles/zayavka-po-spisku-artikulov"}
    )
    product_paths = {f"/product/{slug}" for slug in PRODUCTS.split()}
    assert locs("/sitemap") == locs("/sitemap.xml") == [base + "/sitemaps/static.xml", base + "/sitemaps/products-0.xml"]
    for path, expected in [("/sitemaps/static.xml", static_paths), ("/sitemaps/products-0.xml", product_paths)]:
        values = locs(path)
        assert set(values) == {base + item for item in expected} and len(values) == len(expected), path
    for path in sorted(static_paths | product_paths):
        body, _ = request(path)
        assert metadata(body, "canonical") == base + path, path
        assert "noindex" not in (metadata(body, "robots") or ""), path
    request("/sitemaps/products-1.xml", 404)
    request("/sitemaps/products-00.xml", 404)
    print("PASS sitemap index and 44 unique, canonical, indexable HTTP 200 targets")

    for path, canonical, robots in [
        ("/catalog?page=2", "/catalog?page=2", None),
        ("/catalog?q=RE-568158", "/catalog", "noindex,follow"),
        ("/catalog?brand=jcb&partType=original&page=2", "/catalog", "noindex,follow"),
        ("/catalog?sort=price_desc", "/catalog", "noindex,follow"),
        ("/request?import=1&article=0000550380", "/request", "noindex,nofollow"),
    ]:
        body, _ = request(path, headers={"Host": "injected.invalid"})
        assert metadata(body, "canonical") == base + canonical, path
        assert (metadata(body, "robots") or "").replace(" ", "") == (robots or ""), path
    robots, _ = request("/robots.txt")
    for path in ("/catalog?page=1000", "/articles?page=1000", "/category/filters?page=1000", "/brand/john-deere?page=1000"):
        body, _ = request(path, 404)
        assert metadata(body, "canonical") is None, path
    _, headers = request("/page/%D0%94%D0%BE%D1%81%D1%82%D0%B0%D0%B2%D0%BA%D0%B0", 301)
    assert headers["Location"] == base + "/delivery"
    for expected in ("Allow: /api/assets/", "Disallow: /api/", "Disallow: /admin/", f"Sitemap: {base}/sitemap.xml"):
        assert expected in robots.decode(), expected
    print("PASS canonical origin, pagination, filters, robots and request")

    for slug, sku, price in [("jd-re568158", "RE568158", 3200), ("jcb-332-l8078", "332/L8078", None),
                             ("claas-0000550380", "0000550380", None)]:
        path = f"/product/{slug}"
        body, _ = request(path)
        products = schemas(body, "Product")
        assert len(products) == 1 and products[0]["sku"] == sku and sku in body.decode(), path
        product = products[0]
        assert product["@id"] == base + path + "#product", path
        assert product["brand"]["@id"].endswith("#brand") and schemas(body, "BreadcrumbList"), path
        if price is None:
            assert "offers" not in product and "Цена по запросу" in body.decode(), path
        else:
            assert product["offers"]["@id"] == base + path + "#offer"
            assert product["offers"]["price"] == price and product["offers"]["priceCurrency"] == "RUB"
            assert re.search(r"3\s+200", unescape(body.decode())), "the fixed price should be visible"
    for path, kind in [("/brand/john-deere", "CollectionPage"), ("/category/filters", "CollectionPage"),
                       ("/articles/zayavka-po-spisku-artikulov", "Article")]:
        body, _ = request(path)
        entities = schemas(body, kind)
        assert entities and schemas(body, "BreadcrumbList"), path
        if kind == "CollectionPage":
            assert entities[0]["mainEntity"]["@type"] == "ItemList", path
        else:
            assert b'href="/request?import=1"' in body and b"000123" in body, path
    demo, _ = request("/articles/demonstration")
    assert metadata(demo, "robots") == "noindex,follow"
    print("PASS Product, Offer, leading-zero SKU, collections, breadcrumbs and Article JSON-LD")

    for path in ("/not-published", "/product/unknown", "/product/JD-RE568158", "/product/%2f",
                 "/product/%256a", "/index.php?route=product/product&product_id=1",
                 "/api/order", "/api/assets/00000000-0000-4000-8000-000000000000"):
        request(path, 404)
    _, headers = request("/catalog/?q=RE-568158", 301)
    assert headers["Location"] == base + "/catalog?q=RE-568158"
    request("/product/%6ad-re568158", 301)
    _, headers = request("/catalog", method="HEAD")
    assert headers.get_content_type() == "text/html"
    print("PASS exact paths, direct normalization, HEAD and inaccessible routes")

    for query, sku in [("RE-568158", "RE568158"), ("0000550380", "0000550380")]:
        path = "/api/search?" + urlencode({"q": query, "mode": "suggestions"})
        body, headers = request(path)
        payload = json.loads(body)
        assert headers.get_content_type() == "application/json"
        assert payload["data"][0]["sku"] == sku
        assert payload["meta"]["total"] == len(payload["data"]) <= 6
    body, _ = request("/api/search?q=%D1%84%D0%B8%D0%BB%D1%8C%D1%82%D1%80")
    payload = json.loads(body)
    assert 0 < len(payload["data"]) <= 10 and payload["meta"]["total"] >= len(payload["data"])
    assert json.loads(request("/api/search?q=x")[0]) == {"data": [], "meta": {"total": 0}}
    request("/api/search?q=RE568158&mode=other", 400)
    request("/api/search?q=" + "x" * 65, 400)
    request("/api/search?q%5B%5D=abc", 400)
    request("/api/search", 405, method="POST")
    print("PASS search payload, normalization, bounds and methods")

    _, headers = request("/api/lead", 405)
    assert headers["Allow"] == "POST"
    request("/api/lead", 403, method="POST", headers={"Origin": "http://foreign.invalid"}, data=b"{}")
    request("/api/lead", 403, method="POST", headers={"Sec-Fetch-Site": "cross-site"}, data=b"{}")
    request("/api/lead", 415, method="POST", headers={"Content-Type": "text/plain"}, data=b"{}")
    request("/api/lead", 413, method="POST", headers={"Content-Type": "application/json"}, data=b"x" * 512001)
    request("/api/lead", 400, method="POST", headers={"Origin": base, "Content-Type": "application/json"}, data=b"{")
    print("PASS lead method, origin, content type, body bounds and invalid JSON")

    if options.fixture:
        for slug, expected_offer in [("cmteh-http-zero-confirmed", True), ("cmteh-http-zero-unconfirmed", False)]:
            page, _ = request(f"/product/{slug}")
            product = schemas(page, "Product")[0]
            assert ("offers" in product) == expected_offer, slug
            if expected_offer:
                assert product["offers"]["price"] == 0, slug
            else:
                assert "Цена по запросу" in page.decode(), slug
        print("PASS only confirmed zero price receives a visible Offer")
        _, headers = request("/http-test-old", 301)
        assert headers["Location"] == base + "/product/jd-re568158"
        request("/http-test-gone", 410)
        request("/http-test-chain", 404)
        request("/http-test-loop", 404)
        request("/product/%D1%81%D0%BC-%D1%82%D0%B5%D1%85%D0%BD%D0%BE", 200)
        request("/articles/%D1%81%D0%BC-%D1%82%D0%B5%D1%85%D0%BD%D0%BE", 200)
        image, headers = request("/api/assets/11111111-1111-4111-8111-111111111111")
        assert image.startswith(b"\x89PNG\r\n\x1a\n") and headers.get_content_type() == "image/png"
        assert headers["Cache-Control"] == "no-store"
        request("/api/assets/22222222-2222-4222-8222-222222222222", 404)
        _, headers = request("/api/assets/33333333-3333-4333-8333-333333333333", 301)
        assert headers["Location"] == base + "/api/assets/11111111-1111-4111-8111-111111111111"
        print("PASS imported redirect, 410, chain/loop guard, full-path slug and public/private media")


if __name__ == "__main__":
    main()
