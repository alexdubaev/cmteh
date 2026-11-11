# Read-only hosting readiness — 2026-11-11

Target confirmed fromstandingauthorization: test.cmteh.ru, actualroot /var/www/u2797422/data/www/test.cmteh.ru. SSHtransport alias engine-cmteh-local doesnotauthorize engineapp. Onlyreadcommandsused: php-v; immediateextensiondirectorynames; rawSHA256 fivepubliccorefiles. NoDBwrites/nativeadminlogin/mail/migration/config/deployactions.

Host PHP8.2.35. Extensiondirectories opencart/cmteh/ocmod; listingalone notproofagainstcompiled/thirdpartyhooks. Currenttarget publiccorematches pinnedofficialZIP rawbytes (ZIPcdef537f5a0a7e1b4ddbe16f589dd2e80b10150a09bfbe93ce76ef2d5c3218c0):

- admin/model/catalog/product.php 59bce65d7397436f6ee2a62f584c0048c65f1fede69779b4edd43a42dbe4d070
- system/engine/loader.php 415b4271f543cbada32d22c8716db3259346f6dc8aa8a023cea7f4f5d228a87a
- system/library/db/mysqli.php d237e26915d757478fa72d0755eade87be7727e0d8da22cefbd5753870337f42
- catalog/model/checkout/order.php e8122d51c32d69c6a275622e982f49567ae525d98aa30bfa466f9cfb57084bf9
- catalog/model/catalog/product.php d69c05852584f3d81b3d5998df8a81a843cf7834915804c4fdba1d16269350a5

Это readiness/sourceevidence, неPASSскладскихтестов и неcompatibilityproofPHP8.2/MySQL5.7. Новаятаблица/движения ещёнепримененынаhost. TargetDBactualversion/newdatafreshbackup/recovery/compiledhookinventory toverifybeforedeployment. ПрежниеGO остаютсязакрыты.

Additionalreadonlytargetmoduleprobe PHP8.2.35: pdo_mysql/mysqli/mbstring/zip/dom/xmlreader/simplexml/fileinfo all extension_loaded=true. NoDB/nativebootstrap/filewrites. Notfunctionalnewfeaturehostproof.
