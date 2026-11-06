Browser spreadsheet reader

This is the published read-excel-file 9.3.10 browser bundle, copied byte for byte
from node_modules/read-excel-file/bundle/read-excel-file.min.js after installing
the repository's existing package-lock.json. No build or CDN is used at runtime.
SHA-256: eb774939e3cabf764483ba7d16515d058186eb0587b67de81d40b9aa442f30fa.

Package licenses for read-excel-file, fflate, saxen and unzipper-esm are beside
the bundle. The lockfile lists worker-f 0.1.20 (MIT) as well. The published
worker-f package metadata identifies its author as catamphetamine and provides
no separate license file. Repository: https://gitlab.com/catamphetamine/worker-f.

The reader is loaded only when a browser selects an XLSX file. The browser
rejects files larger than 5 MiB before reading them, then validates the ZIP
central directory, rejects multi-volume/ZIP64 archives, and limits it to 1,024
entries and 32 MiB of declared uncompressed data before passing it to the library.
The importer processes up to 10,000 input rows and the cart stores 100 lines.
