"""Actual installed candidate business acceptance; isolated local fixture, never sends mail."""
import html
import json
import re
from urllib.parse import urlencode
from uuid import uuid4
from release_checks import create_role, require, LEADS, CATALOG, admin_post
from runtime import LocalOpenCart


def business(runtime):
    runtime.login((runtime.directory / "admin-password").read_text().strip())
    admin=runtime
    runtime=create_role(admin,"Business full",[LEADS,"marketing/marketing"],["marketing/marketing"])
    roles=[runtime]
    campaign_id=None
    def fixture(action):
        return runtime.docker("exec","-T","-e","CMTEH_LOCAL_BUSINESS_FIXTURE=1","web","php","/workspace/tests/admin-business-fixture.php",action,capture=True).stdout
    try:
        fixture("delete")
        saved=json.loads(fixture("create"))
        before=fixture("hash")
        for view in ("requests","contacts","reports","sources","capabilities"):
            code,page=runtime.request(runtime.action(LEADS,view=view,search="Synthetic business acceptance"))
            require(code==200 and "Synthetic business acceptance" in page if view in ("requests","contacts") else code==200,"Installed business view missing")
            require("Заказы" not in re.sub(r'<nav id="column-left".*?</nav>','',page,flags=re.S) if view!="capabilities" else True,"Native orders leaked into request reports")
            if view in ("requests","contacts"):
                require(len(re.findall(r'<tr>',page))==51 and "Страница 1 из 2" in page,"Registry must page 52 real records server-side")
                code,last=runtime.request(runtime.action(LEADS,view=view,search="Synthetic business acceptance",page=2))
                require(code==200 and len(re.findall(r'<tr>',last))==3,"Second page must hold remaining two records")
            if view=="sources": require("Источник не указан" in page and "https://example.test/catalog" in page,"Unknown/declared source lost")
        code,empty=runtime.request(runtime.action(LEADS,view="contacts",search="No matching synthetic record"))
        require(code==200 and "Обращений по выбранным условиям нет" in empty,"Empty filter fabricated records")
        code,_=runtime.request(runtime.action(LEADS,view="reports",**{"from":"2026-02-30"}))
        require(code==400,"Invalid date accepted")
        code,filtered=runtime.request(runtime.action(LEADS,view="contacts",type="quote",search="Synthetic business acceptance"))
        require(code==200 and "Записей: 1" in filtered,"Source type filter disagrees")
        code,attachment=runtime.request(runtime.action(LEADS,type="quote",id=saved['quote_id'],action="download"))
        require(code==200 and attachment=="001A;5","Private original changed")
        require(fixture("hash")==before,"Read-only business views changed business/native snapshots")
        reader=create_role(admin,"Business read",[LEADS],[]);roles.append(reader)
        other=create_role(admin,"No business",[CATALOG],[]);roles.append(other)
        for view in ("contacts","reports","sources","capabilities"):
            code,page=reader.request(reader.action(LEADS,view=view))
            require(code==200,"Existing read role needs new rights")
            code,page=other.request(other.action(LEADS,view=view))
            require("Synthetic business acceptance" not in page and "business@example.test" not in page and "business-summary" not in page,"Denied role leaked business data")
        code,page=other.request(other.action("common/dashboard"))
        require("business-summary" not in page and "business@example.test" not in page,"Dashboard leaked request totals")
        for forbidden in ("marketing/contact","marketing/coupon","design/theme","design/layout","design/banner","marketplace/marketplace","customer/customer","sale/order"):
            require('route='+forbidden+'&' not in html.unescape(page),"Unconnected ordinary native menu remains")
        code,_=reader.request(reader.action(LEADS,type="quote",id=saved['quote_id'],action="retry"),b"",'application/x-www-form-urlencoded')
        require(code==403,"Read role gained modify rights")
        for session,token in ((runtime,"wrong"),(LocalOpenCart(runtime.directory,runtime.port),"")):
            code,page=session.request('/admin/index.php?'+urlencode({'route':LEADS,'view':'contacts','user_token':token}))
            require("business@example.test" not in page and "business-summary" not in page,"Invalid session leaked business")
        # Native registry CRUD and actual custom-route tracking on THIS refreshed candidate.
        code='synthetic-'+uuid4().hex[:16]
        result=admin_post(runtime,'marketing/marketing.save',{'name':'Synthetic campaign','description':'Local acceptance','code':code})
        require(result.get('success') and result.get('marketing_id'),"Native campaign create failed")
        campaign_id=int(result['marketing_id'])
        updated=admin_post(runtime,'marketing/marketing.save',{'marketing_id':campaign_id,'name':'Synthetic updated','description':'Local acceptance updated','code':code})
        require(updated.get('success'),"Native campaign update failed")
        status,page=runtime.request(runtime.action('marketing/marketing.form',marketing_id=campaign_id))
        require(status==200 and 'Synthetic updated' in page and 'input-example2' not in page and 'data-store=' in page,"Adapted native campaign form missing")
        status,page=runtime.request(runtime.action('marketing/marketing'))
        require(status==200 and 'Запросы (с повторами)' in page and 'column_orders' not in page and '>Orders<' not in page,"Campaign counter semantics missing")
        status,denied=reader.request(reader.action('marketing/marketing.save'),urlencode({'marketing_id':campaign_id,'name':'Unauthorized','code':code}).encode(),'application/x-www-form-urlencoded')
        require('success' not in denied,"Role without campaign ACL modified registry")
        # New cookie jar, cookie wins over query after first visit. No public form submission or mail.
        client=LocalOpenCart(runtime.directory,runtime.port)
        for path,expected in (("/?tracking="+code,200),("/",200),("/synthetic-tracking-404",404)):
            status,_=client.request(path);require(status==expected,"Current candidate public tracking route changed")
        raw=runtime.docker('exec','-T','web','php','-r',"require '/var/www/html/config.php';require '/workspace/extension/cmteh/system/Database.php';$p=Cmteh\\Database::connectFromOpenCart();echo $p->query('SELECT COUNT(*) FROM '.DB_PREFIX.'marketing_report WHERE marketing_id='.(int)$argv[1])->fetchColumn();",str(campaign_id),capture=True).stdout
        require(int(raw)==3,"Current candidate tracking must count repeated and 404 requests")
        after=json.loads(fixture("hash")); prior=json.loads(before)
        require(all(after[k]==v for k,v in prior.items() if k!='marketing'),"Tracking changed request/customer/order data")
        print('PASS business installed: 52 records, 5 views, pagination/search/date/type/zero/private original/read-only, role/session boundaries; native campaign create/read/update, tracking 3 requests including repeat+404')
    finally:
        if campaign_id:
            admin_post(runtime,'marketing/marketing.delete',{'selected[]':campaign_id})
        fixture('delete')
        for role in roles:role.logout()
        admin.logout()


