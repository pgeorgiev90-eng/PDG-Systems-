# PDG Systems mobile application

Android package: `bg.pdgsystems.app`. The application uses Google's Android Browser Helper to open the existing HTTPS website as a Trusted Web Activity, retaining browser support for CAD, uploads, downloads and account sessions. A compatible browser, such as Chrome, is required.

The release workflow builds and verifies a release APK, then publishes it at `/downloads/pdg-systems.apk`. Website navigation and account permissions continue to be enforced by the PHP APIs.

The signing identity is generated once on the build runner and retained encrypted at the hosting-protected `database/pdg-android-signing.json`. It is never committed or published in plaintext. The current FTP deployment password protects this backup and the PKCS12 store. Before rotating that deployment password, decrypt the backup using the old password and re-encrypt it under the new password, updating the PKCS12 password as well. Losing this signing identity prevents compatible updates to existing installations.

The APK is distributed directly from the site, not through Google Play. Android may ask the user to allow installation from their browser.

An independently installable iPhone build requires an Apple Developer signing identity and a supported Apple distribution route. Do not publish unsigned IPA files as installable downloads.
