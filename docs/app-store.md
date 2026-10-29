# Publishing in the Nextcloud App Store

What it takes to publish Screenings on [apps.nextcloud.com](https://apps.nextcloud.com), following the [App Store developer guide](https://nextcloudappstore.readthedocs.io/en/latest/developer.html) (checked 2026-10-29, App Store 5.0.0). The app ID `screenings` was still free then.

The repository side is ready:
- `LICENSE` (AGPL-3.0) and `CHANGELOG.md` (the App Store shows each release's section)
- `appinfo/info.xml` with description, documentation links, screenshots, website, issue tracker and repository (validates against Nextcloud 35's schema)
- signing in `build/package.sh`

What follows needs the app owner (Guido): a GitHub account, the private key, and the App Store account.

## Once: certificate and registration

### 1. Make a private key and a certificate request

```bash
mkdir -p ~/.nextcloud/certificates
cd ~/.nextcloud/certificates
openssl req -nodes -newkey rsa:4096 -keyout screenings.key -out screenings.csr -subj "/CN=screenings"
```

- **`screenings.key` is the secret.** It signs every release. Keep it out of the repository (`.gitignore` already blocks `*.key` and `*.csr`), back it up somewhere safe, and never send it to anyone. Losing it means a new certificate, and that deletes all earlier releases from the App Store.
- **`~/.nextcloud/certificates/`** is where `build/package.sh` looks by default. Use `SCREENINGS_SIGN_KEY` / `SCREENINGS_SIGN_CERT` for another location.

### 2. Ask Nextcloud to sign it

1. On GitHub, make your email address visible on your profile; Nextcloud uses it to verify you.
2. Fork [nextcloud/app-certificate-requests](https://github.com/nextcloud/app-certificate-requests) and add the file `screenings/screenings.csr` with the contents of your `screenings.csr`.
3. Open a pull request, for example:

   > **Certificate request for screenings**
   >
   > App ID: `screenings`
   > Repository: https://github.com/guido-visser/screenings
   >
   > Screenings is a Nextcloud app for sending private, tracked film screenings: personal or public links to a protected player, a dashboard of how each viewer watched, and viewer feedback (notes at moments in the film, closing questions).
   >
   > I'm the author of the app.

4. Nextcloud reviews it by hand, which can take a few days. They may ask questions. When approved, they post the signed certificate in the pull request (or as `screenings/screenings.crt` in that repository).
5. Save it as `~/.nextcloud/certificates/screenings.crt`, without blank lines before or after.

### 3. Register the app

Log in at [apps.nextcloud.com](https://apps.nextcloud.com) (with GitHub), open **Register app**, and fill in:

- **Certificate:** the contents of `screenings.crt`.
- **Signature:** a signature over the app ID:

  ```bash
  echo -n "screenings" | openssl dgst -sha512 -sign ~/.nextcloud/certificates/screenings.key | openssl base64
  ```

## Every release

1. **Prepare the release:**
   - Raise `<version>` in `appinfo/info.xml`.
   - Add a section to `CHANGELOG.md`.
   - Test, commit and push.
2. **Build the signed package:** start the dev stack (signing runs `occ integrity:sign-app` there), then run:

   ```bash
   sh build/package.sh
   ```

   It prints "Signing with …" and writes two files:
   - `build/screenings-<version>.tar.gz`, the package (signed inside: `appinfo/signature.json`)
   - `build/screenings-<version>.tar.gz.sig`, the signature for the App Store form

   If it says "Unsigned", the key or certificate wasn't found.
3. **Put the package online as a GitHub release** (the App Store downloads it from a URL):

   ```bash
   gh release create v<version> build/screenings-<version>.tar.gz --title "Screenings <version>" --notes "See CHANGELOG.md"
   ```

   GitHub's own source archives don't work for the App Store: their top folder is named `screenings-<version>`, and they don't contain the built `js/`. Upload the package itself.
4. **Upload the release:** in the App Store open **Upload app release** and fill in:
   - **Download:** `https://github.com/guido-visser/screenings/releases/download/v<version>/screenings-<version>.tar.gz`
   - **Signature:** the contents of the `.sig` file
   - **Nightly:** leave unchecked

   The App Store downloads the package, checks the signature and `info.xml`, and publishes the release.

## Things to keep in mind

- **Nextcloud versions:** `info.xml` allows Nextcloud 35 only (`max-version="35"`). For each new Nextcloud major, test the app and publish a release with a higher `max-version`, or servers on the new version stop getting it.
- **Deploying your own server:** `deploy.sh` uses the same `package.sh`. Once the key exists, deployed packages are signed too, which is fine: Nextcloud then checks the app's integrity like any App Store app.
- **Screenshots:** the App Store shows the images from `docs/screenshots/` on GitHub (the URLs are in `info.xml`). Regenerate them with the dev skill's `demo-data.py` and `screenshots.py` when the UI changes.
- **Before announcing it widely:** Safari, iPhone/iPad and Firefox haven't been tested yet (see `docs/HANDOFF.md`).