def business_browser(runtime,tooling,evidence):
    import os
    import subprocess
    from pathlib import Path
    runtime.login((runtime.directory/'admin-password').read_text().strip())
    password_file=runtime.directory/'admin-business-password'
    role=None
    campaign_id=None
    def fixture(action):
        runtime.docker('exec','-T','-e','CMTEH_LOCAL_BUSINESS_FIXTURE=1','web','php','/workspace/tests/admin-business-fixture.php',action,capture=True)
    try:
        fixture('delete');fixture('create')
        campaign=admin_post(runtime,'marketing/marketing.save',{'name':'Synthetic HTML boundary','code':'"><svg data-probe=cmteh onload=1>','description':'Local text boundary'})
        require(campaign.get('success') and campaign.get('marketing_id'),'Campaign boundary fixture failed')
        campaign_id=int(campaign['marketing_id'])
        role=create_role(runtime,'Browser business reader',[LEADS,'marketing/marketing'],[],password_file=password_file)
        username=role.username;role.logout()
        env=os.environ | {'CMTEH_BROWSER_BASE':runtime.origin,'CMTEH_BROWSER_TOOLING':str(tooling),
                          'CMTEH_BROWSER_EVIDENCE':str(evidence),'CMTEH_ADMIN_USERNAME':username,
                          'CMTEH_ADMIN_PASSWORD_FILE':str(password_file),'CMTEH_CAMPAIGN_ID':str(campaign_id)}
        subprocess.run(['node',str(Path(__file__).resolve().parents[1]/'tests/browser/admin-business.mjs')],env=env,check=True)
    finally:
        fixture('delete')
        if campaign_id:admin_post(runtime,'marketing/marketing.delete',{'selected[]':campaign_id})
        if role:role.logout()
        password_file.unlink(missing_ok=True)
        runtime.logout()

if __name__=='__main__':
    import argparse
    from pathlib import Path
    p=argparse.ArgumentParser();p.add_argument('--runtime-dir',type=Path,required=True);p.add_argument('--port',type=int,required=True)
    p.add_argument('--browser-tooling',type=Path);p.add_argument('--evidence',type=Path);a=p.parse_args()
    r=LocalOpenCart(a.runtime_dir,a.port)
    if a.browser_tooling:
        require(a.evidence is not None,'Browser evidence directory required')
        business_browser(r,a.browser_tooling,a.evidence)
    else:business(r)
