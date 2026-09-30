# Field app on a phone (Phase 7B)

The field app (`/field`) records inspections with no signal: a checklist, photos, location and time,
queued on the phone and synced later. A phone browser only allows the parts it needs - the service
worker that keeps the app offline, the camera, GPS - on a **secure context**: an `https://` page the
phone trusts, or `http://localhost`. There are two ways to get one:

| | A. Wi-Fi + HTTPS | B. USB cable + port forwarding |
|---|---|---|
| Needs | phone and PC on the same Wi-Fi; the local CA installed on the phone once | a USB cable, USB debugging on the phone, Chrome or Edge on the PC |
| Certificate | yes (made on the PC, no admin) | none (`localhost` is a secure context) |
| Admin rights on the PC | maybe, to let Node through Windows Firewall (first run) | none |
| Sync | over Wi-Fi, anywhere in range | while the cable is in (recording offline needs neither) |

Both serve the same app from the same server, `run_field.bat`, which also passes `/v1` through to the
API - the phone never talks to port 8080 and needs no other address.

**The online version needs none of this.** Its address is already `https://` with a certificate every
phone trusts (Vercel), so there is no certificate to install, no USB cable and no PC: open
`https://<the Vercel address>/field` on the phone, sign in with an online account, and install it
from the browser menu (**Add to Home screen** / **Install app**). Camera, GPS, offline capture and
sync work as below, syncing with the online server (Render) whenever there is signal; the first sync
after a quiet spell can take about a minute while that server wakes up. The online service worker
covers `/field` only. Setup and accounts: [DEPLOYMENT.md](DEPLOYMENT.md). The rest of this page is
the laptop setup, which is unchanged.

## 1. On the PC (both ways)

The stack must be running (`run_all.bat`: PostgreSQL, API on 8080). Then:

```bat
run_field.bat
```

