# Third-party software and sources

This distribution is for internal company acceptance testing. It contains
static, hash-identified application runtimes, not an initialized workstation.
No installed account, password, php.ini, EAI configuration, customer database
or captured client response is supplied.

- **Node.js 24.14.1:** the x64 executable was checked against the recorded
  official hash. Full Node and component notices are in `Runtime/node/LICENSE`.
- **PHP 8.2.12:** CLI/CGI, mysqli and notices are in `Runtime/php`.
  This product includes PHP software, freely available from
  http://www.php.net/software/ . No XAMPP configuration is included.
- **MySQL Community 5.7.44:** full terms are in `Runtime/mysql/LICENSE`.
  The corresponding official source including Boost is supplied as
  `Licences/Sources/mysql-boost-5.7.44.tar.gz`. Download origins and hashes
  are recorded in `Licences/DOWNLOADS.json`. Keep this source material with
  the binary distribution; it is not an unnecessary development backup.
- **EAI SDK S.1366 / PHP 5.6.28:** files from the supplied official SDK are
  limited to the project's approved runtime pins. PHP/Zend notices are in
  `Licences`. This internal handover does not assert public redistribution
  rights over CarrierWeb software. The company must confirm its authorization
  before sharing that SDK outside the permitted internal audience.
- **Microsoft C/C++ runtimes:** signed prerequisite installers are included
  in `Runtime/Prerequis` and are not run automatically. Their terms apply.
  IT/operator approval is needed before changing Windows components. No
  redistributable DLL was taken from System32.

License texts and vendor copyright notices have not been removed or rewritten.
English application guides do not replace those original legal documents.
Older SDK/PHP/MySQL versions are compatibility dependencies, not a security
recommendation for public exposure. IT must review maintenance, licensing
and deployment security before production.
