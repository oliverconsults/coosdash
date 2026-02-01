KoSIT XRechnung Testsuite Fixtures (Import)

Quelle:
- Repo: https://github.com/itplr-kosit/xrechnung-testsuite
- Tag/Stand: release-2024-10-31
- Commit: c76e200c22a7be5b83c77b1e6d8f7e2bf9569025

Enthalten (valid):
- xrechnung3_minimal_ubl.xml (aus src/test/technical-cases/01.06_minimal_test_ubl.xml)
- xrechnung3_minimal_cii.xml (aus src/test/technical-cases/01.06_minimal_test_uncefact.xml)
- xrechnung3_extension_ubl.xml (aus src/test/business-cases/extension/04.01a-INVOICE_ubl.xml)

Enthalten (invalid, bewusst abgeleitet fuer Negativtests):
- xrechnung3_minimal_ubl_missing_currency.xml (DocumentCurrencyCode entfernt)
- xrechnung3_minimal_ubl_bad_customizationid.xml (CustomizationID verfremdet)

Hinweis:
- Die invalid Cases sind absichtlich syntaktisch nah an valid und sollen KoSIT-Validator-Fehler provozieren.
- Sobald KoSIT-Validator installiert ist (siehe Node #274), koennen wir CI-Tests bauen: valid=PASS, invalid=FAIL.
