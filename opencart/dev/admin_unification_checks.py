"""Installed native admin acceptance for the unified shell (local fixtures only)."""
import re
from release_checks import create_role, require, CATALOG, LEADS, MODULE
from runtime import LocalOpenCart


def shell(runtime):
    runtime.login((runtime.directory / "admin-password").read_text().strip())
    sessions = []
    marker = "Synthetic unknown native brand"
    def fixture(remove=False):
        sql = "DELETE FROM oc_manufacturer WHERE manufacturer_id=990284" if remove else "INSERT INTO oc_manufacturer SET manufacturer_id=990284,name='Synthetic unknown native brand',sort_order=0"
        runtime.docker("exec", "-T", "web", "php", "-r", "require '/var/www/html/config.php'; require '/workspace/extension/cmteh/system/Database.php'; Cmteh\\Database::connectFromOpenCart()->exec($argv[1]);", sql, capture=True)
    fixture()
    try:
        code, page = runtime.request(runtime.action("common/dashboard"))
        require(code == 200 and "Рабочий стол СМ ТЕХНО" in page, "Unified native dashboard is missing")
        require("John Doe" not in page and "cmteh-admin" in page, "Header must display current login, without changing owner identity")
        require("view/javascript/common.js" in page and "button-menu" in page, "Native assets/mobile sidebar lost")
        require("common/security" in page, "Native security block lost")
        require("id=\"menu-cmteh\"" not in page, "Duplicate extension catalog group remains")
        full = create_role(runtime,"Unified full catalog",[MODULE,CATALOG,LEADS,"catalog/product","catalog/category","catalog/manufacturer"],[])
        sessions.append(full)
        code, unknown = full.request(full.action(CATALOG, type="brand", search=marker))
        require(code == 200 and marker in unknown and "manufacturer_id=990284" in unknown, "Authorized unknown native record/context editor missing")
        for label, access in (("Native catalog", ["catalog/product", "catalog/category", "catalog/manufacturer"]),
                              ("Custom catalog", [CATALOG]), ("Leads only", [LEADS])):
            role = create_role(runtime, label, access, [])
            sessions.append(role)
            code, page = role.request(role.action("common/dashboard"))
            require(code == 200 and "Рабочий стол СМ ТЕХНО" in page, "Existing role lost native dashboard")
            menu = re.search(r'<nav id="column-left".*?</nav>', page, re.S)
            require(menu, "Native sidebar missing")
            menu = menu[0]
            if CATALOG in access:
                code, unknown = role.request(role.action(CATALOG, type="brand", search=marker))
                require(all(marker not in row for row in re.findall(r'<tr\b.*?</tr>',unknown,re.S)) and 'manufacturer_id=990284' not in unknown, "Custom-only role leaked unknown native content")
                require(CATALOG in menu and "route=catalog/product" not in menu, "Custom catalog role got duplicate/dead native catalog")
            elif "catalog/product" in access:
                require("route=catalog/product" in menu and CATALOG not in menu, "Native-only role lost sole catalog")
            else:
                require(CATALOG not in menu and "route=catalog/product" not in menu, "Leads-only role sees catalog")
            require((LEADS in menu) == (LEADS in access), "Lead navigation leaked")
        guest = LocalOpenCart(runtime.directory, runtime.port)
        code, page = guest.request(guest.action("common/dashboard"))
        require("form-login" in page and CATALOG not in page, "Anonymous native dashboard exposed links")
        code, page = runtime.request("/admin/index.php?route=common/dashboard&user_token=wrong")
        require("form-login" in page and CATALOG not in page, "Wrong token exposed shell")
        print("PASS unified shell: owner/native-only/custom-only/leads-only/anonymous/wrong-token; 6 scenarios")
    finally:
        fixture(remove=True)
        for role in sessions: role.logout()
        runtime.logout()


if __name__ == "__main__":
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument("--runtime-dir", required=True, type=__import__("pathlib").Path)
    parser.add_argument("--port", type=int, required=True)
    args = parser.parse_args()
    shell(LocalOpenCart(args.runtime_dir, args.port))