It makes the certificates the first time (`certs\`, see 2), builds the app for the field
(`npm run build:field`) and starts `scripts\field_server.mjs`, which prints its addresses:

```text
app over http (this PC, USB forwarding):  http://localhost:5180/field
app over https (phones on this network):  https://192.168.1.23:5443/field
local CA certificate for phones:          http://192.168.1.23:5080/ca.crt
```

Leave that window open while phones use the app. The first time, Windows may ask whether Node.js may
use the network: allow **Private networks**. If the PC will not let you (no admin rights), use way B.

## 2. The certificate (way A)

`node scripts\make_cert.mjs` (run by `run_field.bat` when `certs\` is empty) needs no admin rights and
installs nothing. It uses `mkcert` if it is on the PATH, otherwise the OpenSSL that comes with Git for
Windows, and writes:

- `certs\ca.crt` - a certificate authority for this PC only. **This is what the phone installs, once.**
- `certs\ca.key` - its private key. It never leaves the PC and is git-ignored.
- `certs\server.crt`, `server.key` - the server's certificate, signed by that CA, for `localhost`, the
  PC's name and its current LAN addresses (valid 397 days).

The PC's address changes when it joins another network. `field_server.mjs` warns when the certificate
does not cover the current address; run `node scripts\make_cert.mjs` again. The CA stays, so the phones
need nothing new. (`--new-ca` starts over, and then every phone must install the new `ca.crt`.)

## 3. Install the CA on an Android phone (way A)

Android needs a screen lock (PIN, pattern or password) before it accepts a certificate.

1. Connect the phone to the **same Wi-Fi** as the PC.
2. In Chrome on the phone, open the CA address the server printed, e.g.
   `http://192.168.1.23:5080/ca.crt`. Chrome downloads `smart-mine-local-ca.crt` (to **Downloads**).
   (Or copy `certs\ca.crt` to the phone by USB.) If Android offers to install it straight away, name
   it `Smart Mine local CA`, choose **VPN and apps** if asked, and skip to step 6.
3. Open **Settings**, then find the certificate installer. The path depends on the phone:
   - **Pixel / stock Android 13-15:** Settings > **Security & privacy** > **More security & privacy** >
     **Encryption & credentials** > **Install a certificate** > **CA certificate**.
   - **Samsung (One UI 5-6):** Settings > **Security and privacy** > **More security settings** >
     **Install from device storage** > **CA certificate**.
   - **Older Android (10-12):** Settings > **Security** > **Advanced** > **Encryption & credentials** >
     **Install a certificate** > **CA certificate**.
   - Any phone: search Settings for **"CA certificate"**.
4. Android warns that a CA can see encrypted traffic. Tap **Install anyway**. (It is your own CA, made
   on your PC, and signs only this server's certificate.)
5. Confirm with the screen lock, then pick **smart-mine-local-ca.crt** from Downloads.
6. Check it is there: **Encryption & credentials** > **Trusted credentials** > **User** tab shows
   *Smart Mine Governance local CA (your PC's name)*.
7. Close and reopen Chrome, then open `https://192.168.1.23:5443/field` (the https address the server
   printed). There must be **no certificate warning** and a padlock in the address bar. If Chrome
   still warns, check that the address is the one printed (not the PC's name), then re-run
   `node scripts\make_cert.mjs` and restart `run_field.bat`.

To remove it after the demo: **Encryption & credentials** > **Trusted credentials** > **User** >
*Smart Mine Governance local CA* > **Remove**.

## 4. USB port forwarding, no certificate (way B)

1. On the phone, turn on developer options: Settings > **About phone** > tap **Build number** seven
   times (Samsung: About phone > **Software information** > **Build number**).
2. Settings > **System** > **Developer options** (Samsung: Settings > **Developer options**) > turn on
   **USB debugging**.
3. Connect the phone to the PC by USB. On the phone, allow **USB debugging** for this computer.
4. On the PC, open Chrome at `chrome://inspect/#devices` (or Edge at `edge://inspect/#devices`). Tick
   **Discover USB devices**; the phone appears in the list.
5. Click **Port forwarding...**, add **Device port** `5180` > **Local address** `localhost:5180`, tick
   **Enable port forwarding**, click **Done**. Keep this tab open: forwarding lasts while it is.
6. In Chrome on the phone, open `http://localhost:5180/field`. It is a secure context, so offline
   mode, the camera and GPS all work, and there is no certificate to install.

The cable is needed only to sign in and to sync. After the first sign-in the phone records with no
cable and no network, and syncs the next time the cable (or way A) is back.

## 5. Install the app and use it offline

1. Open the app address (way A or B) in Chrome and sign in once, **with signal**, as an inspector or a
   mine head. The app stores the mines, the assigned inspections and the checklist on the phone, and the
   service worker stores the app itself (about 3 MB).
2. Chrome menu (**⋮**) > **Install app** (or **Add to Home screen**). It opens full-screen from its icon.
3. Allow **Location** and **Camera** when the app first asks.
4. With no signal: start a visit (an assigned inspection, or an unscheduled one), tap checklist items,
   record severity, photos, note, and whether it is a violation (with a corrective action for the mine).
   Each finding is saved on the phone at once, with the phone's time and the GPS fix with its accuracy
   (underground, mark **Underground - no fix**).
5. With signal: tap **Sync now**. If the sign-in expired meanwhile, the app asks for the password first
   and keeps the queue until then. Each item shows **waiting**, **syncing**, **synced** or **failed**
   with the reason. A retried sync never creates duplicates: every item carries the phone's own id.

A finding more than 5 km from the mine's recorded location (`rules.yaml` product.field_capture), or sent
from a phone whose clock is off by more than 5 minutes, is saved and **flagged** for review, never refused.

## 6. Troubleshooting

| Symptom | Cause, fix |
|---|---|
| The phone cannot open the https address | Not on the same Wi-Fi; Windows Firewall blocking Node (allow Private networks); guest Wi-Fi isolating devices. Use way B. |
| Certificate warning on the phone | CA not installed (3), or the address is not in the certificate: re-run `node scripts\make_cert.mjs`, restart `run_field.bat`. |
| "Signing in needs signal" | The first sign-in on a phone needs the server; later ones only to sync. |
| Sync says "No connection" | The phone cannot reach the server; the queue is kept. Try again with signal or the cable. |
| An item shows **failed** | Its reason is shown (e.g. the inspection was closed meanwhile). It stays in the queue; fix the cause and sync again. |
| The app looks old after an update | Close all its tabs and reopen: the new version takes over on the next start. |
